<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TenantTransfer;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\TenantTransfer\Fingerprint\MariaDbTenantSchemaMetadataSource;
use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaTableInventory;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistryCoverageValidator;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class TenantSchemaInventoryTest extends TestCase
{
    public function testDocumentLinkEnumValuesAreExposedForCoverage(): void
    {
        $connection = Bootstrap::buildContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        $tables = [];
        foreach (
            (new MariaDbTenantSchemaMetadataSource($connection))->inventory()
            as $table
        ) {
            $tables[$table->name] = $table;
        }

        self::assertArrayHasKey('document_links', $tables);
        self::assertInstanceOf(
            TenantSchemaTableInventory::class,
            $tables['document_links'],
        );
        self::assertSame(
            [
                'client',
                'invoice',
                'purchase_invoice',
                'project',
                'journal_entry',
                'bank_transaction',
            ],
            $tables['document_links']->enumValues['entity_type'] ?? null,
        );

        $connection->close();
    }

    public function testRegisteredDraftDefinitionsMatchCurrentSchema(): void
    {
        $connection = Bootstrap::buildContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $inventory = (new MariaDbTenantSchemaMetadataSource($connection))
            ->inventory();
        $issues = (new TenantDataRegistryCoverageValidator())->issues(
            TenantDataRegistryFactory::draftV1(),
            $inventory,
        );
        $unexpected = array_values(array_filter(
            $issues,
            static fn (string $issue): bool => !str_starts_with(
                $issue,
                'unregistered_table:',
            ),
        ));

        self::assertSame([], $unexpected);
        self::assertNotSame([], $issues, 'Draft registr zatím nesmí být úplný.');

        $connection->close();
    }
}
