<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\ImportedStatementRebase;
use MyInvoice\Service\Bank\StatementImporter;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Výpis banky za převzatými výpisy z POHODY ({@see ImportedStatementRebase}):
 *  - účet v EUR, ve kterém Fio uvádí v GPC kód měny 0203 (CZK): výpis se naimportuje v EUR
 *    podle měnového účtu a navazuje na převzaté výpisy v EUR bez posunu stavů;
 *  - převzaté výpisy dvou účtů zdroje se stejným číslem účtu (víceměnový účet Raiffeisen
 *    převedený dřív celý v Kč) se do jednoho řetězu nespojí a jejich stavy se neposunou;
 *  - velký posun stavů se bez potvrzení neprovede, potvrzený se zaeviduje a smazání výpisu
 *    banky ho vrátí.
 *
 * Izolace: syntetické účty 2606000xxx (modulo 11), rok 2039, vlastní měny a výpisy; vše se
 * v tearDown maže (import výpisu běží ve vlastní transakci, rollback nejde).
 */
#[Group('integration')]
final class ImportedStatementRebaseGuardTest extends TestCase
{
    private const BANK = '2010';

    private Connection $db;
    private StatementImporter $importer;
    private int $supplierId = 0;
    /** @var list<int> */
    private array $currencyIds = [];
    /** @var list<string> */
    private array $accounts = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->importer = $container->get(StatementImporter::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $this->supplierId = (int) ($this->db->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier v DB.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        foreach ($this->accounts as $account) {
            // Výpis GPC nese číslo účtu z hlavičky s úvodními nulami.
            $ids = $pdo->prepare('SELECT id FROM bank_statements WHERE account_number = ? OR account_number = ?');
            $ids->execute([$account, str_pad($account, 16, '0', STR_PAD_LEFT)]);
            foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $pdo->prepare('DELETE FROM bank_statement_rebases WHERE anchor_statement_id = ?')->execute([$id]);
                $pdo->prepare("DELETE FROM pohoda_import_map WHERE kind = 'bank_statement' AND target_id = ?")->execute([$id]);
                $pdo->prepare('DELETE FROM bank_transaction_imports WHERE original_statement_id = ? OR statement_id = ?')->execute([$id, $id]);
                $pdo->prepare('DELETE FROM bank_transactions WHERE statement_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM bank_statements WHERE id = ?')->execute([$id]);
            }
            $pdo->prepare('DELETE FROM supplier_bank_accounts WHERE account_number = ? OR account_canonical = ?')->execute([$account, $account]);
        }
        foreach ($this->currencyIds as $id) {
            $pdo->prepare('DELETE FROM currencies WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    /** Před opravou převodu byl výpis v Kč (korunový měnový účet s číslem účtu v EUR) a stavy se posunuly. */
    public function testGpcWithCzkCodeContinuesEurChainOfImportedStatements(): void
    {
        $account = $this->account('2606000004');
        $this->currency('EUR', $account);
        $this->imported($account, 'EUR', 'FIOE', [['2039-01-31', '1000.00', '1250.00'], ['2039-02-28', '1250.00', '1498.00']]);

        $r = $this->importer->import($this->gpc($account, 149800, [10000, -2550], '280239', '310339'), 'synteticky-eur.gpc', null, $this->currencyIds[count($this->currencyIds) - 1]);

        self::assertFalse($r['duplicate'], json_encode($r, JSON_UNESCAPED_UNICODE));
        self::assertSame(['currency' => 'EUR', 'prev_balance' => '1498.00', 'curr_balance' => '1572.50'], $this->statement((int) $r['statement_id']));
        $tx = $this->db->pdo()->prepare('SELECT currency, amount FROM bank_transactions WHERE statement_id = ? ORDER BY amount');
        $tx->execute([$r['statement_id']]);
        self::assertSame([['currency' => 'EUR', 'amount' => '-25.50'], ['currency' => 'EUR', 'amount' => '100.00']], $tx->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame([], array_column($r['warnings'], 'code'), json_encode($r['warnings'], JSON_UNESCAPED_UNICODE));
        self::assertSame(['1000.00', '1250.00'], $this->importedOpenings($account));
    }

    /**
     * Víceměnový účet převedený celý v Kč: výpisy zkratek RB (Kč) a RBeu (EUR v korunách) se
     * stejným číslem. Před opravou se oba řetězy spojily a posunuly o rozdíl, který vznikl jen
     * z pohybů druhého účtu.
     */
    public function testImportedStatementsOfDifferentSourceAccountsAreNotRebased(): void
    {
        $account = $this->account('2606000012');
        $this->currency('CZK', $account);
        $this->imported($account, 'CZK', 'RB', [['2039-01-31', '10000.00', '12000.00']]);
        $this->imported($account, 'CZK', 'RBeu', [['2039-01-31', '500.00', '700.00']]);

        $r = $this->importer->import($this->gpc($account, 1200000, [10000], '310139', '280239'), 'synteticky-rb.gpc', null, $this->currencyIds[count($this->currencyIds) - 1]);

        $warning = array_column($r['warnings'], null, 'code')['imported_balances_rebase_mixed'] ?? null;
        self::assertNotNull($warning, json_encode($r['warnings'], JSON_UNESCAPED_UNICODE));
        self::assertSame(['pohoda:RB', 'pohoda:RBeu'], $warning['sources']);
        self::assertSame(['10000.00', '500.00'], $this->importedOpenings($account));
    }

    /**
     * Posun přes {@see ImportedStatementRebase::HOLD_LIMIT} u řetězu s počátečním stavem ze
     * zdroje se bez potvrzení neprovede; potvrzený posun smazání výpisu banky vrátí.
     */
    public function testLargeShiftIsHeldAndConfirmedShiftIsRevertedWithAnchor(): void
    {
        $account = $this->account('2606000020');
        $this->currency('CZK', $account);
        $this->imported($account, 'CZK', 'KB', [['2039-01-31', '10000.00', '12000.00'], ['2039-02-28', '12000.00', '11000.00']]);

        $r = $this->importer->import($this->gpc($account, 5100000, [], '280239', '310339'), 'synteticky-kb.gpc', null, $this->currencyIds[count($this->currencyIds) - 1]);
        $codes = array_column($r['warnings'], 'code');
        self::assertSame(['imported_balances_rebase_held'], $codes, json_encode($r['warnings'], JSON_UNESCAPED_UNICODE));
        self::assertSame(['10000.00', '12000.00'], $this->importedOpenings($account));

        $rebase = new ImportedStatementRebase($this->db);
        $result = $rebase->rebase($this->supplierId, (int) $r['statement_id'], true);
        self::assertSame('rebased', $result['status'] ?? null);
        self::assertSame(40000.0, $result['shift']);
        self::assertSame(['50000.00', '52000.00'], $this->importedOpenings($account));

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        self::assertSame(2, $rebase->revert($this->supplierId, (int) $r['statement_id']));
        $pdo->commit();
        self::assertSame(['10000.00', '12000.00'], $this->importedOpenings($account));
        $left = $pdo->prepare('SELECT COUNT(*) FROM bank_statement_rebases WHERE anchor_statement_id = ?');
        $left->execute([$r['statement_id']]);
        self::assertSame(0, (int) $left->fetchColumn());
    }

    /** Malý posun se provede hned (původní chování) a eviduje se. */
    public function testSmallShiftIsAppliedAndRecorded(): void
    {
        $account = $this->account('2606000039');
        $this->currency('CZK', $account);
        $this->imported($account, 'CZK', 'CS', [['2039-01-31', '10000.00', '12000.00']]);

        $r = $this->importer->import($this->gpc($account, 1250000, [], '310139', '280239'), 'synteticky-cs.gpc', null, $this->currencyIds[count($this->currencyIds) - 1]);

        self::assertSame(['imported_balances_rebased'], array_column($r['warnings'], 'code'));
        self::assertSame(['10500.00'], $this->importedOpenings($account));
        $rows = $this->db->pdo()->prepare('SELECT shift FROM bank_statement_rebases WHERE supplier_id = ? AND anchor_statement_id = ?');
        $rows->execute([$this->supplierId, $r['statement_id']]);
        self::assertSame(['500.00'], $rows->fetchAll(PDO::FETCH_COLUMN));
    }

    private function account(string $number): string
    {
        $this->accounts[] = $number;
        return $number;
    }

    private function currency(string $code, string $account): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default, account_number, bank_code)
             VALUES (?, ?, ?, ?, ?, ?, 2, 1, 0, ?, ?)"
        )->execute([$this->supplierId, $code, 'TEST ' . $code . ' ' . $account, $code, $code, $code, $account, self::BANK]);
        $this->currencyIds[] = (int) $this->db->pdo()->lastInsertId();
    }

    /** @param list<array{0:string,1:string,2:string}> $statements [datum, počáteční, konečný stav] */
    private function imported(string $account, string $currency, string $code, array $statements): void
    {
        $pdo = $this->db->pdo();
        foreach ($statements as $i => [$date, $prev, $curr]) {
            $no = sprintf('%03d', $i + 1);
            $key = 'stmt|' . $code . '|2039|' . $no;
            $pdo->prepare(
                "INSERT INTO bank_statements (supplier_id, source, file_name, file_hash, account_number, bank_code, currency, statement_number, statement_date,
                     prev_balance, curr_balance, credit_total, debit_total, transaction_count)
                 VALUES (?, 'import', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
            )->execute([$this->supplierId, 'pohoda-' . $code . '.import', hash('sha256', 'test|' . $this->supplierId . '|' . $account . '|' . $key), $account, self::BANK, $currency,
                $code . ' ' . $no . '/2039', $date, $prev, $curr, max(0, (float) $curr - (float) $prev), max(0, (float) $prev - (float) $curr)]);
            $id = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO bank_transactions (source, statement_id, posted_at, amount, currency, import_fingerprint)
                VALUES ('statement', ?, ?, ?, ?, ?)")->execute([$id, $date, number_format((float) $curr - (float) $prev, 2, '.', ''), $currency, hash('sha256', 'test-tx|' . $id)]);
            $pdo->prepare("INSERT INTO pohoda_import_map (supplier_id, kind, pohoda_key, target_id) VALUES (?, 'bank_statement', ?, ?)")
                ->execute([$this->supplierId, $key, $id]);
        }
    }

    /** @return list<string> počáteční stavy převzatých výpisů účtu v pořadí */
    private function importedOpenings(string $account): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT prev_balance FROM bank_statements WHERE supplier_id = ? AND account_number = ? AND source = 'import' ORDER BY statement_date, id");
        $stmt->execute([$this->supplierId, $account]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return array<string,string> */
    private function statement(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT currency, prev_balance, curr_balance FROM bank_statements WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Syntetický GPC (Fio): kód měny pohybů 0203 = CZK bez ohledu na měnu účtu.
     *
     * @param list<int> $txs částky v haléřích (centech) se znaménkem
     */
    private function gpc(string $account, int $old, array $txs, string $from, string $to): string
    {
        $acc16 = str_pad($account, 16, '0', STR_PAD_LEFT);
        $credit = array_sum(array_filter($txs, static fn (int $t): bool => $t > 0));
        $debit = -array_sum(array_filter($txs, static fn (int $t): bool => $t < 0));
        $balance = static fn (int $c): string => str_pad((string) abs($c), 14, '0', STR_PAD_LEFT) . ($c < 0 ? '-' : '+');
        $out = '074' . $acc16 . str_pad('SYNTETICKY UCET', 20) . $from . $balance($old) . $balance($old + $credit - $debit)
            . $balance($debit) . $balance($credit) . '004' . $to . 'FIO' . "\r\n";
        foreach ($txs as $i => $t) {
            $out .= '075' . $acc16 . str_pad('', 16, '0') . str_pad((string) (700001 + $i), 13, '0', STR_PAD_LEFT) . str_pad((string) abs($t), 12, '0', STR_PAD_LEFT)
                . ($t < 0 ? '1' : '2') . str_pad('', 10, '0') . '00' . '0000' . '0000' . str_pad('', 10, '0') . $to
                . str_pad('SYNTETICKA PLATBA', 20) . '00203' . $to . "\r\n";
        }
        return $out;
    }
}
