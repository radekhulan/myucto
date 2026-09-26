<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\StereoNx\StereoNxPayrollPostingMap;
use MyInvoice\Service\Payroll\Migration\PayrollPostingMapProposalService;
use MyInvoice\Service\Payroll\Migration\PayrollPostingMapProposalStore;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

#[Group('integration')]
final class StereoNxPayrollPostingProposalTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private ContainerInterface $container;
    private int $supplierId;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildApp()->getContainer();
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();
        self::assertTrue(str_ends_with((string) $pdo->query('SELECT DATABASE()')->fetchColumn(), '_test'));
        $template = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($template <= 0) self::markTestSkipped('Chybí základní syntetická firma.');
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $template);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testProposalPersistsAfterDryRollbackAndConfirmedStereoProposalSurvivesRepeat(): void
    {
        $source = StereoNxPayrollPostingMap::fromTables(
            [['TypPar' => 1, 'Text' => 'Hrubá mzda (z)', 'UcetMD' => '521', 'UcetD' => '331']],
            [['DoklRadaU' => 'MZ']],
            [['DoklRada' => 'MZ', 'Text' => 'Hrubá mzda (z)', 'UcetMD' => '521', 'UcetD' => '331',
                'KdyUcPripad' => '2026-01-31', 'Celkem' => 28_000.0]],
        );
        $service = $this->container->get(PayrollPostingMapProposalService::class);
        $store = $this->container->get(PayrollPostingMapProposalStore::class);
        $pdo = $this->db->pdo();

        $pdo->exec('SAVEPOINT stereo_posting_dry');
        $dry = $service->refresh($this->supplierId, $source, 2026, 'synthetic', true);
        self::assertSame('stereo_nx', $dry['source']);
        $pdo->exec('ROLLBACK TO SAVEPOINT stereo_posting_dry');
        $pdo->exec('RELEASE SAVEPOINT stereo_posting_dry');
        self::assertNull($store->find($this->supplierId, 'stereo_nx'));

        $first = $service->refresh($this->supplierId, $source, 2026, 'synthetic', true);
        self::assertSame('draft', $first['status']);
        self::assertSame('521', self::key($first, 'employment_gross_debit')['candidates'][0]['code']);
        self::assertSame($first, $service->refresh($this->supplierId, $source, 2026, 'synthetic', true));
        $store->markConfirmed($first['id'], ['employment_gross_debit' => '521'], null);
        $confirmed = $store->find($this->supplierId, 'stereo_nx');
        $repeat = $service->refresh($this->supplierId, $source, 2026, 'synthetic', true);
        self::assertSame($confirmed, $repeat);

        // Volba je jen pro Stereo; ostatní převody dál obnovují návrh jako rozpracovaný.
        self::assertSame('draft', $service->refresh($this->supplierId, $source, 2026, 'synthetic')['status']);
    }

    /** @param array<string,mixed> $proposal @return array<string,mixed> */
    private static function key(array $proposal, string $wanted): array
    {
        foreach ($proposal['proposal']['keys'] as $row) {
            if ($row['key'] === $wanted) return $row;
        }
        self::fail('Chybí kontace ' . $wanted);
    }
}
