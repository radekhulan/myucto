<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Document\PayrollArtifact;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKind;
use MyInvoice\Service\Payroll\Document\PayrollDocumentService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollAnnualDocumentKindAllowlistTest extends TestCase
{
    use IsolatedSupplierTrait;

    #[DataProvider('allowedKinds')]
    public function testAnnualArchiveAllowsOnlyExactAnnualDocumentKinds(
        PayrollDocumentKind $kind,
    ): void {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $connection = $container->get(Connection::class);
        $service = $container->get(PayrollDocumentService::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(PayrollDocumentService::class, $service);
        $pdo = $connection->pdo();
        $pdo->beginTransaction();
        try {
            [$supplierId, $employeeId] = $this->identity($pdo);
            $annualRevisionId = $this->annualRevision(
                $pdo,
                $supplierId,
                $employeeId,
                $kind,
            );
            $document = $service->archiveAnnualPdf(
                $supplierId,
                $annualRevisionId,
                $employeeId,
                self::artifact($kind),
                'annual-allowlist-' . $kind->value,
                null,
            );

            self::assertSame($kind->value, $document['document_kind']);
            self::assertSame($annualRevisionId, $document['annual_revision_id']);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $connection->close();
        }
    }

    #[DataProvider('rejectedKinds')]
    public function testAnnualArchiveRejectsEveryOtherDocumentKind(
        PayrollDocumentKind $kind,
    ): void {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $connection = $container->get(Connection::class);
        $service = $container->get(PayrollDocumentService::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(PayrollDocumentService::class, $service);
        $pdo = $connection->pdo();
        $pdo->beginTransaction();
        try {
            [$supplierId, $employeeId] = $this->identity($pdo);
            $annualRevisionId = $this->annualRevision(
                $pdo,
                $supplierId,
                $employeeId,
                PayrollDocumentKind::PayrollSheet,
            );

            $this->expectException(\InvalidArgumentException::class);
            $service->archiveAnnualPdf(
                $supplierId,
                $annualRevisionId,
                $employeeId,
                self::artifact($kind),
                'annual-rejected-' . $kind->value,
                null,
            );
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $connection->close();
        }
    }

    /** @return iterable<string,array{PayrollDocumentKind}> */
    public static function allowedKinds(): iterable
    {
        yield 'mzdový list' => [PayrollDocumentKind::PayrollSheet];
        yield 'zálohové potvrzení' => [
            PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
        ];
        yield 'srážkové potvrzení' => [
            PayrollDocumentKind::TaxableIncomeWithholdingCertificate,
        ];
        // MZ-25: výsledek ročního zúčtování je taky roční doklad vázaný na
        // `payroll_annual_document_revisions`, ne na jeden mzdový běh.
        yield 'roční zúčtování' => [
            PayrollDocumentKind::AnnualSettlementResult,
        ];
    }

    /** @return iterable<string,array{PayrollDocumentKind}> */
    public static function rejectedKinds(): iterable
    {
        yield 'výplatní páska' => [PayrollDocumentKind::Payslip];
        yield 'potvrzení o zaměstnání' => [
            PayrollDocumentKind::EmploymentCertificate,
        ];
        yield 'potvrzení o průměrném výdělku' => [
            PayrollDocumentKind::AverageEarningsCertificate,
        ];
        yield 'měsíční balíček' => [PayrollDocumentKind::MonthlyBundle];
    }

    /** @return array{int,int} */
    private function identity(PDO $pdo): array
    {
        $sourceSupplierId = (int) $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        $supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická roční osoba", "employee", 1)',
        )->execute([$supplierId]);

        return [$supplierId, (int) $pdo->lastInsertId()];
    }

    private function annualRevision(
        PDO $pdo,
        int $supplierId,
        int $employeeId,
        PayrollDocumentKind $kind,
    ): int {
        $manifest = '{"schema_version":"synthetic-annual-source.v1"}';
        $pdo->prepare(
            'INSERT INTO payroll_annual_document_revisions
                (supplier_id, employee_id, tax_year, purpose, revision_no,
                 snapshot_ciphertext, snapshot_hash, source_manifest_json,
                 source_manifest_hash, approved_at)
             VALUES (?, ?, 2026, ?, 1, ?, ?, ?, ?, NOW())',
        )->execute([
            $supplierId,
            $employeeId,
            $kind->value,
            'enc:v2:synthetic',
            str_repeat('a', 64),
            $manifest,
            hash('sha256', $manifest),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private static function artifact(PayrollDocumentKind $kind): PayrollArtifact
    {
        return new PayrollArtifact(
            $kind,
            '%PDF-1.4 synthetic annual artifact ' . $kind->value,
            'application/pdf',
            'synthetic-' . $kind->value . '.pdf',
            str_repeat('a', 64),
            'synthetic-v1',
            'synthetic-v1',
        );
    }
}
