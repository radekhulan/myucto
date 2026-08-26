<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Repository\Submission\SubmissionChannelCredentialRepository;
use MyInvoice\Repository\Submission\SubmissionInboxRepository;
use MyInvoice\Repository\Submission\SubmissionRecipientRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Document\DocumentIngestService;
use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\ChannelStatus;
use MyInvoice\Service\Submission\Channel\InboxMessageHeader;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\Channel\SubmissionInboxChannel;
use Psr\Log\LoggerInterface;

/**
 * Příchozí cesta: vyzvednout seznam → stáhnout → uložit do DMS → zařadit.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ⚠️ VYZVEDNUTÍ SEZNAMU JE PRÁVNÍ ÚKON, NE ČTENÍ ⚠️
 * ═══════════════════════════════════════════════════════════════════════════
 * `GetListOfReceivedMessages` je přihlášení do datové schránky, a tím
 * **doručení všech dodaných zpráv podle § 17 odst. 3 zák. 300/2008 Sb.**
 * Od té chvíle běží zákonné lhůty — u výzvy k odstranění vad, u odvolání,
 * u všeho.
 *
 * Aplikace proto schránku automaticky NEVYBÍRÁ. Každé vyzvednutí musí spustit
 * přihlášený člověk samostatnou akcí v UI; jeho ID se zapíše do auditní stopy
 * ještě před síťovým voláním. Bez konkrétního uživatele se {@see poll()}
 * k síti nedostane.
 *
 * ── Prázdno není totéž co porucha ────────────────────────────────────────────
 * Druhá nebezpečná chyba téhle vrstvy by byla tichá: dotaz na schránku selže,
 * kód to spolkne a vrátí prázdný seznam, uživatel vidí „žádné nové zprávy"
 * a nikdo nezjistí, že výzvy ležely měsíc nevyzvednuté.
 *
 * Bráníme se tomu na třech místech:
 *   1. {@see \MyInvoice\Service\Submission\Channel\SubmissionInboxChannel::listNew()}
 *      selhání HÁZÍ, prázdný seznam vrací jen při úspěchu,
 *   2. {@see poll()} zapisuje výsledek do `submission_inbox_polls` a rozlišuje
 *      `last_attempt_at` od `last_ok_at`,
 *   3. návratová hodnota nese `failed` zvlášť od `fetched`, takže cron může
 *      skončit nenulovým exit kódem.
 *
 * Selhání JEDNÉ zprávy naopak celý běh nepoloží — ostatní zprávy se stáhnou
 * a chyba se zapíše. Jinak by jedna rozbitá příloha zablokovala schránku.
 */
final readonly class SubmissionInboxService
{
    public function __construct(
        private SubmissionInboxRepository $inbox,
        private SubmissionRecipientRepository $recipients,
        private SubmissionChannelCredentialRepository $credentials,
        private SubmissionOutboxService $outboxService,
        private SubmissionChannelRegistry $channels,
        private InboxMessageClassifier $classifier,
        private DocumentIngestService $documents,
        private DeliveryResolutionService $delivery,
        private ActivityLogger $activity,
        private LoggerInterface $logger,
    ) {}

    /**
     * Přepočítá rozhodný den doručení u zpráv, kde se závěr může změnit pouhým
     * během času (běžící lhůta fikce). Volá to cron po vybrání schránky.
     *
     * @return array{checked:int,changed:int,delivered_by_fiction:int}
     */
    public function refreshDelivery(int $supplierId, string $environment, ?int $actorUserId = null): array
    {
        return $this->delivery->refresh($supplierId, $environment, $actorUserId);
    }

    /**
     * Vyzvedne a zpracuje nové zprávy jedné firmy.
     *
     * @throws SubmissionChannelException když vyzvednutí nespustil konkrétní
     *         přihlášený uživatel — viz § 17 odst. 3 v hlavičce třídy
     * @return array{fetched:int,stored:int,skipped:int,failed:int,unclassified:int,error:?string}
     */
    public function poll(
        ChannelContext $context,
        string $channelCode,
        ?int $folderId = null,
        int $limit = 50,
        ?int $actorUserId = null,
    ): array
    {
        $this->assertInteractivePollingAllowed(
            $context->supplierId,
            $channelCode,
            $context->environment,
            $actorUserId,
        );
        return $this->pollUsing(
            $context,
            $channelCode,
            $this->channels->inbox($channelCode),
            $folderId,
            $limit,
            $actorUserId,
        );
    }

    /**
     * Ruční vyzvednutí přes právě ověřenou jednorázovou relaci.
     *
     * Přístupové údaje se neukládají do repository; kanál i context platí jen
     * pro toto synchronní volání vyvolané konkrétním uživatelem.
     *
     * @return array{fetched:int,stored:int,skipped:int,failed:int,unclassified:int,error:?string}
     */
    public function pollWithChannel(
        ChannelContext $context,
        string $channelCode,
        SubmissionInboxChannel $channel,
        ?int $folderId = null,
        int $limit = 50,
        ?int $actorUserId = null,
    ): array {
        $this->assertActorPresent($actorUserId);
        return $this->pollUsing($context, $channelCode, $channel, $folderId, $limit, $actorUserId);
    }

    /**
     * @return array{fetched:int,stored:int,skipped:int,failed:int,unclassified:int,error:?string}
     */
    private function pollUsing(
        ChannelContext $context,
        string $channelCode,
        SubmissionInboxChannel $channel,
        ?int $folderId,
        int $limit,
        ?int $actorUserId,
    ): array {
        $supplierId = $context->supplierId;
        $environment = $context->environment;
        $result = ['fetched' => 0, 'stored' => 0, 'skipped' => 0, 'failed' => 0, 'unclassified' => 0, 'error' => null];

        // Auditní stopa se zapisuje PŘED voláním, ne po něm: doručení nastane
        // okamžikem přihlášení, i když se pak spojení přeruší. Záznam až po
        // úspěchu by právě ty sporné případy zamlčel.
        $this->activity->log(
            'databox_inbox_list_fetched',
            $actorUserId,
            'databox',
            $supplierId,
            ['environment' => $environment, 'legal_basis' => '§ 17 odst. 3 zák. 300/2008 Sb.'],
            null,
            null,
            $supplierId,
        );

        try {
            $listing = $channel->listNew($context);
        } catch (SubmissionChannelException $e) {
            // Selhání dotazu se NIKDY netváří jako prázdná schránka.
            $this->inbox->recordPollFailure($supplierId, $channelCode, $environment, $e->errorCode, $e->getMessage());
            $this->logger->error('Submission inbox poll failed', [
                'supplier_id' => $supplierId,
                'channel' => $channelCode,
                'error_code' => $e->errorCode,
            ]);
            $result['failed'] = 1;
            $result['error'] = $e->errorCode;
            return $result;
        }

        $result['fetched'] = $listing->count();

        $boxKinds = $this->recipientBoxKinds($supplierId);
        $processed = 0;

        foreach ($listing->messages as $header) {
            if ($processed >= $limit) {
                break;
            }
            $processed++;

            if ($this->inbox->exists($supplierId, $channelCode, $environment, $header->externalMessageId)) {
                $result['skipped']++;
                continue;
            }

            try {
                $stored = $this->ingest($context, $channelCode, $channel, $header, $boxKinds, $folderId);
                $result['stored']++;
                if ($stored['classification'] === InboxMessageClassifier::UNCLASSIFIED) {
                    $result['unclassified']++;
                }
            } catch (\Throwable $e) {
                // Jedna rozbitá zpráva nesmí zablokovat zbytek schránky.
                $result['failed']++;
                $this->logger->error('Submission inbox message ingest failed', [
                    'supplier_id' => $supplierId,
                    'channel' => $channelCode,
                    'message_id' => $header->externalMessageId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($result['failed'] > 0) {
            $result['error'] = 'isds_inbox_message_ingest_failed';
            $this->inbox->recordPollFailure(
                $supplierId,
                $channelCode,
                $environment,
                $result['error'],
                'Některé zprávy se nepodařilo stáhnout nebo uložit (' . $result['failed'] . ' z ' . $result['fetched'] . ').',
            );
        } else {
            // Úspěch se zapisuje i při nula zprávách — právě tenhle záznam odlišuje
            // „schránka je prázdná" od „na schránku se nedovoláme".
            $this->inbox->recordPollSuccess($supplierId, $channelCode, $environment, $listing->count());
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    public function listRecent(int $supplierId, string $environment, ?string $classification = null, int $limit = 100): array
    {
        return $this->inbox->listRecent($supplierId, $environment, $classification, $limit);
    }

    /** @return array<string,mixed>|null */
    public function pollState(int $supplierId, string $channelCode, string $environment): ?array
    {
        return $this->inbox->pollState($supplierId, $channelCode, $environment);
    }

    /** Ruční zařazení zprávy, kterou automat nepoznal. */
    public function reclassify(int $supplierId, int $messageId, string $classification, ?int $outboxId): bool
    {
        $allowed = [
            InboxMessageClassifier::DELIVERY_RECEIPT,
            InboxMessageClassifier::CSSZ_PROTOCOL,
            InboxMessageClassifier::HEALTH_INSURER_RESPONSE,
            InboxMessageClassifier::TAX_OFFICE_RESPONSE,
            InboxMessageClassifier::UNCLASSIFIED,
        ];
        if (!in_array($classification, $allowed, true)) {
            throw new SubmissionChannelException('invalid_classification', 'Neznámé zařazení zprávy.', 400);
        }
        if ($classification === InboxMessageClassifier::UNCLASSIFIED && $outboxId !== null) {
            throw new SubmissionChannelException(
                'unclassified_cannot_link',
                'Nezařazená zpráva nemůže být navázaná na podání.',
                400,
            );
        }
        return $this->inbox->reclassify($supplierId, $messageId, $classification, $outboxId);
    }

    // ───────────────────────── interní ─────────────────────────

    /**
     * Brána § 17 odst. 3. Bez konkrétního uživatele, který právě stiskl akci
     * v UI, se na schránku nesahá. Trvalý souhlas ani cron tuto podmínku
     * nenahrazují.
     */
    private function assertInteractivePollingAllowed(
        int $supplierId,
        string $channelCode,
        string $environment,
        ?int $actorUserId,
    ): void
    {
        $this->assertActorPresent($actorUserId);
        $credential = $this->credentials->findPublic($supplierId, $channelCode, $environment);
        if ($credential === null) {
            throw new SubmissionChannelException(
                'credentials_missing',
                'Přístup k datové schránce není nastavený. Doplňte systémový certifikát v Firma → Datová schránka.',
                409,
            );
        }
    }

    private function assertActorPresent(?int $actorUserId): void
    {
        if ($actorUserId === null || $actorUserId <= 0) {
            throw new SubmissionChannelException(
                'interactive_action_required',
                'Datovou schránku lze vyzvednout jen výslovnou akcí přihlášeného uživatele.',
                409,
            );
        }
    }

    /**
     * @param array<string,string> $boxKinds
     * @return array{classification:string,matched_outbox_id:?int}
     */
    private function ingest(
        ChannelContext $context,
        string $channelCode,
        SubmissionInboxChannel $channel,
        InboxMessageHeader $header,
        array $boxKinds,
        ?int $folderId,
    ): array {
        $bytes = $channel->download($header->externalMessageId, $context);

        $ingested = $this->documents->ingestZfoBytes(
            $bytes,
            $context->supplierId,
            $folderId,
            'datova-zprava-' . $header->externalMessageId . '.zfo',
            null,
        );

        $verdict = $this->classifier->classify($context->supplierId, $context->environment, $header, $boxKinds);

        $message = $this->inbox->record([
            'supplier_id' => $context->supplierId,
            'environment' => $context->environment,
            'channel' => $channelCode,
            'external_message_id' => $header->externalMessageId,
            'sender_box_id' => $header->senderBoxId,
            'sender_name' => $header->senderName,
            'subject' => $header->subject,
            'sender_ident' => $header->senderIdent,
            'classification' => $verdict['classification'],
            'matched_outbox_id' => $verdict['matched_outbox_id'],
            'document_id' => $ingested['container_id'] > 0 ? $ingested['container_id'] : null,
            'delivered_at' => $header->deliveredAt?->format('Y-m-d H:i:s'),
            'accepted_at' => $header->acceptedAt?->format('Y-m-d H:i:s'),
            'raw_sha256' => hash('sha256', $bytes),
        ]);

        // Rozhodný den doručení se určuje hned při uložení. Kdyby se počítal až
        // při čtení, měnil by se podle toho, kdy se kdo podívá — a od něj běží
        // lhůty, takže musí být uložený a dohledatelný.
        $this->resolveDelivery($message);

        $this->applyDeliveryReceipt($context, $verdict, $header);

        return $verdict;
    }

    /**
     * Doručenka posune podání na „doručeno" — a NIC VÍC.
     *
     * Tohle je to místo, kde by se záměna „doručeno = přijato" vloudila
     * nejsnáz: přišla doručenka, podání je tedy hotové, ne? Není. Osy
     * vyřízení se tu nedotýkáme vůbec; kdyby se o to někdo pokusil, odmítne
     * to i DB trigger (`delivery must not change acceptance state in the same write`).
     */
    /** @param array{classification:string,matched_outbox_id:?int} $verdict */
    private function applyDeliveryReceipt(ChannelContext $context, array $verdict, InboxMessageHeader $header): void
    {
        if ($verdict['classification'] !== InboxMessageClassifier::DELIVERY_RECEIPT) {
            return;
        }
        $outboxId = $verdict['matched_outbox_id'];
        if ($outboxId === null) {
            return;
        }
        $deliveredAt = $header->deliveredAt ?? $header->acceptedAt;
        if ($deliveredAt === null) {
            return;
        }

        try {
            $this->outboxService->applyStatus(
                $context->supplierId,
                $outboxId,
                ChannelStatus::deliveredOnly(
                    $deliveredAt,
                    'Doručenka z datové schránky. O vyřízení úřadem nevypovídá.',
                ),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Delivery receipt could not be applied to submission', [
                'supplier_id' => $context->supplierId,
                'outbox_id' => $outboxId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Selhání výpočtu doručení nesmí položit stažení zprávy — zpráva uložená
     * bez závěru zůstane v `unknown` a přepočet ji zase najde. Opačné pořadí
     * priorit (radši zprávu nestáhnout) by znamenalo, že chyba ve výpočtu
     * zablokuje celou schránku.
     *
     * @param array<string,mixed> $message
     */
    private function resolveDelivery(array $message): void
    {
        try {
            $this->delivery->resolveMessage($message);
        } catch (\Throwable $e) {
            $this->logger->warning('Delivery resolution failed for inbox message', [
                'supplier_id' => $message['supplier_id'] ?? null,
                'message_id' => $message['id'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @return array<string,string> boxId → kind */
    private function recipientBoxKinds(int $supplierId): array
    {
        $map = [];
        foreach ($this->recipients->listVisible($supplierId) as $recipient) {
            $box = $recipient['isds_box_id'];
            if (is_string($box) && $box !== '') {
                $map[strtolower($box)] = (string) $recipient['kind'];
            }
        }
        return $map;
    }
}
