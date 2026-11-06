<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Nonprofit;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Nonprofit\Membership\MembershipBalanceRepository;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MembershipLegacyBankPairingTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private int $supplier;
    private int $foreign;
    private int $statement;
    private string $account;
    private array $claim;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        $this->pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->beginTransaction();
        $vat = (int) $this->pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            $this->supplier = $this->supplier('legacy-owner@example.test', $vat);
            $this->foreign = $this->supplier('legacy-foreign@example.test', $vat);
            $this->account = $this->unusedSyntheticAccount();
            $this->pdo->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default,account_number,bank_code)
                VALUES(?,'CZK','Synthetic CZK','Kč','Koruna','Crown',2,1,1,?,'0100')")->execute([$this->supplier, $this->account]);
            $this->pdo->prepare("INSERT INTO users(email,password_hash,name,role,locale,is_active) VALUES('membership-legacy-actor@example.test',?,'Synthetic legacy actor','admin','cs',1)")
                ->execute([password_hash('Synthetic unused password', PASSWORD_BCRYPT, ['cost' => 4])]);
            $actor = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare("INSERT INTO accounting_periods(supplier_id,fiscal_year,starts_on,ends_on,status) VALUES(?,2026,'2026-01-01','2026-12-31','open')")
                ->execute([$this->supplier]);
            $period = (int) $this->pdo->lastInsertId();
            foreach ([['315.100', 'asset'], ['221', 'asset'], ['684.100', 'revenue']] as [$code, $type]) {
                $this->pdo->prepare('INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type) VALUES(?,?,?,?)')
                    ->execute([$this->supplier, $code, 'Synthetic ' . $code, $type]);
                $accounts[$code] = (int) $this->pdo->lastInsertId();
            }
            $issueEntry = $this->entry($period, 'nonprofit_membership', null, $actor);
            $issueReceivable = $this->line($issueEntry, $accounts['315.100'], 'debit', 1);
            $issueRevenue = $this->line($issueEntry, $accounts['684.100'], 'credit', 2);
            $this->pdo->prepare("INSERT INTO bank_statements(supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date,imported_by)
                VALUES(NULL,'synthetic-legacy.gpc',?,?,'0100','CZK','2026-02-01',?)")
                ->execute([hash('sha256', 'synthetic legacy membership ' . $this->supplier), $this->account, $actor]);
            $this->statement = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare("INSERT INTO bank_transactions(statement_id,posted_at,amount,currency,description,match_status)
                VALUES(?,'2026-02-01',100,'CZK','Synthetic legacy payment','manual')")->execute([$this->statement]);
            $transaction = (int) $this->pdo->lastInsertId();
            $paymentEntry = $this->entry($period, 'bank', $transaction, $actor);
            $this->line($paymentEntry, $accounts['221'], 'debit', 1);
            $paymentReceivable = $this->line($paymentEntry, $accounts['315.100'], 'credit', 2);
            $this->pdo->prepare("INSERT INTO nonprofit_membership_charge_operations
                (supplier_id,case_id,command_id,operation,member_revision_id,legal_profile_id,accounting_period_id,accounting_date,due_date,
                 amount_cents,receivable_account_id,revenue_account_id,journal_entry_id,receivable_line_id,revenue_line_id,preview_hash,proof_json,
                 receipt_json,parent_hash,record_hash,created_by)
                VALUES(?,1,1,'issue',1,1,?,'2026-01-01','2026-01-31',10000,?,?,?,?,?,REPEAT('1',64),'{}','{}',REPEAT('2',64),REPEAT('3',64),?)")
                ->execute([$this->supplier, $period, $accounts['315.100'], $accounts['684.100'], $issueEntry, $issueReceivable, $issueRevenue, $actor]);
            $this->pdo->prepare("INSERT INTO journal_line_pairings(supplier_id,account_id,note,origin,created_by) VALUES(?,?,'Synthetic exact legacy payment','manual',?)")
                ->execute([$this->supplier, $accounts['315.100'], $actor]);
            $pairing = (int) $this->pdo->lastInsertId();
            $insert = $this->pdo->prepare('INSERT INTO journal_line_pairing_items(supplier_id,entry_id,line_no,pairing_id,account_id) VALUES(?,?,?,?,?)');
            $insert->execute([$this->supplier, $issueEntry, 1, $pairing, $accounts['315.100']]);
            $insert->execute([$this->supplier, $paymentEntry, 2, $pairing, $accounts['315.100']]);
            $this->claim = ['claim_id' => 91, 'journal_entry_id' => $issueEntry, 'line_no' => 1, 'account_id' => $accounts['315.100']];
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    #[DataProvider('ownershipCases')]
    public function testPairingUsesCanonicalLegacyStatementOwnership(string $case, int $expectedInvalid): void
    {
        if ($case === 'foreign') {
            $this->pdo->prepare('UPDATE bank_statements SET supplier_id=? WHERE id=?')->execute([$this->foreign, $this->statement]);
        } elseif ($case === 'ambiguous') {
            $this->pdo->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default,account_number,bank_code)
                VALUES(?,'CZK','Synthetic foreign CZK','Kč','Koruna','Crown',2,1,1,?,'0100')")->execute([$this->foreign, $this->account]);
        }
        $evidence = (new MembershipBalanceRepository($this->db))->pairingEvidence($this->supplier, [$this->claim]);
        self::assertSame($expectedInvalid, $evidence[91]['invalid_count'], $case);
        self::assertSame(2, $evidence[91]['item_count']);
        self::assertSame(1, $evidence[91]['membership_claim_count']);
        self::assertSame(0, $evidence[91]['net_cents']);
    }

    public static function ownershipCases(): iterable
    {
        yield 'unique legacy account is owned' => ['owned_legacy', 0];
        yield 'foreign authoritative owner is rejected' => ['foreign', 1];
        yield 'ambiguous legacy account is rejected' => ['ambiguous', 1];
    }

    private function unusedSyntheticAccount(): string
    {
        $validator = new \MyInvoice\Service\Payment\CzechBankAccountValidator();
        $query = $this->pdo->prepare("SELECT COUNT(*) FROM currencies WHERE REPLACE(REPLACE(account_number,' ',''),'-','')=?");
        for ($candidate = 8000000000 + ($this->supplier % 1000000) * 11; $candidate < 9000000000; $candidate++) {
            try {
                $account = $validator->parse($candidate . '/0100')['account_number'];
            } catch (\InvalidArgumentException) {
                continue;
            }
            $query->execute([$account]);
            if ((int) $query->fetchColumn() === 0) return $account;
        }
        throw new \RuntimeException('Synthetic bank account range exhausted.');
    }

    private function supplier(string $email, int $vat): int
    {
        $this->pdo->prepare("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode)
            VALUES('Synthetic membership pairing','Synthetic street','Synthetic city','10000',?,203,1,?,'double_entry')")->execute([$email, $vat]);
        return (int) $this->pdo->lastInsertId();
    }

    private function entry(int $period, string $source, ?int $sourceId, int $actor): int
    {
        $this->pdo->prepare("INSERT INTO journal_entries(supplier_id,period_id,entry_date,description,source_type,source_id,posted_at,posted_by)
            VALUES(?,?,'2026-02-01','Synthetic membership pairing',?,?, '2026-02-01 10:00:00',?)")
            ->execute([$this->supplier, $period, $source, $sourceId, $actor]);
        return (int) $this->pdo->lastInsertId();
    }

    private function line(int $entry, int $account, string $side, int $line): int
    {
        $this->pdo->prepare('INSERT INTO journal_entry_lines(entry_id,supplier_id,account_id,side,amount,line_no) VALUES(?,?,?,?,100.00,?)')
            ->execute([$entry, $this->supplier, $account, $side, $line]);
        return (int) $this->pdo->lastInsertId();
    }
}
