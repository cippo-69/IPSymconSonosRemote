<?php

declare(strict_types=1);

require_once __DIR__ . '/SonosUPnP.php';

/**
 * Gerätetreiber der Sonos-Kachel.
 *
 * Jede Zeile der Geräteliste wird über SNRDeviceFactory einem Treiber zugeordnet:
 *  - SonosDevice:   Instanz des Moduls „Sonos Player“ (tkugelberg/Sonos) – Gruppierung, Favoriten, Bibliothek
 *  - GenericDevice: jede andere Instanz bzw. reine Variablen – An/Aus, Lautstärke, Stumm, Wiedergabe, Titel
 *
 * Eigene Gerätetypen: Klasse von SNRBaseDevice ableiten und in SNRDeviceFactory::DRIVERS (Modul-GUID => Klasse)
 * eintragen. Was ein Treiber kann, meldet er über Capabilities(); die Kachel blendet nur passende Bedienelemente ein.
 */
interface SNRDevice
{
    public function Key(): string;

    public function Kind(): string;

    /** Variablen, deren Änderung die Kachel aktualisieren soll */
    public function Watch(): array;

    /** power, volume, mute, transport, group, media */
    public function Capabilities(): array;

    public function State(): array;

    /** play, pause, play_pause, stop, next, previous */
    public function Command(string $Command): void;

    public function SetVolume(int $Volume): void;

    public function SetMute(bool $Mute): void;

    public function SetPower(bool $On): void;

    /** Favorit / Bibliothekseintrag abspielen: ['uri','meta','name','type'] */
    public function PlayItem(array $Item): void;

    /** Gerät für die Gruppe vorbereiten (Nicht-Sonos: einschalten, Eingang wählen) */
    public function PrepareForGroup(): void;
}

final class SNRDeviceFactory
{
    public const SONOS_PLAYER = '{52F6586D-A1C7-AAC6-309B-E12A70F6EEF6}';

    /** Modul-GUID => Treiberklasse; alles andere wird GenericDevice */
    public const DRIVERS = [
        self::SONOS_PLAYER => SonosDevice::class
    ];

    /**
     * @return SNRDevice[] Schlüssel => Treiber
     */
    public static function FromList(array $Rows): array
    {
        $out = [];
        foreach (array_values($Rows) as $i => $row) {
            $inst = (int) ($row['InstanceID'] ?? 0);
            $power = (int) ($row['PowerVariable'] ?? 0);
            if ($inst <= 0 && $power <= 0) {
                continue;
            }
            $key = $inst > 0 ? (string) $inst : 'r' . $i;
            if (isset($out[$key])) {
                continue;
            }
            $class = GenericDevice::class;
            if ($inst > 0 && IPS_InstanceExists($inst)) {
                $mid = IPS_GetInstance($inst)['ModuleInfo']['ModuleID'] ?? '';
                $class = self::DRIVERS[$mid] ?? GenericDevice::class;
            }
            $out[$key] = new $class($key, $row);
        }
        return $out;
    }
}

abstract class SNRBaseDevice implements SNRDevice
{
    protected string $key;
    protected array $row;
    protected int $instance;

    public function __construct(string $Key, array $Row)
    {
        $this->key = $Key;
        $this->row = $Row;
        $this->instance = (int) ($Row['InstanceID'] ?? 0);
        if ($this->instance > 0 && !IPS_InstanceExists($this->instance)) {
            $this->instance = 0;
        }
    }

    public function Key(): string
    {
        return $this->key;
    }

    public function InstanceID(): int
    {
        return $this->instance;
    }

    public function Name(): string
    {
        $name = trim((string) ($this->row['Name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        if ($this->instance > 0) {
            return IPS_GetName($this->instance);
        }
        $p = $this->Var('PowerVariable');
        return $p > 0 ? IPS_GetName($p) : $this->key;
    }

    /** Variable aus der Konfigurationszeile (0 = nicht gesetzt) */
    protected function Var(string $Column): int
    {
        $v = (int) ($this->row[$Column] ?? 0);
        return ($v > 0 && IPS_VariableExists($v)) ? $v : 0;
    }

    protected function PowerVar(): int
    {
        return $this->Var('PowerVariable');
    }

    /** null = kein Schaltaktor (immer an) */
    public function Power(): ?bool
    {
        $vid = $this->PowerVar();
        if ($vid === 0) {
            return null;
        }
        return self::ToBool(GetValue($vid));
    }

    public function SetPower(bool $On): void
    {
        $vid = $this->PowerVar();
        if ($vid === 0) {
            throw new Exception('Kein Schaltaktor konfiguriert');
        }
        self::WriteVar($vid, $On);
    }

    /**
     * Kopplung an die Gruppe: einschalten (falls aus) und optional den Eingang setzen,
     * z. B. AV-Receiver auf den Eingang, an dem ein Sonos Port hängt.
     */
    public function PrepareForGroup(): void
    {
        if ($this->Power() === false) {
            $this->SetPower(true);
        }
        $src = $this->Var('SourceVariable');
        $val = (string) ($this->row['SourceValue'] ?? '');
        if ($src > 0 && $val !== '') {
            self::WriteVar($src, $val);
        }
    }

    protected function BaseState(): array
    {
        return [
            'id'    => $this->key,
            'kind'  => $this->Kind(),
            'name'  => $this->Name(),
            'caps'  => $this->Capabilities(),
            'power' => $this->Power()
        ];
    }

    // ---------- Hilfen ----------

    public static function ToBool(mixed $Value): bool
    {
        if (is_string($Value)) {
            return in_array(strtolower(trim($Value)), ['on', '1', 'true', 'an', 'ein'], true);
        }
        return (bool) $Value;
    }

    /**
     * Schreibt eine Variable über ihre Aktion (z. B. Z2M-Steckdose); ohne Aktion per SetValue.
     * Der Wert wird an den Variablentyp angepasst (bool/int/float/string „ON“/„OFF“).
     */
    public static function WriteVar(int $VariableID, mixed $Value): void
    {
        $v = IPS_GetVariable($VariableID);
        switch ($v['VariableType']) {
            case VARIABLETYPE_BOOLEAN:
                $Value = is_bool($Value) ? $Value : self::ToBool($Value);
                break;
            case VARIABLETYPE_INTEGER:
                $Value = is_bool($Value) ? (int) $Value : (int) $Value;
                break;
            case VARIABLETYPE_FLOAT:
                $Value = (float) $Value;
                break;
            case VARIABLETYPE_STRING:
                $Value = is_bool($Value) ? ($Value ? 'ON' : 'OFF') : (string) $Value;
                break;
        }
        $hasAction = ($v['VariableCustomAction'] ?? 0) > 1 || (($v['VariableCustomAction'] ?? 0) === 0 && ($v['VariableAction'] ?? 0) > 1);
        if ($hasAction) {
            RequestAction($VariableID, $Value);
        } else {
            SetValue($VariableID, $Value);
        }
    }

    protected static function Profile(int $VariableID): string
    {
        $v = IPS_GetVariable($VariableID);
        return (string) ($v['VariableCustomProfile'] ?: $v['VariableProfile']);
    }
}

/**
 * Beliebiges Gerät über Variablen. Nicht gesetzte Variablen werden unter der Instanz gesucht
 * (Ident bzw. Profil: Volume/~Volume, Mute, Status/~Playback…, Title/~Song, Artist/~Artist, Power/State).
 */
class GenericDevice extends SNRBaseDevice
{
    private array $vars;

    public function __construct(string $Key, array $Row)
    {
        parent::__construct($Key, $Row);
        $found = $this->Detect();
        $this->vars = [
            'power'  => $this->Var('PowerVariable') ?: $found['power'],
            'volume' => $this->Var('VolumeVariable') ?: $found['volume'],
            'mute'   => $this->Var('MuteVariable') ?: $found['mute'],
            'status' => $this->Var('StatusVariable') ?: $found['status'],
            'title'  => $this->Var('TitleVariable') ?: $found['title'],
            'artist' => $found['artist']
        ];
    }

    public function Kind(): string
    {
        return 'generic';
    }

    protected function PowerVar(): int
    {
        return $this->vars['power'];
    }

    public function Watch(): array
    {
        return array_values(array_filter($this->vars));
    }

    public function Capabilities(): array
    {
        return [
            'power'     => $this->vars['power'] > 0,
            'volume'    => $this->vars['volume'] > 0,
            'mute'      => $this->vars['mute'] > 0,
            'transport' => $this->vars['status'] > 0,
            'group'     => false,
            'media'     => false
        ];
    }

    public function State(): array
    {
        $s = $this->BaseState();
        $s['available'] = $s['power'] !== false;
        $s['state'] = 'stopped';
        if ($this->vars['status'] > 0) {
            $val = GetValue($this->vars['status']);
            if (is_bool($val)) {
                $s['state'] = $val ? 'playing' : 'stopped';
            } else {
                $s['state'] = ((int) $val === 2) ? 'playing' : (((int) $val === 3) ? 'paused' : 'stopped');
            }
        }
        $s['volume'] = $this->vars['volume'] > 0 ? (int) round((float) GetValue($this->vars['volume'])) : null;
        $s['muted'] = $this->vars['mute'] > 0 ? self::ToBool(GetValue($this->vars['mute'])) : null;
        $s['title'] = $this->vars['title'] > 0 ? (string) GetValue($this->vars['title']) : '';
        $s['artist'] = $this->vars['artist'] > 0 ? (string) GetValue($this->vars['artist']) : '';
        $s['image'] = '';
        return $s;
    }

    public function Command(string $Command): void
    {
        $vid = $this->vars['status'];
        if ($vid === 0) {
            throw new Exception('Keine Wiedergabe-Variable');
        }
        $cur = GetValue($vid);
        if (is_bool($cur)) {
            $map = ['play' => true, 'pause' => false, 'stop' => false, 'play_pause' => !$cur];
            if (!array_key_exists($Command, $map)) {
                return;
            }
            self::WriteVar($vid, $map[$Command]);
            return;
        }
        // ~PlaybackPreviousNext: 0 Zurück, 1 Stopp, 2 Play, 3 Pause, 4 Weiter
        $map = ['previous' => 0, 'stop' => 1, 'play' => 2, 'pause' => 3, 'next' => 4, 'play_pause' => ((int) $cur === 2 ? 3 : 2)];
        if (isset($map[$Command])) {
            self::WriteVar($vid, $map[$Command]);
        }
    }

    public function SetVolume(int $Volume): void
    {
        if ($this->vars['volume'] > 0) {
            self::WriteVar($this->vars['volume'], $Volume);
        }
    }

    public function SetMute(bool $Mute): void
    {
        if ($this->vars['mute'] > 0) {
            self::WriteVar($this->vars['mute'], $Mute);
        }
    }

    public function PlayItem(array $Item): void
    {
        throw new Exception('Dieses Gerät kann keine Favoriten abspielen');
    }

    private function Detect(): array
    {
        $f = ['power' => 0, 'volume' => 0, 'mute' => 0, 'status' => 0, 'title' => 0, 'artist' => 0];
        if ($this->instance <= 0) {
            return $f;
        }
        foreach (IPS_GetChildrenIDs($this->instance) as $cid) {
            if (!IPS_VariableExists($cid)) {
                continue;
            }
            $ident = strtolower((string) IPS_GetObject($cid)['ObjectIdent']);
            $profile = self::Profile($cid);
            $type = IPS_GetVariable($cid)['VariableType'];
            if (!$f['volume'] && ($profile === '~Volume' || $ident === 'volume')) {
                $f['volume'] = $cid;
            } elseif (!$f['mute'] && $ident === 'mute') {
                $f['mute'] = $cid;
            } elseif (!$f['status'] && (str_starts_with($profile, '~Playback') || in_array($ident, ['status', 'playback'], true)) && $type !== VARIABLETYPE_STRING) {
                $f['status'] = $cid;
            } elseif (!$f['title'] && ($profile === '~Song' || $ident === 'title')) {
                $f['title'] = $cid;
            } elseif (!$f['artist'] && ($profile === '~Artist' || $ident === 'artist')) {
                $f['artist'] = $cid;
            } elseif (!$f['power'] && in_array($ident, ['power', 'state'], true) && $type === VARIABLETYPE_BOOLEAN) {
                $f['power'] = $cid;
            }
        }
        return $f;
    }
}

/**
 * Sonos-Player aus tkugelberg/Sonos. Bedienung über SNS_*-Funktionen (Gruppenlogik bleibt im Sonos-Modul konsistent),
 * Abspielen von Favoriten und Bibliothek per UPnP auf dem Gruppen-Koordinator.
 */
class SonosDevice extends SNRBaseDevice
{
    public function Kind(): string
    {
        return 'sonos';
    }

    private function Ident(string $Ident, ?int $Instance = null): int
    {
        $id = @IPS_GetObjectIDByIdent($Ident, $Instance ?? $this->instance);
        return $id === false ? 0 : (int) $id;
    }

    private function Value(string $Ident, mixed $Default = null, ?int $Instance = null): mixed
    {
        $vid = $this->Ident($Ident, $Instance);
        return $vid > 0 ? GetValue($vid) : $Default;
    }

    /** Instanz, die die Wiedergabe steuert (eigene oder Gruppen-Koordinator) */
    public function Coordinator(): int
    {
        $m = (int) $this->Value('MemberOfGroup', 0);
        return ($m > 0 && IPS_InstanceExists($m)) ? $m : $this->instance;
    }

    public function StatusVariable(): int
    {
        return $this->Ident('Status');
    }

    public function MemberOf(): int
    {
        return (int) $this->Value('MemberOfGroup', 0);
    }

    public function Members(): array
    {
        try {
            // GetMembers kann bei leerer Gruppe „[0]“ liefern
            return array_values(array_filter(array_map('intval', (array) json_decode(SNS_GetMembers($this->instance), true)), fn ($m) => $m > 0 && IPS_InstanceExists($m)));
        } catch (Throwable $e) {
            return [];
        }
    }

    public function Watch(): array
    {
        $ids = [$this->PowerVar(), $this->Var('VolumeVariable'), $this->Var('MuteVariable')];
        foreach (['Status', 'Volume', 'Mute', 'MemberOfGroup', 'nowPlaying', 'Title', 'Artist', 'CoverURL'] as $ident) {
            $ids[] = $this->Ident($ident);
        }
        foreach (array_keys(self::TONE) as $which) {
            $ids[] = $this->ToneVar($which);
        }
        return array_values(array_filter($ids));
    }

    /** Klangeinstellungen: Ident in der Sonos-Instanz bzw. Variablenname (falls der Ident abweicht) */
    private const TONE = [
        'bass'     => [['Bass'], ['Tiefen', 'Bässe', 'Bass']],
        'treble'   => [['Treble'], ['Höhen', 'Treble']],
        'loudness' => [['Loudness'], ['Loudness']]
    ];

    /** Variable einer Klangeinstellung (0 = in der Instanz nicht freigeschaltet) */
    private function ToneVar(string $Which): int
    {
        if (!isset(self::TONE[$Which]) || $this->instance <= 0) {
            return 0;
        }
        [$idents, $names] = self::TONE[$Which];
        foreach ($idents as $ident) {
            $vid = $this->Ident($ident);
            if ($vid > 0) {
                return $vid;
            }
        }
        foreach (IPS_GetChildrenIDs($this->instance) as $cid) {
            if (IPS_VariableExists($cid) && in_array(IPS_GetName($cid), $names, true)) {
                return $cid;
            }
        }
        return 0;
    }

    /** Werte der freigeschalteten Klangeinstellungen: bass/treble -10…10, loudness bool; null = keine */
    private function Tone(): ?array
    {
        $out = [];
        foreach (array_keys(self::TONE) as $which) {
            $vid = $this->ToneVar($which);
            if ($vid > 0) {
                $out[$which] = $which === 'loudness' ? self::ToBool(GetValue($vid)) : (int) GetValue($vid);
            }
        }
        return $out ?: null;
    }

    public function SetTone(string $Which, int $Value): void
    {
        $vid = $this->ToneVar($Which);
        if ($vid === 0) {
            throw new Exception($this->Name() . ': ' . $Which . ' ist in der Sonos-Instanz nicht freigeschaltet');
        }
        self::WriteVar($vid, $Which === 'loudness' ? $Value !== 0 : max(-10, min(10, $Value)));
    }

    public function Capabilities(): array
    {
        return [
            'power'     => $this->PowerVar() > 0,
            'volume'    => true,
            'mute'      => $this->MuteVar() > 0,
            'transport' => true,
            'group'     => true,
            'media'     => true
        ];
    }

    private function MuteVar(): int
    {
        return $this->Var('MuteVariable') ?: $this->Ident('Mute');
    }

    public function Available(): bool
    {
        if ($this->instance <= 0 || $this->Power() === false) {
            return false;
        }
        return IPS_GetInstance($this->instance)['InstanceStatus'] === IS_ACTIVE;
    }

    public function State(): array
    {
        $s = $this->BaseState();
        $s['available'] = $this->Available();
        $coord = $this->Coordinator();
        $status = (int) $this->Value('Status', 1, $coord);
        $s['state'] = $status === 2 ? 'playing' : ($status === 3 ? 'paused' : 'stopped');
        $vv = $this->Var('VolumeVariable');
        $s['volume'] = $vv > 0 ? (int) round((float) GetValue($vv)) : (int) $this->Value('Volume', 0);
        $mv = $this->MuteVar();
        $s['muted'] = $mv > 0 ? self::ToBool(GetValue($mv)) : null;
        $s['tone'] = $this->Tone();
        $title = (string) $this->Value('Title', '', $coord);
        $s['title'] = $title !== '' ? $title : (string) $this->Value('nowPlaying', '', $coord);
        $s['artist'] = (string) $this->Value('Artist', '', $coord);
        $s['image'] = (string) $this->Value('CoverURL', '', $coord);
        // Schlüssel für das Cover: ändert sich mit dem Titel bzw. Sender (Bild selbst lädt das Modul nur bei Änderung)
        $s['coverKey'] = $coord . '|' . $s['title'] . '|' . $s['artist'] . '|' . $s['image'] . '|' . (string) $this->Value('nowPlaying', '', $coord);
        $s['coordinator'] = $coord;
        $s['instance'] = $this->instance;
        $s['memberOf'] = $this->MemberOf();
        return $s;
    }

    public function Command(string $Command): void
    {
        switch ($Command) {
            case 'play':
                SNS_Play($this->instance);
                break;
            case 'pause':
                SNS_Pause($this->instance);
                break;
            case 'stop':
                SNS_Stop($this->instance);
                break;
            case 'next':
                SNS_Next($this->instance);
                break;
            case 'previous':
                SNS_Previous($this->instance);
                break;
            case 'play_pause':
                if ((int) $this->Value('Status', 1, $this->Coordinator()) === 2) {
                    SNS_Pause($this->instance);
                } else {
                    SNS_Play($this->instance);
                }
                break;
        }
    }

    public function SetVolume(int $Volume): void
    {
        $vv = $this->Var('VolumeVariable');
        if ($vv > 0) {
            self::WriteVar($vv, $Volume);
        } else {
            SNS_SetVolume($this->instance, $Volume);
        }
    }

    public function SetMute(bool $Mute): void
    {
        $mv = $this->Var('MuteVariable');
        if ($mv > 0) {
            self::WriteVar($mv, $Mute);
        } else {
            SNS_SetMute($this->instance, $Mute);
        }
    }

    /**
     * Vor dem Ausschalten: Wiedergabe anhalten und Gruppe verlassen bzw. auflösen,
     * damit die übrigen Geräte nicht ohne Koordinator dastehen.
     */
    public function SetPower(bool $On): void
    {
        if (!$On && $this->Available()) {
            try {
                if ($this->MemberOf() > 0) {
                    SNS_SetGroup($this->instance, 0);
                } else {
                    foreach ($this->Members() as $m) {
                        @SNS_SetGroup($m, 0);
                    }
                    if ((int) $this->Value('Status', 1) === 2) {
                        SNS_Pause($this->instance);
                    }
                }
            } catch (Throwable $e) {
                // Gerät evtl. schon nicht mehr erreichbar – trotzdem ausschalten
            }
        }
        parent::SetPower($On);
    }

    /** Einer Gruppe beitreten (0 = Gruppe verlassen) */
    public function JoinGroup(int $Coordinator): void
    {
        if ($Coordinator > 0) {
            // ein Master kann nicht beitreten – erst eigene Gruppe auflösen
            $this->DissolveGroup();
        }
        SNS_SetGroup($this->instance, $Coordinator);
    }

    public function DissolveGroup(): void
    {
        foreach ($this->Members() as $m) {
            SNS_SetGroup($m, 0);
        }
    }

    public function UPnP(?int $Instance = null): SonosUPnP
    {
        $inst = $Instance ?? $this->instance;
        $ip = trim((string) IPS_GetProperty($inst, 'IPAddress'));
        if ($ip === '') {
            throw new Exception('Sonos-Instanz ' . $inst . ' hat keine IP-Adresse');
        }
        return new SonosUPnP($ip, (int) @IPS_GetProperty($inst, 'TimeOut') ?: 3000);
    }

    public function PlayItem(array $Item): void
    {
        if (!$this->Available()) {
            throw new Exception($this->Name() . ' ist ausgeschaltet');
        }
        $coord = $this->Coordinator();
        $this->UPnP($coord)->PlayItem($Item, (string) IPS_GetProperty($coord, 'RINCON'));
    }

    /** Titel einzeln in die Warteschlange (Ersatzweg, wenn ein Album/eine Playlist des Dienstes nicht als Ganzes geht) */
    public function PlayTracks(array $Items): void
    {
        if (!$this->Available()) {
            throw new Exception($this->Name() . ' ist ausgeschaltet');
        }
        $coord = $this->Coordinator();
        $this->UPnP($coord)->PlayTracks($Items, (string) IPS_GetProperty($coord, 'RINCON'));
    }

    /** Kennung für Musikdienste; ersatzweise aus der MAC-Adresse in der RINCON gebildet */
    public function ServiceDeviceId(): string
    {
        $id = $this->UPnP()->DeviceSerial();
        if ($id === '' && preg_match('~^RINCON_([0-9A-F]{12})~i', (string) IPS_GetProperty($this->instance, 'RINCON'), $m)) {
            $id = strtoupper(implode('-', str_split($m[1], 2))) . ':0';
        }
        return $id;
    }

    /**
     * Aktuelle Wiedergabe als Favoriteneintrag (Radio = Sender, sonst aktueller Titel).
     */
    /**
     * Adresse des Covers bzw. Senderlogos der laufenden Wiedergabe – direkt vom Player (unabhängig von der Variable CoverURL).
     */
    public function CoverUrl(): string
    {
        $coord = $this->Coordinator();
        $u = $this->UPnP($coord);
        $var = trim((string) $this->Value('CoverURL', '', $coord));
        if ($var !== '') {
            return $u->ArtUrl($var);
        }
        $pos = $u->GetPositionInfo();
        $img = (string) ($u->ParseDidl((string) ($pos['TrackMetaData'] ?? ''))[0]['image'] ?? '');
        if ($img !== '') {
            return $img;
        }
        $media = $u->GetMediaInfo();
        $img = (string) ($u->ParseDidl((string) ($media['CurrentURIMetaData'] ?? ''))[0]['image'] ?? '');
        if ($img !== '') {
            return $img;
        }
        // TuneIn-Sender: Logo über die Sender-ID
        if (preg_match('~x-sonosapi-stream:(s\d+)~i', (string) ($media['CurrentURI'] ?? '') . ' ' . (string) ($pos['TrackURI'] ?? ''), $m)) {
            return 'https://cdn-profiles.tunein.com/' . $m[1] . '/images/logoq.png';
        }
        return '';
    }

    public function NowPlayingItem(): ?array
    {
        $coord = $this->Coordinator();
        $u = $this->UPnP($coord);
        $media = $u->GetMediaInfo();
        $curUri = (string) ($media['CurrentURI'] ?? '');
        if ($curUri !== '' && !str_starts_with($curUri, 'x-rincon-queue:') && SonosUPnP::IsStream($curUri)) {
            $meta = (string) ($media['CurrentURIMetaData'] ?? '');
            $info = $u->ParseDidl($meta)[0] ?? [];
            $name = (string) ($info['title'] ?? '') ?: (string) $this->Value('nowPlaying', 'Radio', $coord);
            return ['type' => 'radio', 'uri' => $curUri, 'meta' => $meta, 'name' => $name, 'sub' => '', 'image' => (string) $this->Value('CoverURL', '', $coord)];
        }
        $pos = $u->GetPositionInfo();
        $trackUri = (string) ($pos['TrackURI'] ?? '');
        if ($trackUri === '') {
            return null;
        }
        $meta = (string) ($pos['TrackMetaData'] ?? '');
        $info = $u->ParseDidl($meta)[0] ?? [];
        return [
            'type'  => 'track',
            'uri'   => $trackUri,
            'meta'  => $meta,
            'name'  => (string) ($info['title'] ?? ''),
            'sub'   => trim((string) ($info['creator'] ?? '') . ((($info['album'] ?? '') !== '') ? ' · ' . $info['album'] : '')),
            'image' => (string) ($info['image'] ?? '')
        ];
    }
}
