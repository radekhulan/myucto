<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Export;

use MyInvoice\Service\Payroll\Export\PayrollPeriodExportArchiveBuilder;
use MyInvoice\Service\Payroll\Export\PayrollPeriodExportEntry;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class PayrollPeriodExportArchiveBuilderTest extends TestCase
{
    public function testBuildsByteStableArchiveWithCanonicalManifestAndSafeNames(): void
    {
        $builder = new PayrollPeriodExportArchiveBuilder();
        $data = [
            'scope' => 'monthly',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'revisions' => [
                ['id' => 20, 'result_snapshot_hash' => str_repeat('b', 64)],
                ['id' => 10, 'result_snapshot_hash' => str_repeat('a', 64)],
            ],
        ];
        $entries = [
            new PayrollPeriodExportEntry(
                'protocols/test/jmhz-protocol-000020.xml',
                '<protocol synthetic="true"/>',
                'application/xml',
                'submission_protocol',
                20,
            ),
            new PayrollPeriodExportEntry(
                'documents/document-000010.pdf',
                '%PDF-1.4 synthetic',
                'application/pdf',
                'payroll_document',
                10,
            ),
        ];

        $first = $builder->build($data, $entries);
        $second = $builder->build($data, array_reverse($entries));

        self::assertSame($first['bytes'], $second['bytes']);
        self::assertSame($first['file_sha256'], $second['file_sha256']);
        self::assertSame(
            hash('sha256', $first['bytes']),
            $first['file_sha256'],
        );
        self::assertMatchesRegularExpression(
            '/^mzdy-2026-08-[a-f0-9]{12}\.zip$/D',
            $first['suggested_filename'],
        );

        $temporary = tempnam(sys_get_temp_dir(), 'payroll-export-');
        self::assertIsString($temporary);
        file_put_contents($temporary, $first['bytes']);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($temporary, ZipArchive::RDONLY) === true);
        self::assertSame([
            'data/payroll.json',
            'documents/document-000010.pdf',
            'protocols/test/jmhz-protocol-000020.xml',
            'manifest.json',
            'CHECKSUMS.txt',
            'CTI-MNE.txt',
        ], $this->entryNames($zip));
        $manifestBytes = $zip->getFromName('manifest.json');
        self::assertIsString($manifestBytes);
        $manifest = json_decode(
            $manifestBytes,
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($manifest);
        self::assertSame('myucto-payroll-period-export', $manifest['format']);
        self::assertSame(1, $manifest['version']);
        self::assertSame('monthly', $manifest['scope']);
        self::assertSame([10, 20], $manifest['source_revision_ids']);
        self::assertSame(
            hash('sha256', (string) $zip->getFromName('data/payroll.json')),
            $manifest['entries']['data/payroll.json']['sha256'],
        );
        self::assertArrayNotHasKey('created_at', $manifest);
        $manifestJson = json_encode($manifest, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('synthetic', $manifestJson);
        self::assertStringNotContainsString('password', strtolower($manifestJson));
        $zip->close();
        unlink($temporary);
    }

    public function testRejectsUnsafeOrDuplicateEntryNames(): void
    {
        $builder = new PayrollPeriodExportArchiveBuilder();
        $data = [
            'scope' => 'annual',
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'revisions' => [['id' => 1]],
        ];

        foreach (['../secret', '/absolute.pdf', 'documents\\bad.pdf'] as $name) {
            try {
                $builder->build($data, [
                    new PayrollPeriodExportEntry(
                        $name,
                        'x',
                        'application/octet-stream',
                        'payroll_document',
                        1,
                    ),
                ]);
                self::fail('Nebezpečný název položky musí být odmítnut.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        $builder->build($data, [
            new PayrollPeriodExportEntry(
                'documents/document-000001.pdf',
                'a',
                'application/pdf',
                'payroll_document',
                1,
            ),
            new PayrollPeriodExportEntry(
                'documents/document-000001.pdf',
                'b',
                'application/pdf',
                'payroll_document',
                2,
            ),
        ]);
    }

    public function testFailsClosedWhenSnapshotContainsCredentialField(): void
    {
        foreach (['credentials', 'application_password', 'api_key', 'token'] as $field) {
            try {
                (new PayrollPeriodExportArchiveBuilder())->build([
                    'scope' => 'monthly',
                    'period_start' => '2026-08-01',
                    'period_end' => '2026-08-31',
                    'revisions' => [[
                        'id' => 1,
                        'result_snapshot_json' => [
                            $field => 'must-not-export',
                        ],
                    ]],
                ], []);
                self::fail('Citlivé pole musí být odmítnuto.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        new PayrollPeriodExportEntry(
            'documents/document-000001.pdf',
            'x',
            'application/pdf',
            'unapproved_category',
            1,
        );
    }

    public function testRejectsCategoryOutsideItsArchiveDirectory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PayrollPeriodExportEntry(
            'protocols/test/document-000001.pdf',
            'x',
            'application/pdf',
            'payroll_document',
            1,
        );
    }

    /** @return list<string> */
    private function entryNames(ZipArchive $zip): array
    {
        $names = [];
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $name = $zip->getNameIndex($index);
            self::assertIsString($name);
            $names[] = $name;
        }

        return $names;
    }
}
