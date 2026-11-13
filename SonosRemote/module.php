<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/DeviceDrivers.php';

/**
 * Sonos-Kachel (HTML-SDK)
 *
 * Geräteauswahl per Pulldown, An/Aus über Schaltaktoren (z. B. Z2M-Steckdosen), eine Gruppe über den Master-Schalter,
 * Favoriten je Art (Playlist, Radio, Titel, Interpret, Album) mit 20 Plätzen, Bibliothek der Sonos-Anlage.
 * Sonos-Player kommen aus tkugelberg/Sonos; weitere Geräte laufen über GenericDevice (Variablen) außerhalb der Gruppierung.
 */
class SonosRemote extends IPSModuleStrict
{
    private const FAV_TYPES = ['playlist', 'radio', 'track', 'artist', 'album'];
    private const FAV_SLOTS = 20;

    /** Quellen der Bibliothek: Schlüssel => [ObjectID, Suche auf dem Player, Favoritenart (leer = aus UPnP-Klasse)] */
    private const SOURCES = [
        'fv'     => ['FV:2', false, ''],
        'sq'     => ['SQ:', false, 'playlist'],
        'radio'  => ['R:0/0', false, 'radio'],
        'artist' => ['A:ALBUMARTIST', true, 'artist'],
        'album'  => ['A:ALBUM', true, 'album'],
        'track'  => ['A:TRACKS', true, 'track']
    ];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Devices', '[]');
        $this->RegisterPropertyInteger('BrowseLimit', 100);
        $this->RegisterPropertyInteger('AccentColor', 0x1DB954);

        $this->RegisterAttributeString('Favorites', '{}');
        $this->RegisterAttributeString('Watched', '[]');
        $this->RegisterAttributeString('GroupMaster', '');      // Schlüssel des Master-Geräts ('' = keine Gruppe)
        $this->RegisterAttributeString('GroupSelection', '[]'); // für die Gruppe ausgewählte Geräte
        $this->RegisterAttributeString('Coupled', '[]');        // gekoppelte Nicht-Sonos-Geräte

        $this->RegisterTimer('Flush', 0, 'SNR_Flush($_IPS[\'TARGET\']);');

        $this->SetVisualizationType(1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        foreach ((array) json_decode($this->ReadAttributeString('Watched'), true) as $vid) {
            $this->UnregisterMessage((int) $vid, VM_UPDATE);
        }
        $watch = [];
        foreach ($this->Devices() as $dev) {
            foreach ($dev->Watch() as $vid) {
                if (!in_array($vid, $watch, true)) {
                    $this->RegisterMessage($vid, VM_UPDATE);
                    $watch[] = $vid;
                }
            }
        }
        $this->WriteAttributeString('Watched', json_encode($watch));
        $this->SetStatus(IS_ACTIVE);
        $this->RememberMasterStatus();
        $this->PushFull();
    }

    public function GetVisualizationTile(): string
    {
        $initial = '<script>handleMessage(' . json_encode(json_encode(['type' => 'full', 'state' => $this->BuildState()])) . ');</script>';
        $accent = sprintf('#%06X', $this->ReadPropertyInteger('AccentColor') & 0xFFFFFF);
        return file_get_contents(__DIR__ . '/module.html') . '<style>:root{--sr-accent:' . $accent . ';}</style>' . $initial;
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message !== VM_UPDATE) {
            return;
        }
        // Wiedergabe des Masters an gekoppelte Nicht-Sonos-Geräte weitergeben (auch bei Bedienung über die Sonos-App)
        if (!empty($Data[1]) && $SenderID === (int) $this->GetBuffer('MasterStatusVar')) {
            $cmd = [2 => 'play', 3 => 'pause', 1 => 'stop'][(int) $Data[0]] ?? '';
            if ($cmd !== '') {
                $this->MirrorToCoupled($cmd);
            }
        }
        // Änderungen kurz sammeln (Sonos aktualisiert mehrere Variablen nacheinander)
        $this->SetTimerInterval('Flush', 300);
    }

    public function Flush(): void
    {
        $this->SetTimerInterval('Flush', 0);
        if ($this->ReadAttributeString('GroupMaster') !== '') {
            // ausgewählte Geräte, die gerade hochgefahren sind, nachträglich in die Gruppe holen
            $this->ApplyGroup();
        }
        $this->PushFull();
    }

    /**
     * Aufrufe aus der Kachel: Ident = Aktion, Value = JSON
     */
    public function RequestAction(string $Ident, mixed $Value): void
    {
        $v = is_string($Value) ? (json_decode($Value, true) ?? []) : (array) $Value;
        $id = (string) ($v['id'] ?? '');
        try {
            switch ($Ident) {
                case 'refresh':
                    $this->PushFull();
                    break;
                case 'importSonos':
                    $this->ImportSonos();
                    break;
                case 'cmd':
                    $cmd = (string) ($v['cmd'] ?? '');
                    if (in_array($cmd, ['play', 'pause', 'play_pause', 'stop', 'next', 'previous'], true)) {
                        $this->Device($id)->Command($cmd);
                    }
                    break;
                case 'volume':
                    $this->Device($id)->SetVolume(max(0, min(100, (int) ($v['value'] ?? 0))));
                    break;
                case 'mute':
                    $this->Device($id)->SetMute((bool) ($v['value'] ?? false));
                    break;
                case 'power':
                    $this->SetPower($id, (bool) ($v['value'] ?? false));
                    break;
                case 'master':
                    $this->SetMaster($id, (bool) ($v['value'] ?? false));
                    $this->PushFull();
                    break;
                case 'select':
                    $this->SelectForGroup($id, (bool) ($v['value'] ?? false));
                    break;
                case 'playFav':
                    $this->PlayFavoriteOn((string) ($v['type'] ?? ''), (int) ($v['slot'] ?? -1) + 1, $id);
                    break;
                case 'playBrowse':
                    $item = $this->BrowseItem((int) ($v['idx'] ?? -1));
                    if ($item) {
                        $this->PlayOn($item, $id);
                    }
                    break;
                case 'browse':
                    $this->Browse((string) ($v['source'] ?? 'fv'), (string) ($v['query'] ?? ''));
                    break;
                case 'favAddBrowse':
                    $item = $this->BrowseItem((int) ($v['idx'] ?? -1));
                    if ($item) {
                        $type = (string) ($v['type'] ?? '');
                        $this->FavAdd(in_array($type, self::FAV_TYPES, true) ? $type : $item['type'], $item);
                    }
                    break;
                case 'favNow':
                    $this->FavFromNowPlaying($id);
                    break;
                case 'favRename':
                    $this->FavChange((string) ($v['type'] ?? ''), (int) ($v['slot'] ?? -1), 'rename', (string) ($v['name'] ?? ''));
                    break;
                case 'favDelete':
                    $this->FavChange((string) ($v['type'] ?? ''), (int) ($v['slot'] ?? -1), 'delete');
                    break;
                case 'favMove':
                    $this->FavChange((string) ($v['type'] ?? ''), (int) ($v['slot'] ?? -1), ((int) ($v['dir'] ?? 0)) < 0 ? 'up' : 'down');
                    break;
                default:
                    $this->SendDebug('RequestAction', 'unbekannt: ' . $Ident, 0);
            }
        } catch (Throwable $e) {
            $this->SendDebug($Ident, $e->getMessage(), 0);
            $this->Toast($e->getMessage());
        }
    }

    // ---------- öffentliche Befehle (SNR_*) ----------

    /** Gerät ein-/ausschalten. $Device = Instanz-ID des Geräts (bzw. Schlüssel aus der Liste) */
    public function SetPower(string $Device, bool $On): bool
    {
        $dev = $this->Device($Device);
        if (!$On && $Device === $this->ReadAttributeString('GroupMaster')) {
            $this->EndGroup();
        }
        if (!$On) {
            $this->Uncouple($Device);
        }
        $dev->SetPower($On);
        if ($On && $dev instanceof SonosDevice) {
            $this->Toast(sprintf($this->Translate('%s is switching on – Sonos needs about a minute'), $dev->Name()));
        }
        return true;
    }

    /**
     * Master an: die ausgewählten Geräte spielen mit $Device (Sonos synchron, andere gekoppelt).
     * Master aus: Gruppe auflösen.
     */
    public function SetMaster(string $Device, bool $On): bool
    {
        $master = $this->Device($Device);
        if (!$master instanceof SonosDevice) {
            throw new Exception($this->Translate('Only a Sonos device can be master'));
        }
        if (!$On) {
            if ($this->ReadAttributeString('GroupMaster') === $Device) {
                $this->EndGroup();
            } else {
                $master->DissolveGroup();
            }
            return true;
        }
        if (!$master->Available()) {
            throw new Exception(sprintf($this->Translate('%s is switched off'), $master->Name()));
        }
        $selection = array_values(array_diff($this->Selection(), [$Device]));
        if (count($selection) === 0) {
            throw new Exception($this->Translate('Select the devices for the group first (switch „Group“)'));
        }
        // nur eine Gruppe: alte Gruppe beenden, Master freistellen
        $this->EndGroup();
        if ($master->MemberOf() > 0) {
            SNS_SetGroup($master->InstanceID(), 0);
        }
        $this->WriteAttributeString('GroupSelection', json_encode($selection));
        $this->WriteAttributeString('GroupMaster', $Device);
        $this->RememberMasterStatus();
        $this->ApplyGroup();
        return true;
    }

    /** Gerät für die Gruppe aus-/abwählen. Bei aktiver Gruppe wird es sofort hinzugenommen bzw. herausgenommen. */
    public function SelectForGroup(string $Device, bool $Selected): bool
    {
        $this->Device($Device); // prüft, ob es das Gerät gibt
        if ($Device === $this->ReadAttributeString('GroupMaster')) {
            return false;
        }
        $sel = array_values(array_diff($this->Selection(), [$Device]));
        if ($Selected) {
            $sel[] = $Device;
        }
        $this->WriteAttributeString('GroupSelection', json_encode($sel));
        $this->ApplyGroup();
        $this->PushFull();
        return true;
    }

    /** Kompatibilität: Gerät der Gruppe hinzufügen bzw. herausnehmen */
    public function JoinMaster(string $Device, bool $Join): bool
    {
        return $this->SelectForGroup($Device, $Join);
    }

    /** Favorit abspielen. $Slot 1–20. $Device leer = erstes eingeschaltetes Sonos-Gerät */
    public function PlayFavorite(string $Type, int $Slot, string $Device): bool
    {
        try {
            return $this->PlayFavoriteOn($Type, $Slot, $Device);
        } catch (Throwable $e) {
            $this->SendDebug('PlayFavorite', $e->getMessage(), 0);
            return false;
        }
    }

    public function GetFavorites(): string
    {
        return json_encode($this->Favorites());
    }

    // ---------- Geräte ----------

    /** @return SNRDevice[] */
    private function Devices(): array
    {
        return SNRDeviceFactory::FromList((array) json_decode($this->ReadPropertyString('Devices'), true));
    }

    private function Device(string $Key): SNRDevice
    {
        $devs = $this->Devices();
        if (!isset($devs[$Key])) {
            throw new Exception(sprintf($this->Translate('Unknown device %s'), $Key));
        }
        return $devs[$Key];
    }

    // ---------- Gruppe ----------

    private function Selection(): array
    {
        return array_values(array_map('strval', (array) json_decode($this->ReadAttributeString('GroupSelection'), true)));
    }

    private function Coupled(): array
    {
        return array_values(array_map('strval', (array) json_decode($this->ReadAttributeString('Coupled'), true)));
    }

    private function MasterDevice(): ?SonosDevice
    {
        $key = $this->ReadAttributeString('GroupMaster');
        if ($key === '') {
            return null;
        }
        $d = $this->Devices()[$key] ?? null;
        return $d instanceof SonosDevice ? $d : null;
    }

    /**
     * Gruppe an die Auswahl angleichen (wiederholbar, ändert nur Abweichungen):
     * Sonos tritt synchron bei, andere Geräte werden gekoppelt (einschalten, Eingang wählen, Wiedergabe folgt dem Master).
     */
    private function ApplyGroup(): void
    {
        $master = $this->MasterDevice();
        if ($master === null) {
            $this->WriteAttributeString('GroupMaster', '');
            return;
        }
        $sel = $this->Selection();
        $coupled = $this->Coupled();
        $mi = $master->InstanceID();
        foreach ($this->Devices() as $key => $d) {
            if ($key === $master->Key()) {
                continue;
            }
            $want = in_array((string) $key, $sel, true);
            try {
                if ($d instanceof SonosDevice) {
                    if ($want && $master->Available() && $d->Available() && $d->MemberOf() !== $mi) {
                        $d->JoinGroup($mi);
                    } elseif (!$want && $d->MemberOf() === $mi) {
                        SNS_SetGroup($d->InstanceID(), 0);
                    }
                } elseif ($want && !in_array((string) $key, $coupled, true)) {
                    $d->PrepareForGroup();
                    $coupled[] = (string) $key;
                    // läuft der Master schon, gleich mitstarten
                    if ($d->Capabilities()['transport'] && ($master->State()['state'] ?? '') === 'playing') {
                        $d->Command('play');
                    }
                } elseif (!$want && in_array((string) $key, $coupled, true)) {
                    $coupled = array_values(array_diff($coupled, [(string) $key]));
                }
            } catch (Throwable $e) {
                $this->SendDebug('Gruppe ' . $key, $e->getMessage(), 0);
            }
        }
        $this->WriteAttributeString('Coupled', json_encode(array_values(array_unique($coupled))));
    }

    /** Gruppe beenden: Sonos-Mitglieder herauslösen, Kopplungen lösen. Die Auswahl bleibt für das nächste Mal erhalten. */
    private function EndGroup(): void
    {
        $master = $this->MasterDevice();
        if ($master !== null) {
            try {
                $master->DissolveGroup();
            } catch (Throwable $e) {
                $this->SendDebug('Gruppe auflösen', $e->getMessage(), 0);
            }
        }
        $this->WriteAttributeString('GroupMaster', '');
        $this->WriteAttributeString('Coupled', '[]');
        $this->SetBuffer('MasterStatusVar', '0');
    }

    private function Uncouple(string $Key): void
    {
        $this->WriteAttributeString('Coupled', json_encode(array_values(array_diff($this->Coupled(), [$Key]))));
    }

    private function RememberMasterStatus(): void
    {
        $master = $this->MasterDevice();
        $this->SetBuffer('MasterStatusVar', (string) ($master ? $master->StatusVariable() : 0));
    }

    private function MirrorToCoupled(string $Command): void
    {
        $devs = $this->Devices();
        foreach ($this->Coupled() as $key) {
            $d = $devs[$key] ?? null;
            if ($d === null || !$d->Capabilities()['transport'] || $d->State()['power'] === false) {
                continue;
            }
            try {
                $d->Command($Command);
            } catch (Throwable $e) {
                $this->SendDebug('Kopplung ' . $key, $e->getMessage(), 0);
            }
        }
    }

    private function ImportSonos(): void
    {
        $rows = (array) json_decode($this->ReadPropertyString('Devices'), true);
        $have = array_map(fn ($r) => (int) ($r['InstanceID'] ?? 0), $rows);
        $added = 0;
        foreach (IPS_GetInstanceListByModuleID(SNRDeviceFactory::SONOS_PLAYER) as $iid) {
            if (!in_array($iid, $have, true)) {
                $rows[] = ['InstanceID' => $iid, 'Name' => '', 'PowerVariable' => 0, 'VolumeVariable' => 0, 'MuteVariable' => 0, 'StatusVariable' => 0, 'TitleVariable' => 0, 'SourceVariable' => 0, 'SourceValue' => ''];
                $added++;
            }
        }
        if ($added > 0) {
            IPS_SetProperty($this->InstanceID, 'Devices', json_encode($rows));
            IPS_ApplyChanges($this->InstanceID);
        }
        echo sprintf($this->Translate('%d Sonos players added'), $added);
    }

    private function BuildState(): array
    {
        $devs = $this->Devices();
        $list = [];
        $byInstance = [];
        foreach ($devs as $key => $d) {
            try {
                $s = $d->State();
            } catch (Throwable $e) {
                $s = ['id' => $key, 'kind' => $d->Kind(), 'name' => $key, 'caps' => $d->Capabilities(), 'power' => null, 'available' => false, 'state' => 'stopped', 'volume' => null, 'muted' => null, 'title' => '', 'artist' => '', 'image' => ''];
                $this->SendDebug('State ' . $key, $e->getMessage(), 0);
            }
            $s['master'] = false;
            $s['members'] = [];
            $s['syncedTo'] = '';
            $list[$key] = $s;
            if (!empty($s['instance'])) {
                $byInstance[(int) $s['instance']] = $key;
            }
        }
        // Gruppen aus MemberOfGroup der Sonos-Instanzen ableiten
        foreach ($list as $key => $s) {
            $m = (int) ($s['memberOf'] ?? 0);
            if ($m > 0 && isset($byInstance[$m])) {
                $mk = $byInstance[$m];
                $list[$key]['syncedTo'] = $mk;
                $list[$mk]['master'] = true;
                $list[$mk]['members'][] = $key;
            }
        }
        // Auswahl, Master der Kachel und gekoppelte Nicht-Sonos-Geräte
        $gm = $this->ReadAttributeString('GroupMaster');
        $sel = $this->Selection();
        $coupled = $this->Coupled();
        foreach ($list as $key => $s) {
            $list[$key]['selected'] = in_array((string) $key, $sel, true);
            $list[$key]['coupled'] = false;
        }
        if ($gm !== '' && isset($list[$gm])) {
            $list[$gm]['master'] = true;
            foreach ($coupled as $ck) {
                if (isset($list[$ck]) && $ck !== $gm) {
                    $list[$ck]['coupled'] = true;
                    $list[$ck]['syncedTo'] = $gm;
                    $list[$gm]['members'][] = $ck;
                }
            }
        }
        return [
            'devices'  => array_values($list),
            'hasSonos' => count(array_filter($devs, fn ($d) => $d instanceof SonosDevice)) > 0,
            'favs'     => $this->PublicFavorites()
        ];
    }

    private function PushFull(): void
    {
        $this->UpdateVisualizationValue(json_encode(['type' => 'full', 'state' => $this->BuildState()]));
    }

    private function Toast(string $Text): void
    {
        $this->UpdateVisualizationValue(json_encode(['type' => 'toast', 'text' => $Text]));
    }

    // ---------- Abspielen ----------

    private function PlayOn(array $Item, string $Device): bool
    {
        $dev = null;
        if ($Device !== '') {
            $dev = $this->Device($Device);
        } else {
            foreach ($this->Devices() as $d) {
                if ($d instanceof SonosDevice && $d->Available()) {
                    $dev = $d;
                    break;
                }
            }
        }
        if ($dev === null) {
            throw new Exception($this->Translate('No Sonos device is switched on'));
        }
        if (!$dev->Capabilities()['media']) {
            throw new Exception(sprintf($this->Translate('%s cannot play favorites'), $dev instanceof SNRBaseDevice ? $dev->Name() : $Device));
        }
        $dev->PlayItem($Item);
        $this->Toast(sprintf($this->Translate('Playing %s'), (string) ($Item['name'] ?? '')));
        return true;
    }

    private function PlayFavoriteOn(string $Type, int $Slot, string $Device): bool
    {
        $fav = $this->Favorites()[$Type][$Slot - 1] ?? null;
        if (!is_array($fav)) {
            throw new Exception($this->Translate('Favorite slot is empty'));
        }
        return $this->PlayOn($fav + ['type' => $Type], $Device);
    }

    // ---------- Bibliothek ----------

    private function BrowseDevice(): SonosDevice
    {
        foreach ($this->Devices() as $d) {
            if ($d instanceof SonosDevice && $d->Available()) {
                return $d;
            }
        }
        throw new Exception($this->Translate('No Sonos device is switched on'));
    }

    private function Browse(string $Source, string $Query): void
    {
        if (!isset(self::SOURCES[$Source])) {
            return;
        }
        [$object, $serverSearch, $fixedType] = self::SOURCES[$Source];
        $Query = trim($Query);
        if ($serverSearch && $Query !== '') {
            $object .= ':' . rawurlencode($Query);
        }
        $dev = $this->BrowseDevice();
        $u = $dev->UPnP();
        $res = $u->Browse($object, max(10, min(500, $this->ReadPropertyInteger('BrowseLimit'))));
        $items = [];
        foreach ($res['items'] as $it) {
            if ($it['uri'] === '' || $it['title'] === '') {
                continue;
            }
            if (!$serverSearch && $Query !== '' && mb_stripos($it['title'], $Query) === false) {
                continue;
            }
            $type = $fixedType ?: SonosUPnP::TypeFromClass($it['class'], $it['uri']);
            if ($Source === 'fv') {
                $meta = $it['resMD'];
            } elseif ($Source === 'radio') {
                $meta = SonosUPnP::RadioMeta($it['title']);
            } else {
                $meta = SonosUPnP::LibraryMeta($it);
            }
            $sub = $it['creator'] !== '' ? $it['creator'] : $it['desc'];
            if ($it['album'] !== '' && $type === 'track') {
                $sub = trim($sub . ' · ' . $it['album'], ' ·');
            }
            $items[] = ['uri' => $it['uri'], 'meta' => $meta, 'name' => $it['title'], 'sub' => $sub, 'image' => $it['image'], 'type' => $type];
        }
        $this->SetBuffer('Browse', json_encode($items));
        $public = [];
        foreach ($items as $i => $it) {
            $public[] = ['idx' => $i, 'name' => $it['name'], 'sub' => $it['sub'], 'image' => $it['image'], 'type' => $it['type'], 'uri' => $it['uri']];
        }
        $this->UpdateVisualizationValue(json_encode([
            'type'   => 'browse',
            'source' => $Source,
            'query'  => $Query,
            'total'  => $res['total'],
            'items'  => $public
        ]));
    }

    private function BrowseItem(int $Idx): ?array
    {
        $items = json_decode($this->GetBuffer('Browse'), true);
        return is_array($items[$Idx] ?? null) ? $items[$Idx] : null;
    }

    // ---------- Favoriten: je Art 20 Plätze ----------

    private function Favorites(): array
    {
        $f = json_decode($this->ReadAttributeString('Favorites'), true);
        $out = [];
        foreach (self::FAV_TYPES as $t) {
            $list = array_values((array) ($f[$t] ?? []));
            $list = array_slice(array_pad($list, self::FAV_SLOTS, null), 0, self::FAV_SLOTS);
            $out[$t] = array_map(fn ($x) => (is_array($x) && !empty($x['uri'])) ? $x : null, $list);
        }
        return $out;
    }

    /** Favoriten ohne Metadaten für die Kachel */
    private function PublicFavorites(): array
    {
        $out = [];
        foreach ($this->Favorites() as $t => $list) {
            $out[$t] = array_map(fn ($x) => $x ? ['uri' => $x['uri'], 'name' => $x['name'], 'sub' => $x['sub'], 'image' => $x['image']] : null, $list);
        }
        return $out;
    }

    private function SaveFavorites(array $Favs): void
    {
        $this->WriteAttributeString('Favorites', json_encode($Favs));
        $this->UpdateVisualizationValue(json_encode(['type' => 'favs', 'favs' => $this->PublicFavorites()]));
    }

    private function FavAdd(string $Type, array $Item): void
    {
        $uri = (string) ($Item['uri'] ?? '');
        if (!in_array($Type, self::FAV_TYPES, true) || $uri === '') {
            return;
        }
        $favs = $this->Favorites();
        foreach ($favs[$Type] as $f) {
            if (is_array($f) && $f['uri'] === $uri) {
                $this->Toast($this->Translate('Already in favorites'));
                return;
            }
        }
        $slot = array_search(null, $favs[$Type], true);
        if ($slot === false) {
            $this->Toast($this->Translate('All 20 slots are taken'));
            return;
        }
        $favs[$Type][$slot] = [
            'uri'   => $uri,
            'meta'  => (string) ($Item['meta'] ?? ''),
            'name'  => mb_substr(trim((string) ($Item['name'] ?? '')), 0, 80),
            'sub'   => mb_substr(trim((string) ($Item['sub'] ?? '')), 0, 80),
            'image' => (string) ($Item['image'] ?? '')
        ];
        $this->SaveFavorites($favs);
        $this->Toast(sprintf($this->Translate('Saved to slot %d'), $slot + 1));
    }

    private function FavFromNowPlaying(string $Device): void
    {
        $dev = $this->Device($Device);
        if (!$dev instanceof SonosDevice) {
            throw new Exception($this->Translate('Only Sonos devices can save favorites'));
        }
        $item = $dev->NowPlayingItem();
        if ($item === null) {
            throw new Exception($this->Translate('Nothing is playing'));
        }
        $this->FavAdd($item['type'], $item);
    }

    private function FavChange(string $Type, int $Slot, string $Action, string $Name = ''): void
    {
        if (!in_array($Type, self::FAV_TYPES, true) || $Slot < 0 || $Slot >= self::FAV_SLOTS) {
            return;
        }
        $favs = $this->Favorites();
        $list = $favs[$Type];
        switch ($Action) {
            case 'rename':
                if (is_array($list[$Slot]) && trim($Name) !== '') {
                    $list[$Slot]['name'] = mb_substr(trim($Name), 0, 80);
                }
                break;
            case 'delete':
                $list[$Slot] = null;
                break;
            case 'up':
            case 'down':
                $to = $Slot + ($Action === 'up' ? -1 : 1);
                if ($to < 0 || $to >= self::FAV_SLOTS) {
                    return;
                }
                [$list[$Slot], $list[$to]] = [$list[$to], $list[$Slot]];
                break;
        }
        $favs[$Type] = $list;
        $this->SaveFavorites($favs);
    }
}
