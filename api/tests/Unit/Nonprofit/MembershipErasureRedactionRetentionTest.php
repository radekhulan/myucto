<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Nonprofit;

use MyInvoice\Service\Anonymization\AnonymizationPolicy;
use MyInvoice\Service\System\CompanyPurgeService;
use PHPUnit\Framework\TestCase;

/**
 * Otisk redakce auditu (#321, krok 10) je vázaný na původní hash záznamu. Zůstává tam, kde zůstává
 * řetěz (smazání firmy), a mizí tam, kde se řetěz přepočítává (anonymizovaná kopie).
 */
final class MembershipErasureRedactionRetentionTest extends TestCase
{
    public function testCompanyPurgeKeepsRedactionsWithTheRetainedChain(): void
    {
        self::assertContains('activity_log', CompanyPurgeService::RETAINED_TABLES);
        self::assertContains('activity_log_redactions', CompanyPurgeService::RETAINED_TABLES, 'Bez otisků by zachovaný řetěz hlásil redigované záznamy jako změněné.');
    }

    public function testAnonymizedCopyDropsRedactionsBecauseItReseals(): void
    {
        self::assertArrayHasKey('activity_log_redactions', AnonymizationPolicy::TRUNCATE, 'Kopie přepočítá hash auditu; starý otisk by v ní neplatil.');
        self::assertArrayNotHasKey('activity_log_redactions', AnonymizationPolicy::COLUMNS);
    }
}
