<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Service\Nonprofit\Membership\MembershipBankPaymentGuard;

/**
 * Bankovní pohyb, u kterého se spárování s fakturou nečeká, protože ho uzavírá něco jiného:
 *
 *   - **vlastní převod** — druhá noha leží na jiném vlastním účtu; pozná se podle návrhu
 *     zaúčtování `source = 'transfer'` ({@see \MyInvoice\Service\Accounting\Bank\TransferPairService}),
 *   - **mzdová platba** — visí v `payroll_payment_matches`, stejně jako `payroll_matched`
 *     v {@see \MyInvoice\Service\Payroll\Payment\PayrollBankEvidenceGuard::postingInfo()},
 *   - **pohyb zaúčtovaný mimo saldokontní účty** — platba daně (341/342/343), odvod
 *     pojistného, bankovní poplatek, splátka úvěru, výběr hotovosti. Žádný z nich
 *     fakturu nemá a mít nebude: účetní ho zaúčtovala (ručně nebo automatem) a tím je
 *     vyřízený. Pozná se podle toho, že jeho živý zápis nemá ANI JEDEN řádek na
 *     saldokontním účtu ({@see SALDO_ACCOUNT_PREFIXES}) — dokud zápis neexistuje,
 *     pohyb na spárování pořád čeká.
 *
 * `match_status` jim zůstává `unmatched`, protože ten popisuje jen párování s fakturou.
 * Kdo se ptá „kolik pohybů ještě čeká na spárování", musí je proto vynechat tady —
 * jinak svítí jako nevyřízené v počítadle výpisu i v akcích na úvodní stránce.
 */
final class NonInvoiceBankTransactionScope
{
    /**
     * Účty, na kterých bankovní pohyb POTKÁVÁ doklad: odběratelé, dodavatelé a obě
     * strany záloh. Zápis, který se žádného z nich nedotkne, uzavírá pohyb sám o sobě.
     */
    public const SALDO_ACCOUNT_PREFIXES = ['311', '321', '314', '324'];

    public static function ownTransferSql(int|string $supplierId, string $transactionIdSql): string
    {
        self::validate($supplierId, $transactionIdSql);
        return "EXISTS (
            SELECT 1 FROM bank_posting_suggestions non_invoice_transfer
             WHERE non_invoice_transfer.supplier_id = $supplierId
               AND non_invoice_transfer.bank_transaction_id = $transactionIdSql
               AND non_invoice_transfer.source = 'transfer'
               AND non_invoice_transfer.status IN ('pending', 'approved', 'auto_posted'))";
    }

    /**
     * Pohyb má ŽIVÝ zaúčtovaný zápis, který se nedotkne žádného saldokontního účtu —
     * tedy platbu daně, poplatek, odvod nebo splátku, ne úhradu faktury.
     */
    public static function postedOutsideSaldoSql(int|string $supplierId, string $transactionIdSql): string
    {
        self::validate($supplierId, $transactionIdSql);
        $saldo = implode(' OR ', array_map(
            static fn (string $prefix): string => "non_invoice_acc.account_code LIKE '{$prefix}%'",
            self::SALDO_ACCOUNT_PREFIXES,
        ));

        return "EXISTS (
            SELECT 1 FROM journal_entries non_invoice_je
             WHERE non_invoice_je.supplier_id = $supplierId
               AND non_invoice_je.source_type = 'bank'
               AND non_invoice_je.source_id = $transactionIdSql
               AND non_invoice_je.posted_at IS NOT NULL
               AND non_invoice_je.reversed_by IS NULL
               AND NOT " . self::openCardClearingSql($supplierId, $transactionIdSql) . "
               AND NOT EXISTS (
                   SELECT 1 FROM journal_entry_lines non_invoice_line
                     JOIN chart_of_accounts non_invoice_acc ON non_invoice_acc.id = non_invoice_line.account_id
                    WHERE non_invoice_line.entry_id = non_invoice_je.id
                      AND non_invoice_line.supplier_id = non_invoice_je.supplier_id
                      AND ($saldo)))";
    }

    public static function sql(int|string $supplierId, string $transactionIdSql): string
    {
        self::validate($supplierId, $transactionIdSql);
        return '(' . self::ownTransferSql($supplierId, $transactionIdSql)
            . ' OR ' . self::postedOutsideSaldoSql($supplierId, $transactionIdSql)
            . ' OR ' . self::membershipSql($supplierId, $transactionIdSql) . " OR EXISTS (
            SELECT 1 FROM payroll_payment_matches non_invoice_payroll
             WHERE non_invoice_payroll.supplier_id = $supplierId
               AND non_invoice_payroll.bank_transaction_id = $transactionIdSql))";
    }

    /**
     * Členské úhrady jsou vyřešené i při pohledávkovém účtu ze saldokontního rozsahu.
     * Skalární poddotaz místo EXISTS: semi-join z EXISTS optimalizátor ve stránce a seznamu pohybů řídil
     * přes všechna párování firmy (práce rostla s počtem uhrazených předpisů, ne s velikostí stránky;
     * měření 15. 11.). Korelovaný skalární poddotaz se vyhodnotí po pohybu přes idx_je_supplier_source.
     */
    public static function membershipSql(int|string $supplierId, string $transactionIdSql): string
    {
        self::validate($supplierId, $transactionIdSql);
        // Vnější závorky: volající skládají `NOT ' . membershipSql(), výraz musí být jeden atom jako dřív EXISTS.
        return "((SELECT 1 FROM journal_entries non_invoice_membership FORCE INDEX(idx_je_supplier_source)
            WHERE non_invoice_membership.supplier_id=$supplierId AND non_invoice_membership.source_type='bank'
              AND non_invoice_membership.source_id=$transactionIdSql AND non_invoice_membership.reversed_by IS NULL
              AND " . MembershipBankPaymentGuard::entryUsedSql('non_invoice_membership') . ' LIMIT 1) IS NOT NULL)';
    }

    public static function openCardClearingSql(int|string $supplierId, string $transactionIdSql): string
    {
        self::validate($supplierId, $transactionIdSql);
        return "(EXISTS (
            SELECT 1 FROM journal_entries card_bank
              JOIN journal_entry_lines card_line ON card_line.entry_id = card_bank.id AND card_line.supplier_id = card_bank.supplier_id
              JOIN chart_of_accounts card_acc ON card_acc.id = card_line.account_id AND card_acc.supplier_id = card_bank.supplier_id
             WHERE card_bank.supplier_id = $supplierId AND card_bank.source_type = 'bank'
               AND card_bank.source_id = $transactionIdSql AND card_bank.posted_at IS NOT NULL AND card_bank.reversed_by IS NULL
               AND " . \MyInvoice\Service\Accounting\Card\CardClearingAccounts::codeSql($supplierId, 'card_acc.account_code') . ")
            AND NOT EXISTS (
                SELECT 1 FROM journal_entries card_closed
                 WHERE card_closed.supplier_id = $supplierId AND card_closed.source_id = $transactionIdSql
                   AND card_closed.source_type IN ('card_settlement', 'card_writeoff')
                   AND card_closed.posted_at IS NOT NULL AND card_closed.reversed_by IS NULL))";
    }

    private static function validate(int|string $supplierId, string $transactionIdSql): void
    {
        $reference = '/^[a-zA-Z_][a-zA-Z0-9_]*\.[a-zA-Z_][a-zA-Z0-9_]*$/D';
        if ((is_int($supplierId) ? $supplierId <= 0 : preg_match($reference, $supplierId) !== 1)
            || preg_match($reference, $transactionIdSql) !== 1
        ) {
            throw new \InvalidArgumentException('Neplatný alias bankovního pohybu.');
        }
    }
}
