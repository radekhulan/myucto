<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollJmhzReports;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollPeople;
use PHPUnit\Framework\TestCase;

/**
 * Sleva pracujícího důchodce z PAMICA se bere z podaného hlášení (10490), ne z příznaku
 * slevy zaměstnavatele na kartě. Sleva důchodce u osoby, která důchod mít nemůže, se
 * nepřevezme a dostane příznak k ověření. Syntetická data.
 */
final class PohodaPayrollSubmittedDiscountTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_discount_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp);
    }

    public function testPensionerDiscountComesFromSubmittedReport(): void
    {
        $effective = PohodaPayrollJmhzReports::effective(PohodaPayrollJmhzReports::read($this->export(), 2026));
        $records = PohodaPayrollPeople::withSubmittedDiscounts([
            ['relation_key' => '1', 'pensioner' => true, 'birth_date' => '1960-01-15', 'pensioner_discounts' => []],
            ['relation_key' => '2', 'pensioner' => false, 'birth_date' => '1995-06-01', 'pensioner_discounts' => [['status' => 'not_claimed']]],
            ['relation_key' => '3', 'pensioner' => false, 'birth_date' => '1990-01-01', 'pensioner_discounts' => [['status' => 'not_claimed', 'from' => '2026-01-01', 'to' => null, 'period' => '2026-01']]],
        ], $effective, 2026);

        self::assertSame([
            ['from' => '2026-01-01', 'to' => '2026-01-31', 'status' => 'not_claimed', 'period' => '2026-01'],
            ['from' => '2026-02-01', 'to' => null, 'status' => 'verified', 'period' => '2026-02'],
        ], $records[0]['pensioner_discounts'], 'Důchodce: sleva podle podaného hlášení po měsících.');
        self::assertSame([], $records[1]['pensioner_discounts'], 'Sleva důchodce u osoby, která důchod mít nemůže, se nepřebírá.');
        self::assertTrue($records[1]['pensioner_discount_doubtful'] ?? false);
        self::assertSame('not_claimed', $records[2]['pensioner_discounts'][0]['status'], 'Bez hlášení zůstává stav z karty.');
        self::assertArrayNotHasKey('pensioner_discount_doubtful', $records[2]);
    }

    private function export(): string
    {
        $file = $this->tmp . '/mzdy.xml';
        $form = static function (string $guid, string $ppv, string $discount): string {
            $out = '';
            foreach ([[1, 'bezPriznaku'], [10012, $guid], [10016, 'R'], [10495, 'A'], [10051, '1234567895'], [10228, $ppv],
                [10053, 'Zkušební'], [10054, 'Osoba'], [10056, '1.1.1960'], [10223, '1.1.2025'], [10239, '1'], [10490, $discount]] as [$id, $value]) {
                $out .= '<a id="' . $id . '" t="0" f="1">' . $value . '</a>';
            }
            return $out;
        };
        $header = static fn (int $id, int $month): string => "<MH><ID>{$id}</ID><Rok>2026</Rok><RelMesic>{$month}</RelMesic><RelTyp>1</RelTyp>"
            . '<RelStavDP>7</RelStavDP><ElOdeslano>1</ElOdeslano><DatPod>2026-0' . ($month + 1) . '-15T09:00:00</DatPod></MH>';
        $item = static fn (int $id, int $parent, int $relation, string $body): string => "<MHitems><ID>{$id}</ID><RefAg>{$parent}</RefAg>"
            . "<RefZAM>{$relation}</RefZAM><RefPomer>{$relation}</RefPomer><Data v=\"1\">{$body}</Data></MHitems>";
        file_put_contents($file, '<?xml version="1.0" encoding="UTF-8"?><mdbExport>'
            . $header(1, 1) . $header(2, 2)
            . $item(1, 1, 1, $form('11111111-1111-4111-8111-111111111111', '1111111111111', 'N'))
            . $item(2, 2, 1, $form('22222222-2222-4222-8222-222222222222', '1111111111111', 'A'))
            . $item(3, 2, 2, $form('33333333-3333-4333-8333-333333333333', '2222222222222', 'A'))
            . '</mdbExport>');

        return $file;
    }
}
