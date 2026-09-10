<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientBankAccountRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use PDO;

/**
 * Adresář partnerů (`AdresarF`), jejich bankovní spojení (`AdUcBan`) a předkontace
 * (`UcPrKont`).
 *
 * Partner se stejným IČO, který už ve firmě je, se použije — převod nezakládá druhého.
 * Vlastní firma (Money ji má v adresáři jako záznam č. 1) se přeskakuje.
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
                if ($ico !== '' && isset($ctx->clientsByIco[$ico])) {
                    $clientId = $ctx->clientsByIco[$ico];
                    $p->count(self::STEP_PARTNERS, 'matched');
                } else {
                    $clientId = $this->insertClient($ctx, [
                        'name' => $name,
                        'ico' => $ico,
                        'dic' => trim((string) ($r['DIC'] ?? '')),
                        'street' => trim((string) ($r['Ulice'] ?? '')),
                        'city' => trim((string) ($r['Misto'] ?? '')),
                        'zip' => trim((string) ($r['PSC'] ?? '')),
                        'email' => trim((string) ($r['EMail'] ?? '')),
                        'phone' => trim((string) ($r['TelCislo'] ?? '')),
                        'note' => 'Převzato z Money S3 (adresa č. ' . $no . ')',
                    ], $defaults);
                    $p->count(self::STEP_PARTNERS, 'created');
                }
                $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_CLIENT, $key, $clientId, $ctx->runId);
                $ctx->clientsByMoneyNo[$no] = $clientId;
                if ($ico !== '') {
                    $ctx->clientsByIco[$ico] = $clientId;
                }
            }
        }

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
     * @param array{name:string,ico:string,dic:string,street:string,city:string,zip:string} $snapshot
     */
    public function resolvePartner(ImportContext $ctx, array $snapshot): int
    {
        $ico = self::ico($snapshot['ico']);
        if ($ico !== '' && isset($ctx->clientsByIco[$ico])) {
            return $ctx->clientsByIco[$ico];
        }
        $name = trim($snapshot['name']) !== '' ? trim($snapshot['name']) : 'Neznámý partner z Money S3';
        $key = $ico !== '' ? 'ico:' . $ico : 'name:' . mb_strtolower($name);
        $mapped = $this->map->get($ctx->supplierId, MoneyS3ImportRepository::KIND_CLIENT, $key);
        if ($mapped !== null) {
            return $mapped;
        }
        if ($ico === '') {
            $stmt = $this->db->pdo()->prepare(
                'SELECT id FROM clients WHERE supplier_id = ? AND company_name = ? AND archived_at IS NULL ORDER BY id LIMIT 1'
            );
            $stmt->execute([$ctx->supplierId, $name]);
            $found = $stmt->fetchColumn();
            if ($found !== false) {
                $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_CLIENT, $key, (int) $found, $ctx->runId);
                return (int) $found;
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
             VALUES (?, ?, ?, ?, ?, 100, 1)'
        );
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
            ]);
            $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_POSTING_RULE, $key, (int) $pdo->lastInsertId(), $ctx->runId);
            $p->count(self::STEP_POSTING_RULES, 'created');
        }
        $p->finish(self::STEP_POSTING_RULES);
    }

    /** Účet předkontace jen tehdy, když je v osnově — `xxxxxx` (nedosazeno) a neznámý kód jsou NULL. */
    private function knownAccount(ImportContext $ctx, string $moneyCode): ?string
    {
        $code = AccountCode::fromMoney($moneyCode);
        return $code !== null && isset($ctx->accountIds[$code]) ? $code : null;
    }

    private function loadClientIndex(ImportContext $ctx): void
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, ic FROM clients WHERE supplier_id = ? AND ic IS NOT NULL AND ic <> '' AND archived_at IS NULL ORDER BY id"
        );
        $stmt->execute([$ctx->supplierId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ico = self::ico((string) $row['ic']);
            $ctx->clientsByIco[$ico] ??= (int) $row['id'];
        }
    }

    /**
     * @param array{name:string,ico:string,dic:string,street:string,city:string,zip:string,email:string,phone:string,note:string} $data
     * @param array{currency_id:int,country_id:int} $defaults
     */
    private function insertClient(ImportContext $ctx, array $data, array $defaults): int
    {
        $ico = self::ico($data['ico']);
        $related = $ico !== '' && in_array($ico, array_map(self::ico(...), $ctx->options->relatedPartyIcos), true);
        $dic = strtoupper(str_replace(' ', '', $data['dic']));
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, ic, dic, street, city, zip, country_id,
                 main_email, phone, currency_default_id, is_customer, is_vendor,
                 is_vat_payer, related_party, related_party_type, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?, ?, ?, ?)'
        )->execute([
            $ctx->supplierId,
            mb_substr($data['name'], 0, 190),
            $ico !== '' ? $ico : null,
            $dic !== '' ? mb_substr($dic, 0, 20) : null,
            mb_substr($data['street'] !== '' ? $data['street'] : '-', 0, 190),
            mb_substr($data['city'] !== '' ? $data['city'] : '-', 0, 120),
            mb_substr($data['zip'] !== '' ? str_replace(' ', '', $data['zip']) : '-', 0, 10),
            $defaults['country_id'],
            $data['email'] !== '' ? mb_substr($data['email'], 0, 190) : null,
            $data['phone'] !== '' ? mb_substr($data['phone'], 0, 40) : null,
            $defaults['currency_id'],
            $dic !== '' ? 1 : 0,
            $related ? 1 : 0,
            $related ? 'capital' : null,
            $data['note'],
        ]);
        return (int) $pdo->lastInsertId();
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

    public static function ico(string $ico): string
    {
        return (string) preg_replace('/\D/', '', $ico);
    }
}
