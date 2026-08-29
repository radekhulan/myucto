<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollRegistrationChangeProposalRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationChangeDetectionService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Návrhy registračních povinností proti skutečné databázi.
 *
 * Doménové porovnání se testuje jednotkově; tady jde o to, co jednotkový test
 * nezachytí — platnost SQL, tenantní izolaci a to, že se lhůta z detekce
 * opravdu dostane do přehledu termínů. Dřív se změna údaje nesledovala vůbec,
 * takže osmidenní lhůta neměla kde vzniknout.
 */
#[Group('integration')]
final class PayrollRegistrationChangeProposalRepositoryTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollRegistrationChangeProposalRepository $repository;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employeeId;
    private int $employmentId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('payroll_registration_change_proposals')
            || !$this->db->hasTable('payroll_registration_change_scans')
            || !$this->db->hasTable('payroll_registration_event_snapshots')
        ) {
            $this->markTestSkipped('Migrace detekce registračních změn neproběhly.');
        }
        $this->repository = $container->get(
            PayrollRegistrationChangeProposalRepository::class,
        );
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $pdo
            ->query('SELECT id FROM supplier ORDER BY id LIMIT 1')
            ->fetchColumn();
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->seedEmployment($pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    /**
     * Tentýž rozešlý stav smí mít nejvýš jeden návrh. Bez toho by každé
     * otevření karty založilo další kopii téže lhůty.
     */
    public function testSameStateNeverProducesASecondProposal(): void
    {
        $first = $this->repository->insert($this->record());
        $second = $this->repository->insert($this->record());

        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertSame(
            (int) $first['row']['id'],
            (int) $second['row']['id'],
        );
    }

    /**
     * Jedna změna kódu pojišťovny = dvě povinnosti. Kdyby se srazily na
     * unikátním klíči, povinnost vůči pojišťovnám by tiše zmizela.
     */
    public function testRegistrationAndHealthInsurerDutiesCoexist(): void
    {
        $registration = $this->repository->insert($this->record());
        $insurer = $this->repository->insert($this->record([
            'duty_kind' => PayrollRegistrationChangeDetectionService::DUTY_HEALTH_INSURER,
            'action_code' => null,
            'current_fingerprint' => str_repeat('b', 64),
            'deadline_source' => '§ 10 odst. 1 písm. b) zákona č. 48/1997 Sb.',
        ]));

        self::assertTrue($registration['created']);
        self::assertTrue($insurer['created']);
        self::assertCount(2, $this->repository->openForEmployment(
            $this->supplierId,
            'test',
            $this->employmentId,
        ));
    }

    /** Lhůta z detekce musí být vidět v podkladu pro přehled termínů. */
    public function testOpenProposalReachesTheDeadlineFeed(): void
    {
        $this->repository->insert($this->record());

        $rows = $this->repository->openDeadlines(
            $this->supplierId,
            'test',
            '2026-08-01',
            '2026-09-30',
        );

        self::assertCount(1, $rows);
        self::assertSame('2026-09-06', (string) $rows[0]['due_on']);
        self::assertSame(
            PayrollRegistrationChangeDetectionService::DUTY_REGISTRATION,
            (string) $rows[0]['duty_kind'],
        );
        // Cizí firma nesmí vidět nic.
        self::assertSame([], $this->repository->openDeadlines(
            $this->otherSupplierId,
            'test',
            '2026-08-01',
            '2026-09-30',
        ));
    }

    /** Uzavřený návrh z přehledu zmizí a podruhé se uzavřít nedá. */
    public function testResolvingIsIdempotentAndRemovesTheDeadline(): void
    {
        $stored = $this->repository->insert($this->record());
        $proposalId = (int) $stored['row']['id'];

        self::assertTrue($this->repository->resolve(
            $this->supplierId,
            'test',
            $proposalId,
            'dismissed',
            null,
            null,
            'Podáno formulářem pojišťovny.',
        ));
        self::assertFalse($this->repository->resolve(
            $this->supplierId,
            'test',
            $proposalId,
            'dismissed',
            null,
            null,
            'Druhý pokus.',
        ));
        self::assertSame([], $this->repository->openDeadlines(
            $this->supplierId,
            'test',
            '2026-08-01',
            '2026-09-30',
        ));
    }

    /**
     * Vodoznak je to, co u firmy s pěti sty zaměstnanci nahrazuje pět set
     * dešifrování jedním dotazem — musí být platný SQL a stabilní.
     */
    public function testWatermarkIsStableAndSweepCandidateQueryRuns(): void
    {
        $first = $this->repository->watermark($this->supplierId, $this->employmentId);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        self::assertSame(
            $first,
            $this->repository->watermark($this->supplierId, $this->employmentId),
        );

        // Bez odeslaného podání není co porovnávat, takže kandidát nevznikne;
        // podstatné je, že dotaz proběhne.
        self::assertSame([], $this->repository->staleEmployments(
            $this->supplierId,
            'test',
            50,
        ));

        $this->repository->rememberScan(
            $this->supplierId,
            'test',
            $this->employmentId,
            $first,
            null,
        );
        $this->repository->rememberScan(
            $this->supplierId,
            'test',
            $this->employmentId,
            str_repeat('c', 64),
            null,
        );
        $stored = $this->db->pdo()->prepare(
            'SELECT source_watermark FROM payroll_registration_change_scans
              WHERE supplier_id = ? AND environment = ? AND employment_id = ?',
        );
        $stored->execute([$this->supplierId, 'test', $this->employmentId]);
        self::assertSame(str_repeat('c', 64), (string) $stored->fetchColumn());
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function record(array $overrides = []): array
    {
        return array_merge([
            'supplier_id' => $this->supplierId,
            'employee_id' => $this->employeeId,
            'employment_id' => $this->employmentId,
            'environment' => 'test',
            'duty_kind' => PayrollRegistrationChangeDetectionService::DUTY_REGISTRATION,
            'action_code' => 3,
            'baseline_fingerprint' => str_repeat('a', 64),
            'current_fingerprint' => str_repeat('f', 64),
            'detected_on' => '2026-08-29',
            'due_on' => '2026-09-06',
            'deadline_ruleset_id' => 'cz-regzec-follow-up-2026-04.v1',
            'deadline_source' => '§ 19 odst. 5 zákona č. 323/2025 Sb.',
            'findings_json' => CanonicalJson::encode([
                'schema_reference' =>
                    PayrollRegistrationChangeDetectionService::SCHEMA_REFERENCE,
                'findings' => [[
                    'path' => 'permanent_address.city',
                    'group' => 'permanent_address',
                    'action_code' => 3,
                    'sensitive' => false,
                    'from' => 'Praha',
                    'to' => 'Brno',
                ]],
                'unsupported' => [],
                'without_baseline' => [],
            ]),
        ], $overrides);
    }

    private function seedEmployment(PDO $pdo): void
    {
        $pdo->prepare(
            'UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)',
        )->execute([$this->supplierId, $this->otherSupplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Detekce Zmen", "employee", "hpp", 1, 1, 0, 10000, 0, 1)',
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, is_legacy_projection)
             VALUES (?, ?, "change-detection", "employment", "active",
                     "2026-08-01", 0)',
        )->execute([$this->supplierId, $this->employeeId]);
        $this->employmentId = (int) $pdo->lastInsertId();
    }
}
