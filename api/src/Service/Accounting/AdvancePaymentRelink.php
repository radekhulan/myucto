<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\PurchasePaymentMatchWriter;
use MyInvoice\Service\PurchaseInvoice\AdvanceCoveredPaidStatus;
use MyInvoice\Support\Sql\PurchaseSettledExpr;
use PDO;

/**
 * Oprava „rozpárované" zálohy na přijaté straně: platba zálohy spárovaná s KONEČNOU
 * fakturou, která je navázaná na zálohovou fakturu, a záloha sama bez úhrady (typicky
 * ručně „uhrazená evidenčně").
 *
 * Vzniklo to obcházením chyby, kdy se zúčtování zálohy 321/314 na konečné faktuře
 * nedorovnalo po pozdější změně úhrady zálohy ({@see AdvanceSettlementSync}). Deník je
 * pak účetně vyrovnaný (321/221 místo 314/221 + 321/314), ale stav dokladů lže: záloha
 * je „uhrazená" bez platby a platba visí na faktuře, kterou kryla záloha.
 *
 * Oprava jedné dvojice (vše v jedné transakci, jinak se nezmění nic):
 *   1. párování pohybu se přesune z konečné faktury na zálohu,
 *   2. bankovní zápis se přeúčtuje na místě 321/221 → 314/221 (BankPostingService),
 *   3. zápis konečné faktury dostane zúčtování 321/314 (AdvanceSettlementSync),
 *   4. záloha zůstane uhrazená — teď platbou, datum úhrady = datum pohybu.
 * Jen v otevřeném a nezamčeném období (bankovní zápis i zápis konečné faktury, oba v témž
 * roce) a jen když je jisté, že platba je záloha: záloha bez jakékoli úhrady (SSOT
 * PurchaseSettledExpr — banka, pokladna, zápočty), nepřevzatá, v Kč, platba = částka zálohy
 * a konečná faktura zálohou krytá CELÁ (jinak může jít o doplatek), pohyb hradí jediný
 * doklad a není to platba kartou přes mezičlen 378. Ostatní se jen vypíše.
 * Idempotentní: opravená dvojice už kritéria nesplní.
 */
final class AdvancePaymentRelink
{
    /** Tolerance shody platby s částkou zálohy (haléřové zaokrouhlení). */
    private const AMOUNT_TOLERANCE_CENTS = 100;

    public function __construct(
        private readonly Connection $db,
        private readonly BankPostingService $bankPosting,
        private readonly AdvanceSettlementSync $settlement,
        private readonly JournalEntryRepository $journal,
        private readonly AccountingPeriodRepository $periods,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @return array{candidates:int, fixed:int, rows:list<array<string,mixed>>}
     */
    public function run(?int $supplierId, bool $apply, ?int $userId = null): array
    {
        $rows = [];
        $fixed = 0;
        foreach ($this->candidates($supplierId) as $c) {
            $row = $c + ['status' => 'would_fix'];
            $blocker = $this->blocker($c);
            if ($blocker !== null) {
                $rows[] = ['status' => $blocker['status'], 'message' => $blocker['message']] + $c;
                continue;
            }
            if ($apply) {
                $row = $this->fix($c, $userId);
                if ($row['status'] === 'fixed') {
                    $fixed++;
                }
            } else {
                $fixed++;
            }
            $rows[] = $row;
        }
        return ['candidates' => count($rows), 'fixed' => $fixed, 'rows' => $rows];
    }

    /** @return list<array<string,mixed>> */
    private function candidates(?int $supplierId): array
    {
        $sql = "SELECT f.supplier_id, f.id AS final_id, a.id AS advance_id,
                       COALESCE(NULLIF(f.vendor_invoice_number, ''), f.varsymbol) AS final_no,
                       COALESCE(NULLIF(a.vendor_invoice_number, ''), a.varsymbol) AS advance_no,
                       a.status AS advance_status, a.paid_at AS advance_paid_at,
                       ROUND(a.total_with_vat, 2) AS advance_total,
                       ROUND(f.total_with_vat, 2) AS final_total, ROUND(f.amount_to_pay, 2) AS final_to_pay,
                       ac.code AS currency,
                       pm.id AS match_id, pm.bank_transaction_id AS tx_id, ROUND(pm.amount, 2) AS amount,
                       bt.posted_at AS tx_date,
                       (SELECT COUNT(*) FROM payment_matches o WHERE o.bank_transaction_id = pm.bank_transaction_id) AS tx_matches
                  FROM purchase_invoices f
                  JOIN purchase_invoices a
                    ON a.id = f.advance_purchase_invoice_id AND a.supplier_id = f.supplier_id
                   AND a.document_kind = 'advance' AND a.status <> 'cancelled'
                  JOIN currencies ac ON ac.id = a.currency_id
                  JOIN payment_matches pm
                    ON pm.supplier_id = f.supplier_id AND pm.purchase_invoice_id = f.id
                   AND pm.bank_transaction_id IS NOT NULL
                  JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
                 WHERE f.document_kind = 'invoice' AND f.status NOT IN ('draft', 'cancelled')
                   -- Záloha bez JAKÉKOLI úhrady (banka, pokladna, vzájemný i účetní zápočet) — SSOT.
                   AND ABS(" . PurchaseSettledExpr::settled('a') . ") < 0.005"
            . ($supplierId !== null ? ' AND f.supplier_id = ?' : '')
            . ' ORDER BY f.supplier_id, f.id, pm.id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($supplierId !== null ? [$supplierId] : []);

        return array_map(static fn (array $r): array => [
            'supplier_id'     => (int) $r['supplier_id'],
            'final_id'        => (int) $r['final_id'],
            'final_no'        => (string) ($r['final_no'] ?? ''),
            'advance_id'      => (int) $r['advance_id'],
            'advance_no'      => (string) ($r['advance_no'] ?? ''),
            'advance_status'  => (string) $r['advance_status'],
            'advance_paid_at' => $r['advance_paid_at'] !== null ? (string) $r['advance_paid_at'] : null,
            'advance_total'   => (float) $r['advance_total'],
            'final_total'     => (float) $r['final_total'],
            'final_to_pay'    => (float) $r['final_to_pay'],
            'currency'        => (string) $r['currency'],
            'match_id'        => (int) $r['match_id'],
            'tx_id'           => (int) $r['tx_id'],
            'tx_date'         => substr((string) $r['tx_date'], 0, 10),
            'amount'          => (float) $r['amount'],
            'tx_matches'      => (int) $r['tx_matches'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Proč dvojici nelze opravit automaticky (null = lze).
     *
     * @param array<string,mixed> $c
     * @return array{status:string, message:string}|null
     */
    private function blocker(array $c): ?array
    {
        $sid = (int) $c['supplier_id'];
        if ((int) $c['tx_matches'] !== 1) {
            return ['status' => 'split_payment', 'message' => 'pohyb hradí víc dokladů, oprav ručně'];
        }
        if (strtoupper((string) $c['currency']) !== 'CZK') {
            return ['status' => 'foreign_currency', 'message' => 'cizoměnová záloha, oprav ručně'];
        }
        if (abs((int) round(((float) $c['amount'] - (float) $c['advance_total']) * 100)) > self::AMOUNT_TOLERANCE_CENTS) {
            return ['status' => 'amount_mismatch', 'message' => 'platba neodpovídá částce zálohy (doplatek faktury?)'];
        }
        // Jen faktura, kterou záloha kryje CELOU: jinak může platba na faktuře být legitimní
        // doplatek a přesun na zálohu by ho z faktury vzal.
        if (abs((int) round(((float) $c['final_total'] - (float) $c['advance_total']) * 100)) > self::AMOUNT_TOLERANCE_CENTS
            || (float) $c['final_to_pay'] > 0.005) {
            return ['status' => 'not_fully_covered', 'message' => 'faktura není zálohou plně krytá (doplatek?), oprav ručně'];
        }
        if ((new TakenOverRecord($this->db))->isDocument($sid, 'purchase_invoice', (int) $c['advance_id'])) {
            return ['status' => 'taken_over', 'message' => 'záloha je převzatá z jiného programu (úhrada v počátečním stavu 314)'];
        }
        $bank = $this->journal->findBySource($sid, 'bank', (int) $c['tx_id']);
        if ($bank === null || ($bank['reversed_by'] ?? null) !== null || ($bank['posted_at'] ?? null) === null) {
            return ['status' => 'bank_not_posted', 'message' => 'pohyb nemá živý bankovní zápis'];
        }
        if ((new TakenOverRecord($this->db))->hasLiveBankEntry($sid, (int) $c['tx_id'])) {
            return ['status' => 'taken_over', 'message' => 'bankovní zápis je převzatý z jiného programu'];
        }
        // Platba kartou zaúčtovaná přes mezičlen 378 (do 24. 9. 2026): bankovní zápis je
        // 378/221 a úhradu nese zápis vypořádání — přepárování by ho muselo přestavět taky.
        if ($this->bankPosting->liveCardClearingLine($sid, (int) $c['tx_id']) !== null) {
            return ['status' => 'card_clearing', 'message' => 'platba kartou přes mezičlen 378, přepáruj ručně (vypořádání 321/378)'];
        }
        $dates = ['bankovní zápis' => (string) $bank['entry_date']];
        $finalId = (int) $c['final_id'];
        $active = (new DocumentPostingGroup($this->db))->active($sid, 'purchase_invoice', $finalId);
        if ((new TakenOverRecord($this->db))->isDocumentOrItsEntry($sid, 'purchase_invoice', $finalId)) {
            return ['status' => 'taken_over', 'message' => 'konečná faktura nebo její předpis je převzatý z jiného programu'];
        }
        if ($active !== [] && !DocumentPostingGroup::isSingleDirectEntry($active, 'purchase_invoice', $finalId)) {
            return ['status' => 'posting_group_requires_repost', 'message' => 'konečná faktura má skupinu zápisů, přeúčtujte celý doklad'];
        }
        $final = $active[0] ?? null;
        if ($final !== null) {
            $dates['zápis konečné faktury'] = (string) $final['entry_date'];
            // Úhrada v POZDĚJŠÍM účetním období (hospodářském roce) než zápis faktury se do
            // něj zpětně nedopisuje ({@see AdvanceSettlementSync}); dřívější úhrada je běžná.
            $bankPeriod = $this->periods->findById($sid, (int) $bank['period_id']);
            $finalPeriod = $this->periods->findById($sid, (int) $final['period_id']);
            if ($bankPeriod !== null && $finalPeriod !== null
                && (string) $bankPeriod['starts_on'] > (string) $finalPeriod['starts_on']) {
                return ['status' => 'payment_in_later_period', 'message' => 'platba je v pozdějším účetním období než konečná faktura, zúčtuj ručně k datu úhrady'];
            }
        }
        $lockedUntil = $this->lockedUntil($sid);
        foreach ($dates as $label => $date) {
            $period = $this->periods->findForDate($sid, $date);
            if ($period === null || (string) $period['status'] !== 'open') {
                return ['status' => 'period_not_open', 'message' => $label . ' ' . $date . ' je v uzavřeném období'];
            }
            if ($lockedUntil !== null && $date <= $lockedUntil) {
                return ['status' => 'date_locked', 'message' => $label . ' ' . $date . ' je zamčený k ' . $lockedUntil];
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    private function fix(array $c, ?int $userId): array
    {
        $sid = (int) $c['supplier_id'];
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        $savepoint = 'advance_relink_' . (int) $c['match_id'];
        if ($own) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }
        try {
            // Párování přes jedinou bránu zápisu (bez strážce: párování míří NA zálohu),
            // původní řádek na konečné faktuře odpadá.
            PurchasePaymentMatchWriter::record(
                $pdo, $sid, (int) $c['tx_id'], (int) $c['advance_id'], (float) $c['amount'], 'manual', null, $userId,
            );
            $pdo->prepare('DELETE FROM payment_matches WHERE id = ? AND supplier_id = ?')
                ->execute([(int) $c['match_id'], $sid]);

            // Přeúčtování banky na 314/221; po něm BankPostingService sám dorovná
            // zúčtování v konečné faktuře (AdvanceSettlementSync).
            $entryId = $this->bankPosting->postMatched($sid, (int) $c['tx_id'], $userId);
            if ($entryId === null) {
                throw new \RuntimeException('bankovní zápis se nepřeúčtoval (politika automatiky nebo období)');
            }
            $sync = $this->settlement->syncAdvance($sid, 'purchase', (int) $c['advance_id'], $userId);
            if (!in_array($sync['action'], ['synced', 'in_sync', 'none'], true)) {
                throw new \RuntimeException('zúčtování zálohy v konečné faktuře se nedorovnalo: ' . ($sync['reason'] ?? $sync['action']));
            }
            $this->assertAdvanceBalanced($sid, $entryId, (int) $c['final_id']);

            // Záloha zůstává uhrazená, ale skutečnou platbou: datum úhrady = datum pohybu.
            $pdo->prepare(
                "UPDATE purchase_invoices SET status = 'paid', paid_at = ? WHERE id = ? AND supplier_id = ?"
            )->execute([(string) $c['tx_date'], (int) $c['advance_id'], $sid]);
            // Konečná faktura krytá zálohou je uhrazená k datu úhrady zálohy.
            (new AdvanceCoveredPaidStatus($this->db))->afterPaymentsChanged($sid, [(int) $c['advance_id'], (int) $c['final_id']]);

            $this->activity->log(
                'purchase_invoice.advance_payment_relinked',
                $userId,
                'purchase_invoice',
                (int) $c['advance_id'],
                [
                    'bank_transaction_id' => (int) $c['tx_id'],
                    'from_final_id'       => (int) $c['final_id'],
                    'bank_entry_id'       => $entryId,
                    'previous_status'     => $c['advance_status'],
                    'previous_paid_at'    => $c['advance_paid_at'],
                    'settlement'          => $sync['action'],
                ],
                supplierId: $sid,
            );
            if ($own) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            return ['status' => 'fixed', 'bank_entry_id' => $entryId, 'settlement' => $sync['action']] + $c;
        } catch (\Throwable $e) {
            // Odvolaná transakce (1020/1213) savepoint nemá; uvnitř cizí transakce ji musí
            // dostat vlastník, vlastní transakce je vrácená a případ se nahlásí jako selhaný.
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                if (!$own) {
                    throw $e;
                }
            } elseif ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif (!$own && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            return ['status' => 'failed', 'message' => $e->getMessage()] + $c;
        }
    }

    /** Úhrada na 314 v bance a zúčtování 314 v konečné faktuře musí sedět na haléř. */
    private function assertAdvanceBalanced(int $supplierId, int $bankEntryId, int $finalId): void
    {
        $active = (new DocumentPostingGroup($this->db))->active($supplierId, 'purchase_invoice', $finalId, $this->db->pdo()->inTransaction());
        $entryIds = [$bankEntryId];
        if ($active === []) {
            return; // nezaúčtovaná konečná faktura si zúčtování spočítá sama při zaúčtování
        }
        // Druhá pojistka uvnitř transakce: skupina mohla vzniknout od náhledu
        // blocker(). Jednočlenný součet by její zúčtování neověřil a změny se vrátí.
        if (!DocumentPostingGroup::isSingleDirectEntry($active, 'purchase_invoice', $finalId)) {
            throw new \RuntimeException('Konečná faktura má skupinu zápisů, přeúčtujte celý doklad.');
        }
        $entryIds[] = (int) $active[0]['id'];
        $in = implode(',', array_fill(0, count($entryIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END), 0)
               FROM journal_entry_lines l
               JOIN chart_of_accounts c ON c.id = l.account_id
              WHERE l.supplier_id = ? AND l.entry_id IN ({$in}) AND c.account_code LIKE '314%'"
        );
        $stmt->execute([$supplierId, ...$entryIds]);
        $balance = (int) round((float) $stmt->fetchColumn() * 100);
        if ($balance !== 0) {
            throw new \RuntimeException('314 mezi úhradou a konečnou fakturou nesedí o ' . number_format($balance / 100, 2, ',', ' ') . ' Kč');
        }
    }

    private function lockedUntil(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? null : (string) $v;
    }
}
