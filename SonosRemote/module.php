<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/DeviceDrivers.php';
require_once __DIR__ . '/../libs/SonosSMAPI.php';

/**
 * Sonos-Kachel (HTML-SDK)
 *
 * Geräteauswahl per Pulldown, An/Aus über Schaltaktoren (z. B. Z2M-Steckdosen), eine Gruppe (das zuerst ausgewählte Gerät ist Master),
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

        // Musikdienste (Sonos Music API)
        $this->RegisterAttributeString('Services', '{}');    // verknüpfte Dienste: sid => name, uri, auth, token, key, cats
        $this->RegisterAttributeString('ServiceList', '[]'); // auf der Anlage verfügbare Dienste (Zwischenspeicher)
        $this->RegisterAttributeString('Accounts', '{}');    // gelernt aus Favoriten/Warteschlange: sid => sn, desc, Titel-Schema
        $this->RegisterAttributeString('SmapiIds', '{}');    // Geräte- und Haushalts-Kennung für die Dienste

        $this->RegisterTimer('Flush', 0, 'SNR_Flush($_IPS[\'TARGET\']);');
        $this->RegisterTimer('LinkPoll', 0, 'SNR_LinkPoll($_IPS[\'TARGET\']);');

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
        $state = $this->BuildState();
        $initial = '';
        // eingebettete Cover, auf die der Zustand verweist, gleich mitliefern
        foreach ($state['devices'] as $d) {
            $img = (string) ($d['image'] ?? '');
            if (str_starts_with($img, 'cover:')) {
                $data = $this->GetBuffer('Cover_' . substr($img, 6));
                if ($data !== '') {
                    $initial .= '<script>handleMessage(' . json_encode(json_encode(['type' => 'cover', 'hash' => substr($img, 6), 'data' => $data])) . ');</script>';
                }
            }
        }
        $initial .= '<script>handleMessage(' . json_encode(json_encode(['type' => 'full', 'state' => $state])) . ');</script>';
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
        if (count($this->Selection()) > 0) {
            // ausgewählte Geräte, die gerade hochgefahren sind, nachträglich in die Gruppe holen (bzw. Master werden)
            $this->ApplyGroup();
        }
        $this->PushFull();
    }

    /**
     * Aufrufe aus der Kachel: Ident = Aktion, Value = JSON
     */
    public function RequestAction(string $Ident, mixed $Value): void
    {
        $v = is_string($Value) ? json_decode($Value, true) : $Value;
        $num = is_numeric($Value) ? (int) $Value : 0;   // Aufrufe aus dem Konfigurationsformular (Dienst-ID)
        $v = is_array($v) ? $v : [];
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
                    $src = (string) ($v['source'] ?? 'fv');
                    if ($src === 'tunein' || str_starts_with($src, 'svc:')) {
                        $this->BrowseService($src, (string) ($v['query'] ?? ''), (string) ($v['cat'] ?? ''), (int) ($v['open'] ?? -1), !empty($v['back']));
                    } else {
                        $this->Browse($src, (string) ($v['query'] ?? ''));
                    }
                    break;
                case 'loadServices':
                    $this->LoadServices();
                    break;
                case 'linkStart':
                    $this->LinkStart($num ?: (int) ($v['sid'] ?? 0));
                    break;
                case 'unlink':
                    $this->Unlink($num ?: (int) ($v['sid'] ?? 0));
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
            if (in_array($Ident, ['loadServices', 'linkStart', 'unlink'], true)) {
                echo $e->getMessage();   // Meldung im Konfigurationsformular
            }
        }
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        self::SetFormField($form['actions'], 'LinkService', 'options', $this->ServiceOptions());
        self::SetFormField($form['actions'], 'LinkedServices', 'values', $this->LinkedRows());
        $pending = json_decode($this->GetBuffer('Link'), true);
        if (is_array($pending)) {
            self::SetFormField($form['actions'], 'LinkInfo', 'caption', (string) ($pending['info'] ?? ''));
            self::SetFormField($form['actions'], 'LinkUrl', 'value', (string) $pending['url']);
            self::SetFormField($form['actions'], 'LinkUrl', 'visible', true);
            self::SetFormField($form['actions'], 'LinkOpen', 'visible', true);
        }
        return json_encode($form);
    }

    // ---------- öffentliche Befehle (SNR_*) ----------

    /** Gerät ein-/ausschalten. $Device = Instanz-ID des Geräts (bzw. Schlüssel aus der Liste) */
    public function SetPower(string $Device, bool $On): bool
    {
        $dev = $this->Device($Device);
        if (!$On && $Device === $this->ReadAttributeString('GroupMaster')) {
            // Master geht aus: Gruppe an das nächste ausgewählte Gerät übergeben (die Auswahl bleibt)
            $this->ApplyGroup($Device);
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
     * Gerät für die Gruppe aus-/abwählen. Das zuerst ausgewählte (eingeschaltete) Sonos-Gerät wird Master,
     * alle weiteren spielen mit (Sonos synchron, andere gekoppelt). Wird der Master abgewählt, übernimmt das nächste.
     */
    public function SelectForGroup(string $Device, bool $Selected): bool
    {
        $this->Device($Device); // prüft, ob es das Gerät gibt
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

    /** Anmeldeseite der laufenden Verknüpfung (für den Knopf im Konfigurationsformular) */
    public function GetLinkUrl(): string
    {
        $p = json_decode($this->GetBuffer('Link'), true);
        return is_array($p) ? (string) $p['url'] : '';
    }

    /** Timer: prüft, ob die Anmeldung beim Musikdienst abgeschlossen ist */
    public function LinkPoll(): void
    {
        $p = json_decode($this->GetBuffer('Link'), true);
        if (!is_array($p)) {
            $this->SetTimerInterval('LinkPoll', 0);
            return;
        }
        $svc = $this->AvailableService((int) $p['sid']);
        if ($svc === null || time() > (int) $p['until']) {
            $this->LinkDone($this->Translate('Linking timed out – please start again'));
            return;
        }
        $ids = $this->SmapiIds();
        try {
            $token = (new SonosSMAPI($svc, $ids['device'], $ids['household']))->FinishLink((string) $p['code'], (string) $p['devId']);
        } catch (Throwable $e) {
            $this->SendDebug('LinkPoll', $e->getMessage(), 0);
            $this->LinkDone($e->getMessage());
            return;
        }
        if ($token === null) {
            return;   // noch nicht angemeldet
        }
        $this->SaveLinkedService($svc, $token['token'], $token['key']);
        $this->LinkDone(sprintf($this->Translate('%s is linked'), $svc['name']));
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
    private function ApplyGroup(string $Exclude = ''): void
    {
        $sel = $this->Selection();
        $master = $this->MasterDevice();
        // Master abgewählt oder ausgeschaltet: Gruppe beenden, das nächste ausgewählte Gerät übernimmt
        if ($master !== null && (!in_array($master->Key(), $sel, true) || $master->Key() === $Exclude)) {
            $this->EndGroup();
            $master = null;
        }
        if ($master === null) {
            // Master wird das zuerst ausgewählte eingeschaltete Sonos-Gerät
            $devs = $this->Devices();
            foreach ($sel as $k) {
                $d = $devs[$k] ?? null;
                if ($d instanceof SonosDevice && $k !== $Exclude && $d->Available()) {
                    $master = $d;
                    break;
                }
            }
            if ($master === null) {
                $this->WriteAttributeString('GroupMaster', '');
                $this->WriteAttributeString('Coupled', '[]');
                return;
            }
            if ($master->MemberOf() > 0) {
                SNS_SetGroup($master->InstanceID(), 0);
            }
            $this->WriteAttributeString('GroupMaster', $master->Key());
            $this->RememberMasterStatus();
        }
        $coupled = $this->Coupled();
        $mi = $master->InstanceID();
        foreach ($this->Devices() as $key => $d) {
            if ($key === $master->Key() || $key === $Exclude) {
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
        $this->ResolveCovers($list, $devs);
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
            'favs'     => $this->PublicFavorites(),
            'services' => $this->PublicServices(),
            'groupMaster' => $gm
        ];
    }

    /**
     * Cover bzw. Senderlogo je Sonos-Gerät. Neu geladen wird nur, wenn sich Titel/Sender ändert.
     * https-Bilder gehen direkt an die Kachel; http-Bilder (z. B. vom Player auf Port 1400) werden eingebettet,
     * weil die Kachel sie über https (Symcon Connect, App) sonst nicht anzeigen darf.
     */
    private function ResolveCovers(array &$List, array $Devs): void
    {
        $cache = json_decode($this->GetBuffer('Covers'), true);
        $cache = is_array($cache) ? $cache : [];
        $used = [];
        foreach ($List as $key => &$s) {
            $ck = (string) ($s['coverKey'] ?? '');
            unset($s['coverKey']);
            $d = $Devs[$key] ?? null;
            if (!$d instanceof SonosDevice || empty($s['available']) || $ck === '') {
                continue;
            }
            if (!array_key_exists($ck, $cache)) {
                $cache[$ck] = $this->LoadCover($d);
            }
            $s['image'] = $cache[$ck];
            $used[$ck] = true;
        }
        unset($s);
        $this->SetBuffer('Covers', json_encode(array_intersect_key($cache, $used)));
    }

    private function LoadCover(SonosDevice $Device): string
    {
        try {
            $url = $Device->CoverUrl();
        } catch (Throwable $e) {
            $this->SendDebug('Cover', $e->getMessage(), 0);
            return '';
        }
        if ($url === '' || stripos($url, 'https://') === 0) {
            return $url;
        }
        $hash = md5($url);
        if ($this->GetBuffer('Cover_' . $hash) === '') {
            try {
                [$status, $bin] = SonosSMAPI::Http($url, null, [], 5);
            } catch (Throwable $e) {
                $this->SendDebug('Cover', $e->getMessage(), 0);
                return '';
            }
            $info = ($status === 200 && strlen($bin) < 800000) ? @getimagesizefromstring($bin) : false;
            if ($info === false) {
                $this->SendDebug('Cover', 'kein Bild (HTTP ' . $status . '): ' . $url, 0);
                return '';
            }
            $data = 'data:' . $info['mime'] . ';base64,' . base64_encode($bin);
            $this->SetBuffer('Cover_' . $hash, $data);
            // nur die letzten 12 Cover vorhalten
            $keep = array_values(array_diff((array) json_decode($this->GetBuffer('CoverList'), true), [$hash]));
            $keep[] = $hash;
            while (count($keep) > 12) {
                $this->SetBuffer('Cover_' . array_shift($keep), '');
            }
            $this->SetBuffer('CoverList', json_encode($keep));
            $this->UpdateVisualizationValue(json_encode(['type' => 'cover', 'hash' => $hash, 'data' => $data]));
        }
        return 'cover:' . $hash;
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
        try {
            $dev->PlayItem($Item);
        } catch (Throwable $e) {
            // Album/Playlist eines Musikdienstes, das sich nicht als Ganzes einreihen lässt: Titel einzeln
            $s = $Item['smapi'] ?? null;
            if (!is_array($s) || !$dev instanceof SonosDevice || in_array($s['kind'] ?? '', ['track', 'stream', 'program'], true)) {
                throw $e;
            }
            $this->SendDebug('PlayOn', 'als Ganzes fehlgeschlagen, Titel einzeln: ' . $e->getMessage(), 0);
            $tracks = $this->ServiceTracks((int) $s['sid'], (string) $s['id']);
            if (!$tracks) {
                throw $e;
            }
            $dev->PlayTracks($tracks);
        }
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

    // ---------- Musikdienste (Sonos Music API) und TuneIn ----------

    /**
     * Suchen und Durchblättern in einem Musikdienst ('svc:<sid>') oder TuneIn ('tunein').
     * Ohne Suchbegriff wird die Startebene des Dienstes gezeigt; geöffnete Ordner liegen auf einem Stapel (Zurück).
     */
    private function BrowseService(string $Source, string $Query, string $Cat, int $Open, bool $Back): void
    {
        $nav = json_decode($this->GetBuffer('Nav'), true);
        if (!is_array($nav) || ($nav['source'] ?? '') !== $Source || ($Open < 0 && !$Back)) {
            $nav = ['source' => $Source, 'query' => trim($Query), 'cat' => $Cat, 'stack' => []];
        }
        if ($Open >= 0) {
            $it = $this->BrowseItem($Open);
            if ($it && ($it['ref'] ?? '') !== '') {
                $nav['stack'][] = ['ref' => $it['ref'], 'title' => $it['name']];
            }
        }
        if ($Back) {
            array_pop($nav['stack']);
        }
        $this->SetBuffer('Nav', json_encode($nav));
        $top = $nav['stack'] ? $nav['stack'][count($nav['stack']) - 1] : null;
        $limit = max(10, min(100, $this->ReadPropertyInteger('BrowseLimit')));
        $this->EnsureLearned();
        $items = [];

        if ($Source === 'tunein') {
            $sid = (int) ($this->Accounts()['tunein'] ?? SonosMedia::TUNEIN_SID);
            if ($top) {
                $res = TuneIn::Browse((string) $top['ref']);
            } elseif ($nav['query'] !== '') {
                $res = TuneIn::Search($nav['query']);
            } else {
                $res = TuneIn::Browse();
            }
            foreach ($res['items'] as $r) {
                $e = ['name' => $r['title'], 'sub' => $r['sub'], 'image' => $r['image'], 'type' => 'radio', 'uri' => '', 'meta' => '', 'ref' => $r['url'], 'open' => $r['open']];
                if ($r['play']) {
                    [$e['uri'], $e['meta']] = SonosMedia::TuneIn($r['id'], $r['title'], $sid);
                }
                $items[] = $e;
            }
        } else {
            $sid = (int) substr($Source, 4);
            $client = $this->Client($sid);
            try {
                if ($top) {
                    $res = $client->Metadata((string) $top['ref'], 0, $limit);
                } elseif ($nav['query'] !== '') {
                    $cat = $nav['cat'] !== '' ? $nav['cat'] : (string) ($this->LinkedServices()[$sid]['cats'][0]['id'] ?? 'tracks');
                    $res = $client->Search($cat, $nav['query'], 0, $limit);
                } else {
                    $res = $client->Metadata('root', 0, $limit);
                }
            } finally {
                $this->SaveRefreshed($sid, $client);
            }
            $acc = (array) ($this->Accounts()[$sid] ?? []);
            foreach ($res['items'] as $r) {
                $e = ['name' => $r['title'], 'sub' => $r['sub'], 'image' => $r['image'], 'type' => SonosMedia::FavType($r['kind']), 'uri' => '', 'meta' => '', 'ref' => $r['open'] ? $r['id'] : '', 'open' => $r['open']];
                if ($r['play'] && $r['id'] !== '') {
                    [$e['uri'], $e['meta']] = SonosMedia::Build($r, $sid, $acc);
                    $e['smapi'] = ['sid' => $sid, 'id' => $r['id'], 'kind' => $r['kind']];
                }
                $items[] = $e;
            }
        }

        $this->SetBuffer('Browse', json_encode($items));
        $public = [];
        foreach ($items as $i => $it) {
            $public[] = ['idx' => $i, 'name' => $it['name'], 'sub' => $it['sub'], 'image' => $it['image'], 'type' => $it['type'], 'uri' => $it['uri'], 'play' => $it['uri'] !== '', 'open' => (bool) $it['open']];
        }
        $this->UpdateVisualizationValue(json_encode([
            'type'   => 'browse',
            'source' => $Source,
            'query'  => $nav['query'],
            'total'  => $res['total'],
            'items'  => $public,
            'path'   => array_column($nav['stack'], 'title'),
            'back'   => count($nav['stack']) > 0
        ]));
    }

    /** Titel eines Albums/einer Playlist (bis 100, eine Ordnerebene tiefer bei Interpreten) */
    private function ServiceTracks(int $Sid, string $Id): array
    {
        $client = $this->Client($Sid);
        $acc = (array) ($this->Accounts()[$Sid] ?? []);
        $out = [];
        $todo = [$Id];
        $visited = 0;
        try {
            while ($todo && count($out) < 100 && $visited < 12) {
                $res = $client->Metadata(array_shift($todo), 0, 100);
                $visited++;
                foreach ($res['items'] as $r) {
                    if ($r['kind'] === 'track') {
                        [$u, $m] = SonosMedia::Build($r, $Sid, $acc);
                        $out[] = ['uri' => $u, 'meta' => $m];
                        if (count($out) >= 100) {
                            break;
                        }
                    } elseif ($r['open'] && in_array($r['kind'], ['album', 'playlist', 'container'], true)) {
                        $todo[] = $r['id'];
                    }
                }
            }
        } finally {
            $this->SaveRefreshed($Sid, $client);
        }
        return $out;
    }

    private function Client(int $Sid): SonosSMAPI
    {
        $s = $this->LinkedServices()[$Sid] ?? null;
        if (!is_array($s)) {
            throw new Exception($this->Translate('The music service is not linked – link it in the instance configuration'));
        }
        $ids = $this->SmapiIds();
        return new SonosSMAPI($s, $ids['device'], $ids['household']);
    }

    /** vom Dienst erneuerte Zugangsdaten übernehmen */
    private function SaveRefreshed(int $Sid, SonosSMAPI $Client): void
    {
        if ($Client->Refreshed === null) {
            return;
        }
        $all = $this->LinkedServices();
        if (isset($all[$Sid])) {
            $all[$Sid]['token'] = $Client->Refreshed['token'];
            $all[$Sid]['key'] = $Client->Refreshed['key'];
            $this->WriteAttributeString('Services', json_encode($all));
        }
    }

    private function LinkedServices(): array
    {
        return (array) json_decode($this->ReadAttributeString('Services'), true);
    }

    private function Accounts(): array
    {
        return (array) json_decode($this->ReadAttributeString('Accounts'), true);
    }

    private function AvailableService(int $Sid): ?array
    {
        foreach ((array) json_decode($this->ReadAttributeString('ServiceList'), true) as $s) {
            if ((int) ($s['sid'] ?? 0) === $Sid) {
                return $s;
            }
        }
        return null;
    }

    /** Geräte- und Haushalts-Kennung einmal vom Sonos-Player holen */
    private function SmapiIds(): array
    {
        $ids = (array) json_decode($this->ReadAttributeString('SmapiIds'), true);
        if (($ids['device'] ?? '') === '' || ($ids['household'] ?? '') === '') {
            $dev = $this->BrowseDevice();
            $ids = ['device' => $dev->ServiceDeviceId(), 'household' => $dev->UPnP()->HouseholdID()];
            if ($ids['household'] === '') {
                throw new Exception('Sonos: Haushalts-Kennung nicht verfügbar');
            }
            $this->WriteAttributeString('SmapiIds', json_encode($ids));
        }
        return $ids;
    }

    /** Konto-Angaben je Dienst aus Sonos-Favoriten und Warteschlange lernen (einmal je Laufzeit bzw. auf Anforderung) */
    private function EnsureLearned(bool $Force = false): void
    {
        if (!$Force && $this->GetBuffer('Learned') === '1') {
            return;
        }
        try {
            $u = $this->BrowseDevice()->UPnP();
        } catch (Throwable $e) {
            return;
        }
        $entries = [];
        foreach (['FV:2', 'Q:0'] as $obj) {
            try {
                foreach ($u->Browse($obj, 200)['items'] as $it) {
                    $entries[] = ['uri' => $it['uri'], 'meta' => $it['resMD']];
                }
            } catch (Throwable $e) {
                $this->SendDebug('Lernen ' . $obj, $e->getMessage(), 0);
            }
        }
        $this->WriteAttributeString('Accounts', json_encode(SonosMedia::Learn($entries, $this->Accounts())));
        $this->SetBuffer('Learned', '1');
    }

    private function LoadServices(): void
    {
        $list = $this->BrowseDevice()->UPnP()->MusicServices();
        $this->EnsureLearned(true);
        $acc = $this->Accounts();
        // in der Anlage genutzte Dienste zuerst, dann alphabetisch
        usort($list, function ($a, $b) use ($acc) {
            $ua = isset($acc[$a['sid']]) ? 0 : 1;
            $ub = isset($acc[$b['sid']]) ? 0 : 1;
            return $ua <=> $ub ?: strcasecmp($a['name'], $b['name']);
        });
        $this->WriteAttributeString('ServiceList', json_encode($list));
        $this->UpdateFormField('LinkService', 'options', json_encode($this->ServiceOptions()));
        echo sprintf($this->Translate('%d music services found'), count($list));
    }

    private function LinkStart(int $Sid): void
    {
        $svc = $this->AvailableService($Sid);
        if ($svc === null) {
            throw new Exception($this->Translate('Load the music services first and select one'));
        }
        if ($svc['auth'] === 'Anonymous') {
            $this->SaveLinkedService($svc, '', '');
            $this->LinkDone(sprintf($this->Translate('%s is linked'), $svc['name']));
            return;
        }
        if (!in_array($svc['auth'], ['AppLink', 'DeviceLink'], true)) {
            throw new Exception(sprintf($this->Translate('%s uses the login type %s, which is not supported'), $svc['name'], $svc['auth']));
        }
        $ids = $this->SmapiIds();
        $link = (new SonosSMAPI($svc, $ids['device'], $ids['household']))->BeginLink();
        $info = sprintf($this->Translate('Open the login page and sign in to %s. The link is completed automatically.'), $svc['name']);
        if ($link['show'] && $link['code'] !== '') {
            $info .= ' ' . sprintf($this->Translate('Code: %s'), $link['code']);
        }
        $this->SetBuffer('Link', json_encode(['sid' => $Sid, 'code' => $link['code'], 'devId' => $link['devId'], 'url' => $link['url'], 'info' => $info, 'until' => time() + 600]));
        $this->SetTimerInterval('LinkPoll', 5000);
        $this->UpdateFormField('LinkInfo', 'caption', $info);
        $this->UpdateFormField('LinkUrl', 'value', $link['url']);
        $this->UpdateFormField('LinkUrl', 'visible', true);
        $this->UpdateFormField('LinkOpen', 'visible', true);
    }

    private function SaveLinkedService(array $Svc, string $Token, string $Key): void
    {
        $all = $this->LinkedServices();
        $all[(int) $Svc['sid']] = [
            'sid'   => (int) $Svc['sid'],
            'name'  => $Svc['name'],
            'uri'   => $Svc['uri'],
            'auth'  => $Svc['auth'],
            'token' => $Token,
            'key'   => $Key,
            'cats'  => SonosSMAPI::Categories((string) ($Svc['pm'] ?? ''))
        ];
        $this->WriteAttributeString('Services', json_encode($all));
        $this->EnsureLearned(true);
        $this->PushFull();
    }

    private function LinkDone(string $Info): void
    {
        $this->SetTimerInterval('LinkPoll', 0);
        $this->SetBuffer('Link', '');
        $this->UpdateFormField('LinkInfo', 'caption', $Info);
        $this->UpdateFormField('LinkUrl', 'visible', false);
        $this->UpdateFormField('LinkOpen', 'visible', false);
        $this->UpdateFormField('LinkedServices', 'values', json_encode($this->LinkedRows()));
        $this->UpdateFormField('LinkService', 'options', json_encode($this->ServiceOptions()));
    }

    private function Unlink(int $Sid): void
    {
        $all = $this->LinkedServices();
        unset($all[$Sid]);
        $this->WriteAttributeString('Services', json_encode($all));
        $this->LinkDone('');
        $this->PushFull();
    }

    private function ServiceOptions(): array
    {
        $linked = $this->LinkedServices();
        $acc = $this->Accounts();
        $opts = [['caption' => '-', 'value' => 0]];
        foreach ((array) json_decode($this->ReadAttributeString('ServiceList'), true) as $s) {
            $cap = $s['name'];
            if (isset($linked[$s['sid']])) {
                $cap .= ' ✓';
            } elseif (isset($acc[$s['sid']])) {
                $cap .= ' ★';
            }
            $opts[] = ['caption' => $cap, 'value' => (int) $s['sid']];
        }
        return $opts;
    }

    private function LinkedRows(): array
    {
        $acc = $this->Accounts();
        $rows = [];
        foreach ($this->LinkedServices() as $sid => $s) {
            $rows[] = ['Name' => $s['name'] . ' (' . $sid . ')', 'Auth' => $s['auth'], 'Account' => (string) ($acc[$sid]['sn'] ?? '–')];
        }
        return $rows;
    }

    /** Quellen für die Kachel: TuneIn und die verknüpften Dienste mit ihren Suchkategorien */
    private function PublicServices(): array
    {
        $out = [['key' => 'tunein', 'name' => 'TuneIn', 'cats' => []]];
        foreach ($this->LinkedServices() as $sid => $s) {
            $out[] = ['key' => 'svc:' . $sid, 'name' => $s['name'], 'cats' => array_map(fn ($c) => ['id' => $c['id'], 'type' => $c['type'], 'label' => $c['label']], (array) ($s['cats'] ?? []))];
        }
        return $out;
    }

    private static function SetFormField(array &$Elements, string $Name, string $Key, mixed $Value): void
    {
        foreach ($Elements as &$e) {
            if (!is_array($e)) {
                continue;
            }
            if (($e['name'] ?? '') === $Name) {
                $e[$Key] = $Value;
            }
            if (isset($e['items']) && is_array($e['items'])) {
                self::SetFormField($e['items'], $Name, $Key, $Value);
            }
        }
        unset($e);
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
        if (is_array($Item['smapi'] ?? null)) {
            $favs[$Type][$slot]['smapi'] = $Item['smapi'];
        }
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
