<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaForeignBankRepair;
use MyInvoice\Service\Migration\Pohoda\PohodaImporter;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaExport;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaMdbExport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Bankovní účet POHODY v cizí měně: převod ho zakládal celý v Kč (výpisy, pohyby, párování,
 * deník 221 bez měny), takže zůstatek účtu v EUR neexistoval a přecenění k rozvahovému dni
 * ho nenabízelo. Účet, výpisy a pohyby jsou v měně účtu s částkou z `foreignCurrency`,
 * párování úhrad v měně pohybu, deník analytiky i otevírací zápis nesou měnu, kurz a částku
 * v měně; korunový deník se nemění. Dál opravný průchod pro firmy převedené dřív v Kč
 * ({@see PohodaForeignBankRepair}). Izolovaná firma, rollback v tearDown.
 */
#[Group('integration')]
final class PohodaForeignBankImportTest extends TestCase
{
    private Connection $db;
    private PohodaImporter $importer;
    private string $tmp = '';
    private int $userId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $czId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->importer = $container->get(PohodaImporter::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_fx_bank_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
        $pdo->beginTransaction();
        $this->inTx = true;
        // Kurzy ČNB syntetických dnů (v transakci testu, rollback je vrátí).
        $rate = $pdo->prepare("INSERT INTO exchange_rates (rate_date, currency_code, rate) VALUES (?, 'EUR', ?) ON DUPLICATE KEY UPDATE rate = VALUES(rate)");
        foreach (['2025-12-31' => 24.245, '2026-02-10' => 24.20, '2026-02-27' => 24.25, '2026-03-31' => 24.515] as $day => $value) {
            $rate->execute([$day, $value]);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    /**
     * XML: měnu účtu číselník nenese, určí ji doklady účtu. Před opravou byly výpisy, pohyby
     * i párování v Kč (24 245 + 12 100 − 48,50 + 150 Kč) a deník 221002 bez měny.
     */
    public function testXmlForeignAccountIsImportedInAccountCurrency(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withEurBankAccount($dir);
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $this->assertForeignAccount($supplierId, [SyntheticPohodaExport::EUR_RECEIPT => '500.00', SyntheticPohodaExport::EUR_FEE => '-2.00', SyntheticPohodaExport::EUR_FX_DIFFERENCE => '0.00'], '1498.00');
        // Korunový účet zůstává v Kč.
        self::assertSame(['CZK'], $this->statementCurrencies($supplierId, SyntheticPohodaExport::BANK_ACCOUNT));
        $this->assertJournalForeign($supplierId, [
            [SyntheticPohodaExport::EUR_RECEIPT, 'debit', '12100.00', 'EUR', '24.200000', '500.00'],
            [SyntheticPohodaExport::EUR_FEE, 'credit', '48.50', 'EUR', '24.250000', '2.00'],
            [SyntheticPohodaExport::EUR_FX_DIFFERENCE, 'debit', '150.00', null, null, null],
            [null, 'debit', '24245.00', 'EUR', '24.245000', '1000.00'],
        ]);
        // Úhrada korunové faktury z účtu v EUR: párování v měně pohybu, platba faktury v Kč.
        self::assertSame(['500.00'], $this->matchAmounts($supplierId, SyntheticPohodaExport::EUR_RECEIPT));
        $payment = $this->db->pdo()->prepare("SELECT ip.amount, ip.currency FROM invoice_payments ip JOIN invoices i ON i.id = ip.invoice_id
            WHERE i.supplier_id = ? AND i.varsymbol = ?");
        $payment->execute([$supplierId, SyntheticPohodaExport::EUR_INVOICE]);
        self::assertSame([['amount' => '12100.00', 'currency' => 'CZK']], $payment->fetchAll(\PDO::FETCH_ASSOC));
        $this->assertEurRegistration($supplierId);
        $this->assertBankMatchesJournal($protocol);

        // Opakovaný převod nic nezdvojí ani nepřepočte podruhé.
        $again = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        $this->assertForeignAccount($supplierId, [SyntheticPohodaExport::EUR_RECEIPT => '500.00', SyntheticPohodaExport::EUR_FEE => '-2.00', SyntheticPohodaExport::EUR_FX_DIFFERENCE => '0.00'], '1498.00');
        self::assertSame(['500.00'], $this->matchAmounts($supplierId, SyntheticPohodaExport::EUR_RECEIPT));
    }

    /**
     * Datový soubor: měna účtu z číselníku (`sUcet.RefCM`). Doklad bez částky v měně se
     * přepočte uloženým kurzem ČNB, počáteční stav v měně z deníku kurzem k 31. 12.
     */
    public function testMdbAccountCurrencyComesFromChartOfAccounts(): void
    {
        $supplierId = $this->supplier();
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open(SyntheticPohodaMdbExport::writeEurBankAccount($this->tmp)), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $this->assertForeignAccount($supplierId, ['BANE0001' => '500.00', 'BANE0002' => '-2.00'], '1498.00');
        $this->assertJournalForeign($supplierId, [
            ['BANE0001', 'debit', '12100.00', 'EUR', '24.200000', '500.00'],
            ['BANE0002', 'credit', '48.50', 'EUR', '24.250000', '2.00'],
            [null, 'debit', '24245.00', 'EUR', '24.245000', '1000.00'],
        ]);
        $this->assertEurRegistration($supplierId);
    }

    /**
     * Firma převedená dřív, kdy převod účet v EUR založil v Kč: opravný průchod pozná účet
     * z dat (zkratka BANE a pohyby přepočtené kurzem ČNB), převede výpisy, pohyby, párování,
     * deník a evidenci účtu, korunový deník nezmění a opakované spuštění nic nedělá.
     */
    public function testRepairConvertsAccountImportedInCzk(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withEurBankAccount($dir);
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $ledger = $this->ledger($supplierId);
        $this->legacyCzk($supplierId);
        self::assertSame(['CZK'], $this->statementCurrencies($supplierId, SyntheticPohodaExport::EUR_ACCOUNT_NUMBER));

        $repair = Bootstrap::buildContainer()->get(PohodaForeignBankRepair::class);
        $dry = $this->byCode($repair->run([$supplierId], false, false));
        self::assertSame('convert', $dry[SyntheticPohodaExport::EUR_ACCOUNT]['status'], json_encode($dry, JSON_UNESCAPED_UNICODE));
        self::assertSame('EUR', $dry[SyntheticPohodaExport::EUR_ACCOUNT]['currency']);
        self::assertSame(100.0, $dry[SyntheticPohodaExport::EUR_ACCOUNT]['exact_pct']);
        self::assertSame(1, $dry[SyntheticPohodaExport::EUR_ACCOUNT]['fx_differences']);
        self::assertSame(149800, $dry[SyntheticPohodaExport::EUR_ACCOUNT]['chains'][0]['closing_foreign']);
        self::assertSame('czk', $dry['BAN']['status']);
        self::assertSame(['CZK'], $this->statementCurrencies($supplierId, SyntheticPohodaExport::EUR_ACCOUNT_NUMBER), 'nanečisto nic nemění');

        $done = $this->byCode($repair->run([$supplierId], true, false));
        self::assertSame('converted', $done[SyntheticPohodaExport::EUR_ACCOUNT]['status'], json_encode($done, JSON_UNESCAPED_UNICODE));
        $this->assertForeignAccount($supplierId, [SyntheticPohodaExport::EUR_RECEIPT => '500.00', SyntheticPohodaExport::EUR_FEE => '-2.00', SyntheticPohodaExport::EUR_FX_DIFFERENCE => '0.00'], '1498.00');
        $this->assertJournalForeign($supplierId, [
            [SyntheticPohodaExport::EUR_RECEIPT, 'debit', '12100.00', 'EUR', '24.200000', '500.00'],
            [SyntheticPohodaExport::EUR_FEE, 'credit', '48.50', 'EUR', '24.250000', '2.00'],
            [SyntheticPohodaExport::EUR_FX_DIFFERENCE, 'debit', '150.00', null, null, null],
            [null, 'debit', '24245.00', 'EUR', '24.245000', '1000.00'],
        ]);
        self::assertSame(['500.00'], $this->matchAmounts($supplierId, SyntheticPohodaExport::EUR_RECEIPT));
        $this->assertEurRegistration($supplierId);
        self::assertSame($ledger, $this->ledger($supplierId), 'korunový deník se nemění');
        $log = $this->db->pdo()->prepare('SELECT COUNT(*) FROM activity_log WHERE supplier_id = ? AND action = ?');
        $log->execute([$supplierId, PohodaForeignBankRepair::AUDIT_ACTION]);
        self::assertSame(1, (int) $log->fetchColumn());

        $again = $this->byCode($repair->run([$supplierId], true, false));
        self::assertSame('already_foreign', $again[SyntheticPohodaExport::EUR_ACCOUNT]['status']);
        self::assertSame($ledger, $this->ledger($supplierId));
    }

    /**
     * Výpis GPC účtu v EUR nahraný po převodu, dokud byl účet v Kč: zařadil se pod korunový
     * účet a EUR částky se tvářily jako Kč. Nanečisto ho oprava vypíše s měnou podle názvu
     * souboru a s postupem, `--include-uploaded` ho s převedeným účtem přepne na EUR (částky
     * výpisu banky už v EUR jsou). Výpis se spárovaným pohybem jen vypíše, opakovaný běh nic
     * nemění.
     */
    public function testRepairListsAndSwitchesUploadedStatementOfForeignAccount(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withEurBankAccount($dir);
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $this->legacyCzk($supplierId);
        $clean = $this->uploadedStatement($supplierId, 'Vypis_' . SyntheticPohodaExport::EUR_ACCOUNT_NUMBER . '_EUR_2026_004.gpc', '2026-04-30', 1498.00, 100.00, 'unmatched');
        $matched = $this->uploadedStatement($supplierId, 'Vypis_' . SyntheticPohodaExport::EUR_ACCOUNT_NUMBER . '_EUR_2026_005.gpc', '2026-05-31', 1598.00, 50.00, 'manual');

        $repair = Bootstrap::buildContainer()->get(PohodaForeignBankRepair::class);
        $dry = $this->byCode($repair->run([$supplierId], false, false, null, true));
        $uploaded = array_column($dry[SyntheticPohodaExport::EUR_ACCOUNT]['uploaded'], null, 'id');
        self::assertSame(['CZK', 'EUR', 'switch'], [$uploaded[$clean]['currency'], $uploaded[$clean]['expected'], $uploaded[$clean]['status']],
            json_encode($uploaded, JSON_UNESCAPED_UNICODE));
        self::assertSame(0, $uploaded[$clean]['diff'], 'navazuje na konečný stav převedeného řetězu 1 498 EUR');
        self::assertSame(['wrong_currency', 1], [$uploaded[$matched]['status'], $uploaded[$matched]['blockers']]);
        self::assertStringContainsString('smažte', (string) $uploaded[$matched]['advice']);

        // Bez volby se nahrané výpisy jen vypíší.
        $this->byCode($repair->run([$supplierId], true, false));
        self::assertSame(['CZK', 'CZK'], [$this->statementCurrency($clean), $this->statementCurrency($matched)]);

        $done = $this->byCode($repair->run([$supplierId], true, false, null, true));
        $uploaded = array_column($done[SyntheticPohodaExport::EUR_ACCOUNT]['uploaded'], null, 'id');
        self::assertSame('switched', $uploaded[$clean]['status'], json_encode($uploaded, JSON_UNESCAPED_UNICODE));
        self::assertSame(['EUR', 'CZK'], [$this->statementCurrency($clean), $this->statementCurrency($matched)]);
        $tx = $this->db->pdo()->prepare('SELECT amount, currency FROM bank_transactions WHERE statement_id = ?');
        $tx->execute([$clean]);
        self::assertSame([['amount' => '100.00', 'currency' => 'EUR']], $tx->fetchAll(\PDO::FETCH_ASSOC), 'částka se nepřepočítává');

        $again = $this->byCode($repair->run([$supplierId], true, false, null, true));
        $uploaded = array_column($again[SyntheticPohodaExport::EUR_ACCOUNT]['uploaded'], null, 'id');
        self::assertSame(['ok', 'wrong_currency'], [$uploaded[$clean]['status'], $uploaded[$matched]['status']]);
        $log = $this->db->pdo()->prepare("SELECT COUNT(*) FROM activity_log WHERE supplier_id = ? AND action = ? AND entity_id = ?");
        $log->execute([$supplierId, PohodaForeignBankRepair::AUDIT_ACTION, $clean]);
        self::assertSame(1, (int) $log->fetchColumn());
    }

    /**
     * Účet, jehož pohyby kurzu ČNB neodpovídají (jiný kurz než ČNB), opravný průchod
     * nepřevede a vypíše důvod.
     */
    public function testRepairLeavesAccountWithoutRateMatch(): void
    {
        $supplierId = $this->supplier();
        $dir = SyntheticPohodaExport::write($this->tmp);
        SyntheticPohodaExport::withEurBankAccount($dir);
        $protocol = $this->importer->run($supplierId, $this->userId, PohodaExport::open($dir), false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $this->legacyCzk($supplierId);
        // Kurz banky, ne ČNB: přepočet Kč pohybů nesedí.
        $this->db->pdo()->exec("UPDATE exchange_rates SET rate = 24.731 WHERE currency_code = 'EUR' AND rate_date IN ('2026-02-10', '2026-02-27')");

        $done = $this->byCode(Bootstrap::buildContainer()->get(PohodaForeignBankRepair::class)->run([$supplierId], true, false));
        self::assertContains($done[SyntheticPohodaExport::EUR_ACCOUNT]['status'], ['czk', 'ambiguous'], json_encode($done, JSON_UNESCAPED_UNICODE));
        self::assertNotNull($done[SyntheticPohodaExport::EUR_ACCOUNT]['reason']);
        self::assertNotNull($done[SyntheticPohodaExport::EUR_ACCOUNT]['advice'], 'nevyjasněný účet nese doporučený postup');
        self::assertSame(['CZK'], $this->statementCurrencies($supplierId, SyntheticPohodaExport::EUR_ACCOUNT_NUMBER));
    }

    /** Výpis nahraný po převodu pod korunovým účtem s částkami v měně (stavy v EUR, jak je dává banka). */
    private function uploadedStatement(int $supplierId, string $fileName, string $date, float $opening, float $amount, string $matchStatus): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO bank_statements (supplier_id, source, file_name, file_hash, account_number, bank_code, currency, statement_date,
                                          prev_balance, curr_balance, credit_total, debit_total)
             VALUES (?, 'gpc', ?, ?, ?, ?, 'CZK', ?, ?, ?, ?, 0)"
        )->execute([$supplierId, $fileName, hash('sha256', $fileName . $supplierId), SyntheticPohodaExport::EUR_ACCOUNT_NUMBER, SyntheticPohodaExport::BANK_CODE,
            $date, number_format($opening, 2, '.', ''), number_format($opening + $amount, 2, '.', ''), number_format($amount, 2, '.', '')]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, match_status) VALUES (?, ?, ?, 'CZK', ?)")
            ->execute([$id, $date, number_format($amount, 2, '.', ''), $matchStatus]);
        return $id;
    }

    private function statementCurrency(int $statementId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT currency FROM bank_statements WHERE id = ?');
        $stmt->execute([$statementId]);
        return (string) $stmt->fetchColumn();
    }

    /**
     * Stav po dřívějším převodu: výpisy, pohyby a párování účtu v EUR v Kč, deník bez měny,
     * evidence účtu v Kč a korunový řádek měn s číslem účtu.
     */
    private function legacyCzk(int $supplierId): void
    {
        $pdo = $this->db->pdo();
        $number = SyntheticPohodaExport::EUR_ACCOUNT_NUMBER;
        $czk = [SyntheticPohodaExport::EUR_RECEIPT => 12100, SyntheticPohodaExport::EUR_FEE => -48.50, SyntheticPohodaExport::EUR_FX_DIFFERENCE => 150];
        $tx = $pdo->prepare("UPDATE bank_transactions t JOIN bank_statements s ON s.id = t.statement_id SET t.amount = ?, t.currency = 'CZK'
            WHERE s.supplier_id = ? AND t.bank_ref = ?");
        foreach ($czk as $ref => $amount) {
            $tx->execute([$amount, $supplierId, $ref]);
        }
        $pdo->exec("UPDATE payment_matches pm JOIN bank_transactions t ON t.id = pm.bank_transaction_id SET pm.amount = 12100
            WHERE pm.supplier_id = {$supplierId} AND t.bank_ref = '" . SyntheticPohodaExport::EUR_RECEIPT . "'");
        $pdo->exec("UPDATE bank_statements SET currency = 'CZK' WHERE supplier_id = {$supplierId} AND account_number = '{$number}'");
        $first = true;
        foreach ($pdo->query("SELECT id FROM bank_statements WHERE supplier_id = {$supplierId} AND account_number = '{$number}' ORDER BY statement_date, id")->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $pdo->exec('UPDATE bank_statements SET prev_balance = ' . ($first ? '24245.00' : '36296.50') . ', curr_balance = ' . ($first ? '36296.50' : '36446.50')
                . ', credit_total = ' . ($first ? '12100.00' : '150.00') . ', debit_total = ' . ($first ? '48.50' : '0.00') . " WHERE id = {$id}");
            $first = false;
        }
        $pdo->exec("UPDATE journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
            SET l.currency_code = NULL, l.fx_rate = NULL, l.amount_foreign = NULL WHERE l.supplier_id = {$supplierId} AND a.account_code = '221.002'");
        $eur = (int) $pdo->query("SELECT id FROM currencies WHERE supplier_id = {$supplierId} AND code = 'EUR' AND account_number = '{$number}'")->fetchColumn();
        $pdo->exec("UPDATE currencies SET account_number = NULL, bank_code = NULL, iban = NULL WHERE id = {$eur}");
        $pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default, account_number, bank_code)
            VALUES (?, 'CZK', 'Testovací banka BANE', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 0, ?, ?)")->execute([$supplierId, $number, SyntheticPohodaExport::BANK_CODE]);
        $pdo->exec("UPDATE supplier_bank_accounts SET currency = 'CZK', currency_id = " . (int) $pdo->lastInsertId()
            . " WHERE supplier_id = {$supplierId} AND account_number = '{$number}'");
    }

    /** @param array<string,string> $amounts číslo pohybu => částka v EUR */
    private function assertForeignAccount(int $supplierId, array $amounts, string $closing): void
    {
        $number = SyntheticPohodaExport::EUR_ACCOUNT_NUMBER;
        self::assertSame(['EUR'], $this->statementCurrencies($supplierId, $number));
        $tx = $this->db->pdo()->prepare("SELECT t.bank_ref, t.amount, t.currency FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
            WHERE s.supplier_id = ? AND s.account_number = ? AND s.source = 'import' ORDER BY t.posted_at");
        $tx->execute([$supplierId, $number]);
        $out = [];
        foreach ($tx->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            self::assertSame('EUR', $r['currency']);
            $out[(string) $r['bank_ref']] = (string) $r['amount'];
        }
        self::assertSame($amounts, $out);
        $chain = $this->db->pdo()->prepare("SELECT prev_balance, credit_total, debit_total, curr_balance FROM bank_statements
            WHERE supplier_id = ? AND account_number = ? AND source = 'import' ORDER BY statement_date, id");
        $chain->execute([$supplierId, $number]);
        $rows = $chain->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame('1000.00', $rows[0]['prev_balance'], json_encode($rows));
        $prev = null;
        foreach ($rows as $r) {
            if ($prev !== null) {
                self::assertSame($prev, $r['prev_balance'], json_encode($rows));
            }
            self::assertEqualsWithDelta((float) $r['curr_balance'], (float) $r['prev_balance'] + (float) $r['credit_total'] - (float) $r['debit_total'], 0.001);
            $prev = $r['curr_balance'];
        }
        self::assertSame($closing, $prev);
    }

    /** @param list<array{0:?string,1:string,2:string,3:?string,4:?string,5:?string}> $expected [číslo dokladu (null = otevírací zápis), strana, Kč, měna, kurz, částka v měně] */
    private function assertJournalForeign(int $supplierId, array $expected): void
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT e.document_no, l.side, l.amount, l.currency_code, l.fx_rate, l.amount_foreign
               FROM journal_entry_lines l JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND a.account_code = '221.002' ORDER BY e.document_no IS NULL, e.document_no"
        );
        $stmt->execute([$supplierId]);
        self::assertSame($expected, array_map(static fn (array $r): array => array_values($r), $stmt->fetchAll(\PDO::FETCH_ASSOC)));
    }

    /** Účet v měnách firmy v EUR s číslem účtu, evidence účtu v EUR, žádný korunový řádek s jeho číslem. */
    private function assertEurRegistration(int $supplierId): void
    {
        $number = SyntheticPohodaExport::EUR_ACCOUNT_NUMBER;
        $pdo = $this->db->pdo();
        $rows = $pdo->query("SELECT code, bank_code, is_active FROM currencies WHERE supplier_id = {$supplierId} AND account_number = '{$number}'")->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame([['code' => 'EUR', 'bank_code' => SyntheticPohodaExport::BANK_CODE, 'is_active' => 1]], $rows);
        $sba = $pdo->query("SELECT a.currency, c.code FROM supplier_bank_accounts a LEFT JOIN currencies c ON c.id = a.currency_id
            WHERE a.supplier_id = {$supplierId} AND a.account_number = '{$number}'")->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame([['currency' => 'EUR', 'code' => 'EUR']], $sba);
    }

    /** @return list<string> */
    private function statementCurrencies(int $supplierId, string $number): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT DISTINCT currency FROM bank_statements WHERE supplier_id = ? AND account_number = ? AND source = 'import'");
        $stmt->execute([$supplierId, $number]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** @return list<string> */
    private function matchAmounts(int $supplierId, string $ref): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT pm.amount FROM payment_matches pm JOIN bank_transactions t ON t.id = pm.bank_transaction_id
            WHERE pm.supplier_id = ? AND t.bank_ref = ? ORDER BY pm.id');
        $stmt->execute([$supplierId, $ref]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** @return list<array<string,mixed>> korunové obraty deníku po účtech */
    private function ledger(int $supplierId): array
    {
        return $this->db->pdo()->query("SELECT a.account_code, l.side, SUM(l.amount) AS amount, COUNT(*) AS n FROM journal_entry_lines l
            JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
            WHERE l.supplier_id = {$supplierId} GROUP BY a.account_code, l.side ORDER BY a.account_code, l.side")->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @param list<array<string,mixed>> $rows @return array<string,array<string,mixed>> */
    private function byCode(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['code']] = $r;
        }
        return $out;
    }

    private function assertBankMatchesJournal(ImportProtocol $protocol): void
    {
        foreach ($protocol->get('reconciliation')[0]['documents'] ?? [] as $d) {
            if ($d['key'] === 'bank') {
                self::assertTrue($d['ok'], json_encode($d));
                self::assertEqualsWithDelta($d['documents'], $d['journal'], 0.001, json_encode($d));
                return;
            }
        }
        self::fail('Rekonciliace nemá kontrolu banky: ' . $this->explain($protocol));
    }

    private function supplier(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, dic, is_vat_payer, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Účetní 12", "Brno", "60200", ?, "prevod@example.invalid", ?, ?, 1, ?, ?, "tax_evidence")'
        )->execute([SyntheticPohodaExport::NAME, $this->czId, SyntheticPohodaExport::ICO, 'CZ' . SyntheticPohodaExport::ICO, $this->currencyId, $this->vatRateId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1), (?, 'EUR', 'EUR', '€', 'Euro', 'Euro', 2, 1, 0)"
        )->execute([$id, $id]);
        $czk = (int) $pdo->query("SELECT id FROM currencies WHERE supplier_id = {$id} AND code = 'CZK'")->fetchColumn();
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$czk, $id]);
        return $id;
    }

    private function explain(ImportProtocol $protocol): string
    {
        return (string) json_encode(['steps' => $protocol->toArray()['steps']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
