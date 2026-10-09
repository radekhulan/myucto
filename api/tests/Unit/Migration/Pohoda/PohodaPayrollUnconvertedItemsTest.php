<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollCatalog;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollConverter;
use PHPUnit\Framework\TestCase;

/**
 * Položka katalogu PAMICA, kterou převod nezná, se do sešitu nedostane, ale nesmí
 * zmizet beze stopy: měsíc ji vrací a převod ji vypíše varováním s kódem, názvem,
 * počtem vstupů a částkou. Vědomě vynechané položky (měsíční mzda nese vlastní
 * sloupec, nepřítomnost s daty jde do evidence nepřítomností) se nehlásí.
 *
 * Syntetická data, žádné reálné doklady ani osoby.
 */
final class PohodaPayrollUnconvertedItemsTest extends TestCase
{
    private string $tmp = '';
    private string $file = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_unconverted_' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/12345678_2026', 0755, true);
        $this->file = $this->tmp . '/12345678_2026/91_mzdy.xml';
        file_put_contents($this->file, PohodaPayrollMealAllowanceAndSickDaysTest::payrollXml());
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    public function testUnknownCatalogItemsAreReportedInsteadOfDropped(): void
    {
        $unconverted = $this->month()['unconverted_items'] ?? null;

        self::assertIsArray($unconverted);
        self::assertSame(
            ['code' => 'Z20', 'name' => 'Osvobozené příjmy', 'kind' => 'component', 'inputs' => 2, 'amount' => 1500.0, 'hours' => 0.0],
            $unconverted['component:Z20'] ?? null,
        );
        self::assertSame(
            ['code' => 'V99', 'name' => 'Firemní volno', 'kind' => 'absence', 'inputs' => 1, 'amount' => 800.0, 'hours' => 8.0],
            $unconverted['absence:V99'] ?? null,
        );
        // Měsíční mzda (M01) jde vlastním sloupcem, rodičovská (H08) datovaně z MZneprit.
        self::assertCount(2, $unconverted);
    }

    public function testWarningNamesCodeInputsAndAmount(): void
    {
        $message = PohodaPayrollConverter::unconvertedItemsMessage([$this->month(), $this->month()]);

        self::assertNotNull($message);
        self::assertStringContainsString('Z20 Osvobozené příjmy (4 vstupy, 3 000,00 Kč)', $message);
        self::assertStringContainsString('V99 Firemní volno (2 vstupy, 1 600,00 Kč, 16 h)', $message);
        self::assertNull(PohodaPayrollConverter::unconvertedItemsMessage([['unconverted_items' => []]]));
    }

    /**
     * Rodičovská bez dat by nešla ani do sešitu, ani do evidence nepřítomností:
     * taková doba se taky hlásí.
     */
    public function testDatedOnlyAbsenceWithoutDatesIsReported(): void
    {
        $xml = str_replace(
            '<DatZac>2026-03-01</DatZac><DatKon>2026-03-31</DatKon>',
            '',
            PohodaPayrollMealAllowanceAndSickDaysTest::payrollXml(),
        );
        file_put_contents($this->file, $xml);

        $unconverted = $this->month()['unconverted_items'];

        self::assertSame(1, $unconverted['absence:H08']['inputs'] ?? null);
        self::assertSame(15.0, $unconverted['absence:H08']['hours'] ?? null);
    }

    /** Vědomě vynechaná položka katalogu má význam `ignore`, neznámá `unknown`. */
    public function testCatalogDistinguishesDeliberateIgnoreFromUnknown(): void
    {
        self::assertSame('ignore', PohodaPayrollCatalog::component('M01', 'Základní mzda', false)['meaning']);
        self::assertSame('ignore', PohodaPayrollCatalog::component('M09', 'Základní mzda zkrácený úvazek', false)['meaning']);
        self::assertSame('unknown', PohodaPayrollCatalog::component('Z20', 'Osvobozené příjmy', false)['meaning']);
        self::assertSame('unknown', PohodaPayrollCatalog::absence('V99', 'Firemní volno')['meaning']);
        self::assertSame('ignore', PohodaPayrollCatalog::deduction(['Cislo' => 'S01', 'Nazev' => 'Exekuce', 'JeZak' => '1'])['meaning']);
    }

    /** @return array<string,mixed> */
    private function month(): array
    {
        return PohodaPayrollConverter::read($this->file)->month('2026-03');
    }
}
