<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\DocumentJournalSync;
use MyInvoice\Service\Validation\InvoiceAmountPolicy;
use MyInvoice\Support\Sql\AdvanceDrawSql;

/**
 * Odpočet zálohy na už vystavené konečné faktuře.
 *
 * Konečná faktura nahraná z PDF nebo vystavená bez odpočtu se dřív dala opravit jen smazáním
 * a novým konceptem. Tady se na vystavené faktuře v otevřeném období provede totéž co na
 * konceptu ({@see AdvancePartialDrawdown}) a v jedné transakci se srovnají následky:
 *
 *   - odpočet téže zálohy jen v hlavičce (`advance_paid_amount` z „Spárovat se zálohou")
 *     nahradí odpočtové řádky, jinak by se záloha odečetla dvakrát,
 *   - stav úhrady odpovídá nové částce k úhradě stejně jako při vystavení
 *     ({@see InvoiceAmountPolicy::shouldAutoMarkPaidOnIssue()}, {@see InvoicePaymentService::recomputeLocked()}),
 *   - aktivní zápis v deníku se přepíše podle opraveného dokladu
 *     ({@see DocumentJournalSync::repostForceEdit()}); nejde-li to, nezmění se nic.
 *
 * Zámek období (podané DPH, uzavřené účetní období) hlídá volající akce stejně jako u konceptu.
 */
final class IssuedInvoiceAdvanceDrawdown
{
    /** Vystavené stavy konečné faktury, na kterých jde odpočet zálohy doplnit. */
    public const STATUSES = ['issued', 'sent', 'reminded', 'paid'];

    public function __construct(
        private readonly Connection $db,
        private readonly AdvancePartialDrawdown $drawdown,
        private readonly DocumentJournalSync $journalSync,
    ) {}

    /**
     * @param array{user_id?:?int, posted_by?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array{proforma_id:int, amount:float, base:float, available:float, remaining:float, reposted_entry_id:?int}
     */
    public function apply(int $supplierId, int $invoiceId, int $proformaId, ?float $amount, ?float $percent, string $basis, array $meta): array
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $row = $this->lockIssued($supplierId, $invoiceId);
            if ((int) ($row['parent_invoice_id'] ?? 0) === $proformaId && !$this->hasDeductionLines($invoiceId)) {
                $pdo->prepare('UPDATE invoices SET advance_paid_amount = 0, parent_invoice_id = NULL WHERE id = ? AND supplier_id = ?')
                    ->execute([$invoiceId, $supplierId]);
            }
            $result = $percent !== null
                ? $this->drawdown->applyPercent($supplierId, $invoiceId, $proformaId, $percent, $basis, true)
                : $this->drawdown->apply($supplierId, $invoiceId, $proformaId, (float) $amount, $basis, true);
            $result['reposted_entry_id'] = $this->resync($supplierId, $invoiceId, $meta);
            if ($own) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Srovná stav úhrady a zápis v deníku vystavené faktury po změně odpočtu zálohy
     * (čerpání, spárování, odpárování). Volat v transakci volajícího. Propojení se zálohou
     * stav úhrady záměrně nemění ({@see \MyInvoice\Action\Invoice\LinkAdvanceAction}),
     * proto `$syncStatus = false`.
     *
     * @param array{user_id?:?int, posted_by?:?int, ip?:?string, user_agent?:?string} $meta
     * @return ?int ID přepsaného zápisu, null když doklad zaúčtovaný není
     */
    public function resync(int $supplierId, int $invoiceId, array $meta, bool $syncStatus = true): ?int
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT id, invoice_type, status, parent_invoice_id, amount_to_pay, issue_date, tax_date, varsymbol, paid_at
               FROM invoices WHERE id = ? AND supplier_id = ? FOR UPDATE'
        );
        $stmt->execute([$invoiceId, $supplierId]);
        $inv = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($inv === false || !in_array((string) $inv['status'], self::STATUSES, true)) {
            return null;
        }
        $items = $pdo->prepare('SELECT advance_invoice_id FROM invoice_items WHERE invoice_id = ? AND advance_invoice_id IS NOT NULL');
        $items->execute([$invoiceId]);
        $inv['items'] = $items->fetchAll(\PDO::FETCH_ASSOC);
        if ($syncStatus) {
            if (!InvoiceAmountPolicy::shouldAutoMarkPaidOnIssue($inv)) {
                InvoicePaymentService::recomputeLocked($pdo, $invoiceId);
            } elseif ((string) $inv['status'] !== 'paid') {
                $pdo->prepare("UPDATE invoices SET status = 'paid', paid_at = ? WHERE id = ?")
                    ->execute([(string) $inv['issue_date'], $invoiceId]);
            }
        }

        $date = (string) ($inv['tax_date'] ?? $inv['issue_date']);
        return $this->journalSync->repostForceEdit($supplierId, 'invoice', $invoiceId, [
            'entry_date'    => $date,
            'document_date' => $date,
            'document_no'   => (string) ($inv['varsymbol'] ?? ''),
            'user_id'       => $meta['user_id'] ?? null,
            'posted_by'     => $meta['posted_by'] ?? ($meta['user_id'] ?? null),
            'ip'            => $meta['ip'] ?? null,
            'user_agent'    => $meta['user_agent'] ?? null,
        ]);
    }

    /** @return array<string,mixed> */
    private function lockIssued(int $supplierId, int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, invoice_type, status, parent_invoice_id, import_total_with_vat_czk
               FROM invoices WHERE id = ? AND supplier_id = ? FOR UPDATE'
        );
        $stmt->execute([$invoiceId, $supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \DomainException('Faktura nenalezena.');
        }
        if ((string) $row['invoice_type'] !== 'invoice' || !in_array((string) $row['status'], self::STATUSES, true)) {
            throw new \DomainException('Zálohu lze odečíst jen na konceptu nebo vystavené běžné faktuře.');
        }
        if ($row['import_total_with_vat_czk'] !== null) {
            throw new \DomainException('Na převzatém dokladu s částkami ze zdroje nelze změnit čerpání zálohy.');
        }
        return $row;
    }

    private function hasDeductionLines(int $invoiceId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM invoice_items ii WHERE ii.invoice_id = ? AND ' . AdvanceDrawSql::deductionLine('ii') . ' LIMIT 1'
        );
        $stmt->execute([$invoiceId]);
        return $stmt->fetchColumn() !== false;
    }
}
