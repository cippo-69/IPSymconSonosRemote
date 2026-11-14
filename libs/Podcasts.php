<?php

declare(strict_types=1);

require_once __DIR__ . '/SonosSMAPI.php';

/**
 * Podcasts ohne Konto: Suche und Top-Liste über das öffentliche Podcast-Verzeichnis von Apple,
 * Folgen aus dem RSS-Feed des Podcasts. Abgespielt wird die Audiodatei der Folge direkt auf dem Sonos-Player.
 *
 * Einträge: kind 'show' (Podcast, ref = 'feed:<url>' oder 'id:<Apple-ID>') bzw. 'episode' (url = Audiodatei)
 */
final class Podcasts
{
    private const COUNTRY = 'de';

    private const DIDL_HEAD = '<DIDL-Lite xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:upnp="urn:schemas-upnp-org:metadata-1-0/upnp/" xmlns:r="urn:schemas-rinconnetworks-com:metadata-1-0/" xmlns="urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/">';

    public static function Search(string $Term, int $Limit = 50): array
    {
        $j = self::Json('https://itunes.apple.com/search?media=podcast&entity=podcast&country=' . self::COUNTRY . '&limit=' . max(1, min(200, $Limit)) . '&term=' . rawurlencode($Term));
        $out = [];
        foreach ((array) ($j['results'] ?? []) as $r) {
            if (!is_array($r) || empty($r['collectionName'])) {
                continue;
            }
            $feed = (string) ($r['feedUrl'] ?? '');
            $out[] = self::Show(
                (string) $r['collectionName'],
                (string) ($r['artistName'] ?? ''),
                (string) ($r['artworkUrl600'] ?? ($r['artworkUrl100'] ?? '')),
                $feed !== '' ? 'feed:' . $feed : 'id:' . (int) ($r['collectionId'] ?? 0)
            );
        }
        return ['total' => count($out), 'items' => $out];
    }

    /** Top-Podcasts (Apple-Charts), ersatzweise die ältere iTunes-Liste */
    public static function Top(int $Limit = 50): array
    {
        $Limit = max(1, min(100, $Limit));
        $out = [];
        try {
            $j = self::Json('https://rss.marketingtools.apple.com/api/v2/' . self::COUNTRY . '/podcasts/top/' . $Limit . '/podcasts.json');
            foreach ((array) ($j['feed']['results'] ?? []) as $r) {
                if (is_array($r) && !empty($r['id'])) {
                    $out[] = self::Show((string) ($r['name'] ?? ''), (string) ($r['artistName'] ?? ''), self::BigArt((string) ($r['artworkUrl100'] ?? '')), 'id:' . $r['id']);
                }
            }
        } catch (Throwable $e) {
            // Ersatzliste unten
        }
        if (!$out) {
            $j = self::Json('https://itunes.apple.com/' . self::COUNTRY . '/rss/toppodcasts/limit=' . $Limit . '/json');
            foreach ((array) ($j['feed']['entry'] ?? []) as $r) {
                $id = (string) ($r['id']['attributes']['im:id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $imgs = (array) ($r['im:image'] ?? []);
                $img = (string) (end($imgs)['label'] ?? '');
                $out[] = self::Show((string) ($r['im:name']['label'] ?? ''), (string) ($r['im:artist']['label'] ?? ''), self::BigArt($img), 'id:' . $id);
            }
        }
        return ['total' => count($out), 'items' => $out];
    }

    /** Feed-Adresse eines Podcasts ('feed:<url>' oder 'id:<Apple-ID>') */
    public static function FeedUrl(string $Ref): string
    {
        if (str_starts_with($Ref, 'feed:')) {
            return substr($Ref, 5);
        }
        if (preg_match('~^id:(\d+)$~', $Ref, $m)) {
            $j = self::Json('https://itunes.apple.com/lookup?entity=podcast&id=' . $m[1]);
            $feed = (string) ($j['results'][0]['feedUrl'] ?? '');
            if ($feed !== '') {
                return $feed;
            }
        }
        throw new Exception('Podcast: kein Feed gefunden');
    }

    /**
     * Folgen aus dem RSS-Feed, neueste zuerst. Nur Audio (Video-Podcasts kann Sonos nicht abspielen).
     */
    public static function Episodes(string $Ref, int $Limit = 50): array
    {
        $feed = self::FeedUrl($Ref);
        if (!preg_match('~^https?://~i', $feed)) {
            throw new Exception('Podcast: ungültige Feed-Adresse');
        }
        [$status, $body] = SonosSMAPI::Http($feed, null, ['Accept: application/rss+xml, application/xml, text/xml'], 20);
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_use_internal_errors($prev);
        if ($xml === false || !isset($xml->channel)) {
            throw new Exception('Podcast: Feed nicht lesbar (HTTP ' . $status . ')');
        }
        $ch = $xml->channel;
        $itNs = 'http://www.itunes.com/dtds/podcast-1.0.dtd';
        $show = trim((string) $ch->title);
        $chIt = $ch->children($itNs);
        $showImg = isset($chIt->image) ? (string) $chIt->image->attributes()['href'] : '';
        if ($showImg === '' && isset($ch->image->url)) {
            $showImg = (string) $ch->image->url;
        }
        $items = [];
        foreach ($ch->item as $it) {
            $enc = $it->enclosure;
            $url = isset($enc) ? trim((string) $enc->attributes()['url']) : '';
            $type = isset($enc) ? strtolower((string) $enc->attributes()['type']) : '';
            if ($url === '' || ($type !== '' && !str_starts_with($type, 'audio') && !str_contains($type, 'mpeg'))) {
                continue;
            }
            $iit = $it->children($itNs);
            $img = isset($iit->image) ? (string) $iit->image->attributes()['href'] : '';
            $ts = strtotime((string) $it->pubDate) ?: 0;
            $items[] = [
                'kind'  => 'episode',
                'title' => trim((string) $it->title),
                'sub'   => trim(($ts ? date('d.m.Y', $ts) : '') . ' · ' . self::Duration((string) ($iit->duration ?? '')), ' ·'),
                'image' => $img !== '' ? $img : $showImg,
                'url'   => $url,
                'show'  => $show,
                'ts'    => $ts,
                'play'  => true,
                'open'  => false,
                'ref'   => ''
            ];
        }
        usort($items, fn ($a, $b) => $b['ts'] <=> $a['ts']);
        $total = count($items);
        return ['total' => $total, 'items' => array_slice($items, 0, max(1, $Limit)), 'show' => $show, 'image' => $showImg];
    }

    /** Wiedergabe-URI und Metadaten einer Folge (Titel, Podcast, Bild erscheinen bei „Jetzt läuft“) */
    public static function EpisodeMedia(array $Ep): array
    {
        $h = fn ($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $meta = self::DIDL_HEAD . '<item id="-1" parentID="-1" restricted="true">'
            . '<dc:title>' . $h($Ep['title']) . '</dc:title>'
            . '<dc:creator>' . $h($Ep['show'] ?? '') . '</dc:creator>'
            . '<upnp:album>' . $h($Ep['show'] ?? '') . '</upnp:album>'
            . (($Ep['image'] ?? '') !== '' ? '<upnp:albumArtURI>' . $h($Ep['image']) . '</upnp:albumArtURI>' : '')
            . '<upnp:class>object.item.audioItem.musicTrack</upnp:class>'
            . '<desc id="cdudn" nameSpace="urn:schemas-rinconnetworks-com:metadata-1-0/">RINCON_AssociatedZPUDN</desc></item></DIDL-Lite>';
        return [(string) $Ep['url'], $meta];
    }

    private static function Show(string $Name, string $Artist, string $Image, string $Ref): array
    {
        return ['kind' => 'show', 'title' => $Name, 'sub' => $Artist, 'image' => $Image, 'url' => '', 'ref' => $Ref, 'play' => true, 'open' => true];
    }

    /** Apple-Bildadressen auf 600 px vergrößern */
    private static function BigArt(string $Url): string
    {
        return preg_replace('~/\d+x\d+bb\.(png|jpg)$~', '/600x600bb.$1', $Url);
    }

    private static function Duration(string $D): string
    {
        $D = trim($D);
        if ($D === '') {
            return '';
        }
        if (ctype_digit($D)) {
            $s = (int) $D;
        } else {
            $p = array_map('intval', explode(':', $D));
            $s = 0;
            foreach ($p as $v) {
                $s = $s * 60 + $v;
            }
        }
        if ($s <= 0) {
            return '';
        }
        return $s >= 3600 ? sprintf('%d:%02d h', intdiv($s, 3600), intdiv($s % 3600, 60)) : sprintf('%d min', max(1, (int) round($s / 60)));
    }

    private static function Json(string $Url): array
    {
        [$status, $body] = SonosSMAPI::Http($Url, null, ['Accept: application/json'], 15);
        $j = json_decode($body, true);
        if (!is_array($j)) {
            throw new Exception('Podcasts: keine gültige Antwort (HTTP ' . $status . ')');
        }
        return $j;
    }
}
