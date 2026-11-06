<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eet;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Eet\EetCertificateVault;
use MyInvoice\Service\Eet\EetException;
use MyInvoice\Service\Eet\EetSettingsService;
use MyInvoice\Service\Epo\EpoStepUpService;
use MyInvoice\Service\Epo\EpoSubmissionException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Pokladní certifikáty EET firmy: metadata, chráněný upload `.p12` a místní
 * deaktivace. Soukromý klíč ani heslo se nikdy nevrací a nejdou do auditu.
 *
 * Upload jen z webové relace (nikdy přes token) a s opětovným ověřením
 * uživatele, stejně jako trezor elektronických podpisů. Soubor jde v multipart
 * poli `file`, nebo v JSON jako `pkcs12_base64`; heslo k souboru v `pfx_password`
 * (`password` nese opětovné ověření uživatele).
 */
final class EetCertificatesAction
{
    private const MAX_PKCS12_BYTES = 65_536;

    public function __construct(
        private readonly EetCertificateVault $vault,
        private readonly EetSettingsService $settings,
        private readonly EpoStepUpService $stepUp,
        private readonly ActivityLogger $logger,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($denied = EetActionSupport::deny($request, $response, 'eet.certificates', AccessLevel::READ)) !== null) {
            return $denied;
        }
        return Json::ok($response, ['items' => $this->vault->listMetadata(SupplierGuard::currentId($request))]);
    }

    public function upload(Request $request, Response $response): Response
    {
        if (($denied = EetActionSupport::deny($request, $response, 'eet.certificates', AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $userId = EetActionSupport::userId($request);
        if ($userId === null) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }
        // Soukromý klíč se nikdy nespravuje přes token: token se dá odcizit
        // a na rozdíl od relace u něj není druhý faktor.
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response, 'Pokladní certifikát EET lze nahrát jen z webového rozhraní.');
        }
        $supplierId = SupplierGuard::currentId($request);
        $body = EetActionSupport::body($request);
        try {
            $this->stepUp->verify($request, $userId, $body, 'credential_upload');
        } catch (EpoSubmissionException $exception) {
            return Json::error($response, $exception->errorCode, $exception->getMessage(), $exception->httpStatus);
        }

        $pkcs12 = $this->pkcs12($request, $body);
        if ($pkcs12 === null) {
            return Json::error($response, EetException::VALIDATION, 'Nahrajte soubor pokladního certifikátu ve formátu P12 (nejvýš 64 kB).', 422);
        }
        $environment = $body['environment'] ?? $this->settings->get($supplierId)['environment'];
        $label = $body['label'] ?? null;
        try {
            $certificate = $this->vault->import(
                $supplierId,
                is_string($environment) ? $environment : '',
                $pkcs12,
                is_scalar($body['pfx_password'] ?? null) ? (string) $body['pfx_password'] : '',
                is_scalar($label) ? (string) $label : null,
                $userId,
            );
        } catch (EetException $exception) {
            return EetActionSupport::error($response, $exception);
        } finally {
            if (function_exists('sodium_memzero')) {
                sodium_memzero($pkcs12);
            }
        }

        $this->logger->log(
            'eet_certificate_upload',
            $userId,
            'eet_certificate',
            (int) $certificate['id'],
            [
                'environment' => $certificate['environment'],
                'eic' => $certificate['eic'],
                'fingerprint_sha256' => $certificate['fingerprint_sha256'],
                'not_after' => $certificate['not_after'],
            ],
            null,
            null,
            $supplierId,
        );

        return Json::ok($response, $certificate, 201);
    }

    /** @param array<string,string> $args */
    public function deactivate(Request $request, Response $response, array $args): Response
    {
        if (($denied = EetActionSupport::deny($request, $response, 'eet.certificates', AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $userId = EetActionSupport::userId($request);
        if ($userId === null) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }
        // Deaktivace zastaví evidenci tržeb stejně účinně jako výměna certifikátu,
        // proto platí stejná ochrana jako pro upload: jen relace a opětovné ověření.
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response, 'Pokladní certifikát EET lze deaktivovat jen z webového rozhraní.');
        }
        try {
            $this->stepUp->verify($request, $userId, EetActionSupport::body($request), 'credential_delete');
        } catch (EpoSubmissionException $exception) {
            return Json::error($response, $exception->errorCode, $exception->getMessage(), $exception->httpStatus);
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = EetActionSupport::id($args);
        try {
            $this->vault->deactivate($supplierId, $id, $userId);
        } catch (EetException $exception) {
            return EetActionSupport::error($response, $exception);
        }
        $this->logger->log('eet_certificate_deactivate', $userId, 'eet_certificate', $id, null, null, null, $supplierId);

        return Json::ok($response, $this->vault->find($supplierId, $id));
    }

    /** @param array<string,mixed> $body */
    private function pkcs12(Request $request, array $body): ?string
    {
        $file = $request->getUploadedFiles()['file'] ?? null;
        if ($file instanceof UploadedFileInterface && $file->getError() === UPLOAD_ERR_OK) {
            // Velikost z hlavičky uploadu, ať se příliš velký soubor vůbec nenačítá.
            if (($file->getSize() ?? 0) > self::MAX_PKCS12_BYTES) {
                return null;
            }
            $bytes = (string) $file->getStream();
            return $bytes === '' ? null : $bytes;
        }
        $encoded = $body['pkcs12_base64'] ?? null;
        if (is_string($encoded) && $encoded !== '') {
            $bytes = base64_decode($encoded, true);
            return $bytes === false || $bytes === '' ? null : $bytes;
        }
        return null;
    }
}
