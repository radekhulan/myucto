<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Predikát „počítá se zálohová faktura do nákladů?" má ZÁMĚRNĚ dvě opačné varianty
 * a tenhle guard je drží každou na svém místě.
 *
 *   - INFORMATIVNÍ pohled (CRM, dashboard Náklady): zaplacená a dosud nespárovaná
 *     záloha do nákladů PATŘÍ — peníze odešly, vyúčtovací faktura ještě nedorazila.
 *     Predikát vylučuje zálohu, když `status <> 'paid'` nebo je spárovaná.
 *   - ÚČETNÍ pohled (měsíční hlavičky seznamu přijatých, klientské souhrny):
 *     zaplacená záloha náklad NEzakládá (sedí na 314), počítá se naopak nezaplacená
 *     jako očekávaný náklad. Predikát vylučuje zálohu, když `status = 'paid'`.
 *
 * Vypadá to jako nekonzistence a svádí to ke „sjednocení", které by ale změnilo
 * vykazované částky na jedné nebo druhé straně. Rozhodnutí je zaznamenané
 * v private/checks/NALEZY.md (N-019); guard hlídá, aby se nepřevrátilo omylem.
 *
 * Zároveň hlídá to, co naopak platit MUSÍ na obou stranách: DDKP (§ 28 ZDPH) není
 * náklad nikdy — účtuje jen odpočet DPH ze zálohy (343/314).
 */
final class AdvanceCostPredicateParityTest extends TestCase
{
    public function testInformationalViewCountsPaidUnsettledAdvance(): void
    {
        foreach ([
            'Service/Crm/CrmAggregationService.php',
            'Action/Dashboard/PurchaseSummaryAction.php',
        ] as $rel) {
            $code = $this->src($rel);
            self::assertStringContainsString(
                "status <> 'paid'",
                $code,
                $rel . ': informativní pohled musí zaplacenou nespárovanou zálohu POČÍTAT '
                    . '(vylučuje ji jen když NENÍ zaplacená). Viz N-019.',
            );
        }
    }

    public function testAccountingViewExcludesPaidAdvance(): void
    {
        $code = $this->src('Repository/PurchaseInvoiceRepository.php');
        self::assertStringContainsString(
            "(pi.document_kind = 'advance' AND (pi.status = 'paid' OR \" . AdvanceSettledSql::purchase('pi')",
            $code,
            'Účetní pohled musí zaplacenou zálohu z nákladů VYLUČOVAT (sedí na 314). Viz N-019.',
        );
    }

    public function testAdvanceVatDocumentIsNeverACostAnywhere(): void
    {
        $missing = [];
        foreach ([
            'Service/Crm/CrmAggregationService.php'      => "<> 'tax_document'",
            'Action/Dashboard/PurchaseSummaryAction.php' => "<> 'tax_document'",
            'Repository/PurchaseInvoiceRepository.php'   => "AND pi.document_kind <> 'tax_document' AND NOT \$excludedAdvance",
        ] as $rel => $needle) {
            if (!str_contains($this->src($rel), $needle)) {
                $missing[] = $rel;
            }
        }

        self::assertSame([], $missing, sprintf(
            "DDKP musí být z nákladů vyloučený VŠUDE (na rozdíl od zálohy) — chybí v:\n  %s",
            implode("\n  ", $missing),
        ));
    }

    /**
     * Zdroj BEZ komentářů a docblocků: hledaný predikát musí být v kódu. Stejný řetězec
     * v komentáři by jinak rozsvítil guard, který nic nekontroluje (AGENTS.md, pravidlo 1).
     */
    private function src(string $relative): string
    {
        return self::withoutComments((string) file_get_contents(dirname(__DIR__, 2) . '/src/' . $relative));
    }

    private static function withoutComments(string $php): string
    {
        $code = '';
        foreach (token_get_all($php) as $t) {
            if (is_array($t)) {
                if ($t[0] !== T_COMMENT && $t[0] !== T_DOC_COMMENT) {
                    $code .= $t[1];
                }
                continue;
            }
            $code .= $t;
        }
        return $code;
    }

    /** Konečná faktura s odpočtem zálohy z DDKP: náklad bez odpočtu, na obou stranách predikátu. */
    public function testFinalInvoiceCostExcludesAdvanceDeductionOnBothViews(): void
    {
        $missing = [];
        foreach ([
            'Service/Crm/CrmAggregationService.php',
            'Action/Dashboard/PurchaseSummaryAction.php',
            'Repository/PurchaseInvoiceRepository.php',
        ] as $rel) {
            if (preg_match('/PurchaseCostSql::deductionJoin(For)?\(/', $this->src($rel)) !== 1) {
                $missing[] = $rel;
            }
        }
        self::assertSame([], $missing, "Náklad konečné faktury musí odečíst odpočet zálohy (PurchaseCostSql) — chybí v:\n  "
            . implode("\n  ", $missing));
    }

    public function testCommentDoesNotSatisfyGuard(): void
    {
        $code = self::withoutComments("<?php\n// status <> 'paid'\n/** <> 'tax_document' */\n\$sql = \"x <> 'y'\";\n");
        self::assertStringNotContainsString("status <> 'paid'", $code);
        self::assertStringNotContainsString("<> 'tax_document'", $code);
        self::assertStringContainsString("x <> 'y'", $code, 'Kód (i řetězce SQL) zůstává.');
    }
}
