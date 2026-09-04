<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Submission\SubmissionOutboxRepository;
use MyInvoice\Service\Document\ZfoExtractor;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenPayloadReader;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenSubmissionIdentity;
use MyInvoice\Service\Payroll\Submission\PayrollReceiptVerifierInterface;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionDispatchProjection;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\PayrollVerifiedReceipt;
use MyInvoice\Service\Submission\Channel\InboxMessageHeader;
use MyInvoice\Service\Submission\InboxMessageClassifier;
use MyInvoice\Service\Submission\SubmissionOutboxService;
use MyInvoice\Service\Submission\SubmissionInboxMessageProcessor;

/**
 * Zpracování protokolu ČSSZ doručeného do datové schránky.
 *
 * ── Podle čeho se protokol váže na podání ────────────────────────────────────
 * Věc datové zprávy je vodítko, ne důkaz. Dokumentovaný tvar
 * `[classname-correlationId-dmId]` (protokol ČSSZ v1.47, str. 24) chodí jen
 * u některých odpovědí; protokol o kompletnosti — ten jediný nese OBSAHOVÝ
 * výsledek — `dmId` neuvádí vůbec. Vazbu proto rozhoduje OBSAH přílohy:
 * `idPodani`, variabilní symbol a rozhodné období se porovnají se zmrazenou
 * datovou větou. `idPodani` je GUID, který generuje klient, takže se protokol
 * páruje naším vlastním identifikátorem.
 *
 * ── Čemu se věří ─────────────────────────────────────────────────────────────
 * Obálka GovTalk se ověřuje pečetí ČSSZ jako u VREP. Nepodepsaný protokol
 * o zpracování je důvěryhodný jen tehdy, když ho doručila datová schránka ZE
 * SCHRÁNKY, DO KTERÉ jsme podání poslali, a když jeho obsah sedí na zmrazené
 * podání — viz {@see JmhzDeliveredProtocolVerifier}. Ručně nahraný soubor sem
 * nikdy nevstoupí; ten zůstává evidencí v {@see JmhzProtocolImportService}.
 */
final readonly class JmhzIsdsInboxProcessor implements SubmissionInboxMessageProcessor
{
    /** Agendy JMHZ tak, jak se zapisují do odchozí fronty. */
    private const AGENDA_CODES = ['JMHZ', 'JMHZ25'];

    public function __construct(
        private SubmissionOutboxRepository $outbox,
        private PayrollSubmissionRepository $payrollRepository,
        private PayrollSubmissionService $submissions,
        private PayrollSubmissionDispatchProjection $dispatchProjection,
        private SubmissionOutboxService $outboxService,
        private JmhzFrozenPayloadReader $frozen,
        private ZfoExtractor $zfo,
        private JmhzProtocolSignatureVerifierInterface $signatures,
        private JmhzProtocolParser $parser = new JmhzProtocolParser(),
    ) {}

    /**
     * @param array{classification:string,matched_outbox_id:?int} $verdict
     * @return array{status:string,code:?string,submission_id:?int,receipt_id:?int,remote_status:?string}
     */
    public function process(
        int $supplierId,
        string $environment,
        int $inboxMessageId,
        InboxMessageHeader $header,
        array $verdict,
        string $zfoBytes,
        ?int $actorUserId,
    ): array {
        if ($verdict['classification'] !== InboxMessageClassifier::CSSZ_PROTOCOL) {
            return self::result('not_applicable');
        }

        $candidates = $this->protocolAttachments($zfoBytes);
        if ($candidates === []) {
            return self::result('manual_review', 'jmhz_isds_protocol_attachment_missing');
        }
        if (count($candidates) > 1) {
            // Dvě čitelné datové věty v jedné zprávě neumíme rozsoudit a hádat
            // se tu nesmí: špatně přiřazený protokol přepíše stav podání.
            return self::result('manual_review', 'jmhz_isds_protocol_ambiguous');
        }
        [$report, $bytes] = $candidates[0];

        $binding = $this->bind($supplierId, $environment, $header, $verdict, $report);
        if (is_string($binding)) {
            return self::result('manual_review', $binding);
        }
        [$outbox, $submissionId, $identity] = $binding;

        $this->dispatchProjection->project(
            $supplierId,
            'payroll_submission',
            (int) $outbox['artifact_id'],
            trim((string) ($outbox['external_message_id'] ?? '')),
        );
        // Podání odešlo datovkou, i když ho zmrazení založilo na výchozím
        // kanálu agendy. Bez přepsání by `importReceipt()` skončil na tom, že
        // kanál protokolu neodpovídá podání.
        $submission = $this->submissions->adoptDispatchChannel(
            $supplierId,
            $submissionId,
            'isds',
        );
        if ((string) $submission['environment'] !== $environment) {
            return self::result(
                'manual_review',
                'jmhz_isds_payroll_submission_scope_mismatch',
                $submissionId,
            );
        }

        $declaredStatus = $report->status->payrollRemoteStatus();
        $idempotencyKey = 'jmhz-isds-inbox:' . $inboxMessageId
            . ':' . hash('sha256', $bytes);
        $verifier = $this->verifierFor($report, $supplierId, $environment, $submissionId, $identity);

        try {
            $receipt = $this->submissions->importReceipt(
                $supplierId,
                $submissionId,
                (int) $submission['row_version'],
                null,
                $bytes,
                $header->externalMessageId,
                $report->correlationReference,
                $report->submissionClass,
                $declaredStatus,
                'isds',
                $idempotencyKey,
                $actorUserId,
                $verifier,
            );
        } catch (\Throwable $exception) {
            $current = $this->submissions->get($supplierId, $submissionId);
            $receipt = $this->submissions->importReceipt(
                $supplierId,
                $submissionId,
                (int) $current['row_version'],
                null,
                $bytes,
                $header->externalMessageId,
                $report->correlationReference,
                $report->submissionClass,
                $declaredStatus,
                'isds',
                $idempotencyKey,
                $actorUserId,
                null,
            );

            return self::result(
                'manual_review',
                $exception instanceof JmhzTransportException
                    ? $exception->errorCode
                    : 'jmhz_isds_protocol_untrusted',
                $submissionId,
                (int) $receipt['id'],
            );
        }

        $this->outboxService->applyVerifiedProtocolOutcome(
            $supplierId,
            (int) $outbox['id'],
            $declaredStatus,
            'Ověřený protokol ČSSZ z datové schránky.',
        );

        return self::result(
            'processed',
            null,
            $submissionId,
            (int) $receipt['id'],
            $declaredStatus,
        );
    }

    /**
     * Kdo protokol ověří.
     *
     * Obálka GovTalk má pečeť a bez ní se nepřijímá. Protokol o zpracování
     * a odpověď DZMH pečeť nemají — u nich rozhoduje doručení do datové
     * schránky a shoda obsahu se zmrazeným podáním.
     */
    private function verifierFor(
        JmhzProtocolReport $report,
        int $supplierId,
        string $environment,
        int $submissionId,
        JmhzFrozenSubmissionIdentity $identity,
    ): PayrollReceiptVerifierInterface {
        if ($report->kind === JmhzProtocolKind::PartialSubmission) {
            return $this->sealedVerifier($report->correlationReference);
        }

        return new JmhzDeliveredProtocolVerifier(
            $identity,
            $this->frozen->formGuids($supplierId, $environment, $submissionId),
            [],
            $this->parser,
        );
    }

    /**
     * Najde podání, kterému protokol patří.
     *
     * @param array{classification:string,matched_outbox_id:?int} $verdict
     * @return array{0:array<string,mixed>,1:int,2:JmhzFrozenSubmissionIdentity}|string
     *         chybový kód, když vazba není jednoznačná
     */
    private function bind(
        int $supplierId,
        string $environment,
        InboxMessageHeader $header,
        array $verdict,
        JmhzProtocolReport $report,
    ): array|string {
        if ($report->kind === JmhzProtocolKind::PartialSubmission
            && $verdict['matched_outbox_id'] === null
        ) {
            // Obálka GovTalk nenese ani variabilní symbol, ani období, ani
            // `idPodani`. Bez vazby z věci zprávy není k čemu ji přiřadit
            // a „vzít první podání, které se namane" je přesně ta chyba,
            // kvůli které by se stav zapsal cizímu hlášení.
            return 'jmhz_isds_response_unmatched';
        }

        $matches = [];
        foreach ($this->candidateOutboxRows($supplierId, $environment, $verdict) as $row) {
            $artifact = $this->payrollRepository->findArtifact(
                $supplierId,
                (int) $row['artifact_id'],
            );
            if ($artifact === null
                || (string) $artifact['environment'] !== $environment
                || (string) $artifact['direction'] !== 'outbound'
            ) {
                continue;
            }
            $submissionId = (int) $artifact['submission_id'];
            try {
                $identity = $this->frozen->identity($supplierId, $environment, $submissionId);
            } catch (\Throwable) {
                continue;
            }
            if (!self::identityMatches($identity, $report)) {
                continue;
            }
            // Odpověď musí přijít ze schránky, DO KTERÉ podání odešlo. Tohle je
            // celá důvěra nepodepsaného protokolu: podvrhnout ho znamená ovládat
            // úřední schránku ČSSZ.
            $senderBox = strtolower(trim((string) ($header->senderBoxId ?? '')));
            $recipientBox = strtolower(trim((string) ($row['recipient_box_id'] ?? '')));
            if ($senderBox === '' || $senderBox !== $recipientBox) {
                continue;
            }
            $matches[] = [$row, $submissionId, $identity];
        }

        if ($matches === []) {
            return 'jmhz_isds_response_unmatched';
        }
        if (count($matches) > 1) {
            return 'jmhz_isds_response_ambiguous';
        }

        return $matches[0];
    }

    /**
     * Odchozí zprávy, které přicházejí v úvahu. Když klasifikace navázala
     * konkrétní zprávu podle `dmId` z věci, bere se jen ta; jinak se prochází
     * odeslaná podání JMHZ a rozhodne obsah protokolu.
     *
     * @param array{classification:string,matched_outbox_id:?int} $verdict
     * @return list<array<string,mixed>>
     */
    private function candidateOutboxRows(
        int $supplierId,
        string $environment,
        array $verdict,
    ): array {
        if ($verdict['matched_outbox_id'] !== null) {
            $row = $this->outbox->find($supplierId, (int) $verdict['matched_outbox_id']);

            return $row !== null && self::isJmhzIsdsRow($row, $environment) ? [$row] : [];
        }

        $rows = [];
        foreach ($this->outbox->listForSupplier($supplierId, $environment) as $row) {
            if (self::isJmhzIsdsRow($row, $environment)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @param array<string,mixed> $row */
    private static function isJmhzIsdsRow(array $row, string $environment): bool
    {
        return (string) $row['environment'] === $environment
            && (string) $row['channel'] === 'isds'
            && (string) $row['artifact_kind'] === 'payroll_submission'
            && in_array(
                strtoupper((string) $row['agenda_code']),
                self::AGENDA_CODES,
                true,
            )
            && (int) ($row['artifact_id'] ?? 0) > 0;
    }

    /**
     * Obálka GovTalk identitu podání nenese vůbec (nemá VS ani období), takže
     * u ní rozhoduje vazba z věci zprávy. Protokol o zpracování ji nese celou
     * a musí sedět beze zbytku.
     */
    private static function identityMatches(
        JmhzFrozenSubmissionIdentity $identity,
        JmhzProtocolReport $report,
    ): bool {
        if ($report->kind === JmhzProtocolKind::PartialSubmission) {
            // Obálka se váže výhradně přes `dmId` z věci zprávy — o to se stará
            // volající, který sem takový protokol pustí jen s navázanou frontou.
            return true;
        }
        $guid = $report->submissionGuid === null
            ? null
            : strtoupper($report->submissionGuid);

        return $guid === $identity->submissionGuid
            && $report->variableSymbol === $identity->variableSymbol
            && $report->periodMonth === $identity->month
            && $report->periodYear === $identity->year;
    }

    /**
     * Přílohy, které se dají přečíst jako datová věta protokolu JMHZ.
     *
     * Nehledá se podle NÁZVU přílohy: doložený tvar
     * `ČSSZ_Protokol_o_zpracování_e-Podání_{…}.xml` chodí jen u části odpovědí,
     * kdežto protokol o kompletnosti se jmenuje úplně jinak. Rozhoduje proto,
     * jestli obsah je čitelný protokol. PDF vykreslení téhož protokolu, které
     * ČSSZ přikládá vedle XML, tím propadne samo.
     *
     * @return list<array{0:JmhzProtocolReport,1:string}>
     */
    private function protocolAttachments(string $bytes): array
    {
        $found = [];
        foreach ($this->attachmentBytes($bytes) as $candidate) {
            try {
                $found[] = [$this->parser->parse($candidate), $candidate];
            } catch (\Throwable) {
                continue;
            }
        }

        return $found;
    }

    /**
     * Obsah příloh. Zpráva stažená z ISDS je ZFO; když se rozbalit nedá, bere
     * se vstup jako samotná datová věta — ať se cesta nerozpadne kvůli obalu.
     *
     * @return list<string>
     */
    private function attachmentBytes(string $bytes): array
    {
        try {
            $attachments = $this->zfo->extract($bytes)['attachments'];
        } catch (\Throwable) {
            return [$bytes];
        }
        $out = [];
        foreach ($attachments as $attachment) {
            if ($attachment['bytes'] !== '') {
                $out[] = $attachment['bytes'];
            }
        }

        return $out === [] ? [$bytes] : $out;
    }

    /**
     * Obálku GovTalk doručenou datovkou ověřuje pečeť ČSSZ; vazbu na podání
     * nese `dmId` naší odeslané zprávy z věci odpovědi, protože obálka
     * `CorrelationID` neobsahuje (ověřeno na odpovědi ČSSZ ze 4. 9. 2026).
     * Když ho protokol přesto nese, musí sedět.
     */
    private function sealedVerifier(
        ?string $protocolCorrelation,
    ): PayrollReceiptVerifierInterface {
        $delegate = new JmhzReceiptVerifier(
            $this->signatures,
            $this->parser,
            requireCorrelation: false,
        );

        return new readonly class ($delegate, $protocolCorrelation)
            implements PayrollReceiptVerifierInterface {
            public function __construct(
                private JmhzReceiptVerifier $delegate,
                private ?string $protocolCorrelation,
            ) {}

            public function verify(
                string $bytes,
                string $channel,
                string $environment,
                ?string $expectedCorrelationReference,
            ): PayrollVerifiedReceipt {
                $verified = $this->delegate->verify(
                    $bytes,
                    $channel,
                    $environment,
                    $this->protocolCorrelation,
                );

                return new PayrollVerifiedReceipt(
                    $verified->remoteStatus,
                    null,
                    $verified->partStatuses,
                    $verified->formOutcomes,
                );
            }
        };
    }

    /**
     * @return array{status:string,code:?string,submission_id:?int,receipt_id:?int,remote_status:?string}
     */
    private static function result(
        string $status,
        ?string $code = null,
        ?int $submissionId = null,
        ?int $receiptId = null,
        ?string $remoteStatus = null,
    ): array {
        return [
            'status' => $status,
            'code' => $code,
            'submission_id' => $submissionId,
            'receipt_id' => $receiptId,
            'remote_status' => $remoteStatus,
        ];
    }
}
