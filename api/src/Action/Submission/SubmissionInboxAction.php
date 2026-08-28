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
use MyInvoice\Service\Submission\Channel\Isds\DirectIsdsInboxTransport;
use MyInvoice\Service\Submission\Channel\Isds\IsdsChannel;
use MyInvoice\Service\Submission\Channel\Isds\MobileKeyIsdsAuthenticator;
use MyInvoice\Service\Submission\Channel\Isds\SmsIsdsAuthenticator;
use MyInvoice\Service\Submission\Channel\SensitiveValue;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\SubmissionCredentialService;
use MyInvoice\Service\Submission\SubmissionInboxService;
use MyInvoice\Service\Submission\SubmissionInboxPrivacyService;
use MyInvoice\Service\Submission\IsdsMobileCredentialService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Příchozí zprávy z datové schránky.
 *
 * `state` v odpovědi nese `last_ok_at` odděleně od `last_attempt_at` — UI má
 * podle čeho poznat, že „0 zpráv" znamená prázdnou schránku, a ne že se na ni
 * aplikace nedovolá.
 */
final class SubmissionInboxAction
{
    public function __construct(
        private readonly SubmissionInboxService $inbox,
        private readonly SubmissionCredentialService $credentials,
        private readonly DirectIsdsInboxTransport $directTransport,
        private readonly MobileKeyIsdsAuthenticator $mobileKey,
        private readonly SmsIsdsAuthenticator $sms,
        private readonly IsdsMobileCredentialService $mobileCredentials,
        private readonly SubmissionInboxPrivacyService $privacy,
        private readonly ActivityLogger $logger,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $params = $request->getQueryParams();
        $environment = (string) ($params['environment'] ?? 'production');
        $classification = isset($params['classification']) && $params['classification'] !== ''
            ? (string) $params['classification']
            : null;
        $visibility = (string) ($params['visibility'] ?? 'active');

        try {
            return Json::ok($response, [
                'items' => $this->inbox->listRecent(
                    $supplierId,
                    $environment,
                    $classification,
                    visibility: $visibility,
                ),
                'state' => $this->inbox->pollState($supplierId, 'isds', $environment),
            ]);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'invalid_visibility', $e->getMessage(), 400);
        }
    }

    /** Ruční vyzvednutí — jediná povolená cesta, vždy spuštěná uživatelem. */
    public function poll(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $environment = (string) ($body['environment'] ?? 'production');
        if (($error = $this->acknowledgementError($body, $response)) !== null) {
            return $error;
        }

        try {
            $context = $this->credentials->unlock($supplierId, $environment);
            $result = $this->inbox->pollWithChannel(
                $context,
                'isds',
                new IsdsChannel($this->directTransport),
                500,
                $this->userId($request),
            );
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return $this->pollResult($response, $result);
    }

    /** Jednorázové jméno a heslo se použijí pouze během tohoto HTTP volání. */
    public function pollWithPassword(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        if (($error = $this->acknowledgementError($body, $response)) !== null) {
            return $error;
        }
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
        try {
            $result = $this->inbox->pollWithChannel(
                $context,
                'isds',
                new IsdsChannel($this->directTransport),
                500,
                $this->userId($request),
            );
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        return $this->pollResult($response, $result);
    }

    /** Zahájí jednu ručně potvrzenou relaci Mobilního klíče eGovernmentu. */
    public function mobileKeyStart(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        if (($error = $this->acknowledgementError($body, $response)) !== null) {
            return $error;
        }
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

    /** Ověří potvrzení relace; po potvrzení provede právě jedno načtení schránky. */
    public function mobileKeyStatus(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $flowToken = (string) ($body['flow_token'] ?? '');
        if ($flowToken === '' || strlen($flowToken) > 8192) {
            return Json::error($response, 'isds_mobile_flow_invalid', 'Přihlášení Mobilním klíčem není platné. Spusťte ho znovu.', 400);
        }

        try {
            $result = $this->mobileKey->continue(
                $flowToken,
                SupplierGuard::currentId($request),
                $this->userId($request),
                (string) ($body['environment'] ?? 'production'),
            );
            $context = $result['context'];
            if ($context === null) {
                return Json::ok($response, [
                    'state' => $result['state'],
                    'description' => $result['description'],
                    'result' => null,
                ]);
            }
            try {
                $pollResult = $this->inbox->pollWithChannel(
                    $context,
                    'isds',
                    new IsdsChannel($this->directTransport),
                    500,
                    $this->userId($request),
                );
            } finally {
                $this->mobileKey->logout($context);
            }
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        if ($pollResult['error'] !== null && $pollResult['fetched'] === 0) {
            return $this->pollResult($response, $pollResult);
        }
        return Json::ok($response, [
            'state' => 2,
            'description' => $result['description'],
            'result' => $pollResult,
        ]);
    }

    /** Ověří jméno a heslo a uloží je šifrovaně jen do krátkého jednorázového SMS flow. */
    public function smsStart(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        if (($error = $this->acknowledgementError($body, $response)) !== null) {
            return $error;
        }
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

    /** Ověří SMS kód, provede právě jedno načtení a relaci ISDS ukončí. */
    public function smsComplete(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $flowToken = (string) ($body['flow_token'] ?? '');
        if ($flowToken === '' || strlen($flowToken) > 8192) {
            return Json::error($response, 'isds_sms_flow_invalid', 'Přihlášení pomocí SMS není platné. Vyžádejte nový kód.', 400);
        }
        try {
            $context = $this->sms->complete(
                $flowToken,
                (string) ($body['sms_code'] ?? ''),
                SupplierGuard::currentId($request),
                $this->userId($request),
                (string) ($body['environment'] ?? 'production'),
            );
            try {
                $result = $this->inbox->pollWithChannel(
                    $context,
                    'isds',
                    new IsdsChannel($this->directTransport),
                    500,
                    $this->userId($request),
                );
            } finally {
                $this->sms->logout($context);
            }
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        return $this->pollResult($response, $result);
    }

    /** @param array{fetched:int,stored:int,skipped:int,failed:int,unclassified:int,error:?string} $result */
    private function pollResult(Response $response, array $result): Response
    {
        if ($result['error'] !== null && $result['fetched'] === 0) {
            // Neúspěšný dotaz se nesmí uživateli ukázat jako „nic nového".
            return Json::error(
                $response,
                (string) $result['error'],
                'Na datovou schránku se nepodařilo dovolat, takže o nových zprávách nic nevíme. Zkuste to prosím znovu.',
                502,
            );
        }

        return Json::ok($response, $result);
    }

    /** @param array<string,string> $args */
    public function reclassify(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $updated = $this->inbox->reclassify(
                SupplierGuard::currentId($request),
                (int) ($args['id'] ?? 0),
                (string) ($body['classification'] ?? ''),
                isset($body['outbox_id']) ? (int) $body['outbox_id'] : null,
                $this->rowVersion($body),
            );
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
        if (!$updated) {
            return Json::error($response, 'not_found', 'Zpráva nebyla nalezena.', 404);
        }

        return Json::ok($response, [
            'item' => $this->inbox->findById(
                SupplierGuard::currentId($request),
                (int) ($args['id'] ?? 0),
            ),
        ]);
    }

    /** @param array<string,string> $args */
    public function hide(Request $request, Response $response, array $args): Response
    {
        return $this->changeVisibility($request, $response, $args, true);
    }

    /** @param array<string,string> $args */
    public function restore(Request $request, Response $response, array $args): Response
    {
        return $this->changeVisibility($request, $response, $args, false);
    }

    /** @param array<string,string> $args */
    public function purgeLocalContent(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        if (($body['acknowledged'] ?? false) !== true) {
            return Json::error(
                $response,
                'acknowledgement_required',
                'Nevratné odstranění místní kopie musíte výslovně potvrdit.',
                400,
            );
        }
        try {
            $supplierId = SupplierGuard::currentId($request);
            $item = $this->privacy->purgeLocalContent(
                $supplierId,
                $this->positiveMessageId($args),
                $this->rowVersion($body),
                $this->userId($request),
            );
            $purged = (string) $item['local_content_state'] === 'purged';
            $this->auditPrivacy(
                $request,
                $supplierId,
                (int) $item['id'],
                $purged ? 'purged' : 'purge_pending',
            );
            return Json::ok($response, ['item' => $item], $purged ? 200 : 202);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
    }

    /** @param array<string,string> $args */
    private function changeVisibility(
        Request $request,
        Response $response,
        array $args,
        bool $hidden,
    ): Response {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        try {
            $supplierId = SupplierGuard::currentId($request);
            $body = (array) ($request->getParsedBody() ?? []);
            $item = $hidden
                ? $this->privacy->hide(
                    $supplierId,
                    $this->positiveMessageId($args),
                    $this->rowVersion($body),
                    $this->userId($request),
                )
                : $this->privacy->restore(
                    $supplierId,
                    $this->positiveMessageId($args),
                    $this->rowVersion($body),
                    $this->userId($request),
                );
            $this->auditPrivacy(
                $request,
                $supplierId,
                (int) $item['id'],
                $hidden ? 'hidden' : 'restored',
            );
            return Json::ok($response, ['item' => $item]);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
    }

    /** @param array<string,string> $args */
    private function positiveMessageId(array $args): int
    {
        $value = (string) ($args['id'] ?? '');
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new \InvalidArgumentException('ID zprávy musí být kladné celé číslo.');
        }
        return (int) $value;
    }

    /** @param array<string,mixed> $body */
    private function rowVersion(array $body): int
    {
        $value = $body['row_version'] ?? null;
        if ((!is_int($value) && !(is_string($value) && ctype_digit($value)))
            || (int) $value <= 0
        ) {
            throw new \InvalidArgumentException('Chybí platná verze zprávy.');
        }
        return (int) $value;
    }

    private function auditPrivacy(
        Request $request,
        int $supplierId,
        int $messageId,
        string $operation,
    ): void {
        $this->logger->log(
            'databox.inbox_privacy_' . $operation,
            $this->userId($request),
            'submission_inbox_message',
            $messageId,
            ['operation' => $operation],
            null,
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
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
                'Datová schránka se obsluhuje jen z webového rozhraní.',
                403,
            );
        }

        return null;
    }

    /** @param array<string,mixed> $body */
    private function acknowledgementError(array $body, Response $response): ?Response
    {
        if (($body['acknowledged'] ?? false) === true) {
            return null;
        }
        return Json::error(
            $response,
            'acknowledgement_required',
            'Vyzvednutí zpráv se může počítat jako doručení a spustit zákonné lhůty. Akci musíte výslovně potvrdit.',
            400,
        );
    }

    private function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);

        return (int) ($user['id'] ?? 0);
    }
}
