<?php

declare(strict_types=1);

/**
 * Fehler eines Musikdienstes (SOAP-Fault der Sonos Music API)
 */
class SonosSMAPIFault extends Exception
{
    public string $FaultCode;
    public string $Detail;

    public function __construct(string $FaultCode, string $Message, string $Detail = '')
    {
        parent::__construct($Message);
        $this->FaultCode = $FaultCode;
        $this->Detail = $Detail;
    }
}

/**
 * Zugriff auf Musikdienste über die Sonos Music API (SMAPI) – derselbe Weg, den die Sonos-App nutzt:
 * Anmeldung per AppLink/DeviceLink, Suche, Durchblättern. Abgespielt wird über den Sonos-Player (SonosMedia).
 *
 * $Service: sid, name, uri (SecureUri), auth (AppLink|DeviceLink|Anonymous), token, key
 */
class SonosSMAPI
{
    public const NS = 'http://www.sonos.com/Services/1.1';
    private const UA = 'Linux UPnP/1.0 Sonos/29.3-87071 (ICRU_iPhone7,1); iOS/Version 8.2 (Build 12D508)';

    private array $svc;
    private string $deviceId;
    private string $householdId;

    /** Neue Zugangsdaten nach automatischer Erneuerung durch den Dienst – vom Aufrufer zu speichern */
    public ?array $Refreshed = null;

    public function __construct(array $Service, string $DeviceId, string $HouseholdId)
    {
        $this->svc = $Service + ['sid' => 0, 'name' => '', 'uri' => '', 'auth' => 'Anonymous', 'token' => '', 'key' => ''];
        $this->deviceId = $DeviceId;
        $this->householdId = $HouseholdId;
        if ($this->svc['uri'] === '') {
            throw new Exception($this->svc['name'] . ': keine Adresse des Dienstes');
        }
    }

    // ---------- Anmeldung ----------

    /**
     * Anmeldung starten. Liefert url (Anmeldeseite), code, show (Code anzeigen), devId (linkDeviceId)
     */
    public function BeginLink(): array
    {
        if ($this->svc['auth'] === 'AppLink') {
            $r = $this->call('getAppLink', ['householdId' => $this->householdId]);
            $node = self::First($r, 'deviceLink') ?? $r;
        } else {
            $node = $this->call('getDeviceLinkCode', ['householdId' => $this->householdId]);
        }
        $url = self::Text($node, 'regUrl');
        if ($url === '') {
            throw new Exception($this->svc['name'] . ': keine Anmeldeseite erhalten');
        }
        return [
            'url'   => $url,
            'code'  => self::Text($node, 'linkCode'),
            'show'  => strtolower(self::Text($node, 'showLinkCode')) === 'true',
            'devId' => self::Text($node, 'linkDeviceId')
        ];
    }

    /**
     * Anmeldung abschließen. null = Benutzer hat sich noch nicht angemeldet (erneut versuchen)
     */
    public function FinishLink(string $Code, string $LinkDeviceId): ?array
    {
        $args = ['householdId' => $this->householdId, 'linkCode' => $Code];
        if ($LinkDeviceId !== '') {
            $args['linkDeviceId'] = $LinkDeviceId;
        }
        try {
            $r = $this->call('getDeviceAuthToken', $args);
        } catch (SonosSMAPIFault $e) {
            if (stripos($e->FaultCode . ' ' . $e->getMessage() . ' ' . $e->Detail, 'NOT_LINKED_RETRY') !== false) {
                return null;
            }
            throw $e;
        }
        $token = self::Text($r, 'authToken');
        return $token === '' ? null : ['token' => $token, 'key' => self::Text($r, 'privateKey')];
    }

    // ---------- Inhalte ----------

    public function Search(string $Category, string $Term, int $Index = 0, int $Count = 100): array
    {
        return $this->Items($this->call('search', ['id' => $Category, 'term' => $Term, 'index' => $Index, 'count' => $Count]));
    }

    public function Metadata(string $Id, int $Index = 0, int $Count = 100): array
    {
        return $this->Items($this->call('getMetadata', ['id' => $Id, 'index' => $Index, 'count' => $Count, 'recursive' => 'false']));
    }

    /**
     * Suchkategorien aus der Presentation Map des Dienstes: [{id (mappedId), type, label}]
     */
    public static function Categories(string $PresentationMapUrl): array
    {
        $found = [];
        if ($PresentationMapUrl !== '') {
            try {
                [, $xml] = self::Http($PresentationMapUrl);
                $doc = new DOMDocument();
                if (@$doc->loadXML($xml)) {
                    $xp = new DOMXPath($doc);
                    $q = "//*[local-name()='PresentationMap'][@type='Search']//*[local-name()='Category' or local-name()='CustomCategory']";
                    foreach ($xp->query($q) as $c) {
                        /** @var DOMElement $c */
                        $mapped = $c->getAttribute('mappedId');
                        $key = strtolower($c->getAttribute('id') ?: $c->getAttribute('stringId'));
                        if ($mapped !== '' && $key !== '') {
                            $found[$key] = $mapped;
                        }
                    }
                }
            } catch (Throwable $e) {
                // ohne Presentation Map: Standardkategorien
            }
        }
        if (!$found) {
            $found = ['tracks' => 'tracks', 'albums' => 'albums', 'artists' => 'artists', 'playlists' => 'playlists'];
        }
        $known = [
            'tracks'    => ['track', 'Tracks'],
            'albums'    => ['album', 'Albums'],
            'artists'   => ['artist', 'Artists'],
            'playlists' => ['playlist', 'Playlists'],
            'stations'  => ['radio', 'Radio'],
            'podcasts'  => ['playlist', 'Podcasts']
        ];
        $out = [];
        foreach ($known as $key => [$type, $label]) {
            if (isset($found[$key])) {
                $out[] = ['id' => $found[$key], 'type' => $type, 'label' => $label];
                unset($found[$key]);
            }
        }
        foreach ($found as $key => $mapped) {
            $out[] = ['id' => $mapped, 'type' => 'playlist', 'label' => ucfirst($key)];
        }
        return $out;
    }

    // ---------- intern ----------

    private function call(string $Method, array $Args, bool $Retry = true): DOMElement
    {
        $h = fn ($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $body = '';
        foreach ($Args as $k => $v) {
            $body .= '<' . $k . '>' . $h($v) . '</' . $k . '>';
        }
        $cred = '<deviceId>' . $h($this->deviceId) . '</deviceId><deviceProvider>Sonos</deviceProvider>';
        if ($this->svc['token'] !== '') {
            $cred .= '<loginToken><token>' . $h($this->svc['token']) . '</token><key>' . $h($this->svc['key']) . '</key><householdId>' . $h($this->householdId) . '</householdId></loginToken>';
        }
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            . '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<s:Header><credentials xmlns="' . self::NS . '">' . $cred . '</credentials></s:Header>'
            . '<s:Body><' . $Method . ' xmlns="' . self::NS . '">' . $body . '</' . $Method . '></s:Body></s:Envelope>';

        [$status, $resp] = self::Http($this->svc['uri'], $xml, [
            'Content-Type: text/xml; charset="utf-8"',
            'SOAPACTION: "' . self::NS . '#' . $Method . '"',
            'Accept-Language: de-DE'
        ]);
        $doc = new DOMDocument();
        if (trim($resp) === '' || !@$doc->loadXML($resp)) {
            throw new Exception($this->svc['name'] . ': keine gültige Antwort auf ' . $Method . ' (HTTP ' . $status . ')');
        }
        $fault = self::First($doc->documentElement, 'Fault');
        if ($fault !== null) {
            $code = self::Text($fault, 'faultcode');
            $text = self::Text($fault, 'faultstring');
            $detail = self::Text($fault, 'detail');
            // Dienst verlangt erneuerte Zugangsdaten und liefert sie gleich mit
            if ($Retry && stripos($code . ' ' . $text, 'TokenRefreshRequired') !== false) {
                $token = self::Text($fault, 'authToken');
                if ($token !== '') {
                    $key = self::Text($fault, 'privateKey');
                    $this->svc['token'] = $token;
                    if ($key !== '') {
                        $this->svc['key'] = $key;
                    }
                    $this->Refreshed = ['token' => $this->svc['token'], 'key' => $this->svc['key']];
                    return $this->call($Method, $Args, false);
                }
            }
            if (preg_match('~AuthTokenExpired|LoginUnauthorized|LoginInvalid|SessionIdInvalid~i', $code . ' ' . $text)) {
                throw new SonosSMAPIFault($code, $this->svc['name'] . ': Anmeldung abgelaufen – Dienst in der Instanz neu verknüpfen', $detail);
            }
            throw new SonosSMAPIFault($code, $this->svc['name'] . ': ' . ($text !== '' ? $text : $code), $detail);
        }
        $res = self::First($doc->documentElement, $Method . 'Response');
        if ($res === null) {
            throw new Exception($this->svc['name'] . ': unerwartete Antwort auf ' . $Method . ' (HTTP ' . $status . ')');
        }
        return $res;
    }

    /** Ergebnis von search/getMetadata in einheitliche Einträge wandeln */
    private function Items(DOMElement $Response): array
    {
        $result = null;
        foreach ($Response->childNodes as $c) {
            if ($c instanceof DOMElement) {
                $result = $c;
                break;
            }
        }
        $items = [];
        $total = 0;
        if ($result !== null) {
            foreach ($result->childNodes as $n) {
                if (!$n instanceof DOMElement) {
                    continue;
                }
                switch ($n->localName) {
                    case 'total':
                        $total = (int) $n->textContent;
                        break;
                    case 'mediaCollection':
                        $items[] = self::Collection($n);
                        break;
                    case 'mediaMetadata':
                        $items[] = self::Media($n);
                        break;
                }
            }
        }
        return ['total' => max($total, count($items)), 'items' => $items];
    }

    private static function Collection(DOMElement $N): array
    {
        $type = strtolower(self::Child($N, 'itemType'));
        $kind = match (true) {
            $type === 'album'  => 'album',
            $type === 'artist' => 'artist',
            $type === 'program' => 'program',
            in_array($type, ['playlist', 'tracklist', 'albumlist', 'favorites', 'favorite', 'artisttracklist'], true) => 'playlist',
            default            => 'container'
        };
        $canPlay = strtolower(self::Child($N, 'canPlay')) === 'true' || $kind === 'program';
        $enum = strtolower(self::Child($N, 'canEnumerate'));
        $canOpen = $kind !== 'program' && $enum !== 'false';
        return [
            'id'    => self::Child($N, 'id'),
            'kind'  => $kind,
            'title' => self::Child($N, 'title'),
            'sub'   => self::Child($N, 'artist') ?: self::Child($N, 'authorName'),
            'image' => self::Child($N, 'albumArtURI'),
            'mime'  => '',
            'play'  => $canPlay,
            'open'  => $canOpen
        ];
    }

    private static function Media(DOMElement $N): array
    {
        $type = strtolower(self::Child($N, 'itemType'));
        $tm = self::First($N, 'trackMetadata');
        $sm = self::First($N, 'streamMetadata');
        $kind = match (true) {
            $type === 'program'                                   => 'program',
            in_array($type, ['stream', 'show'], true) || ($sm !== null && $tm === null) => 'stream',
            default                                               => 'track'
        };
        $sub = '';
        $image = self::Child($N, 'albumArtURI');
        if ($tm !== null) {
            $sub = trim(self::Child($tm, 'artist') . ((self::Child($tm, 'album') !== '') ? ' · ' . self::Child($tm, 'album') : ''), ' ·');
            $image = $image ?: self::Child($tm, 'albumArtURI');
        } elseif ($sm !== null) {
            $sub = self::Child($sm, 'currentShow') ?: self::Child($sm, 'currentHost');
            $image = $image ?: self::Child($sm, 'logo');
        }
        return [
            'id'    => self::Child($N, 'id'),
            'kind'  => $kind,
            'title' => self::Child($N, 'title'),
            'sub'   => $sub,
            'image' => $image,
            'mime'  => self::Child($N, 'mimeType'),
            'play'  => true,
            'open'  => false
        ];
    }

    /** Text eines direkten Kindelements (Namensraum egal) */
    private static function Child(DOMElement $N, string $Local): string
    {
        foreach ($N->childNodes as $c) {
            if ($c instanceof DOMElement && $c->localName === $Local) {
                return trim($c->textContent);
            }
        }
        return '';
    }

    /** erstes Element dieses Namens unterhalb von $N (Namensraum egal) */
    private static function First(DOMNode $N, string $Local): ?DOMElement
    {
        $doc = $N instanceof DOMDocument ? $N : $N->ownerDocument;
        if ($N instanceof DOMDocument) {
            $N = $doc->documentElement;
        }
        $list = $N->getElementsByTagNameNS('*', $Local);
        if ($list->length === 0) {
            $list = $N->getElementsByTagName($Local);
        }
        $e = $list->item(0);
        return $e instanceof DOMElement ? $e : null;
    }

    private static function Text(DOMNode $N, string $Local): string
    {
        $e = self::First($N, $Local);
        return $e ? trim($e->textContent) : '';
    }

    /** @return array{0:int,1:string} HTTP-Status, Antwort */
    public static function Http(string $Url, ?string $Post = null, array $Headers = [], int $Timeout = 10): array
    {
        $ch = curl_init($Url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $Timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_HTTPHEADER     => $Headers,
            CURLOPT_ENCODING       => ''
        ]);
        if ($Post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $Post);
        }
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp === false) {
            throw new Exception('HTTP ' . parse_url($Url, PHP_URL_HOST) . ': ' . curl_error($ch));
        }
        return [$status, (string) $resp];
    }
}

/**
 * Wiedergabe-URIs und DIDL-Metadaten für Einträge aus Musikdiensten.
 * Kontonummer (sn), Konto-Kennung (desc) und das URI-Schema für Titel werden aus den Sonos-Favoriten und der
 * Warteschlange gelernt; ohne Vorlage gelten die üblichen Schemata der Dienste.
 */
final class SonosMedia
{
    private const HEAD = '<DIDL-Lite xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:upnp="urn:schemas-upnp-org:metadata-1-0/upnp/" xmlns:r="urn:schemas-rinconnetworks-com:metadata-1-0/" xmlns="urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/">';

    /** Schemata, die keine Einzeltitel sind (werden nicht als Titel-Vorlage gelernt) */
    private const NOT_TRACK = ['x-rincon-cpcontainer:', 'x-sonosapi-stream:', 'x-sonosapi-radio:', 'x-sonosapi-hls:', 'x-rincon-mp3radio:', 'x-rincon-playlist:', 'x-rincon-queue:', 'x-rincon:', 'x-file-cifs:', 'file:', 'http:', 'https:', 'aac:', 'hls-radio:'];

    public const TUNEIN_SID = 254;

    /** Kennung wie in Sonos-URIs (URL-kodiert, Kleinbuchstaben in %xx) */
    public static function Esc(string $Id): string
    {
        return preg_replace_callback('/%[0-9A-F]{2}/', fn ($m) => strtolower($m[0]), rawurlencode($Id));
    }

    public static function FavType(string $Kind): string
    {
        return match ($Kind) {
            'track'             => 'track',
            'stream', 'program' => 'radio',
            'album'             => 'album',
            'artist'            => 'artist',
            default             => 'playlist'
        };
    }

    /**
     * @param array $It    Eintrag aus SonosSMAPI (id, kind, title, mime)
     * @param array $Acc   gelernte Angaben zum Dienst (sn, desc, trackScheme, trackExt, trackFlags)
     * @return array{0:string,1:string} URI, Metadaten
     */
    public static function Build(array $It, int $Sid, array $Acc): array
    {
        $eid = self::Esc((string) $It['id']);
        $st = $Sid * 256 + 7;
        $sn = (string) ($Acc['sn'] ?? '1');
        $desc = (string) ($Acc['desc'] ?? '');
        if ($desc === '') {
            $desc = 'SA_RINCON' . $st . '_X_#Svc' . $st . '-0-Token';
        }
        $q = fn (int $flags) => '?sid=' . $Sid . '&flags=' . $flags . '&sn=' . $sn;
        $title = (string) ($It['title'] ?? '');

        switch ($It['kind']) {
            case 'track':
                if (!empty($Acc['trackScheme'])) {
                    [$scheme, $ext, $flags] = [(string) $Acc['trackScheme'], (string) ($Acc['trackExt'] ?? ''), (int) ($Acc['trackFlags'] ?? 8224)];
                } else {
                    [$scheme, $ext, $flags] = self::TrackDefaults($Sid, (string) ($It['mime'] ?? ''));
                }
                return [$scheme . $eid . $ext . $q($flags), self::Didl('item', '10032020' . $eid, $title, 'object.item.audioItem.musicTrack', $desc)];
            case 'stream':
                return ['x-sonosapi-stream:' . $eid . $q(8224), self::Didl('item', 'F00092020' . $eid, $title, 'object.item.audioItem.audioBroadcast', $desc)];
            case 'program':
                return ['x-sonosapi-radio:' . $eid . $q(8300), self::Didl('item', '100c206c' . $eid, $title, 'object.item.audioItem.audioBroadcast', $desc)];
        }
        [$prefix, $class] = match ($It['kind']) {
            'album'    => ['0004206c', 'object.container.album.musicAlbum'],
            'artist'   => ['10052064', 'object.container.person.musicArtist'],
            'playlist' => ['0006206c', 'object.container.playlistContainer'],
            default    => ['000c206c', 'object.container.playlistContainer']
        };
        return ['x-rincon-cpcontainer:' . $prefix . $eid . $q(8300), self::Didl('container', $prefix . $eid, $title, $class, $desc)];
    }

    /** Übliche Titel-URIs der Dienste: [Schema, Endung, Flags] */
    private static function TrackDefaults(int $Sid, string $Mime): array
    {
        $m = strtolower($Mime);
        if (in_array($Sid, [9, 12], true)) {
            return ['x-sonos-spotify:', '', 8224];
        }
        if ($Sid === 201 || str_contains($m, 'mpegurl') || str_contains($m, 'hls')) {
            return ['x-sonosapi-hls-static:', '', 0];
        }
        $ext = match (true) {
            str_contains($m, 'flac')                                            => '.flac',
            str_contains($m, 'mpeg') || str_contains($m, 'mp3')                 => '.mp3',
            str_contains($m, 'mp4') || str_contains($m, 'aac') || str_contains($m, 'm4a') => '.mp4',
            default                                                             => ''
        };
        return ['x-sonos-http:', $ext, 8224];
    }

    public static function TuneIn(string $GuideId, string $Title, int $Sid = self::TUNEIN_SID): array
    {
        return [
            'x-sonosapi-stream:' . $GuideId . '?sid=' . $Sid . '&flags=8224&sn=0',
            self::Didl('item', 'F00092020' . $GuideId, $Title, 'object.item.audioItem.audioBroadcast', 'SA_RINCON65031_')
        ];
    }

    /**
     * Aus vorhandenen Einträgen (Sonos-Favoriten, Warteschlange) je Dienst Kontonummer, Konto-Kennung und Titel-Schema lernen.
     * @param array $Entries [['uri' => …, 'meta' => …]]
     * @param array $Known   bisher Gelerntes (sid => [...], 'tunein' => sid)
     */
    public static function Learn(array $Entries, array $Known): array
    {
        foreach ($Entries as $e) {
            $uri = html_entity_decode((string) ($e['uri'] ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8');
            if (!preg_match('~^([a-z0-9\-]+:)([^?]*)\?(.*)$~i', $uri, $m)) {
                continue;
            }
            parse_str($m[3], $q);
            $sid = (int) ($q['sid'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $scheme = strtolower($m[1]);
            if ($scheme === 'x-sonosapi-stream:' && preg_match('~^s\d+$~', $m[2])) {
                $Known['tunein'] = $sid;
                continue;
            }
            $a = (array) ($Known[$sid] ?? []);
            if (isset($q['sn']) && $q['sn'] !== '') {
                $a['sn'] = (string) $q['sn'];
            }
            if (preg_match('~>\s*(SA_RINCON\d+_[^<]*?)\s*</desc>~', (string) ($e['meta'] ?? ''), $d)) {
                $a['desc'] = html_entity_decode($d[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
            if (!in_array($scheme, self::NOT_TRACK, true)) {
                $a['trackScheme'] = $m[1];
                $a['trackExt'] = preg_match('~(\.(?:flac|mp3|mp4|m4a|ogg|aac|wma))$~i', $m[2], $x) ? $x[1] : '';
                $a['trackFlags'] = (int) ($q['flags'] ?? 8224);
            }
            $Known[$sid] = $a;
        }
        return $Known;
    }

    private static function Didl(string $Tag, string $Id, string $Title, string $Class, string $Desc): string
    {
        $h = fn ($s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return self::HEAD . '<' . $Tag . ' id="' . $h($Id) . '" parentID="-1" restricted="true"><dc:title>' . $h($Title) . '</dc:title><upnp:class>' . $Class . '</upnp:class><desc id="cdudn" nameSpace="urn:schemas-rinconnetworks-com:metadata-1-0/">' . $h($Desc) . '</desc></' . $Tag . '></DIDL-Lite>';
    }
}

/**
 * Internetradio über TuneIn (öffentliche OPML-Schnittstelle, ohne Konto)
 */
final class TuneIn
{
    public static function Search(string $Query): array
    {
        return self::Fetch('https://opml.radiotime.com/Search.ashx?types=station&query=' . rawurlencode($Query));
    }

    public static function Browse(string $Url = ''): array
    {
        return self::Fetch($Url !== '' ? $Url : 'https://opml.radiotime.com/Browse.ashx');
    }

    private static function Fetch(string $Url): array
    {
        $Url = preg_replace('~^http://~i', 'https://', $Url);
        if (!preg_match('~^https://opml\.radiotime\.com/~i', $Url)) {
            throw new Exception('TuneIn: ungültige Adresse');
        }
        $Url .= (str_contains($Url, '?') ? '&' : '?') . 'render=json&locale=de';
        [$status, $body] = SonosSMAPI::Http($Url);
        $j = json_decode($body, true);
        if (!is_array($j)) {
            throw new Exception('TuneIn: keine gültige Antwort (HTTP ' . $status . ')');
        }
        $out = [];
        self::Collect((array) ($j['body'] ?? []), $out);
        return ['total' => count($out), 'items' => $out];
    }

    private static function Collect(array $List, array &$Out): void
    {
        foreach ($List as $o) {
            if (!is_array($o)) {
                continue;
            }
            if (isset($o['children']) && is_array($o['children'])) {
                self::Collect($o['children'], $Out);
                continue;
            }
            $type = (string) ($o['type'] ?? '');
            $gid = (string) ($o['guide_id'] ?? ($o['preset_id'] ?? ''));
            $base = ['title' => (string) ($o['text'] ?? ''), 'sub' => (string) ($o['subtext'] ?? ''), 'image' => preg_replace('~^http://~i', 'https://', (string) ($o['image'] ?? ''))];
            if ($type === 'audio' && preg_match('~^s\d+$~', $gid)) {
                $Out[] = $base + ['id' => $gid, 'kind' => 'stream', 'play' => true, 'open' => false, 'url' => ''];
            } elseif ($type === 'link' && !empty($o['URL'])) {
                $Out[] = $base + ['id' => '', 'kind' => 'container', 'play' => false, 'open' => true, 'url' => (string) $o['URL']];
            }
        }
    }
}
