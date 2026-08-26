<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel\Isds\Gateway;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Submission\IsdsGatewaySessionRepository;
use MyInvoice\Repository\Submission\SubmissionOutboxAttemptRepository;
use MyInvoice\Repository\Submission\SubmissionOutboxRepository;
use MyInvoice\Service\Submission\Channel\DispatchState;
use MyInvoice\Service\Submission\Channel\Isds\IsdsChannel;
use MyInvoice\Service\Submission\Channel\Isds\IsdsTransportTimeout;
use MyInvoice\Service\Submission\Channel\OutboundSubmission;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\SubmissionArtifactResolver;
use MyInvoice\Service\Submission\SubmissionArtifactValidator;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionDispatchProjection;
use Psr\Log\LoggerInterface;

/**
 * Odeslání podání přes odesílací bránu ISDS (`SetConcept`).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč to není implementace `IsdsTransport`
 * ═══════════════════════════════════════════════════════════════════════════
 * `IsdsTransport::createMessage()` je synchronní: zavolá se a buď zpráva odešla,
 * nebo ne. Odesílací brána takhle nefunguje a předstírat to by byla lež ve
 * jménu tvaru rozhraní. Mezi přípravou a odesláním stojí ČLOVĚK, který koncept
 * schvaluje přímo v ISDS, a mezitím proběhnou dvě přesměrování prohlížeče.
 *
 * `IsdsTransport` zůstává nabindovaný na `UnavailableIsdsTransport`, protože
 * čtecí operace (seznam zpráv, stažení zprávy, doručenka) brána neumí VŮBEC —
 * v celé specifikaci není zmínka o download. Ty tedy dál fail-closed odmítají,
 * a je to pravda, ne nedodělek.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Průběh a kde přesně se co stane nevratným
 * ═══════════════════════════════════════════════════════════════════════════
 *   {@see start()}     fronta `ready` → XSD kontrola → relace `awaiting_login`
 *                      → přesměrování na `/as/login`
 *   {@see complete()}  1. návrat s `sessionId` → `GetCredential` → `SetConcept`
 *                         → relace `awaiting_approval` → přesměrování na koncept
 *                      2. návrat po schválení → `GetCredential` → conceptDmId
 *                         → fronta `ready` → `sending` → `sent`
 *
 * **Fronta zůstává `ready` až do posledního kroku.** Je to úmysl: vložený
 * koncept není odeslaná zpráva a uživatel ho smí zamítnout. Kdyby se řádek
 * zabral dřív, zamítnutí by ho nechalo viset v `sending`, odkud podle DB
 * triggeru není cesty zpět na `ready` — a podání by se muselo zakládat znovu.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Izolace tenantů
 * ═══════════════════════════════════════════════════════════════════════════
 * `appToken` přichází z přesměrování v prohlížeči, tedy z místa, které
 * neřídíme. Slouží VÝHRADNĚ k vyhledání relace. O tom, jestli se s ní smí
 * pracovat, rozhoduje {@see assertOwnership()}: `supplier_id` i `user_id`
 * relace se musí shodovat s přihlášenou relací. Neshoda končí odmítnutím
 * a NEVOLÁ SE ŽÁDNÁ SÍŤOVÁ OPERACE — cizí token nesmí ani spotřebovat
 * `sessionId`, natož vložit koncept.
 */
final readonly class IsdsGatewayDispatchService
{
    private const CHANNEL = IsdsChannel::CODE;

    /** ISDS dává na zadání přihlašovacích údajů 5 minut (kap. 2.6 bod 2). */
    private const LOGIN_WINDOW_SECONDS = 300;

    public function __construct(
        private Connection $db,
        private SubmissionOutboxRepository $outbox,
        private SubmissionOutboxAttemptRepository $attempts,
        private IsdsGatewaySessionRepository $sessions,
        private IsdsGatewayRegistrationService $registrations,
        private IsdsGatewayClient $client,
        private SubmissionArtifactResolver $artifacts,
        private SubmissionArtifactValidator $validator,
        private LoggerInterface $logger,
        private ?PayrollSubmissionDispatchProjection $payrollProjection,
    ) {}

    /**
     * Zahájí odeslání: připraví koncept a vrátí adresu, kam poslat uživatele.
     *
     * @return array{
     *   session_id:int, app_token:string, redirect_url:string,
     *   login_guidance:string, login_policy_documented:bool, expires_at:string, resumed:bool
     * }
     */
    public function start(int $supplierId, int $outboxId, int $userId): array
    {
        $row = $this->outbox->find($supplierId, $outboxId);
        if ($row === null) {
            throw new SubmissionChannelException('submission_not_found', 'Podání ve frontě není.', 404);
        }
        if ((string) $row['channel'] !== self::CHANNEL) {
            throw new SubmissionChannelException(
                'isds_gateway_not_applicable',
                'Odesílací branou datové schránky jdou odeslat jen podání kanálem datové schránky.',
                409,
            );
        }
        if ((string) $row['dispatch_state'] !== DispatchState::Ready->value) {
            throw new SubmissionChannelException(
                'submission_not_ready',
                'Tohle podání už není připravené k odeslání (' . (string) $row['dispatch_state'] . ').',
                409,
            );
        }

        $environment = (string) $row['environment'];
        // Fail-closed: bez registrace, bez certifikátu nebo s vypnutou branou
        // se dál nejde. Chyba nese vlastní kód a uživateli říká, co má udělat.
        $registration = $this->registrations->load($environment);

        // Úklid nejdřív, jinak by relace, ze které se uživatel nevrátil,
        // zablokovala tohle podání natrvalo (UNIQUE nad živými relacemi).
        // Dělá se to tady a ne jen cronem, aby nasazení bez zapnutého cronu
        // nemělo tichou past.
        $this->sessions->expireStale();

        // Dvojí kliknutí: živá relace se neopakuje, uživatel se vrací do té,
        // kterou už má rozpracovanou.
        $existing = $this->sessions->findActiveForOutbox($supplierId, $outboxId);
        if ($existing !== null) {
            $this->assertOwnership($existing, $supplierId, $userId);

            return [
                'session_id' => (int) $existing['id'],
                'app_token' => (string) $existing['app_token'],
                'redirect_url' => (string) $existing['state'] === 'awaiting_approval'
                    ? $registration->conceptUrl((string) $existing['concept_id'], (string) $existing['app_token'])
                    : $registration->loginUrl((string) $existing['app_token']),
                'login_guidance' => $registration->loginPolicy->userGuidance(),
                'login_policy_documented' => $registration->loginPolicy->isDocumented(),
                'expires_at' => (string) $existing['expires_at'],
                'resumed' => true,
            ];
        }

        $message = $this->buildMessage($supplierId, $row);

        // ── Brána, která má u brány plné veto ──
        // ISDS obsah příloh nevaliduje vůbec a chyby přijdou až po dnech jako
        // výzva k odstranění vad podle § 74 DŘ. Kontrola se dělá TEĎ, dokud je
        // řádek `ready` a dokud se dá zápis validace provést bez optimistické
        // kolize s pozdějším zabráním.
        $this->runValidationGate($supplierId, $outboxId, $row);

        // V UTC schválně: `started_at` i úklid vypršelých relací se řídí
        // `UTC_TIMESTAMP()`. Lokální čas by v létě posunul platnost o dvě
        // hodiny dopředu a relace by nikdy nevypršela.
        $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . ($registration->conceptTtlSeconds + self::LOGIN_WINDOW_SECONDS) . ' seconds');

        $session = $this->sessions->open([
            'supplier_id' => $supplierId,
            'environment' => $environment,
            'outbox_id' => $outboxId,
            'user_id' => $userId,
            'app_token' => $this->generateAppToken(),
            'payload_sha256' => $message->payloadSha256(),
            'correlation_reference' => (string) $row['correlation_reference'],
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);
        if ($session === null) {
            // Souběžné kliknutí stihlo relaci založit mezi naším dotazem
            // a zápisem. UNIQUE index nás zachytil — druhý koncept nevznikne.
            throw new SubmissionChannelException(
                'isds_gateway_session_conflict',
                'Odeslání tohohle podání už právě probíhá v jiném okně. Dokončete ho tam.',
                409,
            );
        }

        $this->logger->info('ISDS gateway session started', [
            'supplier_id' => $supplierId,
            'outbox_id' => $outboxId,
            'session_id' => (int) $session['id'],
        ] + $registration->toLogContext());

        return [
            'session_id' => (int) $session['id'],
            'app_token' => (string) $session['app_token'],
            'redirect_url' => $registration->loginUrl((string) $session['app_token']),
            'login_guidance' => $registration->loginPolicy->userGuidance(),
            'login_policy_documented' => $registration->loginPolicy->isDocumented(),
            'expires_at' => (string) $session['expires_at'],
            'resumed' => false,
        ];
    }

    /**
     * Zpracuje návrat z ISDS. Volá se pro OBĚ přesměrování — která fáze to je,
     * rozhoduje stav relace, ne parametr z prohlížeče.
     *
     * @return array{
     *   state:string, outbox_id:int, redirect_url:?string,
     *   external_message_id:?string, message:string
     * }
     */
    public function complete(int $supplierId, int $userId, string $appToken, string $sessionId): array
    {
        $session = $this->sessions->findByAppToken($appToken);
        if ($session === null) {
            throw new SubmissionChannelException(
                'isds_gateway_session_unknown',
                'Návrat z datové schránky nepatří k žádnému rozpracovanému odeslání. '
                . 'Spusťte odeslání znovu.',
                404,
            );
        }
        // ⚠️ PŘED jakýmkoli síťovým voláním. Cizí token nesmí spotřebovat
        // sessionId ani vložit koncept.
        $this->assertOwnership($session, $supplierId, $userId);

        $registration = $this->registrations->load((string) $session['environment']);
        $outboxId = (int) $session['outbox_id'];

        return match ((string) $session['state']) {
            'awaiting_login' => $this->pushConcept($session, $registration, $sessionId),
            'awaiting_approval' => $this->recordApproval($session, $registration, $sessionId),
            'approved' => $this->completeApprovedSession($session),
            default => [
                'state' => (string) $session['state'],
                'outbox_id' => $outboxId,
                'redirect_url' => null,
                'external_message_id' => null,
                'message' => $session['error_message'] !== null
                    ? (string) $session['error_message']
                    : 'Odeslání přes datovou schránku už je uzavřené.',
            ],
        };
    }

    /** Uzavře relace, ke kterým se uživatel nevrátil. Volá se z cronu. */
    public function expireStaleSessions(): int
    {
        return $this->sessions->expireStale();
    }

    // ───────────────────────── fáze ─────────────────────────

    /**
     * @param array<string,mixed> $session
     * @return array{state:string,outbox_id:int,redirect_url:?string,external_message_id:?string,message:string}
     */
    private function pushConcept(array $session, IsdsGatewayRegistration $registration, string $sessionId): array
    {
        $supplierId = (int) $session['supplier_id'];
        $outboxId = (int) $session['outbox_id'];
        $id = (int) $session['id'];

        $row = $this->outbox->find($supplierId, $outboxId);
        if ($row === null || (string) $row['dispatch_state'] !== DispatchState::Ready->value) {
            $this->sessions->close(
                $supplierId,
                $id,
                'awaiting_login',
                'failed',
                'isds_gateway_submission_moved',
                'Podání se mezitím změnilo, koncept se do datové schránky nevkládal.',
            );
            throw new SubmissionChannelException(
                'isds_gateway_submission_moved',
                'Podání se mezitím změnilo. Zkontrolujte jeho stav a odeslání případně spusťte znovu.',
                409,
            );
        }

        $message = $this->buildMessage($supplierId, $row);
        // Otisk z okamžiku zahájení. Kdyby se artefakt mezitím změnil, do ISDS
        // by šlo něco jiného, než co uživatel schvaloval v aplikaci.
        if (!hash_equals((string) $session['payload_sha256'], $message->payloadSha256())) {
            $this->sessions->close(
                $supplierId,
                $id,
                'awaiting_login',
                'failed',
                'isds_gateway_payload_changed',
                'Podklad se od zahájení odeslání změnil.',
            );
            throw new SubmissionChannelException(
                'isds_gateway_payload_changed',
                'Podklad se od zahájení odeslání změnil. Zkontrolujte ho a spusťte odeslání znovu.',
                409,
            );
        }

        $credential = $this->client->exchangeSession($registration, $sessionId);

        try {
            $conceptId = $this->client->setConcept($registration, $credential, $message);
        } catch (IsdsTransportTimeout $e) {
            // Koncept mohl v ISDS vzniknout, ale bez schválení uživatelem
            // NIKDY sám neodejde — fronta proto smí zůstat `ready` a podání
            // jde bezpečně spustit znovu. Jediný následek je osiřelý koncept,
            // který uživateli blokuje jeden ze tří slotů, dokud nevyprší.
            $this->sessions->close(
                $supplierId,
                $id,
                'awaiting_login',
                'uncertain',
                $e->errorCode,
                $e->getMessage(),
            );
            $this->client->logout($registration, $credential);

            throw new SubmissionChannelException(
                'isds_gateway_concept_uncertain',
                'Spojení s datovou schránkou se přerušilo při přípravě zprávy. Zpráva NEODEŠLA — '
                . 'schválení jste nepotvrzovali. Zkuste odeslání za chvíli znovu.',
                504,
            );
        } catch (SubmissionChannelException $e) {
            $this->sessions->close($supplierId, $id, 'awaiting_login', 'failed', $e->errorCode, $e->getMessage());
            $this->client->logout($registration, $credential);
            throw $e;
        }

        $updated = $this->sessions->markConceptPushed($supplierId, $id, $conceptId);
        if ($updated === null) {
            // Souběžný callback byl rychlejší. Nevkládáme druhý koncept.
            $current = $this->sessions->find($supplierId, $id);
            throw new SubmissionChannelException(
                'isds_gateway_session_conflict',
                'Tenhle návrat z datové schránky už byl zpracovaný v jiném okně ('
                . (string) ($current['state'] ?? 'neznámý stav') . ').',
                409,
            );
        }

        // `timeLimitedId` je vložením konceptu spotřebované (kap. 3.4), ale
        // úklid stojí jedno volání a uvolní uživateli slot dřív.
        $this->client->logout($registration, $credential);

        return [
            'state' => 'awaiting_approval',
            'outbox_id' => $outboxId,
            'redirect_url' => $registration->conceptUrl($conceptId, (string) $session['app_token']),
            'external_message_id' => null,
            'message' => 'Zpráva je připravená v datové schránce. Zkontrolujte ji a odeslání potvrďte.',
        ];
    }

    /**
     * @param array<string,mixed> $session
     * @return array{state:string,outbox_id:int,redirect_url:?string,external_message_id:?string,message:string}
     */
    private function recordApproval(array $session, IsdsGatewayRegistration $registration, string $sessionId): array
    {
        $supplierId = (int) $session['supplier_id'];
        $outboxId = (int) $session['outbox_id'];
        $id = (int) $session['id'];

        try {
            $credential = $this->client->exchangeSession($registration, $sessionId);
        } catch (IsdsTransportTimeout $e) {
            // Nejtěžší místo celého toku: uživatel schvaloval, ale my nevíme
            // s jakým výsledkem. Zpráva MOHLA odejít, takže fronta nesmí
            // zůstat `ready` — jinak by ji uživatel poslal podruhé.
            $this->markUncertain($supplierId, $outboxId, $id, (int) $session['user_id'], $e->errorCode, $e->getMessage());

            throw new SubmissionChannelException(
                'isds_gateway_outcome_uncertain',
                'Datová schránka neodpověděla a není jisté, jestli zpráva odešla. '
                . 'Podání zůstává rozpracované — NEODESÍLEJTE ho znovu, dokud si to neověříte '
                . 've své datové schránce v odeslaných zprávách.',
                504,
            );
        }

        $this->client->logout($registration, $credential);

        if (!$credential->hasConceptOutcome()) {
            $this->markUncertain(
                $supplierId,
                $outboxId,
                $id,
                (int) $session['user_id'],
                'isds_gateway_outcome_missing',
                'Datová schránka nevrátila výsledek schválení konceptu.',
            );

            throw new SubmissionChannelException(
                'isds_gateway_outcome_missing',
                'Datová schránka nevrátila výsledek schválení a není jisté, jestli zpráva odešla. '
                . 'Podání zůstává rozpracované — ověřte si to v odeslaných zprávách své datové schránky.',
                502,
            );
        }

        if ($credential->isRejectedByUser()) {
            // Kód 2305. Prokazatelně nic neodešlo — fronta zůstává `ready`
            // a podání jde bez následků spustit znovu.
            $this->sessions->close(
                $supplierId,
                $id,
                'awaiting_approval',
                'rejected',
                'isds_gateway_rejected_by_user',
                'Odeslání jste v datové schránce zamítli.',
                $credential->conceptStatusCode,
                $credential->conceptStatusMessage,
            );

            return [
                'state' => 'rejected',
                'outbox_id' => $outboxId,
                'redirect_url' => null,
                'external_message_id' => null,
                'message' => 'Odeslání jste v datové schránce zamítli. Podání zůstalo připravené ve frontě.',
            ];
        }

        if (!$credential->isDispatched()) {
            $this->sessions->close(
                $supplierId,
                $id,
                'awaiting_approval',
                'failed',
                'isds_gateway_dispatch_failed',
                'Datová schránka zprávu neodeslala: ' . ($credential->conceptStatusMessage ?? 'bez uvedení důvodu'),
                $credential->conceptStatusCode,
                $credential->conceptStatusMessage,
            );

            return [
                'state' => 'failed',
                'outbox_id' => $outboxId,
                'redirect_url' => null,
                'external_message_id' => null,
                'message' => 'Datová schránka zprávu neodeslala ('
                    . ($credential->conceptStatusMessage ?? 'bez uvedení důvodu')
                    . '). Podání zůstalo připravené ve frontě.',
            ];
        }

        $messageId = (string) $credential->conceptDmId;

        // ── Brána idempotence relace ──
        // Přechod uspěje právě jednou. Druhý (souběžný i pozdější) návrat
        // dostane null a odejde s aktuálním stavem — bez druhého zápisu.
        try {
            $closed = $this->transactional(function () use ($supplierId, $outboxId, $id, $session, $messageId, $credential): ?array {
                $approved = $this->sessions->markApproved(
                    $supplierId,
                    $id,
                    $messageId,
                    (string) $credential->conceptStatusCode,
                    $credential->conceptStatusMessage,
                );
                if ($approved === null) {
                    $approved = $this->sessions->find($supplierId, $id);
                    if ($approved === null || (string) $approved['state'] !== 'approved') {
                        return null;
                    }
                    if (!hash_equals((string) ($approved['concept_dm_id'] ?? ''), $messageId)) {
                        throw new \RuntimeException('Relace ISDS obsahuje jiné dmID než potvrzený callback.');
                    }
                }

                $this->recordDispatch($supplierId, $outboxId, (int) $session['user_id'], $messageId);

                return $approved;
            });
        } catch (\Throwable $e) {
            try {
                $this->sessions->markApproved(
                    $supplierId,
                    $id,
                    $messageId,
                    (string) $credential->conceptStatusCode,
                    $credential->conceptStatusMessage,
                );
            } catch (\Throwable $preserveError) {
                $this->logger->critical('ISDS gateway dmID could not be preserved after projection failure', [
                    'supplier_id' => $supplierId,
                    'outbox_id' => $outboxId,
                    'session_id' => $id,
                    'error' => $preserveError->getMessage(),
                ]);
            }

            throw new SubmissionChannelException(
                'isds_gateway_projection_failed',
                'Datová schránka potvrdila odeslání zprávy, ale její stav se nepodařilo zapsat do fronty. '
                . 'Podání znovu neodesílejte; obnovte stránku a pokud chyba trvá, kontaktujte správce.',
                500,
                $e,
            );
        }
        if ($closed === null) {
            $current = $this->sessions->find($supplierId, $id);

            return [
                'state' => (string) ($current['state'] ?? 'approved'),
                'outbox_id' => $outboxId,
                'redirect_url' => null,
                'external_message_id' => isset($current['concept_dm_id'])
                    ? (string) $current['concept_dm_id']
                    : null,
                'message' => 'Odeslání už bylo zaznamenané.',
            ];
        }

        $this->logger->info('ISDS gateway dispatch recorded', [
            'supplier_id' => $supplierId,
            'outbox_id' => $outboxId,
            'session_id' => $id,
        ] + $credential->toLogContext());

        return [
            'state' => 'approved',
            'outbox_id' => $outboxId,
            'redirect_url' => null,
            'external_message_id' => $messageId,
            'message' => 'Zpráva odešla z vaší datové schránky. Doručenku k ní nahrajte ručně — '
                . 'odesílací brána ji stáhnout neumí.',
        ];
    }

    // ───────────────────────── zápis do fronty ─────────────────────────

    /**
     * @param array<string,mixed> $session
     * @return array{state:string,outbox_id:int,redirect_url:null,external_message_id:string,message:string}
     */
    private function completeApprovedSession(array $session): array
    {
        $supplierId = (int) $session['supplier_id'];
        $outboxId = (int) $session['outbox_id'];
        $messageId = trim((string) ($session['concept_dm_id'] ?? ''));
        if ($messageId === '') {
            throw new SubmissionChannelException(
                'isds_gateway_approved_without_message_id',
                'Datová schránka označila relaci jako odeslanou, ale chybí identifikátor zprávy. '
                . 'Podání znovu neodesílejte a kontaktujte správce.',
                500,
            );
        }

        try {
            $this->transactional(fn (): null => $this->recordDispatch(
                $supplierId,
                $outboxId,
                (int) $session['user_id'],
                $messageId,
            ));
        } catch (SubmissionChannelException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new SubmissionChannelException(
                'isds_gateway_projection_failed',
                'Zpráva už byla odeslána datovou schránkou, ale její stav se nepodařilo dokončit ve frontě. '
                . 'Podání znovu neodesílejte; obnovte stránku a pokud chyba trvá, kontaktujte správce.',
                500,
                $e,
            );
        }

        return [
            'state' => 'approved',
            'outbox_id' => $outboxId,
            'redirect_url' => null,
            'external_message_id' => $messageId,
            'message' => 'Podání už bylo odesláno datovou schránkou.',
        ];
    }

    /**
     * Zapíše odeslání do fronty a do ledgeru pokusů.
     *
     * Řádek se tady zabírá poprvé (`ready` → `sending`) a hned uzavírá na
     * `sent`. Kdyby zabrání selhalo, znamená to, že podání už někdo jiný
     * posunul — v tom případě se nic nepřepisuje. `external_message_id` je
     * navíc v DB triggeru jednorázové přiřazení, takže ani chyba v aplikaci
     * nemůže přepsat jediný důkaz o tom, že zpráva u příjemce je.
     */
    private function recordDispatch(int $supplierId, int $outboxId, int $userId, string $messageId): void
    {
        $current = $this->outbox->find($supplierId, $outboxId);
        if ($current === null) {
            throw new SubmissionChannelException('submission_not_found', 'Podání ve frontě není.', 404);
        }
        if ((string) $current['dispatch_state'] === DispatchState::Sent->value
            && (string) $current['dispatch_mode'] === 'gateway'
            && hash_equals((string) ($current['external_message_id'] ?? ''), $messageId)
        ) {
            return;
        }
        if ((string) $current['dispatch_state'] !== DispatchState::Ready->value) {
            throw new SubmissionChannelException(
                'isds_gateway_dispatch_conflict',
                'Zpráva byla v datové schránce odeslána, ale podání se mezitím ve frontě změnilo. '
                . 'Podání znovu neodesílejte a kontaktujte správce.',
                409,
            );
        }

        $claimed = $this->outbox->claimForGatewaySending($supplierId, $outboxId, $userId);
        if ($claimed === null) {
            throw new SubmissionChannelException(
                'isds_gateway_dispatch_conflict',
                'Zpráva byla v datové schránce odeslána, ale podání právě změnil jiný proces. '
                . 'Podání znovu neodesílejte a obnovte stránku.',
                409,
            );
        }

        $attempt = $this->attempts->open(
            $supplierId,
            $outboxId,
            self::CHANNEL,
            $this->attempts->nextAttemptNo($supplierId, $outboxId),
            (string) $claimed['artifact_sha256'],
            (string) $claimed['correlation_reference'],
            $userId,
        );

        $this->outbox->markSent($supplierId, $outboxId, $messageId, (int) $claimed['row_version']);
        $this->attempts->markSent($supplierId, (int) $attempt['id'], $messageId, (int) $attempt['row_version']);
        $this->payrollProjection?->project(
            $supplierId,
            (string) $claimed['artifact_kind'],
            (int) $claimed['artifact_id'],
            $messageId,
        );
    }

    /**
     * @template T
     * @param \Closure():T $operation
     * @return T
     */
    private function transactional(\Closure $operation): mixed
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        $savepoint = 'isds_gateway_dispatch';

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            $result = $operation();
            if ($ownsTransaction) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            throw $e;
        }
    }

    /**
     * Nevědomost o osudu zprávy. Fronta se zabere a označí `send_uncertain`,
     * aby ji nešlo odeslat podruhé.
     */
    private function markUncertain(
        int $supplierId,
        int $outboxId,
        int $sessionId,
        int $userId,
        string $errorCode,
        string $errorMessage,
    ): void {
        $this->sessions->close($supplierId, $sessionId, 'awaiting_approval', 'uncertain', $errorCode, $errorMessage);

        $claimed = $this->outbox->claimForGatewaySending($supplierId, $outboxId, $userId);
        if ($claimed === null) {
            return;
        }
        $this->outbox->markUncertain($supplierId, $outboxId, $errorCode, $errorMessage, (int) $claimed['row_version']);
    }

    // ───────────────────────── pomocné ─────────────────────────

    /** @param array<string,mixed> $row */
    private function buildMessage(int $supplierId, array $row): IsdsConceptMessage
    {
        $artifact = $this->artifacts->resolve(
            $supplierId,
            (string) $row['artifact_kind'],
            (int) $row['artifact_id'],
        );
        if ($artifact === null) {
            throw new SubmissionChannelException(
                'artifact_not_found',
                'Podklad k odeslání se nepodařilo najít. Vygenerujte ho znovu.',
                404,
            );
        }
        if (!hash_equals((string) $row['artifact_sha256'], hash('sha256', $artifact['bytes']))) {
            throw new SubmissionChannelException(
                'artifact_changed',
                'Podklad se od zařazení do fronty změnil. Zkontrolujte ho a zařaďte podání znovu.',
                409,
            );
        }

        $message = IsdsConceptMessage::fromOutboundSubmission(new OutboundSubmission(
            outboxId: (int) $row['id'],
            supplierId: $supplierId,
            environment: (string) $row['environment'],
            agendaCode: (string) $row['agenda_code'],
            subject: (string) $row['subject'],
            recipientBoxId: $row['recipient_box_id'] !== null ? (string) $row['recipient_box_id'] : null,
            artifactFilename: (string) $row['artifact_filename'],
            artifactMimeType: $artifact['mime'],
            artifactBytes: $artifact['bytes'],
            artifactSha256: (string) $row['artifact_sha256'],
            correlationReference: (string) $row['correlation_reference'],
        ));
        $message->assertValid();

        return $message;
    }

    /** @param array<string,mixed> $row */
    private function runValidationGate(int $supplierId, int $outboxId, array $row): void
    {
        $artifact = $this->artifacts->resolve(
            $supplierId,
            (string) $row['artifact_kind'],
            (int) $row['artifact_id'],
        );
        if ($artifact === null) {
            throw new SubmissionChannelException(
                'artifact_not_found',
                'Podklad k odeslání se nepodařilo najít. Vygenerujte ho znovu.',
                404,
            );
        }

        $validation = $this->validator->validateArtifact((string) $row['agenda_code'], $artifact);
        $this->outbox->recordValidation($supplierId, $outboxId, $validation['status'], (int) $row['row_version']);

        if ($validation['status'] === 'failed') {
            throw new SubmissionChannelException(
                'artifact_invalid',
                'Podklad neprošel kontrolou proti XSD schématu, takže by ho úřad vrátil jako vadné podání: '
                . implode(' ', array_slice($validation['errors'], 0, 3)),
                422,
            );
        }
    }

    /**
     * Jediné místo, kde se rozhoduje, kdo smí s relací pracovat.
     *
     * `appToken` sám o sobě NEAUTORIZUJE NIC — je to hodnota z přesměrování
     * v prohlížeči. Musí sedět tenant i uživatel, který odeslání zahájil.
     * Uživatel je ve výčtu proto, že schválení odeslání datové zprávy je právní
     * úkon: nesmí ho za kolegu dokončit někdo jiný, i kdyby byl ve stejné firmě.
     *
     * @param array<string,mixed> $session
     */
    private function assertOwnership(array $session, int $supplierId, int $userId): void
    {
        if ((int) $session['supplier_id'] !== $supplierId) {
            $this->logger->error('ISDS gateway session accessed across tenants — refused', [
                'session_supplier_id' => (int) $session['supplier_id'],
                'request_supplier_id' => $supplierId,
                'session_id' => (int) $session['id'],
            ]);

            throw new SubmissionChannelException(
                'isds_gateway_session_foreign',
                'Tohle odeslání patří jiné firmě.',
                403,
            );
        }
        if ((int) $session['user_id'] !== $userId) {
            $this->logger->warning('ISDS gateway session resumed by a different user — refused', [
                'session_id' => (int) $session['id'],
                'supplier_id' => $supplierId,
            ]);

            throw new SubmissionChannelException(
                'isds_gateway_session_other_user',
                'Odeslání zahájil jiný uživatel. Dokončit ho musí tentýž člověk, který ho spustil.',
                403,
            );
        }
    }

    /**
     * `appToken` — náš vlastní identifikátor, max 20 ČÍSLIC (kap. 2.6 bod 1).
     *
     * 18 číslic z `random_bytes` je ~60 bitů entropie. Není to tajemství, na
     * kterém by stála bezpečnost — o tu se stará {@see assertOwnership()} —
     * ale nesmí jít uhodnout, aby se nedalo trefit do cizí rozpracované relace
     * a vyrobit tak zbytečné odmítnutí.
     */
    private function generateAppToken(): string
    {
        $digits = '';
        while (strlen($digits) < 18) {
            $digits .= (string) random_int(0, 9);
        }

        // Vedoucí nula by prošla, ale sloupec je řetězec a porovnává se přesně;
        // první číslici tedy držíme nenulovou, ať se hodnota nedá „zkrátit".
        return (string) random_int(1, 9) . substr($digits, 0, 17);
    }
}
