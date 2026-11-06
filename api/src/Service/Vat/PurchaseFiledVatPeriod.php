<?php

declare(strict_types=1);

namespace MyInvoice\Service\Vat;

use MyInvoice\Service\Report\VatLedgerService;

/**
 * Spadá přijatá faktura do podaného období DPH (přiznání nebo kontrolní hlášení)?
 *
 * Protějšek kontroly vystavených faktur ({@see FiledVatPeriodLookup} v
 * {@see \MyInvoice\Action\Invoice\UpdateInvoiceAction}). Přijatá faktura vstupuje do
 * evidence DPH obdobím odpočtu (§ 73, {@see VatLedgerService::purchaseClaimDateExpr()}),
 * ne datem vystavení, proto se ptá na to. OSS přiznání se přijatých dokladů netýká.
 */
final class PurchaseFiledVatPeriod
{
    public function __construct(
        private readonly FiledVatPeriodLookup $lookup,
        private readonly VatLedgerService $ledger,
    ) {}

    /**
     * Popis podaného výkazu pokrývajícího období odpočtu dokladu (případně další data,
     * kam ho úprava přesouvá), nebo `null`.
     *
     * @param list<?string> $extraDates
     */
    public function describe(int $supplierId, int $purchaseInvoiceId, array $extraDates = []): ?string
    {
        $info = $this->ledger->purchaseClaimInfo($supplierId, [$purchaseInvoiceId])[$purchaseInvoiceId] ?? null;
        $dates = [];
        foreach ([$info['claim_date'] ?? null, ...$extraDates] as $date) {
            $date = substr(trim((string) $date), 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
                $dates[$date] = true;
            }
        }
        foreach (array_keys($dates) as $date) {
            if (($filed = $this->lookup->describe($supplierId, $date, false)) !== null) {
                return $filed;
            }
        }

        return null;
    }

    /** Období odpočtu dokladu (YYYY-MM-DD) nebo `null`. */
    public function claimDate(int $supplierId, int $purchaseInvoiceId): ?string
    {
        $date = $this->ledger->purchaseClaimInfo($supplierId, [$purchaseInvoiceId])[$purchaseInvoiceId]['claim_date'] ?? null;

        return $date !== null && $date !== '' ? substr((string) $date, 0, 10) : null;
    }

    public static function message(string $filed): string
    {
        return 'Období odpočtu dokladu už bylo podáno (' . $filed . '). Změnu, která se promítne do evidence DPH, '
            . 'v něm může provést jen správce firmy; opravu podaného období řešte opravným nebo dodatečným tvrzením.';
    }
}
