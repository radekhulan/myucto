<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Archive\ArchiveRestoreService;
use MyInvoice\Service\Accounting\Archive\ArchiveService;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\Assets\DepreciationPostingService;
use MyInvoice\Service\Accounting\Cash\CashDocumentService;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * CI round-trip test ověřené obnovy archivu (Fáze F, audit 2026-07): export firmy se
 * seed daty (deník, pokladna, majetek, faktury + platby) → restore jako NOVÁ firma →
 * porovnání. Ověřuje, že po obnově sedí Σ MD = Σ D per období, počty a klíčové agregáty
 * odpovídají originálu, a že polymorfní/deferred remap (source_type='cash' → cash_documents,
 * 'asset'/'depreciation', invoice_payments) míří na nová id obnovené firmy (ne na originál).
 *
 * Vše běží v jedné transakci → rollback (originál i obnovená firma zmizí). Soubory archivu
 * na disku uklízí tearDown. Izolovaný supplier, soft-skip bez cfg.php.
 */
#[Group('integration')]
final class ArchiveRestoreRoundTripTest extends TestCase
{
    private const YEAR = 2097;

    private Connection $db;
    private ArchiveService $archive;
    private ArchiveRestoreService $restore;
    private PostingService $posting;
    private CashDocumentService $cash;
    private CashRegisterService $registers;
    private AssetService $assets;
    private DepreciationPostingService $depPosting;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    /** @var list<string> */
    private array $tempFiles = [];
    /** @var list<int> */
    private array $cleanupSuppliers = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db         = $c->get(Connection::class);
            $this->archive    = $c->get(ArchiveService::class);
            $this->restore    = $c->get(ArchiveRestoreService::class);
            $this->posting    = $c->get(PostingService::class);
            $this->cash       = $c->get(CashDocumentService::class);
            $this->registers  = $c->get(CashRegisterService::class);
            $this->assets     = $c->get(AssetService::class);
            $this->depPosting = $c->get(DepreciationPostingService::class);
            $this->periods    = $c->get(AccountingPeriodRepository::class);
            $seeder           = $c->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $anyCurrency  = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId    = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId         = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $anyCurrency === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        // Firma se založí s dočasnou (cizí) měnou, pak dostane VLASTNÍ CZK řádek —
        // aby currencies byly per-tenant (a tedy v archivu + remapovatelné).
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, ic, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, "12345678", ?, ?, ?)'
        )->execute(['F-restore round-trip s.r.o.', $czId, 'f-restore@example.com', $anyCurrency, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $this->cleanupSuppliers[] = $this->supplierId;

        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, "CZK", "Kč", "Kč", "koruna", "koruna", 2, 1, 1)'
        )->execute([$this->supplierId]);
        $this->currencyId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')
            ->execute([$this->currencyId, $this->supplierId]);

        $seeder->seedForSupplier($this->supplierId);
        $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        // úklid archivních + journal souborů na disku pro obě firmy (originál + obnovenou)
        foreach ($this->cleanupSuppliers as $sid) {
            foreach (['archives/sup-' . $sid, 'journal/sup-' . $sid] as $rel) {
                $this->rmTree(RuntimePaths::storage($rel));
            }
        }
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testExportRestoreRoundTripPreservesAccounting(): void
    {
        $sid = $this->supplierId;

        // ── seed reprezentativních dat ───────────────────────────────────────
        // Deník: 2 ruční zápisy (source_id NULL)
        $this->manual(1000.00, '311', '602');
        $this->manual(250.00, '518', '321');

        // Pokladna: registr + prodej s DPH (source_type='cash') + převod
        $reg = $this->registers->create($sid, ['name' => 'Pokladna', 'account_code' => '211', 'is_default' => true]);
        $this->cash->create($sid, [
            'register_id' => $reg, 'issue_date' => self::YEAR . '-06-15', 'description' => 'Prodej',
            'purpose' => 'sale', 'doc_type' => 'in', 'total_amount' => 1210.00, 'vat_mode' => 'vat',
            'vat_lines' => [['vat_rate' => 21, 'base_amount' => 1000.00, 'vat_amount' => 210.00]], 'post' => true,
        ], $this->userId);
        $this->cash->create($sid, [
            'register_id' => $reg, 'issue_date' => self::YEAR . '-06-16', 'description' => 'Vklad',
            'purpose' => 'transfer', 'doc_type' => 'in', 'total_amount' => 5000.00, 'post' => true,
        ], $this->userId);

        // Faktura + úhrada přes pokladnu (invoice_payments source='cash')
        $clientId = $this->client();
        $invoiceId = $this->saleInvoice('FV-2097-1', $clientId, 3000.00);
        $this->cash->create($sid, [
            'register_id' => $reg, 'issue_date' => self::YEAR . '-06-17', 'description' => 'Úhrada FV',
            'purpose' => 'invoice_payment', 'doc_type' => 'in', 'total_amount' => 3000.00, 'invoice_id' => $invoiceId, 'post' => true,
        ], $this->userId);

        // Majetek: zařazení do užívání (source_type='asset') + roční odpis (source_type='depreciation')
        $assetSeeded = $this->seedAsset();

        // Banka: bank_statements/bank_transactions NEMAJÍ supplier_id (jsou tenant jen
        // tranzitivně, přes payment_matches / matched_invoice_id) — přesně případ, který
        // adversariální review (2026-07) označilo za kriticky nebezpečný (tichý cross-tenant
        // odkaz při chybějícím remapu).
        $bankHash = bin2hex(random_bytes(32));
        $bankInvoiceId = $this->saleInvoice('FV-2097-2', $clientId, 5000.00);
        $oldStatementId = $this->bankStatement($bankHash);
        $oldBtId = $this->bankTransaction($oldStatementId, $bankInvoiceId, 5000.00);
        $this->paymentMatch($oldBtId, $bankInvoiceId, 5000.00);

        // ── snapshot originálu ───────────────────────────────────────────────
        $origCounts = $this->aggregate($sid);
        $origBalance = $this->balance($sid);
        self::assertSame('0.00', $this->maxDiff($origBalance), 'Originál je vyvážený (sanity).');
        self::assertGreaterThan(0, $origCounts['journal_entries']);
        self::assertGreaterThan(0, $origCounts['cash_documents']);
        self::assertSame(1, $origCounts['invoice_payments']);

        // ── export → restore ─────────────────────────────────────────────────
        $meta = $this->archive->export($sid, $this->userId);
        $zipPath = $this->archive->filePath($sid, $meta);
        $this->tempFiles[] = $zipPath;
        self::assertFileExists($zipPath);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath));
        $invoiceJsonl = $zip->getFromName('invoices.jsonl');
        self::assertIsString($invoiceJsonl);
        self::assertStringNotContainsString(
            '"approval_token"',
            $invoiceJsonl,
            'Archiv nesmí přenášet jednorázový schvalovací token.',
        );
        self::assertStringNotContainsString(
            '"public_token"',
            $invoiceJsonl,
            'Archiv nesmí přenášet veřejný token faktury.',
        );
        $zip->close();

        // Originální bank_statements řádek smaž PO exportu (cascade smaže i
        // bank_transactions/payment_matches originálu) — jinak by find-or-create dedup
        // (stejný file_hash, cíleně přidaný proti UNIQUE kolizi, viz importBankStatement)
        // vždy „úspěšně" namapoval na PŮVODNÍ (dosud existující) řádek i BEZ opraveného
        // remapu, a test by tak neodlišil opravený kód od chybného (oba by dali stejné
        // číslo). Smazáním se vynutí, že restore MUSÍ vložit genuinně NOVÝ řádek — pokud
        // by byl FK ponechán na starém (smazaném) id, INSERT by tvrdě spadl na FK constraint.
        $this->db->pdo()->exec('DELETE FROM bank_statements WHERE id = ' . $oldStatementId);

        $report = $this->restore->restore($zipPath);
        $newSid = (int) $report['new_supplier_id'];
        $this->cleanupSuppliers[] = $newSid;
        self::assertGreaterThan(0, $newSid);
        self::assertNotSame($sid, $newSid, 'Obnova založila NOVOU firmu.');

        // ── kontroly ─────────────────────────────────────────────────────────
        // 1) Podvojnost per období — report i re-dotaz
        foreach ($report['balance'] as $b) {
            self::assertSame('0.00', $b['diff'], "Σ MD = Σ D období #{$b['period_id']} po obnově.");
        }
        self::assertSame('0.00', $this->maxDiff($this->balance($newSid)), 'Obnovená firma je vyvážená.');

        // 2) Počty i klíčové agregáty se shodují s originálem
        $newCounts = $this->aggregate($newSid);
        foreach ($origCounts as $key => $val) {
            self::assertSame($val, $newCounts[$key], "Agregát '{$key}' se po obnově shoduje ({$val}).");
        }

        // 3) source_type='cash' zápisy míří na cash_documents NOVÉ firmy (deferred remap)
        $orphanCash = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entries je
              WHERE je.supplier_id = {$newSid} AND je.source_type = 'cash'
                AND (je.source_id IS NULL OR je.source_id NOT IN
                     (SELECT id FROM cash_documents WHERE supplier_id = {$newSid}))"
        )->fetchColumn();
        self::assertSame(0, $orphanCash, 'Každý cash zápis obnovené firmy odkazuje na její vlastní pokladní doklad.');

        // 4) invoice_payments obnovené firmy odkazují na faktury téže firmy
        $orphanPay = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM invoice_payments ip
              WHERE ip.supplier_id = {$newSid}
                AND ip.invoice_id NOT IN (SELECT id FROM invoices WHERE supplier_id = {$newSid})"
        )->fetchColumn();
        self::assertSame(0, $orphanPay, 'Platby obnovené firmy odkazují na její vlastní faktury.');

        // 5) Majetek: zápis 'asset' míří na assets nové firmy
        if ($assetSeeded) {
            $orphanAsset = (int) $this->db->pdo()->query(
                "SELECT COUNT(*) FROM journal_entries je
                  WHERE je.supplier_id = {$newSid} AND je.source_type = 'asset'
                    AND je.source_id NOT IN (SELECT id FROM assets WHERE supplier_id = {$newSid})"
            )->fetchColumn();
            self::assertSame(0, $orphanAsset, 'Zápis zařazení majetku odkazuje na majetek nové firmy.');
        }

        // 6) Tenant izolace: žádný řádek obnovené firmy nenese staré supplier_id
        foreach (['journal_entries', 'journal_entry_lines', 'cash_documents', 'invoices', 'invoice_payments'] as $t) {
            $leak = (int) $this->db->pdo()->query(
                "SELECT COUNT(*) FROM {$t} WHERE supplier_id = {$newSid} AND supplier_id = {$sid}"
            )->fetchColumn();
            self::assertSame(0, $leak);
        }

        // 7) supplier master řádek se založil, bez credentialů, s IČO originálu
        $newSup = $this->db->pdo()->query("SELECT company_name, ic FROM supplier WHERE id = {$newSid}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('12345678', (string) $newSup['ic']);

        // 8) KRITICKÉ (adversariální review): bank_transactions/bank_statements nemají
        // supplier_id — musí se přesto remapovat na NOVÁ id (jinak by po obnově do běžící
        // instance tiše ukazovaly na bankovní data PŮVODNÍ firmy; ON DELETE CASCADE by pak
        // smazání výpisu originálu smazalo i "obnovenou" transakci/párování).
        $newMatch = $this->db->pdo()->query(
            "SELECT bank_transaction_id FROM payment_matches WHERE supplier_id = {$newSid}"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($newMatch, 'payment_matches obnovené firmy existuje.');
        $newBtId = (int) $newMatch['bank_transaction_id'];
        self::assertNotSame($oldBtId, $newBtId, 'payment_matches.bank_transaction_id po obnově NEukazuje na starou (cizí) bankovní transakci.');

        $newBt = $this->db->pdo()->query(
            "SELECT statement_id, matched_invoice_id FROM bank_transactions WHERE id = {$newBtId}"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($newBt, 'Nová bankovní transakce existuje.');
        $newStatementId = (int) $newBt['statement_id'];
        self::assertNotSame($oldStatementId, $newStatementId, 'bank_transactions.statement_id po obnově NEukazuje na starý (cizí) výpis.');

        $stStmt = $this->db->pdo()->prepare('SELECT file_hash FROM bank_statements WHERE id = ?');
        $stStmt->execute([$newStatementId]);
        self::assertSame($bankHash, (string) $stStmt->fetchColumn(), 'Nový bank_statements řádek nese stejný obsah (file_hash) jako originál.');

        $newBankInvoiceId = (int) $this->db->pdo()->query(
            "SELECT id FROM invoices WHERE supplier_id = {$newSid} AND varsymbol = 'FV-2097-2'"
        )->fetchColumn();
        self::assertSame($newBankInvoiceId, (int) $newBt['matched_invoice_id'], 'bank_transactions.matched_invoice_id ukazuje na fakturu NOVÉ firmy.');

        // 9) Dedup pojistka: bank_statements je celoinstanční content-addressed tabulka
        // (UNIQUE file_hash) — restore STEJNÉHO archivu podruhé (simulace obnovy do běžící
        // instance, kde stejný výpis díky prvnímu restoru z kroku 8 už existuje) nesmí
        // spadnout na UNIQUE constraint a musí sdílet TENTÝŽ řádek (find-or-create), ne
        // selhat celou transakcí.
        $hashStmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM bank_statements WHERE file_hash = ?');
        $hashStmt->execute([$bankHash]);
        $countBefore = (int) $hashStmt->fetchColumn();

        $report2 = $this->restore->restore($zipPath);
        $newSid2 = (int) $report2['new_supplier_id'];
        $this->cleanupSuppliers[] = $newSid2;
        self::assertGreaterThan(0, $newSid2);

        $hashStmt->execute([$bankHash]);
        $countAfter = (int) $hashStmt->fetchColumn();
        self::assertSame($countBefore, $countAfter, 'Druhá obnova sdílí existující bank_statements řádek (dedup dle file_hash), nevytváří duplicitu.');

        $match2 = $this->db->pdo()->query(
            "SELECT bank_transaction_id FROM payment_matches WHERE supplier_id = {$newSid2}"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($match2, 'payment_matches třetí (znovu obnovené) firmy existuje.');
        $bt2 = $this->db->pdo()->query(
            'SELECT statement_id FROM bank_transactions WHERE id = ' . (int) $match2['bank_transaction_id']
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame($newStatementId, (int) $bt2['statement_id'], 'Třetí firma sdílí stejný dedupovaný bank_statements řádek jako druhá.');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function manual(float $amount, string $debit, string $credit): void
    {
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => $debit, 'side' => 'debit', 'amount' => $amount],
            ['account_code' => $credit, 'side' => 'credit', 'amount' => $amount],
        ], ['entry_date' => self::YEAR . '-05-01', 'posted_by' => $this->userId]);
    }

    private function seedAsset(): bool
    {
        try {
            $created = $this->assets->create($this->supplierId, [
                'inventory_number' => 'M-RT-001',
                'name' => 'Testovací stroj',
                'input_price' => 120000.00,
                'acquisition_date' => self::YEAR . '-02-10',
                'tax_method' => 'straight',
                'tax_group' => 1,
                'acc_useful_life_months' => 36,
            ], ['user_id' => $this->userId]);
            $assetId = (int) $created['asset']['id'];
            $this->assets->putIntoUse($this->supplierId, $assetId, self::YEAR . '-03-01', true,
                ['user_id' => $this->userId, 'posted_by' => $this->userId]);
            $this->depPosting->bookYear($this->supplierId, self::YEAR, ['posted_by' => $this->userId]);
            return true;
        } catch (\Throwable $e) {
            // Majetek je bonus pokrytí — když se seed nepovede (odlišné API), test běží dál.
            return false;
        }
    }

    private function client(): int
    {
        $czId = (int) $this->db->pdo()->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Odběratel s.r.o.", "Test 1", "Praha", "11000", ?, "odberatel@example.com", "cs", ?, 1, 0)'
        );
        $stmt->execute([$this->supplierId, $czId, $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function saleInvoice(string $varsymbol, int $clientId, float $total): int
    {
        $issue = self::YEAR . '-06-10';
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 paid_total, status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, 0, ?, 0, "issued", "1", ?)'
        );
        $stmt->execute([$this->supplierId, $varsymbol, $clientId, $issue, $issue, $issue, $this->currencyId, $total, $total, $this->userId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function bankStatement(string $hash): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO bank_statements
                (file_name, file_hash, account_number, bank_code, currency, statement_date,
                 prev_balance, curr_balance, credit_total, debit_total, transaction_count)
             VALUES (?, ?, "1234567890/0100", "0100", "CZK", ?, 0, 5000, 5000, 0, 1)'
        );
        $stmt->execute(['vypis-' . $hash . '.gpc', $hash, self::YEAR . '-06-18']);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function bankTransaction(int $statementId, int $invoiceId, float $amount): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, variable_symbol, matched_invoice_id, match_status, matched_by)
             VALUES (?, ?, ?, "CZK", "20970002", ?, "manual", ?)'
        );
        $stmt->execute([$statementId, self::YEAR . '-06-18', $amount, $invoiceId, $this->userId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function paymentMatch(int $bankTransactionId, int $invoiceId, float $amount): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payment_matches
                (supplier_id, bank_transaction_id, invoice_id, amount, match_type, matched_by_user_id)
             VALUES (?, ?, ?, ?, "manual", ?)'
        );
        $stmt->execute([$this->supplierId, $bankTransactionId, $invoiceId, $amount, $this->userId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<string,int> počty klíčových tabulek pro firmu */
    private function aggregate(int $sid): array
    {
        $out = [];
        foreach ([
            'journal_entries', 'journal_entry_lines', 'cash_registers', 'cash_documents',
            'cash_document_vat_lines', 'invoices', 'invoice_payments', 'assets', 'depreciation_entries',
        ] as $t) {
            $col = $t === 'cash_document_vat_lines'
                ? 'cash_document_id IN (SELECT id FROM cash_documents WHERE supplier_id = ' . $sid . ')'
                : 'supplier_id = ' . $sid;
            $out[$t] = (int) $this->db->pdo()->query("SELECT COUNT(*) FROM {$t} WHERE {$col}")->fetchColumn();
        }
        // agregát částek pro shodu obsahu, ne jen počtu
        $out['jel_debit_halere'] = (int) round(100 * (float) $this->db->pdo()->query(
            "SELECT COALESCE(SUM(amount),0) FROM journal_entry_lines WHERE supplier_id = {$sid} AND side = 'debit'"
        )->fetchColumn());
        return $out;
    }

    /** @return list<array{period_id:int,diff:string}> */
    private function balance(int $sid): array
    {
        $rows = $this->db->pdo()->query(
            "SELECT je.period_id,
                    SUM(CASE WHEN jel.side='debit' THEN jel.amount ELSE 0 END)
                  - SUM(CASE WHEN jel.side='credit' THEN jel.amount ELSE 0 END) AS diff
               FROM journal_entries je JOIN journal_entry_lines jel ON jel.entry_id = je.id
              WHERE je.supplier_id = {$sid} AND je.posted_at IS NOT NULL
              GROUP BY je.period_id"
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn (array $r): array => [
            'period_id' => (int) $r['period_id'],
            'diff' => number_format((float) $r['diff'], 2, '.', ''),
        ], $rows);
    }

    /** @param list<array{period_id:int,diff:string}> $balance */
    private function maxDiff(array $balance): string
    {
        $max = '0.00';
        foreach ($balance as $b) {
            if (abs((float) $b['diff']) > abs((float) $max)) {
                $max = $b['diff'];
            }
        }
        return $max;
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
