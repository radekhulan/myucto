<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Component;

use MyInvoice\Service\Payroll\Component\PayrollComponentJmhzTargetCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use PHPUnit\Framework\TestCase;

final class PayrollComponentJmhzTargetCatalogTest extends TestCase
{
    public function testTargetsDistinguishAggregateOnlyAndDetailAmounts(): void
    {
        $catalog = new PayrollComponentJmhzTargetCatalog(new JmhzSpecPackageCatalog());
        $targets = $catalog->targets();

        self::assertSame(23, count($targets));
        $expectedParents = [
            ['10328', null],
            ['10329', '10328'],
            ['10330', '10328'],
            ['10331', '10328'],
            ['10332', '10328'],
            ['10333', '10332'],
            ['10334', '10332'],
            ['10335', '10332'],
            ['10336', '10332'],
            ['10337', null],
            ['10338', '10337'],
            ['10339', '10337'],
            ['10340', '10337'],
            ['10341', '10337'],
            ['10342', null],
            ['10343', null],
            ['10417', null],
            ['10418', '10417'],
            ['10292', '10417'],
            ['10293', '10417'],
            ['10294', '10417'],
            ['10295', '10417'],
            ['10296', '10417'],
        ];
        foreach ($expectedParents as [$attributeId, $parentId]) {
            self::assertSame($parentId, $catalog->requireTarget($attributeId)['parent_attribute_id']);
        }
        self::assertSame('catch_all_total', $catalog->requireTarget('10328')['aggregation_role']);
        self::assertNull($catalog->requireTarget('10328')['parent_attribute_id']);
        self::assertSame('10328', $catalog->requireTarget('10329')['parent_attribute_id']);
        self::assertSame('detail', $catalog->requireTarget('10329')['aggregation_role']);
        self::assertSame('catch_all_total', $catalog->requireTarget('10332')['aggregation_role']);
        self::assertSame('10332', $catalog->requireTarget('10336')['parent_attribute_id']);
        self::assertSame('catch_all_total', $catalog->requireTarget('10337')['aggregation_role']);
        // 10342 stojí VEDLE úhrnu 10337, ne pod ním: v přijatých hlášeních má
        // měsíc s náhradou jen za nemoc mzdyZuctovane = 0.
        self::assertNull($catalog->requireTarget('10342')['parent_attribute_id']);
        self::assertSame([], $catalog->rollupAttributeIds('10342'));
        self::assertNull($catalog->requireTarget('10343')['parent_attribute_id']);
        // 10417 je úhrn příspěvku zaměstnavatele na produkty spoření na stáří
        // a pojištění dlouhodobé péče, tedy 10418 + 10292 … + 10296.
        self::assertNull($catalog->requireTarget('10417')['parent_attribute_id']);
        self::assertSame('catch_all_total', $catalog->requireTarget('10417')['aggregation_role']);
        self::assertSame('employment', $catalog->requireTarget('10328')['aggregation_scope']);
        self::assertSame(['10332', '10328'], $catalog->rollupAttributeIds('10333'));
        self::assertSame(['10337'], $catalog->rollupAttributeIds('10338'));
        self::assertSame([], $catalog->rollupAttributeIds('10417'));
        // Celý rozpad se roluje do 10417 a vykazuje se v souhrnných datech
        // zaměstnance (jednou za osobu), ne po pracovněprávních vztazích.
        foreach (['10418', '10292', '10293', '10294', '10295', '10296'] as $savingsDetail) {
            self::assertSame('detail', $catalog->requireTarget($savingsDetail)['aggregation_role']);
            self::assertSame(['10417'], $catalog->rollupAttributeIds($savingsDetail));
            self::assertSame(
                'employee_summary',
                $catalog->requireTarget($savingsDetail)['aggregation_scope'],
            );
        }
        self::assertSame('employee_summary', $catalog->requireTarget('10417')['aggregation_scope']);
        foreach ($targets as $target) {
            self::assertSame('číslo', $target['data_type']);
            self::assertSame('x', $target['monthly_marker']);
        }
        foreach (['10344', '10286', 'unknown'] as $unsupported) {
            try {
                $catalog->requireTarget($unsupported);
                self::fail("Atribut {$unsupported} nesmí být cílem mzdové složky.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
