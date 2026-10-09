<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\GoPay;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use PDO;

/**
 * Zaúčtování jednoho GoPay pohybu (source_type 'gopay', source_id = gopay_movements.id).
 *
 * Jediné místo, které z pohybu staví účetní zápis: pohyby z importu vyúčtování
 * i čekající pohyby založené úhradou faktury v den platby. Samostatná služba bez
 * závislosti na evidenci úhrad, aby ji evidence úhrad mohla volat.
 */
final class GoPayMovementPoster
{
    use GoPayUnitOfWork;

    public function __construct(
        private readonly Connection $db,
        private readonly PostingService $posting,
        private readonly JournalEntryRepository $journal,
    ) {}

    public function post(int $supplierId, int $movementId, ?int $userId): void
    {
        $pdo = $this->db->pdo();
        $ownTx = $this->beginUnit($pdo, 'gopay_movement');
        try {
            $stmt = $pdo->prepare(
                'SELECT gm.*,gc.clearing_id provider_clearing_id,COALESCE(gc.currency,gm.currency) currency,
                        gs.gopay_account_id,gs.receivable_account_id,gs.fee_account_id,gs.clearing_account_id
                   FROM gopay_movements gm
              LEFT JOIN gopay_clearings gc ON gc.id=gm.clearing_id AND gc.supplier_id=gm.supplier_id
                   JOIN gopay_settings gs ON gs.supplier_id=gm.supplier_id AND gs.currency=COALESCE(gc.currency,gm.currency)
                  WHERE gm.id=? AND gm.supplier_id=? FOR UPDATE'
            );
            $stmt->execute([$movementId, $supplierId]);
            $movement = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($movement)) {
                throw new GoPayException('movement_not_found', 'GoPay pohyb nebyl nalezen.', 404);
            }
            if (!$this->isDoubleEntry($supplierId)) {
                $this->linkForTaxEvidence($supplierId, $movement);
                $this->commitUnit($pdo, $ownTx, 'gopay_movement');
                return;
            }
            if ($movement['status'] === 'posted' && $movement['journal_entry_id'] !== null) {
                $existing = $this->journal->find((int) $movement['journal_entry_id'], $supplierId);
                if (is_array($existing) && ($existing['reversed_by'] ?? null) === null) {
                    $this->commitUnit($pdo, $ownTx, 'gopay_movement');
                    return;
                }
            }

            $accounts = $this->accountsById($supplierId, [
                (int) $movement['gopay_account_id'], (int) $movement['receivable_account_id'],
                (int) $movement['fee_account_id'], (int) $movement['clearing_account_id'],
            ]);
            $gopay = $accounts[(int) $movement['gopay_account_id']]['account_code'];
            $receivable = $accounts[(int) $movement['receivable_account_id']]['account_code'];
            $fee = $accounts[(int) $movement['fee_account_id']]['account_code'];
            $clearing = $accounts[(int) $movement['clearing_account_id']]['account_code'];
            $amount = number_format(abs((float) $movement['amount']), 2, '.', '');

            $links = ['invoice_id' => null, 'invoice_payment_id' => null, 'credit_note_id' => null];
            [$debit, $credit, $description] = match ((string) $movement['movement_type']) {
                'credit' => $this->creditPosting($supplierId, $movement, $gopay, $receivable, $links),
                'storno' => $this->stornoPosting($supplierId, $movement, $receivable, $gopay, $links),
                'storno_fee' => [$fee, $gopay, 'Poplatek GoPay za vratku'],
                'clearing_fee' => [$fee, $gopay, 'Poplatek GoPay za vyúčtování a zpracování plateb'],
                'fee_credit' => [$gopay, $fee, 'Dobropis poplatků GoPay'],
                'payout' => [$clearing, $gopay, 'Převod vyúčtování GoPay na běžný účet'],
                default => throw new GoPayException('unsupported_movement', 'Nepodporovaný typ GoPay pohybu.'),
            };

            $entryId = $this->posting->postDocument($supplierId, 'gopay', $movementId, [
                ['account_code' => $debit, 'side' => 'debit', 'amount' => $amount],
                ['account_code' => $credit, 'side' => 'credit', 'amount' => $amount],
            ], [
                'entry_date' => (string) $movement['performed_on'],
                'document_date' => (string) $movement['performed_on'],
                'document_no' => $movement['provider_clearing_id'] !== null
                    ? 'GP-' . $movement['provider_clearing_id'] . '-' . $movementId
                    : 'GP-' . $movementId,
                'description' => $description,
                'posted' => true,
                'posted_by' => $userId,
                'user_id' => $userId,
            ]);

            $pdo->prepare(
                'UPDATE gopay_movements
                    SET invoice_id=?,invoice_payment_id=?,credit_note_id=?,journal_entry_id=?,
                        status="posted",issue_code=NULL,issue_message=NULL,processed_at=NOW()
                  WHERE id=? AND supplier_id=?'
            )->execute([
                $links['invoice_id'], $links['invoice_payment_id'], $links['credit_note_id'],
                $entryId, $movementId, $supplierId,
            ]);
            $this->commitUnit($pdo, $ownTx, 'gopay_movement');
        } catch (\Throwable $e) {
            $this->rollbackUnit($pdo, $ownTx, 'gopay_movement');
            $status = $e instanceof GoPayException ? 'unmatched' : 'error';
            $code = $e instanceof GoPayException ? $e->errorCode
                : ($e instanceof PostingException ? $e->errorCode : 'processing_failed');
            $message = mb_substr($e->getMessage(), 0, 500);
            $pdo->prepare(
                'UPDATE gopay_movements SET status=?,issue_code=?,issue_message=?,processed_at=NOW()
                  WHERE id=? AND supplier_id=? AND status<>"posted"'
            )->execute([$status, $code, $message, $movementId, $supplierId]);
        }
    }

    /**
     * Daňová evidence nemá zápisy: pohyb se jen spáruje s úhradou faktury nebo dobropisem
     * a označí jako zpracovaný. Peněžní deník pak čte příjem z úhrady faktury (ke dni
     * inkasa), vratku, poplatky a výplatu přímo z pohybů vyúčtování.
     *
     * @param array<string,mixed> $movement
     */
    private function linkForTaxEvidence(int $supplierId, array $movement): void
    {
        $movementId = (int) $movement['id'];
        $links = ['invoice_id' => null, 'invoice_payment_id' => null, 'credit_note_id' => null];
        try {
            switch ((string) $movement['movement_type']) {
                case 'credit':
                    $match = $this->matchInvoicePayment($supplierId, $movement);
                    $duplicate = $this->db->pdo()->prepare(
                        'SELECT 1 FROM gopay_movements
                          WHERE supplier_id=? AND invoice_payment_id=? AND id<>?
                            AND movement_type="credit" AND status="posted" LIMIT 1'
                    );
                    $duplicate->execute([$supplierId, (int) $match['payment_id'], $movementId]);
                    if ($duplicate->fetchColumn() !== false) {
                        throw new GoPayException('payment_already_posted', 'Úhrada faktury je už spárovaná s jiným GoPay pohybem.');
                    }
                    $links['invoice_id'] = (int) $match['invoice_id'];
                    $links['invoice_payment_id'] = (int) $match['payment_id'];
                    break;
                case 'storno':
                    $links['credit_note_id'] = (int) $this->matchCreditNote($supplierId, $movement)['id'];
                    break;
                case 'storno_fee':
                case 'clearing_fee':
                case 'fee_credit':
                case 'payout':
                    break;
                default:
                    throw new GoPayException('unsupported_movement', 'Nepodporovaný typ GoPay pohybu.');
            }
        } catch (GoPayException $e) {
            $this->db->pdo()->prepare(
                'UPDATE gopay_movements
                    SET invoice_id=IF(origin="payment",invoice_id,NULL),
                        invoice_payment_id=IF(origin="payment",invoice_payment_id,NULL),
                        credit_note_id=NULL,
                        status="unmatched",issue_code=?,issue_message=?,processed_at=NOW()
                  WHERE id=? AND supplier_id=?'
            )->execute([$e->errorCode, mb_substr($e->getMessage(), 0, 500), $movementId, $supplierId]);
            return;
        }

        $this->db->pdo()->prepare(
            'UPDATE gopay_movements
                SET invoice_id=?,invoice_payment_id=?,credit_note_id=?,journal_entry_id=NULL,
                    status="posted",issue_code=NULL,issue_message=NULL,processed_at=NOW()
              WHERE id=? AND supplier_id=?'
        )->execute([
            $links['invoice_id'], $links['invoice_payment_id'], $links['credit_note_id'],
            $movementId, $supplierId,
        ]);
    }

    private function isDoubleEntry(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id=?');
        $stmt->execute([$supplierId]);
        return $stmt->fetchColumn() === 'double_entry';
    }

    /**
     * Zápisy GoPay pohybů jde smazat jen v otevřeném a nezamčeném období a jen
     * bez storna. Sdílí mazání vyúčtování i mazání úhrady s čekajícím pohybem.
     *
     * @param list<int> $entryIds
     */
    public function assertEntriesRemovable(int $supplierId, array $entryIds, string $subject): void
    {
        if ($entryIds === []) {
            return;
        }
        $pdo = $this->db->pdo();
        $placeholders = implode(',', array_fill(0, count($entryIds), '?'));
        $entries = $pdo->prepare(
            'SELECT je.id,je.entry_date,je.reversed_by,ap.status period_status,
                    EXISTS(SELECT 1 FROM journal_entries original
                            WHERE original.supplier_id=je.supplier_id
                              AND original.reversed_by=je.id) is_reversal
               FROM journal_entries je
               JOIN accounting_periods ap ON ap.id=je.period_id AND ap.supplier_id=je.supplier_id
              WHERE je.supplier_id=? AND je.id IN (' . $placeholders . ')
              FOR UPDATE'
        );
        $entries->execute(array_merge([$supplierId], $entryIds));
        $locked = $pdo->prepare(
            'SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id=? FOR UPDATE'
        );
        $locked->execute([$supplierId]);
        $lockedUntil = $locked->fetchColumn();
        foreach ($entries->fetchAll(PDO::FETCH_ASSOC) as $entry) {
            if ((string) $entry['period_status'] !== 'open') {
                throw new GoPayException(
                    'period_not_open',
                    $subject . ' obsahuje účetní zápis v uzavřeném období.',
                    409,
                );
            }
            if ($lockedUntil !== false && $lockedUntil !== null
                && (string) $entry['entry_date'] <= (string) $lockedUntil) {
                throw new GoPayException(
                    'date_locked',
                    $subject . ' obsahuje účetní zápis v uzamčené části účetnictví.',
                    409,
                );
            }
            if ($entry['reversed_by'] !== null || (bool) $entry['is_reversal']) {
                throw new GoPayException(
                    'entry_has_reversal',
                    $subject . ' obsahuje stornovaný účetní zápis. Nejprve vyřeš jeho storno v deníku.',
                    409,
                );
            }
        }
    }

    /** @param list<int> $ids @return array<int,array<string,mixed>> */
    public function accountsById(int $supplierId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT id,account_code,name,account_type,is_active,is_synthetic FROM chart_of_accounts
              WHERE supplier_id=? AND id IN (' . $placeholders . ')'
        );
        $stmt->execute(array_merge([$supplierId], $ids));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['id']] = $row;
        }
        if (count($out) !== count($ids)) {
            throw new GoPayException('account_not_found', 'Některý zvolený účet nepatří této firmě.');
        }
        return $out;
    }

    /** @param array<string,mixed> $movement @param array<string,?int> $links @return array{string,string,string} */
    private function creditPosting(int $supplierId, array $movement, string $gopay, string $receivable, array &$links): array
    {
        $match = $this->matchInvoicePayment($supplierId, $movement);
        $this->assertPaymentNotPostedElsewhere($supplierId, (int) $match['payment_id'], (int) $movement['id']);
        $links['invoice_id'] = (int) $match['invoice_id'];
        $links['invoice_payment_id'] = (int) $match['payment_id'];
        $reference = trim((string) ($movement['order_id'] ?? '')) !== ''
            ? (string) $movement['order_id'] : (string) $movement['payment_session_id'];
        return [$gopay, $receivable, 'GoPay úhrada faktury ' . (string) $match['varsymbol'] . ' (' . $reference . ')'];
    }

    /**
     * Úhrada faktury smí být v deníku zaúčtovaná jediným GoPay pohybem. Pohyb
     * z vyúčtování, který se k již zaúčtované úhradě dostane jinou cestou než
     * převzetím čekajícího pohybu (např. přes číslo objednávky), by jinak snížil
     * pohledávku podruhé.
     */
    private function assertPaymentNotPostedElsewhere(int $supplierId, int $paymentId, int $movementId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM gopay_movements gm
               JOIN journal_entries je ON je.id=gm.journal_entry_id AND je.supplier_id=gm.supplier_id
              WHERE gm.supplier_id=? AND gm.invoice_payment_id=? AND gm.id<>?
                AND gm.movement_type="credit" AND je.reversed_by IS NULL
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $paymentId, $movementId]);
        if ($stmt->fetchColumn() !== false) {
            throw new GoPayException('payment_already_posted', 'Úhrada faktury je už zaúčtovaná jiným GoPay pohybem.');
        }
    }

    /** @param array<string,mixed> $movement @param array<string,?int> $links @return array{string,string,string} */
    private function stornoPosting(int $supplierId, array $movement, string $receivable, string $gopay, array &$links): array
    {
        $match = $this->matchCreditNote($supplierId, $movement);
        $links['credit_note_id'] = (int) $match['id'];
        return [$receivable, $gopay, 'GoPay vratka k dobropisu ' . (string) $match['varsymbol'] . ' (' . (string) $movement['order_id'] . ')'];
    }

    /** @param array<string,mixed> $movement @return array<string,mixed> */
    private function matchInvoicePayment(int $supplierId, array $movement): array
    {
        $paymentSessionId = trim((string) ($movement['payment_session_id'] ?? ''));
        $amount = number_format(abs((float) $movement['amount']), 2, '.', '');
        $currency = (string) $movement['currency'];
        if ($paymentSessionId !== '') {
            $stmt = $this->db->pdo()->prepare(
                'SELECT p.id payment_id,p.invoice_id,p.amount,p.currency,i.varsymbol,
                        i.supplier_order_number,i.note_below_items
                   FROM invoice_payments p JOIN invoices i ON i.id=p.invoice_id AND i.supplier_id=p.supplier_id
                  WHERE p.supplier_id=? AND p.bank_reference=?
                    AND i.invoice_type IN ("invoice","proforma")'
            );
            $stmt->execute([$supplierId, 'GOPAY:' . $paymentSessionId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) > 1) {
                throw new GoPayException('payment_reference_ambiguous', 'GoPay ID je uložené u více úhrad.');
            }
            if (count($rows) === 1) {
                if (number_format((float) $rows[0]['amount'], 2, '.', '') !== $amount || (string) $rows[0]['currency'] !== $currency) {
                    throw new GoPayException('payment_amount_mismatch', 'GoPay platba se liší částkou nebo měnou od úhrady faktury.');
                }
                $this->assertInvoicePosted($supplierId, (int) $rows[0]['invoice_id']);
                return $rows[0];
            }
        }

        $orderId = trim((string) ($movement['order_id'] ?? ''));
        if ($orderId === '') {
            throw new GoPayException('invoice_reference_missing', 'Platba nemá GoPay ID ani číslo objednávky.');
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.id payment_id,p.invoice_id,p.amount,p.currency,i.varsymbol,
                    i.supplier_order_number,i.note_below_items
               FROM invoice_payments p JOIN invoices i ON i.id=p.invoice_id AND i.supplier_id=p.supplier_id
              WHERE p.supplier_id=? AND p.amount=? AND p.currency=?
                AND i.invoice_type IN ("invoice","proforma")
                AND (i.supplier_order_number=?
                     OR (i.supplier_order_number IS NULL AND i.note_below_items LIKE ?))'
        );
        $stmt->execute([$supplierId, $amount, $currency, $orderId, '%' . $orderId . '%']);
        $rows = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),
            fn (array $row): bool => $this->documentHasOrder($row, $orderId)));
        if (count($rows) !== 1) {
            throw new GoPayException(count($rows) === 0 ? 'invoice_not_found' : 'invoice_ambiguous',
                count($rows) === 0 ? 'K GoPay platbě nebyla nalezena faktura a její úhrada.' : 'K GoPay platbě bylo nalezeno více faktur.');
        }
        $this->assertInvoicePosted($supplierId, (int) $rows[0]['invoice_id']);
        return $rows[0];
    }

    /**
     * Záměrně jen dobropis, ne celý {@see \MyInvoice\Service\Invoice\RefundDocument}: storno
     * GoPay vrací kartou zaplacenou objednávku, jejíž opravou je dobropis. Faktura
     * k vyplacení (vyúčtování s přeplatkem) kartou zaplacená není, vrací se převodem
     * nebo hotově.
     *
     * @param array<string,mixed> $movement @return array<string,mixed>
     */
    private function matchCreditNote(int $supplierId, array $movement): array
    {
        $orderId = trim((string) ($movement['order_id'] ?? ''));
        if ($orderId === '') {
            throw new GoPayException('credit_note_reference_missing', 'Vratka nemá číslo objednávky.');
        }
        $amount = number_format(abs((float) $movement['amount']), 2, '.', '');
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.id,i.varsymbol,i.supplier_order_number,i.note_below_items,i.parent_invoice_id
               FROM invoices i JOIN currencies c ON c.id=i.currency_id
              WHERE i.supplier_id=? AND i.invoice_type="credit_note" AND ABS(i.amount_to_pay)=?
                AND c.code=?
                AND (i.supplier_order_number=?
                     OR (i.supplier_order_number IS NULL AND i.note_below_items LIKE ?))'
        );
        $stmt->execute([$supplierId, $amount, (string) $movement['currency'], $orderId, '%' . $orderId . '%']);
        $rows = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),
            fn (array $row): bool => $this->documentHasOrder($row, $orderId)));
        if (count($rows) !== 1) {
            throw new GoPayException(count($rows) === 0 ? 'credit_note_not_found' : 'credit_note_ambiguous',
                count($rows) === 0 ? 'K GoPay vratce nebyl nalezen dobropis.' : 'K GoPay vratce bylo nalezeno více dobropisů.');
        }
        $this->assertInvoicePosted($supplierId, (int) $rows[0]['id']);
        return $rows[0];
    }

    private function assertInvoicePosted(int $supplierId, int $invoiceId): void
    {
        // Daňová evidence doklady nezaúčtovává; párování stačí k existujícímu dokladu.
        if (!$this->isDoubleEntry($supplierId)) {
            return;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM journal_entries
              WHERE supplier_id=? AND source_type="invoice" AND source_id=?
                AND posted_at IS NOT NULL AND reversed_by IS NULL LIMIT 1'
        );
        $stmt->execute([$supplierId, $invoiceId]);
        if ($stmt->fetchColumn() === false) {
            throw new GoPayException('invoice_not_posted', 'Faktura nebo dobropis ještě není zaúčtovaný v deníku.');
        }
    }

    private function noteHasOrder(string $note, string $orderId): bool
    {
        return preg_match('/(?:^|\R)\s*Objednávka:\s*' . preg_quote($orderId, '/') . '\s*(?:\R|$)/iu', $note) === 1;
    }

    /**
     * Doklad nese číslo objednávky: přesně v čísle objednávky dodavatele, u historických
     * dokladů bez něj na řádku „Objednávka: …" v poznámce.
     *
     * @param array<string,mixed> $document
     */
    public function documentHasOrder(array $document, string $orderId): bool
    {
        $stored = trim((string) ($document['supplier_order_number'] ?? ''));
        if ($stored !== '') {
            return mb_strtoupper($stored) === mb_strtoupper(trim($orderId));
        }
        return $this->noteHasOrder((string) ($document['note_below_items'] ?? ''), $orderId);
    }
}
