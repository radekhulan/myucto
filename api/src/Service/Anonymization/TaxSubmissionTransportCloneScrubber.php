<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TriggerMetadata;
use PDO;

/**
 * Transport podání, jejichž XML kopie přepsala podle finančního archivu.
 *
 * Doklad o odeslání zdrojového XML se nepřepočítává na nové XML: otisky požadavků,
 * reference EPO, stavy i potvrzení zůstávají jako záznam zdrojové instalace. Každé takové
 * podání dostane řádek mapy (zdrojový a cílový otisk XML, otisk tehdejšího transportu
 * a jeho třídu) a tím přestane jít odeslat. Rozpracovaná, ještě neodeslaná práce se v kopii
 * zruší; nejistý, odeslaný, doručený i potvrzený transport zůstává beze změny.
 */
final class TaxSubmissionTransportCloneScrubber
{
    private const PRE_DISPATCH_ATTEMPTS = ['prepared', 'handoff_created', 'awaiting_confirmation', 'testing', 'test_passed', 'test_failed', 'failed', 'expired', 'cancelled'];

    private const PRE_DISPATCH_OUTBOX = ['ready', 'failed', 'cancelled'];

    public function __construct(private readonly Connection $db) {}

    /**
     * @param list<array{supplier_id:int, tax_submission_id:int, source_xml_sha256:string, target_xml_sha256:string}> $submissions
     * @return array{root:string, count:int}
     */
    public function run(array $submissions): array
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()
            || TriggerMetadata::any($pdo)) {
            throw new \LogicException('Mapa transportu podání musí vzniknout před triggery a mimo transakci.');
        }
        $root = hash('sha256', 'anonymized-clone-transport-v1');
        $insert = $pdo->prepare(
            'INSERT INTO anonymized_clone_submission_provenance
                (supplier_id, tax_submission_id, source_xml_sha256, target_xml_sha256, transport_receipt_root, transport_class,
                 attempt_count, status_event_count, artifact_count, outbox_count, outbox_attempt_count)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        foreach ($submissions as $submission) {
            $pdo->beginTransaction();
            try {
                $evidence = $this->evidence($submission['supplier_id'], $submission['tax_submission_id']);
                $receipt = hash('sha256', json_encode($evidence['rows'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                $class = $this->classify($evidence['rows']);
                $insert->execute([
                    $submission['supplier_id'], $submission['tax_submission_id'], $submission['source_xml_sha256'], $submission['target_xml_sha256'],
                    $receipt, $class, $evidence['counts']['attempts'], $evidence['counts']['events'], $evidence['counts']['artifacts'],
                    $evidence['counts']['outbox'], $evidence['counts']['outbox_attempts'],
                ]);
                if ($class === 'cancelled_before_dispatch') {
                    $this->detach($submission['supplier_id'], $submission['tax_submission_id']);
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            $root = hash('sha256', $root . "\0" . $submission['supplier_id'] . "\0" . $submission['tax_submission_id']
                . "\0" . $submission['source_xml_sha256'] . "\0" . $submission['target_xml_sha256'] . "\0" . $receipt . "\0" . $class);
        }

        return ['root' => $root, 'count' => count($submissions)];
    }

    /**
     * Ponechané (ne pseudonymizované) údaje transportu jednoho podání v pevném pořadí.
     *
     * @return array{rows:array<string, list<array<string,mixed>>>, counts:array<string,int>}
     */
    private function evidence(int $supplierId, int $submissionId): array
    {
        $pdo = $this->db->pdo();
        $queries = [
            'attempts' => 'SELECT id, channel, epo_environment, status, request_sha256, remote_submission_ref, submitted_at, confirmed_at
                             FROM tax_submission_attempts WHERE supplier_id = ? AND tax_submission_id = ? ORDER BY id FOR UPDATE',
            'events' => 'SELECT id, attempt_id, epo_environment, event_type, status, http_status, created_at
                           FROM tax_submission_status_events WHERE supplier_id = ? AND tax_submission_id = ? ORDER BY id',
            'artifacts' => 'SELECT id, attempt_id, artifact_kind, sha256, verification_status
                              FROM tax_submission_artifacts WHERE supplier_id = ? AND tax_submission_id = ? ORDER BY id',
            'outbox' => "SELECT id, environment, channel, dispatch_state, acceptance_state, artifact_sha256, correlation_reference, external_message_id, sent_at, delivered_at, accepted_at, rejected_at
                           FROM submission_outbox WHERE supplier_id = ? AND artifact_kind = 'tax_submission' AND artifact_id = ? ORDER BY id FOR UPDATE",
        ];
        $rows = [];
        foreach ($queries as $key => $sql) {
            $statement = $pdo->prepare($sql);
            $statement->execute([$supplierId, $submissionId]);
            $rows[$key] = $statement->fetchAll(PDO::FETCH_ASSOC);
        }
        $rows['outbox_attempts'] = [];
        $attempts = $pdo->prepare('SELECT id, outbox_id, attempt_no, outcome, request_sha256, external_message_id FROM submission_outbox_attempts WHERE outbox_id = ? ORDER BY id');
        foreach ($rows['outbox'] as $outbox) {
            $attempts->execute([(int) $outbox['id']]);
            array_push($rows['outbox_attempts'], ...$attempts->fetchAll(PDO::FETCH_ASSOC));
        }

        return ['rows' => $rows, 'counts' => array_map(count(...), $rows)];
    }

    /** @param array<string, list<array<string,mixed>>> $rows */
    private function classify(array $rows): string
    {
        if ($rows['attempts'] === [] && $rows['outbox'] === []) {
            return 'no_transport';
        }
        foreach ($rows['attempts'] as $attempt) {
            if (!in_array($attempt['status'], self::PRE_DISPATCH_ATTEMPTS, true)) {
                return 'source_provenance';
            }
        }
        foreach ($rows['outbox'] as $outbox) {
            if (!in_array($outbox['dispatch_state'], self::PRE_DISPATCH_OUTBOX, true) || ($outbox['acceptance_state'] ?? 'unknown') !== 'unknown') {
                return 'source_provenance';
            }
        }

        return $rows['outbox_attempts'] === [] ? 'cancelled_before_dispatch' : 'source_provenance';
    }

    private function detach(int $supplierId, int $submissionId): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE tax_submission_attempts SET status = 'cancelled', next_poll_at = NULL
                        WHERE supplier_id = ? AND tax_submission_id = ? AND status <> 'cancelled'")
            ->execute([$supplierId, $submissionId]);
        $pdo->prepare("UPDATE submission_outbox SET dispatch_state = 'cancelled', row_version = row_version + 1
                        WHERE supplier_id = ? AND artifact_kind = 'tax_submission' AND artifact_id = ? AND dispatch_state <> 'cancelled'")
            ->execute([$supplierId, $submissionId]);
    }
}
