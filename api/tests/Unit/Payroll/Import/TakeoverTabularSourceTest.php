<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import;

use MyInvoice\Service\Payroll\Import\Takeover\TakeoverTabularImportService;
use PHPUnit\Framework\TestCase;

final class TakeoverTabularSourceTest extends TestCase
{
    public function testFeederOnlyStereoNxSourceIsRejectedBeforeParsing(): void
    {
        $service = (new \ReflectionClass(TakeoverTabularImportService::class))->newInstanceWithoutConstructor();

        foreach (['preview', 'apply'] as $operation) {
            try {
                $service->{$operation}(1, 'stereo_nx', 'csv', 'synteticke.csv', '');
                self::fail('Ruční import přijal zdroj vyhrazený pro čtečku Stereo NX.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Zdroj Stereo NX lze převzít jen ze zálohy Stereo NX.', $e->getMessage());
            }
        }
    }
}
