<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\DocumentViewerContext;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;

final class PayrollStatutoryObligationService
{
    private const ATTESTATION_VERSION = 'manual-statutory-evidence.v2';

    public function __construct(
        private readonly Connection $db,
        private readonly DocumentRepository $documents,
        private readonly ActivityLogger $logger,
        private readonly PayrollStatutoryAgendaCatalog $catalog,
    ) {}

    /** @return array<string,mixed> */
    public function overview(
        int $supplierId,
        string $environment,
        string $period,
    ): array {
        self::assertEnvironment($environment);
        $matrix = $this->catalog->forPeriod($period);
        [$periodStart, $periodEnd] = self::periodBounds($period);
        $stmt = $this->db->pdo()->prepare(
            'SELECT evidence.id, evidence.environment, evidence.agenda_code,
                    evidence.employee_id, employee.full_name,
                    evidence.period_start, evidence.period_end,
                    evidence.case_reference, evidence.receipt_reference,
                    evidence.completed_on, evidence.payment_amount_minor,
                    evidence.payment_currency, evidence.document_id,
                    evidence.document_sha256,
                    evidence.capability_matrix_version,
                    evidence.capability_matrix_sha256,
                    evidence.attestation_version, evidence.created_by,
                    evidence.created_at, document.title AS document_title
               FROM payroll_statutory_obligation_evidence evidence
          LEFT JOIN payroll_employees employee
                 ON employee.supplier_id = evidence.supplier_id
                AND employee.id = evidence.employee_id
               JOIN documents document
                 ON document.supplier_id = evidence.supplier_id
                AND document.id = evidence.document_id
              WHERE evidence.supplier_id = ?
                AND evidence.environment = ?
                AND evidence.period_start = ?
                AND evidence.period_end = ?
              ORDER BY evidence.created_at DESC, evidence.id DESC
              LIMIT 200'
        );
        $stmt->execute([$supplierId, $environment, $periodStart, $periodEnd]);

        return [
            'environment' => $environment,
            'period' => $period,
            'matrix_version' => $matrix['version'],
            'matrix_sha256' => $matrix['sha256'],
            'agendas' => $matrix['agendas'],
            'evidence' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /**
     * @param array<array-key,mixed> $input
     * @return array{evidence:array<string,mixed>,created:bool}
     */
    public function recordEvidence(
        int $supplierId,
        string $environment,
        string $period,
        array $input,
        string $idempotencyKey,
        int $actorUserId,
    ): array {
        self::assertEnvironment($environment);
        if ($supplierId <= 0 || $actorUserId <= 0) {
            throw new \InvalidArgumentException('Firma a uživatel musí být platné.');
        }
        if (strlen($idempotencyKey) < 1 || strlen($idempotencyKey) > 190) {
            throw new \InvalidArgumentException(
                'Hlavička Idempotency-Key musí mít 1 až 190 bajtů.',
            );
        }
        $agendaCode = self::requiredText($input['agenda_code'] ?? null, 'Agenda', 40);
        if (!in_array($agendaCode, [
            'NEMPRI',
            'HZUPN',
            'STATUTORY_ACCIDENT_INSURANCE',
        ], true)) {
            throw new \InvalidArgumentException(
                'Pro tuto agendu nelze důkaz v tomto workflow uložit.',
            );
        }
        $accidentInsurance = $agendaCode === 'STATUTORY_ACCIDENT_INSURANCE';
        if (!$accidentInsurance
            && ($input['manual_submission_confirmed'] ?? null) !== true
        ) {
            throw new \InvalidArgumentException(
                'Je nutné výslovně potvrdit ruční odeslání oficiálním kanálem.',
            );
        }
        if ($accidentInsurance
            && ($input['manual_payment_confirmed'] ?? null) !== true
        ) {
            throw new \InvalidArgumentException(
                'Je nutné potvrdit externě ověřenou částku a skutečně provedenou úhradu.',
            );
        }
        $employeeId = $accidentInsurance
            ? null
            : self::positiveInt($input['employee_id'] ?? null, 'Zaměstnanec');
        $paymentAmountMinor = $accidentInsurance
            ? self::paymentAmountMinor($input['payment_amount'] ?? null)
            : null;
        $paymentCurrency = $accidentInsurance ? 'CZK' : null;
        $documentId = self::positiveInt($input['document_id'] ?? null, 'Dokument');
        $caseReference = self::requiredText(
            $input['case_reference'] ?? null,
            'Reference případu',
            128,
        );
        $receiptReference = self::requiredText(
            $input['receipt_reference'] ?? null,
            'Reference potvrzení',
            128,
        );
        $completedOn = self::date($input['completed_on'] ?? null, 'Datum splnění');
        if ($completedOn > (new \DateTimeImmutable('today'))->format('Y-m-d')) {
            throw new \InvalidArgumentException('Datum splnění nesmí být v budoucnosti.');
        }
        [$periodStart, $periodEnd] = self::periodBounds($period);
        $matrix = $this->catalog->forPeriod($period);
        $agenda = array_values(array_filter(
            $matrix['agendas'],
            static fn (array $item): bool => $item['agenda_code'] === $agendaCode,
        ))[0] ?? null;
        if (!is_array($agenda) || $agenda['evidence_supported'] !== true) {
            throw new \DomainException(
                'Pro tuto agendu a období není uložení důkazu podporováno.',
            );
        }

        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $supplierLock = $pdo->prepare('SELECT id FROM supplier WHERE id = ? FOR UPDATE');
            $supplierLock->execute([$supplierId]);
            if ($supplierLock->fetchColumn() === false) {
                throw new \OutOfBoundsException('Firma nebyla nalezena.');
            }
            if ($employeeId !== null) {
                $employee = $pdo->prepare(
                    'SELECT id FROM payroll_employees
                      WHERE supplier_id = ? AND id = ? FOR UPDATE'
                );
                $employee->execute([$supplierId, $employeeId]);
                if ($employee->fetchColumn() === false) {
                    throw new \OutOfBoundsException(
                        'Zaměstnanec nebyl v této firmě nalezen.',
                    );
                }
            }
            $document = $this->documents->findActiveReferenceForUpdate(
                $documentId,
                $supplierId,
                DocumentViewerContext::internalCompany(),
            );
            if ($document === null) {
                throw new \InvalidArgumentException(
                    'Důkaz musí být aktivní firemní dokument této firmy.',
                );
            }
            $documentSha256 = strtolower($document['sha256']);
            if (preg_match('/^[0-9a-f]{64}$/D', $documentSha256) !== 1) {
                throw new \LogicException('Firemní dokument nemá platný SHA-256 otisk.');
            }

            $fingerprintData = [
                'supplier_id' => $supplierId,
                'environment' => $environment,
                'agenda_code' => $agendaCode,
                'employee_id' => $employeeId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'case_reference' => $caseReference,
                'receipt_reference' => $receiptReference,
                'completed_on' => $completedOn,
                'payment_amount_minor' => $paymentAmountMinor,
                'payment_currency' => $paymentCurrency,
                'document_id' => $documentId,
                'document_sha256' => $documentSha256,
                'capability_matrix_version' => $matrix['version'],
                'capability_matrix_sha256' => $matrix['sha256'],
                'attestation_version' => self::ATTESTATION_VERSION,
            ];
            $fingerprint = hash('sha256', CanonicalJson::encode($fingerprintData));
            $keyHash = hash('sha256', $idempotencyKey, true);
            $existing = $this->findByIdempotencyKey(
                $supplierId,
                $environment,
                $keyHash,
            );
            if ($existing !== null) {
                $existingFingerprint = $existing['request_fingerprint'] ?? null;
                if (!is_string($existingFingerprint)
                    || preg_match('/^[0-9a-f]{64}$/D', $existingFingerprint) !== 1
                    || !hash_equals($existingFingerprint, $fingerprint)
                ) {
                    throw new \DomainException(
                        'Idempotency-Key už byl použit pro jiný důkaz.',
                    );
                }
                if ($ownsTransaction) {
                    $pdo->commit();
                }

                return [
                    'evidence' => self::publicEvidence($existing),
                    'created' => false,
                ];
            }

            $insert = $pdo->prepare(
                'INSERT INTO payroll_statutory_obligation_evidence
                    (supplier_id, environment, agenda_code, employee_id,
                     period_start, period_end, case_reference,
                     receipt_reference, completed_on, payment_amount_minor,
                     payment_currency, document_id,
                     document_sha256, capability_matrix_version,
                     capability_matrix_sha256, attestation_version,
                     request_fingerprint, idempotency_key_hash, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $supplierId,
                $environment,
                $agendaCode,
                $employeeId,
                $periodStart,
                $periodEnd,
                $caseReference,
                $receiptReference,
                $completedOn,
                $paymentAmountMinor,
                $paymentCurrency,
                $documentId,
                $documentSha256,
                $matrix['version'],
                $matrix['sha256'],
                self::ATTESTATION_VERSION,
                $fingerprint,
                $keyHash,
                $actorUserId,
            ]);
            $id = (int) $pdo->lastInsertId();
            $this->logger->log(
                'payroll.stat_obligation.evidence_recorded',
                $actorUserId,
                'payroll_statutory_obligation_evidence',
                $id,
                [
                    'agenda_code' => $agendaCode,
                    'environment' => $environment,
                    'period' => $period,
                    'employee_id' => $employeeId,
                    'payment_amount_minor' => $paymentAmountMinor,
                    'payment_currency' => $paymentCurrency,
                    'document_id' => $documentId,
                    'document_sha256' => $documentSha256,
                    'capability_matrix_sha256' => $matrix['sha256'],
                ],
                null,
                null,
                $supplierId,
            );
            $created = $this->findById($supplierId, $id);
            if ($created === null) {
                throw new \LogicException('Uložený důkaz nelze znovu načíst.');
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return [
                'evidence' => self::publicEvidence($created),
                'created' => true,
            ];
        } catch (\Throwable $exception) {
            self::rollbackOwnedTransaction($pdo, $ownsTransaction);
            throw $exception;
        }
    }

    /** @return array<string,mixed>|null */
    private function findByIdempotencyKey(
        int $supplierId,
        string $environment,
        string $keyHash,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT evidence.*, employee.full_name,
                    document.title AS document_title
               FROM payroll_statutory_obligation_evidence evidence
          LEFT JOIN payroll_employees employee
                 ON employee.supplier_id = evidence.supplier_id
                AND employee.id = evidence.employee_id
               JOIN documents document
                 ON document.supplier_id = evidence.supplier_id
                AND document.id = evidence.document_id
              WHERE evidence.supplier_id = ? AND evidence.environment = ?
                AND evidence.idempotency_key_hash = ? FOR UPDATE'
        );
        $stmt->execute([$supplierId, $environment, $keyHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return self::associativeRow($row);
    }

    /** @return array<string,mixed>|null */
    private function findById(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT evidence.*, employee.full_name,
                    document.title AS document_title
               FROM payroll_statutory_obligation_evidence evidence
          LEFT JOIN payroll_employees employee
                 ON employee.supplier_id = evidence.supplier_id
                AND employee.id = evidence.employee_id
               JOIN documents document
                 ON document.supplier_id = evidence.supplier_id
                AND document.id = evidence.document_id
              WHERE evidence.supplier_id = ? AND evidence.id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return self::associativeRow($row);
    }

    private static function assertEnvironment(string $environment): void
    {
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new \InvalidArgumentException(
                'Prostředí musí být production nebo test.',
            );
        }
    }

    /** @return array{string,string} */
    private static function periodBounds(string $period): array
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $period . '-01');
        if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $period) !== 1
            || !$start instanceof \DateTimeImmutable
        ) {
            throw new \InvalidArgumentException('Období musí mít formát RRRR-MM.');
        }

        return [
            $start->format('Y-m-d'),
            $start->modify('last day of this month')->format('Y-m-d'),
        ];
    }

    private static function positiveInt(mixed $value, string $label): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($validated === false) {
            throw new \InvalidArgumentException($label . ' musí být vybrán.');
        }

        return (int) $validated;
    }

    private static function paymentAmountMinor(mixed $value): int
    {
        $text = is_string($value) || is_int($value) || is_float($value)
            ? trim((string) $value)
            : '';
        if (preg_match('/^[0-9]{1,12}(?:[.,][0-9]{1,2})?$/D', $text) !== 1) {
            throw new \InvalidArgumentException(
                'Uhrazená částka musí být kladná částka v CZK s nejvýše dvěma desetinnými místy.',
            );
        }
        $parts = preg_split('/[.,]/', $text, 2);
        $whole = $parts[0] ?? '';
        $fraction = $parts[1] ?? '';
        $minor = ((int) $whole * 100)
            + (int) str_pad($fraction, 2, '0', STR_PAD_RIGHT);
        if ($minor <= 0) {
            throw new \InvalidArgumentException(
                'Uhrazená částka musí být větší než nula.',
            );
        }

        return $minor;
    }

    private static function requiredText(
        mixed $value,
        string $label,
        int $maxLength,
    ): string {
        $text = is_string($value) ? trim($value) : '';
        if ($text === '' || mb_strlen($text) > $maxLength) {
            throw new \InvalidArgumentException(
                $label . " je povinná a smí mít nejvýše {$maxLength} znaků.",
            );
        }

        return $text;
    }

    private static function date(mixed $value, string $label): string
    {
        $text = is_string($value) ? trim($value) : '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if (!$date instanceof \DateTimeImmutable
            || $date->format('Y-m-d') !== $text
        ) {
            throw new \InvalidArgumentException($label . ' musí být platné datum.');
        }

        return $text;
    }

    private static function rollbackOwnedTransaction(PDO $pdo, bool $ownsTransaction): void
    {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    /** @return array<string,mixed>|null */
    private static function associativeRow(mixed $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }

        $result = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function publicEvidence(array $row): array
    {
        unset($row['idempotency_key_hash']);

        return $row;
    }
}
