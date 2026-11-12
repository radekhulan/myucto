<?php

declare(strict_types=1);

namespace MyInvoice\Tooling;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Hlídač vyhlášení legislativy a číselníků pro mzdy (jen čtení, jen veřejné zdroje).
 *
 * Každý zdroj dá po stažení pozorování {fingerprint, summary, items}. Porovnává se s uloženým
 * stavem; první pozorování je výchozí stav (baseline) a nehlásí se. Zdroj, který nejde stáhnout,
 * je varování a jeho uložený stav zůstane beze změny. Nic se neinstaluje ani neimportuje.
 *
 * Druhy zdrojů (`kind`):
 *  - file:      binární soubor nebo export, otisk + velikost + Last-Modified (volitelně `pinned_sha256`)
 *  - page:      HTML stránka, otisk normalizovaného textu (volitelně jen část, `extract` = regex)
 *  - mpsv-page: JSON stránka vývojářského portálu MPSV (verze, příloha po příloze, otázky a odpovědi)
 *  - rss:       RSS, hlásí jen nové položky s klíčovým slovem (`keywords`)
 */
final class PayrollSourceWatcher
{
    public const STATE_SCHEMA_VERSION = 'payroll-source-watch.v1';

    private const MAX_FEED_ITEMS = 400;

    /** @var array<string,array<string,mixed>> */
    private array $sources;

    /** @var callable(string):array{status:int,body:string,headers:array<string,string>} */
    private $fetch;

    /**
     * @param array<string,array<string,mixed>> $sources
     * @param (callable(string):array{status:int,body:string,headers:array<string,string>})|null $fetch
     */
    public function __construct(array $sources, ?callable $fetch = null, private readonly int $maxBytes = 64 * 1024 * 1024)
    {
        foreach ($sources as $id => $source) {
            if (!is_string($id) || preg_match('/\A[a-z0-9][a-z0-9._-]*\z/D', $id) !== 1) {
                throw new InvalidArgumentException("Neplatný identifikátor zdroje {$id}.");
            }
            if (!in_array($source['kind'] ?? null, ['file', 'page', 'mpsv-page', 'rss'], true)) {
                throw new InvalidArgumentException("Zdroj {$id} má neznámý druh.");
            }
            if (!is_string($source['url'] ?? null) || !str_starts_with($source['url'], 'https://')) {
                throw new InvalidArgumentException("Zdroj {$id} musí mít https adresu.");
            }
        }
        $this->sources = $sources;
        $this->fetch = $fetch ?? $this->fetchCurl(...);
    }

    /**
     * @param list<string>|null $only
     * @return array{
     *     checked_at:string,
     *     baseline_created:bool,
     *     baselined:list<string>,
     *     changed:bool,
     *     change_count:int,
     *     changes:list<array{source:string,label:string,url:string,message:string,details:list<string>}>,
     *     warnings:list<string>,
     *     checked:int
     * }
     */
    public function watch(string $statePath, bool $persist = true, ?array $only = null, ?string $now = null): array
    {
        $now ??= gmdate('c');
        $state = $this->loadState($statePath);
        $previous = $state['sources'];
        $report = [
            'checked_at' => $now,
            'baseline_created' => false,
            'baselined' => [],
            'changed' => false,
            'change_count' => 0,
            'changes' => [],
            'warnings' => [],
            'checked' => 0,
        ];

        foreach ($this->sources as $id => $source) {
            if ($only !== null && !in_array($id, $only, true)) {
                continue;
            }
            $report['checked']++;
            try {
                $observation = $this->observe($source);
            } catch (Throwable $e) {
                $report['warnings'][] = "{$id}: {$e->getMessage()}";
                continue;
            }
            $old = $previous[$id] ?? null;
            if ($old === null) {
                $report['baselined'][] = $id;
                $pinned = $source['pinned_sha256'] ?? null;
                if (is_string($pinned) && $pinned !== '' && ($observation['sha256'] ?? '') !== $pinned) {
                    $report['changes'][] = $this->change($id, $source, 'Obsah se liší od připnutého v repu', [
                        'připnuto ' . substr($pinned, 0, 12) . ', na webu ' . substr((string) ($observation['sha256'] ?? ''), 0, 12),
                    ]);
                }
            } else {
                $details = $this->diff($source, $old, $observation);
                if ($details !== null) {
                    $report['changes'][] = $this->change($id, $source, $details['message'], $details['details']);
                }
            }
            $previous[$id] = $observation + ['observed_at' => $now];
        }

        $report['baseline_created'] = $report['baselined'] !== [] && $state['created'];
        $report['change_count'] = count($report['changes']);
        $report['changed'] = $report['change_count'] > 0;
        if ($persist) {
            $this->writeState($statePath, $previous, $now);
        }

        return $report;
    }

    /** @return array<string,mixed> */
    private function observe(array $source): array
    {
        $response = ($this->fetch)($source['url']);
        if ($response['status'] !== 200) {
            throw new RuntimeException("HTTP {$response['status']} z {$source['url']}");
        }
        $body = $response['body'];
        if ($body === '') {
            throw new RuntimeException("prázdná odpověď z {$source['url']}");
        }

        return match ($source['kind']) {
            'file' => $this->observeFile($body, $response['headers']),
            'page' => $this->observePage($body, $source),
            'mpsv-page' => $this->observeMpsvPage($body),
            'rss' => $this->observeRss($body),
        };
    }

    /** @param array<string,string> $headers @return array<string,mixed> */
    private function observeFile(string $body, array $headers): array
    {
        $sha = hash('sha256', $body);
        $lastModified = $headers['last-modified'] ?? '';

        return [
            'fingerprint' => $sha . ':' . strlen($body),
            'sha256' => $sha,
            'size' => strlen($body),
            'last_modified' => $lastModified,
            'summary' => substr($sha, 0, 12) . ', ' . strlen($body) . ' B' . ($lastModified !== '' ? ", {$lastModified}" : ''),
        ];
    }

    /** @return array<string,mixed> */
    private function observePage(string $body, array $source): array
    {
        $part = $body;
        $pattern = $source['extract'] ?? null;
        if (is_string($pattern)) {
            if (preg_match($pattern, $body, $matches) !== 1) {
                throw new RuntimeException('na stránce chybí sledovaná část (změnila se struktura?)');
            }
            $part = $matches[1] ?? $matches[0];
        }
        $text = $this->htmlText($part);
        if (preg_match_all('~href="([^"]*/documents/[^"?#]*)~i', $part, $links) > 0) {
            $text .= ' ' . implode(' ', array_unique($links[1]));
        }
        if ($text === '') {
            throw new RuntimeException('sledovaná část stránky je prázdná');
        }
        $sha = hash('sha256', $text);

        return [
            'fingerprint' => $sha,
            'sha256' => $sha,
            'size' => strlen($text),
            'summary' => substr($sha, 0, 12) . ', ' . strlen($text) . ' B textu',
        ];
    }

    /** @return array<string,mixed> */
    private function observeMpsvPage(string $body): array
    {
        $json = json_decode($body, true);
        if (!is_array($json) || !isset($json['payload']) || !is_array($json['payload'])) {
            throw new RuntimeException('odpověď MPSV API není očekávaný JSON');
        }
        $items = [];
        $version = (string) ($json['config']['confluencePageVersion'] ?? '');
        $updated = (string) ($json['updatedDate'] ?? '');
        foreach ($json['payload']['attachments'] ?? [] as $attachment) {
            if (is_array($attachment) && is_string($attachment['fileName'] ?? null)) {
                $items['příloha ' . $attachment['fileName'] . ' [' . ($attachment['mediaId'] ?? '?') . ']']
                    = (string) ($attachment['fileSize'] ?? '');
            }
        }
        foreach ($json['payload']['entries'] ?? [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $values = $entry['values'] ?? [];
            $key = 'záznam ' . (is_array($values) ? (string) ($values['id'] ?? md5(json_encode($values))) : '?');
            $items[$key] = substr(hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE) ?: ''), 0, 16);
        }
        $bodyHash = isset($json['payload']['body'])
            ? substr(hash('sha256', json_encode($json['payload']['body'], JSON_UNESCAPED_UNICODE) ?: ''), 0, 16)
            : '';
        ksort($items);
        $fingerprint = hash('sha256', json_encode([$version, $updated, $bodyHash, $items], JSON_UNESCAPED_UNICODE) ?: '');

        return [
            'fingerprint' => $fingerprint,
            'summary' => "verze {$version}, upraveno {$updated}, " . count($items) . ' položek',
            'meta' => ['version' => $version, 'updated' => $updated, 'body' => $bodyHash],
            'items' => $items,
        ];
    }

    /** @return array<string,mixed> */
    private function observeRss(string $body): array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false || !isset($xml->channel->item)) {
            throw new RuntimeException('odpověď není RSS');
        }
        $items = [];
        foreach ($xml->channel->item as $item) {
            $guid = trim((string) ($item->guid ?: $item->link));
            if ($guid === '') {
                continue;
            }
            $title = $this->normalizeText((string) $item->title);
            $description = $this->htmlText(html_entity_decode((string) $item->description, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $items[$guid] = [
                'title' => $title,
                'text' => $this->shorten($description, 400),
                'link' => trim((string) $item->link),
                'date' => trim((string) $item->pubDate),
            ];
            if (count($items) >= self::MAX_FEED_ITEMS) {
                break;
            }
        }
        if ($items === []) {
            throw new RuntimeException('RSS neobsahuje žádné položky');
        }

        return [
            'fingerprint' => hash('sha256', implode("\n", array_keys($items))),
            'summary' => count($items) . ' položek',
            'feed' => $items,
        ];
    }

    /**
     * @param array<string,mixed> $source
     * @param array<string,mixed> $old
     * @param array<string,mixed> $new
     * @return array{message:string,details:list<string>}|null
     */
    private function diff(array $source, array $old, array $new): ?array
    {
        if ($source['kind'] === 'rss') {
            return $this->diffFeed($source, $old, $new);
        }
        if (($old['fingerprint'] ?? null) === $new['fingerprint']) {
            return null;
        }
        $details = [];
        if ($source['kind'] === 'mpsv-page') {
            foreach (['version' => 'verze stránky', 'updated' => 'datum úpravy', 'body' => 'text stránky'] as $key => $label) {
                $a = $old['meta'][$key] ?? '';
                $b = $new['meta'][$key] ?? '';
                if ($a !== $b) {
                    $details[] = "{$label}: {$a} -> {$b}";
                }
            }
            $oldItems = is_array($old['items'] ?? null) ? $old['items'] : [];
            foreach ($new['items'] as $key => $value) {
                if (!array_key_exists($key, $oldItems)) {
                    $details[] = "nové: {$key}";
                } elseif ((string) $oldItems[$key] !== (string) $value) {
                    $details[] = "změněno: {$key}";
                }
            }
            foreach (array_keys($oldItems) as $key) {
                if (!array_key_exists($key, $new['items'])) {
                    $details[] = "zmizelo: {$key}";
                }
            }
        } else {
            $details[] = 'dříve ' . ($old['summary'] ?? '?') . '; nyní ' . $new['summary'];
        }

        return ['message' => 'Změna obsahu zdroje', 'details' => array_slice($details, 0, 30)];
    }

    /**
     * @param array<string,mixed> $source
     * @param array<string,mixed> $old
     * @param array<string,mixed> $new
     * @return array{message:string,details:list<string>}|null
     */
    private function diffFeed(array $source, array $old, array $new): ?array
    {
        $oldFeed = is_array($old['feed'] ?? null) ? $old['feed'] : [];
        $keywords = array_map($this->fold(...), is_array($source['keywords'] ?? null) ? $source['keywords'] : []);
        $details = [];
        foreach ($new['feed'] as $guid => $item) {
            if (array_key_exists($guid, $oldFeed)) {
                continue;
            }
            $haystack = $this->fold($item['title'] . ' ' . $item['text']);
            foreach ($keywords as $keyword) {
                if ($keyword !== '' && str_contains($haystack, $keyword)) {
                    $details[] = $item['title'] . ($item['date'] !== '' ? ' (' . substr($item['date'], 0, 10) . ')' : '')
                        . ' ' . $item['link'] . ' [slovo: ' . $keyword . ']';
                    break;
                }
            }
        }

        return $details === [] ? null : ['message' => 'Nový předpis se sledovaným slovem', 'details' => array_slice($details, 0, 20)];
    }

    /**
     * @param array<string,mixed> $source
     * @param list<string> $details
     * @return array{source:string,label:string,url:string,message:string,details:list<string>}
     */
    private function change(string $id, array $source, string $message, array $details): array
    {
        return [
            'source' => $id,
            'label' => (string) ($source['label'] ?? $id),
            'url' => (string) ($source['page_url'] ?? $source['url']),
            'message' => $message,
            'details' => $details,
        ];
    }

    private function htmlText(string $html): string
    {
        $html = preg_replace('~<(script|style|noscript)\b.*?</\1>~is', ' ', $html) ?? $html;
        $html = preg_replace('~<!--.*?-->~s', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $this->normalizeText($text);
    }

    private function normalizeText(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function shorten(string $value, int $length): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
    }

    private function fold(string $value): string
    {
        $value = mb_strtolower($value);
        $folded = transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC', $value);

        return is_string($folded) ? $folded : $value;
    }

    /** @return array{sources:array<string,array<string,mixed>>,created:bool} */
    private function loadState(string $path): array
    {
        if (!is_file($path)) {
            return ['sources' => [], 'created' => true];
        }
        $json = json_decode((string) file_get_contents($path), true);
        if (!is_array($json) || ($json['schema_version'] ?? null) !== self::STATE_SCHEMA_VERSION || !is_array($json['sources'] ?? null)) {
            throw new RuntimeException("Stavový soubor {$path} je poškozený nebo má jinou verzi, smažte ho pro nový výchozí stav.");
        }

        return ['sources' => $json['sources'], 'created' => false];
    }

    /** @param array<string,array<string,mixed>> $sources */
    private function writeState(string $path, array $sources, string $now): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Nelze vytvořit adresář {$dir}.");
        }
        ksort($sources);
        $json = json_encode(
            ['schema_version' => self::STATE_SCHEMA_VERSION, 'updated_at' => $now, 'sources' => $sources],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $tmp = $path . '.tmp' . getmypid();
        if (file_put_contents($tmp, $json . "\n") === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Nelze zapsat stav do {$path}.");
        }
    }

    private function encodeUrl(string $url): string
    {
        return preg_replace_callback('#[^A-Za-z0-9\-._~:/?\[\]@!$&\'()*+,;=%]#u', static fn (array $m): string => rawurlencode($m[0]), $url) ?? $url;
    }

    /** @return array{status:int,body:string,headers:array<string,string>} */
    private function fetchCurl(string $url): array
    {
        $headers = [];
        $body = '';
        $tooBig = false;
        $max = $this->maxBytes;
        $curl = curl_init($this->encodeUrl($url));
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'MyUcto-legis-watch/1.0',
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $headers = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooBig, $max): int {
                $body .= $chunk;
                if (strlen($body) > $max) {
                    $tooBig = true;

                    return 0;
                }

                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        if ($tooBig) {
            throw new RuntimeException("odpověď překročila limit {$max} B");
        }
        if ($ok === false) {
            throw new RuntimeException("zdroj nelze načíst ({$error})");
        }

        return ['status' => $status, 'body' => $body, 'headers' => $headers];
    }
}
