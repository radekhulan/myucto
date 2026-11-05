<?php

declare(strict_types=1);

namespace MyInvoice\Service\System;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use Throwable;

/**
 * Ohlášení plánované údržby (servisní hláška s termínem od–do).
 *
 * Hlášky zadává provozovatel centrálně na licenčním serveru a instance si je
 * stahuje sama úlohou `cron-maintenance-notice` (každých 15 minut). Zvenku se
 * do instance nic neposílá. Poslední stažený seznam se drží v `app_meta`, takže
 * výpadek serveru hlášku neschová ani nevymyslí: platí poslední známý stav
 * a hláška s uplynulým koncem zmizí sama.
 *
 * Vedle centrálního zdroje lze hlášku zadat i lokálně souborem
 * `${MYINVOICE_DATA_DIR}/storage/maintenance-notice.json` (stejný formát,
 * jeden objekt nebo `{"notices": [...]}`). Slouží self-hosted instalacím
 * a nouzi, kdy licenční server neběží.
 *
 * ⚠️ Hláška je jen ohlášení. Aplikaci nikdy nezamyká: samotnou odstávku řídí
 * výhradně zámek údržby ({@see MaintenanceLock}, `maintenance.json`). Chybný čas
 * nebo podvržený feed tak nemůže instanci shodit.
 *
 * ⚠️ Text je vždy prostý. Značky se zahazují už při uložení a frontend ho
 * vykresluje jako text, nikdy jako HTML.
 */
final class MaintenanceNoticeService
{
    public const META_KEY = 'maintenance_notices';
    public const DEFAULT_PATH = '/api/maintenance-notice';
    public const MAX_MESSAGE_LENGTH = 1000;
    public const MAX_NOTICES = 5;
    public const SEVERITIES = ['info', 'warning', 'danger'];

    private readonly string $localFile;

    public function __construct(
        private readonly Config $config,
        private readonly Connection $db,
        private readonly ManagedModeGuard $managed,
        private readonly ?Client $http = null,
        ?string $localFile = null,
    ) {
        $this->localFile = $localFile ?? RuntimePaths::storage('maintenance-notice.json');
    }

    /** Kam se instance ptá. Prázdné `maintenance_notice.url` = licenční server. */
    public function feedUrl(): string
    {
        $url = trim((string) $this->config->get('maintenance_notice.url', ''));
        if ($url !== '') {
            return $url;
        }

        return rtrim((string) $this->config->get('license.server_url', 'https://myucto.cz'), '/') . self::DEFAULT_PATH;
    }

    /**
     * Stáhne aktuální hlášky a uloží je. Prázdný seznam ze serveru uložené
     * hlášky smaže (provozovatel hlášku zrušil). Při chybě sítě nebo neplatné
     * odpovědi zůstává poslední známý stav.
     *
     * @return array{ok:bool,count:int,error:string}
     */
    public function refresh(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $client = $this->http ?? new Client([
            'timeout'         => 5,
            'connect_timeout' => 5,
            'http_errors'     => false,
            'verify'          => (bool) $this->config->get('license.verify_tls', true),
        ]);

        try {
            $response = $client->get($this->feedUrl(), [
                'query'   => ['audience' => $this->managed->isManaged() ? 'managed' : 'self_hosted'],
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (GuzzleException $e) {
            return ['ok' => false, 'count' => 0, 'error' => 'network: ' . $e->getMessage()];
        }

        $status = $response->getStatusCode();
        $data = json_decode((string) $response->getBody(), true);
        if ($status !== 200 || !is_array($data) || ($data['ok'] ?? false) !== true || !is_array($data['notices'] ?? null)) {
            return ['ok' => false, 'count' => 0, 'error' => "bad_response: HTTP {$status}"];
        }

        $notices = self::normalizeList($data['notices'], 'remote');
        $this->store(['fetched_at' => $now->format(DATE_ATOM), 'notices' => $notices]);

        return ['ok' => true, 'count' => count($notices), 'error' => ''];
    }

    /**
     * Hlášky, které se mají právě teď ukazovat (od `show_from` do `ends_at`),
     * seřazené podle začátku údržby.
     *
     * @return list<array{key:string,severity:string,starts_at:string,ends_at:string,message:array{cs:string,en:string}}>
     */
    public function active(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $all = array_merge($this->stored(), $this->local());

        $visible = [];
        foreach ($all as $notice) {
            $showFrom = new \DateTimeImmutable($notice['show_from']);
            $endsAt = new \DateTimeImmutable($notice['ends_at']);
            if ($showFrom > $now || $endsAt <= $now) {
                continue;
            }
            unset($notice['show_from']);
            $visible[] = $notice;
        }
        usort($visible, static fn (array $a, array $b): int => strcmp($a['starts_at'], $b['starts_at']));

        return array_slice($visible, 0, self::MAX_NOTICES);
    }

    /**
     * Zkontroluje a sjednotí seznam hlášek z cizího zdroje. Neplatná položka
     * (chybějící nebo nesmyslné časy, prázdný text) se zahodí, nikdy neshodí celek.
     *
     * @param array<mixed> $items
     * @return list<array{key:string,severity:string,show_from:string,starts_at:string,ends_at:string,message:array{cs:string,en:string}}>
     */
    public static function normalizeList(array $items, string $source): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $notice = self::normalize($item, $source);
            if ($notice !== null) {
                $out[] = $notice;
            }
            if (count($out) >= self::MAX_NOTICES) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<mixed> $item
     * @return array{key:string,severity:string,show_from:string,starts_at:string,ends_at:string,message:array{cs:string,en:string}}|null
     */
    private static function normalize(array $item, string $source): ?array
    {
        $startsAt = self::parseTime($item['starts_at'] ?? null);
        $endsAt = self::parseTime($item['ends_at'] ?? null);
        if ($startsAt === null || $endsAt === null || $endsAt <= $startsAt) {
            return null;
        }
        $showFrom = self::parseTime($item['show_from'] ?? null) ?? $startsAt->modify('-3 days');
        if ($showFrom > $startsAt) {
            $showFrom = $startsAt;
        }

        $message = is_array($item['message'] ?? null) ? $item['message'] : [];
        $cs = self::cleanText($message['cs'] ?? '');
        $en = self::cleanText($message['en'] ?? '');
        if ($cs === '' && $en === '') {
            return null;
        }

        $severity = (string) ($item['severity'] ?? 'warning');
        if (!in_array($severity, self::SEVERITIES, true)) {
            $severity = 'warning';
        }

        // Klíč pro zavření hlášky v prohlížeči. Nese i revizi, takže když
        // provozovatel hlášku změní (posune termín, upraví text), ukáže se znovu.
        $id = is_scalar($item['id'] ?? null) ? (string) $item['id'] : '';
        $revision = is_scalar($item['revision'] ?? null) ? (string) $item['revision'] : '';
        $key = $id !== ''
            ? $source . ':' . $id . ($revision !== '' ? '@' . $revision : '')
            : $source . ':' . substr(hash('sha256', $startsAt->format(DATE_ATOM) . $endsAt->format(DATE_ATOM) . $cs . $en), 0, 16);

        return [
            'key'       => mb_substr(preg_replace('/[^A-Za-z0-9:@._-]/', '', $key) ?? '', 0, 120),
            'severity'  => $severity,
            'show_from' => $showFrom->format(DATE_ATOM),
            'starts_at' => $startsAt->format(DATE_ATOM),
            'ends_at'   => $endsAt->format(DATE_ATOM),
            'message'   => ['cs' => $cs !== '' ? $cs : $en, 'en' => $en !== '' ? $en : $cs],
        ];
    }

    private static function parseTime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable(trim($value)))->setTimezone(new \DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    private static function cleanText(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $text = strip_tags($value);
        $text = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $text) ?? '';
        $text = trim(preg_replace('/[ \t]+/u', ' ', $text) ?? '');

        return mb_substr($text, 0, self::MAX_MESSAGE_LENGTH);
    }

    /** @return list<array{key:string,severity:string,show_from:string,starts_at:string,ends_at:string,message:array{cs:string,en:string}}> */
    private function stored(): array
    {
        try {
            if (!$this->db->hasTable('app_meta')) {
                return [];
            }
            $stmt = $this->db->pdo()->prepare('SELECT v FROM app_meta WHERE k = ?');
            $stmt->execute([self::META_KEY]);
            $raw = $stmt->fetchColumn();
        } catch (Throwable) {
            return [];
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded) || !is_array($decoded['notices'] ?? null)) {
            return [];
        }

        // Uložené hlášky prošly normalizací už při stažení. Tady se jen ověří
        // tvar, aby ručně upravený řádek v DB nic nerozbil.
        $out = [];
        foreach ($decoded['notices'] as $n) {
            if (self::isNormalized($n)) {
                $out[] = $n;
            }
        }

        return $out;
    }

    /** @phpstan-assert-if-true array{key:string,severity:string,show_from:string,starts_at:string,ends_at:string,message:array{cs:string,en:string}} $n */
    private static function isNormalized(mixed $n): bool
    {
        if (!is_array($n) || !is_array($n['message'] ?? null)) {
            return false;
        }
        foreach (['key', 'severity', 'show_from', 'starts_at', 'ends_at'] as $field) {
            if (!is_string($n[$field] ?? null) || $n[$field] === '') {
                return false;
            }
        }
        foreach (['show_from', 'starts_at', 'ends_at'] as $field) {
            if (self::parseTime($n[$field]) === null) {
                return false;
            }
        }

        return in_array($n['severity'], self::SEVERITIES, true)
            && is_string($n['message']['cs'] ?? null)
            && is_string($n['message']['en'] ?? null);
    }

    /** @return list<array{key:string,severity:string,show_from:string,starts_at:string,ends_at:string,message:array{cs:string,en:string}}> */
    private function local(): array
    {
        if (!is_file($this->localFile)) {
            return [];
        }
        $raw = @file_get_contents($this->localFile);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return [];
        }
        $items = is_array($decoded['notices'] ?? null) ? $decoded['notices'] : [$decoded];

        return self::normalizeList($items, 'local');
    }

    /** @param array<string,mixed> $payload */
    private function store(array $payload): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO app_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)',
        )->execute([self::META_KEY, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
    }
}
