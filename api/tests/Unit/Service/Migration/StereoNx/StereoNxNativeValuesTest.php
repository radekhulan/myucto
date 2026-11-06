<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\StereoNx\StereoNxBackup;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticNx1Archive;
use PHPUnit\Framework\TestCase;

final class StereoNxNativeValuesTest extends TestCase
{
    public function testNativeBooleansAndNullBitmapRemainDistinct(): void
    {
        $rows = [
            ['Danovy' => true, 'Text' => 'Synthetic', 'Number' => 7, 'Amount' => 1.25],
            ['Danovy' => false, 'Text' => '', 'Number' => 0, 'Amount' => 0.0],
            ['Danovy' => null, 'Text' => null, 'Number' => null, 'Amount' => null],
        ];
        $this->assertRows($rows);
    }

    public function testNullBitmapCrossesByteAndWordBoundaries(): void
    {
        $values = $nulls = [];
        for ($index = 0; $index < 67; $index++) {
            $values['Flag' . $index] = $index % 2 === 0;
            $nulls['Flag' . $index] = in_array($index, [0, 7, 8, 31, 32, 63, 64, 66], true) ? null : false;
        }
        $this->assertRows([$values, $nulls]);
    }

    /** @param list<array<string,mixed>> $rows */
    private function assertRows(array $rows): void
    {
        $path = tempnam(sys_get_temp_dir(), 'stereo_native_');
        try {
            SyntheticNx1Archive::write($path, ['Rows' => $rows, 'Empty' => []],
                ['ico' => '00000000', 'dic' => 'CZ00000000', 'name' => 'Synthetic', 'vat_payer' => true]);
            $backup = StereoNxBackup::open($path, 0);
            self::assertSame($rows, iterator_to_array($backup->rows('Rows'), false));
            self::assertSame([], iterator_to_array($backup->rows('Empty'), false));
            self::assertSame(['ok', 'ok'], array_column($backup->inventory(), 'status'));
        } finally {
            unlink($path);
        }
    }
}
