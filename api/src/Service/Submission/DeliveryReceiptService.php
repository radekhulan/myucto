<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Repository\Submission\SubmissionInboxRepository;
use MyInvoice\Repository\Submission\SubmissionOutboxRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Document\DocumentIngestService;
use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\ChannelStatus;
use MyInvoice\Service\Submission\Channel\DispatchState;
use MyInvoice\Service\Submission\Channel\Isds\IsdsTransport;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use Psr\Log\LoggerInterface;

/**
 * Nahraná doručenka → spárování s podáním. Poslední chybějící centimetr cesty,
 * která jinak celá běží.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Jak se sem doručenka dostane
 * ═══════════════════════════════════════════════════════════════════════════
 * Strojové napojení na datovou schránku nasazené není a podle rozboru rozsahu
 * ani být nemusí: zprávu odesílá člověk ze své vlastní schránky. Aplikace mu
 * připraví přílohu, příjemce i spisovou značku; on ji odešle a **stáhne
 * doručenku, kterou sem nahraje.** Teprve tím vznikne průkazná evidence —
 * kdo, co, kdy a s jakým výsledkem podal.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Tři pravidla, která tuhle třídu drží pohromadě
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * **1. Nehádáme.** Automaticky se páruje jedině přes přesný identifikátor
 * ({@see DeliveryReceiptMatcher}). Když ho doručenka nenese, nabídnou se
 * kandidáti a **nic se nezmění, dokud člověk nepotvrdí**. Nespárovaná
 * doručenka skončí v „nezařazeno", kde je vidět — ne v tichu.
 *
 * **2. Doručenka posouvá jen osu doručení.** Nikdy osu vyřízení. Podání
 * doručené do schránky úřadu není podání, které úřad přijal; mezi tím leží
 * celé zpracování a případná výzva k odstranění vad podle § 74 DŘ. Vynucují to
 * tři nezávislé vrstvy: tahle třída se osy vyřízení nedotkne,
 * {@see SubmissionOutboxService::applyStatus()} tvrzení o přijetí od kanálu
 * s `DeliveryOnly` zahodí, a `acceptance_evidence_kind` v databázi pro
 * doručenku nemá hodnotu — takový zápis prostě nejde formulovat.
 *
 * **3. Nahrání je idempotentní.** Táž doručenka podruhé nezaloží druhý
 * dokument, druhou zprávu ani druhý důkaz. Drží to tři zámky: kontrola
 * existence PŘED uložením do DMS, unikátní klíč
 * `uq_submission_inbox_message`, a jednorázové přiřazení `receipt_document_id`
 * na úrovni DB triggeru.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ⚠️ Podpis doručenky NEOVĚŘUJEME
 * ═══════════════════════════════════════════════════════════════════════════
 * {@see \MyInvoice\Service\Document\ZfoExtractor} obsah PKCS#7 obálky jen
 * rozbalí — CMS podpis ani časové razítko nekontroluje. Doručenka je proto
 * **nahlášený**, ne ověřený důkaz: `receipt_signature_status` zůstává
 * `unverified` a UI to musí říkat nahlas. Poctivé „nevíme" je lepší než
 * falešná jistota; kdo by ji bral jako ověřenou, spoléhal by na razítko,
 * které nikdo nečetl.
 */
final readonly class DeliveryReceiptService
{
    /** Doručenka se přiřadila sama přes přesný identifikátor. */
    public const STATUS_MATCHED = 'matched';
    /** Máme kandidáty, čeká se na člověka. Nic se nezměnilo. */
    public const STATUS_CANDIDATES = 'candidates';
    /** Nemáme co nabídnout — doručenka leží v „nezařazeno". */
    public const STATUS_UNMATCHED = 'unmatched';
    /** Tatáž doručenka už tu je. Druhý průchod nic nemění. */
    public const STATUS_ALREADY_PROCESSED = 'already_processed';
    /**
     * ISDS dodejku k naší zprávě zatím nemá — zpráva ještě nebyla doručena.
     * Je to STAV, ne selhání; selhání dotazu letí ven jako výjimka.
     */
    public const STATUS_NOT_AVAILABLE = 'not_available';

    private const CHANNEL = 'isds';
    private const ENVIRONMENTS = ['production', 'test'];

    public function __construct(
        private DeliveryReceiptReader $reader,
        private DeliveryReceiptMatcher $matcher,
        private SubmissionInboxRepository $inbox,
        private SubmissionOutboxRepository $outbox,
        private SubmissionOutboxService $outboxService,
        private DocumentIngestService $documents,
        private ActivityLogger $activity,
        private LoggerInterface $logger,
    ) {}

    /**
     * Nahrání doručenky uživatelem.
     *
     * @param ?int $outboxId když uživatel nahrává doručenku PŘÍMO u podání,
     *                       je to jeho vlastní rozhodnutí o vazbě — bere se
     *                       jako ruční potvrzení, ne jako automatická shoda
     * @param ?string $matchedBy čím je vazba na `$outboxId` podložená. `null`
     *                       znamená rozhodnutí člověka; {@see downloadFromIsds()}
     *                       sem pošle {@see DeliveryReceiptMatcher::BY_ISDS_DOWNLOAD},
     *                       protože tam za vazbu ručí ISDS, ne uživatel
     * @return array<string,mixed>
     * @throws \MyInvoice\Service\Document\DocumentException když soubor není čitelná doručenka
     */
    public function upload(
        int $supplierId,
        string $environment,
        string $bytes,
        string $filename,
        int $userId,
        ?int $outboxId = null,
        ?int $folderId = null,
        ?string $matchedBy = null,
    ): array {
        $this->assertEnvironment($environment);
        $receipt = $this->reader->read($bytes);

        // ── Zámek idempotence č. 1 ──
        // Kontrola PŘED uložením do DMS. Kdyby byla až za ním, druhé nahrání
        // téhož souboru by sice nezaložilo druhou zprávu, ale nechalo by
        // v Dokumentech duplicitní kontejner s přílohami.
        $existing = $this->inbox->find($supplierId, self::CHANNEL, $environment, $receipt->messageId);
        if ($existing !== null) {
            return $this->alreadyProcessed($supplierId, $existing, $receipt);
        }

        $verdict = $outboxId !== null
            ? $this->verdictForExplicitTarget($supplierId, $environment, $outboxId, $matchedBy)
            : $this->matcher->match($supplierId, $environment, $receipt);

        $ingested = $this->documents->ingestZfoBytes(
            $bytes,
            $supplierId,
            $folderId,
            $this->safeFilename($filename, $receipt->messageId),
            $userId,
        );
        $documentId = $ingested['container_id'] > 0 ? $ingested['container_id'] : null;

        $matchedOutboxId = $verdict['status'] === DeliveryReceiptMatcher::STATUS_MATCHED
            ? $verdict['outbox_id']
            : null;

        // ── Zámek idempotence č. 2 ──
        // `record()` chytá porušení unikátního klíče a vrátí existující řádek.
        $message = $this->inbox->record([
            'supplier_id' => $supplierId,
            'environment' => $environment,
            'channel' => self::CHANNEL,
            'external_message_id' => $receipt->messageId,
            'sender_box_id' => $receipt->senderBoxId,
            'sender_name' => $receipt->senderName,
            'subject' => $receipt->subject !== null ? mb_substr($receipt->subject, 0, 255) : null,
            'sender_ident' => $receipt->senderIdent !== null ? mb_substr($receipt->senderIdent, 0, 64) : null,
            'classification' => InboxMessageClassifier::DELIVERY_RECEIPT,
            'matched_outbox_id' => $matchedOutboxId,
            'document_id' => $documentId,
            'delivered_at' => $receipt->deliveryTime?->format('Y-m-d H:i:s'),
            'accepted_at' => $receipt->acceptanceTime?->format('Y-m-d H:i:s'),
            'raw_sha256' => $receipt->rawSha256,
        ]);

        $result = $this->baseResult($verdict['status'], $receipt, $message, $documentId);
        $result['candidates'] = $verdict['candidates'];
        $result['reason'] = $verdict['reason'];

        if ($matchedOutboxId === null) {
            $this->logUnmatched($supplierId, $userId, $receipt, $verdict);

            return $result + [
                'outbox_id' => null,
                'matched_by' => null,
                'submission' => null,
                'message' => $verdict['status'] === DeliveryReceiptMatcher::STATUS_CANDIDATES
                    ? 'Doručenka je uložená, ale sama se k podání nepřiřadila. Vyberte podání, ke kterému patří.'
                    : 'Doručenka je uložená v nezařazených — k žádnému čekajícímu podání ji nejde přiřadit. '
                      . 'Zkontrolujte, jestli je nahraná ve správné firmě a prostředí.',
            ];
        }

        try {
            $applied = $this->applyToSubmission(
                $supplierId,
                (int) $matchedOutboxId,
                $receipt,
                (int) $message['id'],
                $documentId,
                (string) $verdict['matched_by'],
                $userId,
            );
        } catch (SubmissionChannelException $e) {
            // Vazba se nedala dotáhnout (podání už má jinou doručenku, mezitím
            // se zavřelo…). Zpráva NESMÍ zůstat viset navázaná na podání,
            // kterému se nic nezapsalo — jinak by zmizela z nezařazených
            // a přitom by nikde nic nedokládala.
            $this->inbox->reclassify(
                $supplierId,
                (int) $message['id'],
                InboxMessageClassifier::DELIVERY_RECEIPT,
                null,
            );
            $this->logUnmatched($supplierId, $userId, $receipt, $verdict);

            return array_merge($result, [
                'status' => self::STATUS_UNMATCHED,
                'reason' => $e->errorCode,
                'candidates' => [],
                'outbox_id' => null,
                'matched_by' => null,
                'submission' => null,
                'validation' => null,
                'delivery_recorded' => false,
                'message' => $e->getMessage() . ' Doručenka zůstala uložená v nezařazených.',
            ]);
        }

        return array_merge($result, $applied, [
            'outbox_id' => (int) $matchedOutboxId,
            'matched_by' => $verdict['matched_by'],
        ]);
    }

    /**
     * Vyžádá si doručenku k odeslanému podání přímo z ISDS
     * (`GetSignedDeliveryInfo`) a projde s ní tutéž cestu jako ruční nahrání.
     *
     * ── Proč to existuje ────────────────────────────────────────────────────
     * U agend, které {@see AgendaReceiptCapability} řadí mezi
     * `DeliveryReceiptOnly` — typicky hlášení zdravotním pojišťovnám — je
     * doručenka JEDINÝ strojový důkaz, který kdy vznikne. Pojišťovna žádnou
     * odpověď neposílá, takže bez doručenky nemá podání v evidenci nic než
     * naše vlastní tvrzení, že odešlo. Nutit kvůli tomu účetní exportovat ZFO
     * z portálu datovky a nahrávat ho zpátky je zbytečné kolečko: `dmID` té
     * zprávy aplikace zná, protože si ho zapsala při odeslání.
     *
     * ── Právní poznámka: tohle NENÍ doručení ────────────────────────────────
     * Právně významný úkon je `GetListOfReceivedMessages` — přihlášení do
     * schránky, a tím doručení všech DODANÝCH zpráv podle § 17 odst. 3
     * zák. 300/2008 Sb. `GetSignedDeliveryInfo` se ptá na dodejku k NAŠÍ
     * ODESLANÉ zprávě; ta v naší schránce žádnou dodanou zprávou není a lhůta
     * doručení běží příjemci, ne nám. Tenhle následek tedy nemá.
     *
     * Přesto se volá výhradně z výslovné akce přihlášeného uživatele, ne na
     * pozadí: sahá to na síť pod pověřením firmy a to má být vidět v auditní
     * stopě s konkrétním člověkem.
     *
     * ── Fail-closed ─────────────────────────────────────────────────────────
     * `null` z transportu znamená VÝHRADNĚ „zpráva ještě dodejku nemá"
     * (nedoručeno). Selhání dotazu letí ven jako {@see SubmissionChannelException} —
     * nikdy se nesmí přeložit na „doručenka není". Rozdíl mezi „ještě nedorazila"
     * a „nepodařilo se zeptat" je rozdíl mezi čekáním a opravou.
     *
     * @return array<string,mixed> tvar jako {@see upload()}; navíc `status`
     *         {@see self::STATUS_NOT_AVAILABLE}, když dodejka ještě není
     * @throws SubmissionChannelException když se na dodejku nedá zeptat
     */
    public function downloadFromIsds(
        int $supplierId,
        string $environment,
        int $outboxId,
        int $userId,
        ChannelContext $context,
        IsdsTransport $transport,
    ): array {
        $this->assertEnvironment($environment);
        $submission = $this->outbox->find($supplierId, $outboxId);
        if ($submission === null) {
            throw new SubmissionChannelException('submission_not_found', 'Podání ve frontě není.', 404);
        }
        if ((string) $submission['environment'] !== $environment) {
            throw new SubmissionChannelException(
                'submission_environment_mismatch',
                'Podání je z jiného prostředí. Testovací doručenka nesmí hnout produkčním podáním.',
                409,
            );
        }
        if ((string) $submission['channel'] !== self::CHANNEL) {
            throw new SubmissionChannelException(
                'receipt_channel_unsupported',
                'Doručenku umí vydat jen datová schránka. Tohle podání šlo jiným kanálem.',
                409,
            );
        }
        if ($submission['receipt_document_id'] !== null) {
            throw new SubmissionChannelException(
                'receipt_already_attached',
                'Tohle podání už doručenku má.',
                409,
            );
        }
        $messageId = trim((string) ($submission['external_message_id'] ?? ''));
        if ($messageId === '') {
            // Bez dmID není na co se ptát. Je to legitimní stav (podání ještě
            // neodešlo, nebo odešlo ručně a číslo nikdo neopsal), ne porucha.
            throw new SubmissionChannelException(
                'receipt_message_id_missing',
                'U podání není ID odeslané datové zprávy, takže si o doručenku nejde říct. '
                . 'Doplňte ho přes „Označit jako odesláno", nebo doručenku nahrajte souborem.',
                409,
            );
        }

        $bytes = $transport->downloadDeliveryReceipt($context, $messageId);
        if ($bytes === null || $bytes === '') {
            $this->activity->log(
                'databox_receipt_download_pending',
                $userId,
                'submission_outbox',
                $outboxId,
                ['message_id' => $messageId],
                null,
                null,
                $supplierId,
            );

            return [
                'status' => self::STATUS_NOT_AVAILABLE,
                'outbox_id' => $outboxId,
                'inbox_message_id' => null,
                'document_id' => null,
                'receipt' => null,
                'candidates' => [],
                'reason' => 'not_delivered_yet',
                'matched_by' => null,
                'submission' => $submission,
                'validation' => null,
                'delivery_recorded' => false,
                'message' => 'ISDS k téhle zprávě zatím dodejku nemá — příjemce ji ještě nedostal. '
                    . 'Zkuste to znovu později; nejde o chybu podání.',
            ];
        }

        return $this->upload(
            $supplierId,
            $environment,
            $bytes,
            'dodejka-' . $messageId . '.zfo',
            $userId,
            $outboxId,
            null,
            DeliveryReceiptMatcher::BY_ISDS_DOWNLOAD,
        );
    }

    /**
     * Potvrzení vazby člověkem u doručenky, která se sama nepřiřadila.
     *
     * @return array<string,mixed>
     */
    public function confirmMatch(int $supplierId, int $inboxMessageId, int $outboxId, int $userId): array
    {
        $message = $this->inbox->findById($supplierId, $inboxMessageId);
        if ($message === null) {
            throw new SubmissionChannelException('receipt_not_found', 'Doručenka nebyla nalezena.', 404);
        }
        if ((string) $message['classification'] !== InboxMessageClassifier::DELIVERY_RECEIPT) {
            throw new SubmissionChannelException(
                'not_a_delivery_receipt',
                'Tahle zpráva není doručenka. K podání jde připojit jen doručenka.',
                409,
            );
        }

        $submission = $this->outbox->find($supplierId, $outboxId);
        if ($submission === null) {
            throw new SubmissionChannelException('submission_not_found', 'Podání ve frontě není.', 404);
        }
        if ((string) $submission['environment'] !== (string) $message['environment']
            || (string) $submission['channel'] !== self::CHANNEL
        ) {
            throw new SubmissionChannelException(
                'submission_environment_mismatch',
                'Doručenka a podání nejsou ze stejného prostředí. Testovací doručenka nesmí hnout produkčním podáním.',
                409,
            );
        }

        $alreadyLinked = $message['matched_outbox_id'] !== null ? (int) $message['matched_outbox_id'] : null;
        if ($alreadyLinked !== null && $alreadyLinked !== $outboxId) {
            throw new SubmissionChannelException(
                'receipt_already_matched',
                'Tahle doručenka už patří k jinému podání (#' . $alreadyLinked . ').',
                409,
            );
        }
        if ($alreadyLinked === null) {
            $this->inbox->linkToOutbox($supplierId, $inboxMessageId, $outboxId);
        }

        $receipt = self::receiptFromMessage($message);
        try {
            $applied = $this->applyToSubmission(
                $supplierId,
                $outboxId,
                $receipt,
                $inboxMessageId,
                $message['document_id'] !== null ? (int) $message['document_id'] : null,
                DeliveryReceiptMatcher::BY_MANUAL,
                $userId,
            );
        } catch (SubmissionChannelException $e) {
            // Vazbu vracíme zpátky, aby doručenka nezůstala „přiřazená"
            // k podání, kterému se nic nezapsalo.
            if ($alreadyLinked === null) {
                $this->inbox->reclassify(
                    $supplierId,
                    $inboxMessageId,
                    InboxMessageClassifier::DELIVERY_RECEIPT,
                    null,
                );
            }
            throw $e;
        }

        $result = $this->baseResult(self::STATUS_MATCHED, $receipt, $message, $message['document_id'] !== null ? (int) $message['document_id'] : null);

        return array_merge($result, $applied, [
            'outbox_id' => $outboxId,
            'matched_by' => DeliveryReceiptMatcher::BY_MANUAL,
            'candidates' => [],
            'reason' => DeliveryReceiptMatcher::BY_MANUAL,
        ]);
    }

    /**
     * Kandidáti pro doručenku, která už v aplikaci leží nespárovaná.
     *
     * @return list<array<string,mixed>>
     */
    public function candidatesFor(int $supplierId, int $inboxMessageId): array
    {
        $message = $this->inbox->findById($supplierId, $inboxMessageId);
        if ($message === null) {
            throw new SubmissionChannelException('receipt_not_found', 'Doručenka nebyla nalezena.', 404);
        }
        $verdict = $this->matcher->match(
            $supplierId,
            (string) $message['environment'],
            self::receiptFromMessage($message),
        );

        return $verdict['candidates'];
    }

    /**
     * Doručenky, které se k ničemu nepřiřadily. Prázdno tu znamená
     * „všechno je spárované", ne „nic nedorazilo".
     *
     * @return list<array<string,mixed>>
     */
    public function listUnmatched(int $supplierId, string $environment): array
    {
        $this->assertEnvironment($environment);

        return $this->inbox->listUnmatchedReceipts($supplierId, $environment);
    }

    // ───────────────────────── interní ─────────────────────────

    /**
     * Zapíše, co doručenka o podání dokládá.
     *
     * Pořadí kroků není náhodné:
     *   1. **odeslání** — u ručně odeslaného podání je doručenka zároveň
     *      jediným důkazem, že zpráva vůbec odešla, a nese i její dmID,
     *   2. **doručení** — vlastním zápisem, protože DB trigger odmítne UPDATE,
     *      který by hnul doručením a vyřízením najednou,
     *   3. **připojení souboru** — až nakonec, aby v případě selhání kroků
     *      výš nevznikl důkaz u podání, jehož stav se nezměnil.
     *
     * Osy vyřízení se tenhle kód nedotýká vůbec. Ani jedním řádkem.
     *
     * @return array{submission:?array<string,mixed>,message:string,validation:?array<string,mixed>,delivery_recorded:bool}
     */
    private function applyToSubmission(
        int $supplierId,
        int $outboxId,
        DeliveryReceipt $receipt,
        int $inboxMessageId,
        ?int $documentId,
        string $matchedBy,
        int $userId,
    ): array {
        $submission = $this->outbox->find($supplierId, $outboxId);
        if ($submission === null) {
            throw new SubmissionChannelException('submission_not_found', 'Podání ve frontě není.', 404);
        }

        $validation = null;
        $sentAt = $receipt->sentAt() ?? $receipt->deliveredAt();
        $state = (string) $submission['dispatch_state'];

        // 1) Odeslání. Ručně odeslané podání se o svém odchodu dozví právě teď.
        if (in_array($state, [
            DispatchState::Ready->value,
            DispatchState::Sending->value,
            DispatchState::SendUncertain->value,
        ], true)) {
            if ($sentAt === null) {
                // Bez času nejde `sent_at` zapsat tak, aby neodporoval doručení.
                // Radši doručenku jen připojíme jako důkaz a stav necháme být,
                // než abychom si čas domysleli.
                $this->logger->warning('Delivery receipt carries no timestamps', [
                    'supplier_id' => $supplierId,
                    'outbox_id' => $outboxId,
                    'message_id' => $receipt->messageId,
                ]);
            } else {
                $marked = $this->outboxService->markSentManually(
                    $supplierId,
                    $outboxId,
                    $userId,
                    $receipt->messageId,
                    $sentAt,
                );
                $validation = $marked['validation'];
                $submission = $marked['row'];
            }
        }

        // 2) Doručení — a nic víc. `applyStatus` osu vyřízení neposune ani
        //    tehdy, kdyby ji kanál tvrdil: ISDS má `DeliveryOnly`.
        $deliveredAt = $receipt->deliveredAt();
        $deliveryRecorded = false;
        if ($deliveredAt !== null
            && (string) $submission['dispatch_state'] === DispatchState::Sent->value
        ) {
            $submission = $this->outboxService->applyStatus(
                $supplierId,
                $outboxId,
                ChannelStatus::deliveredOnly(
                    $deliveredAt,
                    ($matchedBy === DeliveryReceiptMatcher::BY_ISDS_DOWNLOAD
                        ? 'Doručenka stažená aplikací z ISDS. '
                        : 'Doručenka nahraná uživatelem. ')
                    . 'Dokládá doručení do schránky příjemce, '
                    . 'ne vyřízení úřadem. Podpis doručenky neověřujeme.',
                ),
            );
            $deliveryRecorded = (string) $submission['dispatch_state'] === DispatchState::Delivered->value;
        }

        // 3) Soubor jako důkaz.
        if ($documentId !== null) {
            $attached = $this->outboxService->attachReceipt(
                $supplierId,
                $outboxId,
                $documentId,
                $inboxMessageId,
                $matchedBy,
            );
            $submission = $attached['row'];
        }

        $this->activity->log(
            'databox_receipt_matched',
            $userId,
            'submission_outbox',
            $outboxId,
            [
                'matched_by' => $matchedBy,
                'message_id' => $receipt->messageId,
                'signature_status' => 'unverified',
            ],
            null,
            null,
            $supplierId,
        );

        return [
            'submission' => $submission,
            'validation' => $validation,
            'delivery_recorded' => $deliveryRecorded,
            'message' => $this->outcomeSentence($submission, $deliveryRecorded, $validation),
        ];
    }

    /**
     * Věta, podle které se dá jednat. Ne „hotovo", ale co přesně platí.
     *
     * @param array<string,mixed> $submission
     * @param ?array<string,mixed> $validation
     */
    private function outcomeSentence(array $submission, bool $deliveryRecorded, ?array $validation): string
    {
        $sentence = $deliveryRecorded
            ? 'Doručenka je připojená k podání a podání je označené jako doručené do schránky příjemce. '
              . 'O vyřízení úřadem to nevypovídá a podpis doručenky zatím neověřujeme.'
            : 'Doručenka je připojená k podání. Podpis doručenky zatím neověřujeme.';

        if ($validation !== null && ($validation['status'] ?? null) === 'failed') {
            $sentence .= ' Pozor: odeslaný podklad neprošel kontrolou proti XSD schématu, '
                . 'takže ho úřad může vrátit jako vadné podání podle § 74 daňového řádu.';
        } elseif ($validation !== null && ($validation['checked'] ?? true) === false) {
            $sentence .= ' Obsah podání se zkontrolovat nedal — podklad už v aplikaci není.';
        }

        return $sentence;
    }

    /**
     * Uživatel nahrál doručenku přímo u konkrétního podání. Je to jeho
     * rozhodnutí o vazbě, tedy `manual` — ne automatická shoda, i kdyby
     * identifikátory náhodou seděly.
     *
     * @return array{status:string,outbox_id:?int,matched_by:?string,reason:string,candidates:list<array<string,mixed>>}
     */
    private function verdictForExplicitTarget(
        int $supplierId,
        string $environment,
        int $outboxId,
        ?string $matchedBy = null,
    ): array {
        $submission = $this->outbox->find($supplierId, $outboxId);
        if ($submission === null) {
            throw new SubmissionChannelException('submission_not_found', 'Podání ve frontě není.', 404);
        }
        if ((string) $submission['environment'] !== $environment || (string) $submission['channel'] !== self::CHANNEL) {
            throw new SubmissionChannelException(
                'submission_environment_mismatch',
                'Doručenka a podání nejsou ze stejného prostředí.',
                409,
            );
        }
        $matchedBy ??= DeliveryReceiptMatcher::BY_MANUAL;

        return [
            'status' => DeliveryReceiptMatcher::STATUS_MATCHED,
            'outbox_id' => $outboxId,
            'matched_by' => $matchedBy,
            'reason' => $matchedBy,
            'candidates' => [],
        ];
    }

    /**
     * @param array<string,mixed> $existing
     * @return array<string,mixed>
     */
    private function alreadyProcessed(int $supplierId, array $existing, DeliveryReceipt $receipt): array
    {
        $outboxId = $existing['matched_outbox_id'] !== null ? (int) $existing['matched_outbox_id'] : null;

        $result = $this->baseResult(
            self::STATUS_ALREADY_PROCESSED,
            $receipt,
            $existing,
            $existing['document_id'] !== null ? (int) $existing['document_id'] : null,
        );

        return $result + [
            'outbox_id' => $outboxId,
            'matched_by' => null,
            'candidates' => [],
            'reason' => 'duplicate_upload',
            'submission' => $outboxId !== null ? $this->outbox->find($supplierId, $outboxId) : null,
            'validation' => null,
            'delivery_recorded' => false,
            'message' => $outboxId !== null
                ? 'Tuhle doručenku už tu máte — je připojená k podání a nic se nezměnilo.'
                : 'Tuhle doručenku už tu máte. Leží v nezařazených a čeká na přiřazení k podání.',
        ];
    }

    /**
     * @param array<string,mixed> $message
     * @return array<string,mixed>
     */
    private function baseResult(string $status, DeliveryReceipt $receipt, array $message, ?int $documentId): array
    {
        return [
            'status' => $status,
            'inbox_message_id' => (int) $message['id'],
            'document_id' => $documentId,
            'receipt' => [
                'message_id' => $receipt->messageId,
                'sender_box_id' => $receipt->senderBoxId,
                'sender_name' => $receipt->senderName,
                'recipient_box_id' => $receipt->recipientBoxId,
                'recipient_name' => $receipt->recipientName,
                'sender_ident' => $receipt->senderIdent,
                'subject' => $receipt->subject,
                'sent_at' => $receipt->sentAt()?->format('Y-m-d H:i:s'),
                'delivered_at' => $receipt->deliveredAt()?->format('Y-m-d H:i:s'),
                // Konstanta, ne dopočet: dokud CMS podpis neověřujeme, nesmí
                // se doručenka tvářit jako ověřený důkaz.
                'signature_status' => 'unverified',
            ],
        ];
    }

    /**
     * @param array{status:string,outbox_id:?int,matched_by:?string,reason:string,candidates:list<array<string,mixed>>} $verdict
     */
    private function logUnmatched(int $supplierId, int $userId, DeliveryReceipt $receipt, array $verdict): void
    {
        $this->activity->log(
            'databox_receipt_unmatched',
            $userId,
            'databox',
            $supplierId,
            [
                'message_id' => $receipt->messageId,
                'reason' => $verdict['reason'],
                'candidates' => count($verdict['candidates']),
            ],
            null,
            null,
            $supplierId,
        );
    }

    /**
     * Doručenka zrekonstruovaná z už uloženého řádku — druhé čtení souboru
     * kvůli potvrzení vazby by bylo zbytečné a jen by přineslo možnost, že se
     * obojí rozejde.
     *
     * @param array<string,mixed> $message
     */
    private static function receiptFromMessage(array $message): DeliveryReceipt
    {
        return new DeliveryReceipt(
            messageId: (string) $message['external_message_id'],
            senderBoxId: self::nullableString($message['sender_box_id'] ?? null),
            senderName: self::nullableString($message['sender_name'] ?? null),
            recipientBoxId: null, // v inboxu se neukládá — pro potvrzenou vazbu není potřeba
            recipientName: null,
            senderIdent: self::nullableString($message['sender_ident'] ?? null),
            subject: self::nullableString($message['subject'] ?? null),
            deliveryTime: self::nullableTime($message['delivered_at'] ?? null),
            acceptanceTime: self::nullableTime($message['accepted_at'] ?? null),
            rawSha256: (string) ($message['raw_sha256'] ?? ''),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function nullableTime(mixed $value): ?\DateTimeImmutable
    {
        $value = self::nullableString($value);
        if ($value === null) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Název pod kterým doručenka přistane v Dokumentech. */
    private function safeFilename(string $filename, string $messageId): string
    {
        $filename = trim(basename(str_replace('\\', '/', $filename)));
        if ($filename === '' || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'zfo') {
            return 'dorucenka-' . preg_replace('/[^A-Za-z0-9._-]/', '', $messageId) . '.zfo';
        }

        return mb_substr($filename, 0, 255);
    }

    private function assertEnvironment(string $environment): void
    {
        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            throw new SubmissionChannelException('invalid_environment', 'Neznámé prostředí.', 400);
        }
    }
}
