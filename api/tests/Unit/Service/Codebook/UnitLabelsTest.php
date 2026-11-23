<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Codebook;

use MyInvoice\Service\Codebook\UnitLabels;
use PHPUnit\Framework\TestCase;

final class UnitLabelsTest extends TestCase
{
    private const MAP = ['lekce_55' => 'Lekce (55 min)', 'ks' => 'kus'];

    public function testCodeIsReplacedByLabel(): void
    {
        self::assertSame('Lekce (55 min)', UnitLabels::label(self::MAP, 'lekce_55'));
    }

    public function testCodeIsMatchedRegardlessOfCaseAndSurroundingSpaces(): void
    {
        self::assertSame('kus', UnitLabels::label(self::MAP, ' KS '));
    }

    public function testUnknownUnitKeepsTheCode(): void
    {
        self::assertSame('KT', UnitLabels::label(self::MAP, 'KT'));
        self::assertSame('', UnitLabels::label(self::MAP, ''));
    }
}
