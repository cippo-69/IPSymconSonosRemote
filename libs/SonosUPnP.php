<?php

declare(strict_types=1);

/**
 * Schlanker UPnP-Zugriff auf einen Sonos-Player (Port 1400).
 * Aufbau und Kodierung wie libs/sonosAccess.php aus tkugelberg/Sonos, nur die hier benötigten Aufrufe.
 */
class SonosUPnP
{
    private const AVT = ['/MediaRenderer/AVTransport/Control', 'urn:schemas-upnp-org:service:AVTransport:1'];
    private const CD = ['/MediaServer/ContentDirectory/Control', 'urn:schemas-upnp-org:service:ContentDirectory:1'];
    private const MS = ['/MusicServices/Control', 'urn:schemas-upnp-org:service:MusicServices:1'];
    private const DP = ['/DeviceProperties/Control', 'urn:schemas-upnp-org:service:DeviceProperties:1'];
    private const SP = ['/SystemProperties/Control', 'urn:schemas-upnp-org:service:SystemProperties:1'];

    private const DIDL_HEAD = '<DIDL-Lite xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:upnp="urn:schemas-upnp-org:metadata-1-0/upnp/" xmlns:r="urn:schemas-rinconnetworks-com:metadata-1-0/" xmlns="urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/">';

    /** URIs, die direkt als Transport gesetzt werden (Streams/Radio) statt über die Warteschlange */
    private const STREAM_PREFIXES = ['x-sonosapi-stream:', 'x-sonosapi-radio:', 'x-rincon-mp3radio:', 'x-sonosapi-hls:', 'aac:', 'hls-radio:', 'x-rincon-stream:', 'x-sonos-htastream:', 'x-rincon:'];

    private string $address;
    private int $timeout;

    public function __construct(string $Address, int $TimeoutMs = 3000)
    {
        $this->address = $Address;
        $this->timeout = max(1, (int) ceil($TimeoutMs / 1000));
    }

    public function Address(): string
    {
        return $this->address;
    }

    // ---------- Transport ----------

    public function Play(): void
    {
        $this->call(self::AVT, 'Play', [new SoapParam('0', 'InstanceID'), new SoapParam('1', 'Speed')]);
    }

    public function SetAVTransportURI(string $Uri, string $Meta = ''): void
    {
        $this->call(self::AVT, 'SetAVTransportURI', [
            new SoapParam('0', 'InstanceID'),
            new SoapParam(htmlspecialchars($Uri, ENT_COMPAT | ENT_HTML401, 'UTF-8', false), 'CurrentURI'),
            new SoapParam($Meta, 'CurrentURIMetaData')
        ]);
    }

    public function ClearQueue(): void
    {
        $this->call(self::AVT, 'RemoveAllTracksFromQueue', [new SoapParam('0', 'InstanceID')]);
    }

    public function AddToQueue(string $Uri, string $Meta = '', bool $AsNext = true): void
    {
        $this->call(self::AVT, 'AddURIToQueue', [
            new SoapParam('0', 'InstanceID'),
            new SoapParam(htmlspecialchars($Uri), 'EnqueuedURI'),
            new SoapParam($Meta, 'EnqueuedURIMetaData'),
            new SoapParam('0', 'DesiredFirstTrackNumberEnqueued'),
            new SoapParam($AsNext ? '1' : '0', 'EnqueueAsNext')
        ]);
    }

    public function GetMediaInfo(): array
    {
        return (array) $this->call(self::AVT, 'GetMediaInfo', [new SoapParam('0', 'InstanceID')]);
    }

    public function GetPositionInfo(): array
    {
        return (array) $this->call(self::AVT, 'GetPositionInfo', [new SoapParam('0', 'InstanceID')]);
    }

    /**
     * Medium abspielen. Streams/Radio direkt, alles andere (Playlist, Album, Interpret, Titel) über die Warteschlange.
     */
    public function PlayItem(array $Item, string $Rincon): void
    {
        $uri = (string) ($Item['uri'] ?? '');
        $meta = (string) ($Item['meta'] ?? '');
        if ($uri === '') {
            throw new Exception('Keine URI');
        }
        // Streams/Radio direkt; Dateien mit http-Adresse (z. B. Podcast-Folgen, 'queue') über die Warteschlange
        if (($Item['type'] ?? '') === 'radio' || (self::IsStream($uri) && empty($Item['queue']))) {
            if ($meta === '') {
                $meta = self::RadioMeta((string) ($Item['name'] ?? 'Radio'));
            }
            $this->SetAVTransportURI($uri, $meta);
        } else {
            $this->ClearQueue();
            $this->AddToQueue($uri, $meta);
            $this->SetAVTransportURI('x-rincon-queue:' . $Rincon . '#0');
        }
        $this->Play();
    }

    /**
     * Mehrere Titel der Reihe nach in die geleerte Warteschlange und abspielen
     * @param array $Items [['uri' => …, 'meta' => …]]
     */
    public function PlayTracks(array $Items, string $Rincon): void
    {
        $this->ClearQueue();
        foreach ($Items as $it) {
            $this->AddToQueue((string) $it['uri'], (string) ($it['meta'] ?? ''), false);
        }
        $this->SetAVTransportURI('x-rincon-queue:' . $Rincon . '#0');
        $this->Play();
    }

    // ---------- Musikdienste ----------

    /**
     * Auf der Anlage verfügbare Musikdienste: [[sid, name, uri, auth, pm]]
     */
    public function MusicServices(): array
    {
        $res = (array) $this->call(self::MS, 'ListAvailableServices', []);
        $xml = (string) ($res['AvailableServiceDescriptorList'] ?? '');
        $doc = new DOMDocument();
        if ($xml === '' || !@$doc->loadXML($xml)) {
            return [];
        }
        $out = [];
        foreach ($doc->getElementsByTagName('Service') as $s) {
            /** @var DOMElement $s */
            $policy = $s->getElementsByTagName('Policy')->item(0);
            $pm = $s->getElementsByTagName('PresentationMap')->item(0);
            $out[] = [
                'sid'  => (int) $s->getAttribute('Id'),
                'name' => $s->getAttribute('Name'),
                'uri'  => $s->getAttribute('SecureUri') ?: $s->getAttribute('Uri'),
                'auth' => $policy instanceof DOMElement ? $policy->getAttribute('Auth') : 'Anonymous',
                'pm'   => $pm instanceof DOMElement ? $pm->getAttribute('Uri') : ''
            ];
        }
        return $out;
    }

    public function HouseholdID(): string
    {
        $r = $this->call(self::DP, 'GetHouseholdID', []);
        return is_scalar($r) ? (string) $r : (string) (((array) $r)['CurrentHouseholdID'] ?? '');
    }

    /** Geräte-Kennung für Musikdienste (wie die Sonos-App); leer, wenn nicht verfügbar */
    public function DeviceSerial(): string
    {
        try {
            $r = $this->call(self::SP, 'GetString', [new SoapParam('R_TrialZPSerial', 'VariableName')]);
            return is_scalar($r) ? (string) $r : (string) (((array) $r)['StringValue'] ?? '');
        } catch (Throwable $e) {
            return '';
        }
    }

    public static function IsStream(string $Uri): bool
    {
        foreach (self::STREAM_PREFIXES as $p) {
            if (stripos($Uri, $p) === 0) {
                return true;
            }
        }
        return (bool) preg_match('~^https?://~i', $Uri);
    }

    public static function RadioMeta(string $Name): string
    {
        return self::DIDL_HEAD . '<item id="-1" parentID="-1" restricted="true"><dc:title>' . htmlspecialchars($Name) . '</dc:title><upnp:class>object.item.audioItem.audioBroadcast</upnp:class><desc id="cdudn" nameSpace="urn:schemas-rinconnetworks-com:metadata-1-0/">SA_RINCON65031_</desc></item></DIDL-Lite>';
    }

    // ---------- Inhalte durchsuchen ----------

    /**
     * Liefert Einträge eines ContentDirectory-Containers in vereinfachter Form.
     */
    public function Browse(string $ObjectID, int $Count = 100, int $Start = 0): array
    {
        $res = (array) $this->call(self::CD, 'Browse', [
            new SoapParam($ObjectID, 'ObjectID'),
            new SoapParam('BrowseDirectChildren', 'BrowseFlag'),
            new SoapParam('', 'Filter'),
            new SoapParam($Start, 'StartingIndex'),
            new SoapParam($Count, 'RequestedCount'),
            new SoapParam('', 'SortCriteria')
        ]);
        return [
            'total' => (int) ($res['TotalMatches'] ?? 0),
            'items' => $this->ParseDidl((string) ($res['Result'] ?? ''))
        ];
    }

    public function ParseDidl(string $Xml): array
    {
        if (trim($Xml) === '') {
            return [];
        }
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($Xml);
        libxml_use_internal_errors($prev);
        if ($xml === false) {
            return [];
        }
        $out = [];
        foreach (['container', 'item'] as $tag) {
            foreach ($xml->{$tag} as $node) {
                $out[] = $this->NodeToItem($node, $tag === 'container');
            }
        }
        return $out;
    }

    private function NodeToItem(SimpleXMLElement $Node, bool $IsContainer): array
    {
        $dc = $Node->children('http://purl.org/dc/elements/1.1/');
        $up = $Node->children('urn:schemas-upnp-org:metadata-1-0/upnp/');
        $r = $Node->children('urn:schemas-rinconnetworks-com:metadata-1-0/');
        $attr = $Node->attributes();
        $item = [
            'id'        => (string) ($attr['id'] ?? ''),
            'parentID'  => (string) ($attr['parentID'] ?? ''),
            'container' => $IsContainer,
            'title'     => (string) ($dc->title ?? ''),
            'creator'   => (string) ($dc->creator ?? ''),
            'album'     => (string) ($up->album ?? ''),
            'class'     => (string) ($up->class ?? ''),
            'uri'       => (string) ($Node->res ?? ''),
            'image'     => $this->ArtUrl((string) ($up->albumArtURI ?? '')),
            'resMD'     => (string) ($r->resMD ?? ''),
            'desc'      => (string) ($r->description ?? '')
        ];
        // Sonos-Favoriten: eigentliche Klasse und Bild stehen in r:resMD
        if ($item['resMD'] !== '') {
            $inner = $this->ParseDidl($item['resMD']);
            if (isset($inner[0])) {
                $item['class'] = $inner[0]['class'] ?: $item['class'];
                if ($item['image'] === '') {
                    $item['image'] = $inner[0]['image'];
                }
            }
        }
        return $item;
    }

    public function ArtUrl(string $Uri): string
    {
        $Uri = trim($Uri);
        if ($Uri === '') {
            return '';
        }
        if (preg_match('~^https?://~i', $Uri)) {
            return $Uri;
        }
        return 'http://' . $this->address . ':1400' . (str_starts_with($Uri, '/') ? '' : '/') . $Uri;
    }

    /**
     * Metadaten für Einträge der lokalen Musikbibliothek / Sonos-Playlists (wie SoCo).
     */
    public static function LibraryMeta(array $Item): string
    {
        $tag = !empty($Item['container']) ? 'container' : 'item';
        return self::DIDL_HEAD . '<' . $tag . ' id="' . htmlspecialchars((string) $Item['id']) . '" parentID="' . htmlspecialchars((string) $Item['parentID']) . '" restricted="true"><dc:title>' . htmlspecialchars((string) $Item['title']) . '</dc:title><upnp:class>' . htmlspecialchars((string) $Item['class']) . '</upnp:class><desc id="cdudn" nameSpace="urn:schemas-rinconnetworks-com:metadata-1-0/">RINCON_AssociatedZPUDN</desc></' . $tag . '></DIDL-Lite>';
    }

    /**
     * Ordnet eine UPnP-Klasse einer Favoritenart zu.
     */
    public static function TypeFromClass(string $Class, string $Uri = ''): string
    {
        $c = strtolower($Class);
        if (str_contains($c, 'audiobroadcast') || ($Uri !== '' && self::IsStream($Uri) && !str_contains(strtolower($Uri), 'x-sonosapi-radio:'))) {
            return 'radio';
        }
        if (str_contains($c, 'musicartist') || str_contains($c, 'person')) {
            return 'artist';
        }
        if (str_contains($c, 'musicalbum') || str_contains($c, 'album')) {
            return 'album';
        }
        if (str_contains($c, 'musictrack') || str_contains($c, 'audioitem')) {
            return 'track';
        }
        return 'playlist';
    }

    // ---------- intern ----------

    private function call(array $Service, string $Action, array $Params): mixed
    {
        try {
            $client = new SoapClient(null, [
                'location'           => 'http://' . $this->address . ':1400' . $Service[0],
                'uri'                => $Service[1],
                'trace'              => true,
                'connection_timeout' => $this->timeout
            ]);
            return $client->__soapCall($Action, $Params);
        } catch (SoapFault $e) {
            $code = isset($e->detail->UPnPError->errorCode) ? ' UPnP ' . $e->detail->UPnPError->errorCode : '';
            throw new Exception('Sonos ' . $Action . ' (' . $this->address . '): ' . $e->getMessage() . $code);
        }
    }
}
