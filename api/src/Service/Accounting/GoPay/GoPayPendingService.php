<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\GoPay;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * GoPay úhrada faktury se účtuje dnem platby, ne až importem vyúčtování.
 *
 * Pohledávka zaniká zaplacením kartou; od té chvíle peníze drží GoPay (účet
 * gopay_account). Vyúčtování ale chodí souhrnně jednou měsíčně a jeho období
 * nekončí koncem měsíce, takže účtování až z vyúčtování nechávalo 311 v hlavní
 * knize otevřené, zatímco faktura i saldokonto byly uhrazené.
 *
 * Úhrada s referencí 'GOPAY:<paymentSessionId>' proto hned založí čekající pohyb
 * (clearing_id NULL, origin 'payment') a zaúčtuje ho stejným kódem jako pohyb
 * z vyúčtování ({@see GoPayMovementPoster}). Import vyúčtování čekající pohyb
 * se stejným paymentSessionId převezme, místo aby účtoval podruhé.
 */
final class GoPayPendingService
{
    use GoPayUnitOfWork;

    public const REFERENCE_PREFIX = 'GOPAY:';

    public function __construct(
        private readonly Connection $db,
        private readonly GoPayMovementPoster $poster,
    ) {}

    public static function sessionFromReference(?string $reference): ?string
    {
        $reference = trim((string) $reference);
        if (!str_starts_with($reference, self::REFERENCE_PREFIX)) {
            return null;
        }
        $session = trim(substr($reference, strlen(self::REFERENCE_PREFIX)));
        return $session === '' || strlen($session) > 40 ? null : $session;
    }

    /**
     * Založí a zaúčtuje čekající pohyb k úhradě faktury. Úhrada bez GoPay reference,
     * firma bez podvojného účetnictví nebo bez nastavení GoPay pro měnu úhrady se
     * nemění. Idempotentní: úhrada s už existujícím pohybem nic nezakládá.
     *
     * @return int|null id čekajícího pohybu, NULL když nevznikl
     */
    public function recordForPayment(int $paymentId, ?int $userId): ?int
    {
        $pdo = $this->db->pdo();
        $ownTx = $this->beginUnit($pdo, 'gopay_pending');
        try {
            // Zamyká se jen úhrada. Firma a nastavení GoPay se ověří zvlášť bez zámku: JOIN
            // ve FOR UPDATE by výlučně zamkl i řádek firmy a serializoval celou firmu
            // (každý zápis s cizím klíčem na firmu na něm drží sdílený zámek).
            $stmt = $pdo->prepare(
                'SELECT id,supplier_id,invoice_id,paid_on,amount,currency,bank_reference
                   FROM invoice_payments
                  WHERE id=? AND bank_reference LIKE "GOPAY:%"
                  FOR UPDATE'
            );
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($payment)) {
                $configured = $pdo->prepare(
                    'SELECT 1 FROM supplier s
                       JOIN gopay_settings gs ON gs.supplier_id=s.id AND gs.currency=?
                      WHERE s.id=? AND s.accounting_mode="double_entry"'
                );
                $configured->execute([(string) $payment['currency'], (int) $payment['supplier_id']]);
                if ($configured->fetchColumn() === false) {
                    $payment = false;
                }
            }
            $session = is_array($payment) ? self::sessionFromReference((string) $payment['bank_reference']) : null;
            if (!is_array($payment) || $session === null) {
                $this->commitUnit($pdo, $ownTx, 'gopay_pending');
                return null;
            }
            $supplierId = (int) $payment['supplier_id'];

            $existing = $pdo->prepare(
                'SELECT id,origin,clearing_id,status,journal_entry_id FROM gopay_movements
                  WHERE supplier_id=? AND movement_type="credit"
                    AND (invoice_payment_id=? OR payment_session_id=?)
                  ORDER BY id LIMIT 1 FOR UPDATE'
            );
            $existing->execute([$supplierId, $paymentId, $session]);
            $movement = $existing->fetch(PDO::FETCH_ASSOC);
            if (is_array($movement)) {
                $this->commitUnit($pdo, $ownTx, 'gopay_pending');
                // Vyúčtování dorazilo dřív než úhrada: jeho pohyb zůstal bez faktury.
                // Teď už ji najde, zaúčtuje se ke dni platby z vyúčtování.
                if ((string) $movement['status'] !== 'posted' || $movement['journal_entry_id'] === null) {
                    $this->poster->post($supplierId, (int) $movement['id'], $userId);
                }
                return null;
            }

            $pdo->prepare(
                'INSERT INTO gopay_movements
                    (supplier_id,clearing_id,origin,external_id,movement_type,performed_on,amount,currency,
                     payment_session_id,invoice_id,invoice_payment_id,status)
                 VALUES (?,NULL,"payment",NULL,"credit",?,?,?,?,?,?,"pending")'
            )->execute([
                $supplierId, (string) $payment['paid_on'], (string) $payment['amount'],
                (string) $payment['currency'], $session, (int) $payment['invoice_id'], $paymentId,
            ]);
            $movementId = (int) $pdo->lastInsertId();
            $this->commitUnit($pdo, $ownTx, 'gopay_pending');
        } catch (\Throwable $e) {
            $this->rollbackUnit($pdo, $ownTx, 'gopay_pending', $e);
            throw $e;
        }

        $this->poster->post($supplierId, $movementId, $userId);
        return $movementId;
    }

    /**
     * Doúčtování existujících dat: založí čekající pohyby k GoPay úhradám, které
     * ještě žádný pohyb nemají, a znovu zkusí zaúčtovat čekající pohyby s chybou
     * (např. faktura mezitím zaúčtovaná, období odemčené). Opakované spuštění nic
     * nezdvojí.
     *
     * @return array{created:int,posted:int,issues:list<array<string,mixed>>}
     */
    public function postPending(int $supplierId, ?int $userId): array
    {
        $this->assertReady($supplierId);
        $created = 0;
        foreach ($this->unrecordedPaymentIds($supplierId) as $paymentId) {
            if ($this->recordForPayment($paymentId, $userId) !== null) {
                $created++;
            }
        }

        $unposted = $this->db->pdo()->prepare(
            'SELECT id FROM gopay_movements
              WHERE supplier_id=? AND origin="payment" AND clearing_id IS NULL
                AND (status<>"posted" OR journal_entry_id IS NULL)
              ORDER BY performed_on,id'
        );
        $unposted->execute([$supplierId]);
        foreach ($unposted->fetchAll(PDO::FETCH_COLUMN) as $movementId) {
            $this->poster->post($supplierId, (int) $movementId, $userId);
        }

        $overview = $this->overview($supplierId);
        $issues = array_values(array_filter(
            $overview['items'],
            static fn (array $item): bool => $item['status'] !== 'posted',
        ));
        return [
            'created' => $created,
            'posted' => count($overview['items']) - count($issues),
            'issues' => $issues,
        ];
    }

    /**
     * Čekající pohyby (co dorazí v příštím vyúčtování) a počet GoPay úhrad, které
     * na doúčtování teprve čekají.
     *
     * @return array{items:list<array<string,mixed>>,totals:list<array{currency:string,amount:float,count:int}>,unrecorded_count:int,unposted_count:int}
     */
    public function overview(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT gm.id,gm.performed_on,gm.amount,gm.currency,gm.payment_session_id,gm.status,
                    gm.issue_code,gm.issue_message,gm.invoice_id,gm.invoice_payment_id,gm.journal_entry_id,
                    i.varsymbol invoice_number,je.document_no journal_document_no
               FROM gopay_movements gm
          LEFT JOIN invoices i ON i.id=gm.invoice_id AND i.supplier_id=gm.supplier_id
          LEFT JOIN journal_entries je ON je.id=gm.journal_entry_id AND je.supplier_id=gm.supplier_id
              WHERE gm.supplier_id=? AND gm.origin="payment" AND gm.clearing_id IS NULL
              ORDER BY gm.performed_on,gm.id'
        );
        $stmt->execute([$supplierId]);
        $items = [];
        $totals = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            foreach (['id', 'invoice_id', 'invoice_payment_id', 'journal_entry_id'] as $field) {
                $row[$field] = $row[$field] !== null ? (int) $row[$field] : null;
            }
            $row['amount'] = (float) $row['amount'];
            $currency = (string) $row['currency'];
            $totals[$currency] ??= ['currency' => $currency, 'amount' => 0.0, 'count' => 0];
            $totals[$currency]['amount'] = round($totals[$currency]['amount'] + $row['amount'], 2);
            $totals[$currency]['count']++;
            $items[] = $row;
        }

        return [
            'items' => $items,
            'totals' => array_values($totals),
            'unrecorded_count' => count($this->unrecordedPaymentIds($supplierId)),
            'unposted_count' => count(array_filter(
                $items,
                static fn (array $item): bool => $item['status'] !== 'posted' || $item['journal_entry_id'] === null,
            )),
        ];
    }

    /**
     * Import vyúčtování: kreditní pohyb, ke kterému už existuje čekající pohyb se
     * stejným paymentSessionId, částkou a měnou, se nezakládá znovu: čekající pohyb
     * převezme jeho údaje i vazbu na vyúčtování a jeho zápis ke dni platby zůstává.
     * Nesoulad částky nebo měny čekající pohyb nepřevezme; pohyb z vyúčtování pak
     * skončí u úhrady s chybou payment_amount_mismatch jako dosud.
     *
     * Výpis z obchodního účtu (XLS/XLSX) GoPay ID platby nenese. Jeho kreditní pohyb
     * převezme čekající pohyb faktury se stejným číslem objednávky, částkou a měnou
     * s datem platby nejvýše 3 dny od pohybu, a to jen když je takový právě jeden.
     */
    public function adoptIntoClearing(int $supplierId, int $clearingId): int
    {
        $pdo = $this->db->pdo();
        $clearing = $pdo->prepare('SELECT currency FROM gopay_clearings WHERE id=? AND supplier_id=?');
        $clearing->execute([$clearingId, $supplierId]);
        $currency = $clearing->fetchColumn();
        if ($currency === false) {
            return 0;
        }

        $candidates = $pdo->prepare(
            'SELECT gm.id,gm.external_id,gm.payment_session_id,gm.amount,gm.order_id,gm.account_movement_id,
                    gm.payment_channel,gm.counterparty_name,gm.performed_on
               FROM gopay_movements gm
              WHERE gm.supplier_id=? AND gm.clearing_id=? AND gm.origin="clearing"
                AND gm.movement_type="credit"
                AND (gm.payment_session_id IS NOT NULL OR gm.order_id IS NOT NULL)
                AND NOT EXISTS(SELECT 1 FROM journal_entries je
                                WHERE je.supplier_id=gm.supplier_id AND je.source_type="gopay"
                                  AND je.source_id=gm.id)
              ORDER BY gm.id'
        );
        $candidates->execute([$supplierId, $clearingId]);
        $adopted = 0;
        foreach ($candidates->fetchAll(PDO::FETCH_ASSOC) as $imported) {
            $ownTx = $this->beginUnit($pdo, 'gopay_adopt');
            try {
                $row = $imported['payment_session_id'] !== null
                    ? $this->pendingBySession($supplierId, (string) $imported['payment_session_id'])
                    : $this->pendingByOrder($supplierId, $imported, (string) $currency);
                if (!is_array($row)
                    || number_format((float) $row['amount'], 2, '.', '') !== number_format((float) $imported['amount'], 2, '.', '')
                    || (string) $row['currency'] !== (string) $currency) {
                    $this->commitUnit($pdo, $ownTx, 'gopay_adopt');
                    continue;
                }
                $pdo->prepare('DELETE FROM gopay_movements WHERE id=? AND supplier_id=?')
                    ->execute([(int) $imported['id'], $supplierId]);
                $pdo->prepare(
                    'UPDATE gopay_movements
                        SET clearing_id=?,external_id=?,order_id=?,account_movement_id=?,
                            payment_channel=?,counterparty_name=?
                      WHERE id=? AND supplier_id=?'
                )->execute([
                    $clearingId, $imported['external_id'], $imported['order_id'], $imported['account_movement_id'],
                    $imported['payment_channel'], $imported['counterparty_name'], (int) $row['id'], $supplierId,
                ]);
                $this->commitUnit($pdo, $ownTx, 'gopay_adopt');
                $adopted++;
            } catch (\Throwable $e) {
                $this->rollbackUnit($pdo, $ownTx, 'gopay_adopt', $e);
                throw $e;
            }
        }
        return $adopted;
    }

    /** @return array<string,mixed>|null */
    private function pendingBySession(int $supplierId, string $session): ?array
    {
        $pending = $this->db->pdo()->prepare(
            'SELECT id,amount,currency FROM gopay_movements
              WHERE supplier_id=? AND origin="payment" AND clearing_id IS NULL
                AND movement_type="credit" AND payment_session_id=?
              ORDER BY id LIMIT 1 FOR UPDATE'
        );
        $pending->execute([$supplierId, $session]);
        $row = $pending->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $imported @return array<string,mixed>|null */
    private function pendingByOrder(int $supplierId, array $imported, string $currency): ?array
    {
        $orderId = trim((string) $imported['order_id']);
        $pending = $this->db->pdo()->prepare(
            'SELECT gm.id,gm.amount,gm.currency,i.supplier_order_number,i.note_below_items
               FROM gopay_movements gm
               JOIN invoices i ON i.id=gm.invoice_id AND i.supplier_id=gm.supplier_id
              WHERE gm.supplier_id=? AND gm.origin="payment" AND gm.clearing_id IS NULL
                AND gm.movement_type="credit" AND gm.amount=? AND gm.currency=?
                AND ABS(DATEDIFF(gm.performed_on,?))<=3
                AND (i.supplier_order_number=?
                     OR (i.supplier_order_number IS NULL AND i.note_below_items LIKE ?))
              ORDER BY gm.id FOR UPDATE'
        );
        $pending->execute([
            $supplierId, number_format((float) $imported['amount'], 2, '.', ''), $currency,
            (string) $imported['performed_on'], $orderId, '%' . $orderId . '%',
        ]);
        $rows = array_values(array_filter(
            $pending->fetchAll(PDO::FETCH_ASSOC),
            fn (array $row): bool => $this->poster->documentHasOrder($row, $orderId),
        ));
        return count($rows) === 1 ? $rows[0] : null;
    }

    /** Smazání vyúčtování vrací převzaté pohyby mezi čekající i s jejich zápisem. */
    public function releaseFromClearing(int $supplierId, int $clearingId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE gopay_movements
                SET clearing_id=NULL,external_id=NULL,account_movement_id=NULL
              WHERE supplier_id=? AND clearing_id=? AND origin="payment"'
        )->execute([$supplierId, $clearingId]);
    }

    /**
     * Před smazáním úhrady faktury: čekající pohyb i jeho zápis zmizí s ní (za
     * stejných podmínek jako mazání vyúčtování). Úhradu, kterou už potvrdilo
     * vyúčtování, smazat nejde, ať pohyb vznikl převzetím čekajícího pohybu, nebo
     * přímo z vyúčtování: jeho zápis 221/311 by zůstal bez úhrady.
     */
    public function releaseForPayment(int $paymentId): void
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            (new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($this->db))->transaction(fn () => $this->releaseForPayment($paymentId));
            return;
        }
        $scope = $pdo->prepare('SELECT supplier_id FROM invoice_payments WHERE id = ?');
        $scope->execute([$paymentId]);
        $scopeId = $scope->fetchColumn();
        if ($scopeId !== false) (new \MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard($this->db))->lockClaims((int) $scopeId);
        $stmt = $pdo->prepare(
            'SELECT gm.id,gm.supplier_id,gm.clearing_id,gc.clearing_id provider_clearing_id
               FROM gopay_movements gm
          LEFT JOIN gopay_clearings gc ON gc.id=gm.clearing_id AND gc.supplier_id=gm.supplier_id
              WHERE gm.invoice_payment_id=? AND gm.movement_type="credit"
              ORDER BY gm.id FOR UPDATE'
        );
        $stmt->execute([$paymentId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $movement) {
            if ($scopeId === false || (int) $scopeId !== (int) $movement['supplier_id']) {
                throw new GoPayException('allocation_source_changed', 'Zdroj úhrady se změnil. Načtěte aktuální stav.', 409);
            }
            if ($movement['clearing_id'] !== null) {
                throw new GoPayException(
                    'payment_in_clearing',
                    'Úhrada je už potvrzená ve vyúčtování GoPay ' . (string) $movement['provider_clearing_id']
                        . '. Smazat ji lze až po smazání tohoto vyúčtování.',
                    409,
                );
            }
            $supplierId = (int) $movement['supplier_id'];
            $entries = $pdo->prepare(
                'SELECT id FROM journal_entries WHERE supplier_id=? AND source_type="gopay" AND source_id=? FOR UPDATE'
            );
            $entries->execute([$supplierId, (int) $movement['id']]);
            $entryIds = array_map('intval', $entries->fetchAll(PDO::FETCH_COLUMN));
            (new \MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard($this->db))->assertPostingEntriesMutable($supplierId, $entryIds);
            (new \MyInvoice\Service\Accounting\Allocation\JointAllocationUseGuard($this->db))->assertEntriesMutable($supplierId, $entryIds);
            $this->poster->assertEntriesRemovable($supplierId, $entryIds, 'Úhrada GoPay');
            foreach ($entryIds as $entryId) {
                $pdo->prepare('DELETE FROM journal_entries WHERE id=? AND supplier_id=?')
                    ->execute([$entryId, $supplierId]);
            }
            $pdo->prepare('DELETE FROM gopay_movements WHERE id=? AND supplier_id=?')
                ->execute([(int) $movement['id'], $supplierId]);
        }
    }

    public function releaseForInvoice(int $invoiceId): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM invoice_payments WHERE invoice_id=? ORDER BY id');
        $stmt->execute([$invoiceId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $paymentId) {
            $this->releaseForPayment((int) $paymentId);
        }
    }

    /** @return list<int> */
    private function unrecordedPaymentIds(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ip.id
               FROM invoice_payments ip
               JOIN supplier s ON s.id=ip.supplier_id AND s.accounting_mode="double_entry"
               JOIN gopay_settings gs ON gs.supplier_id=ip.supplier_id AND gs.currency=ip.currency
              WHERE ip.supplier_id=? AND ip.bank_reference LIKE BINARY "GOPAY:%"
                AND CHAR_LENGTH(TRIM(SUBSTRING(ip.bank_reference,7))) BETWEEN 1 AND 40
                AND NOT EXISTS(SELECT 1 FROM gopay_movements gm
                                WHERE gm.supplier_id=ip.supplier_id AND gm.movement_type="credit"
                                  AND (gm.invoice_payment_id=ip.id
                                       OR gm.payment_session_id=TRIM(SUBSTRING(ip.bank_reference,7))))
              ORDER BY ip.paid_on,ip.id'
        );
        $stmt->execute([$supplierId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function assertReady(int $supplierId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.accounting_mode,
                    EXISTS(SELECT 1 FROM gopay_settings gs WHERE gs.supplier_id=s.id) configured
               FROM supplier s WHERE s.id=?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['accounting_mode'] !== 'double_entry') {
            throw new GoPayException('not_double_entry', 'GoPay automatické účtování vyžaduje podvojné účetnictví.', 409);
        }
        if (!(bool) $row['configured']) {
            throw new GoPayException('settings_missing', 'Nejdřív nastav účty modulu GoPay.', 409);
        }
    }
}
