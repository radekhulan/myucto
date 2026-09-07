<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;

/**
 * Trvalé smazání pokusu o odeslání z historie.
 *
 * PROČ TO EXISTUJE
 * ------------------------------------------------------------------------------
 * Ledger pokusů je záměrně append-only a běžná cesta ven je zahození
 * ({@see PayrollSubmissionAbandonService}) — pokus dostane terminální stav
 * a v historii zůstane i s tím, co úřad odpověděl. Jenže po nepovedeném
 * prvním odeslání (třeba certifikátem, který ČSSZ nemá v registru podávajících)
 * zůstane v přehledu viset záznam, který nic nedokládá a jen mate: účetní u něj
 * vidí otevřenou transakci, přestože povinnost je dávno podaná jinou cestou.
 * Tohle je páka, jak takový záznam odklidit úplně.
 *
 * CO SE SMAZAT NESMÍ
 * ------------------------------------------------------------------------------
 * Cokoli, co je DŮKAZ o odeslání. Konkrétně:
 *
 *   - pokus ve stavu `completed` — ten protokol od úřadu dostal,
 *   - pokus, na jehož `correlation_reference` visí dodejka nebo protokol,
 *   - pokus, jehož `correlation_reference` nese samo podání — tím je podání
 *     u úřadu identifikované a bez něj by nešlo dohledat, co se odeslalo.
 *
 * Zbývá tedy přesně to, co úřad nikdy nepřijal. Smazání se zapisuje do
 * auditního logu (volající), protože po řádku samotném nezůstane nic.
 */
final readonly class PayrollSubmissionAttemptDeletionService
{
    public function __construct(
        private Connection $db,
        private PayrollSubmissionTransportAttemptRepository $attempts,
    ) {}

    /**
     * Vrátí důvod, proč pokus smazat nejde, nebo `null`, když jde.
     *
     * Oddělené od {@see delete()}, aby si UI mohlo tlačítko rovnou schovat
     * a nenabízelo akci, která stejně skončí chybou.
     *
     * @param array<string,mixed> $attempt
     */
    public function blockedReason(int $supplierId, string $environment, array $attempt): ?string
    {
        if ((string) ($attempt['status'] ?? '') === 'completed') {
            return 'Pokus dostal od úřadu protokol o zpracování — je to doklad o odeslání'
                . ' a z historie se nemaže. Použijte zahození pokusu.';
        }

        $correlation = trim((string) ($attempt['correlation_reference'] ?? ''));
        if ($correlation === '') {
            return null;
        }

        $submissionId = (int) ($attempt['submission_id'] ?? 0);
        if ($this->receiptExists($supplierId, $environment, $submissionId, $correlation)) {
            return 'K pokusu je připnutá dodejka nebo protokol — je to doklad o odeslání'
                . ' a z historie se nemaže. Použijte zahození pokusu.';
        }
        if ($this->submissionIdentifiedBy($supplierId, $environment, $submissionId, $correlation)) {
            return 'Podání je u úřadu vedené právě pod identifikátorem tohoto pokusu.'
                . ' Smazáním by se ztratila jediná stopa, čím se odeslalo.';
        }

        return null;
    }

    /**
     * @return array{deleted:true,attempt_id:int,submission_id:int,attempt_no:int,channel:string,status:string,correlation_reference:?string}
     */
    public function delete(
        int $supplierId,
        string $environment,
        int $attemptId,
        int $expectedRowVersion,
    ): array {
        $attempt = $this->attempts->find($supplierId, $environment, $attemptId);
        if ($attempt === null) {
            throw new \DomainException('Pokus o odeslání nebyl nalezen.');
        }
        $blocked = $this->blockedReason($supplierId, $environment, $attempt);
        if ($blocked !== null) {
            throw new \DomainException($blocked);
        }

        // Snapshot PŘED smazáním — po něm už není z čeho auditní zápis složit.
        $snapshot = [
            'deleted' => true,
            'attempt_id' => (int) $attempt['id'],
            'submission_id' => (int) $attempt['submission_id'],
            'attempt_no' => (int) $attempt['attempt_no'],
            'channel' => (string) $attempt['channel'],
            'status' => (string) $attempt['status'],
            'correlation_reference' => isset($attempt['correlation_reference'])
                && (string) $attempt['correlation_reference'] !== ''
                    ? (string) $attempt['correlation_reference']
                    : null,
        ];

        $this->attempts->delete($attemptId, $expectedRowVersion);

        return $snapshot;
    }

    private function receiptExists(
        int $supplierId,
        string $environment,
        int $submissionId,
        string $correlation,
    ): bool {
        $statement = $this->db->pdo()->prepare(
            'SELECT 1 FROM payroll_submission_receipts
              WHERE supplier_id = ? AND environment = ? AND submission_id = ?
                AND correlation_reference = ?
              LIMIT 1',
        );
        $statement->execute([$supplierId, $environment, $submissionId, $correlation]);

        return $statement->fetchColumn() !== false;
    }

    private function submissionIdentifiedBy(
        int $supplierId,
        string $environment,
        int $submissionId,
        string $correlation,
    ): bool {
        $statement = $this->db->pdo()->prepare(
            'SELECT 1 FROM payroll_submissions
              WHERE supplier_id = ? AND environment = ? AND id = ?
                AND correlation_reference = ?
              LIMIT 1',
        );
        $statement->execute([$supplierId, $environment, $submissionId, $correlation]);

        return $statement->fetchColumn() !== false;
    }
}
