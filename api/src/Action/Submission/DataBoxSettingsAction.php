<?php

declare(strict_types=1);

namespace MyInvoice\Action\Submission;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\SubmissionCredentialService;
use MyInvoice\Service\Submission\IsdsMobileCredentialService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Firma → Datová schránka: systémový certifikát aktuální firmy pro ručně
 * spuštěné operace.
 *
 * Bezpečnostní režim se přebírá od trezoru certifikátů beze změny:
 * jen z přihlášené webové relace, nikdy přes bearer token. Certifikát k datové
 * schránce otevírá odesílání podání jménem firmy — je to stejná třída tajemství
 * jako soukromý klíč, a tak se s ním zachází.
 *
 * Osobní Mobilní klíč má oddělený trezor v rozsahu firma + uživatel. Jeho
 * komunikační kód se nikdy nevrací v API a nezakládá automatické vybírání.
 *
 * Odpověď NIKDY neobsahuje uložený certifikát ani jeho heslo: čte se z projekce,
 * která ciphertext sloupce vůbec nevybírá.
 */
final class DataBoxSettingsAction
{
    public function __construct(
        private readonly SubmissionCredentialService $credentials,
        private readonly IsdsMobileCredentialService $mobileCredentials,
        private readonly ActivityLogger $logger,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }

        return Json::ok($response, [
            'items' => $this->credentials->listPublic(SupplierGuard::currentId($request)),
        ]);
    }

    public function save(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $userId = $this->userId($request);
        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $certificate = '';
            $file = $request->getUploadedFiles()['certificate'] ?? null;
            if ($file instanceof UploadedFileInterface && $file->getError() === UPLOAD_ERR_OK) {
                $certificate = (string) $file->getStream()->getContents();
            }

            $saved = $this->credentials->save(
                $supplierId,
                (string) ($body['environment'] ?? 'production'),
                (string) ($body['label'] ?? ''),
                (string) ($body['box_id'] ?? ''),
                $certificate,
                isset($body['certificate_password']) ? (string) $body['certificate_password'] : null,
                $userId,
            );
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        $this->logger->log('databox_credentials_save', $userId, 'databox', $saved['id'], null, null, null, $supplierId);

        return Json::ok($response, $saved);
    }

    public function mobileKeyProfile(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }
        try {
            $profile = $this->mobileCredentials->profile(
                SupplierGuard::currentId($request),
                $this->userId($request),
                (string) ($request->getQueryParams()['environment'] ?? 'production'),
            );
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        return Json::ok($response, $profile);
    }

    public function saveMobileKeyProfile(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $userId = $this->userId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $profile = $this->mobileCredentials->save(
                $supplierId,
                $userId,
                (string) ($body['environment'] ?? 'production'),
                (string) ($body['username'] ?? ''),
                (string) ($body['communication_code'] ?? ''),
            );
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        $this->logger->log('databox_mobile_credentials_save', $userId, 'databox', $profile['id'], null, null, null, $supplierId);
        return Json::ok($response, $profile);
    }

    /** @param array<string,string> $args */
    public function deleteMobileKeyProfile(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $userId = $this->userId($request);
        try {
            $deleted = $this->mobileCredentials->delete(
                $supplierId,
                $userId,
                (string) ($args['environment'] ?? 'production'),
            );
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        if (!$deleted) {
            return Json::error($response, 'not_found', 'Uložené přihlášení Mobilním klíčem nebylo nalezeno.', 404);
        }
        $this->logger->log('databox_mobile_credentials_delete', $userId, 'databox', 0, null, null, null, $supplierId);
        return Json::ok($response, ['deleted' => true]);
    }

    /** @param array<string,string> $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $environment = (string) ($args['environment'] ?? 'production');

        try {
            $deleted = $this->credentials->delete($supplierId, $environment);
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        if (!$deleted) {
            return Json::error($response, 'not_found', 'Takové přihlášení uložené není.', 404);
        }

        $this->logger->log('databox_credentials_delete', $this->userId($request), 'databox', 0, null, null, null, $supplierId);

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
                'Přihlášení k datové schránce lze spravovat jen z webového rozhraní.',
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
