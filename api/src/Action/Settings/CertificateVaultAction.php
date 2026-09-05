<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Epo\EpoSigningCredentialService;
use MyInvoice\Service\Epo\EpoStepUpService;
use MyInvoice\Service\Epo\EpoSubmissionException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Certifikáty na jednom místě — Systém → Elektronické podpisy.
 *
 * Až dosud měla aplikace dvě úložiště: certifikát nahraný do podpisového
 * profilu (`signing_credentials`) a šifrovaný osobní trezor
 * (`epo_signing_credentials`). Profil se na trezor umí odkázat, ale jen
 * jednosměrně, takže certifikát nahraný u e-mailových podpisů nešel použít
 * v EPO. Uživatel to našel dřív než my.
 *
 * Tahle akce dělá z trezoru **jediné místo, kam se certifikát nahrává**.
 * Neotevírá nové úložiště — naopak zpřístupňuje to existující pod neutrálním
 * názvem, protože „EPO certifikát" je jméno prvního konzumenta, ne účelu.
 * EPO endpointy zůstávají v platnosti a míří do téhož trezoru.
 *
 * Bezpečnostní režim se přebírá beze změny: jen z přihlášené webové relace
 * (nikdy přes bearer token) a s ověřením druhým faktorem u každé operace,
 * která se dotkne soukromého klíče.
 */
final class CertificateVaultAction
{
    public function __construct(
        private readonly EpoSigningCredentialService $credentials,
        private readonly EpoStepUpService $stepUp,
        private readonly ActivityLogger $logger,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }

        return Json::ok($response, [
            'items' => $this->credentials->listOwnedForSupplier(
                $this->userId($request),
                SupplierGuard::currentId($request),
            ),
        ]);
    }

    public function upload(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $userId = $this->userId($request);
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $this->stepUp->verify($request, $userId, $body, 'credential_upload');
            $file = $request->getUploadedFiles()['file'] ?? null;
            if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
                throw new EpoSubmissionException(
                    'certificate_file_required',
                    'Nahrajte soubor certifikátu ve formátu PFX nebo P12.',
                    422,
                );
            }
            $label = trim((string) ($body['label'] ?? ''));
            $password = (string) ($body['password'] ?? '');
            $result = $this->credentials->import(
                $userId,
                $supplierId,
                $label === '' ? (string) $file->getClientFilename() : $label,
                (string) $file->getStream(),
                $password,
            );
        } catch (EpoSubmissionException $exception) {
            return Json::error(
                $response,
                $exception->errorCode,
                $exception->getMessage(),
                $exception->httpStatus,
            );
        }
        $this->logger->log(
            'certificate_vault_upload',
            $userId,
            'certificate',
            (int) ($result['id'] ?? 0),
            null,
            null,
            null,
            $supplierId,
        );

        return Json::ok($response, $result);
    }

    private function guard(Request $request, Response $response, AccessLevel $level): ?Response
    {
        if (!RequestAuthorization::allows($request, 'settings.signing', $level)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění.', 403);
        }
        if ($this->userId($request) <= 0) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }
        // Soukromý klíč se nikdy nespravuje přes token: token se dá odcizit
        // a na rozdíl od relace u něj není druhý faktor.
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response, 'Certifikáty lze spravovat jen z webového rozhraní.');
        }

        return null;
    }

    private function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);

        return (int) ($user['id'] ?? 0);
    }
}
