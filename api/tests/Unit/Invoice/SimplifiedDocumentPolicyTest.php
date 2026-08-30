<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Invoice;

use MyInvoice\Service\Invoice\SimplifiedDocumentPolicy;
use PHPUnit\Framework\TestCase;

/**
 * § 30 a § 30a ZDPH — zjednodušený daňový doklad.
 *
 * Institut, který systém neznal vůbec, přestože se vystavuje při každém pokladním prodeji.
 *
 * Testy míří hlavně na VÝJIMKY (§ 30 odst. 2). Ty totiž nejsou formalita: u dodání do
 * jiného členského státu a u přenesené daňové povinnosti odběratel své identifikační
 * údaje na dokladu POTŘEBUJE — bez nich se plnění nedá vykázat v souhrnném ani kontrolním
 * hlášení. Špatně zvolený zjednodušený doklad tedy rozbije výkazy, ne jen náležitosti.
 */
final class SimplifiedDocumentPolicyTest extends TestCase
{
    /** Běžný pokladní prodej do limitu — právě pro tohle institut existuje. */
    public function testSmallDomesticSaleIsAllowed(): void
    {
        self::assertNull(SimplifiedDocumentPolicy::rejectionReason(
            ['total_with_vat' => 4_500.0, 'reverse_charge' => 0],
            '1',
        ));
    }

    /**
     * Limit se posuzuje z částky VČETNĚ daně. Doklad se základem 9 000 Kč je s 21%
     * daní na 10 890 Kč — tedy nad limitem, přestože základ pod ním pohodlně je.
     */
    public function testLimitIsJudgedFromAmountIncludingVat(): void
    {
        $reason = SimplifiedDocumentPolicy::rejectionReason(
            ['total_with_vat' => 10_890.0, 'reverse_charge' => 0],
            '1',
        );

        self::assertNotNull($reason);
        self::assertStringContainsString('§ 30 odst. 1', $reason);
    }

    /** Přesně na limitu ještě lze — zákon říká „není vyšší než". */
    public function testExactlyAtLimitIsAllowed(): void
    {
        self::assertNull(SimplifiedDocumentPolicy::rejectionReason(
            ['total_with_vat' => 10_000.0, 'reverse_charge' => 0],
            '1',
        ));
    }

    /**
     * Přenesená daňová povinnost — zakázáno, i když je doklad pod limitem. Bez DIČ
     * odběratele plnění vypadne z kontrolního hlášení.
     */
    public function testReverseChargeIsForbiddenEvenBelowLimit(): void
    {
        $reason = SimplifiedDocumentPolicy::rejectionReason(
            ['total_with_vat' => 1_000.0, 'reverse_charge' => 1],
            '25',
        );

        self::assertNotNull($reason);
        self::assertStringContainsString('kontrolním hlášení', $reason);
    }

    /** Dodání zboží do jiného členského státu (ř. 20) — zakázáno kvůli souhrnnému hlášení. */
    public function testEuGoodsSupplyIsForbidden(): void
    {
        $reason = SimplifiedDocumentPolicy::rejectionReason(
            ['total_with_vat' => 5_000.0, 'reverse_charge' => 0],
            '20',
        );

        self::assertNotNull($reason);
        self::assertStringContainsString('§ 30 odst. 2', $reason);
    }

    /**
     * Vývoz do 3. země (ř. 22) mezi výjimkami NENÍ. Dopisovat zákazy nad rámec § 30
     * odst. 2 by uživateli bránilo v něčem, co mu zákon dovoluje.
     */
    public function testExportIsNotAmongTheExceptions(): void
    {
        self::assertNull(SimplifiedDocumentPolicy::rejectionReason(
            ['total_with_vat' => 5_000.0, 'reverse_charge' => 0],
            '22',
        ));
    }

    /** Neznámá klasifikace zákaz nezakládá — rozhoduje jen limit a režim. */
    public function testUnknownClassificationOnlyLimitApplies(): void
    {
        self::assertNull(SimplifiedDocumentPolicy::rejectionReason(
            ['total_with_vat' => 500.0, 'reverse_charge' => 0],
            null,
        ));
    }

    /** Dobropis se posuzuje podle absolutní částky, ne podle znaménka. */
    public function testCreditNoteIsJudgedByAbsoluteAmount(): void
    {
        self::assertNotNull(SimplifiedDocumentPolicy::rejectionReason(
            ['total_with_vat' => -12_000.0, 'reverse_charge' => 0],
            '1',
        ));
    }

    /** Limit se čte z roční sady (volající ho předá) — literál je jen fallback default. */
    public function testLimitIsTakenFromExplicitParameterNotLiteral(): void
    {
        self::assertNull(SimplifiedDocumentPolicy::rejectionReason(
            ['total_with_vat' => 15_000.0, 'reverse_charge' => 0],
            '1',
            20_000.0,
        ));

        $reason = SimplifiedDocumentPolicy::rejectionReason(
            ['total_with_vat' => 5_000.0, 'reverse_charge' => 0],
            '1',
            4_000.0,
        );
        self::assertNotNull($reason);
        self::assertStringContainsString('4 000', $reason);
    }
}
