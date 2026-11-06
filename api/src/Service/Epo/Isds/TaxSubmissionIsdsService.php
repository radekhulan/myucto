<?php

declare(strict_types=1);

namespace MyInvoice\Service\Epo\Isds;

use MyInvoice\Repository\Submission\SubmissionOutboxRepository;
use MyInvoice\Repository\TaxSubmissionEpoRepository;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Epo\TaxSubmissionDocumentService;
use MyInvoice\Service\Submission\Channel\Isds\IsdsTransportAvailabilityResolver;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\SubmissionOutboxService;

/**
 * Zařazení archivovaného daňového podání (DPH, KH, SH, DPPO, DPFO) do obecné
 * fronty datové schránky.
 *
 * Odeslání samotné jde stejnou cestou jako mzdová podání: odesílací branou,
 * Mobilním klíčem, jménem a heslem nebo systémovým certifikátem
 * ({@see SubmissionOutboxService::confirmAndSend()}). Tahle služba jen ověří,
 * že snapshot smí ven, určí schránku finančního úřadu a sestaví věc zprávy.
 *
 * ── Jeden snapshot = jeden kanál ────────────────────────────────────────────
 * Podat totéž přiznání přes EPO i datovou schránkou by znamenalo dvě podání.
 * Zařazení proto odmítne snapshot s rozpracovaným EPO pokusem a EPO naopak
 * odmítne snapshot, který drží fronta datové schránky
 * ({@see TaxSubmissionEpoRepository::activeIsdsDispatch()}).
 */
final readonly class TaxSubmissionIsdsService
{
    private const CHANNEL = 'isds';
    private const ARTIFACT_KIND = 'tax_submission';
    private const ENVIRONMENT = 'production';

    private const FORM_LABELS = [
        'dphdp3' => 'Přiznání k DPH',
        'dphkh1' => 'Kontrolní hlášení DPH',
        'dphshv' => 'Souhrnné hlášení',
        'dppdp9' => 'Přiznání k dani z příjmů právnických osob',
        'dpfdp5' => 'Přiznání k dani z příjmů fyzických osob',
        'dpfdp7' => 'Přiznání k dani z příjmů fyzických osob',
    ];

    public function __construct(
        private TaxSubmissionRepository $submissions,
        private TaxSubmissionEpoRepository $epo,
        private TaxIsdsRouting $routing,
        private SubmissionOutboxService $outbox,
        private SubmissionOutboxRepository $outboxRows,
        private TaxSubmissionDocumentService $documents,
        private \MyInvoice\Service\Tax\Return\AccountingDecreeService $accountingDecrees,
        private ?IsdsTransportAvailabilityResolver $transportAvailability = null,
    ) {}

    /**
     * @return array{
     *   outbox_id:int,created:bool,row:array<string,mixed>,
     *   recipient:array{id:int,box_id:string,name:string,code:string,source:string},
     *   xml_office_code:?string,office_mismatch:bool,subject:string,
     *   attachment:array{filename:string,mime:string,sha256:string,bytes:int},
     *   transport:array{automatic:bool,channel:string,reason:?string}
     * }
     */
    public function enqueue(int $supplierId, int $submissionId, ?int $userId): array
    {
        $submission = $this->submissions->find($submissionId, $supplierId);
        if ($submission === null) {
            throw new SubmissionChannelException('not_found', 'Podání nebylo nalezeno.', 404);
        }
        try {
            $this->accountingDecrees->assertDppoSubmissionSupported($supplierId, $submission, true);
        } catch (\MyInvoice\Service\Tax\Return\TaxReturnException $e) {
            throw new SubmissionChannelException($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        $formCode = strtolower((string) $submission['form_code']);
        TaxIsdsRouting::groupFor($formCode);

        if (in_array((string) $submission['status'], ['submitted', 'accepted'], true)) {
            throw new SubmissionChannelException(
                'already_submitted',
                'Tento XML snapshot už je označený jako podaný.',
                409,
            );
        }
        if ((string) $submission['validation_status'] !== 'passed') {
            throw new SubmissionChannelException(
                'validation_failed',
                'XML neprošlo lokální XSD validací, datovou schránkou ho poslat nelze.',
                422,
            );
        }
        if (!hash_equals((string) $submission['xml_sha256'], hash('sha256', (string) $submission['xml_content']))) {
            throw new SubmissionChannelException(
                'snapshot_changed',
                'Archivovaný XML snapshot neodpovídá uloženému otisku.',
                409,
            );
        }
        $moved = $this->submissions->amendmentBaselineMoved($submission, $supplierId);
        if ($moved !== null) {
            throw new SubmissionChannelException(
                'amendment_baseline_moved',
                'Od vytvoření tohoto dodatečného přiznání se změnila poslední známá daň období.'
                    . ' Rozdíl by se započetl dvakrát — vygenerujte dodatečné přiznání znovu.',
                409,
            );
        }
        $this->epo->expireAttempts($submissionId, $supplierId);
        if ($this->epo->activeAttempt($submissionId, $supplierId, self::ENVIRONMENT) !== null) {
            throw new SubmissionChannelException(
                'epo_attempt_active',
                'Tento snapshot má rozpracované nebo potvrzené podání přes EPO. Jedno podání'
                    . ' se posílá jen jednou cestou.',
                409,
            );
        }

        $route = $this->routing->resolve($supplierId, $submission);
        $recipient = $route['recipient'];
        $generation = $this->releaseOtherQueued($supplierId, $submissionId, (int) $recipient['id']);

        $subject = $this->subject($submission);
        $waiting = $this->waitingFor($supplierId, $submissionId, (int) $recipient['id']);
        $enqueued = $waiting !== null
            ? ['row' => $waiting, 'created' => false]
            : $this->outbox->enqueue(
                $supplierId,
                self::ENVIRONMENT,
                self::CHANNEL,
                strtoupper($formCode),
                self::ARTIFACT_KIND,
                $submissionId,
                (int) $recipient['id'],
                $subject,
                $userId,
                $generation,
            );
        $row = $enqueued['row'];

        // Zdrojové XML do Dokumentů ke složce podání, stejně jako u předání do EPO.
        // Archiv je pohodlí, ne podmínka odeslání — fronta čte bajty ze snapshotu.
        try {
            $this->documents->ensureSourceXml($submission, $supplierId, null, $userId);
        } catch (\Throwable) {
        }

        return [
            'outbox_id' => (int) $row['id'],
            'created' => $enqueued['created'],
            'row' => $row,
            'recipient' => [
                'id' => (int) $recipient['id'],
                'box_id' => (string) $recipient['isds_box_id'],
                'name' => (string) $recipient['name'],
                'code' => (string) $recipient['code'],
                'source' => $route['source'],
            ],
            'xml_office_code' => $route['xml_office_code'],
            'office_mismatch' => $route['mismatch'],
            'subject' => (string) $row['subject'],
            'attachment' => [
                'filename' => (string) $row['artifact_filename'],
                'mime' => 'application/xml',
                'sha256' => (string) $row['artifact_sha256'],
                'bytes' => strlen((string) $submission['xml_content']),
            ],
            'transport' => $this->transportAvailability !== null
                ? $this->transportAvailability->resolve($supplierId, self::ENVIRONMENT, $userId)
                : ['automatic' => false, 'channel' => 'manual_upload', 'reason' => 'isds_transport_unavailable'],
        ];
    }

    /**
     * Věc datové zprávy. Předepsaný tvar neexistuje; úřad i účetní v ní mají
     * na první pohled poznat výkaz, období, druh a daňový subjekt.
     *
     * @param array<string,mixed> $submission
     */
    public function subject(array $submission): string
    {
        $formCode = strtolower((string) $submission['form_code']);
        $parts = [
            (self::FORM_LABELS[$formCode] ?? strtoupper($formCode)) . ' za ' . self::period($submission),
        ];
        $variant = self::variantLabel($formCode, (string) ($submission['form_variant'] ?? ''));
        if ($variant !== null) {
            $parts[] = $variant;
        }
        $dic = self::dicFromXml((string) ($submission['xml_content'] ?? ''));
        if ($dic !== null) {
            $parts[] = 'DIČ CZ' . $dic;
        }
        return mb_substr(implode(', ', $parts), 0, 255);
    }

    /**
     * Zpráva zařazená pro jiný cíl (uživatel mezitím změnil schránku FÚ) se
     * zruší — nikdy neodešla. Odesílanou nebo odeslanou zprávu nikdo nepřebije.
     *
     * Vrací pořadí dalšího pokusu: počet už uzavřených (zrušených a neúspěšných)
     * zpráv snapshotu. Po zrušení nebo neúspěchu tak vznikne nová zpráva místo
     * vrácení té uzavřené, dokud ve frontě čeká, zařazení ji jen vrátí.
     */
    private function releaseOtherQueued(int $supplierId, int $submissionId, int $recipientId): int
    {
        $rows = $this->outboxRows->listForArtifacts($supplierId, self::ARTIFACT_KIND, [$submissionId]);
        foreach ($rows as $row) {
            if ((string) $row['channel'] !== self::CHANNEL || (string) $row['environment'] !== self::ENVIRONMENT) {
                continue;
            }
            $state = (string) $row['dispatch_state'];
            if (in_array($state, ['sending', 'send_uncertain', 'sent', 'delivered'], true)) {
                throw new SubmissionChannelException(
                    'isds_dispatch_active',
                    'Tento snapshot už datovou schránkou odchází nebo odešel. Znovu se nezařazuje.',
                    409,
                );
            }
            if ($state === 'ready' && (int) ($row['recipient_id'] ?? 0) !== $recipientId) {
                $this->outbox->cancel($supplierId, (int) $row['id']);
            }
        }

        $closed = 0;
        foreach ($this->outboxRows->listForArtifacts($supplierId, self::ARTIFACT_KIND, [$submissionId]) as $row) {
            if ((string) $row['channel'] === self::CHANNEL
                && in_array((string) $row['dispatch_state'], ['failed', 'cancelled'], true)
            ) {
                $closed++;
            }
        }
        return $closed;
    }

    /**
     * Zpráva pro tutéž schránku, která už čeká ve frontě: opakované zařazení
     * ji jen vrátí, i kdyby se mezitím změnil počet uzavřených zpráv.
     *
     * @return array<string,mixed>|null
     */
    private function waitingFor(int $supplierId, int $submissionId, int $recipientId): ?array
    {
        foreach ($this->outboxRows->listForArtifacts($supplierId, self::ARTIFACT_KIND, [$submissionId]) as $row) {
            if ((string) $row['channel'] === self::CHANNEL
                && (string) $row['environment'] === self::ENVIRONMENT
                && (string) $row['dispatch_state'] === 'ready'
                && (int) ($row['recipient_id'] ?? 0) === $recipientId
            ) {
                return $row;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $submission */
    private static function period(array $submission): string
    {
        $year = (int) $submission['period_year'];
        if ($submission['period_month'] !== null) {
            return sprintf('%02d/%d', (int) $submission['period_month'], $year);
        }
        if ($submission['period_quarter'] !== null) {
            return sprintf('%d. čtvrtletí %d', (int) $submission['period_quarter'], $year);
        }
        return 'rok ' . $year;
    }

    private static function variantLabel(string $formCode, string $variant): ?string
    {
        // Každý formulář má vlastní sadu kódů (zrcadlí VARIANT_LABELS v TaxSubmissions.vue);
        // neznámá kombinace se do věci nepíše, místo aby se vymýšlel popisek.
        $labels = match ($formCode) {
            'dphdp3' => ['B' => 'řádné', 'O' => 'opravné', 'D' => 'dodatečné', 'E' => 'dodatečné opravné'],
            'dphkh1' => ['B' => 'řádné', 'O' => 'řádné opravné', 'N' => 'následné', 'E' => 'následné opravné'],
            'dphshv' => ['R' => 'řádné', 'N' => 'následné'],
            default => [],
        };
        return $labels[strtoupper($variant)] ?? null;
    }

    private static function dicFromXml(string $xml): ?string
    {
        if (preg_match('/\sdic="(?:CZ)?([0-9]{8,10})"/', $xml, $match) === 1) {
            return $match[1];
        }
        return null;
    }
}
