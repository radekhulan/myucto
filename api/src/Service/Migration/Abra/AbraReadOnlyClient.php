<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Abra;

use MyInvoice\Service\Http\OutboundUrlGuard;
use MyInvoice\Service\Http\OutboundRequestException;

class AbraReadOnlyClient
{
    public const PAGE_SIZE = 1000;
    private float $lastRequestAt = 0.0;
    private ?\Closure $pageObserver = null;
    private ?AbraPageCache $pageCache = null;
    private array $cacheMetadataUnsupported = [];
    private const EVIDENCES = [
        'evidence-list', 'nastaveni', 'adresar', 'ucetni-obdobi', 'ucetni-osnova', 'ucet',
        'mena', 'kurz', 'stredisko', 'cinnost', 'zakazka', 'cleneni-dph', 'sazba-dph',
        'typ-faktury-vydane', 'typ-faktury-prijate', 'faktura-vydana', 'faktura-prijata',
        'prodejka', 'prodejka-polozka',
        'faktura-vydana-polozka', 'faktura-prijata-polozka', 'interni-doklad', 'interni-doklad-polozka',
        'pohledavka', 'pohledavka-polozka', 'zavazek', 'zavazek-polozka', 'banka', 'banka-polozka',
        'bankovni-ucet', 'pokladna', 'pokladni-pohyb', 'pokladni-pohyb-polozka', 'vzajemny-zapocet',
        'vazba', 'ucetni-denik', 'pohyb-na-uctech', 'stav-uctu', 'saldo', 'saldo-k-datu',
        'podklady-dph', 'ulozene-priznani-dph', 'ulozene-priznani-kon-vyk-dph', 'radek-priznani-dph',
        'majetek', 'majetek-udalost', 'ucetni-odpis', 'danovy-odpis', 'cenik', 'sklad', 'skladovy-pohyb',
        'skladovy-pohyb-polozka', 'skladova-karta', 'predpis-zauctovani',
    ];
    private const QUERY = ['limit', 'start', 'detail', 'relations', 'includes', 'order', 'filter',
        'add-row-count', 'add-global-version', 'postingState', 'idUcetniObdobi', 'ucetniObdobi',
        'datumUctovaniOd', 'datumUctovaniDo', 'datum', 'stavZauctovani', 'evidence', 'filtrovat-platnost'];

    public function __construct(private readonly OutboundUrlGuard $http) {}

    public function capturePages(?\Closure $observer): void { $this->pageObserver = $observer; }

    public function usePageCache(?AbraPageCache $cache): void
    {
        $this->pageCache = $cache;
        $this->cacheMetadataUnsupported = [];
    }

    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || strlen($url) > 1200) {
            throw new AbraException('invalid_url', 'Zadejte HTTPS adresu účetní firmy v ABRA Flexi bez přihlašovacích údajů v URL.');
        }
        $path = rtrim($parts['path'] ?? '', '/');
        if (!preg_match('~^/(?:v2/)?(?:c|flexi)/([a-zA-Z0-9_-]+)$~D', $path, $m)) {
            throw new AbraException('invalid_url', 'URL musí směřovat na účetní firmu, například https://server/c/firma.');
        }
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return 'https://' . $host . $port . '/c/' . $m[1];
    }

    public function get(array $credentials, string $evidence, array $query = []): array
    {
        $baseEvidence = explode('/', $evidence)[0];
        if (!in_array($baseEvidence, self::EVIDENCES, true)
            || !preg_match('~^[a-z][a-z0-9-]*(?:/(?:properties|relations))?$~D', $evidence)
            || array_diff(array_keys($query), self::QUERY) !== []) {
            throw new AbraException('unsafe_request', 'Tento požadavek není povolen pro čtení ABRA Flexi.');
        }
        if ($evidence === 'nastaveni') {
            $query = ['limit' => 250, 'detail' => 'custom:id,ic,nazFirmy,platiOdData,mena,ossEU,ossMimoEU,ossDovoz'];
        }
        if (isset($query['limit']) && (!is_int($query['limit']) || $query['limit'] < 1 || $query['limit'] > self::PAGE_SIZE)) {
            throw new AbraException('invalid_limit', 'Neplatná velikost stránky ABRA Flexi.');
        }
        $path = $evidence . '.json';
        if (isset($query['filter'])) {
            $filter = $query['filter'];
            if (!is_string($filter) || $filter === '' || strlen($filter) > 8000
                || str_contains($evidence, '/') || preg_match('/[\x00-\x1f\x7f]/', $filter)) {
                throw new AbraException('invalid_filter', 'Neplatný čtecí filtr ABRA Flexi.');
            }
            $path = $evidence . '/(' . rawurlencode($filter) . ').json';
            unset($query['filter']);
        }
        return $this->fetch($credentials, $path, $query);
    }

    public function list(array $credentials, string $evidence, array $query = [],
        ?callable $progress = null, ?callable $cancelled = null, ?callable $consume = null): array
    {
        $out = [];
        $start = 0;
        $total = null;
        $pageSize = $consume !== null && isset($query['limit']) ? max(1, min(self::PAGE_SIZE, (int) $query['limit'])) : self::PAGE_SIZE;
        do {
            if ($cancelled !== null && $cancelled()) throw new AbraException('cancelled', 'Převod byl zrušen.', [], 409);
            $pageQuery = array_replace(
                ['detail' => 'full'], $query,
                ['limit' => $pageSize, 'start' => $start, 'add-row-count' => 'true'],
            );
            $payload = null;
            if ($this->pageCache !== null && $this->cacheMetadataFields($evidence) !== null
                && !isset($this->cacheMetadataUnsupported[$evidence])
                && $this->pageCache->has($evidence, $query, $start)) {
                $metadataQuery = $pageQuery;
                $metadataQuery['detail'] = $this->cacheMetadataFields($evidence);
                unset($metadataQuery['relations'], $metadataQuery['includes']);
                try {
                    $metadata = $this->get($credentials, $evidence, $metadataQuery);
                    $payload = $this->pageCache->matching($evidence, $query, $start, $metadata);
                } catch (AbraException $error) {
                    if (!in_array($error->errorCode, ['abra_http_400', 'abra_http_404'], true)) throw $error;
                    $this->cacheMetadataUnsupported[$evidence] = true;
                }
            }
            $payload ??= $this->get($credentials, $evidence, $pageQuery);
            $root = $payload['winstrom'] ?? null;
            $rows = is_array($root) ? ($root[$evidence] ?? null) : null;
            if (!is_array($rows) || !array_is_list($rows)) {
                throw new AbraException('invalid_response', 'ABRA Flexi nevrátila očekávaný seznam záznamů.');
            }
            if (isset($root['@rowCount'])) {
                $count = filter_var($root['@rowCount'], FILTER_VALIDATE_INT);
                if ($count === false || $count < 0 || ($total !== null && $total !== $count)) {
                    throw new AbraException('source_changed', 'Data ve zdroji se během načítání změnila. Opakujte načtení.');
                }
                $total = $count;
            }
            $n = count($rows);
            if ($n > $pageSize || $start + $n > 2000000) throw new AbraException('data_limit', 'Převod překročil povolený rozsah dat.');
            if ($this->pageObserver !== null) ($this->pageObserver)($evidence, $query, $start, $payload);
            if ($this->pageCache !== null) $this->pageCache->save($evidence, $query, $start, $payload);
            if (in_array($evidence, AbraSource::DOCUMENT_EVIDENCES, true)) {
                $rows = array_map(AbraSource::compactDocument(...), $rows);
            } elseif (in_array($evidence, ['banka', 'pokladni-pohyb'], true)) {
                $rows = array_map(AbraSource::compactPaymentDocument(...), $rows);
            } elseif ($evidence === 'ucetni-denik') {
                $rows = array_map(AbraSource::compactJournal(...), $rows);
            } elseif ($evidence === 'pohyb-na-uctech') {
                $rows = array_map(AbraSource::compactAccountMovement(...), $rows);
            }
            if ($consume !== null) {
                $consume($rows, $start, $total);
            } else {
                array_push($out, ...$rows);
            }
            $start += $n;
            if ($progress !== null) $progress($start, $total ?? $start);
            if ($n === 0 || $n < $pageSize || ($total !== null && $start === $total)) break;
        } while (true);
        if ($total !== null && $start !== $total) {
            throw new AbraException('incomplete_export', 'Zdrojový export není úplný. Převod se nespustil.');
        }
        return $out;
    }

    public function scan(array $credentials, string $evidence, array $query, callable $consume,
        ?callable $progress = null, ?callable $cancelled = null): void
    {
        $this->list($credentials, $evidence, $query, $progress, $cancelled, $consume);
    }

    private function cacheMetadataFields(string $evidence): ?string
    {
        return match ($evidence) {
            'faktura-vydana', 'faktura-prijata', 'prodejka', 'zavazek', 'pohledavka', 'banka', 'pokladni-pohyb', 'cenik', 'sklad',
                'skladova-karta', 'skladovy-pohyb' => 'custom:id,lastUpdate',
            'ucetni-denik' => 'custom:idUcetniDenik,lastUpdate,datUcto,sumTuz,mdUcet,dalUcet',
            'pohyb-na-uctech' => 'custom:idUcetniDenik,lastUpdate,datUcto,ucet,sumTuzMd,sumTuzDal',
            default => null,
        };
    }

    public function changesStatus(array $credentials): bool
    {
        $root = $this->fetch($credentials, 'changes/status.json', [])['winstrom'] ?? [];
        return filter_var($root['success'] ?? false, FILTER_VALIDATE_BOOL);
    }

    public function changes(array $credentials, int|string $cursor): array
    {
        if (!ctype_digit((string) $cursor)) throw new AbraException('invalid_cursor', 'Neplatný bod synchronizace.');
        return $this->fetch($credentials, 'changes.json', ['start' => (string) $cursor, 'limit' => self::PAGE_SIZE]);
    }

    private function fetch(array $credentials, string $path, array $query): array
    {
        $base = self::normalizeUrl((string) ($credentials['url'] ?? ''));
        if (str_starts_with($path, 'nastaveni')) {
            $base = preg_replace('~/c/([^/]+)$~D', '/v2/c/$1', $base);
        }
        $username = (string) ($credentials['username'] ?? '');
        $password = (string) ($credentials['password'] ?? '');
        if ($username === '' || $password === '' || str_contains($username, ':')
            || preg_match('/[\x00-\x1f\x7f]/', $username . $password)) {
            throw new AbraException('invalid_credentials', 'Zadejte platné přihlašovací údaje ABRA Flexi.');
        }
        $host = (string) parse_url($base, PHP_URL_HOST);
        $budget = new AbraRequestBudget();
        $budget->reserve($host);
        try {
            $delay = 0.5 - (microtime(true) - $this->lastRequestAt);
            if ($delay > 0) usleep((int) ceil($delay * 1000000));
            $this->lastRequestAt = microtime(true);
            $r = $this->http->request('GET', $base . '/' . $path
                . ($query !== [] ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : ''),
                ['Accept' => 'application/json'], timeout: 180, maxBytes: 64 * 1024 * 1024,
                basicAuth: $username . ':' . $password);
        } catch (\Throwable $error) {
            throw self::transportFailure($error);
        }
        if ($r->status !== 200) {
            if ($r->status === 429) {
                $retry = $r->headers['retry-after'] ?? '';
                $budget->cooldown($host, ctype_digit((string) $retry) ? (int) $retry : 600);
            }
            $message = match ($r->status) {
                401, 403 => 'ABRA Flexi odmítla přihlášení nebo přístup k této evidenci.',
                400, 404 => 'Požadovaná evidence není v této účetní firmě dostupná.',
                429 => 'ABRA Flexi dočasně omezila počet požadavků. Opakujte načtení později.',
                default => 'ABRA Flexi nevrátila platnou odpověď. Opakujte načtení později.',
            };
            throw new AbraException('abra_http_' . $r->status, $message, [], 422);
        }
        try {
            $data = json_decode($r->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new AbraException('invalid_response', 'ABRA Flexi vrátila neplatný formát odpovědi.');
        }
        if (!is_array($data) || (!isset($data['winstrom']) && !isset($data['evidences']))) {
            throw new AbraException('invalid_response', 'ABRA Flexi vrátila neočekávaný formát odpovědi.');
        }
        $success = $data['winstrom']['success'] ?? null;
        if ($success !== null && filter_var($success, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === false && $path !== 'changes/status.json') {
            throw new AbraException('source_error', 'ABRA Flexi odmítla čtecí požadavek.');
        }
        return $data;
    }

    public static function transportFailure(\Throwable $error): AbraException
    {
        if ($error instanceof OutboundRequestException && $error->reason === OutboundRequestException::SIZE_LIMIT) {
            return new AbraException('response_too_large', 'Jeden blok dat z ABRA Flexi překročil povolenou velikost odpovědi. Převod se zastavil.', [], 422);
        }
        if ($error instanceof OutboundRequestException && preg_match('/timed?\s*out|timeout/i', $error->getMessage())) {
            return new AbraException('connection_timeout', 'ABRA Flexi nestihla vrátit celý blok dat v časovém limitu. Převod se zastavil bez opakování požadavku.', [], 422);
        }
        return new AbraException('connection_failed', 'Nelze bezpečně připojit ABRA Flexi. Ověřte veřejnou HTTPS adresu, certifikát a dostupnost serveru.', [], 422);
    }
}
