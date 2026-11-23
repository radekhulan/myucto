<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Tests\Support\EnforcementRunFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Příznak „člen orgánu je zároveň společníkem" u vztahu výkonu funkce: jde nastavit
 * jen u statutory_body, zapíše se do historie a podklad snímku běhu ho nese.
 */
#[Group('integration')]
final class PayrollStatutoryPartnerFlagTest extends TestCase
{
    use EnforcementRunFixtureTrait;

    protected function setUp(): void
    {
        $this->bootEnforcementRun();
    }

    protected function tearDown(): void
    {
        $this->tearDownEnforcementRun();
    }

    public function testFlagIsOnlyForStatutoryBodyAndReachesTheSnapshotRows(): void
    {
        $repository = $this->service(PayrollEmploymentRepository::class);
        try {
            $repository->setStatutoryPartner($this->supplierId, $this->employmentId, true, 1, $this->actorId, null, null);
            self::fail('Běžný pracovní poměr společníka nese nesmí.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('výkon funkce', $exception->getMessage());
        }

        $this->db->pdo()->prepare(
            "UPDATE payroll_employments SET relation_type = 'statutory_body' WHERE supplier_id = ? AND id = ?"
        )->execute([$this->supplierId, $this->employmentId]);
        $version = (int) $this->db->pdo()->query(
            'SELECT row_version FROM payroll_employments WHERE id = ' . $this->employmentId
        )->fetchColumn();
        $employment = $repository->setStatutoryPartner(
            $this->supplierId,
            $this->employmentId,
            true,
            $version,
            $this->actorId,
            null,
            null,
        );
        self::assertTrue($employment['statutory_partner']);

        $log = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM activity_log WHERE supplier_id = ? AND action = 'payroll.employment.statutory_partner_changed'"
        );
        $log->execute([$this->supplierId]);
        self::assertSame(1, (int) $log->fetchColumn());

        $rows = $this->service(PayrollRunSnapshotBuilder::class)
            ->employmentRows($this->supplierId, '2026-06-01', '2026-06-30', null);
        self::assertSame(1, (int) $rows[0]['statutory_partner']);
    }
}
