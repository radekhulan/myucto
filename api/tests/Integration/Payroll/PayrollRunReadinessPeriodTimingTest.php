<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Support\PayrollTestContainer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Kontrola před během řekne, že období ještě neskončilo nebo ani nezačalo.
 *
 * Běh šel bez jediné zmínky založit, spočítat i schválit za měsíc, který
 * teprve přijde; na kalendář narazila až příprava měsíčního hlášení. Běh se
 * nezakazuje (výplata před koncem měsíce je legitimní), jen se řekne, co to
 * znamená.
 */
#[Group('integration')]
final class PayrollRunReadinessPeriodTimingTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollRunReadinessService $readiness;
    private int $supplierId;

    protected function setUp(): void
    {
        $container = PayrollTestContainer::build();
        $container->set(ClockInterface::class, new class () implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-11-11 12:00:00', new \DateTimeZone('Europe/Prague'));
            }
        });
        $this->db = $container->get(Connection::class);
        $this->readiness = $container->get(PayrollRunReadinessService::class);
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($sourceSupplierId <= 0) {
            $this->markTestSkipped('Chybí zdrojová firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO users (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, "Synthetic timing", "readonly", "cs", 1)'
        )->execute([
            'timing-' . bin2hex(random_bytes(4)) . '@invalid.example',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);
        $actorId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", "2026-01-01", ?, NOW())'
        )->execute([$this->supplierId, $actorId]);
        $container->get(PayrollEmployerPolicyRepository::class)->create($this->supplierId, [
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'payday_day' => 10,
            'payday_month_offset' => 1,
            'payday_business_day_rule' => 'previous_business_day',
            'balance_rounding_mode' => 'exact_minor_units',
            'home_office_policy' => 'not_used',
            'travel_expense_policy' => 'not_used',
            'automatic_posting_enabled' => true,
            'delivery_channel' => 'disabled',
            'delivery_verified_on' => null,
            'source_kind' => 'manual',
            'source_reference' => 'synthetic:timing-policy',
        ], $actorId);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testFinishedMonthCarriesNoTimingFinding(): void
    {
        $result = $this->readiness->inspect($this->supplierId, '2026-10-01', '2026-11-10');

        self::assertNull(self::finding($result['findings'], 'period_not_finished'));
        self::assertNull(self::finding($result['findings'], 'period_not_started'));
    }

    public function testRunningMonthIsAnInformationWithTheDateReportsOpen(): void
    {
        // Dnes je 11. 11. 2026: listopad běží.
        $result = $this->readiness->inspect($this->supplierId, '2026-11-01', '2026-12-10');

        $finding = self::finding($result['findings'], 'period_not_finished');
        self::assertNotNull($finding, json_encode(array_column($result['findings'], 'code')));
        self::assertNull(self::finding($result['findings'], 'period_not_started'));
        self::assertSame('anytime', $finding['impact']);
        self::assertSame('info', $finding['severity']);
        self::assertStringContainsString('11/2026 ještě neskončilo', $finding['message']);
        self::assertStringContainsString('končí 30. 11. 2026', $finding['message']);
        self::assertStringContainsString('nejdříve 1. 12. 2026', $finding['message']);
        self::assertStringContainsString('opravnou revizi', $finding['message']);
        // Informace o kalendáři běh nezastaví.
        self::assertNotSame('blocking', $finding['impact']);
        self::assertSame([], $finding['bulk_actions']);
    }

    public function testMonthThatHasNotStartedWarnsMoreStrongly(): void
    {
        foreach (['2026-12-01' => ['12/2026', '1. 12. 2026', '1. 1. 2027'], '2027-01-01' => ['01/2027', '1. 1. 2027', '1. 2. 2027']] as $periodStart => [$period, $starts, $reportsFrom]) {
            $paymentDate = (new \DateTimeImmutable($periodStart))->modify('+1 month +9 days')->format('Y-m-d');
            $result = $this->readiness->inspect($this->supplierId, $periodStart, $paymentDate);

            $finding = self::finding($result['findings'], 'period_not_started');
            self::assertNotNull($finding, $periodStart . ': ' . json_encode(array_column($result['findings'], 'code')));
            self::assertNull(self::finding($result['findings'], 'period_not_finished'));
            // Silnější než u běžícího měsíce, ale pořád ne závora.
            self::assertSame('revision', $finding['impact']);
            self::assertSame('warning', $finding['severity']);
            self::assertStringContainsString("Období {$period} ještě nezačalo (začíná {$starts})", $finding['message']);
            self::assertStringContainsString("nejdříve {$reportsFrom}", $finding['message']);
            self::assertStringContainsString('z plánu', $finding['message']);
        }
    }

    /**
     * Kontrola za leden 2027 mlčela o hodnotách roku, které ještě nevyšly
     * (nezabavitelné částky, cestovní náhrady, stravenkový paušál).
     */
    public function testJanuaryOfTheNewYearNamesValuesThatAreNotPublishedYet(): void
    {
        $result = $this->readiness->inspect($this->supplierId, '2027-01-01', '2027-02-10');

        $finding = self::finding($result['findings'], 'ruleset_year_values_pending');
        self::assertNotNull($finding, json_encode(array_column($result['findings'], 'code')));
        self::assertSame('anytime', $finding['impact']);
        self::assertSame('info', $finding['severity']);
        self::assertSame('/payroll/rulesets', $finding['remediation_path']);
        self::assertStringContainsString('Pro rok 2027', $finding['message']);
        self::assertStringContainsString('exekuční srážky (nezabavitelné částky)', $finding['message']);
        self::assertStringContainsString('cestovní náhrady', $finding['message']);
        self::assertStringContainsString('neodhaduje', $finding['message']);

        // Rok 2026 má všechny hodnoty vyhlášené: nález se neukáže.
        $complete = $this->readiness->inspect($this->supplierId, '2026-10-01', '2026-11-10');
        self::assertNull(self::finding($complete['findings'], 'ruleset_year_values_pending'));
    }

    public function testLastDayOfMonthStillCountsAsRunning(): void
    {
        // Období, které končí dnes, ještě neskončilo; hlášení jde až zítra.
        $container = PayrollTestContainer::build();
        $container->set(ClockInterface::class, new class () implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-11-30 23:30:00', new \DateTimeZone('Europe/Prague'));
            }
        });
        $container->set(Connection::class, $this->db);
        $readiness = $container->get(PayrollRunReadinessService::class);

        $result = $readiness->inspect($this->supplierId, '2026-11-01', '2026-12-10');

        self::assertNotNull(self::finding($result['findings'], 'period_not_finished'));
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @return array<string,mixed>|null
     */
    private static function finding(array $findings, string $code): ?array
    {
        foreach ($findings as $finding) {
            if (($finding['code'] ?? null) === $code) {
                return $finding;
            }
        }

        return null;
    }
}
