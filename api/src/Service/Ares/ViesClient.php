<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ares;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * VIES REST lookup pro EU DIČ.
 *
 * Endpoint: GET /ms/{COUNTRY}/vat/{NUMBER_BEZ_PREFIXU}
 * SOAP fallback: pokud REST vrátí 5xx nebo timeout.
 * Cache: vies_cache 24h
 *
 * Pro CZ DIČ se primárně používá ARES — autoritativní český registr.
 * VIES pro CZ data čerpá odtud s delay a při výpadcích vrací false-negative
 * (isValid:false + name/address vyplněné placeholderem "---" z viesApproximate),
 * což se zacachuje a uživateli pak svítí nepravdivé "DIČ není platné".
 */
final class ViesClient
{
    public function __construct(
        private readonly Config $config,
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
        private readonly AresClient $ares,
        private readonly CrpDphClient $crpdph,
    ) {}

    /**
     * @return array{valid:bool, name?:string, address?:string, country?:string, vat_number?:string, group_registration?:bool, source:'cache'|'rest'|'soap'|'ares'|'crpdph'|'error'}
     */
    public function lookup(string $vatId): array
    {
        $vatId = strtoupper(preg_replace('/[\s\-]/', '', $vatId) ?? '');
        if (!preg_match('/^([A-Z]{2})([A-Z0-9+*]{2,12})$/', $vatId, $m)) {
            return ['valid' => false, 'source' => 'error'];
        }
        $country = $m[1];
        $number  = $m[2];

        // CZ skupinová registrace DPH (kmenová část 699xxxxxx): VIES DPH skupiny
        // neeviduje → vracel by trvalý false-negative „DIČ není platné". Autoritativní
        // je registr plátců DPH (CRPDPH). Délka 9 je součástí podmínky: DIČ skupiny má
        // tvar CZ699xxxxxx, kdežto 8místné „69900012" je běžné IČO, které do registru
        // skupin nepatří a musí dál chodit na ARES. Záměrně PŘED cache — dřív zacachované
        // false-negativy z VIES nesmí výsledek přebít; vlastní TTL má CrpDphClient.
        if ($country === 'CZ' && CrpDphClient::isGroupRegistration($number)) {
            return $this->tryCrpDph($number, $vatId);
        }

        $cached = $this->fromCache($vatId);
        if ($cached !== null) {
            $cached['source'] = 'cache';
            return $cached;
        }

        // CZ → ARES (přesnější + spolehlivější než VIES pro tuzemce), ALE jen když
        // číselná část DIČ odpovídá IČO (8 číslic). OSVČ s historickým DIČ =
        // "CZ" + rodné číslo (9–10 číslic) NENÍ IČO → ARES-dle-IČO by hledal
        // neexistující subjekt a vrátil false-negative "DIČ neexistuje" i pro
        // aktivního plátce (např. CZ8901311870 = Ing. Jiří Zikmund). Pro takové
        // (i pro 8místné IČO, které ARES nenajde) spadneme na autoritativní VIES,
        // který ověří DIČ jak je zadané.
        if ($country === 'CZ' && strlen($number) === 8) {
            $aresResult = $this->tryAres($number, $vatId);
            if ($aresResult !== null) {
                $this->cache($vatId, $aresResult);
                return $aresResult;
            }
            // ARES nedostupné / subjekt nenalezen → spadneme na VIES jako fallback
        }

        // Try REST first
        $rest = $this->tryRest($country, $number, $vatId);
        if ($rest !== null) {
            $this->cache($vatId, $rest);
            return $rest;
        }

        // SOAP fallback
        $soap = $this->trySoap($country, $number, $vatId);
        if ($soap !== null) {
            $this->cache($vatId, $soap);
            return $soap;
        }

        return ['valid' => false, 'source' => 'error'];
    }

    /**
     * CZ skupinové DIČ (kmen 699*) — ověření přes registr plátců DPH (CRPDPH),
     * protože VIES skupinové registrace DPH vůbec neobsahuje. Registr nevrací
     * název ani adresu subjektu (jen status a zveřejněné účty), proto zůstávají
     * prázdné. found = registrovaný plátce.
     *
     * Nedostupnost registru se mapuje stejně jako výpadek VIES (source 'error' =
     * „služba nedostupná"), NIKDY na tvrdé invalid — jinak by výpadek služby
     * označil platného plátce za neplatného.
     *
     * @return array{valid:bool, name?:string, address?:string, parsed?:null, country?:string, vat_number?:string, group_registration?:bool, source:'crpdph'|'error'}
     */
    private function tryCrpDph(string $number, string $vatId): array
    {
        try {
            $r = $this->crpdph->lookup($number);
        } catch (\Throwable $e) {
            $this->logger->warning('CRPDPH ověření skupinového DIČ selhalo: ' . $e->getMessage(), ['vat' => $vatId]);
            return ['valid' => false, 'source' => 'error'];
        }
        if (($r['source'] ?? '') === 'error') {
            return ['valid' => false, 'source' => 'error'];
        }

        return [
            'valid'              => (bool) ($r['found'] ?? false),
            'name'               => '',
            'address'            => '',
            'parsed'             => null,
            'country'            => 'CZ',
            'vat_number'         => $vatId,
            'source'             => 'crpdph',
            'group_registration' => true,
        ];
    }

    /**
     * CZ-only verifikace přes ARES: aktivní plátce musí mít právě ověřované DIČ.
     * Normalizace ARES zahrnuje i skupinovou registraci; samotné plátcovství člena
     * skupiny nedokládá platnost jeho původního vlastního DIČ.
     *
     * Vrací null = ARES nerozhodl (nedostupný NEBO subjekt nenalezen) → necháme
     * rozhodnout autoritativní VIES. ARES bereme jako směrodatný JEN když subjekt
     * skutečně našel; "nenalezeno" nesmí být tvrdý negativ, protože IČO odvozené
     * z DIČ nemusí existovat (viz lookup() pro OSVČ s rodným číslem v DIČ).
     *
     * @return array{valid:bool, name:string, address:string, parsed:?array, country:string, vat_number:string, source:'ares'}|null
     */
    private function tryAres(string $ico, string $vatId): ?array
    {
        $r = $this->ares->lookup($ico);
        if ($r === null || !($r['found'] ?? false)) {
            return null; // ARES nedostupný / subjekt nenalezen → fallback na VIES
        }
        $data   = $r['data'] ?? [];
        $valid  = (bool) ($data['is_vat_payer'] ?? false)
            && strtoupper(trim((string) ($data['dic'] ?? ''))) === $vatId;
        $street = (string) ($data['street'] ?? '');
        $zip    = (string) ($data['zip'] ?? '');
        $city   = (string) ($data['city'] ?? '');
        $address = trim($street . "\n" . trim($zip . ' ' . $city));

        return [
            'valid'      => $valid,
            'name'       => (string) ($data['company_name'] ?? ''),
            'address'    => $address,
            'parsed'     => ($street !== '' || $city !== '') ? [
                'street' => $street,
                'city'   => $city,
                'zip'    => $zip,
            ] : null,
            'country'    => 'CZ',
            'vat_number' => $vatId,
            'source'     => 'ares',
        ];
    }

    private function tryRest(string $country, string $number, string $vatId): ?array
    {
        $base = rtrim((string) $this->config->get('vies.rest_api', ''), '/');
        if ($base === '') return null;

        $url = "{$base}/{$country}/vat/{$number}";
        $timeout = (int) $this->config->get('vies.timeout', 8);

        try {
            $client = new Client(['timeout' => $timeout, 'connect_timeout' => $timeout]);
            $resp = $client->get($url, ['http_errors' => false, 'headers' => ['Accept' => 'application/json']]);
            $status = $resp->getStatusCode();

            if ($status >= 500) return null; // server error → fallback

            $body = json_decode((string) $resp->getBody(), true);
            if (!is_array($body)) return null;

            $valid = (bool) ($body['isValid'] ?? false);
            $address = trim((string) ($body['address'] ?? ''));
            return [
                'valid'      => $valid,
                'name'       => trim((string) ($body['name'] ?? '')),
                'address'    => $address,
                'parsed'     => $valid ? $this->parseAddress($address, $country) : null,
                'country'    => $country,
                'vat_number' => $vatId,
                'source'     => 'rest',
            ];
        } catch (GuzzleException $e) {
            $this->logger->warning('VIES REST nedostupné: ' . $e->getMessage(), ['vat' => $vatId]);
            return null;
        }
    }

    private function trySoap(string $country, string $number, string $vatId): ?array
    {
        $wsdl = (string) $this->config->get('vies.wsdl', '');
        if ($wsdl === '') return null;

        try {
            $client = new \SoapClient($wsdl, [
                'connection_timeout' => (int) $this->config->get('vies.timeout', 8),
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_BOTH,
            ]);
            $result = $client->checkVat([
                'countryCode' => $country,
                'vatNumber'   => $number,
            ]);

            $valid = (bool) ($result->valid ?? false);
            $address = trim((string) ($result->address ?? ''));
            return [
                'valid'      => $valid,
                'name'       => trim((string) ($result->name ?? '')),
                'address'    => $address,
                'parsed'     => $valid ? $this->parseAddress($address, $country) : null,
                'country'    => $country,
                'vat_number' => $vatId,
                'source'     => 'soap',
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('VIES SOAP selhalo: ' . $e->getMessage(), ['vat' => $vatId]);
            return null;
        }
    }

    /**
     * Heuristický parser VIES adresního textu. Formát se mezi zeměmi liší —
     * pokud nemá smysl parsovat, vrátí null a frontend nechá raw `address` k zobrazení.
     *
     * CZ pattern (3 řádky):
     *   "Zkušební 123/4"                    ← ulice + č.p.
     *   "VZOROV 3 - JIŽNÍ PŘEDMĚSTÍ"         ← městská část (zahodit)
     *   "100 00  VZOROV 1"                  ← PSČ + město
     *
     * SK pattern: podobný CZ.
     * DE/AT pattern: typicky "Straße 1\n12345 Stadt" (2 řádky).
     *
     * @return array{street:string, city:string, zip:string}|null
     */
    private function parseAddress(string $address, string $country): ?array
    {
        $address = trim($address);
        if ($address === '') return null;

        // Normalizuj line endings, zahoď prázdné řádky
        $lines = preg_split('/\r\n|\r|\n/', $address) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== ''));
        if (empty($lines)) return null;

        $cz = ['CZ', 'SK'];
        if (in_array($country, $cz, true)) {
            return $this->parseCzSk($lines);
        }

        // DE/AT/CH/PL: pattern "PSČ město" v posledním řádku
        return $this->parseGeneric($lines);
    }

    /**
     * @param string[] $lines
     */
    private function parseCzSk(array $lines): ?array
    {
        // Drop trailing country name lines (VIES SK: "Slovensko", VIES CZ: "Česko" / "Česká republika")
        $countryNames = ['slovensko', 'slovenská republika', 'slovenska republika', 'česko', 'cesko', 'česká republika', 'ceska republika', 'czech republic', 'czechia'];
        while (!empty($lines)) {
            $tail = mb_strtolower(end($lines), 'UTF-8');
            if (in_array($tail, $countryNames, true)) {
                array_pop($lines);
            } else {
                break;
            }
        }
        if (empty($lines)) return null;

        // Poslední řádek = "PSČ město" — CZ má "100 00 Vzorov", SK má "82108 Bratislava"
        $last = end($lines);
        if (!preg_match('/^(\d{3}\s?\d{2})\s+(.+)$/u', $last, $m)) {
            return null;
        }
        $zip  = preg_replace('/\s+/', ' ', $m[1]);
        $city = trim($m[2]);

        // Strip suffixy typu "Bratislava - mestská časť Ružinov" → "Bratislava"
        $city = preg_replace('/\s*-\s*(mestská|mestska)\s+(časť|cast)\b.*$/iu', '', $city);
        $city = $this->prettyCase(trim($city));

        // První řádek = ulice
        $street = $lines[0] ?? '';

        // Pokud byl jen 1 řádek a obsahuje vše, sbalíme jinak
        if ($street === $last) {
            return null;
        }

        return [
            'street' => $this->prettyCase($street),
            'city'   => $city,
            'zip'    => $zip,
        ];
    }

    /**
     * @param string[] $lines
     */
    private function parseGeneric(array $lines): ?array
    {
        // Generic EU: poslední řádek "PSČ město"
        $last = end($lines);
        // Variace: "12345 Stadt", "12345  Stadt", "L-1234 City" (Lucembursko), "AT-1230 Wien"
        if (preg_match('/^((?:[A-Z]{1,2}-?)?\d{3,5})\s+(.+)$/u', $last, $m)) {
            $street = $lines[0] ?? '';
            return [
                'street' => trim($street),
                'city'   => trim($m[2]),
                'zip'    => trim($m[1]),
            ];
        }
        return null;
    }

    /**
     * Konvertuje "ZKUŠEBNÍ" → "Zkušební", "VZOROV 1" → "Vzorov 1".
     * Pro CZ/SK adresy jsou často všechny velké, ALE názvy ulic/měst chceme s velkým prvním písmenem.
     */
    private function prettyCase(string $s): string
    {
        // Pokud text obsahuje malá písmena, nech ho být (už je správně formátovaný)
        if (preg_match('/[a-záčďéěíňóřšťúůýž]/u', $s)) {
            return $s;
        }

        // Mb_convert_case s MODE_TITLE
        return mb_convert_case(mb_strtolower($s, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }

    private function fromCache(string $vatId): ?array
    {
        $ttl = (int) $this->config->get('vies.cache_ttl', 86400);
        $stmt = $this->db->pdo()->prepare(
            'SELECT payload FROM vies_cache WHERE vat_id = ? AND fetched_at > NOW() - INTERVAL ? SECOND'
        );
        $stmt->execute([$vatId, $ttl]);
        $row = $stmt->fetchColumn();
        if ($row === false) return null;
        $data = json_decode((string) $row, true);
        if (!is_array($data)) return null;
        // Jen výsledky odvozené z ARES závisí na výkladu jeho registrací DPH.
        // Staré pozitivum ani negativum nesmí přebít nové přesné ověření DIČ.
        if (($data['source'] ?? '') === 'ares'
            && ($data['normalization_version'] ?? null) !== AresClient::NORMALIZATION_VERSION
        ) {
            return null;
        }
        unset($data['normalization_version']);

        // Repair: starší cache může mít parsed:null kvůli předchozí slabší heuristice.
        // Když je payload validní a máme address+country, zkusíme re-parse novou logikou.
        if (
            ($data['valid'] ?? false) === true
            && ($data['parsed'] ?? null) === null
            && !empty($data['address'])
            && !empty($data['country'])
        ) {
            $reparsed = $this->parseAddress((string) $data['address'], (string) $data['country']);
            if ($reparsed !== null) {
                $data['parsed'] = $reparsed;
                $this->cache($vatId, $data);
            }
        }
        return $data;
    }

    private function cache(string $vatId, array $payload): void
    {
        if (($payload['source'] ?? '') === 'ares') {
            $payload['normalization_version'] = AresClient::NORMALIZATION_VERSION;
        }
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO vies_cache (vat_id, is_valid, payload) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE is_valid = VALUES(is_valid), payload = VALUES(payload), fetched_at = NOW()'
        );
        $stmt->execute([
            $vatId,
            $payload['valid'] ? 1 : 0,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
    }
}
