<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Reports;

use MyInvoice\Service\Accounting\Reports\{DeferredTax504Statement, Statement504Presentation, Statement504XlsxExporter};
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

final class DeferredTax504PresentationTest extends TestCase
{
    public static function report(): array
    {
        return ['accounting_framework' => 'cz_504', 'statement_type' => 'income_statement', 'scope' => 'full', 'as_of' => '2097-06-30',
            'period' => ['starts_on' => '2096-07-01', 'ends_on' => '2097-06-30'],
            'deferred_tax_method' => ['version' => '504-deferred-own-v1', 'reference' => 'SYN-METHOD', 'faithful_image_reason' => 'Synthetic future effect',
                'standard_departure_reason' => 'Synthetic recoverable asset', 'presentation_reason' => 'Synthetic separate disclosure', 'disclosure' => DeferredTax504Statement::DISCLOSURE],
            'rows' => [['row_code' => 'UT.38', 'display_code' => 'A.VIII.29', 'label' => 'Daň z příjmů', 'main' => 0.0, 'economic' => 1000.02, 'total' => 1000.02,
                'components' => [['key' => 'current_tax', 'label' => 'Splatná daň z příjmů', 'main' => 0.0, 'economic' => 500.01, 'total' => 500.01],
                    ['key' => 'deferred_tax', 'label' => 'Odložená daň podle vlastní metody', 'main' => 0.0, 'economic' => 500.01, 'total' => 500.01]]]]];
    }

    public function testComponentsRemainSeparateAndReconcileAlsoAfterThousandsRounding(): void
    {
        foreach (['czk', 'thousands'] as $unit) {
            $view = Statement504Presentation::build(self::report(), $unit);
            $rows = $view['tables'][0]['rows'];
            self::assertCount(3, $rows);
            self::assertSame('Splatná daň z příjmů', $rows[1][1]);
            self::assertSame('Odložená daň podle vlastní metody', $rows[2][1]);
            self::assertSame($rows[0][4], $rows[1][4] + $rows[2][4]);
            self::assertStringContainsString('SYN-METHOD', $view['deferred_tax_note']);
        }
    }

    public function testXlsxContainsNumericComponentsAndMethodDisclosure(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'deferred504_');
        try {
            file_put_contents($path, (new Statement504XlsxExporter())->export(self::report())['bytes']);
            $book = IOFactory::load($path);
            $rows = $book->getActiveSheet()->toArray(null, false, false);
            $found = array_values(array_filter($rows, static fn (array $r): bool => ($r[1] ?? '') === 'Odložená daň podle vlastní metody'));
            self::assertCount(1, $found);
            self::assertSame(500.01, $found[0][4]);
            self::assertStringContainsString('SYN-METHOD', json_encode($rows));
        } finally { if (isset($book)) $book->disconnectWorksheets(); unlink($path); }
    }
}
