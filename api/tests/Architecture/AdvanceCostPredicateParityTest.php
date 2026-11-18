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

    private function src(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/src/' . $relative);
    }
}
