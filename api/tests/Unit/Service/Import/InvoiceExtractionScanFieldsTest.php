<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\InvoiceExtractionPrompt;
use PHPUnit\Framework\TestCase;

/**
 * Pole pro přiřazení skenu k existujícímu dokladu jsou součástí extrakčního
 * kontraktu všech providerů (prompt i strict JSON schéma).
 */
final class InvoiceExtractionScanFieldsTest extends TestCase
{
    private const FIELDS = ['barcode', 'license_plate', 'card_last4', 'company_role'];

    public function testJsonSchemaRequiresScanFields(): void
    {
        $schema = InvoiceExtractionPrompt::invoiceJsonSchema();
        foreach (self::FIELDS as $field) {
            self::assertArrayHasKey($field, $schema['properties']);
            self::assertContains($field, $schema['required']);
        }
        self::assertSame(['buyer', 'vendor', 'both', 'none', null], $schema['properties']['company_role']['enum']);
        self::assertSame(['string', 'null'], $schema['properties']['customer']['properties']['ic']['type'], 'odběratel zůstává v customer');
    }

    public function testPromptDescribesScanFields(): void
    {
        $prompt = InvoiceExtractionPrompt::invoiceSystem();
        foreach (self::FIELDS as $field) {
            self::assertStringContainsString('"' . $field . '"', $prompt, "schéma v promptu uvádí {$field}");
        }
        self::assertStringContainsString(InvoiceExtractionPrompt::scanFieldRules(), $prompt);
        self::assertStringContainsString('NIKDY celé číslo karty', InvoiceExtractionPrompt::scanFieldRules());
    }

    public function testRoleNeutralContextDoesNotForceCustomer(): void
    {
        $ctx = InvoiceExtractionPrompt::roleNeutralContext('název "Vlastní firma s.r.o.", IČO "12345678"');

        self::assertStringContainsString('odběratel (přijatý doklad) i dodavatel (vydaný doklad)', $ctx);
        self::assertStringContainsString('company_role', $ctx);
        self::assertStringNotContainsString('VŽDY odběratel', $ctx);
    }
}
