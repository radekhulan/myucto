<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ares;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * ARES REST lookup pro IČO.
 *
 * Endpoint: GET /ekonomicke-subjekty/{ico}
 * Cache: ares_cache 24h
 *
 * Vrací normalizovaný array nebo null pokud subjekt nenalezen / chyba sítě.
 */
final class AresClient
{
    /** Verze výkladu registrací DPH, sdílená i s cache výsledků VIES odvozených z ARES. */
    public const int NORMALIZATION_VERSION = 2;

    public function __construct(
        private readonly Config $config,
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{found:bool, data?:array<string,mixed>, source:'cache'|'fresh'}|null
     */
    public function lookup(string $ico): ?array
    {
        $ico = preg_replace('/\D/', '', $ico) ?? '';
        if (strlen($ico) !== 8) {
            return ['found' => false, 'source' => 'fresh'];
        }

        $cached = $this->fromCache($ico);
        if ($cached !== null) {
            $cached['source'] = 'cache';
            return $cached;
        }

        $base = rtrim((string) $this->config->get('ares.api'), '/');
        // Bez baseline URL bychom poslali Guzzle relativní path → cURL error 3
        // ("URL rejected: No host part in the URL"), což se historicky logovalo
        // jako "ARES API nedostupné" — uživatele to vysílá ladit síť místo configu.
        // Per issue #30: Config::baselineDefaults() už default poskytuje, ale
        // pokud admin v cfg.local.php / ENV nastaví prázdný string, chytneme to tady.
        if ($base === '') {
            $this->logger->warning('ARES URL není nakonfigurovaná (config.ares.api je prázdná) — lookup přeskočen', ['ico' => $ico]);
            return null;
        }
        $url  = $base . '/' . $ico;
        $timeout = (int) $this->config->get('ares.timeout', 5);

        try {
            $client = new Client(['timeout' => $timeout, 'connect_timeout' => $timeout]);
            $resp = $client->get($url, ['http_errors' => false, 'headers' => ['Accept' => 'application/json']]);
            $status = $resp->getStatusCode();

            if ($status === 404) {
                $payload = ['found' => false];
                $this->cache($ico, $payload);
                return $payload + ['source' => 'fresh'];
            }
            if ($status !== 200) {
                $this->logger->warning('ARES vrátilo neočekávaný status', ['ico' => $ico, 'status' => $status]);
                return null;
            }

            $body = json_decode((string) $resp->getBody(), true);
            if (!is_array($body)) {
                return null;
            }

            $normalized = $this->normalize($body);
            $payload = ['found' => true, 'data' => $normalized];
            $this->cache($ico, $payload);
            return $payload + ['source' => 'fresh'];
        } catch (GuzzleException $e) {
            $this->logger->warning('ARES API nedostupné: ' . $e->getMessage(), ['ico' => $ico]);
            return null;
        }
    }

    private function normalize(array $raw): array
    {
        $sidlo = $raw['sidlo'] ?? [];
        $regs  = $raw['seznamRegistraci'] ?? [];
        $individualVatActive = ($regs['stavZdrojeDph'] ?? '') === 'AKTIVNI';
        $groupVatActive = ($regs['stavZdrojeSkDph'] ?? '') === 'AKTIVNI';
        $dic = (string) ($raw['dic'] ?? '');
        if ($groupVatActive) {
            $groupDic = trim((string) ($raw['dicSkDph'] ?? ''));
            // Člen skupiny používá pro DPH její DIČ, vlastní může zůstat v ARES
            // i po zániku individuální registrace. Chybějící DIČ skupiny nenahrazujeme
            // neaktivním vlastním DIČ; aktivní skupina přesto dokládá plátcovství.
            $dic = $groupDic !== '' || !$individualVatActive ? $groupDic : $dic;
        }

        // Skládáme ulici z částí. Místní část obce bez ulic ARES vrací bez `nazevUlice`,
        // adresa se pak píše názvem části obce a číslem domu (issue #252).
        $street = trim((string) ($sidlo['nazevUlice'] ?? ''));
        if ($street === '') {
            $street = trim((string) ($sidlo['nazevCastiObce'] ?? ''));
        }
        $cisloDom = $sidlo['cisloDomovni'] ?? null;
        $cisloOr  = $sidlo['cisloOrientacni'] ?? null;
        if ($cisloDom !== null && $cisloOr !== null) {
            $street .= ' ' . $cisloDom . '/' . $cisloOr . (string) ($sidlo['cisloOrientacniPismeno'] ?? '');
        } elseif ($cisloDom !== null) {
            $street .= ' ' . $cisloDom;
        }
        $street = trim($street);

        $psc = (string) ($sidlo['psc'] ?? '');
        if (preg_match('/^\d{5}$/', $psc)) {
            $psc = substr($psc, 0, 3) . ' ' . substr($psc, 3); // 30100 → "301 00"
        }

        return [
            'company_name' => (string) ($raw['obchodniJmeno'] ?? ''),
            'ic'           => (string) ($raw['ico'] ?? ''),
            'dic'          => $dic,
            'street'       => $street,
            'city'         => (string) ($sidlo['nazevObce'] ?? ''),
            'zip'          => $psc,
            'country_iso2' => (string) ($sidlo['kodStatu'] ?? 'CZ'),
            'is_vat_payer' => $individualVatActive || $groupVatActive,
            'date_active'  => (string) ($raw['datumVzniku'] ?? ''),
            'legal_form'   => (string) ($raw['pravniForma'] ?? ''),
            // Číslo popisné / orientační zvlášť (pro EPO VetaP). cisloOrientacni může mít písmeno.
            'street_number_pop'    => $cisloDom !== null ? (string) $cisloDom : '',
            'street_number_orient' => $cisloOr !== null
                ? ((string) $cisloOr . (string) ($sidlo['cisloOrientacniPismeno'] ?? ''))
                : '',
            // Převažující CZ-NACE NELZE z agregovaného seznamu ARES spolehlivě určit
            // (nemá příznak hlavní činnosti). Vyplníme jen když je jednoznačná (1 reálný kód).
            'cz_nace_code' => self::primaryNace($raw),
            // Typ poplatníka odvozený z právní formy: OSVČ → 'fo' (DPFO), firma → 'po' (DPPO).
            'taxpayer_type' => self::taxpayerTypeFromLegalForm((string) ($raw['pravniForma'] ?? '')),
            // Zápis v OR pro PO (např. „Spisová značka C 45039 vedená u Krajského
            // soudu v Plzni"). U OSVČ / subjektů mimo OR zůstává prázdné.
            'commercial_register' => $this->extractCommercialRegister($raw),
        ];
    }

    /**
     * Typ poplatníka z kódu právní formy (ARES `pravniForma`):
     *  - kódy 100–109 = fyzické osoby podnikající (OSVČ) → 'fo' (DPFO),
     *  - jakýkoli jiný neprázdný kód = právnická osoba → 'po' (DPPO),
     *  - prázdné = '' (nedeterminujeme, necháme uživateli).
     */
    private static function taxpayerTypeFromLegalForm(string $pf): string
    {
        if ($pf === '') {
            return '';
        }
        return preg_match('/^10\d$/', $pf) === 1 ? 'fo' : 'po';
    }

    /**
     * Jednoznačná převažující CZ-NACE z `czNace` — jen pokud po odfiltrování
     * placeholderů (kód „00"/samé nuly) zbyde právě jeden kód. Jinak '' (raději
     * nevyplnit, než dosadit špatnou převažující činnost do přiznání).
     */
    private static function primaryNace(array $raw): string
    {
        $list = $raw['czNace'] ?? null;
        if (!is_array($list)) {
            return '';
        }
        // POZN.: kódy sbíráme do listu hodnot, NE jako klíče pole — PHP by numerický
        // string klíč („620") přetypoval na int a funkce by vrátila int místo string
        // (TypeError → pád celého normalize, regrese pro subjekty s jedinou NACE, #76b).
        $codes = [];
        foreach ($list as $c) {
            $c = trim((string) $c);
            if ($c === '' || (int) $c === 0) {
                continue; // přeskoč „00" / prázdné
            }
            if (!in_array($c, $codes, true)) {
                $codes[] = $c;
            }
        }
        return count($codes) === 1 ? $codes[0] : '';
    }

    /**
     * Spisová značka je v `dalsiUdaje[].spisovaZnacka` ve formátu „C 45039/KSPL"
     * (oddíl, vložka, kód rejstříkového soudu). Preferujeme záznam z veřejného
     * rejstříku (`datovyZdroj == 'vr'`). Vrátíme čitelný text, nebo '' když chybí.
     */
    private function extractCommercialRegister(array $raw): string
    {
        $dalsi = $raw['dalsiUdaje'] ?? null;
        if (!is_array($dalsi)) {
            return '';
        }
        $znacka = '';
        foreach ($dalsi as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $val = trim((string) ($entry['spisovaZnacka'] ?? ''));
            if ($val === '') {
                continue;
            }
            $znacka = $val;
            if (($entry['datovyZdroj'] ?? '') === 'vr') {
                break; // preferovaný zdroj nalezen
            }
        }
        if ($znacka === '') {
            return '';
        }

        // „C 45039/KSPL" → oddíl=C, vložka=45039, soud=KSPL
        if (preg_match('~^\s*([A-Za-z]+)\s+(\S+?)\s*/\s*([A-Z]{2,5})\s*$~u', $znacka, $m)) {
            $court = self::COURT_NAMES[strtoupper($m[3])] ?? null;
            if ($court !== null) {
                return "Spisová značka {$m[1]} {$m[2]} vedená u {$court}";
            }
        }
        // Neznámý formát/soud → vrať aspoň surovou značku.
        return 'Spisová značka ' . $znacka;
    }

    /**
     * Kódy rejstříkových soudů (ARES `dalsiUdaje.spisovaZnacka` suffix) → genitiv
     * pro větu „… vedená u …".
     */
    private const COURT_NAMES = [
        'MSPH' => 'Městského soudu v Praze',
        'KSCB' => 'Krajského soudu v Českých Budějovicích',
        'KSPL' => 'Krajského soudu v Plzni',
        'KSUL' => 'Krajského soudu v Ústí nad Labem',
        'KSHK' => 'Krajského soudu v Hradci Králové',
        'KSBR' => 'Krajského soudu v Brně',
        'KSOS' => 'Krajského soudu v Ostravě',
    ];

    private function fromCache(string $ico): ?array
    {
        $ttl = (int) $this->config->get('ares.cache_ttl', 86400);
        $stmt = $this->db->pdo()->prepare(
            'SELECT payload FROM ares_cache WHERE ic = ? AND fetched_at > NOW() - INTERVAL ? SECOND'
        );
        $stmt->execute([$ico, $ttl]);
        $row = $stmt->fetchColumn();
        if ($row === false) {
            return null;
        }
        $data = json_decode((string) $row, true);
        if (!is_array($data)) {
            return null;
        }
        // Starý normalizovaný payload zahodil dicSkDph i stavZdrojeSkDph, nelze
        // ho lokálně opravit. Při výpadku vrátí lookup nezjištěno, nikoli neplátce.
        if (($data['found'] ?? false) && ($data['normalization_version'] ?? null) !== self::NORMALIZATION_VERSION) {
            return null;
        }
        unset($data['normalization_version']);
        return $data;
    }

    private function cache(string $ico, array $payload): void
    {
        $payload['normalization_version'] = self::NORMALIZATION_VERSION;
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ares_cache (ic, payload) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = NOW()'
        );
        $stmt->execute([$ico, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    }
}
