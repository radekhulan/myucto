<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\BankNoticeMutationGuard;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Payment\IbanValidator;
use PDO;

final class ClientBankAccountRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly IbanValidator $ibanValidator,
    ) {}

    /** @return list<array<string,mixed>> */
    public function listForClient(int $clientId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, client_id, account_number, bank_code, iban,
                    source_manual, source_vat_registry, source_bank_statement,
                    last_bank_transaction_id, is_active, first_seen_at, last_seen_at
               FROM client_bank_accounts
              WHERE client_id = ? AND supplier_id = ? AND is_active = 1
              ORDER BY source_vat_registry DESC, source_bank_statement DESC, id'
        );
        $stmt->execute([$clientId, $supplierId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<string,mixed> */
    public function addManual(int $clientId, int $supplierId, array $data): array
    {
        $this->assertClientOwned($clientId, $supplierId);
        $account = $this->normalizeInput(
            (string) ($data['account_number'] ?? ''),
            isset($data['bank_code']) ? (string) $data['bank_code'] : null,
            isset($data['iban']) ? (string) $data['iban'] : null,
        );
        $id = $this->upsert($clientId, $supplierId, $account, manual: true);
        return $this->find($id, $clientId, $supplierId)
            ?? throw new \RuntimeException('Bankovní účet se nepodařilo načíst.');
    }

    /** @param list<array<string,mixed>> $accounts */
    public function syncVatRegistry(int $clientId, int $supplierId, array $accounts): int
    {
        $this->assertClientOwned($clientId, $supplierId);
        $count = 0;
        foreach ($accounts as $raw) {
            $iban = trim((string) ($raw['iban'] ?? ''));
            $number = trim((string) ($raw['number'] ?? ''));
            $prefix = trim((string) ($raw['prefix'] ?? ''));
            $accountNumber = $iban !== '' ? $iban : ($prefix !== '' ? $prefix . '-' : '') . $number;
            if ($accountNumber === '') {
                continue;
            }
            $account = $this->normalizeInput(
                $accountNumber,
                isset($raw['bank_code']) ? (string) $raw['bank_code'] : null,
                $iban !== '' ? $iban : null,
            );
            $this->upsert($clientId, $supplierId, $account, vatRegistry: true);
            ++$count;
        }
        return $count;
    }

    public function captureForInvoiceTransaction(int $invoiceId, int $transactionId): void
    {
        try {
            $stmt = $this->db->pdo()->prepare(
                'SELECT i.client_id, i.supplier_id, bt.counterparty_account, bt.counterparty_bank
                   FROM invoices i
                   JOIN bank_transactions bt ON bt.id = ?
                   JOIN bank_statements bs ON bs.id = bt.statement_id
                  WHERE i.id = ? AND i.client_id IS NOT NULL
                    AND ' . BankStatementOwnershipResolver::sqlForColumn('i.supplier_id')
            );
            $stmt->execute([$transactionId, $invoiceId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false || trim((string) ($row['counterparty_account'] ?? '')) === '') {
                return;
            }
            $account = $this->normalizeInput(
                (string) $row['counterparty_account'],
                isset($row['counterparty_bank']) ? (string) $row['counterparty_bank'] : null,
                null,
            );
            $this->upsert(
                (int) $row['client_id'],
                (int) $row['supplier_id'],
                $account,
                bankStatement: true,
                transactionId: $transactionId,
            );
        } catch (\Throwable) {
            return;
        }
    }

    public function captureForPurchaseInvoiceTransaction(int $purchaseInvoiceId, int $transactionId): void
    {
        try {
            $stmt = $this->db->pdo()->prepare(
                'SELECT pi.vendor_id AS client_id, pi.supplier_id,
                        bt.counterparty_account, bt.counterparty_bank
                   FROM purchase_invoices pi
                   JOIN bank_transactions bt ON bt.id = ?
                   JOIN bank_statements bs ON bs.id = bt.statement_id
                  WHERE pi.id = ? AND pi.vendor_id IS NOT NULL
                    AND ' . BankStatementOwnershipResolver::sqlForColumn('pi.supplier_id')
            );
            $stmt->execute([$transactionId, $purchaseInvoiceId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false || trim((string) ($row['counterparty_account'] ?? '')) === '') {
                return;
            }
            $account = $this->normalizeInput(
                (string) $row['counterparty_account'],
                isset($row['counterparty_bank']) ? (string) $row['counterparty_bank'] : null,
                null,
            );
            $this->upsert(
                (int) $row['client_id'],
                (int) $row['supplier_id'],
                $account,
                bankStatement: true,
                transactionId: $transactionId,
            );
        } catch (\Throwable) {
            return;
        }
    }

    public function captureFromBank(
        int $clientId,
        int $supplierId,
        string $accountNumber,
        ?string $bankCode = null,
        ?int $transactionId = null,
    ): int {
        $this->assertClientOwned($clientId, $supplierId);
        $account = $this->normalizeInput($accountNumber, $bankCode, null);
        return $this->upsert(
            $clientId,
            $supplierId,
            $account,
            bankStatement: true,
            transactionId: $transactionId,
        );
    }

    public function deactivate(int $id, int $clientId, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE client_bank_accounts SET is_active = 0
              WHERE id = ? AND client_id = ? AND supplier_id = ? AND is_active = 1'
        );
        $stmt->execute([$id, $clientId, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** @return array<string,mixed>|null */
    private function find(int $id, int $clientId, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, client_id, account_number, bank_code, iban,
                    source_manual, source_vat_registry, source_bank_statement,
                    last_bank_transaction_id, is_active, first_seen_at, last_seen_at
               FROM client_bank_accounts WHERE id = ? AND client_id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $clientId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /**
     * @param array{account_number:string,bank_code:?string,iban:?string,account_key:string,bank_key:string} $account
     */
    private function upsert(
        int $clientId,
        int $supplierId,
        array $account,
        bool $manual = false,
        bool $vatRegistry = false,
        bool $bankStatement = false,
        ?int $transactionId = null,
    ): int {
        return (new BankNoticeMutationGuard($this->db))->mutate($supplierId, $transactionId, fn () => $this->upsertMutable($clientId, $supplierId, $account, $manual, $vatRegistry, $bankStatement, $transactionId));
    }

    private function upsertMutable(
        int $clientId,
        int $supplierId,
        array $account,
        bool $manual = false,
        bool $vatRegistry = false,
        bool $bankStatement = false,
        ?int $transactionId = null,
    ): int {
        $existing = $this->db->pdo()->prepare(
            'SELECT id
               FROM client_bank_accounts
              WHERE supplier_id = ? AND client_id = ? AND account_key = ?
                AND (bank_key = ? OR bank_key = \'\' OR ? = \'\')
              ORDER BY (bank_key = ?) DESC, (bank_key <> \'\') DESC, id
              LIMIT 1'
        );
        $existing->execute([
            $supplierId,
            $clientId,
            $account['account_key'],
            $account['bank_key'],
            $account['bank_key'],
            $account['bank_key'],
        ]);
        $existingId = (int) ($existing->fetchColumn() ?: 0);
        if ($existingId > 0) {
            $update = $this->db->pdo()->prepare(
                'UPDATE client_bank_accounts
                    SET account_number = ?,
                        bank_code = COALESCE(?, bank_code),
                        iban = COALESCE(?, iban),
                        bank_key = CASE WHEN ? <> \'\' THEN ? ELSE bank_key END,
                        source_manual = GREATEST(source_manual, ?),
                        source_vat_registry = GREATEST(source_vat_registry, ?),
                        source_bank_statement = GREATEST(source_bank_statement, ?),
                        last_bank_transaction_id = COALESCE(?, last_bank_transaction_id),
                        is_active = 1,
                        last_seen_at = CURRENT_TIMESTAMP
                  WHERE id = ? AND supplier_id = ? AND client_id = ?'
            );
            $update->execute([
                $account['account_number'],
                $account['bank_code'],
                $account['iban'],
                $account['bank_key'],
                $account['bank_key'],
                $manual ? 1 : 0,
                $vatRegistry ? 1 : 0,
                $bankStatement ? 1 : 0,
                $transactionId !== null && $transactionId > 0 ? $transactionId : null,
                $existingId,
                $supplierId,
                $clientId,
            ]);
            return $existingId;
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO client_bank_accounts
                (supplier_id, client_id, account_number, bank_code, iban, account_key, bank_key,
                 source_manual, source_vat_registry, source_bank_statement, last_bank_transaction_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                account_number = VALUES(account_number),
                bank_code = COALESCE(VALUES(bank_code), bank_code),
                iban = COALESCE(VALUES(iban), iban),
                source_manual = GREATEST(source_manual, VALUES(source_manual)),
                source_vat_registry = GREATEST(source_vat_registry, VALUES(source_vat_registry)),
                source_bank_statement = GREATEST(source_bank_statement, VALUES(source_bank_statement)),
                last_bank_transaction_id = COALESCE(VALUES(last_bank_transaction_id), last_bank_transaction_id),
                is_active = 1,
                last_seen_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            $supplierId,
            $clientId,
            $account['account_number'],
            $account['bank_code'],
            $account['iban'],
            $account['account_key'],
            $account['bank_key'],
            $manual ? 1 : 0,
            $vatRegistry ? 1 : 0,
            $bankStatement ? 1 : 0,
            $transactionId !== null && $transactionId > 0 ? $transactionId : null,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array{account_number:string,bank_code:?string,iban:?string,account_key:string,bank_key:string} */
    private function normalizeInput(string $accountNumber, ?string $bankCode, ?string $iban): array
    {
        $accountNumber = trim($accountNumber);
        $bankCode = trim((string) $bankCode);
        $iban = strtoupper((string) preg_replace('/\s+/', '', trim((string) $iban)));

        if (str_contains($accountNumber, '/')) {
            [$numberPart, $inlineBank] = array_pad(explode('/', $accountNumber, 2), 2, '');
            $accountNumber = trim($numberPart);
            if ($bankCode === '') {
                $bankCode = trim($inlineBank);
            }
        }
        $compact = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $accountNumber));
        $looksLikeIban = preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]+$/', $compact) === 1;
        if ($iban === '' && $looksLikeIban) {
            $iban = $compact;
        }
        if ($looksLikeIban) {
            if (!$this->ibanValidator->isValid($compact)) {
                throw new \InvalidArgumentException('IBAN není platný.');
            }
            $accountNumber = $compact;
            if (str_starts_with($compact, 'CZ') && strlen($compact) === 24) {
                $bankCode = $bankCode !== '' ? $bankCode : substr($compact, 4, 4);
                $accountKey = ltrim(substr($compact, 8, 16), '0');
            } else {
                $accountKey = $compact;
            }
        } else {
            $digits = preg_replace('/\D/', '', $accountNumber) ?? '';
            $accountKey = ltrim($digits, '0');
            // Český účet ukládej kanonicky (`předčíslí-číslo` bez vodicích nul), ne jako
            // 16místný tvar z GPC výpisu. Klíč účtu se tím nemění.
            if (preg_match('/^\d{4}$/D', $bankCode) === 1) {
                $accountNumber = AccountNumberNormalizer::czechNational($accountNumber) ?? $accountNumber;
            }
        }
        $bankKey = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $bankCode));
        if ($accountKey === '') {
            throw new \InvalidArgumentException('Číslo bankovního účtu je povinné.');
        }
        if (mb_strlen($accountNumber) > 40 || mb_strlen($bankCode) > 11 || mb_strlen($iban) > 34) {
            throw new \InvalidArgumentException('Bankovní účet je příliš dlouhý.');
        }
        return [
            'account_number' => $accountNumber,
            'bank_code' => $bankCode !== '' ? $bankCode : null,
            'iban' => $iban !== '' ? $iban : null,
            'account_key' => $accountKey,
            'bank_key' => $bankKey,
        ];
    }

    private function assertClientOwned(int $clientId, int $supplierId): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM clients WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$clientId, $supplierId]);
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException('Obchodní partner nebyl nalezen.');
        }
    }

    /** @return array<string,mixed> */
    private static function cast(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['client_id'] = (int) $row['client_id'];
        $row['source_manual'] = (bool) $row['source_manual'];
        $row['source_vat_registry'] = (bool) $row['source_vat_registry'];
        $row['source_bank_statement'] = (bool) $row['source_bank_statement'];
        $row['last_bank_transaction_id'] = $row['last_bank_transaction_id'] !== null
            ? (int) $row['last_bank_transaction_id'] : null;
        $row['is_active'] = (bool) $row['is_active'];
        return $row;
    }
}
