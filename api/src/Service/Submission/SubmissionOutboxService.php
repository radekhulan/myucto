<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Repository\Submission\SubmissionOutboxAttemptRepository;
use MyInvoice\Repository\Submission\SubmissionOutboxRepository;
use MyInvoice\Repository\Submission\SubmissionRecipientRepository;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionDispatchProjection;
use MyInvoice\Service\Submission\Channel\AcceptanceState;
use MyInvoice\Service\Submission\Channel\AcceptanceEvidence;
use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\ChannelStatus;
use MyInvoice\Service\Submission\Channel\DispatchState;
use MyInvoice\Service\Submission\Channel\OutboundSubmission;
use MyInvoice\Service\Submission\Channel\SubmissionChannel;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use Psr\Log\LoggerInterface;

/**
 * Orchestrace odchozí cesty: zařadit → potvrdit člověkem → odeslat →
 * sledovat stav.
 *
 * ── Tři věci, kvůli kterým tahle třída existuje ──────────────────────────────
 *
 * 1) **„Doručeno" se nikdy nestane „zpracováno".** Kanál sice vrací
 *    {@see ChannelStatus} se dvěma osami, ale tady se ještě navíc ověřuje, že
 *    ten kanál na tvrzení o vyřízení VŮBEC MÁ ({@see applyStatus()}). Kanál
 *    s `DeliveryOnly` může vrátit cokoliv — do databáze se to nedostane.
 *    Třetí pojistkou je DB: `acceptance_evidence_kind` nemá pro doručenku
 *    hodnotu, takže ji nejde zapsat ani ručním UPDATE.
 *
 * 2) **Odesílá jen člověk.** {@see confirmAndSend()} vyžaduje `$userId` a
 *    přechod `ready → sending` je jediná brána ven. Automatika (cron, import,
 *    AI) smí volat {@see enqueue()}, a tím to končí.
 *
 * 3) **Timeout uprostřed odeslání se dá dořešit.** Do zprávy se před
 *    odesláním razítkuje `correlation_reference`, takže se dá dohledáním
 *    v odeslaných zprávách zjistit, co se doopravdy stalo
 *    ({@see resolveUncertain()}).
 */
final readonly class SubmissionOutboxService
{
    private const ARTIFACT_KINDS = ['payroll_submission', 'tax_submission', 'document'];
    private const ENVIRONMENTS = ['production', 'test'];

    public function __construct(
        private SubmissionOutboxRepository $outbox,
        private SubmissionOutboxAttemptRepository $attempts,
        private SubmissionRecipientRepository $recipients,
        private SubmissionChannelRegistry $channels,
        private SubmissionArtifactResolver $artifacts,
        private SubmissionArtifactValidator $validator,
        private LoggerInterface $logger,
        private ?PayrollSubmissionDispatchProjection $payrollProjection,
    ) {}

    /**
     * Zařadí podání do fronty. Smí to i automat — odeslání tím nevzniká.
     *
     * Idempotentní přes `$idempotencyKey`: opakované zařazení téhož artefaktu
     * pro téhož příjemce vrátí existující řádek.
     *
     * @return array{row:array<string,mixed>,created:bool}
     */
    public function enqueue(
        int $supplierId,
        string $environment,
        string $channel,
        string $agendaCode,
        string $artifactKind,
        int $artifactId,
        ?int $recipientId,
        ?string $subject,
        ?int $userId,
    ): array {
        $this->assertEnvironment($environment);
        $this->channels->get($channel); // validace kódu kanálu

        if (!in_array($artifactKind, self::ARTIFACT_KINDS, true)) {
            throw new SubmissionChannelException('invalid_artifact_kind', 'Neznámý druh artefaktu.', 400);
        }
        $agendaCode = strtoupper(trim($agendaCode));
        if (preg_match('/^[A-Z][A-Z0-9_]{1,47}$/', $agendaCode) !== 1) {
            throw new SubmissionChannelException('invalid_agenda_code', 'Neplatný kód agendy.', 400);
        }

        $artifact = $this->artifacts->resolve($supplierId, $artifactKind, $artifactId);
        if ($artifact === null) {
            throw new SubmissionChannelException(
                'artifact_not_found',
                'Podklad k odeslání se nepodařilo najít. Vygenerujte ho znovu.',
                404,
            );
        }

        $recipientBoxId = null;
        if ($recipientId !== null) {
            $recipient = $this->recipients->findVisible($supplierId, $recipientId);
            if ($recipient === null) {
                throw new SubmissionChannelException('recipient_not_found', 'Příjemce v číselníku není.', 404);
            }
            $recipientBoxId = $recipient['isds_box_id'] !== null ? (string) $recipient['isds_box_id'] : null;
            if ($channel === 'isds' && $recipientBoxId === null) {
                throw new SubmissionChannelException(
                    'recipient_box_missing',
                    'Vybraný příjemce nemá v číselníku ID datové schránky. Doplňte ho před odesláním.',
                    409,
                );
            }
        } elseif ($channel === 'isds') {
            throw new SubmissionChannelException(
                'recipient_required',
                'Pro odeslání datovou schránkou vyberte příjemce z číselníku.',
                400,
            );
        }

        $sha = hash('sha256', $artifact['bytes']);
        $idempotencyKey = implode('|', [
            'submission-outbox.v1',
            $supplierId,
            $environment,
            $channel,
            $agendaCode,
            $artifactKind,
            $artifactId,
            $sha,
            $recipientId ?? 0,
        ]);

        return $this->outbox->enqueue([
            'supplier_id' => $supplierId,
            'environment' => $environment,
            'channel' => $channel,
            'agenda_code' => $agendaCode,
            'recipient_id' => $recipientId,
            'recipient_box_id' => $recipientBoxId,
            'subject' => mb_substr(
                ($subject !== null && trim($subject) !== '') ? trim($subject) : $agendaCode . ' — ' . $artifact['filename'],
                0,
                255,
            ),
            'artifact_kind' => $artifactKind,
            'artifact_id' => $artifactId,
            'artifact_filename' => mb_substr($artifact['filename'], 0, 255),
            'artifact_sha256' => $sha,
            'correlation_reference' => $this->correlationReference($agendaCode),
            'created_by' => $userId,
        ], $idempotencyKey);
    }

    /**
     * Potvrzení člověkem + odeslání. Jediná cesta ven.
     *
     * Idempotence: druhé (souběžné i pozdější) potvrzení narazí na to, že
     * podání už není `ready`, a vrátí aktuální stav místo druhého odeslání.
     *
     * @return array{row:array<string,mixed>,dispatched:bool}
     */
    public function confirmAndSend(int $supplierId, int $id, int $userId, ChannelContext $context): array
    {
        $row = $this->outbox->find($supplierId, $id);
        if ($row === null) {
            throw new SubmissionChannelException('submission_not_found', 'Podání ve frontě není.', 404);
        }

        // ── Brána idempotence ──
        // `claimForSending` má v UPDATE podmínku `dispatch_state = 'ready'`,
        // takže uspěje právě jednou. Druhý požadavek dostane null a odejde
        // s aktuálním stavem — bez druhého podání u úřadu.
        $claimed = $this->outbox->claimForSending($supplierId, $id, $userId);
        if ($claimed === null) {
            $current = $this->outbox->find($supplierId, $id);
            if ($current === null) {
                throw new SubmissionChannelException('submission_not_found', 'Podání ve frontě není.', 404);
            }
            return ['row' => $current, 'dispatched' => false];
        }

        $artifact = $this->artifacts->resolve($supplierId, (string) $claimed['artifact_kind'], (int) $claimed['artifact_id']);
        if ($artifact === null) {
            return [
                'row' => $this->outbox->markFailed(
                    $supplierId,
                    $id,
                    'artifact_missing',
                    'Podklad k odeslání už v aplikaci není. Vygenerujte ho znovu a zařaďte podání do fronty znovu.',
                    (int) $claimed['row_version'],
                ),
                'dispatched' => false,
            ];
        }

        // Otisk chrání před odesláním něčeho jiného, než co uživatel schválil.
        $currentSha = hash('sha256', $artifact['bytes']);
        if (!hash_equals((string) $claimed['artifact_sha256'], $currentSha)) {
            return [
                'row' => $this->outbox->markFailed(
                    $supplierId,
                    $id,
                    'artifact_changed',
                    'Podklad se od zařazení do fronty změnil. Zkontrolujte ho a zařaďte podání znovu.',
                    (int) $claimed['row_version'],
                ),
                'dispatched' => false,
            ];
        }

        $channel = $this->channels->get((string) $claimed['channel']);

        // ─── Dvě brány, které musí projít, než zpráva opustí aplikaci ───
        // Obě existují proto, že datová schránka nekontroluje NIC: obsah
        // nevaliduje a o schránce příjemce nám sama nic neřekne. Chyba by se
        // ozvala až po dnech výzvou k odstranění vad podle § 74 DŘ, nebo vůbec.
        try {
            $gate = $this->runPreSendGates($supplierId, $id, $claimed, $channel, $artifact, $context);
        } catch (SubmissionChannelException $e) {
            // Brány cestou zapisují svůj výsledek, takže verze z claimu už je
            // zastaralá — bez čerstvého načtení by zápis selhání spadl na
            // optimistickém zámku a podání zůstalo viset v `sending`.
            $current = $this->outbox->find($supplierId, $id) ?? $claimed;
            return [
                'row' => $this->outbox->markFailed(
                    $supplierId,
                    $id,
                    $e->errorCode,
                    $e->getMessage(),
                    (int) $current['row_version'],
                ),
                'dispatched' => false,
            ];
        }
        $claimed = $gate;

        $attemptNo = $this->attempts->nextAttemptNo($supplierId, $id);
        // Pokus se zakládá PŘED voláním kanálu — jinak by přerušené volání
        // nezanechalo v ledgeru žádnou stopu.
        $attempt = $this->attempts->open(
            $supplierId,
            $id,
            (string) $claimed['channel'],
            $attemptNo,
            $currentSha,
            (string) $claimed['correlation_reference'],
            $userId,
        );

        $submission = new OutboundSubmission(
            outboxId: $id,
            supplierId: $supplierId,
            environment: (string) $claimed['environment'],
            agendaCode: (string) $claimed['agenda_code'],
            subject: (string) $claimed['subject'],
            recipientBoxId: $claimed['recipient_box_id'] !== null ? (string) $claimed['recipient_box_id'] : null,
            artifactFilename: (string) $claimed['artifact_filename'],
            artifactMimeType: $artifact['mime'],
            artifactBytes: $artifact['bytes'],
            artifactSha256: $currentSha,
            correlationReference: (string) $claimed['correlation_reference'],
        );

        $result = $channel->send($submission, $context);
        $attemptVersion = (int) $attempt['row_version'];
        $outboxVersion = (int) $claimed['row_version'];

        return match ($result->state) {
            DispatchState::Sent => (function () use ($supplierId, $id, $attempt, $attemptVersion, $outboxVersion, $result): array {
                $this->attempts->markSent($supplierId, (int) $attempt['id'], (string) $result->externalMessageId, $attemptVersion);
                $row = $this->outbox->markSent(
                    $supplierId,
                    $id,
                    (string) $result->externalMessageId,
                    $outboxVersion,
                );
                $this->projectPayrollSubmission(
                    $supplierId,
                    $row,
                    (string) $result->externalMessageId,
                );
                return [
                    'row' => $row,
                    'dispatched' => true,
                ];
            })(),
            DispatchState::SendUncertain => (function () use ($supplierId, $id, $attempt, $attemptVersion, $outboxVersion, $result): array {
                $this->attempts->markUncertain(
                    $supplierId,
                    (int) $attempt['id'],
                    (string) $result->errorCode,
                    (string) $result->errorMessage,
                    $attemptVersion,
                );
                return [
                    'row' => $this->outbox->markUncertain(
                        $supplierId,
                        $id,
                        (string) $result->errorCode,
                        (string) $result->errorMessage,
                        $outboxVersion,
                    ),
                    'dispatched' => false,
                ];
            })(),
            default => (function () use ($supplierId, $id, $attempt, $attemptVersion, $outboxVersion, $result): array {
                $this->attempts->markFailed(
                    $supplierId,
                    (int) $attempt['id'],
                    (string) $result->errorCode,
                    (string) $result->errorMessage,
                    $attemptVersion,
                );
                return [
                    'row' => $this->outbox->markFailed(
                        $supplierId,
                        $id,
                        (string) $result->errorCode,
                        (string) $result->errorMessage,
                        $outboxVersion,
                    ),
                    'dispatched' => false,
                ];
            })(),
        };
    }

    /**
     * Zaznamená odeslání, které člověk provedl ve své vlastní datové schránce.
     *
     * ── Proč to tu vůbec je ─────────────────────────────────────────────────
     * Strojový transport nasazený není, takže {@see confirmAndSend()} u datovky
     * skončí srozumitelnou překážkou a zpráva odejde ručně. Kdyby aplikace
     * neměla jak si to zapsat, zůstalo by odeslané podání navždy
     * v „připraveno" — a to je horší lež než nic: uživatel by si myslel,
     * že podat ještě musí, nebo by podal podruhé.
     *
     * ── Co se tady liší od strojového odeslání ──────────────────────────────
     * 1. `dispatch_mode` se přepne na `manual`, čímž odpadá brána ověření
     *    schránky v ISDS. Nemá se čím naplnit a není potřeba: adresáta vybírá
     *    člověk ve své datové schránce, která neexistující schránku odmítne
     *    na místě.
     * 2. XSD kontrola PROBĚHNE, ale nemá právo veta. Zpráva už odešla; odmítnout
     *    zápis by neznamenalo, že se to nestalo. Výsledek `failed` se uloží
     *    a uživatel se o vadě dozví teď, ne až z výzvy podle § 74 DŘ.
     * 3. `sent_at` se bere z předané hodnoty, ne z hodin serveru — podání se
     *    stalo dřív, než jsme se o něm dozvěděli.
     *
     * Idempotence: opakované volání s TÝMŽ ID zprávy vrátí aktuální stav
     * a `recorded: false`. Jiné ID se odmítne — ID zprávy je jednorázové
     * přiřazení a hlídá ho i DB trigger.
     *
     * @return array{row:array<string,mixed>,recorded:bool,validation:array{status:string,checked:bool,errors:list<string>}}
     */
    public function markSentManually(
        int $supplierId,
        int $id,
        int $userId,
        string $externalMessageId,
        ?\DateTimeImmutable $sentAt = null,
    ): array {
        $externalMessageId = trim($externalMessageId);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', $externalMessageId) !== 1) {
            throw new SubmissionChannelException(
                'invalid_message_id',
                'ID datové zprávy nemá platný tvar. Najdete ho v detailu odeslané zprávy '
                . 've své datové schránce jako „ID zprávy".',
                400,
            );
        }

        $row = $this->outbox->find($supplierId, $id);
        if ($row === null) {
            throw new SubmissionChannelException('submission_not_found', 'Podání ve frontě není.', 404);
        }
        if ((string) $row['channel'] !== 'isds') {
            throw new SubmissionChannelException(
                'manual_dispatch_not_applicable',
                'Ručně odeslat jde jen podání datovou schránkou.',
                409,
            );
        }

        $state = (string) $row['dispatch_state'];

        // Už odeslané: buď je to tentýž zápis podruhé (a pak se nic neděje),
        // nebo někdo tvrdí jiné ID zprávy — a to by přepsalo jediný důkaz,
        // že zpráva u příjemce je.
        if (in_array($state, [DispatchState::Sent->value, DispatchState::Delivered->value], true)) {
            if ((string) $row['external_message_id'] === $externalMessageId) {
                return ['row' => $row, 'recorded' => false, 'validation' => $this->validationSnapshot($row)];
            }
            throw new SubmissionChannelException(
                'submission_already_sent',
                'Podání už má přiřazené ID zprávy ' . (string) $row['external_message_id']
                . '. Jiné ID k němu zapsat nejde — zkontrolujte, jestli nejde o jiné podání.',
                409,
            );
        }
        if (in_array($state, [DispatchState::Failed->value, DispatchState::Cancelled->value], true)) {
            throw new SubmissionChannelException(
                'submission_closed',
                'Tohle podání je uzavřené (' . $state . '). Zařaďte ho do fronty znovu a odešlete jako nové.',
                409,
            );
        }

        if ($state === DispatchState::Ready->value) {
            // `dispatch_mode` smí trigger změnit jen dokud je řádek `ready`,
            // takže přepnutí a zabrání musí být jeden UPDATE.
            $claimed = $this->outbox->claimForManualSending($supplierId, $id, $userId);
            if ($claimed === null) {
                $current = $this->outbox->find($supplierId, $id);
                if ($current === null) {
                    throw new SubmissionChannelException('submission_not_found', 'Podání ve frontě není.', 404);
                }
                return ['row' => $current, 'recorded' => false, 'validation' => $this->validationSnapshot($current)];
            }
            $row = $claimed;
        }

        $validation = $this->runValidationWithoutVeto($supplierId, $id, $row);
        $row = $validation['row'];

        $updated = $this->outbox->markSentManually(
            $supplierId,
            $id,
            $externalMessageId,
            $sentAt ?? new \DateTimeImmutable('now'),
            (int) $row['row_version'],
        );
        $this->projectPayrollSubmission(
            $supplierId,
            $updated,
            $externalMessageId,
        );

        return [
            'row' => $updated,
            'recorded' => true,
            'validation' => [
                'status' => $validation['status'],
                'checked' => $validation['checked'],
                'errors' => $validation['errors'],
            ],
        ];
    }

    /**
     * Připojí doručenku k podání jako důkaz o dni podání (§ 73 odst. 1 DŘ).
     *
     * ⚠️ Osy vyřízení se nedotýká a dotknout se jí nemůže: pro doručenku
     * neexistuje hodnota v `acceptance_evidence_kind` a DB trigger zápis, který
     * by při připojení doručenky hnul vyřízením, odmítne.
     *
     * @param 'correlation_reference'|'external_message_id'|'manual' $matchedBy
     * @return array{row:array<string,mixed>,attached:bool}
     */
    public function attachReceipt(
        int $supplierId,
        int $id,
        int $documentId,
        ?int $inboxMessageId,
        string $matchedBy,
    ): array {
        $row = $this->outbox->find($supplierId, $id);
        if ($row === null) {
            throw new SubmissionChannelException('submission_not_found', 'Podání ve frontě není.', 404);
        }

        $existing = $row['receipt_document_id'] !== null ? (int) $row['receipt_document_id'] : null;
        if ($existing !== null) {
            // Tatáž doručenka podruhé = nic. Jiná doručenka = odmítnutí; první
            // důkaz se nepřepisuje.
            if ($existing === $documentId) {
                return ['row' => $row, 'attached' => false];
            }
            throw new SubmissionChannelException(
                'receipt_already_attached',
                'K tomuhle podání už je připojená jiná doručenka. Nahraná doručenka zůstala '
                . 'v nezařazených — zkontrolujte, ke kterému podání patří.',
                409,
            );
        }

        return [
            'row' => $this->outbox->attachReceipt(
                $supplierId,
                $id,
                $documentId,
                $inboxMessageId,
                $matchedBy,
                (int) $row['row_version'],
            ),
            'attached' => true,
        ];
    }

    /**
     * Dořeší podání, u kterého se odeslání přerušilo.
     *
     * Odpovědi jsou tři a všechny tři jsou legitimní:
     *   - zpráva se v odeslaných našla → podání JE odeslané, adoptujeme dmID,
     *   - zpráva tam není → nic neodešlo, podání jde bezpečně opakovat,
     *   - schránka neodpověděla → nevědomost trvá a NIC se nemění.
     * Ta třetí je důvod, proč se to nedá vyřešit jedním retry v kódu odeslání.
     *
     * @return array<string,mixed>
     */
    public function resolveUncertain(int $supplierId, int $id, ChannelContext $context): array
    {
        $row = $this->outbox->find($supplierId, $id);
        if ($row === null) {
            throw new SubmissionChannelException('submission_not_found', 'Podání ve frontě není.', 404);
        }
        // `sending` je tu schválně vedle `send_uncertain`. Když proces zemře
        // (fatální chyba, restart poolu, výpadek) mezi voláním kanálu a zápisem
        // výsledku, zůstane řádek viset v `sending` — a to je přesně tentýž
        // druh nevědomosti: nevíme, jestli zpráva odešla. Bez tohohle by takové
        // podání nešlo dořešit vůbec.
        $recoverable = [DispatchState::SendUncertain->value, DispatchState::Sending->value];
        if (!in_array((string) $row['dispatch_state'], $recoverable, true)) {
            return $row;
        }

        $channel = $this->channels->get((string) $row['channel']);
        $probe = $channel->probe((string) $row['correlation_reference'], $context);

        if (!$probe->resolved) {
            $this->logger->warning('Submission dispatch probe inconclusive', [
                'supplier_id' => $supplierId,
                'outbox_id' => $id,
                'reason' => $probe->reason,
            ]);
            return $row;
        }

        if ($probe->externalMessageId !== null) {
            $updated = $this->outbox->markSent(
                $supplierId,
                $id,
                $probe->externalMessageId,
                (int) $row['row_version'],
            );
            $this->projectPayrollSubmission(
                $supplierId,
                $updated,
                $probe->externalMessageId,
            );

            return $updated;
        }

        return $this->outbox->markFailed(
            $supplierId,
            $id,
            'not_sent',
            'Zpráva se v odeslaných nenašla — podání neodešlo. Zařaďte ho do fronty znovu.',
            (int) $row['row_version'],
        );
    }

    /**
     * Promítne stav zjištěný od kanálu do databáze.
     *
     * ── Tady se hlídá to nejdůležitější ──
     * Kanál, který umí doložit jen doručení, nesmí posunout osu vyřízení.
     * Kdyby budoucí adaptér (po výměně ISDS knihovny) začal vracet
     * `AcceptanceState::Accepted`, zahodí se to tady — a hlasitě, do logu,
     * protože je to chyba adaptéru, ne provozní stav.
     *
     * @return array<string,mixed>
     */
    public function applyStatus(int $supplierId, int $id, ChannelStatus $status): array
    {
        $row = $this->outbox->find($supplierId, $id);
        if ($row === null) {
            throw new SubmissionChannelException('submission_not_found', 'Podání ve frontě není.', 404);
        }

        $channel = $this->channels->get((string) $row['channel']);
        $version = (int) $row['row_version'];

        // 1) osa dopravy
        if ($status->dispatch === DispatchState::Delivered
            && $status->deliveredAt !== null
            && (string) $row['dispatch_state'] !== DispatchState::Delivered->value
        ) {
            $row = $this->outbox->markDelivered($supplierId, $id, $status->deliveredAt, $version);
            $version = (int) $row['row_version'];
        }

        // 2) osa vyřízení — jen když na to kanál má
        if ($status->acceptance === AcceptanceState::Unknown || $status->evidence === null) {
            return $row;
        }
        if (!$channel->evidenceStrength()->canProveAcceptance()) {
            $this->logger->error('Channel without processing evidence claimed acceptance — ignored', [
                'supplier_id' => $supplierId,
                'outbox_id' => $id,
                'channel' => (string) $row['channel'],
                'claimed_acceptance' => $status->acceptance->value,
            ]);
            return $row;
        }
        if ((string) $row['acceptance_state'] !== AcceptanceState::Unknown->value) {
            return $row; // rozhodnutí úřadu je jednorázové
        }

        return $this->outbox->recordAcceptance(
            $supplierId,
            $id,
            $status->acceptance->value,
            $status->evidence->value,
            $status->note,
            $version,
        );
    }

    /**
     * Promítne výsledek samostatného, kryptograficky ověřeného protokolu.
     * Doručenka sem nikdy nejde; schopnost číst protokol musí být doložená
     * pro přesnou dvojici kanál + agenda.
     *
     * @return array<string,mixed>
     */
    public function applyVerifiedProtocolOutcome(
        int $supplierId,
        int $id,
        string $remoteStatus,
        ?string $note = null,
    ): array {
        $row = $this->outbox->find($supplierId, $id);
        if ($row === null) {
            throw new SubmissionChannelException(
                'submission_not_found',
                'Podání ve frontě není.',
                404,
            );
        }
        if (AgendaReceiptCapability::forChannel(
            (string) $row['channel'],
            (string) $row['agenda_code'],
        ) !== AgendaReceiptCapability::ProcessingProtocol) {
            throw new SubmissionChannelException(
                'processing_protocol_undocumented',
                'Tahle agenda nemá doložený strojově čitelný protokol.',
                409,
            );
        }
        if ((string) $row['acceptance_state'] !== AcceptanceState::Unknown->value) {
            return $row;
        }
        $acceptance = match ($remoteStatus) {
            'accepted' => AcceptanceState::Accepted,
            'rejected', 'correction_required' => AcceptanceState::Rejected,
            default => null,
        };
        if ($acceptance === null) {
            return $row;
        }

        return $this->outbox->recordAcceptance(
            $supplierId,
            $id,
            $acceptance->value,
            AcceptanceEvidence::AgencyProtocolMessage->value,
            $note,
            (int) $row['row_version'],
        );
    }

    /** @param array<string,mixed> $row */
    private function projectPayrollSubmission(
        int $supplierId,
        array $row,
        string $externalMessageId,
    ): void {
        $this->payrollProjection?->project(
            $supplierId,
            (string) $row['artifact_kind'],
            (int) $row['artifact_id'],
            $externalMessageId,
        );
    }

    /** @return list<array<string,mixed>> */
    public function listForSupplier(int $supplierId, string $environment, int $limit = 100): array
    {
        $this->assertEnvironment($environment);
        return $this->outbox->listForSupplier($supplierId, $environment, $limit);
    }

    /** @return list<array<string,mixed>> */
    public function attemptsFor(int $supplierId, int $id): array
    {
        return $this->attempts->listForOutbox($supplierId, $id);
    }

    /** @return array<string,mixed> */
    public function cancel(int $supplierId, int $id): array
    {
        $row = $this->outbox->find($supplierId, $id);
        if ($row === null) {
            throw new SubmissionChannelException('submission_not_found', 'Podání ve frontě není.', 404);
        }
        if ((string) $row['dispatch_state'] !== DispatchState::Ready->value) {
            throw new SubmissionChannelException(
                'submission_not_cancellable',
                'Zrušit jde jen podání, které ještě nebylo odesláno.',
                409,
            );
        }
        return $this->outbox->cancel($supplierId, $id, (int) $row['row_version']);
    }

    // ───────────────────────── interní ─────────────────────────

    /**
     * Kontroly, které musí projít PŘED odesláním. Vrací aktualizovaný řádek.
     *
     * 1) **Lokální validace proti XSD.** ISDS obsah příloh nevaliduje vůbec —
     *    nedostaneme podací číslo, ani seznam chyb. Špatný formát je vada
     *    podání a přijde na něj výzva podle § 74 DŘ, typicky za několik dnů,
     *    kdy už může být po lhůtě. Tahle kontrola je jediná náhrada za chybějící
     *    validaci na druhé straně. Když pro agendu schéma nemáme, stav je
     *    `skipped` — a to je poctivější, než tvrdit `passed`.
     *
     * 2) **Ověření schránky příjemce v ISDS.** Náš číselník smí zestárnout
     *    (seznam schránek Finanční správy je z roku 2023), ISDS ne. Ověření
     *    odchytí zrušenou nebo znepřístupněnou schránku dřív, než do ní pošleme
     *    přiznání „do prázdna".
     *
     * @param array<string,mixed> $row
     * @param array{filename:string,mime:string,bytes:string} $artifact
     * @return array<string,mixed>
     * @throws SubmissionChannelException když některá brána neprojde
     */
    private function runPreSendGates(
        int $supplierId,
        int $id,
        array $row,
        SubmissionChannel $channel,
        array $artifact,
        ChannelContext $context,
    ): array {
        $validation = $this->validator->validateArtifact((string) $row['agenda_code'], $artifact);
        $row = $this->outbox->recordValidation(
            $supplierId,
            $id,
            $validation['status'],
            (int) $row['row_version'],
        );
        if ($validation['status'] === 'failed') {
            throw new SubmissionChannelException(
                'artifact_invalid',
                'Podklad neprošel kontrolou proti XSD schématu, takže by ho úřad vrátil jako vadné podání: '
                . implode(' ', array_slice($validation['errors'], 0, 3)),
                422,
            );
        }

        $verification = $channel->verifyRecipient(
            $row['recipient_box_id'] !== null ? (string) $row['recipient_box_id'] : null,
            $context,
        );
        if ($verification === null) {
            return $row; // kanál příjemce neadresuje (EPO)
        }
        if (!$verification['usable']) {
            throw new SubmissionChannelException(
                'recipient_box_unusable',
                'Datová schránka příjemce není použitelná (' . ($verification['reason'] ?? 'bez uvedení důvodu')
                . '). Ověřte ID schránky v číselníku — podání do zrušené schránky je nepodané.',
                409,
            );
        }

        return $this->outbox->recordRecipientVerified($supplierId, $id, (int) $row['row_version']);
    }

    /**
     * XSD kontrola u podání, které už odešlo mimo aplikaci.
     *
     * Kontrola proběhne vždy, ale nic nezastaví — zpráva je pryč. Když už
     * výsledek zapsaný je (u podání, které prošlo bránami strojového odeslání),
     * nepřepisuje se; jinak by se `passed` z okamžiku odeslání ztratilo.
     *
     * `checked = false` znamená, že se zkontrolovat NEDALO (podklad už
     * v aplikaci není). To je jiná informace než `skipped` (schéma pro agendu
     * nemáme) a UI ji musí umět rozlišit — jinak se „nezkontrolováno" tváří
     * jako „zkontrolováno a v pořádku".
     *
     * @param array<string,mixed> $row
     * @return array{row:array<string,mixed>,status:string,checked:bool,errors:list<string>}
     */
    private function runValidationWithoutVeto(int $supplierId, int $id, array $row): array
    {
        if ($row['artifact_validation_status'] !== null) {
            return [
                'row' => $row,
                'status' => (string) $row['artifact_validation_status'],
                'checked' => true,
                'errors' => [],
            ];
        }

        $artifact = $this->artifacts->resolve($supplierId, (string) $row['artifact_kind'], (int) $row['artifact_id']);
        if ($artifact === null) {
            $this->logger->warning('Manually dispatched submission has no artifact to validate', [
                'supplier_id' => $supplierId,
                'outbox_id' => $id,
            ]);

            return [
                'row' => $this->outbox->recordValidation($supplierId, $id, 'skipped', (int) $row['row_version']),
                'status' => 'skipped',
                'checked' => false,
                'errors' => [],
            ];
        }

        $validation = $this->validator->validateArtifact((string) $row['agenda_code'], $artifact);

        return [
            'row' => $this->outbox->recordValidation($supplierId, $id, $validation['status'], (int) $row['row_version']),
            'status' => $validation['status'],
            'checked' => true,
            'errors' => array_slice($validation['errors'], 0, 5),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{status:string,checked:bool,errors:list<string>}
     */
    private function validationSnapshot(array $row): array
    {
        return [
            'status' => $row['artifact_validation_status'] !== null
                ? (string) $row['artifact_validation_status']
                : 'skipped',
            'checked' => $row['artifact_validation_status'] !== null,
            'errors' => [],
        ];
    }

    /**
     * Spisová značka, kterou kanál razítkuje do odchozí zprávy.
     *
     * Jde do `dmSenderIdent`, a ten má tvrdý limit 50 znaků. Tenhle tvar má
     * nejvýš 12 + 1 + 8 + 1 + 12 = 34 znaků, takže se do něj vejde i u nejdelších
     * kódů agend. Musí být jedinečná a čitelná i pro člověka, který ji uvidí
     * v datové schránce — po přerušeném odeslání se podle ní dohledává, jestli
     * zpráva odešla, a jiná cesta neexistuje (ISDS nemá idempotency token).
     */
    private function correlationReference(string $agendaCode): string
    {
        $reference = substr($agendaCode, 0, 12) . '-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(6)));
        if (strlen($reference) > 50) {
            throw new \LogicException('Spisová značka podání přesáhla limit 50 znaků pole dmSenderIdent.');
        }
        return $reference;
    }

    private function assertEnvironment(string $environment): void
    {
        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            throw new SubmissionChannelException('invalid_environment', 'Neznámé prostředí.', 400);
        }
    }
}
