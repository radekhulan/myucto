<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\DocumentViewerContext;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;

final readonly class PayrollInsolvencyPaymentInstructionService
{
    public function __construct(
        private Connection $db,
        private DocumentRepository $documents,
    ) {}

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function resolve(
        int $supplierId,
        int $employeeId,
        string $periodStart,
        array $data,
        int $actorUserId,
    ): array {
        $employmentId = $this->positiveInt(
            $data['insolvency_employment_id'] ?? null,
            'insolvency_employment_id',
        );
        $accountId = $this->positiveInt(
            $data['insolvency_institution_account_id'] ?? null,
            'insolvency_institution_account_id',
        );
        $documentId = $this->positiveInt(
            $data['insolvency_decision_document_id'] ?? null,
            'insolvency_decision_document_id',
        );
        $requestedId = $this->nullablePositiveInt(
            $data['insolvency_payment_instruction_id'] ?? null,
            'insolvency_payment_instruction_id',
        );
        if ($actorUserId <= 0) {
            throw new \InvalidArgumentException(
                'Uživatel platebního pokynu oddlužení není platný.',
            );
        }

        $pdo = $this->db->pdo();
        $employment = $pdo->prepare(
            'SELECT id
               FROM payroll_employments
              WHERE supplier_id = ? AND id = ? AND employee_id = ?
                AND status IN ("active", "ended")
                AND COALESCE(actual_start_date, start_date, "1900-01-01")
                    <= LAST_DAY(?)
                AND (end_date IS NULL OR end_date >= ?)
              FOR UPDATE',
        );
        $employment->execute([
            $supplierId,
            $employmentId,
            $employeeId,
            $periodStart,
            $periodStart,
        ]);
        if ($employment->fetchColumn() === false) {
            throw new \DomainException(
                'Pracovní vztah platebního pokynu oddlužení nepatří osobě '
                . 'nebo nebyl v měsíci účinný.',
            );
        }

        $accountStatement = $pdo->prepare(
            'SELECT account.id, account.row_version,
                    LOWER(HEX(account.bank_account_hash)) AS account_hash,
                    account.source_kind, account.source_reference,
                    account.verified_on, account.verified_by,
                    account.currency_code, account.valid_from, account.valid_to,
                    institution.institution_type, institution.institution_code
               FROM payroll_institution_accounts account
               JOIN payroll_institutions institution
                 ON institution.supplier_id = account.supplier_id
                AND institution.id = account.institution_id
              WHERE account.supplier_id = ? AND account.id = ?
              FOR UPDATE',
        );
        $accountStatement->execute([$supplierId, $accountId]);
        $account = $accountStatement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($account)
            || ($account['institution_type'] ?? null) !== 'other_recipient'
            || ($account['currency_code'] ?? null) !== 'CZK'
            || !in_array($account['source_kind'] ?? null, [
                'official_registry',
                'official_document',
                'institution_notice',
                'user_verified',
            ], true)
            || (int) ($account['verified_by'] ?? 0) <= 0
        ) {
            throw new \DomainException(
                'Platební pokyn oddlužení vyžaduje explicitně vybraný '
                . 'ověřený účet příjemce.',
            );
        }
        $periodEnd = (new \DateTimeImmutable($periodStart))
            ->modify('last day of this month')
            ->format('Y-m-d');
        $validFrom = (string) ($account['valid_from'] ?? '');
        $validTo = $account['valid_to'] ?? null;
        if ($validFrom === '' || $validFrom > $periodEnd
            || ($validTo !== null
                && (!is_string($validTo) || $validTo < $periodStart))
        ) {
            throw new \DomainException(
                'Vybraný účet příjemce oddlužení nebyl v měsíci účinný.',
            );
        }
        $accountHash = (string) ($account['account_hash'] ?? '');
        if (preg_match('/^[0-9a-f]{64}$/D', $accountHash) !== 1
            || $accountHash === str_repeat('0', 64)
        ) {
            throw new \DomainException(
                'Ověřený účet příjemce oddlužení nemá platný otisk.',
            );
        }

        $document = $this->documents->findActiveReferenceForUpdate(
            $documentId,
            $supplierId,
            DocumentViewerContext::companyOnly(),
        );
        $documentHash = $document['sha256'] ?? null;
        if (!is_string($documentHash)
            || preg_match('/^[0-9a-f]{64}$/D', $documentHash) !== 1
        ) {
            throw new \DomainException(
                'Rozhodnutí k platebnímu pokynu oddlužení nebylo nalezeno '
                . 've firemních dokumentech.',
            );
        }

        $material = [
            'schema_reference' =>
                'payroll-insolvency-payment-instruction.v1',
            'supplier_id' => $supplierId,
            'employee_id' => $employeeId,
            'employment_id' => $employmentId,
            'period_start' => $periodStart,
            'institution_account_id' => $accountId,
            'institution_account_row_version' => (int) $account['row_version'],
            'institution_account_hash' => $accountHash,
            'institution_type' => 'other_recipient',
            'institution_code' => (string) $account['institution_code'],
            'decision_document_id' => $documentId,
            'decision_document_hash' => $documentHash,
        ];
        $instructionHash = hash('sha256', CanonicalJson::encode($material));
        if ($requestedId !== null) {
            $existing = $this->findForUpdate($supplierId, $requestedId);
            if ($existing === null
                || !hash_equals(
                    (string) $existing['instruction_hash'],
                    $instructionHash,
                )
            ) {
                throw new \DomainException(
                    'Neměnný platební pokyn oddlužení neodpovídá zadanému '
                    . 'příjemci, účtu, pracovnímu vztahu nebo rozhodnutí.',
                );
            }

            return $existing;
        }

        $existing = $this->findByHashForUpdate(
            $supplierId,
            $instructionHash,
        );
        if ($existing !== null) {
            return $existing;
        }
        $insert = $pdo->prepare(
            'INSERT INTO payroll_insolvency_payment_instructions
                (supplier_id, employee_id, employment_id, period_start,
                 institution_account_id, institution_account_row_version,
                 institution_account_hash, institution_type, institution_code,
                 decision_document_id, decision_document_hash,
                 instruction_hash, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $insert->execute([
            $supplierId,
            $employeeId,
            $employmentId,
            $periodStart,
            $accountId,
            (int) $account['row_version'],
            $accountHash,
            'other_recipient',
            (string) $account['institution_code'],
            $documentId,
            $documentHash,
            $instructionHash,
            $actorUserId,
        ]);

        return $this->findForUpdate(
            $supplierId,
            (int) $pdo->lastInsertId(),
        ) ?? throw new \RuntimeException(
            'Platební pokyn oddlužení se po uložení nepodařilo načíst.',
        );
    }

    /** @return array<string,mixed>|null */
    private function findForUpdate(int $supplierId, int $id): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_insolvency_payment_instructions
              WHERE supplier_id = ? AND id = ? FOR UPDATE',
        );
        $statement->execute([$supplierId, $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function findByHashForUpdate(
        int $supplierId,
        string $instructionHash,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_insolvency_payment_instructions
              WHERE supplier_id = ? AND instruction_hash = ? FOR UPDATE',
        );
        $statement->execute([$supplierId, $instructionHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function positiveInt(mixed $value, string $field): int
    {
        $normalized = filter_var($value, FILTER_VALIDATE_INT);
        if ($normalized === false || $normalized <= 0) {
            throw new \InvalidArgumentException(
                "{$field} musí být kladné celé číslo.",
            );
        }

        return $normalized;
    }

    private function nullablePositiveInt(mixed $value, string $field): ?int
    {
        return $value === null ? null : $this->positiveInt($value, $field);
    }
}
