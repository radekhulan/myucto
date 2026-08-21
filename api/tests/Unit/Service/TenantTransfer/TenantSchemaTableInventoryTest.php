<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaTableInventory;
use PHPUnit\Framework\TestCase;

final class TenantSchemaTableInventoryTest extends TestCase
{
    public function testEnumValuesAreKeptByColumn(): void
    {
        $inventory = new TenantSchemaTableInventory(
            'document_links',
            'BASE TABLE',
            ['document_id', 'entity_type'],
            ['document_id', 'entity_type'],
            [],
            [['document_id', 'entity_type']],
            [],
            ['entity_type' => ['client', 'invoice']],
        );

        self::assertSame(
            ['entity_type' => ['client', 'invoice']],
            $inventory->enumValues,
        );
    }

    public function testEnumValuesRejectUnknownColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'ENUM hodnoty inventury odkazují na neznámý sloupec.',
        );

        new TenantSchemaTableInventory(
            'document_links',
            'BASE TABLE',
            ['document_id', 'entity_type'],
            ['document_id', 'entity_type'],
            [],
            [['document_id', 'entity_type']],
            [],
            ['ghost_type' => ['client']],
        );
    }

    public function testEnumValuesRejectDuplicates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Inventura obsahuje neplatný seznam ENUM hodnot.',
        );

        new TenantSchemaTableInventory(
            'document_links',
            'BASE TABLE',
            ['document_id', 'entity_type'],
            ['document_id', 'entity_type'],
            [],
            [['document_id', 'entity_type']],
            [],
            ['entity_type' => ['client', 'client']],
        );
    }
}
