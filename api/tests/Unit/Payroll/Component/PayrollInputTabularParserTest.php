<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Component;

use MyInvoice\Service\Payroll\Component\PayrollInputTabularParser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class PayrollInputTabularParserTest extends TestCase
{
    public function testCsvSupportsSemicolonAndKeepsRowErrorsSeparate(): void
    {
        $csv = implode("\n", [
            'employment_id;employment_code;component_code;amount_minor;external_id',
            '10;HPP-1;BONUS;25000;ext-1',
            '10;HPP-1;BONUS',
        ]);

        $parsed = (new PayrollInputTabularParser())->parse('csv', $csv);

        self::assertCount(1, $parsed['rows']);
        self::assertCount(1, $parsed['errors']);
        self::assertSame(2, $parsed['rows'][0]['row_number']);
        self::assertSame('column_count', $parsed['errors'][0]['error_code']);
    }

    public function testXlsxReadsStaticValues(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['employment_id', 'employment_code', 'component_code', 'amount_minor', 'external_id'],
            [10, 'HPP-1', 'BONUS', 25000, 'ext-1'],
        ]);
        $content = $this->xlsx($spreadsheet);

        $parsed = (new PayrollInputTabularParser())->parse('xlsx', $content);

        self::assertCount(1, $parsed['rows']);
        self::assertSame('25000', $parsed['rows'][0]['amount_minor']);
    }

    public function testXlsxRejectsFormulaInsteadOfEvaluatingIt(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['employment_id', 'employment_code', 'component_code', 'amount_minor', 'external_id'],
            [10, 'HPP-1', 'BONUS', '=10000+15000', 'ext-1'],
        ]);
        $content = $this->xlsx($spreadsheet);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('vzorec');
        (new PayrollInputTabularParser())->parse('xlsx', $content);
    }

    public function testXlsxSupportsAttendanceColumnsThroughSharedParser(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([
            ['employment_code', 'starts_at', 'ends_at', 'timezone', 'category', 'break_minutes', 'external_id'],
            ['HPP-1', '2026-05-04T08:00:00+02:00', '2026-05-04T16:00:00+02:00', 'Europe/Prague', 'regular', 30, 'time-1'],
        ]);

        $parsed = (new PayrollInputTabularParser())->parse(
            'xlsx',
            $this->xlsx($spreadsheet),
            ['employment_code', 'starts_at', 'ends_at', 'timezone', 'category', 'external_id'],
        );

        self::assertCount(1, $parsed['rows']);
        self::assertSame('HPP-1', $parsed['rows'][0]['employment_code']);
        self::assertSame('30', $parsed['rows'][0]['break_minutes']);
    }

    public function testImportRejectsFileOverFiveMegabytesBeforeParsing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('5 MB');
        (new PayrollInputTabularParser())->parse('csv', str_repeat('x', 5_000_001));
    }

    public function testXlsxRejectsCompressedArchiveExpandingOverSafetyLimit(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([
            ['employment_id', 'employment_code', 'component_code', 'amount_minor', 'external_id'],
            [10, 'HPP-1', 'BONUS', 25000, 'ext-1'],
        ]);
        $content = $this->xlsx($spreadsheet);
        $tmp = tempnam(sys_get_temp_dir(), 'payroll-zip-bomb-');
        if ($tmp === false) {
            throw new \RuntimeException('Nelze vytvořit syntetický XLSX.');
        }
        try {
            file_put_contents($tmp, $content, LOCK_EX);
            $zip = new ZipArchive();
            self::assertTrue($zip->open($tmp) === true);
            self::assertTrue($zip->addFromString(
                'xl/worksheets/compressed-padding.bin',
                str_repeat('0', 25_000_000),
            ));
            $zip->close();
            $expanded = file_get_contents($tmp);
            self::assertIsString($expanded);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Rozbalený XLSX');
            (new PayrollInputTabularParser())->parse('xlsx', $expanded);
        } finally {
            @unlink($tmp);
        }
    }

    public function testXlsxRejectsExternalOrActiveWorkbookParts(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([
            ['employment_id', 'employment_code', 'component_code', 'amount_minor', 'external_id'],
            [10, 'HPP-1', 'BONUS', 25000, 'ext-1'],
        ]);
        $tmp = tempnam(sys_get_temp_dir(), 'payroll-active-xlsx-');
        if ($tmp === false) {
            throw new \RuntimeException('Nelze vytvořit syntetický XLSX.');
        }
        try {
            file_put_contents($tmp, $this->xlsx($spreadsheet), LOCK_EX);
            $zip = new ZipArchive();
            self::assertTrue($zip->open($tmp) === true);
            self::assertTrue($zip->addFromString(
                'xl/externalLinks/externalLink1.xml',
                '<externalLink/>',
            ));
            $zip->close();
            $active = file_get_contents($tmp);
            self::assertIsString($active);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('aktivní nebo externí prvky');
            (new PayrollInputTabularParser())->parse('xlsx', $active);
        } finally {
            @unlink($tmp);
        }
    }

    private function xlsx(Spreadsheet $spreadsheet): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'payroll-input-test-');
        if ($tmp === false) {
            throw new \RuntimeException('Nelze vytvořit syntetický XLSX.');
        }
        try {
            (new Xlsx($spreadsheet))->save($tmp);
            $content = file_get_contents($tmp);
            if ($content === false) {
                throw new \RuntimeException('Syntetický XLSX nelze načíst.');
            }
            return $content;
        } finally {
            $spreadsheet->disconnectWorksheets();
            @unlink($tmp);
        }
    }
}
