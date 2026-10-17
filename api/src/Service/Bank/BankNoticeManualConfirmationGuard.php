<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\TaxEvidence\TaxEvidenceYearLock;
use PDO;

/** Ruční přesun bankovní vazby nesmí měnit podklady uzavřeného období. */
final class BankNoticeManualConfirmationGuard
{
    public function __construct(private readonly Connection $db) {}

    /** @param array<string,mixed> $notice @param array<string,mixed> $target */
    public function assertAllowed(int $supplierId, array $notice, array $target): void
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) throw new \LogicException('Kontrola ručního potvrzení vyžaduje transakci.');

        $dates = [(string) $notice['posted_at'], (string) $target['posted_at']];
        $payments = $pdo->prepare('SELECT paid_on FROM invoice_payments WHERE supplier_id=? AND bank_transaction_id IN (?,?) FOR UPDATE');
        $payments->execute([$supplierId, (int) $notice['id'], (int) $target['id']]);
        $dates = array_values(array_unique(array_merge($dates, $payments->fetchAll(PDO::FETCH_COLUMN))));
        sort($dates);

        $mode = $pdo->prepare('SELECT accounting_mode FROM supplier WHERE id=?');
        $mode->execute([$supplierId]);
        $doubleEntry = $mode->fetchColumn() === 'double_entry';
        $periods = new AccountingPeriodRepository($this->db);
        foreach ($dates as $date) {
            if ($doubleEntry) {
                // Stejný řádkový zámek a deterministický výběr období jako PostingService.
                $period = $periods->findForDateForUpdate($supplierId, $date);
                if ($period !== null && $period['status'] !== 'open') {
                    throw new BankNoticeConfirmationException('period_not_open', 'Ruční potvrzení zasahuje do uzavřeného účetního období.', 409);
                }
            } elseif (TaxEvidenceYearLock::isFinal($this->db, $supplierId, (int) substr($date, 0, 4), true)) {
                throw new BankNoticeConfirmationException(TaxEvidenceYearLock::ERROR_CODE, TaxEvidenceYearLock::message((int) substr($date, 0, 4)), 409);
            }
        }
        $settings = $pdo->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id=? FOR UPDATE');
        $settings->execute([$supplierId]);
        $lockedUntil = $settings->fetchColumn();
        if (is_string($lockedUntil) && $dates[0] <= $lockedUntil) {
            throw new BankNoticeConfirmationException('date_locked', 'Datum avíza, výpisu nebo úhrady spadá do zamčeného období.', 409);
        }

        // Importní evidence ukazuje na stejný kanonický bank_transaction_id. Zápis
        // hledáme podle tohoto ID, nikoli podle vlastníka právě otevřeného výpisu.
        $types = implode(',', array_fill(0, count(BankTransactionReleaseService::TRANSACTION_SOURCE_TYPES), '?'));
        $journal = $pdo->prepare("SELECT id FROM journal_entries WHERE supplier_id=? AND source_type IN ($types) AND source_id=? AND posted_at IS NOT NULL AND reversed_by IS NULL FOR UPDATE");
        $journal->execute([$supplierId, ...BankTransactionReleaseService::TRANSACTION_SOURCE_TYPES, (int) $target['id']]);
        if ($journal->fetchColumn() !== false) {
            throw new BankNoticeConfirmationException('target_posted', 'Pohyb výpisu je již zaúčtovaný. Nejdříve vyřešte jeho zápis v účetním deníku.', 409);
        }
    }
}
