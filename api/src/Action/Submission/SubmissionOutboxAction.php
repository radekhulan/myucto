<?php

declare(strict_types=1);

namespace MyInvoice\Action\Submission;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\ChannelCredentials;
use MyInvoice\Service\Submission\Channel\Isds\MobileKeyIsdsAuthenticator;
use MyInvoice\Service\Submission\Channel\SensitiveValue;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\IsdsMobileCredentialService;
use MyInvoice\Service\Submission\SubmissionCredentialService;
use MyInvoice\Service\Submission\SubmissionOutboxService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Fronta odchozích podání.
 *
 * Odesílat smí jen přihlášený člověk z webového rozhraní — proto stejný
 * `forbidden_via_token` guard jako u trezoru. Automat (cron, import) do fronty
 * zařazuje přes službu, ne přes tuhle akci, a odeslat nemůže vůbec.
 */
final class SubmissionOutboxAction
{
    public function __construct(
        private readonly SubmissionOutboxService $outbox,
        private readonly SubmissionCredentialService $credentials,
        private readonly ActivityLogger $logger,
        private readonly MobileKeyIsdsAuthenticator $mobileKey,
        private readonly IsdsMobileCredentialService $mobileCredentials,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }
        $params = $request->getQueryParams();
        $environment = (string) ($params['environment'] ?? 'production');

        try {
            $items = $this->outbox->listForSupplier(SupplierGuard::currentId($request), $environment);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return Json::ok($response, ['items' => $items]);
    }

    /** @param array<string,string> $args */
    public function attempts(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }

        return Json::ok($response, [
            'items' => $this->outbox->attemptsFor(SupplierGuard::currentId($request), (int) ($args['id'] ?? 0)),
        ]);
    }

    /**
     * Stažení souboru, který se tímhle podáním odesílá.
     *
     * Existuje kvůli ručnímu odeslání datovou schránkou: návod říká „přiložte
     * soubor", takže ho aplikace musí umět vydat. Bez toho by ho účetní
     * hledala v dokumentech a mohla přiložit jiný.
     *
     * Kontrolní suma se porovnává se zmrazenou hodnotou z fronty. Když se
     * artefakt mezitím přegeneroval, stažení se ODMÍTNE — nabídnout soubor,
     * který neodpovídá spisové značce, je horší než nenabídnout nic.
     *
     * @param array<string,string> $args
     */
    public function artifact(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }

        try {
            $artifact = $this->outbox->artifactFor(
                SupplierGuard::currentId($request),
                (int) ($args['id'] ?? 0),
            );
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        if (!hash_equals($artifact['claimed_sha256'], $artifact['sha256'])) {
            return Json::error(
                $response,
                'artifact_changed',
                'Soubor se od zařazení do fronty změnil, proto ho nenabízíme ke stažení. '
                . 'Zrušte podání a zařaďte ho znovu z aktuálního podkladu.',
                409,
            );
        }

        $response->getBody()->write($artifact['bytes']);

        return $response
            ->withHeader('Content-Type', $artifact['mime'])
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . $artifact['filename'] . '"',
            )
            ->withHeader('Content-Length', (string) strlen($artifact['bytes']))
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    }

    public function enqueue(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $supplierId = SupplierGuard::currentId($request);

        try {
            $result = $this->outbox->enqueue(
                $supplierId,
                (string) ($body['environment'] ?? 'production'),
                (string) ($body['channel'] ?? 'isds'),
                (string) ($body['agenda_code'] ?? ''),
                (string) ($body['artifact_kind'] ?? ''),
                (int) ($body['artifact_id'] ?? 0),
                isset($body['recipient_id']) ? (int) $body['recipient_id'] : null,
                isset($body['subject']) ? (string) $body['subject'] : null,
                $this->userId($request),
            );
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            return Json::error($response, 'enqueue_conflict', $e->getMessage(), 409);
        }

        return Json::ok($response, $result);
    }

    /**
     * Potvrzení a odeslání člověkem.
     *
     * Opakované volání nevytvoří druhé podání — vrátí `dispatched: false`
     * a aktuální stav. Idempotenci drží podmínka `dispatch_state = 'ready'`
     * v UPDATE, ne kontrola v aplikaci.
     *
     * @param array<string,string> $args
     */
    public function confirm(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $userId = $this->userId($request);
        $id = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        $environment = (string) ($body['environment'] ?? 'production');

        try {
            $context = $this->credentials->unlock($supplierId, $environment);
            $result = $this->outbox->confirmAndSend($supplierId, $id, $userId, $context);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            return Json::error($response, 'submission_conflict', $e->getMessage(), 409);
        }

        if ($result['dispatched']) {
            $this->logger->log('submission_outbox_sent', $userId, 'submission_outbox', $id, null, null, null, $supplierId);
        }

        return Json::ok($response, $result);
    }

    /**
     * Zahájí přihlášení Mobilním klíčem pro ODESLÁNÍ konkrétního podání.
     *
     * Proč vlastní cesta, a ne `confirm()` s uloženým certifikátem: přímý
     * transport odesílá výhradně v relaci, kterou člověk právě potvrdil
     * ({@see \MyInvoice\Service\Submission\Channel\Isds\DirectIsdsInboxTransport::hasConfirmedSession()}).
     * Uložený certifikát ani heslo takovou relaci nepředstavují — u nich by
     * u odeslání nestál nikdo — takže `SessionAwareIsdsTransport` sáhne po
     * náhradní cestě a podání skončí větou „odešlete si to sami".
     *
     * @param array<string,string> $args
     */
    /**
     * Odeslání jménem a heslem k datové schránce.
     *
     * Why: čtení nabízelo čtyři způsoby přihlášení, odesílání jediný. Nebylo
     * to omezení ISDS — jméno a heslo relaci naváže stejně jako Mobilní klíč,
     * jen se autentizuje každý požadavek zvlášť, takže nevzniká cookie, na
     * kterou se dřív ptala DirectIsdsInboxTransport::hasConfirmedSession().
     * Účetní, která má u schránky jen heslo, tak zprávu připravila a odeslat
     * ji nemohla.
     *
     * Heslo se NEUKLÁDÁ: projde requestem do ISDS a zahodí se s ním.
     *
     * @param array{id:string} $args
     */
    public function passwordSend(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'submission_not_found', 'Podání neexistuje.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $environment = (string) ($body['environment'] ?? 'production');
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if (!in_array($environment, ['production', 'test'], true)) {
            return Json::error($response, 'invalid_environment', 'Neznámé prostředí.', 400);
        }
        if ($username === '' || strlen($username) > 128 || preg_match('/[\x00-\x20:\x7f]/', $username) === 1) {
            return Json::error($response, 'isds_username_invalid', 'Vyplňte platné uživatelské jméno k datové schránce.', 400);
        }
        if ($password === '' || strlen($password) > 512 || preg_match('/[\x00-\x1f\x7f]/', $password) === 1) {
            return Json::error($response, 'isds_password_invalid', 'Vyplňte heslo k datové schránce.', 400);
        }

        $supplierId = SupplierGuard::currentId($request);
        $userId = $this->userId($request);
        $context = new ChannelContext(
            $supplierId,
            $environment,
            new ChannelCredentials(
                boxId: '',
                authMode: 'password',
                username: SensitiveValue::fromProducer(static fn (): string => $username),
                password: SensitiveValue::fromProducer(static fn (): string => $password),
            ),
        );

        return $this->sendWithContext($response, $supplierId, $id, $userId, $context);
    }

    /**
     * Odeslání systémovým certifikátem firmy.
     *
     * Za certifikátem nestojí člověk, proto se odeslání potvrzuje v aplikaci —
     * stejně jako u EPO. Bez toho potvrzení by zprávu odeslal kdokoli, kdo se
     * dostane k relaci; s ním je to vědomý krok, který jde doložit.
     *
     * @param array{id:string} $args
     */
    public function certificateSend(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'submission_not_found', 'Podání neexistuje.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        if (($body['authorized'] ?? null) !== true) {
            return Json::error(
                $response,
                'isds_certificate_send_unauthorized',
                'Odeslání systémovým certifikátem potvrďte — za certifikátem nestojí člověk.',
                400,
            );
        }
        $environment = (string) ($body['environment'] ?? 'production');
        if (!in_array($environment, ['production', 'test'], true)) {
            return Json::error($response, 'invalid_environment', 'Neznámé prostředí.', 400);
        }

        $supplierId = SupplierGuard::currentId($request);
        $userId = $this->userId($request);
        try {
            $context = $this->credentials->unlock($supplierId, $environment);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return $this->sendWithContext($response, $supplierId, $id, $userId, $context);
    }

    /** Společný závěr obou cest: odeslat, zalogovat, vrátit stav. */
    private function sendWithContext(
        Response $response,
        int $supplierId,
        int $id,
        int $userId,
        ChannelContext $context,
    ): Response {
        try {
            $result = $this->outbox->confirmAndSend($supplierId, $id, $userId, $context);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            return Json::error($response, 'submission_conflict', $e->getMessage(), 409);
        }
        if ($result['dispatched']) {
            $this->logger->log('submission_outbox_sent', $userId, 'submission_outbox', $id, null, null, null, $supplierId);
        }

        return Json::ok($response, ['result' => $result]);
    }

    public function mobileKeyStart(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        if ((int) ($args['id'] ?? 0) <= 0) {
            return Json::error($response, 'submission_not_found', 'Podání neexistuje.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $supplierId = SupplierGuard::currentId($request);
            $userId = $this->userId($request);
            $environment = (string) ($body['environment'] ?? 'production');
            $result = ($body['use_saved_credentials'] ?? false) === true
                ? $this->mobileKey->startWithCredentials(
                    $supplierId,
                    $userId,
                    $environment,
                    $this->mobileCredentials->unlock($supplierId, $userId, $environment),
                )
                : $this->mobileKey->start(
                    $supplierId,
                    $userId,
                    $environment,
                    (string) ($body['username'] ?? ''),
                    (string) ($body['communication_code'] ?? ''),
                );
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return Json::ok($response, $result);
    }

    /**
     * Ověří potvrzení relace a hned v ní podání odešle.
     *
     * Odeslání musí proběhnout TÍMTO voláním, ne dalším: `continue()` průchod
     * při potvrzení spotřebuje, takže relaci už podruhé vyzvednout nejde.
     * Rozdělit to na „zjisti stav" a „teď odešli" by znamenalo držet session
     * cookie někde mezi požadavky — a to je přesně to, co tenhle model
     * (krátká relace potvrzená člověkem) nechce.
     *
     * @param array<string,string> $args
     */
    public function mobileKeyConfirm(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $userId = $this->userId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'submission_not_found', 'Podání neexistuje.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $flowToken = (string) ($body['flow_token'] ?? '');
        if ($flowToken === '' || strlen($flowToken) > 8192) {
            return Json::error($response, 'isds_mobile_flow_invalid', 'Přihlášení Mobilním klíčem není platné. Spusťte ho znovu.', 400);
        }

        try {
            $result = $this->mobileKey->continue(
                $flowToken,
                $supplierId,
                $userId,
                (string) ($body['environment'] ?? 'production'),
            );
            $context = $result['context'];
            if ($context === null) {
                // Čeká se na potvrzení v mobilu — podání se nedotýkáme.
                return Json::ok($response, [
                    'state' => $result['state'],
                    'description' => $result['description'],
                    'result' => null,
                ]);
            }
            $sendResult = $this->outbox->confirmAndSend($supplierId, $id, $userId, $context);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            return Json::error($response, 'submission_conflict', $e->getMessage(), 409);
        }

        if ($sendResult['dispatched']) {
            $this->logger->log('submission_outbox_sent', $userId, 'submission_outbox', $id, null, null, null, $supplierId);
        }

        return Json::ok($response, [
            'state' => $result['state'],
            'description' => $result['description'],
            'result' => $sendResult,
        ]);
    }

    /**
     * Zahájí přihlášení Mobilním klíčem pro HROMADNÉ odeslání víc podání
     * v JEDNÉ relaci — viz {@see mobileKeyConfirmBatch()}.
     *
     * Cesta je BEZ `{id}` schválně: přihlášení k ISDS není vázané na
     * konkrétní podání (`mobileKeyStart()` výš `id` taky nikam nepoužívá,
     * jen ho ověří jako hlídku), takže dávka nemá důvod jedno předstírat.
     */
    public function mobileKeyStartBatch(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $supplierId = SupplierGuard::currentId($request);
            $userId = $this->userId($request);
            $environment = (string) ($body['environment'] ?? 'production');
            $result = ($body['use_saved_credentials'] ?? false) === true
                ? $this->mobileKey->startWithCredentials(
                    $supplierId,
                    $userId,
                    $environment,
                    $this->mobileCredentials->unlock($supplierId, $userId, $environment),
                )
                : $this->mobileKey->start(
                    $supplierId,
                    $userId,
                    $environment,
                    (string) ($body['username'] ?? ''),
                    (string) ($body['communication_code'] ?? ''),
                );
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return Json::ok($response, $result);
    }

    /**
     * Ověří potvrzení relace a v NÍ odešle VÍC vybraných podání najednou.
     *
     * ── Proč dávka, a ne opakované volání {@see mobileKeyConfirm()} ─────────
     * Relaci vydá `continue()` jen JEDNOU — druhé volání se stejným tokenem by
     * narazilo na spotřebovaný flow. Účetní má ale měsíčně až osm podání
     * (ČSSZ + sedm zdravotních pojišťoven) a osm potvrzení v mobilu je
     * nepoužitelné. `outbox_ids` proto nese všechno, co se má v TÉTO relaci
     * odeslat, a {@see SubmissionOutboxService::confirmAndSendBatch()} to
     * pošle po jednom se svým vlastním výsledkem — pád jednoho podání
     * nezastaví ostatní.
     *
     * Relace se explicitně ODHLAŠUJE (`logout()`) v `finally`, i když
     * jednotlivé {@see mobileKeyConfirm()} to dnes nedělá (spoléhá na
     * 30minutové vypršení nečinností): dávka během jedné relace provede víc
     * citlivých operací za sebou, takže nemá důvod žít o nic déle, než musí.
     *
     * Limit počtu položek je bezpečnostní pojistka, ne provozní překážka —
     * měsíční dávka má v praxi nejvýš jednotky položek.
     */
    public function mobileKeyConfirmBatch(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $userId = $this->userId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $flowToken = (string) ($body['flow_token'] ?? '');
        if ($flowToken === '' || strlen($flowToken) > 8192) {
            return Json::error($response, 'isds_mobile_flow_invalid', 'Přihlášení Mobilním klíčem není platné. Spusťte ho znovu.', 400);
        }
        $ids = [];
        foreach ((array) ($body['outbox_ids'] ?? []) as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return Json::error($response, 'submission_not_found', 'Nevybrali jste žádné podání k odeslání.', 400);
        }
        if (count($ids) > 50) {
            return Json::error($response, 'validation_failed', 'Najednou lze odeslat nejvýš 50 podání.', 422);
        }

        try {
            $result = $this->mobileKey->continue(
                $flowToken,
                $supplierId,
                $userId,
                (string) ($body['environment'] ?? 'production'),
            );
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        $context = $result['context'];
        if ($context === null) {
            // Čeká se na potvrzení v mobilu — žádné podání se nedotýkáme.
            return Json::ok($response, [
                'state' => $result['state'],
                'description' => $result['description'],
                'results' => null,
            ]);
        }

        try {
            $results = $this->outbox->confirmAndSendBatch($supplierId, $ids, $userId, $context);
        } finally {
            $this->mobileKey->logout($context);
        }

        foreach ($results as $item) {
            if ($item['dispatched']) {
                $this->logger->log('submission_outbox_sent', $userId, 'submission_outbox', $item['id'], null, null, null, $supplierId);
            }
        }

        return Json::ok($response, [
            'state' => $result['state'],
            'description' => $result['description'],
            'results' => $results,
        ]);
    }

    /**
     * Dořešení podání, u kterého se odeslání přerušilo.
     *
     * @param array<string,string> $args
     */
    public function resolve(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $context = $this->credentials->unlock($supplierId, (string) ($body['environment'] ?? 'production'));
            $row = $this->outbox->resolveUncertain($supplierId, (int) ($args['id'] ?? 0), $context);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            return Json::error($response, 'submission_conflict', $e->getMessage(), 409);
        }

        return Json::ok($response, $row);
    }

    /** @param array<string,string> $args */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        try {
            $row = $this->outbox->cancel(SupplierGuard::currentId($request), (int) ($args['id'] ?? 0));
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            return Json::error($response, 'submission_conflict', $e->getMessage(), 409);
        }

        return Json::ok($response, $row);
    }

    /**
     * Trvale smaže zrušenou odchozí zprávu, která nikdy neopustila aplikaci.
     *
     * ── Proč to vůbec jde ───────────────────────────────────────────────────
     * Zrušená zpráva, která se nikam neodeslala, nedokládá nic. Zůstávala ale
     * ve výpisu navždy jako řádek se štítkem „Zrušeno" a jediným tlačítkem
     * „Historie pokusů" — tedy jako trvalý zmatek u podání, které pořád čeká
     * na odeslání.
     *
     * ── Co se maže ──────────────────────────────────────────────────────────
     * Odchozí ZPRÁVA, ne podání. Povinnost tím zůstává nesplněná, přesně jako
     * po zrušení.
     *
     * Auditní stopa nese všechno, co bylo v řádku (agenda, období, spisová
     * značka), protože po smazání se to nikde jinde nedočte.
     *
     * @param array<string,string> $args
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $userId = $this->userId($request);
        $id = (int) ($args['id'] ?? 0);

        try {
            $row = $this->outbox->delete($supplierId, $id);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            return Json::error($response, 'submission_conflict', $e->getMessage(), 409);
        }

        $this->logger->log(
            'submission_outbox_deleted',
            $userId,
            'submission_outbox',
            $id,
            [
                'agenda_code' => $row['agenda_code'],
                'subject' => $row['subject'],
                'environment' => $row['environment'],
                'channel' => $row['channel'],
                'correlation_reference' => $row['correlation_reference'],
                'artifact_kind' => $row['artifact_kind'],
                'artifact_id' => $row['artifact_id'],
                'artifact_filename' => $row['artifact_filename'],
                'recipient_box_id' => $row['recipient_box_id'],
                'dispatch_state' => $row['dispatch_state'],
                'created_at' => $row['created_at'],
            ],
            null,
            null,
            $supplierId,
        );

        return Json::ok($response, ['deleted' => true]);
    }

    private function guard(Request $request, Response $response, AccessLevel $level): ?Response
    {
        if (!RequestAuthorization::allows($request, 'settings.signing', $level)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění.', 403);
        }
        if ($this->userId($request) <= 0) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'forbidden_via_token',
                'Podání se odesílá jen z webového rozhraní.',
                403,
            );
        }

        return null;
    }

    private function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);

        return (int) ($user['id'] ?? 0);
    }
}
