<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Vat\VatStatusService;

/**
 * Daňové brány vystavení vydaného dokladu (§ 29, § 30, § 108 ZDPH).
 *
 * Jediné místo pro pravidla, která rozhodují, jestli koncept smí vzniknout jako daňový
 * doklad: vystavení z editoru ({@see \MyInvoice\Action\Invoice\IssueInvoiceAction})
 * i rychlá účtenka ({@see \MyInvoice\Service\Mobile\QuickReceiptService}) se ptají
 * tady. Dřív žily jen uvnitř akce, takže každá další cesta vystavení by je musela
 * opsat a první rozdíl by se ukázal až ve výkazu.
 *
 * Každá brána vrací `null` (doklad smí projít), nebo odmítnutí s kódem, hláškou
 * a HTTP stavem. Koncept smí být neúplný; blokuje se až vystavení.
 */
final class InvoiceIssueTaxGate
{
    public function __construct(
        private readonly Connection $db,
        private readonly TaxConstantsRepository $taxConstants,
        private readonly VatStatusService $vatStatus,
    ) {}

    /**
     * Rozhodné datum dokladu pro plátcovství DPH: DUZP (tax_date), jinak issue_date.
     * U dobropisu se tax_date při vystavení přepisuje datem doručení (§ 42 odst. 3),
     * rozhodné datum ho proto zohledňuje už předem.
     *
     * @param array<string,mixed> $invoice
     */
    public static function vatStatusDate(array $invoice): string
    {
        return ($invoice['invoice_type'] ?? '') === 'credit_note' && !empty($invoice['corrective_delivered_on'])
            ? (string) $invoice['corrective_delivered_on']
            : (string) (($invoice['tax_date'] ?? null) ?: $invoice['issue_date']);
    }

    /**
     * § 29 odst. 2 ZDPH: doklad v režimu přenesené daňové povinnosti musí nést DIČ
     * odběratele, jinak plnění z kontrolního hlášení tiše vypadne.
     *
     * @param array<string,mixed> $invoice
     * @return array{code:string, message:string, status:int}|null
     */
    public function reverseChargeRejection(array $invoice): ?array
    {
        if (empty($invoice['reverse_charge'])) {
            return null;
        }
        $dicStmt = $this->db->pdo()->prepare('SELECT dic FROM clients WHERE id = ?');
        $dicStmt->execute([(int) ($invoice['client_id'] ?? 0)]);
        if (KontrolniHlaseniBuilder::cleanDic((string) ($dicStmt->fetchColumn() ?: '')) !== '') {
            return null;
        }
        return self::rejection(
            'reverse_charge_dic_missing',
            'Doklad v režimu přenesené daňové povinnosti musí nést DIČ odběratele '
                . '(§ 29 odst. 2 ZDPH). Bez něj plnění nelze uvést v kontrolním hlášení. '
                . 'Doplňte DIČ u odběratele, nebo režim přenesené povinnosti vypněte.',
        );
    }

    /**
     * Koncový zákazník bez údajů smí být odběratelem jen zjednodušeného dokladu (§ 30).
     * Běžný daňový doklad musí nést jméno a adresu odběratele (§ 29 odst. 1 písm. c).
     *
     * @param array<string,mixed> $invoice
     * @return array{code:string, message:string, status:int}|null
     */
    public function walkInRejection(array $invoice): ?array
    {
        if (!empty($invoice['is_simplified'])) {
            return null;
        }
        $walkInStmt = $this->db->pdo()->prepare('SELECT is_walk_in FROM clients WHERE id = ?');
        $walkInStmt->execute([(int) ($invoice['client_id'] ?? 0)]);
        if ((int) $walkInStmt->fetchColumn() !== 1) {
            return null;
        }
        return self::rejection(
            'walk_in_customer_requires_simplified',
            'Doklad bez odběratele lze vystavit jen jako zjednodušený daňový doklad (§ 30 ZDPH). '
                . 'Vyberte odběratele, nebo zaškrtněte zjednodušený daňový doklad.',
        );
    }

    /**
     * § 30 odst. 1 a 2 ZDPH: limit zjednodušeného dokladu a jeho výjimky
     * ({@see SimplifiedDocumentPolicy}).
     *
     * @param array<string,mixed> $invoice hlavička se `total_with_vat`, `reverse_charge`, `vat_classification_code`
     * @return array{code:string, message:string, status:int}|null
     */
    public function simplifiedRejection(array $invoice): ?array
    {
        if (empty($invoice['is_simplified'])) {
            return null;
        }
        $reason = SimplifiedDocumentPolicy::rejectionReason(
            $invoice,
            $this->dphdp3Line($invoice),
            $this->simplifiedLimit($invoice),
        );
        return $reason === null ? null : self::rejection('simplified_document_not_allowed', $reason);
    }

    /**
     * Smí doklad vzniknout jako zjednodušený? Totéž pravidlo jako
     * {@see simplifiedRejection()}, jen bez příznaku na dokladu: rychlá účtenka se
     * podle něj rozhoduje, který doklad vystaví.
     *
     * @param array<string,mixed> $invoice
     */
    public function allowsSimplified(array $invoice): bool
    {
        return $this->simplifiedRejection(['is_simplified' => 1] + $invoice) === null;
    }

    /**
     * Neplátce nesmí vystavit doklad s DPH (§ 108 odst. 4 ZDPH). Rozhoduje stav
     * k rozhodnému datu dokladu, ne dnešní cache. Reverse charge se nekontroluje:
     * RC řádky daň nenesou.
     *
     * @param array<string,mixed> $invoice
     * @return array{code:string, message:string, status:int}|null
     */
    public function vatPayerRejection(array $invoice): ?array
    {
        $date = self::vatStatusDate($invoice);
        if ((float) ($invoice['total_vat'] ?? 0) <= 0
            || !empty($invoice['reverse_charge'])
            || $this->vatStatus->isVatPayerAt((int) $invoice['supplier_id'], $date)
        ) {
            return null;
        }
        return self::rejection(
            'not_vat_payer_at_date',
            sprintf(
                'Firma není k rozhodnému datu dokladu (%s) plátcem DPH — doklad s DPH nelze vystavit. '
                    . 'Přepněte položky na sazbu 0 %%, nebo opravte datum zdanitelného plnění.',
                date('j. n. Y', (int) strtotime($date)),
            ),
        );
    }

    /** @param array<string,mixed> $invoice */
    private function dphdp3Line(array $invoice): ?string
    {
        $lineStmt = $this->db->pdo()->prepare(
            'SELECT dphdp3_line FROM vat_classifications
              WHERE code = ? AND (supplier_id = ? OR supplier_id IS NULL)
              ORDER BY supplier_id IS NULL LIMIT 1'
        );
        $lineStmt->execute([
            (string) ($invoice['vat_classification_code'] ?? ''),
            (int) $invoice['supplier_id'],
        ]);
        $line = $lineStmt->fetchColumn();
        return $line === false || $line === null ? null : (string) $line;
    }

    /** @param array<string,mixed> $invoice */
    private function simplifiedLimit(array $invoice): float
    {
        $docYear = (int) substr((string) (($invoice['tax_date'] ?? null) ?: $invoice['issue_date']), 0, 4);
        return (float) ($this->taxConstants->forYear($docYear)['simplified_document_limit_with_vat']
            ?? SimplifiedDocumentPolicy::LIMIT_WITH_VAT);
    }

    /** @return array{code:string, message:string, status:int} */
    private static function rejection(string $code, string $message, int $status = 422): array
    {
        return ['code' => $code, 'message' => $message, 'status' => $status];
    }
}
