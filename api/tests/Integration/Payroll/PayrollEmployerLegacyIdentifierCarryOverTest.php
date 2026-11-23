<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Service\Payroll\PayrollEmployerLegacyIdentifierCarryOver;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Přenos VS ČSSZ, kódu OSSZ a čísla plátce zdravotního pojištění z Nastavení firmy
 * do Mezd. Pravidla migrace 1194: jen PO, jen do prázdného cíle, jen platný tvar,
 * číslo plátce jen do účinného účtu výchozí pojišťovny. Smazat starý údaj smí
 * {@see PayrollEmployerLegacyIdentifierCarryOver::settle()} jen tehdy, když ho Mzdy drží.
 *
 * Běží v transakci na izolované firmě → rollback.
 */
#[Group('integration')]
final class PayrollEmployerLegacyIdentifierCarryOverTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollEmployerLegacyIdentifierCarryOver $carryOver;
    private PayrollInstitutionAccountRepository $accounts;
    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
            $this->db = $container->get(Connection::class);
            $this->carryOver = $container->get(PayrollEmployerLegacyIdentifierCarryOver::class);
            $this->accounts = $container->get(PayrollInstitutionAccountRepository::class);
            $licensed = $container->get(PayrollModuleAccess::class)->isLicensed();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if (!$licensed) {
            $this->markTestSkipped('Licence bez mzdového doplňku.');
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->setLegacy('po', '87 654 321', '110', '555-666-777');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testLegalEntityCarriesAllThreeIdentifiersIntoEmptyTargets(): void
    {
        $officeId = $this->createSettings();
        $accountId = $this->createHealthAccount(null);

        $carried = $this->carryOver->carryOver($this->supplierId, $this->userId);

        self::assertSame([
            'cssz_vsdp' => '87654321',
            'cssz_ossz_code' => '110',
            'health_insurance_number' => '555666777',
        ], $carried);
        self::assertSame('87654321', $this->officeSymbol($officeId));
        self::assertSame('110', $this->officeCode());
        self::assertSame('555666777', $this->accountSymbol($accountId));
        self::assertSame(2, $this->scalar('SELECT row_version FROM payroll_employer_settings WHERE supplier_id = ?'));
        self::assertSame(0, $this->scalar(
            'SELECT COUNT(*) FROM payroll_office_registration_versions WHERE supplier_id = ?'
        ), 'Účinnost registrace se nevymýšlí.');
    }

    public function testNaturalPersonNeverCarries(): void
    {
        $this->setLegacy('fo', '87654321', '110', '555666777');
        $officeId = $this->createSettings();
        $accountId = $this->createHealthAccount(null);

        self::assertSame([], $this->carryOver->carryOver($this->supplierId, $this->userId));
        self::assertNull($this->officeSymbol($officeId));
        self::assertNull($this->officeCode());
        self::assertNull($this->accountSymbol($accountId));

        $this->enablePayroll();
        self::assertSame(['carried' => [], 'cleared' => []], $this->carryOver->settle($this->supplierId, $this->userId));
        self::assertSame(['87654321', '110', '555666777'], $this->legacy(), 'U OSVČ jsou to osobní údaje.');
    }

    public function testFilledTargetsAreNeverOverwritten(): void
    {
        $officeId = $this->createSettings('1111111111', '220');
        $accountId = $this->createHealthAccount('2222222222');

        self::assertSame([], $this->carryOver->carryOver($this->supplierId, $this->userId));
        self::assertSame('1111111111', $this->officeSymbol($officeId));
        self::assertSame('220', $this->officeCode());
        self::assertSame('2222222222', $this->accountSymbol($accountId));
    }

    public function testInvalidFormatsAreNotCarried(): void
    {
        $this->setLegacy('po', '12345678901', 'P10', 'bez čísel');
        $officeId = $this->createSettings();
        $accountId = $this->createHealthAccount(null);

        self::assertSame([], $this->carryOver->carryOver($this->supplierId, $this->userId));
        self::assertNull($this->officeSymbol($officeId));
        self::assertNull($this->officeCode());
        self::assertNull($this->accountSymbol($accountId));

        $this->enablePayroll();
        $this->carryOver->settle($this->supplierId, $this->userId);
        self::assertSame(['12345678901', 'P10', 'bez čísel'], $this->legacy(), 'Nepřenesený údaj se nesmí smazat.');
    }

    public function testHealthNumberGoesOnlyToEffectiveAccountOfDefaultInsurer(): void
    {
        $this->createSettings(insurer: '111');
        $otherInsurer = $this->createHealthAccount(null, '201');
        $expired = $this->createHealthAccount(null, '111', '2020-01-01', '2020-12-31');

        $carried = $this->carryOver->carryOver($this->supplierId, $this->userId);

        self::assertArrayNotHasKey('health_insurance_number', $carried);
        self::assertNull($this->accountSymbol($otherInsurer));
        self::assertNull($this->accountSymbol($expired));
    }

    public function testSettleWithoutEmployerSettingsKeepsEverythingOnCompany(): void
    {
        $this->enablePayroll();

        self::assertSame(['carried' => [], 'cleared' => []], $this->carryOver->settle($this->supplierId, $this->userId));
        self::assertSame(['87 654 321', '110', '555-666-777'], $this->legacyRaw());
    }

    public function testSettleClearsOnlyWhatPayrollHolds(): void
    {
        $this->enablePayroll();
        $this->createSettings();

        $result = $this->carryOver->settle($this->supplierId, $this->userId);

        self::assertSame(['cssz_vsdp' => '87654321', 'cssz_ossz_code' => '110'], $result['carried']);
        self::assertSame(['cssz_vsdp', 'cssz_ossz_code'], $result['cleared']);
        self::assertSame([null, null, '555-666-777'], $this->legacyRaw(), 'Bez účtu pojišťovny číslo plátce zůstává na firmě.');
    }

    public function testSettleDoesNothingWithPayrollOff(): void
    {
        $this->createSettings();

        self::assertSame(['carried' => [], 'cleared' => []], $this->carryOver->settle($this->supplierId, $this->userId));
        self::assertSame('87 654 321', $this->legacyRaw()[0]);
    }

    private function setLegacy(string $type, ?string $symbol, ?string $office, ?string $health): void
    {
        $this->db->pdo()->prepare(
            'UPDATE supplier
                SET taxpayer_type = ?, payroll_enabled = 0,
                    cssz_vsdp = ?, cssz_ossz_code = ?, health_insurance_number = ?
              WHERE id = ?'
        )->execute([$type, $symbol, $office, $health, $this->supplierId]);
    }

    private function enablePayroll(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
    }

    private function createSettings(?string $symbol = null, ?string $officeCode = null, ?string $insurer = '111'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_offices (supplier_id, code, name, social_security_variable_symbol, is_active)
             VALUES (?, "MZDY", "Syntetická účtárna", ?, 1)'
        )->execute([$this->supplierId, $symbol]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings
                (supplier_id, default_office_id, social_security_office_code, default_health_insurer_code)
             VALUES (?, ?, ?, ?)'
        )->execute([$this->supplierId, $officeId, $officeCode, $insurer]);

        return $officeId;
    }

    private function createHealthAccount(
        ?string $symbol,
        string $insurer = '111',
        string $validFrom = '2026-01-01',
        ?string $validTo = null,
    ): int {
        $account = $this->accounts->create($this->supplierId, [
            'institution_type' => 'health_insurer',
            'institution_code' => $insurer,
            'institution_name' => 'Syntetická zdravotní pojišťovna',
            'bank_account' => '1000000005/0100',
            'currency_code' => 'CZK',
            'variable_symbol' => $symbol,
            'specific_symbol' => null,
            'constant_symbol' => null,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'source_kind' => 'official_document',
            'source_reference' => 'synthetic:carry-over',
            'verified_on' => '2026-06-15',
        ], $this->userId);

        return (int) $account['id'];
    }

    private function officeSymbol(int $officeId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT social_security_variable_symbol FROM payroll_offices WHERE id = ?');
        $stmt->execute([$officeId]);
        $value = $stmt->fetchColumn();

        return $value === null ? null : (string) $value;
    }

    private function officeCode(): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT social_security_office_code FROM payroll_employer_settings WHERE supplier_id = ?');
        $stmt->execute([$this->supplierId]);
        $value = $stmt->fetchColumn();

        return $value === null ? null : (string) $value;
    }

    private function accountSymbol(int $accountId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT variable_symbol FROM payroll_institution_accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        $value = $stmt->fetchColumn();

        return $value === null ? null : (string) $value;
    }

    private function scalar(string $sql): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$this->supplierId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<?string> */
    private function legacyRaw(): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT cssz_vsdp, cssz_ossz_code, health_insurance_number FROM supplier WHERE id = ?'
        );
        $stmt->execute([$this->supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_NUM) ?: [null, null, null];

        return array_map(static fn (mixed $v): ?string => $v === null ? null : (string) $v, $row);
    }

    /** @return list<?string> */
    private function legacy(): array
    {
        return array_map(static fn (?string $v): ?string => $v === null ? null : trim($v), $this->legacyRaw());
    }
}
