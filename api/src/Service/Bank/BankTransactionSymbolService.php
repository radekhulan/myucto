<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Service\Accounting\Bulk\BulkTransaction;
use PDO;

/**
 * Oprava variabilního symbolu bankovního pohybu (hromadná akce v seznamu pohybů).
 *
 * Banka někdy VS nepřenese nebo ho plátce zadá špatně (bez nul, v poznámce), pohyb
 * se pak nespáruje. Symbol jde opravit jen u pohybu, který ještě nic nevyřídil:
 * nespárovaný, neignorovaný, ne převzaté e-mailové avízo, bez úhrady faktury, přijaté
 * faktury, ostatní položky nebo mzdy a bez živého zápisu v deníku. U vyřízeného pohybu
 * by změna symbolu tiše rozešla pohyb s tím, co podle něj vzniklo; nejdřív se musí
 * zrušit spárování nebo zaúčtování.
 *
 * Po změně symbolu se pohyb znovu nabídne automatickému párování stejnou cestou jako
 * „Přepárovat výpis" ({@see TransactionRematcher}): převzetí avíza, párování ve dvou
 * průchodech a zaúčtování po spárování podle nastavení firmy.
 */
final class BankTransactionSymbolService
{
    public const MAX_LENGTH = 10;

    public function __construct(
        private readonly Connection $db,
        private readonly TransactionRematcher $rematcher,
    ) {}

    /** Platný VS: jen číslice, 1 až {@see MAX_LENGTH} znaků. */
    public static function isValid(string $symbol): bool
    {
        return preg_match('/^[0-9]{1,' . self::MAX_LENGTH . '}$/D', $symbol) === 1;
    }

    /**
     * Pohyb firmy (vlastník výpisu, {@see BankStatementOwnershipResolver}) a co brání
     * změně symbolu.
     *
     * @return array{id:int, statement_id:int, variable_symbol:?string, specific_symbol:?string,
     *               constant_symbol:?string, description:?string, match_status:string,
     *               blocker:?array{0:string, 1:string}, fingerprint:string}|null
     */
    public function state(int $supplierId, int $txId, bool $forUpdate = false): ?array
    {
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $stmt = $this->db->pdo()->prepare(
            "SELECT bt.id, bt.statement_id, bt.variable_symbol, bt.specific_symbol, bt.constant_symbol, bt.description,
                    bt.match_status, bt.matched_invoice_id, " . BankNoticeLifecycle::activeSql('bt') . " AS active,
                    (EXISTS (SELECT 1 FROM invoice_payments ip WHERE ip.bank_transaction_id = bt.id)
                     OR EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.bank_transaction_id = bt.id)
                     OR EXISTS (SELECT 1 FROM other_item_allocations oia WHERE oia.supplier_id = ? AND oia.bank_transaction_id = bt.id)
                     OR EXISTS (SELECT 1 FROM payroll_payment_matches ppm WHERE ppm.supplier_id = ? AND ppm.bank_transaction_id = bt.id)) AS settled,
                    EXISTS (SELECT 1 FROM journal_entries je
                             WHERE je.supplier_id = ? AND je.source_type IN ('"
                                . implode("', '", BankTransactionReleaseService::TRANSACTION_SOURCE_TYPES) . "')
                               AND je.source_id = bt.id AND je.reversed_by IS NULL) AS posted
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.id = ? AND " . BankStatementOwnershipResolver::sql() . $lock
        );
        $stmt->execute([$supplierId, $supplierId, $supplierId, $txId, ...BankStatementOwnershipResolver::params($supplierId)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $status = (string) $row['match_status'];
        $blocker = match (true) {
            !(bool) $row['active'] => ['notice_superseded', 'E-mailové avízo převzal výpis, symbol se opravuje na pohybu výpisu.'],
            $status === 'ignored' => ['ignored', 'Ignorovaný pohyb se nepáruje. Nejdřív ho vraťte mezi nespárované.'],
            $status !== 'unmatched' || $row['matched_invoice_id'] !== null => ['already_matched', 'Pohyb je spárovaný. Nejdřív zrušte spárování.'],
            (bool) $row['settled'] => ['already_settled', 'Pohyb už hradí doklad, ostatní položku nebo mzdu. Nejdřív zrušte spárování.'],
            (bool) $row['posted'] => ['posted', 'Pohyb je zaúčtovaný. Nejdřív zrušte zaúčtování.'],
            default => null,
        };
        $nullable = static fn (mixed $v): ?string => $v === null ? null : (string) $v;
        return [
            'id' => (int) $row['id'],
            'statement_id' => (int) $row['statement_id'],
            'variable_symbol' => $nullable($row['variable_symbol']),
            'specific_symbol' => $nullable($row['specific_symbol']),
            'constant_symbol' => $nullable($row['constant_symbol']),
            'description' => $nullable($row['description']),
            'match_status' => $status,
            'blocker' => $blocker,
            'fingerprint' => hash('sha256', json_encode([
                $row['id'], $row['variable_symbol'], $status, $row['matched_invoice_id'], $row['active'], $row['settled'], $row['posted'],
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * Zapíše nový symbol, jen když je pohyb pořád ve stavu `$expectedFingerprint`
     * a změnu nic nebrání. Po zápisu (`$rematch`) pohyb nabídne automatickému párování;
     * jeho chyba změnu symbolu nevrací, jen se ohlásí.
     *
     * @return array{state:array<string,mixed>, rematch_error:?string}
     * @throws BankTransactionSymbolException
     */
    public function change(int $supplierId, int $txId, ?string $symbol, string $expectedFingerprint, ?int $userId, bool $rematch): array
    {
        if ($symbol !== null && !self::isValid($symbol)) {
            throw new BankTransactionSymbolException('invalid_variable_symbol', 'Variabilní symbol smí mít jen číslice, nejvýš ' . self::MAX_LENGTH . '.');
        }
        $pdo = $this->db->pdo();
        $statementId = BulkTransaction::run($pdo, function () use ($pdo, $supplierId, $txId, $symbol, $expectedFingerprint): int {
            $state = $this->state($supplierId, $txId, true);
            if ($state === null) {
                throw new BankTransactionSymbolException('not_found', 'Bankovní pohyb nenalezen.');
            }
            if ($state['fingerprint'] !== $expectedFingerprint) {
                throw new BankTransactionSymbolException('changed', 'Pohyb se mezitím změnil.');
            }
            if ($state['blocker'] !== null) {
                throw new BankTransactionSymbolException($state['blocker'][0], $state['blocker'][1]);
            }
            $pdo->prepare('UPDATE bank_transactions SET variable_symbol = ? WHERE id = ?')->execute([$symbol, $txId]);
            return $state['statement_id'];
        });
        $error = $rematch ? $this->rematch($txId, $statementId, $userId) : null;
        return ['state' => (array) $this->state($supplierId, $txId), 'rematch_error' => $error];
    }

    /** Párování po změně symbolu, až po zápisu symbolu. Vrací chybu, nebo NULL. */
    private function rematch(int $txId, int $statementId, ?int $userId): ?string
    {
        try {
            $this->rematcher->rematch([$txId], $userId);
            $this->rematcher->recountStatements([$statementId]);
            return null;
        } catch (\Throwable $e) {
            // Odvolanou transakci volajícího (běh v cizí transakci) nespolknout.
            if (TransactionFailure::aborted($e) && $this->db->pdo()->inTransaction()) {
                throw $e;
            }
            return mb_substr($e->getMessage(), 0, 300);
        }
    }
}
