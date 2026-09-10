<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\MoneyS3ImportRepository;
use PDO;

/**
 * Pokladny a pokladní doklady (`SzUcPokl` typ P, `PoklKnih`), bankovní účty a pohyby
 * (`SzUcPokl` typ U, `BankKnih`).
 *
 * Money výpis jako soubor nemá, jen jednotlivé bankovní doklady. Na účet a rok proto
 * vznikne jeden souhrnný výpis se zdrojem `import` (migrace 1771). Žádná jiná hodnota
 * není pravdivá: `gpc` slibuje nahraný soubor, `pdf` rozparsovaný výpis a `bank_api`
 * má vedlejší účinek — podle něj se hledají účty s aktivním napojením na banku. Cesta
 * „složit GPC a pustit ho přes importér výpisů" je záměrně zavřená: importér
 * rekonstruovaný výpis odmítá, protože výpis v systému má být bankou potvrzený originál.
 */
final class CashBankImporter
{
    public const STEP_CASH = 'cash';
    public const STEP_BANK = 'bank';

    public function __construct(
        private readonly Connection $db,
        private readonly MoneyS3ImportRepository $map,
    ) {}

    public function importCash(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $registers = [];
        foreach ($ctx->backup->rowsAcrossYears('SzUcPokl') as $r) {
            $code = trim((string) ($r['Zkrat'] ?? ''));
            if ($code === '' || strtoupper(trim((string) ($r['UcPokl'] ?? ''))) !== 'P' || isset($registers[$code])) {
                continue;
            }
            $registers[$code] = $this->ensureRegister($ctx, $code, trim((string) ($r['Popis'] ?? '')) ?: $code, (string) ($r['PrimUcet'] ?? ''));
        }

        $existing = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_CASH_DOCUMENT);
        $ruleExists = $pdo->prepare('SELECT 1 FROM posting_rules WHERE supplier_id = ? AND rule_key = ? LIMIT 1');
        $numberTaken = $pdo->prepare('SELECT 1 FROM cash_documents WHERE supplier_id = ? AND doc_number = ? LIMIT 1');
        $insert = $pdo->prepare(
            'INSERT INTO cash_documents
                (supplier_id, register_id, doc_type, purpose, doc_number, issue_date, tax_date,
                 partner_name, partner_ic, partner_dic, description, vat_mode, total_amount, currency_code,
                 rule_key, external_barcode, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "none", ?, "CZK", ?, ?, "posted", ?)'
        );
        foreach ($ctx->backup->rowsAcrossYears('PoklKnih') as $r) {
            $year = $ctx->yearOf($r);
            $docNo = trim((string) ($r['Doklad'] ?? ''));
            if ($year === null || $docNo === '') {
                continue;
            }
            $key = $year . '|' . $docNo;
            if (isset($existing[$key])) {
                $ctx->cashDocuments[$key] = $existing[$key];
                $p->count(self::STEP_CASH, 'existing');
                continue;
            }
            if ($ctx->isLocked($year)) {
                $p->warn(self::STEP_CASH, 'year_locked', "Pokladní doklad {$docNo}: rok {$year} je uzavřený, doklad nepřevzat.");
                continue;
            }
            $registerId = $registers[trim((string) ($r['Pokl'] ?? ''))] ?? null;
            $issue = InvoiceImporter::date($r, ['DatVyst', 'DatUcPr']);
            if ($registerId === null || $issue === null) {
                $p->warn(self::STEP_CASH, 'register_or_date_missing', "Pokladní doklad {$docNo} nemá pokladnu nebo datum, nepřevzat.");
                continue;
            }
            $number = mb_substr($docNo, 0, 30);
            $numberTaken->execute([$ctx->supplierId, $number]);
            if ($numberTaken->fetchColumn() !== false) {
                $number = mb_substr($docNo . '/' . $year, 0, 30);
            }
            $isOut = ((int) ($r['Vydej'] ?? 0)) === 1;
            $rule = mb_substr(trim((string) ($r['PrKont'] ?? '')), 0, 64);
            $ruleKey = null;
            if ($rule !== '') {
                $ruleExists->execute([$ctx->supplierId, $rule]);
                $ruleKey = $ruleExists->fetchColumn() !== false ? $rule : null;
            }
            $insert->execute([
                $ctx->supplierId,
                $registerId,
                $isOut ? 'out' : 'in',
                $isOut ? 'purchase' : 'sale',
                $number,
                $issue,
                InvoiceImporter::date($r, ['DatUplDPH']) ?? $issue,
                mb_substr(trim((string) ($r['AdNazev'] ?? '')), 0, 255) ?: null,
                mb_substr(CodebookImporter::ico((string) ($r['AdICO'] ?? '')), 0, 20) ?: null,
                mb_substr(strtoupper(str_replace(' ', '', trim((string) ($r['AdDIC'] ?? '')))), 0, 20) ?: null,
                mb_substr(trim((string) ($r['Popis'] ?? '')) ?: $docNo, 0, 255),
                round(abs((float) ($r['Celkem'] ?? 0)), 2),
                $ruleKey,
                mb_substr(trim((string) ($r['BarCode'] ?? '')), 0, 64) ?: null,
                $ctx->userId > 0 ? $ctx->userId : null,
            ]);
            $id = (int) $pdo->lastInsertId();
            $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_CASH_DOCUMENT, $key, $id, $ctx->runId);
            $ctx->cashDocuments[$key] = $id;
            $p->count(self::STEP_CASH, 'created');
        }
        $p->finish(self::STEP_CASH);
    }

    public function importBank(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $accounts = [];
        foreach ($ctx->backup->rowsAcrossYears('SzUcPokl') as $r) {
            $code = trim((string) ($r['Zkrat'] ?? ''));
            if ($code !== '' && strtoupper(trim((string) ($r['UcPokl'] ?? ''))) === 'U') {
                $accounts[$code] = [
                    'number' => trim((string) ($r['Ucet'] ?? '')),
                    'bank' => trim((string) ($r['BKod'] ?? '')),
                    'iban' => trim((string) ($r['IBAN'] ?? '')),
                ];
            }
        }
        $this->fillOwnAccount($ctx->supplierId, $accounts);

        $byStatement = [];
        foreach ($ctx->backup->rowsAcrossYears('BankKnih') as $r) {
            $year = $ctx->yearOf($r);
            if ($year === null || trim((string) ($r['Doklad'] ?? '')) === '') {
                continue;
            }
            $code = trim((string) ($r['Ucet'] ?? '')) ?: ((string) (array_key_first($accounts) ?? 'BANKA'));
            $byStatement[$year . '|' . $code][] = $r;
        }

        $existingStatements = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_BANK_STATEMENT);
        $existingTx = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_BANK_TRANSACTION);
        $insertStatement = $pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id, source, file_name, file_hash, account_number, bank_code,
                 currency, statement_number, statement_date, transaction_count, imported_by)
             VALUES (?, "import", ?, ?, ?, ?, "CZK", ?, ?, ?, ?)'
        );
        $insertTx = $pdo->prepare(
            'INSERT INTO bank_transactions
                (source, source_ref, statement_id, posted_at, amount, currency, variable_symbol,
                 counterparty_name, description, bank_ref, import_fingerprint)
             VALUES ("statement", ?, ?, ?, ?, "CZK", ?, ?, ?, ?, ?)'
        );
        $touchStatement = $pdo->prepare(
            'UPDATE bank_statements SET transaction_count = transaction_count + ?, statement_date = GREATEST(statement_date, ?)
              WHERE id = ? AND supplier_id = ?'
        );

        foreach ($byStatement as $statementKey => $rows) {
            [$yearText, $code] = explode('|', $statementKey, 2);
            $year = (int) $yearText;
            if ($ctx->isLocked($year)) {
                $p->info(self::STEP_BANK, 'year_locked', "Bankovní pohyby účtu {$code} za rok {$year}: rok je uzavřený, nepřebírají se.");
                foreach ($rows as $r) {
                    $txKey = $year . '|' . trim((string) $r['Doklad']);
                    if (isset($existingTx[$txKey])) {
                        $ctx->bankTransactions[$txKey] = $existingTx[$txKey];
                    }
                }
                continue;
            }
            $dates = [];
            foreach ($rows as $r) {
                $d = InvoiceImporter::date($r, ['DatUcPr', 'DatPlat']);
                if ($d !== null) {
                    $dates[] = $d;
                }
            }
            sort($dates);
            $lastDate = $dates === [] ? sprintf('%04d-12-31', $year) : $dates[count($dates) - 1];

            $statementId = $existingStatements[$statementKey] ?? null;
            if ($statementId === null) {
                $meta = $accounts[$code] ?? ['number' => '', 'bank' => '', 'iban' => ''];
                $name = sprintf('money-s3-%s-%d.import', preg_replace('/[^A-Za-z0-9_-]/', '_', $code), $year);
                $insertStatement->execute([
                    $ctx->supplierId,
                    $name,
                    hash('sha256', 'money-s3|' . $ctx->supplierId . '|' . $code . '|' . $year),
                    mb_substr($meta['number'] !== '' ? $meta['number'] : $code, 0, 40),
                    $meta['bank'] !== '' ? mb_substr($meta['bank'], 0, 4) : null,
                    mb_substr('MS3/' . $code . '/' . $year, 0, 20),
                    $lastDate,
                    0,
                    $ctx->userId > 0 ? $ctx->userId : null,
                ]);
                $statementId = (int) $pdo->lastInsertId();
                $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_BANK_STATEMENT, $statementKey, $statementId, $ctx->runId);
                $p->count(self::STEP_BANK, 'statements');
            }

            $added = 0;
            foreach ($rows as $r) {
                $docNo = trim((string) $r['Doklad']);
                $txKey = $year . '|' . $docNo;
                if (isset($existingTx[$txKey])) {
                    $ctx->bankTransactions[$txKey] = $existingTx[$txKey];
                    $p->count(self::STEP_BANK, 'existing');
                    continue;
                }
                // Výdej = odchozí platba, na výpisu se znaménkem minus.
                $amount = round(abs((float) ($r['Celkem'] ?? 0)), 2);
                if (((int) ($r['Vydej'] ?? 0)) === 1) {
                    $amount = -$amount;
                }
                $insertTx->execute([
                    mb_substr($docNo, 0, 190),
                    $statementId,
                    InvoiceImporter::date($r, ['DatPlat', 'DatUcPr']) ?? $lastDate,
                    number_format($amount, 2, '.', ''),
                    mb_substr(trim((string) ($r['VarSym'] ?? '')), 0, 20) ?: null,
                    mb_substr(trim((string) ($r['AdNazev'] ?? '')), 0, 190) ?: null,
                    mb_substr(trim((string) ($r['Popis'] ?? '')), 0, 255) ?: null,
                    mb_substr($docNo, 0, 40),
                    hash('sha256', 'money-s3|' . $ctx->supplierId . '|' . $txKey),
                ]);
                $id = (int) $pdo->lastInsertId();
                $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_BANK_TRANSACTION, $txKey, $id, $ctx->runId);
                $ctx->bankTransactions[$txKey] = $id;
                $added++;
                $p->count(self::STEP_BANK, 'transactions');
            }
            if ($added > 0 && isset($existingStatements[$statementKey])) {
                $touchStatement->execute([$added, $lastDate, $statementId, $ctx->supplierId]);
            } elseif ($added > 0) {
                $touchStatement->execute([$added, $lastDate, $statementId, $ctx->supplierId]);
            }
        }
        $p->finish(self::STEP_BANK);
    }

    /**
     * Pokladna na stejném účtu, kterou firma už má, se použije — pokladna je v MyÚčtu
     * vázaná na účet (unikátní na firmu), druhá na týž účet nevznikne.
     */
    private function ensureRegister(ImportContext $ctx, string $code, string $name, string $primaryAccount): int
    {
        $mapped = $this->map->get($ctx->supplierId, MoneyS3ImportRepository::KIND_CASH_REGISTER, $code);
        if ($mapped !== null) {
            return $mapped;
        }
        $pdo = $this->db->pdo();
        $account = AccountCode::fromMoney($primaryAccount);
        $account = $account !== null && isset($ctx->accountIds[$account]) ? $account : null;
        if ($account !== null) {
            $stmt = $pdo->prepare('SELECT id FROM cash_registers WHERE supplier_id = ? AND account_code = ? LIMIT 1');
            $stmt->execute([$ctx->supplierId, $account]);
            $found = $stmt->fetchColumn();
            if ($found !== false) {
                $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_CASH_REGISTER, $code, (int) $found, $ctx->runId);
                return (int) $found;
            }
        }
        $nameTaken = $pdo->prepare('SELECT 1 FROM cash_registers WHERE supplier_id = ? AND name = ? LIMIT 1');
        $nameTaken->execute([$ctx->supplierId, mb_substr($name, 0, 100)]);
        if ($nameTaken->fetchColumn() !== false) {
            $name .= ' (Money S3 ' . $code . ')';
        }
        $pdo->prepare(
            'INSERT INTO cash_registers (supplier_id, name, account_code, currency_code, is_active) VALUES (?, ?, ?, "CZK", 1)'
        )->execute([$ctx->supplierId, mb_substr($name, 0, 100), $account]);
        $id = (int) $pdo->lastInsertId();
        $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_CASH_REGISTER, $code, $id, $ctx->runId);
        $ctx->protocol->count(self::STEP_CASH, 'registers');
        return $id;
    }

    /**
     * Skutečné číslo účtu firmy zná Money. Doplní se na korunový účet firmy jen tehdy,
     * když tam žádné není — vyplněný účet převod nepřepisuje.
     *
     * @param array<string,array{number:string,bank:string,iban:string}> $accounts
     */
    private function fillOwnAccount(int $supplierId, array $accounts): void
    {
        $primary = $accounts !== [] ? reset($accounts) : null;
        if ($primary === null || $primary['number'] === '') {
            return;
        }
        $this->db->pdo()->prepare(
            "UPDATE currencies SET account_number = ?, bank_code = ?, iban = COALESCE(iban, ?)
              WHERE supplier_id = ? AND code = 'CZK' AND (account_number IS NULL OR account_number = '')"
        )->execute([
            mb_substr($primary['number'], 0, 30),
            $primary['bank'] !== '' ? mb_substr($primary['bank'], 0, 4) : null,
            $primary['iban'] !== '' ? mb_substr($primary['iban'], 0, 34) : null,
            $supplierId,
        ]);
    }
}
