<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\LoggingPdo;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\PermissionChecker;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Support\Sql\PurchaseSettledExpr;
use PDO;
use Psr\Log\LoggerInterface;

/** Dohledání starší platby po přijetí dokladu; peníze zapisuje výhradně StatementMatcher. */
final class PurchaseBankMatching
{
    public const CANDIDATE_LIMIT = 200;

    public function __construct(
        private readonly Connection $db,
        private readonly StatementMatcher $matcher,
        private readonly PermissionResolver $permissions,
        private readonly PermissionChecker $checker,
        private readonly LoggerInterface $logger,
    ) {}

    public function afterReady(int $supplierId, int $id, ?int $userId): void
    {
        $callback = fn () => $this->retry($supplierId, $id, $userId);
        $pdo = $this->db->pdo();
        if ($pdo instanceof LoggingPdo) $pdo->afterCommit("purchase-bank:{$supplierId}:{$id}", $callback);
        elseif (!$pdo->inTransaction()) $callback();
        // Cizí PDO bez afterCommit nesmí provést úhradu před commitem dokladu.
    }

    /** @return array<string,mixed> */
    public function retry(int $supplierId, int $id, ?int $userId): array
    {
        try {
            $role = $userId !== null ? $this->permissions->forSupplierUser($userId, $supplierId) : null;
            if ($role !== null && (!$this->checker->allows($role, 'bank', AccessLevel::READ)
                || !$this->checker->allows($role, 'bank.match', AccessLevel::WRITE)
                || !$this->checker->allows($role, 'purchase_invoices', AccessLevel::READ)
                || ($role->isClientType() && !$this->checker->allows($role, 'purchase_invoices.transition', AccessLevel::WRITE)))) {
                return ['outcome' => 'forbidden'];
            }
            $summary = (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $id, $role): array {
                // Stejné pořadí zámků jako import banky: kontext firmy, pohyby, doklad.
                (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->acquire($supplierId);
                $document = $this->document($supplierId, $id);
                if ($document === null) return ['outcome' => 'ineligible'];
                $ids = $this->candidateIds($supplierId, $document);
                if (count($ids) > self::CANDIDATE_LIMIT) return ['outcome' => 'limited', 'matched_count' => 0];
                $context = new BankTransactionContext($this->db);
                $transactions = [];
                foreach ($ids as $txId) {
                    $tx = $context->mutableTransaction($supplierId, $txId);
                    if ($tx !== null) $transactions[$txId] = $tx;
                }
                $document = $this->document($supplierId, $id, true);
                if ($document === null) return ['outcome' => 'ineligible'];
                $dates = [$document['issue_date'], $document['effective_cost_date'] ?? $document['issue_date']];
                foreach ($transactions as $tx) $dates[] = substr((string) $tx['posted_at'], 0, 10);
                if ((new PurchaseBankMatchingPeriodGuard($this->db))->datesLocked($supplierId, $dates)) return ['outcome' => 'locked'];
                if ($role?->isClientType() && ($document['booked_at'] !== null || $document['status'] === 'booked')) {
                    return ['outcome' => 'locked'];
                }
                // Po získání zámků znovu použij celý scope; změna od prvního SELECTu
                // nesmí obejít ignorování, jiné vypořádání ani nahrazené avízo.
                $freeIds = $this->candidateIds($supplierId, $document);
                if (count($freeIds) > self::CANDIDATE_LIMIT) return ['outcome' => 'limited', 'matched_count' => 0];
                $matched = 0;
                $matchedIds = [];
                foreach ($transactions as $txId => $tx) {
                    if (!in_array($txId, $freeIds, true)) continue;
                    // Dvě vhodné platby k jedinému dokladu vyžadují rozhodnutí člověka.
                    $result = $this->matcher->matchForPurchase($supplierId, $id, $tx, count($freeIds) === 1);
                    if (($result['status'] ?? 'unmatched') !== 'unmatched') {
                        ++$matched;
                        $matchedIds[] = $txId;
                    }
                }
                if ($matchedIds !== []) {
                    $places = implode(',', array_fill(0, count($matchedIds), '?'));
                    $affected = $this->db->pdo()->prepare('SELECT bs.id FROM bank_statements bs
                        JOIN (SELECT statement_id FROM bank_transactions WHERE id IN (' . $places . ')
                            UNION SELECT statement_id FROM bank_transaction_imports WHERE bank_transaction_id IN (' . $places . ')) a
                            ON a.statement_id = bs.id WHERE ' . BankStatementOwnershipResolver::sql());
                    $affected->execute([...$matchedIds, ...$matchedIds, ...BankStatementOwnershipResolver::params($supplierId)]);
                    $statementIds = array_map('intval', $affected->fetchAll(PDO::FETCH_COLUMN));
                    if ($statementIds !== []) {
                        // Zdrojové, opakovaně importované i měsíční výpisy používají stejný SSOT scope.
                        $idsSql = implode(',', $statementIds);
                        $this->db->pdo()->exec('UPDATE bank_statements bs SET matched_count = '
                            . StatementTransactionScope::countSql('bs.id', condition: "bt.match_status IN ('auto_exact','auto_partial','manual')")
                            . ' WHERE bs.id IN (' . $idsSql . ')');
                        $months = $this->db->pdo()->prepare('SELECT DISTINCT monthly_statement_id FROM bank_api_evidence_months
                            WHERE supplier_id = ? AND evidence_statement_id IN (' . $idsSql . ')');
                        $months->execute([$supplierId]);
                        (new BankApiMonthlyStatements($this->db->pdo()))->refreshMonths(array_map('intval', $months->fetchAll(PDO::FETCH_COLUMN)), $supplierId);
                    }
                }
                return ['outcome' => 'searched', 'matched_count' => $matched];
            });
        } catch (\Throwable $e) {
            if (TransactionFailure::aborted($e) && $this->db->pdo()->inTransaction()) throw $e;
            $this->logger->error('Dohledání bankovní úhrady selhalo.', ['supplier_id' => $supplierId, 'purchase_invoice_id' => $id, 'exception' => $e]);
            $summary = ['outcome' => 'failed'];
        }
        $summary['checked_at'] = date(DATE_ATOM);
        try {
            $this->db->pdo()->prepare('INSERT INTO purchase_bank_matching_attempts (result_json, supplier_id, purchase_invoice_id)
                SELECT ?, supplier_id, id FROM purchase_invoices WHERE supplier_id = ? AND id = ?
                ON DUPLICATE KEY UPDATE result_json = VALUES(result_json)')
                ->execute([json_encode($summary, JSON_THROW_ON_ERROR), $supplierId, $id]);
        } catch (\Throwable $e) {
            if (TransactionFailure::aborted($e)) throw $e;
            $this->logger->error('Uložení výsledku dohledání banky selhalo.', ['exception' => $e]);
        }
        return $summary;
    }

    /** @return array<string,mixed>|null */
    private function document(int $supplierId, int $id, bool $lock = false): ?array
    {
        $stmt = $this->db->pdo()->prepare("SELECT p.*, c.code AS currency,
                p.amount_to_pay + COALESCE(p.rounding, 0) - (" . PurchaseSettledExpr::settled('p') . ") AS remaining
            FROM purchase_invoices p JOIN currencies c ON c.id = p.currency_id
            WHERE p.supplier_id = ? AND p.id = ? AND p.status IN ('received','booked','paid')
              AND p.document_kind IN ('invoice','receipt','advance') AND p.cash_register_id IS NULL
            HAVING remaining > 0.005" . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId,$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return list<int> */
    private function candidateIds(int $supplierId, array $document, ?array $onlyIds = null): array
    {
        [$sql, $params] = $this->candidateQuery($supplierId, $document, $onlyIds);
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array{string,list<mixed>} */
    private function candidateQuery(int $supplierId, array $document, ?array $onlyIds = null): array
    {
        $symbols = [];
        foreach (['payment_variable_symbol','varsymbol','vendor_invoice_number'] as $field) {
            $raw = (string) ($document[$field] ?? '');
            if ($raw !== '') $symbols[] = $raw;
            $digits = VariableSymbolNormalizer::forMatching($raw);
            if ($digits === '') continue;
            // Bankovní symbol má nejvýše 10 číslic, včetně platných vodicích nul.
            for ($length = strlen($digits); $length <= 10; ++$length) $symbols[] = str_pad($digits, $length, '0', STR_PAD_LEFT);
        }
        $symbols = array_values(array_unique($symbols));
        $parts = [];
        $params = [];
        $scope = ' FROM bank_statements bs JOIN bank_transactions bt ON bt.statement_id = bs.id WHERE (bs.supplier_id = ' . $supplierId . ' OR bs.supplier_id IS NULL) AND '
            . BankStatementOwnershipResolver::sql() . ' AND bt.amount < 0 AND ' . BankPaymentCandidateScope::sql($supplierId)
            . ($onlyIds !== null ? ' AND bt.id IN (' . implode(',', array_map('intval', $onlyIds)) . ')' : '');
        if ($symbols !== []) {
            $parts[] = '(SELECT bt.id' . $scope . ' AND bt.variable_symbol IN (' . implode(',',array_fill(0,count($symbols),'?')) . ') LIMIT 201)';
            array_push($params, ...BankStatementOwnershipResolver::params($supplierId), ...$symbols);
        }
        $amount = (float) $document['remaining'];
        $local = FxPaymentSettlement::expectedLocalAmount($amount, (float) ($document['exchange_rate'] ?: 1));
        $tolerance = FxPaymentSettlement::matchTolerance($local, FxPaymentSettlement::AMOUNT_TOLERANCE, true);
        foreach (array_unique([$document['issue_date'],$document['due_date']]) as $date) {
            $day = new \DateTimeImmutable($date);
            $parts[] = '(SELECT bt.id' . $scope . ' AND bt.posted_at >= ? AND bt.posted_at < ?
                AND ((bt.amount BETWEEN ? AND ? AND (bt.currency = ? OR (bt.currency IS NULL AND bs.currency = ?)))
                  OR (bt.amount BETWEEN ? AND ? AND (bt.currency = \'CZK\' OR (bt.currency IS NULL AND bs.currency = \'CZK\')))
                  OR (bt.original_currency = ? AND (bt.original_amount BETWEEN ? AND ? OR bt.original_amount BETWEEN ? AND ?))) LIMIT 201)';
            array_push($params, ...BankStatementOwnershipResolver::params($supplierId));
            array_push($params, $day->modify('-90 days')->format('Y-m-d'),$day->modify('+91 days')->format('Y-m-d'),
                -$amount-1,-$amount+1,$document['currency'],$document['currency'],
                -$local-$tolerance,-$local+$tolerance,$document['currency'],$amount-1,$amount+1,-$amount-1,-$amount+1);
        }
        return [implode(' UNION ', $parts) . ' LIMIT 201', $params];
    }

    /** Indexovaný odkaz z auditu návrhů; bez skenu JSON ani bankovních pohybů firmy. */
    private function suggestionQuery(int $supplierId, int $id): array
    {
        return ['SELECT s.id, s.bank_transaction_id, s.reason, s.candidates_json,
                    bt.statement_id, bt.posted_at, bt.amount, COALESCE(bt.currency, bs.currency, \'CZK\') AS currency, bt.description
                FROM (SELECT DISTINCT suggestion_id FROM bank_match_audit
                    WHERE supplier_id = ? AND purchase_invoice_id = ? AND suggestion_id IS NOT NULL) a
                JOIN bank_match_suggestions s ON s.id = a.suggestion_id
                JOIN bank_transactions bt ON bt.id = s.bank_transaction_id
                JOIN bank_statements bs ON bs.id = bt.statement_id
                WHERE s.supplier_id = ? AND s.status = \'pending\' AND '
                . BankStatementOwnershipResolver::sql() . ' AND ' . BankPaymentCandidateScope::sql($supplierId),
            [$supplierId, $id, $supplierId, ...BankStatementOwnershipResolver::params($supplierId)]];
    }

    /** Importér předá ID až po dokončení celého importu; žádné párování uvnitř importní smyčky. */
    public function afterImport(int $supplierId, array $ids, ?int $userId): void
    {
        foreach (array_unique($ids) as $id) $this->afterReady($supplierId, (int) $id, $userId);
    }

    /** Čtení nikdy nevytváří úhradu ani návrh. @return array<string,mixed> */
    public function read(int $supplierId, int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT result_json FROM purchase_bank_matching_attempts WHERE supplier_id = ? AND purchase_invoice_id = ?');
        $stmt->execute([$supplierId,$id]);
        $json = $stmt->fetchColumn();
        $last = is_string($json) ? json_decode($json,true,512,JSON_THROW_ON_ERROR) : null;
        $stmt = $this->db->pdo()->prepare('SELECT bt.id, bt.statement_id, bt.posted_at, bt.amount, COALESCE(bt.currency, bs.currency, \'CZK\') AS currency, bt.description
            FROM payment_matches pm JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
            JOIN bank_statements bs ON bs.id = bt.statement_id
            WHERE pm.supplier_id = ? AND pm.purchase_invoice_id = ? AND ' . BankStatementOwnershipResolver::sql());
        $stmt->execute([$supplierId,$id,...BankStatementOwnershipResolver::params($supplierId)]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $document = $this->document($supplierId,$id);
        $suggestions = [];
        if ($document !== null) {
            [$sql, $params] = $this->suggestionQuery($supplierId, $id);
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Jen ID již uložených návrhů: detail neprohledává bankovní historii.
            $ids = $rows !== [] ? $this->candidateIds($supplierId, $document, array_column($rows, 'bank_transaction_id')) : [];
            foreach ($rows as $row) {
                if (!in_array((int) $row['bank_transaction_id'], $ids, true)) continue;
                foreach (json_decode($row['candidates_json'],true,512,JSON_THROW_ON_ERROR) as $index => $candidate) {
                    if ((int) ($candidate['purchase_invoice_id'] ?? 0) !== $id
                        || abs((float) $candidate['display']['amount'] - (float) $document['remaining']) > 0.005
                        || $candidate['display']['currency'] !== $document['currency']) continue;
                    unset($row['candidates_json']);
                    $suggestions[] = $row + ['candidate_index' => $index, 'candidate' => $candidate];
                    break;
                }
            }
        }
        $cast = static function (array $row): array {
            foreach (['id', 'statement_id', 'bank_transaction_id', 'candidate_index'] as $key) {
                if (isset($row[$key])) $row[$key] = (int) $row[$key];
            }
            $row['amount'] = (float) $row['amount'];
            return $row;
        };
        return ['matches' => array_map($cast, $matches), 'suggestions' => array_map($cast, $suggestions), 'last_attempt' => $last];
    }
}
