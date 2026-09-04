<?php

declare(strict_types=1);

namespace MyInvoice\Action\Submission;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Document\DocumentException;
use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\Isds\DirectIsdsInboxTransport;
use MyInvoice\Service\Submission\Channel\Isds\MobileKeyIsdsAuthenticator;
use MyInvoice\Service\Submission\Channel\Isds\SmsIsdsAuthenticator;
use MyInvoice\Service\Submission\Channel\ChannelCredentials;
use MyInvoice\Service\Submission\Channel\SensitiveValue;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\DeliveryReceiptService;
use MyInvoice\Service\Submission\SubmissionCredentialService;
use MyInvoice\Service\Submission\SubmissionOutboxService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Doručenky z datové schránky nahrané uživatelem.
 *
 * Uzavírá cestu, kterou dnes aplikace umí až k předposlednímu kroku: podklad
 * připraví, příjemce zná, spisovou značku razítkuje — a pak uživateli řekne,
 * ať zprávu odešle ze své datové schránky sám. Tenhle Action bere výsledek
 * (doručenku) zpátky dovnitř a zaváže ho k podání.
 *
 * ── Proč se chyby nespojují do jedné ────────────────────────────────────────
 * Uživatel sem bude nahrávat i špatné soubory: PDF místo ZFO, cizí zprávu,
 * poškozený download. `DocumentException` nese vlastní kód i větu, takže se
 * ven dostane konkrétní důvod, ne „nepodařilo se". Prázdná odpověď u nahraného
 * souboru je nejhorší možná — uživatel neví, jestli má opravit sebe, nebo čekat.
 */
final class SubmissionReceiptAction
{
    /** Doručenka je textová obálka s přílohami; 40 MB je nad rámec toho, co ISDS vydá. */
    private const MAX_UPLOAD_BYTES = 40 * 1024 * 1024;

    public function __construct(
        private readonly DeliveryReceiptService $receipts,
        private readonly SubmissionOutboxService $outbox,
        private readonly SubmissionCredentialService $credentials,
        private readonly DirectIsdsInboxTransport $transport,
        private readonly MobileKeyIsdsAuthenticator $mobileKey,
        private readonly SmsIsdsAuthenticator $sms,
    ) {}

    /**
     * POST /api/submissions/receipts — nahrání doručenky bez určeného podání.
     * POST /api/submissions/outbox/{id}/receipt — nahrání přímo u podání.
     *
     * @param array<string,string> $args
     */
    public function upload(Request $request, Response $response, array $args = []): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId <= 0) {
            return Json::error($response, 'no_supplier', 'Chybí kontext firmy.', 400);
        }
        $userId = $this->userId($request);

        $body = (array) ($request->getParsedBody() ?? []);
        $environment = (string) ($body['environment'] ?? 'production');
        $outboxId = isset($args['id']) ? (int) $args['id'] : null;
        if ($outboxId === null && isset($body['outbox_id']) && (int) $body['outbox_id'] > 0) {
            $outboxId = (int) $body['outbox_id'];
        }

        $file = $this->singleFile($request);
        if ($file === null) {
            return Json::error(
                $response,
                'no_file',
                'Nebyl odeslán žádný soubor. Vyberte doručenku (.zfo), kterou jste stáhli z datové schránky.',
                400,
            );
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'upload_error', 'Soubor se nepodařilo přenést. Zkuste to znovu.', 400);
        }
        $size = $file->getSize();
        if ($size !== null && $size > self::MAX_UPLOAD_BYTES) {
            return Json::error($response, 'receipt_too_large', 'Soubor je příliš velký na doručenku.', 413);
        }

        try {
            $bytes = (string) $file->getStream()->getContents();
        } catch (\Throwable) {
            return Json::error($response, 'upload_unreadable', 'Nahraný soubor se nepodařilo přečíst.', 400);
        }

        try {
            $result = $this->receipts->upload(
                $supplierId,
                $environment,
                $bytes,
                (string) $file->getClientFilename(),
                $userId,
                $outboxId,
            );
        } catch (DocumentException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            return Json::error($response, 'receipt_conflict', $e->getMessage(), 409);
        }

        return Json::ok($response, $result);
    }

    /** GET /api/submissions/receipts/unmatched — co leží v „nezařazeno". */
    public function unmatched(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }
        $params = $request->getQueryParams();

        try {
            $items = $this->receipts->listUnmatched(
                SupplierGuard::currentId($request),
                (string) ($params['environment'] ?? 'production'),
            );
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return Json::ok($response, ['items' => $items]);
    }

    /**
     * GET /api/submissions/receipts/{id}/candidates — ke kterým podáním by
     * nespárovaná doručenka mohla patřit.
     *
     * @param array<string,string> $args
     */
    public function candidates(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }

        try {
            $items = $this->receipts->candidatesFor(
                SupplierGuard::currentId($request),
                (int) ($args['id'] ?? 0),
            );
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return Json::ok($response, ['items' => $items]);
    }

    /**
     * POST /api/submissions/receipts/{id}/match — člověk potvrdil vazbu.
     *
     * @param array<string,string> $args
     */
    public function match(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $outboxId = (int) ($body['outbox_id'] ?? 0);
        if ($outboxId <= 0) {
            return Json::error($response, 'outbox_required', 'Vyberte podání, ke kterému doručenka patří.', 400);
        }

        try {
            $result = $this->receipts->confirmMatch(
                SupplierGuard::currentId($request),
                (int) ($args['id'] ?? 0),
                $outboxId,
                $this->userId($request),
            );
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            return Json::error($response, 'receipt_conflict', $e->getMessage(), 409);
        }

        return Json::ok($response, $result);
    }

    /**
     * POST /api/submissions/outbox/{id}/mark-sent — „odeslal jsem to ručně".
     *
     * Uživatel opíše ID zprávy z odeslané zprávy ve své datové schránce. Není
     * to formalita: je to jediný identifikátor, podle kterého se pak doručenka
     * spáruje sama, i kdyby v ní naše spisová značka nebyla.
     *
     * @param array<string,string> $args
     */
    public function markSent(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $sentAt = null;
        $rawSentAt = trim((string) ($body['sent_at'] ?? ''));
        if ($rawSentAt !== '') {
            try {
                $sentAt = new \DateTimeImmutable($rawSentAt);
            } catch (\Throwable) {
                return Json::error($response, 'invalid_sent_at', 'Datum odeslání nemá platný tvar.', 400);
            }
        }

        try {
            $result = $this->outbox->markSentManually(
                SupplierGuard::currentId($request),
                (int) ($args['id'] ?? 0),
                $this->userId($request),
                (string) ($body['external_message_id'] ?? ''),
                $sentAt,
            );
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            return Json::error($response, 'submission_conflict', $e->getMessage(), 409);
        }

        return Json::ok($response, $result);
    }

    /**
     * POST /api/submissions/outbox/{id}/receipt/download — vyžádat dodejku
     * z ISDS pod uloženým pověřením firmy (systémový certifikát).
     *
     * Za certifikátem nestojí člověk, proto se to — stejně jako u odeslání
     * ({@see SubmissionOutboxAction::certificateSend()}) — potvrzuje v aplikaci.
     *
     * @param array<string,string> $args
     */
    public function download(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $outboxId = (int) ($args['id'] ?? 0);
        if ($outboxId <= 0) {
            return Json::error($response, 'submission_not_found', 'Podání neexistuje.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $environment = (string) ($body['environment'] ?? 'production');
        if (!in_array($environment, ['production', 'test'], true)) {
            return Json::error($response, 'invalid_environment', 'Neznámé prostředí.', 400);
        }

        $supplierId = SupplierGuard::currentId($request);
        try {
            $context = $this->credentials->unlock($supplierId, $environment);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return $this->downloadWithContext($response, $supplierId, $outboxId, $environment, $request, $context);
    }

    /**
     * POST /api/submissions/outbox/{id}/receipt/download/mobile-key/confirm —
     * totéž v relaci potvrzené Mobilním klíčem.
     *
     * Relace se zahajuje existující cestou `/api/submissions/outbox/{id}/mobile-key/start`;
     * ta o podání nic netvrdí, jen přihlašuje. Stažení musí proběhnout TÍMTO
     * voláním, protože `continue()` potvrzení spotřebuje a podruhé už relaci
     * vyzvednout nejde.
     *
     * @param array<string,string> $args
     */
    public function downloadWithMobileKey(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $outboxId = (int) ($args['id'] ?? 0);
        if ($outboxId <= 0) {
            return Json::error($response, 'submission_not_found', 'Podání neexistuje.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $environment = (string) ($body['environment'] ?? 'production');
        if (!in_array($environment, ['production', 'test'], true)) {
            return Json::error($response, 'invalid_environment', 'Neznámé prostředí.', 400);
        }
        $flowToken = (string) ($body['flow_token'] ?? '');
        if ($flowToken === '' || strlen($flowToken) > 8192) {
            return Json::error(
                $response,
                'isds_mobile_flow_invalid',
                'Přihlášení Mobilním klíčem není platné. Spusťte ho znovu.',
                400,
            );
        }

        $supplierId = SupplierGuard::currentId($request);
        try {
            $flow = $this->mobileKey->continue(
                $flowToken,
                $supplierId,
                $this->userId($request),
                $environment,
            );
            $context = $flow['context'];
            if ($context === null) {
                // Čeká se na potvrzení v mobilu — podání se nedotýkáme.
                return Json::ok($response, [
                    'state' => $flow['state'],
                    'description' => $flow['description'],
                    'result' => null,
                ]);
            }
            try {
                $result = $this->receipts->downloadFromIsds(
                    $supplierId,
                    $environment,
                    $outboxId,
                    $this->userId($request),
                    $context,
                    $this->transport,
                );
            } finally {
                $this->mobileKey->logout($context);
            }
        } catch (DocumentException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            return Json::error($response, 'receipt_conflict', $e->getMessage(), 409);
        }

        return Json::ok($response, [
            'state' => $flow['state'],
            'description' => $flow['description'],
            'result' => $result,
        ]);
    }

    /**
     * POST /api/submissions/outbox/receipts/download — dodejky ke VŠEM
     * odeslaným zprávám, které je ještě nemají, v jednom přihlášení.
     *
     * Přihlášení do schránky je potvrzení v mobilu; vyžadovat ho u každé
     * zprávy zvlášť znamená, že to účetní po uzávěrce přestane dělat.
     */
    public function downloadBatch(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $environment = (string) ($body['environment'] ?? 'production');
        if (!in_array($environment, ['production', 'test'], true)) {
            return Json::error($response, 'invalid_environment', 'Neznámé prostředí.', 400);
        }

        $supplierId = SupplierGuard::currentId($request);
        try {
            $context = $this->credentials->unlock($supplierId, $environment);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return $this->downloadBatchWithContext($response, $supplierId, $environment, $request, $context);
    }

    /**
     * POST /api/submissions/outbox/receipts/download/password — dávka
     * v relaci otevřené jménem a heslem do datové schránky.
     *
     * Údaje se nikam neukládají; žijí jen po dobu tohohle volání.
     */
    public function downloadBatchWithPassword(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $environment = (string) ($body['environment'] ?? 'production');
        if (!in_array($environment, ['production', 'test'], true)) {
            return Json::error($response, 'invalid_environment', 'Neznámé prostředí.', 400);
        }
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if ($username === '' || strlen($username) > 128
            || preg_match('/[\x00-\x20:\x7f]/', $username) === 1
        ) {
            return Json::error(
                $response,
                'isds_username_invalid',
                'Vyplňte platné uživatelské jméno k datové schránce.',
                400,
            );
        }
        if ($password === '' || strlen($password) > 512) {
            return Json::error(
                $response,
                'isds_password_invalid',
                'Vyplňte heslo k datové schránce.',
                400,
            );
        }

        $supplierId = SupplierGuard::currentId($request);
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

        return $this->downloadBatchWithContext($response, $supplierId, $environment, $request, $context);
    }

    /**
     * POST /api/submissions/outbox/receipts/download/sms/start — vyžádá SMS kód.
     */
    public function downloadBatchSmsStart(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $result = $this->sms->start(
                SupplierGuard::currentId($request),
                $this->userId($request),
                (string) ($body['environment'] ?? 'production'),
                (string) ($body['username'] ?? ''),
                (string) ($body['password'] ?? ''),
            );
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return Json::ok($response, $result);
    }

    /**
     * POST /api/submissions/outbox/receipts/download/sms/complete — ověří kód
     * a v téže relaci stáhne všechny čekající dodejky.
     */
    public function downloadBatchSmsComplete(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $environment = (string) ($body['environment'] ?? 'production');
        if (!in_array($environment, ['production', 'test'], true)) {
            return Json::error($response, 'invalid_environment', 'Neznámé prostředí.', 400);
        }
        $flowToken = (string) ($body['flow_token'] ?? '');
        if ($flowToken === '' || strlen($flowToken) > 8192) {
            return Json::error(
                $response,
                'isds_sms_flow_invalid',
                'Přihlášení pomocí SMS není platné. Vyžádejte nový kód.',
                400,
            );
        }

        $supplierId = SupplierGuard::currentId($request);
        try {
            $context = $this->sms->complete(
                $flowToken,
                (string) ($body['sms_code'] ?? ''),
                $supplierId,
                $this->userId($request),
                $environment,
            );
            try {
                $result = $this->receipts->downloadManyFromIsds(
                    $supplierId,
                    $environment,
                    $this->userId($request),
                    $context,
                    $this->transport,
                );
            } finally {
                $this->sms->logout($context);
            }
        } catch (DocumentException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            return Json::error($response, 'receipt_conflict', $e->getMessage(), 409);
        }

        return Json::ok($response, $result);
    }

    /**
     * POST /api/submissions/outbox/receipts/download/mobile-key/confirm —
     * totéž v relaci potvrzené Mobilním klíčem.
     *
     * Relace se zahajuje existující dávkovou cestou
     * `/api/submissions/outbox/mobile-key/start-batch`; ta o žádném podání nic
     * netvrdí, jen přihlašuje.
     */
    public function downloadBatchWithMobileKey(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $environment = (string) ($body['environment'] ?? 'production');
        if (!in_array($environment, ['production', 'test'], true)) {
            return Json::error($response, 'invalid_environment', 'Neznámé prostředí.', 400);
        }
        $flowToken = (string) ($body['flow_token'] ?? '');
        if ($flowToken === '' || strlen($flowToken) > 8192) {
            return Json::error(
                $response,
                'isds_mobile_flow_invalid',
                'Přihlášení Mobilním klíčem není platné. Spusťte ho znovu.',
                400,
            );
        }

        $supplierId = SupplierGuard::currentId($request);
        try {
            $flow = $this->mobileKey->continue(
                $flowToken,
                $supplierId,
                $this->userId($request),
                $environment,
            );
            $context = $flow['context'];
            if ($context === null) {
                // Čeká se na potvrzení v mobilu — žádné podání se nedotýká.
                return Json::ok($response, [
                    'state' => $flow['state'],
                    'description' => $flow['description'],
                    'result' => null,
                ]);
            }
            try {
                $result = $this->receipts->downloadManyFromIsds(
                    $supplierId,
                    $environment,
                    $this->userId($request),
                    $context,
                    $this->transport,
                );
            } finally {
                $this->mobileKey->logout($context);
            }
        } catch (DocumentException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            return Json::error($response, 'receipt_conflict', $e->getMessage(), 409);
        }

        return Json::ok($response, [
            'state' => $flow['state'],
            'description' => $flow['description'],
            'result' => $result,
        ]);
    }

    // ───────────────────────── interní ─────────────────────────

    /** Společný závěr obou dávkových cest. */
    private function downloadBatchWithContext(
        Response $response,
        int $supplierId,
        string $environment,
        Request $request,
        ChannelContext $context,
    ): Response {
        try {
            $result = $this->receipts->downloadManyFromIsds(
                $supplierId,
                $environment,
                $this->userId($request),
                $context,
                $this->transport,
            );
        } catch (DocumentException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            return Json::error($response, 'receipt_conflict', $e->getMessage(), 409);
        }

        return Json::ok($response, $result);
    }

    /** Společný závěr obou cest stažení. */
    private function downloadWithContext(
        Response $response,
        int $supplierId,
        int $outboxId,
        string $environment,
        Request $request,
        ChannelContext $context,
    ): Response {
        try {
            $result = $this->receipts->downloadFromIsds(
                $supplierId,
                $environment,
                $outboxId,
                $this->userId($request),
                $context,
                $this->transport,
            );
        } catch (DocumentException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            return Json::error($response, 'receipt_conflict', $e->getMessage(), 409);
        }

        return Json::ok($response, $result);
    }

    private function singleFile(Request $request): ?UploadedFileInterface
    {
        $files = $request->getUploadedFiles();
        foreach (['receipt', 'file'] as $field) {
            $candidate = $files[$field] ?? null;
            if (is_array($candidate)) {
                $candidate = $candidate[0] ?? null;
            }
            if ($candidate instanceof UploadedFileInterface) {
                return $candidate;
            }
        }

        return null;
    }

    private function guard(Request $request, Response $response, AccessLevel $level): ?Response
    {
        if (!RequestAuthorization::allows($request, 'settings.signing', $level)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění.', 403);
        }
        if ($this->userId($request) <= 0) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }
        // Doručenka je důkaz o podání a připojuje ho člověk, ne integrace.
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'forbidden_via_token',
                'Doručenky se obsluhují jen z webového rozhraní.',
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
