<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollPaymentSettlementSignalRepository;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Pozná zaplacený mzdový odvod v bankovních pohybech.
 *
 * ── Proč to vůbec je ────────────────────────────────────────────────────────
 * Odvody na ZP, SP a zálohovou daň mají u každého zaměstnavatele VLASTNÍ
 * variabilní symbol (viz `payroll_institution_accounts.variable_symbol`), takže
 * odchozí platba je identifikovatelná na první pohled — a přesto ji dosud musel
 * účetní spárovat ručně v Mzdových příkazech. Do té doby hlídač termínů tvrdil
 * „zbývá 3 024 Kč" o odvodu, který byl dávno pryč z účtu.
 *
 * ── Dvě různé jistoty, dva různé výsledky ───────────────────────────────────
 * Rozhoduje ZDROJ pohybu, ne to, jak dobře sedí:
 *
 *   * oficiální výpis (`source='statement'`) → skutečné spárování
 *     ({@see PayrollPaymentReconciliationService::match()}) se vším všudy:
 *     platební kniha, saldo, protizápis do deníku;
 *   * e-mailové avízo (`email_notice`, `idoklad`) → jen provizorní SIGNÁL
 *     (migrace 1750). Avízo je duplikát: týž pohyb dorazí ještě jednou výpisem,
 *     a protože `payroll_payment_matches` je nemazatelný a needitovatelný,
 *     nešlo by párování na výpis přenést tak, jak to u faktur dělá
 *     {@see \MyInvoice\Service\Bank\EmailNoticeReconciler}. Signál proto zhasne
 *     termín, ale salda se nedotkne — a jakmile dorazí výpis, tahle služba ho
 *     nahradí skutečnou úhradou a signál uzavře.
 *
 * ── Kdy se sáhne jen na signál i u výpisu ───────────────────────────────────
 * Spárovat jde jedině proti platební ALOKACI (mzdový příkaz). Když závazek
 * žádnou nemá — účetní zaplatila rovnou z internetového bankovnictví, aniž by
 * v aplikaci vystavila příkaz — vznikne signál i z výpisu. Termín zhasne,
 * závazek zůstane v saldu otevřený a čeká, až ho účetní zařadí do dávky.
 *
 * ── Jednoznačnost ───────────────────────────────────────────────────────────
 * Uzná se jen dvojice, která je nejlepší pro OBĚ strany: závazek nemá bližšího
 * kandidáta a pohyb taky ne. Vzdálenost měříme ke dni splatnosti, protože
 * odvody se u stabilní mzdy opakují měsíc po měsíci na haléř stejné a jediné,
 * co je odlišuje, je datum. Shodná vzdálenost dvou kandidátů = remíza = ruce
 * pryč (ať to raději dopáruje člověk, než abychom platbu přiřadili k jinému
 * měsíci).
 */
final class PayrollPaymentSettlementRecognizer
{
    /**
     * Pohyb se hledá od doby, kdy je odvod vůbec splatitelný (měsíc po konci
     * mzdového období), s malou rezervou pro firmy, které mzdy uzavírají dřív.
     */
    private const PAYABLE_FROM_TOLERANCE_DAYS = 5;

    /** Jak dlouho po splatnosti ještě platbu k závazku přiřadíme. */
    private const LATE_PAYMENT_WINDOW_DAYS = 60;

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollPaymentSettlementSignalRepository $signals,
        private readonly PayrollPaymentReconciliationService $reconciliation,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Projede otevřené odvody firmy a uzná, co jednoznačně sedí.
     *
     * @return array{matched:int,signalled:int,ambiguous:int}
     */
    public function recognizeForSupplier(int $supplierId, ?int $userId = null): array
    {
        $result = ['matched' => 0, 'signalled' => 0, 'ambiguous' => 0];
        if ($supplierId <= 0) {
            return $result;
        }
        $liabilities = $this->openLiabilities($supplierId);
        if ($liabilities === []) {
            return $result;
        }
        $transactions = $this->candidateTransactions($supplierId, $liabilities);
        if ($transactions === []) {
            return $result;
        }

        $pairs = [];
        foreach ($liabilities as $liability) {
            foreach ($transactions as $transaction) {
                if (!$this->fits($liability, $transaction)) {
                    continue;
                }
                $pairs[] = [
                    'liability' => $liability,
                    'transaction' => $transaction,
                    'distance' => abs(
                        $this->dayNumber($transaction['posted_at'])
                        - $this->dayNumber($liability['due_on']),
                    ),
                ];
            }
        }

        foreach ($this->unambiguousPairs($pairs, $result) as $pair) {
            $outcome = $this->settle(
                $supplierId,
                $pair['liability'],
                $pair['transaction'],
                $userId,
            );
            if ($outcome !== null) {
                $result[$outcome]++;
            }
        }

        return $result;
    }

    /**
     * Dvojice, která je nejbližší pro obě strany a nemá remízu.
     *
     * @param list<array{liability:array<string,mixed>,transaction:array<string,mixed>,distance:int}> $pairs
     * @param array{matched:int,signalled:int,ambiguous:int} $result
     * @return list<array{liability:array<string,mixed>,transaction:array<string,mixed>,distance:int}>
     */
    private function unambiguousPairs(array $pairs, array &$result): array
    {
        $best = static function (array $pairs, string $key, int $id): ?array {
            $own = array_values(array_filter(
                $pairs,
                static fn (array $pair): bool => $pair[$key]['id'] === $id,
            ));
            usort(
                $own,
                static fn (array $a, array $b): int => $a['distance'] <=> $b['distance'],
            );
            if ($own === []) {
                return null;
            }
            // Remíza (dvě stejně vzdálené platby) = nejednoznačné.
            if (count($own) > 1 && $own[0]['distance'] === $own[1]['distance']) {
                return null;
            }

            return $own[0];
        };

        $accepted = [];
        $seenLiabilities = [];
        foreach ($pairs as $pair) {
            $liabilityId = (int) $pair['liability']['id'];
            if (isset($seenLiabilities[$liabilityId])) {
                continue;
            }
            $seenLiabilities[$liabilityId] = true;
            $bestForLiability = $best($pairs, 'liability', $liabilityId);
            if ($bestForLiability === null) {
                $result['ambiguous']++;
                continue;
            }
            $transactionId = (int) $bestForLiability['transaction']['id'];
            $bestForTransaction = $best($pairs, 'transaction', $transactionId);
            if ($bestForTransaction === null
                || (int) $bestForTransaction['liability']['id'] !== $liabilityId
            ) {
                $result['ambiguous']++;
                continue;
            }
            $accepted[] = $bestForLiability;
        }

        return $accepted;
    }

    /**
     * @param array<string,mixed> $liability
     * @param array<string,mixed> $transaction
     */
    private function fits(array $liability, array $transaction): bool
    {
        if ((int) $transaction['amount_minor'] !== (int) $liability['outstanding_minor']) {
            return false;
        }
        if ($transaction['currency_code'] !== null
            && $transaction['currency_code'] !== $liability['currency_code']
        ) {
            return false;
        }
        if (VariableSymbolNormalizer::forMatching((string) $transaction['variable_symbol'])
            !== VariableSymbolNormalizer::forMatching((string) $liability['variable_symbol'])
        ) {
            return false;
        }
        $postedAt = (string) $transaction['posted_at'];

        return $postedAt >= $this->payableFrom($liability)
            && $postedAt <= $this->payableTo($liability);
    }

    /** @param array<string,mixed> $liability */
    private function payableFrom(array $liability): string
    {
        return (new \DateTimeImmutable((string) $liability['period_start']))
            ->modify('+1 month')
            ->modify('-' . self::PAYABLE_FROM_TOLERANCE_DAYS . ' days')
            ->format('Y-m-d');
    }

    /** @param array<string,mixed> $liability */
    private function payableTo(array $liability): string
    {
        return (new \DateTimeImmutable((string) $liability['due_on']))
            ->modify('+' . self::LATE_PAYMENT_WINDOW_DAYS . ' days')
            ->format('Y-m-d');
    }

    private function dayNumber(string $date): int
    {
        return (int) ((new \DateTimeImmutable($date))->format('U') / 86400);
    }

    /**
     * @param array<string,mixed> $liability
     * @param array<string,mixed> $transaction
     * @return 'matched'|'signalled'|null null = nic se nestalo
     */
    private function settle(
        int $supplierId,
        array $liability,
        array $transaction,
        ?int $userId,
    ): ?string {
        $liabilityId = (int) $liability['id'];
        $transactionId = (int) $transaction['id'];
        $allocationId = $liability['allocation_id'] === null
            ? null
            : (int) $liability['allocation_id'];

        if ($allocationId !== null && $transaction['source'] === 'statement') {
            try {
                $match = $this->reconciliation->match(
                    new PayrollPaymentReconciliationCommand(
                        $supplierId,
                        $allocationId,
                        (int) $liability['outstanding_minor'],
                        PayrollPaymentEvidenceReference::bank(
                            (int) $transaction['statement_id'],
                            $transactionId,
                        ),
                        $this->idempotencyKey($allocationId, $transactionId),
                        $userId,
                    ),
                );
                $this->signals->resolveForLiability(
                    $supplierId,
                    $liabilityId,
                    $match->id,
                );

                return 'matched';
            } catch (\DomainException | \InvalidArgumentException $exception) {
                // Zamčený rok, mezitím spotřebovaný pohyb, cizí vlastnictví —
                // automat couvne a nechá to na člověku. Není to chyba běhu.
                $this->logger?->info('payroll.settlement_auto_match_declined', [
                    'supplier_id' => $supplierId,
                    'liability_id' => $liabilityId,
                    'bank_transaction_id' => $transactionId,
                    'reason' => $exception->getMessage(),
                ]);

                return null;
            }
        }

        if ($liability['has_open_signal'] === true) {
            // O téhle platbě už víme z jiného zdroje — druhý signál nepřidá nic.
            return null;
        }

        return $this->signals->insert(
            $supplierId,
            $liabilityId,
            'bank_notice',
            $transactionId,
            (string) $transaction['posted_at'],
            (int) $transaction['amount_minor'],
            null,
            $userId,
        ) ? 'signalled' : null;
    }

    private function idempotencyKey(int $allocationId, int $transactionId): string
    {
        return 'payroll-auto-match:' . $allocationId . ':' . $transactionId;
    }

    /**
     * Otevřené odvody s vlastním variabilním symbolem instituce.
     *
     * Závazek s živým signálem se vynechá — už o něm víme. Zaměstnanecké čisté
     * mzdy tu nejsou schválně: platba na účet zaměstnance VS nenese, takže by
     * párování stálo jen na částce.
     *
     * @return list<array<string,mixed>>
     */
    private function openLiabilities(int $supplierId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT liability.id, liability.due_on, liability.amount_minor,
                    liability.currency_code, liability.liability_kind,
                    run.period_start,
                    account.variable_symbol,
                    COALESCE(settlement.settled_minor, 0) AS settled_minor,
                    (SELECT allocation.id
                       FROM payroll_payment_allocations allocation
                       JOIN payroll_payment_items item
                         ON item.supplier_id = allocation.supplier_id
                        AND item.id = allocation.item_id
                       JOIN payroll_payment_batches batch
                         ON batch.supplier_id = item.supplier_id
                        AND batch.id = item.batch_id
                      WHERE allocation.supplier_id = liability.supplier_id
                        AND allocation.liability_id = liability.id
                        AND batch.channel = "bank"
                        AND allocation.amount_minor = liability.amount_minor
                      ORDER BY allocation.id ASC
                      LIMIT 1) AS allocation_id,
                    (SELECT COUNT(*)
                       FROM payroll_payment_allocations allocation
                      WHERE allocation.supplier_id = liability.supplier_id
                        AND allocation.liability_id = liability.id) AS allocation_count,
                    EXISTS (
                      SELECT 1
                        FROM payroll_payment_settlement_signals settlement_signal
                       WHERE settlement_signal.supplier_id = liability.supplier_id
                         AND settlement_signal.liability_id = liability.id
                         AND settlement_signal.resolved_at IS NULL
                    ) AS has_open_signal
               FROM payroll_payment_liabilities liability
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id
                AND revision.id = liability.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
                AND run.current_revision_no = revision.revision_no
               JOIN payroll_institution_accounts account
                 ON account.supplier_id = liability.supplier_id
                AND liability.recipient_reference LIKE "institution:%:account:%"
                AND account.id = CAST(SUBSTRING_INDEX(
                      liability.recipient_reference, ":", -1
                    ) AS UNSIGNED)
          LEFT JOIN (
                    SELECT supplier_id, liability_id,
                           SUM(amount_minor) AS settled_minor
                      FROM payroll_payment_matches
                     WHERE supplier_id = ? AND liability_id IS NOT NULL
                     GROUP BY supplier_id, liability_id
               ) settlement
                 ON settlement.supplier_id = liability.supplier_id
                AND settlement.liability_id = liability.id
              WHERE liability.supplier_id = ?
                AND liability.direction = "outgoing"
                AND account.variable_symbol IS NOT NULL
                AND account.variable_symbol <> ""
                AND liability.amount_minor > COALESCE(settlement.settled_minor, 0)
              ORDER BY liability.due_on ASC, liability.id ASC',
        );
        $statement->execute([$supplierId, $supplierId]);

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $outstanding = (int) $row['amount_minor'] - (int) $row['settled_minor'];
            if ($outstanding <= 0) {
                continue;
            }
            $result[] = [
                'id' => (int) $row['id'],
                'due_on' => (string) $row['due_on'],
                'period_start' => (string) $row['period_start'],
                'currency_code' => (string) $row['currency_code'],
                'liability_kind' => (string) $row['liability_kind'],
                // Závazek s živým signálem se dál hledá ZÁMĚRNĚ: signál je jen
                // provizorní a přesně tohle je chvíle, kdy ho má vystřídat
                // skutečná úhrada z výpisu. Druhý signál se nezaloží (viz settle()).
                'has_open_signal' => (bool) $row['has_open_signal'],
                'variable_symbol' => (string) $row['variable_symbol'],
                'outstanding_minor' => $outstanding,
                // Částečně alokovaný nebo rozdělený závazek se automaticky
                // nepáruje — dvojici alokace/částka by musel rozřešit člověk.
                'allocation_id' => (int) $row['allocation_count'] === 1
                    && $row['allocation_id'] !== null
                    && $outstanding === (int) $row['amount_minor']
                        ? (int) $row['allocation_id']
                        : null,
            ];
        }

        return $result;
    }

    /**
     * Odchozí nespárované pohyby v okně, které pokrývá všechny otevřené odvody.
     *
     * @param list<array<string,mixed>> $liabilities
     * @return list<array<string,mixed>>
     */
    private function candidateTransactions(int $supplierId, array $liabilities): array
    {
        $from = null;
        $to = null;
        foreach ($liabilities as $liability) {
            $payableFrom = $this->payableFrom($liability);
            $payableTo = $this->payableTo($liability);
            $from = $from === null || $payableFrom < $from ? $payableFrom : $from;
            $to = $to === null || $payableTo > $to ? $payableTo : $to;
        }
        if ($from === null || $to === null) {
            return [];
        }

        $statement = $this->db->pdo()->prepare(
            'SELECT bank_transaction.id, bank_transaction.statement_id,
                    bank_transaction.source, bank_transaction.posted_at,
                    CAST(ROUND(-bank_transaction.amount * 100) AS SIGNED)
                        AS amount_minor,
                    COALESCE(bank_transaction.currency, bank_statement.currency)
                        AS currency_code,
                    bank_transaction.variable_symbol
               FROM bank_statements bank_statement
               JOIN bank_transactions bank_transaction
                 ON bank_transaction.statement_id = bank_statement.id
              WHERE bank_statement.supplier_id = ?
                AND bank_transaction.amount < 0
                AND bank_transaction.posted_at >= ?
                AND bank_transaction.posted_at <= ?
                AND bank_transaction.matched_invoice_id IS NULL
                AND bank_transaction.match_status = "unmatched"
                AND bank_transaction.variable_symbol IS NOT NULL
                AND bank_transaction.variable_symbol <> ""
                AND NOT EXISTS (
                      SELECT 1 FROM invoice_payments invoice_payment
                       WHERE invoice_payment.bank_transaction_id = bank_transaction.id
                    )
                AND NOT EXISTS (
                      SELECT 1 FROM payment_matches payment_match
                       WHERE payment_match.bank_transaction_id = bank_transaction.id
                    )
                AND NOT EXISTS (
                      SELECT 1 FROM payroll_payment_matches payroll_match
                       WHERE payroll_match.bank_transaction_id = bank_transaction.id
                    )
                AND NOT EXISTS (
                      SELECT 1 FROM payroll_payment_settlement_signals settlement_signal
                       WHERE settlement_signal.bank_transaction_id = bank_transaction.id
                    )
              ORDER BY bank_transaction.posted_at ASC, bank_transaction.id ASC',
        );
        $statement->execute([$supplierId, $from, $to]);

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = [
                'id' => (int) $row['id'],
                'statement_id' => (int) $row['statement_id'],
                'source' => (string) $row['source'],
                'posted_at' => (string) $row['posted_at'],
                'amount_minor' => (int) $row['amount_minor'],
                'currency_code' => $row['currency_code'] === null
                    ? null
                    : (string) $row['currency_code'],
                'variable_symbol' => (string) $row['variable_symbol'],
            ];
        }

        return $result;
    }
}
