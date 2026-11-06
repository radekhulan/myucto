<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientBankAccountRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Geo\CountryNameMatcher;
use MyInvoice\Service\Migration\Shared\PartnerCountry;
use MyInvoice\Service\Migration\Shared\PartnerIdentityMatcher;
use PDO;

/**
 * Adresář partnerů (`AdresarF`), jejich bankovní spojení (`AdUcBan`) a předkontace
 * (`UcPrKont`).
 *
 * Partner se stejným IČO, který už ve firmě je, se použije — převod nezakládá druhého.
 * Vlastní firma (Money ji má v adresáři jako záznam č. 1) se přeskakuje. Normalizace
 * IČO/DIČ a párování na existující kontakt jsou společné všem převodům
 * ({@see PartnerIdentityMatcher}).
 *
 * Předkontace se přenáší jako `posting_rules` s klíčem = zkratka z Money. Pokladní
 * doklad si ji nese v `rule_key`, takže je na čem stavět automatické účtování dalších
 * dokladů; převedený deník se podle ní nepřepočítává.
 */
final class CodebookImporter
{
    public const STEP_PARTNERS = 'partners';
    public const STEP_POSTING_RULES = 'posting_rules';

    public function __construct(
        private readonly Connection $db,
        private readonly ClientBankAccountRepository $bankAccounts,
        private readonly MoneyS3ImportRepository $map,
        private readonly PartnerIdentityMatcher $identity,
    ) {}

    public function importPartners(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $defaults = $this->defaults($ctx->supplierId);
        $ownIco = self::ico($ctx->agenda->ico);
        $this->loadClientIndex($ctx);

        $table = $ctx->backup->table('AdresarF');
        if ($table !== null && $table->hasData()) {
            foreach ($table->rows() as $r) {
                $no = (int) ($r['Cislo'] ?? 0);
                $name = trim((string) ($r['Nazev'] ?? $r['Firma'] ?? ''));
                $ico = self::ico((string) ($r['ICO'] ?? ''));
                if ($name === '' || ($ico !== '' && $ico === $ownIco)) {
                    continue;
                }
                $key = 'no:' . $no;
                $mapped = $this->map->get($ctx->supplierId, MoneyS3ImportRepository::KIND_CLIENT, $key);
                if ($mapped !== null) {
                    $ctx->clientsByMoneyNo[$no] = $mapped;
                    $p->count(self::STEP_PARTNERS, 'existing');
                    continue;
                }
                if ($ctx->skipsNew(self::STEP_PARTNERS)) {
                    continue;
                }
                $data = [
                    'name' => $name,
                    'ico' => $ico,
                    'dic' => trim((string) ($r['DIC'] ?? '')),
                    'street' => trim((string) ($r['Ulice'] ?? '')),
                    'city' => trim((string) ($r['Misto'] ?? '')),
                    'zip' => trim((string) ($r['PSC'] ?? '')),
                    'email' => trim((string) ($r['EMail'] ?? '')),
                    'phone' => trim((string) ($r['TelCislo'] ?? '')),
                    'country' => trim((string) (($r['Stat'] ?? '') ?: ($r['FaktStat'] ?? '') ?: ($r['ObchStat'] ?? ''))),
                    'note' => 'Převzato z Money S3 (adresa č. ' . $no . ')',
                ];
                if ($ico !== '' && isset($ctx->clientsByIco[$ico])) {
                    $clientId = $ctx->clientsByIco[$ico];
                    $this->identity->fillMissing($ctx->supplierId, $clientId, $data);
                    $p->count(self::STEP_PARTNERS, 'matched');
                } else {
                    $clientId = $this->insertClient($ctx, $data, $defaults);
                    $p->count(self::STEP_PARTNERS, 'created');
                }
                $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_CLIENT, $key, $clientId, $ctx->runId);
                $ctx->clientsByMoneyNo[$no] = $clientId;
                if ($ico !== '') {
                    $ctx->clientsByIco[$ico] = $clientId;
                }
            }
        }
        if ($ctx->options->relinkOnly) {
            // Opravný převod karty partnerů ani jejich bankovní spojení nemění.
            $p->finish(self::STEP_PARTNERS);
            return;
        }
        $this->markExistingRelatedParties($ctx);

        // Bankovní spojení jde přes repozitář aplikace: normalizace čísla účtu a odvozené
        // klíče, na kterých stojí párování plateb a hlídání změny účtu dodavatele, patří
        // tam. `AdUcBan` váže účet na pořadové číslo adresy, ne na IČO.
        $accounts = $ctx->backup->table('AdUcBan');
        if ($accounts !== null && $accounts->hasData()) {
            foreach ($accounts->rows() as $r) {
                $clientId = $ctx->clientsByMoneyNo[(int) ($r['CisPartn'] ?? 0)] ?? null;
                $number = trim((string) ($r['Ucet'] ?? ''));
                if ($clientId === null || preg_match('/^(\d{1,6}-)?\d{2,10}$/', $number) !== 1) {
                    continue;
                }
                try {
                    $this->bankAccounts->addManual($clientId, $ctx->supplierId, [
                        'account_number' => $number,
                        'bank_code' => trim((string) ($r['KodBanky'] ?? '')) ?: null,
                    ]);
                    $p->count(self::STEP_PARTNERS, 'bank_accounts');
                } catch (\Throwable $e) {
                    $p->warn(self::STEP_PARTNERS, 'bank_account_rejected', "Bankovní spojení {$number} nepřevzato: " . $e->getMessage());
                }
            }
        }
        $p->finish(self::STEP_PARTNERS);
    }

    /**
     * Partner dokladu podle IČO, jinak podle názvu, jinak se založí z údajů na dokladu
     * (Money drží na dokladu kopii adresy, partner v adresáři chybět může).
     *
     * @param array{name:string,ico:string,dic:string,street:string,city:string,zip:string,country?:string} $snapshot
     */
    public function resolvePartner(ImportContext $ctx, array $snapshot): int
    {
        $ico = self::ico($snapshot['ico']);
        if ($ico !== '' && isset($ctx->clientsByIco[$ico])) {
            return $ctx->clientsByIco[$ico];
        }
        $name = trim($snapshot['name']) !== '' ? trim($snapshot['name']) : 'Neznámý partner z Money S3';
        $key = PartnerIdentityMatcher::documentKey($ico, $name);
        $mapped = $this->map->get($ctx->supplierId, MoneyS3ImportRepository::KIND_CLIENT, $key);
        if ($mapped !== null) {
            return $mapped;
        }
        if ($ico === '') {
            // Název se na rozdíl od Pohody a PREMIER nezkracuje na délku sloupce (190).
            $found = $this->identity->clientByName($ctx->supplierId, $name);
            if ($found !== null) {
                $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_CLIENT, $key, $found, $ctx->runId);
                return $found;
            }
        }
        $clientId = $this->insertClient($ctx, $snapshot + ['email' => '', 'phone' => '', 'note' => 'Převzato z Money S3 (podle dokladu)'], $this->defaults($ctx->supplierId));
        $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_CLIENT, $key, $clientId, $ctx->runId);
        if ($ico !== '') {
            $ctx->clientsByIco[$ico] = $clientId;
        }
        $ctx->protocol->count(self::STEP_PARTNERS, 'created_from_documents');
        return $clientId;
    }

    public function importPostingRules(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $exists = $pdo->prepare('SELECT id FROM posting_rules WHERE supplier_id = ? AND rule_key = ? LIMIT 1');
        $insert = $pdo->prepare(
            'INSERT INTO posting_rules
                (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
             VALUES (?, ?, ?, ?, ?, 100, ?)'
        );
        $recent = $this->recentlyUsedKeys($ctx);
        $seen = [];
        foreach ($ctx->backup->rowsAcrossYears('UcPrKont') as $r) {
            $key = mb_substr(trim((string) ($r['Zkrat'] ?? '')), 0, 64);
            $desc = trim((string) ($r['Popis'] ?? ''));
            if ($key === '' || $desc === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ($this->map->get($ctx->supplierId, MoneyS3ImportRepository::KIND_POSTING_RULE, $key) !== null) {
                $p->count(self::STEP_POSTING_RULES, 'existing');
                continue;
            }
            $exists->execute([$ctx->supplierId, $key]);
            $found = $exists->fetchColumn();
            if ($found !== false) {
                // Pravidlo se stejným klíčem si firma založila sama — převod ho nepřepisuje.
                $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_POSTING_RULE, $key, (int) $found, $ctx->runId);
                $p->count(self::STEP_POSTING_RULES, 'kept');
                continue;
            }
            $insert->execute([
                $ctx->supplierId,
                $key,
                mb_substr($desc, 0, 255),
                $this->knownAccount($ctx, (string) ($r['UcMD'] ?? '')),
                $this->knownAccount($ctx, (string) ($r['UcD'] ?? '')),
                $recent === null || isset($recent[$key]) ? 1 : 0,
            ]);
            $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_POSTING_RULE, $key, (int) $pdo->lastInsertId(), $ctx->runId);
            $p->count(self::STEP_POSTING_RULES, 'created');
            if ($recent !== null && !isset($recent[$key])) {
                $p->count(self::STEP_POSTING_RULES, 'inactive');
            }
        }
        $p->finish(self::STEP_POSTING_RULES);
    }

    /**
     * Zkratky předkontací, které doklady Money použily v posledních dvou převáděných
     * letech. Číselník Money se za roky nabalí stovkami starých variant — aktivní
     * zůstanou jen tyto, ostatní se převedou vypnuté. Bez známých let (null) aktivní všechny.
     *
     * @return array<string,true>|null
     */
    private function recentlyUsedKeys(ImportContext $ctx): ?array
    {
        if ($ctx->dirYears === []) {
            return null;
        }
        $from = max($ctx->dirYears) - 1;
        $used = [];
        foreach ($ctx->backup->yearDirs() as $dir) {
            if (($ctx->dirYears[basename($dir)] ?? 0) < $from) {
                continue;
            }
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*.[Dd][Aa][Tt]') ?: [] as $path) {
                $name = pathinfo($path, PATHINFO_FILENAME);
                if (preg_match('/^(BankKnih|IntDokl|KnihPohl|KnihZav|PoklKnih|PolUcD\w*)$/i', $name) !== 1) {
                    continue;
                }
                $table = $ctx->backup->table($name, $dir);
                if ($table === null || !$table->hasData()) {
                    continue;
                }
                foreach ($table->rows() as $row) {
                    $key = mb_substr(trim((string) ($row['PrKont'] ?? '')), 0, 64);
                    if ($key !== '') {
                        $used[$key] = true;
                    }
                }
            }
        }
        return $used;
    }

    /** Účet předkontace jen tehdy, když je v osnově — `xxxxxx` (nedosazeno) a neznámý kód jsou NULL. */
    private function knownAccount(ImportContext $ctx, string $moneyCode): ?string
    {
        $code = AccountCode::fromMoney($moneyCode);
        return $code !== null && isset($ctx->accountIds[$code]) ? $code : null;
    }

    /**
     * Spřízněné osoby mezi partnery, které převod nezaložil, ale spároval (kontakt už ve
     * firmě byl, nebo ho založil dřívější běh). `insertClient()` příznak dává jen nově
     * zakládaným, takže partner s existující kartou ze sestavy spojených osob vypadl.
     * Karta už označená si svůj typ vztahu i doložení ponechá.
     */
    private function markExistingRelatedParties(ImportContext $ctx): void
    {
        $ids = [];
        foreach ($ctx->options->relatedPartyIcos as $ico) {
            $clientId = $ctx->clientsByIco[self::ico($ico)] ?? null;
            if ($clientId !== null) {
                $ids[$clientId] = $clientId;
            }
        }
        if ($ids === []) {
            return;
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "UPDATE clients SET related_party = 1, related_party_type = COALESCE(related_party_type, 'capital')
              WHERE supplier_id = ? AND related_party = 0 AND id IN ({$marks})"
        );
        $stmt->execute([$ctx->supplierId, ...array_values($ids)]);
        if ($stmt->rowCount() > 0) {
            $ctx->protocol->count(self::STEP_PARTNERS, 'related_marked', $stmt->rowCount());
        }
    }

    private function loadClientIndex(ImportContext $ctx): void
    {
        foreach ($this->identity->clientsByIco($ctx->supplierId) as $ico => $clientId) {
            $ctx->clientsByIco[$ico] ??= $clientId;
        }
    }

    /**
     * @param array{name:string,ico:string,dic:string,street:string,city:string,zip:string,email:string,phone:string,note:string,country?:string} $data
     * @param array{currency_id:int,country_id:int} $defaults
     */
    private function insertClient(ImportContext $ctx, array $data, array $defaults): int
    {
        $ico = self::ico($data['ico']);
        $related = $ico !== '' && in_array($ico, array_map(self::ico(...), $ctx->options->relatedPartyIcos), true);
        // Značka člena skupiny DPH („SKUPINOVE_DPH") DIČ není: partner je plátcem, ale DIČ
        // skupiny Money nezná — karta ho nedostane a poznámka řekne, že ho je potřeba doplnit.
        $groupVat = str_starts_with(\MyInvoice\Support\CompanyIdNormalizer::dic($data['dic']) ?? '', 'SKUPINOV');
        $dic = self::vatId($data['dic']);
        if ($groupVat) {
            $data['note'] .= '; člen skupiny DPH, DIČ skupiny v Money chybí';
        }
        // Země: předpona DIČ z EU, jinak stát z adresy v Money. Money pustí do pole DIČ i
        // rejstříkové nebo daňové číslo mimo EU (FN…, PIB…) — pak zemi nese jen adresa.
        $countryName = trim((string) ($data['country'] ?? ''));
        // Pole státu s číslicemi nese jiný údaj (u fyzické osoby třeba rodné číslo): zemí
        // není a do protokolu se jeho obsah nevypisuje.
        if (preg_match('/\d/', $countryName) === 1) {
            $countryName = '';
        }
        $countryId = $this->countryFromVatId($dic) ?? $this->countryFromName($countryName);
        if ($countryId === null && $countryName !== '' && !$this->isDomesticName($countryName)) {
            $ctx->protocol->warn(CodebookImporter::STEP_PARTNERS, 'country_unknown',
                "Stát „{$countryName}“ partnera {$data['name']} v číselníku zemí není, partner má zemi firmy. Zkontrolujte ho.");
        }
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, ic, dic, street, city, zip, country_id,
                 main_email, phone, currency_default_id, is_customer, is_vendor,
                 is_vat_payer, related_party, related_party_type, note, auto_send_reminders)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?, ?, ?, ?, 0)'
        )->execute([
            $ctx->supplierId,
            mb_substr($data['name'], 0, 190),
            $ico !== '' ? $ico : null,
            $dic !== '' ? mb_substr($dic, 0, 20) : null,
            mb_substr($data['street'] !== '' ? $data['street'] : '-', 0, 190),
            mb_substr($data['city'] !== '' ? $data['city'] : '-', 0, 120),
            mb_substr($data['zip'] !== '' ? str_replace(' ', '', $data['zip']) : '-', 0, 10),
            // Zahraniční partner se zemí firmy by vypadl ze souhrnného hlášení a samovyměření
            // by se bralo jako tuzemské.
            $countryId ?? $defaults['country_id'],
            $data['email'] !== '' ? mb_substr($data['email'], 0, 190) : null,
            $data['phone'] !== '' ? mb_substr($data['phone'], 0, 40) : null,
            $defaults['currency_id'],
            $dic !== '' || $groupVat ? 1 : 0,
            $related ? 1 : 0,
            $related ? 'capital' : null,
            $data['note'],
        ]);
        return (int) $pdo->lastInsertId();
    }

    private ?CountryNameMatcher $countryMatcher = null;

    /** Země partnera podle předpony DIČ (EL = Řecko); tuzemské, chybějící nebo neznámé → null. */
    private function countryFromVatId(string $dic): ?int
    {
        $iso = PartnerCountry::fromVatId($dic);
        return $iso === null || $iso === 'CZ' ? null : $this->countryMatcher()->idOf($iso);
    }

    /** Země podle státu z adresy Money ({@see CountryNameMatcher}); tuzemsko a neznámý stát → null. */
    private function countryFromName(string $name): ?int
    {
        $iso = $this->countryMatcher()->match($name);
        return $iso === null || $iso === 'CZ' ? null : $this->countryMatcher()->idOf($iso);
    }

    private function isDomesticName(string $name): bool
    {
        return $this->countryMatcher()->match($name) === 'CZ';
    }

    private function countryMatcher(): CountryNameMatcher
    {
        return $this->countryMatcher ??= CountryNameMatcher::fromDatabase($this->db);
    }

    /** @return array{currency_id:int,country_id:int} */
    private function defaults(int $supplierId): array
    {
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
        $countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        return ['currency_id' => $currencyId, 'country_id' => $countryId ?: (int) ($row['country_id'] ?? 0)];
    }

    /** IČO v kanonickém tvaru (8 číslic): Money vede tentýž subjekt jednou s vodicí nulou a jednou bez ní. */
    public static function ico(string $ico): string
    {
        return PartnerIdentityMatcher::ico($ico);
    }

    /**
     * DIČ z adresáře Money, jen když má tvar DIČ (kód státu a aspoň jedna číslice).
     * Money do pole ukládá i značku člena skupiny DPH („SKUPINOVE_DPH") nebo jiný text.
     */
    private static function vatId(string $dic): string
    {
        return PartnerIdentityMatcher::vatId($dic);
    }
}
