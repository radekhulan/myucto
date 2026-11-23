<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Report\VatLedgerService;
use PHPUnit\Framework\TestCase;

/**
 * Rozhodné datum nároku na odpočet (§ 73 ZDPH) — jediný zdroj pravdy.
 *
 * `VatLedgerService::purchaseClaimDateExpr()` je kanonický výraz. `MonthlyExportService`
 * měl vlastní kopii s komentářem „Shodné s VatLedgerService", která shodná NEBYLA —
 * chyběla jí:
 *
 *   - větev `received_at_source = 'manual'` (§ 73/1: nárok nejdřív v měsíci, kdy
 *     byl doklad doručen),
 *   - rozpoznání reverse charge podle `vat_classification_code` a podle POLOŽEK,
 *     ne jen podle hlavičkového flagu (issue #117).
 *
 * Na ostrých datech kvůli tomu **2 z 248 dokladů** padly v exportu do jiného měsíce
 * než v podaném přiznání — podklady pro účetní tedy neseděly na to, co odešlo na FÚ.
 * Přesně ten druh chyby, který nikdo nenajde, dokud se na něj někdo nezeptá.
 *
 * Komentář „shodné s X" je tvrzení bez záruky. Tenhle test ho mění na kontrolu.
 */
final class VatClaimDateSsotTest extends TestCase
{
    private const EXPORT_SERVICE = 'Service/Export/MonthlyExportService.php';

    /** Export musí SSOT volat, ne kopírovat. */
    public function testMonthlyExportUsesSharedClaimDateExpression(): void
    {
        $code = $this->src(self::EXPORT_SERVICE);

        self::assertStringContainsString(
            'VatLedgerService::purchaseClaimDateExpr()',
            $code,
            'Export musí použít sdílený výraz — vlastní kopie se už jednou rozešla.',
        );
        self::assertStringNotContainsString(
            "WHEN pi.tax_date >= pi.issue_date THEN pi.tax_date ELSE pi.issue_date END",
            $code,
            'Vlastní kopie kaskády se do exportu vrátila.',
        );
    }

    /**
     * Charakterizace SSOT: obě větve, o které se kopie lišila, ve výrazu musí zůstat.
     * Kdyby je někdo z kanonického výrazu odstranil, rozdíl by se „vyřešil" tím,
     * že se zhorší i správná strana.
     */
    public function testCanonicalExpressionKeepsBothDistinguishingBranches(): void
    {
        $expr = VatLedgerService::purchaseClaimDateExpr();

        self::assertStringContainsString(
            "received_at_source = 'manual'",
            $expr,
            '§ 73/1 — nárok nejdřív v měsíci doručení dokladu.',
        );
        self::assertStringContainsString(
            'purchase_invoice_items',
            $expr,
            'Reverse charge se pozná i podle položek, ne jen podle hlavičky (#117).',
        );
        self::assertStringContainsString(
            "vat_classification_code IN ('23','24','24e','25')",
            $expr,
            'Reverse charge se pozná i podle klasifikačního kódu.',
        );
    }

    /**
     * Převod z cizího systému potřebuje totéž pravidlo dřív, než je doklad v databázi
     * ({@see VatLedgerService::purchaseClaimFollowsTaxDate()}). Kódy sdílí s SQL výrazem
     * konstanta a PHP větev rozhoduje stejně: přenesení příznakem nebo kódem hlavičky či
     * položky a dodavatel mimo CZ, chybějící země je CZ jako `COALESCE(co.iso2, 'CZ')`.
     */
    public function testPhpPredicateMatchesForeignReverseChargeBranch(): void
    {
        $expr = VatLedgerService::purchaseClaimDateExpr();
        self::assertStringContainsString("IN ('" . implode("','", VatLedgerService::FOREIGN_REVERSE_CHARGE_CODES) . "')", $expr);
        self::assertStringContainsString("COALESCE(co.iso2, 'CZ') <> 'CZ'", $expr);

        self::assertTrue(VatLedgerService::purchaseClaimFollowsTaxDate(true, null, [], 'DE'));
        self::assertTrue(VatLedgerService::purchaseClaimFollowsTaxDate(false, null, [null, '24e'], 'DE'));
        self::assertTrue(VatLedgerService::purchaseClaimFollowsTaxDate(false, '25', [], 'US'));
        self::assertFalse(VatLedgerService::purchaseClaimFollowsTaxDate(true, null, ['24e'], 'CZ'));
        self::assertFalse(VatLedgerService::purchaseClaimFollowsTaxDate(true, null, [], null));
        self::assertFalse(VatLedgerService::purchaseClaimFollowsTaxDate(false, null, ['40', '5', null], 'DE'), 'Tuzemské přenesení (kód 5) se řadí podle § 73.');
    }

    /**
     * Výraz je psaný pro aliasy `pi` a `co`. Volající, který je nemá, dostane až
     * SQL chybu za běhu — proto se kontroluje, že je export v dotazu opravdu zavádí.
     */
    public function testExportQueryProvidesRequiredAliases(): void
    {
        $code = $this->src(self::EXPORT_SERVICE);

        self::assertStringContainsString('FROM purchase_invoices pi', $code);
        self::assertStringContainsString('countries co', $code);
    }

    private function src(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/src/' . $relative);
    }
}
