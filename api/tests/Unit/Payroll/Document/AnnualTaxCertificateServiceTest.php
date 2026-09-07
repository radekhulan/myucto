<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Document;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\NamedLockName;
use MyInvoice\Service\Payroll\Document\AnnualTaxCertificateDocumentData;
use MyInvoice\Service\Payroll\Document\AnnualTaxCertificatePdfRenderer;
use MyInvoice\Service\Payroll\Document\AnnualTaxCertificateService;
use MyInvoice\Service\Payroll\Document\AnnualTaxCertificateSnapshotBuilder;
use MyInvoice\Service\Payroll\Document\PayrollArtifact;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKind;
use MyInvoice\Service\Payroll\Document\PayrollDocumentService;
use MyInvoice\Service\Payroll\Document\PayrollDocumentStorageScope;
use PDO;
use PHPUnit\Framework\TestCase;

final class AnnualTaxCertificateServiceTest extends TestCase
{
    public function testAuditFailureRollsBackDatabaseAndNewStorage(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturnOnConsecutiveCalls(
            false,
            true,
        );
        $pdo->expects(self::once())->method('beginTransaction');
        $pdo->expects(self::never())->method('commit');
        $pdo->expects(self::once())->method('rollBack');
        // Jméno zámku nese otisk databáze (NamedLockName), takže atrapa musí
        // odpovědět i na `SELECT DATABASE()` a očekávané jméno se skládá touž cestou.
        $database = 'myucto_unit_test';
        $expectedLock = NamedLockName::compose('payroll-annual-document', $database, '11:21:2026');
        $databaseQuery = $this->createStub(\PDOStatement::class);
        $databaseQuery->method('fetchColumn')->willReturn($database);
        $pdo->method('query')->willReturn($databaseQuery);
        $lock = $this->createMock(\PDOStatement::class);
        $lock->expects(self::once())->method('execute')->with([$expectedLock]);
        $lock->expects(self::once())->method('fetchColumn')->willReturn(1);
        $release = $this->createMock(\PDOStatement::class);
        $release->expects(self::once())->method('execute')->with([$expectedLock]);
        $release->expects(self::once())->method('fetchColumn')->willReturn(1);
        $pdo->expects(self::exactly(2))->method('prepare')
            ->willReturnOnConsecutiveCalls($lock, $release);

        $connection = (new \ReflectionClass(Connection::class))
            ->newInstanceWithoutConstructor();
        $pdoProperty = new \ReflectionProperty(Connection::class, 'pdo');
        $pdoProperty->setValue($connection, $pdo);
        $snapshot = $this->createMock(
            AnnualTaxCertificateSnapshotBuilder::class,
        );
        $data = (new \ReflectionClass(
            AnnualTaxCertificateDocumentData::class,
        ))->newInstanceWithoutConstructor();
        $issuedAt = new \ReflectionProperty(
            AnnualTaxCertificateDocumentData::class,
            'issuedAt',
        );
        $issuedAt->setValue($data, '2026-08-04 10:20:30');
        $snapshot->expects(self::once())
            ->method('build')
            ->willReturn([
                'revision' => ['id' => 71],
                'document' => $data,
            ]);
        $artifact = new PayrollArtifact(
            PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
            '%PDF-1.7 synthetic',
            'application/pdf',
            'tax-certificate-synthetic.pdf',
            str_repeat('a', 64),
            'template-v1',
            'renderer-v1',
        );
        $renderer = $this->createMock(AnnualTaxCertificatePdfRenderer::class);
        $renderer->expects(self::once())
            ->method('render')
            ->with($data)
            ->willReturn($artifact);

        $scope = new PayrollDocumentStorageScope();
        $documents = $this->createMock(PayrollDocumentService::class);
        $documents->expects(self::once())
            ->method('beginStorageScope')
            ->willReturn($scope);
        $documents->expects(self::once())
            ->method('archiveAnnualPdf')
            ->willReturn([
                'id' => 81,
                'annual_revision_id' => 71,
                'document_kind' =>
                    PayrollDocumentKind::TaxableIncomeAdvanceCertificate->value,
                'file_sha256' => str_repeat('b', 64),
                'created_at' => '2026-08-04 10:20:30',
            ]);
        $documents->expects(self::never())->method('commitStorageScope');
        $documents->expects(self::once())
            ->method('cleanupStorageScope')
            ->with(11, $scope);

        $service = new AnnualTaxCertificateService(
            $connection,
            $snapshot,
            $renderer,
            $documents,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('synthetic audit failure');
        $service->generate(
            11,
            21,
            2026,
            PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
            31,
            static function (): never {
                throw new \RuntimeException('synthetic audit failure');
            },
        );
    }

    /**
     * Zámek úložiště nesmí být na celého zaměstnavatele.
     *
     * Držel se přes vykreslení PDF, takže roční potvrzení padesáti lidí se
     * vydávala jedno po druhém a další žádost spadla na desetivteřinovém
     * timeoutu. Dvě různé osoby se proto musí zamykat každá zvlášť.
     */
    public function testStorageLockIsScopedToOnePersonAndYear(): void
    {
        $first = $this->capturedLockName(11, 21, 2026);
        $sameEmployerOtherPerson = $this->capturedLockName(11, 22, 2026);

        self::assertNotSame(
            $first,
            $sameEmployerOtherPerson,
            'Dvě různé osoby téhož zaměstnavatele se nesmí zdržovat navzájem.',
        );
        self::assertStringContainsString('2026', $first, 'Zámek musí rozlišovat i rok.');
        self::assertLessThanOrEqual(
            64,
            strlen($first),
            'Delší jméno zámku MySQL odmítne.',
        );
    }

    /** Spustí generování na atrapách a vrátí jméno, kterým se zamklo úložiště. */
    private function capturedLockName(
        int $supplierId,
        int $employeeId,
        int $taxYear,
    ): string {
        $names = [];
        $pdo = $this->createStub(PDO::class);
        $pdo->method('inTransaction')->willReturn(false);
        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('execute')->willReturnCallback(
            static function (array $params) use (&$names): bool {
                $names[] = $params[0];
                return true;
            },
        );
        $statement->method('fetchColumn')->willReturn(1);
        $pdo->method('prepare')->willReturn($statement);
        // Jméno zámku nese otisk databáze (NamedLockName), takže atrapa musí umět
        // odpovědět i na `SELECT DATABASE()`. Na čem konkrétně test běží, je jedno —
        // jde o tvar jména, ne o obsah otisku.
        $pdo->method('query')->willReturn($statement);

        $connection = (new \ReflectionClass(Connection::class))
            ->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Connection::class, 'pdo'))
            ->setValue($connection, $pdo);

        $snapshot = $this->createStub(AnnualTaxCertificateSnapshotBuilder::class);
        $snapshot->method('build')->willThrowException(
            new \RuntimeException('synthetic stop after lock'),
        );
        $documents = $this->createStub(PayrollDocumentService::class);
        $documents->method('beginStorageScope')
            ->willReturn(new PayrollDocumentStorageScope());

        $service = new AnnualTaxCertificateService(
            $connection,
            $snapshot,
            $this->createStub(AnnualTaxCertificatePdfRenderer::class),
            $documents,
        );

        try {
            $service->generate(
                $supplierId,
                $employeeId,
                $taxYear,
                PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
                31,
            );
        } catch (\RuntimeException) {
            // Zámek se bere ještě před snapshotem — víc než jeho jméno tenhle test nezajímá.
        }

        self::assertNotSame([], $names, 'Úložiště se vůbec nezamklo.');

        return (string) $names[0];
    }
}
