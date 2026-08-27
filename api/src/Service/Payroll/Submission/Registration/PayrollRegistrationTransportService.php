<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use DOMDocument;
use DOMElement;
use DOMXPath;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenPayloadReader;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchOutcome;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchService;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;

/**
 * Překlad zmrazené PREZEC/REGZEC datové věty do existující cesty VREP/APEP.
 *
 * Adaptér nic nesestavuje znovu. Načte archivované bajty, ověří jejich agendu
 * a VS proti tenantově podání a předá je beze změny témuž transportu a ledgeru,
 * které používá JMHZ. Samotné volání je dostupné jen z explicitní HTTP akce;
 * žádný cron registrační podání sám neodesílá.
 */
final readonly class PayrollRegistrationTransportService
{
    private const DOCUMENTS = [
        'PREZEC26' => [
            'root' => 'PREZEC',
            'namespace' => 'http://schemas.cssz.cz/PREZEC/2026',
            'class' => 'CSSZ_PREZEC',
            'actions' => [9, 10],
        ],
        'REGZEC25' => [
            'root' => 'REGZEC',
            'namespace' => 'http://schemas.cssz.cz/REGZEC/2025',
            'class' => 'CSSZ_REGZEC',
            'actions' => [1, 2, 3, 4, 5, 6, 7, 8],
        ],
    ];

    public function __construct(
        private PayrollSubmissionRepository $submissions,
        private PayrollSubmissionTransportAttemptRepository $attempts,
        private JmhzFrozenPayloadReader $frozen,
        private JmhzDispatchService $dispatch,
    ) {}

    /** @return array<string,mixed> */
    public function send(
        int $supplierId,
        string $environment,
        int $submissionId,
        string $idempotencyKey,
        ?int $actorUserId,
    ): array {
        $context = $this->context($supplierId, $environment, $submissionId, []);
        $payload = $this->payload(
            $this->frozen->bytes($supplierId, $environment, $submissionId),
            $context['agenda_code'],
        );
        $existing = $this->attempts->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            $this->assertReplayScope(
                $existing,
                $supplierId,
                $environment,
                $submissionId,
                $payload['sha256'],
            );

            return $this->result(
                new JmhzDispatchOutcome($existing),
                $context['agenda_code'],
                $payload,
            );
        }
        if ($context['status'] !== 'ready') {
            throw new \DomainException(
                'Registrační podání už bylo odesláno; pokračujte dotazem na výsledek původního pokusu.',
            );
        }
        $outcome = $this->dispatch->send(
            $supplierId,
            $environment,
            $submissionId,
            $payload['bytes'],
            $payload['variable_symbol'],
            $idempotencyKey,
            $actorUserId,
            $payload['submission_class'],
        );

        return $this->result($outcome, $context['agenda_code'], $payload);
    }

    /** @return array{agenda_code:string,submission_class:string,attempt:?array<string,mixed>} */
    public function status(
        int $supplierId,
        string $environment,
        int $submissionId,
    ): array {
        $context = $this->context($supplierId, $environment, $submissionId, []);
        $history = $this->attempts->listForSubmission(
            $supplierId,
            $environment,
            $submissionId,
        );
        $attempt = $history === [] ? null : $history[array_key_last($history)];

        return [
            'agenda_code' => $context['agenda_code'],
            'submission_class' => self::DOCUMENTS[$context['agenda_code']]['class'],
            'attempt' => $attempt,
        ];
    }

    public function poll(
        int $supplierId,
        string $environment,
        int $attemptId,
    ): JmhzDispatchOutcome {
        $attempt = $this->attempt($supplierId, $environment, $attemptId);
        if (($attempt['status'] ?? null) !== 'awaiting_protocol') {
            throw new JmhzTransportException(
                'registration_dispatch_poll_unavailable',
                'Na výsledek se lze zeptat pouze u odeslaného podání, které čeká na protokol ČSSZ.',
            );
        }
        $submissionId = (int) $attempt['submission_id'];
        $context = $this->context(
            $supplierId,
            $environment,
            $submissionId,
            ['submitted', 'processing', 'partially_accepted', 'accepted', 'rejected'],
        );
        $payload = $this->payload(
            $this->frozen->bytes($supplierId, $environment, $submissionId),
            $context['agenda_code'],
        );

        return $this->dispatch->poll(
            $supplierId,
            $environment,
            $attemptId,
            $payload['variable_symbol'],
            1,
            $payload['submission_class'],
        );
    }

    /** @return array{closed:bool,already_closed:bool,attempt:array<string,mixed>} */
    public function close(
        int $supplierId,
        string $environment,
        int $attemptId,
    ): array {
        $attempt = $this->attempt($supplierId, $environment, $attemptId);
        $submissionId = (int) $attempt['submission_id'];
        $context = $this->context(
            $supplierId,
            $environment,
            $submissionId,
            ['submitted', 'processing', 'partially_accepted', 'accepted', 'rejected'],
        );
        $payload = $this->payload(
            $this->frozen->bytes($supplierId, $environment, $submissionId),
            $context['agenda_code'],
        );

        return $this->dispatch->close(
            $supplierId,
            $environment,
            $attemptId,
            $payload['variable_symbol'],
            $payload['submission_class'],
        );
    }

    /**
     * @param list<string> $statuses
     * Prázdný seznam stavů znamená pouze kontrolu vlastnictví, prostředí a agendy.
     *
     * @return array{agenda_code:string,status:string}
     */
    private function context(
        int $supplierId,
        string $environment,
        int $submissionId,
        array $statuses,
    ): array {
        $submission = $this->submissions->findSubmission($supplierId, $submissionId);
        if ($submission === null) {
            throw new \DomainException('Registrační podání nebylo nalezeno ve stejné firmě.');
        }
        if ($submission['environment'] !== $environment) {
            throw new \DomainException('Registrační podání patří jinému prostředí.');
        }
        if ($statuses !== [] && !in_array($submission['status'], $statuses, true)) {
            throw new \DomainException('Registrační podání není ve stavu vhodném pro tento krok přenosu.');
        }
        $obligation = $this->submissions->findObligationOfSubmission(
            $supplierId,
            $environment,
            $submissionId,
        );
        if ($obligation === null || $obligation['subject_type'] !== 'employment') {
            throw new \DomainException('Podání není registrací pracovního vztahu.');
        }
        $agenda = $obligation['agenda_code'];
        if (!isset(self::DOCUMENTS[$agenda])) {
            throw new \DomainException('Transport podporuje pouze registrační agendy PREZEC a REGZEC.');
        }

        return [
            'agenda_code' => $agenda,
            'status' => (string) $submission['status'],
        ];
    }

    /** @param array<string,mixed> $attempt */
    private function assertReplayScope(
        array $attempt,
        int $supplierId,
        string $environment,
        int $submissionId,
        string $payloadSha256,
    ): void {
        if ((int) ($attempt['supplier_id'] ?? 0) !== $supplierId
            || (string) ($attempt['environment'] ?? '') !== $environment
            || (int) ($attempt['submission_id'] ?? 0) !== $submissionId
            || (string) ($attempt['channel'] ?? '') !== JmhzDispatchService::CHANNEL
            || !hash_equals(
                $payloadSha256,
                (string) ($attempt['request_sha256'] ?? ''),
            )
        ) {
            throw new \DomainException(
                'Idempotentní klíč už patří jinému registračnímu podání nebo jiným zmrazeným datům.',
            );
        }
    }

    /** @return array<string,mixed> */
    private function attempt(int $supplierId, string $environment, int $attemptId): array
    {
        $attempt = $this->attempts->find($supplierId, $environment, $attemptId);
        if ($attempt === null) {
            throw new JmhzTransportException(
                'registration_dispatch_attempt_unknown',
                'Pokus o odeslání registračního podání neexistuje.',
                404,
            );
        }

        return $attempt;
    }

    /**
     * @return array{bytes:string,sha256:string,variable_symbol:string,submission_class:string}
     */
    private function payload(string $bytes, string $agendaCode): array
    {
        $document = self::DOCUMENTS[$agendaCode];
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = false;
        try {
            $loaded = $dom->loadXML($bytes, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $dom->documentElement;
        if (!$loaded
            || !$root instanceof DOMElement
            || $root->localName !== $document['root']
            || $root->namespaceURI !== $document['namespace']
        ) {
            throw new \DomainException('Zmrazené XML neodpovídá agendě registračního podání.');
        }
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('r', $document['namespace']);
        $employees = $xpath->query(
            '/r:' . $document['root'] . '/r:employees/r:employee',
        );
        if ($employees === false || $employees->length === 0) {
            throw new \DomainException('Zmrazené XML registrace neobsahuje zaměstnance.');
        }
        foreach ($employees as $employee) {
            $action = $employee instanceof DOMElement
                ? $employee->getAttribute('act')
                : '';
            if (!$employee instanceof DOMElement
                || preg_match('/^[0-9]+$/D', $action) !== 1
                || !in_array((int) $action, $document['actions'], true)
            ) {
                throw new \DomainException(
                    'Transport podporuje pouze schválenou podporovanou akci PREZEC/REGZEC.',
                );
            }
            if ($agendaCode === 'REGZEC25' && (int) $action === 1) {
                try {
                    PayrollRegistrationBusinessMatrix::requireActionVariant(
                        1,
                        null,
                        null,
                        false,
                    );
                } catch (PayrollRegistrationXmlException $exception) {
                    throw new \DomainException(
                        $exception->getMessage(),
                        0,
                        $exception,
                    );
                }
            }
        }
        $symbols = [];
        $nodes = $xpath->query(
            '/r:' . $document['root'] . '/r:employees/r:employee/r:comp/@vs',
        );
        if ($nodes === false) {
            throw new \DomainException('Zmrazené XML registrace nelze zkontrolovat.');
        }
        foreach ($nodes as $node) {
            $value = trim($node->nodeValue ?? '');
            if ($value !== '') {
                $symbols[$value] = true;
            }
        }
        if (count($symbols) !== 1) {
            throw new \DomainException('Registrační podání musí obsahovat právě jeden variabilní symbol zaměstnavatele.');
        }
        $variableSymbol = (string) array_key_first($symbols);
        if (preg_match('/^[0-9]{10}$/D', $variableSymbol) !== 1) {
            throw new \DomainException('Variabilní symbol registračního podání musí mít deset číslic.');
        }

        return [
            'bytes' => $bytes,
            'sha256' => hash('sha256', $bytes),
            'variable_symbol' => $variableSymbol,
            'submission_class' => $document['class'],
        ];
    }

    /**
     * @param array{bytes:string,sha256:string,variable_symbol:string,submission_class:string} $payload
     * @return array<string,mixed>
     */
    private function result(
        JmhzDispatchOutcome $outcome,
        string $agendaCode,
        array $payload,
    ): array {
        return [
            'agenda_code' => $agendaCode,
            'submission_class' => $payload['submission_class'],
            'payload_sha256' => $payload['sha256'],
            'attempt' => $outcome->attempt,
            'acknowledgement' => $outcome->acknowledgement === null ? null : [
                'correlation_id' => $outcome->acknowledgement->correlationId,
                'poll_interval_seconds' => $outcome->acknowledgement->pollIntervalSeconds,
                'gateway_timestamp' => $outcome->acknowledgement->gatewayTimestamp,
            ],
            'settled' => $outcome->isSettled(),
        ];
    }
}
