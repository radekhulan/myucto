<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\DimensionDefaultRepository;
use MyInvoice\Service\Accounting\Card\CardClearingRegime;
use MyInvoice\Service\Accounting\Card\CardClearingWriteOffService;
use MyInvoice\Service\Accounting\Dimension\DimensionRuleService;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\GoPay\GoPayMovementPoster;
use MyInvoice\Service\Accounting\GoPay\GoPayService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Payroll\Travel\BusinessTripCashPosting;
use MyInvoice\Service\Payroll\Travel\BusinessTripSettlement;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * Rámec 504 vyžaduje činnost na nákladech a výnosech. Automatické zápisy modulů bez
 * vlastního dokladu (poplatek GoPay, uzavření platby kartou bez dokladu, cestovní náhrada
 * vypořádaná pokladnou) ji musí převzít z pravidla dimenze nebo z výchozích dimenzí
 * kontextu. Když ji určit nejde, zápis se odmítne srozumitelnou hláškou.
 *
 * Izolace: rok 2099, sdílená transakce BankPostingTestCase (firma dočasně v rámci 504).
 */
#[Group('integration')]
final class Activity504ModuleSourcesTest extends BankPostingTestCase
{
    private int $activityType;
    private int $mainValue;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE supplier SET dimensions_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare("UPDATE supplier_accounting_frameworks SET accounting_framework = 'cz_504' WHERE supplier_id = ?")->execute([$this->supplierId]);
        $pdo->prepare(
            "INSERT IGNORE INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, '1900-01-01', 'cz_504')"
        )->execute([$this->supplierId]);
        $catalog = (new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplierId, self::YEAR . '-06-15');
        self::assertArrayHasKey('activity', $catalog);
        $this->activityType = (int) $catalog['activity']['id'];
        $this->mainValue = (int) $catalog['activity']['values']['main'];
    }

    // ── GoPay ────────────────────────────────────────────────────────────────

    public function testGoPayFeeTakesActivityFromDestinationBankAccountDefault(): void
    {
        $bankAccount = $this->configureGoPay();
        (new DimensionDefaultRepository($this->db))->replace($this->supplierId, 'bank_account', $bankAccount, [$this->activityType => $this->mainValue]);
        $movement = $this->goPayMovement('clearing_fee', -12.50);

        $this->container->get(GoPayMovementPoster::class)->post($this->supplierId, $movement, $this->userId);

        $row = $this->goPayRow($movement);
        self::assertSame('posted', $row['status'], (string) $row['issue_message']);
        self::assertSame([$this->activityType => $this->mainValue], $this->lineDimensions((int) $row['journal_entry_id'], '568'));
    }

    public function testGoPayFeeTakesActivityFromDimensionRule(): void
    {
        $this->configureGoPay();
        $this->activityRule('568');
        $movement = $this->goPayMovement('clearing_fee', -12.50);

        $this->container->get(GoPayMovementPoster::class)->post($this->supplierId, $movement, $this->userId);

        $row = $this->goPayRow($movement);
        self::assertSame('posted', $row['status'], (string) $row['issue_message']);
        self::assertSame([$this->activityType => $this->mainValue], $this->lineDimensions((int) $row['journal_entry_id'], '568'));
    }

    public function testGoPayFeeWithoutActivityFailsVisibly(): void
    {
        $this->configureGoPay();
        $movement = $this->goPayMovement('fee_credit', 5.00);

        $this->container->get(GoPayMovementPoster::class)->post($this->supplierId, $movement, $this->userId);

        $row = $this->goPayRow($movement);
        self::assertSame('error', $row['status']);
        self::assertSame('dimension_required', $row['issue_code']);
        self::assertStringContainsString('568', (string) $row['issue_message']);
        self::assertStringContainsString('Firma → Dimenze → Pravidla', (string) $row['issue_message']);
        self::assertNull($row['journal_entry_id']);
    }

    // ── uzavření platby kartou bez dokladu ───────────────────────────────────

    public function testCardWriteOffTakesActivityFromStatementAccountDefault(): void
    {
        $tx = $this->cardPayment();
        $account = $this->statementBankAccount();
        (new DimensionDefaultRepository($this->db))->replace($this->supplierId, 'bank_account', $account, [$this->activityType => $this->mainValue]);

        $res = $this->container->get(CardClearingWriteOffService::class)->writeOff($this->supplierId, $tx, 'expense', null, $this->userId);

        self::assertSame([$this->activityType => $this->mainValue], $this->lineDimensions($res['entry_id'], $res['account_code']));
    }

    public function testCardWriteOffTakesActivityFromDimensionRule(): void
    {
        $tx = $this->cardPayment();
        $this->activityRule('548');

        $res = $this->container->get(CardClearingWriteOffService::class)->writeOff($this->supplierId, $tx, 'expense', null, $this->userId);

        self::assertSame([$this->activityType => $this->mainValue], $this->lineDimensions($res['entry_id'], $res['account_code']));
    }

    public function testCardWriteOffWithoutActivityFailsVisibly(): void
    {
        $tx = $this->cardPayment();

        $caught = null;
        try {
            $this->container->get(CardClearingWriteOffService::class)->writeOff($this->supplierId, $tx, 'expense', null, $this->userId);
        } catch (PostingException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught);
        self::assertSame('dimension_required', $caught->errorCode);
        self::assertStringContainsString('548', $caught->getMessage());
        self::assertStringContainsString('Firma → Dimenze → Pravidla', $caught->getMessage());
    }

    // ── cestovní náhrada vypořádaná pokladnou ────────────────────────────────

    public function testTravelTakesActivityFromEmploymentDimension(): void
    {
        $employmentId = $this->employment();
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO payroll_dimensions (supplier_id, dimension_type, code, name, valid_from, dimension_value_id)
             VALUES (?, 'activity', 'HL', 'Hlavní činnost', '2000-01-01', ?)"
        )->execute([$this->supplierId, $this->mainValue]);
        $pdo->prepare(
            "INSERT INTO payroll_employment_dimensions (supplier_id, employment_id, dimension_id, share_percent, valid_from)
             VALUES (?, ?, ?, 100, '2000-01-01')"
        )->execute([$this->supplierId, $employmentId, (int) $pdo->lastInsertId()]);

        $outcome = $this->postTravel($employmentId);

        self::assertSame('posted', $outcome['status'], (string) json_encode($outcome));
        self::assertSame([$this->activityType => $this->mainValue], $this->lineDimensions((int) $outcome['journal_entry_id'], '512'));
    }

    public function testTravelTakesActivityFromDimensionRule(): void
    {
        $this->activityRule('512');

        $outcome = $this->postTravel($this->employment());

        self::assertSame('posted', $outcome['status'], (string) json_encode($outcome));
        self::assertSame([$this->activityType => $this->mainValue], $this->lineDimensions((int) $outcome['journal_entry_id'], '512'));
    }

    /** Chyba zaúčtování (tady chybějící činnost) se nesmí schovat za `skipped`. */
    public function testTravelWithoutActivityReturnsErrorOutcomeWithMessage(): void
    {
        $outcome = $this->postTravel($this->employment());

        self::assertSame('error', $outcome['status']);
        self::assertSame('dimension_required', $outcome['reason']);
        self::assertNull($outcome['journal_entry_id']);
        self::assertStringContainsString('512', (string) $outcome['message']);
        self::assertStringContainsString('Firma → Dimenze → Pravidla', (string) $outcome['message']);
    }

    /** Uzavřené období dál vrací `skipped`: opakované promítnutí zápis dožene. */
    public function testTravelInClosedPeriodIsStillSkipped(): void
    {
        $this->activityRule('512');
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->periodId]);

        $outcome = $this->postTravel($this->employment());

        self::assertSame('skipped', $outcome['status'], (string) json_encode($outcome));
        self::assertNull($outcome['message']);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function activityRule(string $mask): void
    {
        $this->container->get(DimensionRuleService::class)->create($this->supplierId, [
            'dimension_type_id' => $this->activityType,
            'account_mask' => $mask,
            'enforcement' => 'none',
            'default_value_id' => $this->mainValue,
        ]);
    }

    /** @return int vlastní bankový účet, na jehož analytiku 221.x jde výplata GoPay */
    private function configureGoPay(): int
    {
        $pdo = $this->db->pdo();
        $suffix = '98761';
        $pdo->prepare(
            "INSERT INTO supplier_bank_accounts
                (supplier_id, label, account_number, bank_code, bank_code_norm, currency, account_canonical, kind, analytic_suffix, source, is_active)
             VALUES (?, 'Účet výplat GoPay', '9876100017', '0100', '0100', 'CZK', '9876100017', 'current', ?, 'manual', 1)"
        )->execute([$this->supplierId, $suffix]);
        $bankAccount = (int) $pdo->lastInsertId();
        $parent = (int) $pdo->query("SELECT id FROM chart_of_accounts WHERE supplier_id={$this->supplierId} AND account_code='221'")->fetchColumn();
        $insert = $pdo->prepare(
            'INSERT INTO chart_of_accounts (supplier_id,account_code,name,account_type,normal_side,is_synthetic,parent_id)
             VALUES (?,?,?,"asset","debit",0,?)'
        );
        $insert->execute([$this->supplierId, '221.98762', 'GoPay test', $parent]);
        $gopayId = (int) $pdo->lastInsertId();
        $insert->execute([$this->supplierId, '221.' . $suffix, 'Banka test', $parent]);
        $destinationId = (int) $pdo->lastInsertId();
        $this->container->get(GoPayService::class)->saveSettings($this->supplierId, [
            'currency' => 'CZK',
            'gopay_account_id' => $gopayId,
            'receivable_account_id' => $this->accountId('311'),
            'fee_account_id' => $this->accountId('568'),
            'clearing_account_id' => $this->accountId('261'),
            'destination_bank_account_id' => $destinationId,
            'payout_account_number' => '1000000005',
            'payout_bank_code' => '0100',
            'payout_date_tolerance_days' => 3,
        ], $this->userId);
        return $bankAccount;
    }

    private function goPayMovement(string $type, float $amount): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO gopay_movements (supplier_id, origin, movement_type, performed_on, amount, currency, status)
             VALUES (?, 'clearing', ?, ?, ?, 'CZK', 'pending')"
        )->execute([$this->supplierId, $type, self::YEAR . '-03-10', $amount]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function goPayRow(int $movementId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT status, issue_code, issue_message, journal_entry_id FROM gopay_movements WHERE id = ?');
        $stmt->execute([$movementId]);
        return (array) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** Platba kartou zaúčtovaná přes mezičlen 378, bez dokladu. */
    private function cardPayment(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO card_clearing_settings (supplier_id, enabled, effective_from, clearing_synthetic)
             VALUES (?, 1, '2099-01-01', '378')
             ON DUPLICATE KEY UPDATE enabled = 1, effective_from = VALUES(effective_from), clearing_synthetic = VALUES(clearing_synthetic)"
        )->execute([$this->supplierId]);
        $this->container->get(CardClearingRegime::class)->forget($this->supplierId);
        $pdo->prepare('INSERT INTO payment_cards (supplier_id, label, last4) VALUES (?, ?, ?)')
            ->execute([$this->supplierId, 'Karta 504', '5040']);
        $tx = $this->transaction($this->statement(), -300.00, [
            'posted_at' => self::YEAR . '-06-15', 'counterparty_name' => 'OBCHOD TEST', 'description' => 'PK: 000000******5040',
        ]);
        $pdo->prepare('UPDATE bank_transactions SET card_last4 = ? WHERE id = ?')->execute(['5040', $tx]);
        $this->service->handleTransaction($tx, $this->userId);
        return $tx;
    }

    /** Vlastní účet firmy, na kterém leží výpis platby kartou. */
    private function statementBankAccount(): int
    {
        $pdo = $this->db->pdo();
        $find = $pdo->prepare('SELECT id FROM supplier_bank_accounts WHERE supplier_id = ? AND account_canonical = ? ORDER BY id LIMIT 1');
        $find->execute([$this->supplierId, ltrim(self::ACCOUNT, '0')]);
        $id = (int) $find->fetchColumn();
        if ($id > 0) {
            return $id;
        }
        $pdo->prepare(
            "INSERT INTO supplier_bank_accounts
                (supplier_id, label, account_number, bank_code, bank_code_norm, currency, account_canonical, kind, source, is_active)
             VALUES (?, 'Účet výpisu', ?, ?, ?, 'CZK', ?, 'current', 'manual', 1)"
        )->execute([$this->supplierId, self::ACCOUNT, self::BANK_CODE, self::BANK_CODE, ltrim(self::ACCOUNT, '0')]);
        return (int) $pdo->lastInsertId();
    }

    private function employment(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetický cestující 504", "employee", "hpp", 1, 1, 0, 42000, 0, 1)'
        )->execute([$this->supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor,
                 is_legacy_projection)
             VALUES (?, ?, "SYN-504-TRV", "employment", "active",
                     "2099-01-01", "2099-01-01", 4200000, 0)'
        )->execute([$this->supplierId, $employeeId]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array{status:string,journal_entry_id:?int,reason:?string,message:?string} */
    private function postTravel(int $employmentId): array
    {
        $trip = [
            'id' => 990_000 + $employmentId,
            'employment_id' => $employmentId,
            'approved_at' => self::YEAR . '-06-20 10:00:00',
            'advance_settlement' => BusinessTripSettlement::MODE_CASH,
            'exempt_total_minor' => 18_500,
            'taxable_total_minor' => 0,
            'advance_minor' => 10_000,
        ];
        return $this->container->get(BusinessTripCashPosting::class)
            ->post($this->supplierId, $trip, BusinessTripSettlement::fromTrip($trip), $this->userId);
    }

    private function accountId(string $code): int
    {
        $row = $this->accounts->findByCode($this->supplierId, $code);
        self::assertNotNull($row, 'Chybí účet ' . $code);
        return (int) $row['id'];
    }

    /** @return array<int,int> dimenze řádku zápisu na účtu (typ => hodnota) */
    private function lineDimensions(int $entryId, string $accountCode): array
    {
        $entry = $this->journal->find($entryId, $this->supplierId);
        self::assertNotNull($entry);
        $dimensions = (new DimensionAssignmentRepository($this->db))->entryLineDimensions($this->supplierId, $entryId);
        foreach ($entry['lines'] as $line) {
            if ($this->accountCode((int) $line['account_id']) === $accountCode) {
                return array_map('intval', $dimensions[(int) $line['id']] ?? []);
            }
        }
        self::fail('Zápis nemá řádek na účtu ' . $accountCode);
    }
}
