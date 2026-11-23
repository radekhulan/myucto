<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Migration\MoneyS3\AccountCode;
use MyInvoice\Service\Migration\Shared\PartnerCountry;
use MyInvoice\Service\Migration\Shared\PartnerIdentityMatcher;
use PDO;

/**
 * Adresář partnerů (`30_adresar.xml`) a předkontace (`03_predkontace_pu.xml`).
 *
 * Partner se stejným IČO, který už ve firmě je, se použije - převod nezakládá druhého
 * a u existující karty jen doplní chybějící údaje. Vlastní firma se přeskakuje. Vede-li
 * adresář totéž IČO na kartách s různými názvy (přejmenování, transformace), je každý
 * název samostatný kontakt a doklad patří ke kartě podle vazby nebo názvu
 * ({@see mappedPartner()}).
 * Doklad s partnerem mimo adresář (Pohoda drží na dokladu kopii adresy) partnera založí
 * z údajů dokladu ({@see resolvePartner()}).
 *
 * Normalizace IČO/DIČ a párování na existující kontakt jsou společné všem převodům
 * ({@see PartnerIdentityMatcher}).
 *
 * Předkontace jdou do `posting_rules` s klíčem = zkratka z Pohody. Na rozdíl od deníku
 * mají v seznamu předkontací strany pojmenované normálně (`debit` = MD, `credit` = Dal).
 */
final class PartnerImporter
{
    public const STEP_PARTNERS = 'partners';
    public const STEP_POSTING_RULES = 'posting_rules';

    /**
     * Výchozí měna a země nových kontaktů po firmách: jeden proces (job, testy) převádí
     * i víc firem a měna je řádek číselníku konkrétní firmy.
     *
     * @var array<int,array{currency_id:int,country_id:int}>
     */
    private array $defaults = [];

    /** @var array<string,int|null> */
    private array $countryIds = [];

    /** @var array<int,array<string,true>> IČO, u kterých protokol už nejednoznačný název ohlásil */
    private array $ambiguousIcos = [];

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $map,
        private readonly PartnerIdentityMatcher $identity,
    ) {}

    public function importPartners(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        $this->loadClientIndex($ctx);
        $existingByName = $this->clientsByIcoName($ctx->supplierId);
        $ownIco = self::ico($ctx->export->ico);

        $cards = [];
        /** @var array<string,array<string,true>> $names IČO => názvy jeho karet */
        $names = [];
        foreach ($ctx->export->records('addressbook', 'addressbook') as $r) {
            $h = PohodaXml::get($r, 'addressbookHeader');
            $id = PohodaXml::text($h, 'id');
            $s = self::snapshot(PohodaXml::get($h, 'identity'));
            if ($s['name'] === '' || $id === '' || ($s['ico'] !== '' && $s['ico'] === $ownIco)) {
                $p->count(self::STEP_PARTNERS, 'skipped');
                continue;
            }
            $cards[] = [$id, $h, $s];
            if ($s['ico'] !== '') {
                $names[$s['ico']][self::nameKey($s['name'])] = true;
            }
        }
        foreach ($names as $cardNames) {
            if (count($cardNames) > 1) {
                $p->count(self::STEP_PARTNERS, 'split_by_name');
            }
        }

        foreach ($cards as [$id, $h, $s]) {
            $nameKey = self::nameKey($s['name']);
            // IČO vedené na kartách s různými názvy (přejmenování, transformace): každý
            // název je samostatný kontakt, karty se stejným názvem sdílí jeden.
            $byName = $s['ico'] !== '' && count($names[$s['ico']]) > 1;
            $key = 'ab:' . $id;
            $mapped = $this->map->get($ctx->supplierId, PohodaImportRepository::KIND_CLIENT, $key)
                ?? $this->map->get($ctx->supplierId, PohodaImportRepository::KIND_CLIENT_MATCH, $key);
            if ($mapped !== null) {
                $this->index($ctx, $id, $s['ico'], $nameKey, $mapped);
                $p->count(self::STEP_PARTNERS, 'existing');
                continue;
            }
            if ($ctx->skipsNew(self::STEP_PARTNERS)) {
                continue;
            }
            $s['email'] = PohodaXml::text($h, 'email');
            $s['phone'] = PohodaXml::text($h, 'mobil') ?: PohodaXml::text($h, 'phone');
            $s['note'] = 'Převzato z Pohody (adresář č. ' . $id . ')';
            $existing = match (true) {
                $s['ico'] === '' => null,
                $byName => $ctx->clientsByIcoName[$s['ico']][$nameKey] ?? $existingByName[$s['ico']][$nameKey] ?? null,
                default => $ctx->clientsByIco[$s['ico']] ?? null,
            };
            if ($existing !== null) {
                $clientId = $existing;
                $this->identity->fillMissing($ctx->supplierId, $clientId, $s);
                $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_CLIENT_MATCH, $key, $clientId, $ctx->runId);
                $p->count(self::STEP_PARTNERS, 'matched');
            } else {
                $clientId = $this->insertClient($ctx, $s);
                $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_CLIENT, $key, $clientId, $ctx->runId);
                $p->count(self::STEP_PARTNERS, 'created');
            }
            $this->index($ctx, $id, $s['ico'], $nameKey, $clientId);
        }
        $this->completeForeignVatIdsFromDocuments($ctx);
        $p->finish(self::STEP_PARTNERS);
    }

    /** Kontakt karty adresáře do indexů převodu (první karta IČO zůstává výchozí pro IČO). */
    private function index(PohodaContext $ctx, string $cardId, string $ico, string $nameKey, int $clientId): void
    {
        $ctx->clientsByPohodaId[$cardId] = $clientId;
        if ($ico !== '') {
            $ctx->clientsByIco[$ico] ??= $clientId;
            $ctx->clientsByIcoName[$ico][$nameKey] ??= $clientId;
        }
    }

    /**
     * Klíč názvu pro porovnání karty, dokladu a kontaktu: bez rozdílu velikosti písmen
     * a opakovaných mezer.
     */
    public static function nameKey(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /**
     * Nearchivované kontakty firmy podle IČO a názvu (nejstarší s daným názvem).
     *
     * @return array<string,array<string,int>>
     */
    private function clientsByIcoName(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT id, ic, company_name FROM clients WHERE supplier_id = ? AND ic IS NOT NULL AND ic <> '' AND archived_at IS NULL ORDER BY id");
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ico = self::ico((string) $row['ic']);
            if ($ico !== '') {
                $out[$ico][self::nameKey((string) $row['company_name'])] ??= (int) $row['id'];
            }
        }
        return $out;
    }

    /**
     * DIČ zahraničních dodavatelů z přijatých dokladů ještě před jejich převodem. Převod
     * dokladu řadí samovyměření podle státu dodavatele ({@see InvoiceImporter}) a evidence
     * DPH ho čte z kontaktu - kdyby se kontakt opravil až u pozdějšího dokladu, dřívější
     * doklady téhož dodavatele by se převedly jako tuzemské a období by se jim posunulo.
     */
    private function completeForeignVatIdsFromDocuments(PohodaContext $ctx): void
    {
        $sources = array_map(static fn (): array => ['invoice', 'invoiceHeader'], InvoiceImporter::PURCHASE_AGENDAS)
            + ['internal' => ['intDoc', 'intDocHeader']];
        foreach ($sources as $agenda => [$element, $header]) {
            foreach ($ctx->export->records($agenda, $element) as $r) {
                $s = self::snapshot(PohodaXml::get(PohodaXml::get($r, $header), 'partnerIdentity'));
                if ($s['dic'] !== '' && isset($ctx->clientsByPohodaId[$s['pohoda_id']])) {
                    $this->completeForeignVatId($ctx, $ctx->clientsByPohodaId[$s['pohoda_id']], $s['dic']);
                }
            }
        }
    }

    /**
     * Partner dokladu: podle vazby na adresář Pohody (`typ:id`), IČO, názvu, jinak se
     * založí z údajů na dokladu.
     *
     * @param array{pohoda_id:string,name:string,ico:string,dic:string,street:string,city:string,zip:string,country:string} $s
     */
    public function resolvePartner(PohodaContext $ctx, array $s): int
    {
        $name = $s['name'] !== '' ? $s['name'] : 'Neznámý partner z Pohody';
        $mapped = $this->mappedPartner($ctx, $s, $name);
        if ($mapped !== null) {
            $this->completeForeignVatId($ctx, $mapped, $s['dic']);
            return $mapped;
        }
        $key = PartnerIdentityMatcher::documentKey($s['ico'], $name);
        if ($s['ico'] === '') {
            $found = $this->identity->clientByName($ctx->supplierId, mb_substr($name, 0, 190));
            if ($found !== null) {
                $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_CLIENT_MATCH, $key, $found, $ctx->runId);
                return $found;
            }
        }
        $clientId = $this->insertClient($ctx, $s + ['name' => $name, 'email' => '', 'phone' => '', 'note' => 'Převzato z Pohody (podle dokladu)']);
        $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_CLIENT, $key, $clientId, $ctx->runId);
        if ($s['ico'] !== '') {
            $ctx->clientsByIco[$s['ico']] = $clientId;
        }
        $ctx->protocol->count(self::STEP_PARTNERS, 'created_from_documents');
        return $clientId;
    }

    /**
     * Partner, kterého už firma má, stejnými pravidly jako {@see resolvePartner()}, ale bez
     * založení karty a bez zápisu do mapy. `null` = partner v MyÚčtu není.
     *
     * @param array{pohoda_id:string,name:string,ico:string,dic:string,street:string,city:string,zip:string,country:string} $s
     */
    public function knownPartner(PohodaContext $ctx, array $s): ?int
    {
        $mapped = $this->mappedPartner($ctx, $s, $s['name']);
        if ($mapped !== null || $s['ico'] !== '' || $s['name'] === '') {
            return $mapped;
        }
        return $this->identity->clientByName($ctx->supplierId, mb_substr($s['name'], 0, 190));
    }

    /**
     * Adresář POHODY často nevede DIČ zahraničního dodavatele, nese ho až doklad. Kontakt by
     * zůstal se státem firmy a samovyměření by evidence DPH brala jako tuzemský doklad.
     * Kontaktu bez DIČ se doplní DIČ z dokladu a stát podle jeho předpony; kontakt, který
     * DIČ už má, se nemění.
     */
    private function completeForeignVatId(PohodaContext $ctx, int $clientId, string $dic): void
    {
        $dic = self::vatId($dic);
        $iso2 = PartnerCountry::fromVatId($dic);
        if ($iso2 === null || $iso2 === 'CZ') {
            return;
        }
        $countryId = $this->countryId($iso2 === 'EL' ? 'GR' : $iso2);
        if ($countryId !== null) {
            $this->db->pdo()->prepare("UPDATE clients SET country_id = ? WHERE id = ? AND supplier_id = ? AND (dic IS NULL OR dic = '') AND country_id <=> ?")
                ->execute([$countryId, $clientId, $ctx->supplierId, $this->defaults($ctx->supplierId)['country_id']]);
        }
        $this->identity->fillMissing($ctx->supplierId, $clientId, ['dic' => $dic, 'street' => '', 'city' => '', 'zip' => '', 'email' => '', 'phone' => '']);
    }

    /**
     * Kontakt dokladu z převodu: rozhoduje vazba na kartu adresáře (`typ:id`). Doklad bez
     * vazby dostane kontakt podle IČO, a vede-li adresář IČO na kartách s různými názvy,
     * ten se stejným názvem jako doklad ({@see partnerByIco()}).
     *
     * @param array{pohoda_id:string,ico:string} $s
     */
    private function mappedPartner(PohodaContext $ctx, array $s, string $name): ?int
    {
        if ($s['pohoda_id'] !== '' && isset($ctx->clientsByPohodaId[$s['pohoda_id']])) {
            return $ctx->clientsByPohodaId[$s['pohoda_id']];
        }
        if ($s['ico'] !== '' && isset($ctx->clientsByIco[$s['ico']])) {
            return $this->partnerByIco($ctx, $s['ico'], $name);
        }
        $key = PartnerIdentityMatcher::documentKey($s['ico'], $name);
        return $this->map->get($ctx->supplierId, PohodaImportRepository::KIND_CLIENT, $key)
            ?? $this->map->get($ctx->supplierId, PohodaImportRepository::KIND_CLIENT_MATCH, $key);
    }

    /**
     * Kontakt podle IČO. Vede-li adresář IČO na kartách s různými názvy, kontakt karty se
     * stejným názvem jako doklad; bez jednoznačné shody první karta IČO a upozornění
     * v protokolu (jednou za IČO).
     */
    private function partnerByIco(PohodaContext $ctx, string $ico, string $name): int
    {
        $byName = $ctx->clientsByIcoName[$ico] ?? [];
        if (count(array_unique($byName)) <= 1) {
            return $ctx->clientsByIco[$ico];
        }
        $found = $byName[self::nameKey($name)] ?? null;
        if ($found !== null) {
            return $found;
        }
        $ctx->protocol->count(self::STEP_PARTNERS, 'partner_name_ambiguous');
        if (!isset($this->ambiguousIcos[$ctx->supplierId][$ico])) {
            $this->ambiguousIcos[$ctx->supplierId][$ico] = true;
            $ctx->protocol->warn(self::STEP_PARTNERS, 'partner_name_ambiguous',
                "Adresář vede IČO {$ico} na více kartách s různými názvy a doklad s názvem „{$name}“ neodpovídá žádné z nich. Doklady s tímto názvem jsou u kontaktu první karty, ověřte je.",
                ['ico' => $ico, 'name' => $name]);
        }
        return reset($byName);
    }

    public function importPostingRules(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $exists = $pdo->prepare('SELECT id FROM posting_rules WHERE supplier_id = ? AND rule_key = ? LIMIT 1');
        $insert = $pdo->prepare(
            'INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
             VALUES (?, ?, ?, ?, ?, 100, 1)'
        );
        $seen = [];
        foreach ($ctx->export->records('posting_rules', 'itemAccounting') as $r) {
            $key = mb_substr(trim((string) ($r['@code'] ?? '')), 0, 64);
            $desc = trim((string) ($r['@accounting'] ?? ''));
            if ($key === '' || $desc === '') {
                continue;
            }
            if (isset($seen[$key])) {
                $p->count(self::STEP_POSTING_RULES, 'duplicate_code');
                continue;
            }
            $seen[$key] = true;
            if ($this->map->get($ctx->supplierId, PohodaImportRepository::KIND_POSTING_RULE, $key) !== null) {
                $p->count(self::STEP_POSTING_RULES, 'existing');
                continue;
            }
            $exists->execute([$ctx->supplierId, $key]);
            if ($exists->fetchColumn() !== false) {
                // Pravidlo se stejným klíčem si firma založila sama - převod ho nepřepisuje.
                $p->count(self::STEP_POSTING_RULES, 'kept');
                continue;
            }
            $insert->execute([
                $ctx->supplierId,
                $key,
                mb_substr($desc, 0, 255),
                $this->knownAccount($ctx, (string) ($r['@debit'] ?? '')),
                $this->knownAccount($ctx, (string) ($r['@credit'] ?? '')),
            ]);
            $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_POSTING_RULE, $key, (int) $pdo->lastInsertId(), $ctx->runId);
            $p->count(self::STEP_POSTING_RULES, 'created');
        }
        $p->finish(self::STEP_POSTING_RULES);
    }

    /**
     * Adresa strany dokladu nebo záznamu adresáře (`…Identity` s `typ:id` a `typ:address`).
     *
     * @return array{pohoda_id:string,name:string,ico:string,dic:string,street:string,city:string,zip:string,country:string}
     */
    public static function snapshot(mixed $identity): array
    {
        $a = PohodaXml::get($identity, 'address');
        $company = PohodaXml::text($a, 'company');
        $person = trim(PohodaXml::text($a, 'name') . ' ' . PohodaXml::text($a, 'surname'));
        $street = PohodaXml::text($a, 'street');
        $number = PohodaXml::text($a, 'number');
        return [
            'pohoda_id' => PohodaXml::text($identity, 'id'),
            'name' => $company !== '' ? $company : $person,
            'ico' => self::ico(PohodaXml::text($a, 'ico')),
            'dic' => strtoupper(str_replace(' ', '', PohodaXml::text($a, 'dic'))),
            'street' => trim($street . ($number !== '' ? ' ' . $number : '')),
            'city' => PohodaXml::text($a, 'city'),
            'zip' => str_replace(' ', '', PohodaXml::text($a, 'zip')),
            'country' => strtoupper(PohodaXml::text($a, 'country/ids')),
        ];
    }

    /** @param array{name:string,ico:string,dic:string,street:string,city:string,zip:string} $s */
    public static function snapshotJson(array $s): string
    {
        return (string) json_encode([
            'company_name' => $s['name'], 'street' => $s['street'], 'city' => $s['city'],
            'zip' => $s['zip'], 'ic' => $s['ico'], 'dic' => $s['dic'],
        ], JSON_UNESCAPED_UNICODE);
    }

    /** IČO v kanonickém tvaru (8 číslic) - Pohoda vede tentýž subjekt i bez vodicí nuly. */
    public static function ico(string $ico): string
    {
        return PartnerIdentityMatcher::ico($ico);
    }

    /** DIČ, jen když má tvar DIČ (kód státu a aspoň jedna číslice). */
    public static function vatId(string $dic): string
    {
        return PartnerIdentityMatcher::vatId($dic);
    }

    private function knownAccount(PohodaContext $ctx, string $code): ?string
    {
        $target = AccountCode::fromMoney($code);
        return $target !== null && isset($ctx->accountIds[$target]) ? $target : null;
    }

    private function loadClientIndex(PohodaContext $ctx): void
    {
        foreach ($this->identity->clientsByIco($ctx->supplierId) as $ico => $clientId) {
            $ctx->clientsByIco[$ico] ??= $clientId;
        }
    }

    /** @param array{name:string,ico:string,dic:string,street:string,city:string,zip:string,country:string,email:string,phone:string,note:string} $s */
    private function insertClient(PohodaContext $ctx, array $s): int
    {
        $defaults = $this->defaults($ctx->supplierId);
        $dic = self::vatId($s['dic']);
        // Zahraniční partner se zemí firmy by vypadl ze souhrnného hlášení a samovyměření
        // by se bralo jako tuzemské: země z předpony DIČ, jinak ze státu na adrese.
        $fromVat = PartnerCountry::fromVatId($dic);
        $country = $fromVat !== null && $fromVat !== 'CZ' ? $fromVat : $s['country'];
        $countryId = $this->countryId($country === 'EL' ? 'GR' : $country) ?? $defaults['country_id'];
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, ic, dic, street, city, zip, country_id, main_email, phone,
                 currency_default_id, is_customer, is_vendor, is_vat_payer, note, auto_send_reminders)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?, ?, 0)'
        )->execute([
            $ctx->supplierId,
            mb_substr($s['name'], 0, 190),
            $s['ico'] !== '' ? $s['ico'] : null,
            $dic !== '' ? mb_substr($dic, 0, 20) : null,
            mb_substr($s['street'] !== '' ? $s['street'] : '-', 0, 190),
            mb_substr($s['city'] !== '' ? $s['city'] : '-', 0, 120),
            mb_substr($s['zip'] !== '' ? $s['zip'] : '-', 0, 10),
            $countryId,
            $s['email'] !== '' ? mb_substr($s['email'], 0, 190) : null,
            $s['phone'] !== '' ? mb_substr($s['phone'], 0, 40) : null,
            $defaults['currency_id'],
            $dic !== '' ? 1 : 0,
            $s['note'],
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function countryId(string $iso2): ?int
    {
        $iso2 = strtoupper(trim($iso2));
        if (preg_match('/^[A-Z]{2}$/', $iso2) !== 1) {
            return null;
        }
        if (!array_key_exists($iso2, $this->countryIds)) {
            $stmt = $this->db->pdo()->prepare('SELECT id FROM countries WHERE iso2 = ? LIMIT 1');
            $stmt->execute([$iso2]);
            $id = $stmt->fetchColumn();
            $this->countryIds[$iso2] = $id === false ? null : (int) $id;
        }
        return $this->countryIds[$iso2];
    }

    /** @return array{currency_id:int,country_id:int} */
    private function defaults(int $supplierId): array
    {
        if (isset($this->defaults[$supplierId])) {
            return $this->defaults[$supplierId];
        }
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT default_currency_id, country_id FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $currencyId = (int) ($row['default_currency_id'] ?? 0);
        if ($currencyId === 0) {
            $c = $pdo->prepare("SELECT id FROM currencies WHERE supplier_id = ? AND code = 'CZK' ORDER BY id LIMIT 1");
            $c->execute([$supplierId]);
            $currencyId = (int) $c->fetchColumn();
        }
        return $this->defaults[$supplierId] = ['currency_id' => $currencyId, 'country_id' => $this->countryId('CZ') ?? (int) ($row['country_id'] ?? 0)];
    }
}
