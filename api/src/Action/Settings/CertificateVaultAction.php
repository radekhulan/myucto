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
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Signing\CertificateVaultSupplierSharing;
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
        private readonly CertificateVaultSupplierSharing $sharing,
        private readonly IpMatcher $ipMatcher,
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
            // Heslo k souboru má vlastní pole: `password` nese opětovné
            // ověření uživatele a v multipart formuláři by jedno přebilo druhé.
            $password = (string) ($body['pfx_password'] ?? '');
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

        $replaceId = (int) ($body['replace_credential_id'] ?? 0);
        if ($replaceId > 0) {
            // Certifikát už je uložený; nepovedená náhrada ho nesmí schovat
            // za chybovou odpověď, jen se ohlásí vedle něj.
            try {
                $result['supplier_sharing'] = $this->sharing->replaceInSuppliers(
                    $request,
                    (int) ($result['id'] ?? 0),
                    $replaceId,
                    $userId,
                    $supplierId,
                    $this->clientIp($request),
                );
                if ($result['supplier_sharing'] === null) {
                    unset($result['supplier_sharing']);
                    $result['supplier_sharing_error'] = 'Nahrazovaný certifikát nebyl nalezen.';
                }
            } catch (\InvalidArgumentException | EpoSubmissionException $exception) {
                $result['supplier_sharing_error'] = $exception->getMessage();
            }
        } elseif ($this->flag($body, 'share_with_other_suppliers')) {
            $result['supplier_sharing'] = $this->sharing->shareWithOtherSuppliers(
                $request,
                (int) ($result['id'] ?? 0),
                $userId,
                $supplierId,
                $this->flag($body, 'share_only_without_valid'),
                $this->clientIp($request),
            ) ?? [];
        }

        return Json::ok($response, $result);
    }

    /**
     * Povolí už uložený certifikát i v dalších firmách uživatele, typicky
     * certifikát nahraný dřív jen v jedné firmě. Cílové firmy určuje server
     * ({@see CertificateVaultSupplierSharing}); klient posílá jen volbu
     * „jen do firem bez platného certifikátu", případně `replace_credential_id`,
     * když má certifikát všude nahradit starší.
     */
    public function shareWithOtherSuppliers(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $userId = $this->userId($request);
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $this->stepUp->verify($request, $userId, $body, 'credential_supplier_access');
        } catch (EpoSubmissionException $exception) {
            return Json::error(
                $response,
                $exception->errorCode,
                $exception->getMessage(),
                $exception->httpStatus,
            );
        }
        $credentialId = (int) ($args['credentialId'] ?? 0);
        $replaceId = (int) ($body['replace_credential_id'] ?? 0);
        if ($replaceId > 0) {
            try {
                $results = $this->sharing->replaceInSuppliers(
                    $request,
                    $credentialId,
                    $replaceId,
                    $userId,
                    $supplierId,
                    $this->clientIp($request),
                );
            } catch (\InvalidArgumentException $exception) {
                return Json::error($response, 'replace_invalid', $exception->getMessage(), 422);
            } catch (EpoSubmissionException $exception) {
                return Json::error(
                    $response,
                    $exception->errorCode,
                    $exception->getMessage(),
                    $exception->httpStatus,
                );
            }
        } else {
            $results = $this->sharing->shareWithOtherSuppliers(
                $request,
                $credentialId,
                $userId,
                $supplierId,
                $this->flag($body, 'share_only_without_valid'),
                $this->clientIp($request),
            );
        }
        if ($results === null) {
            return Json::error($response, 'credential_not_found', 'Certifikát nebyl nalezen.', 404);
        }

        return Json::ok($response, ['supplier_sharing' => $results]);
    }

    /**
     * Smaže certifikát z osobního trezoru. Pravidla (jen vlastník, co mazání
     * blokuje, co se zruší s ním) drží {@see EpoSigningCredentialService::deleteOwned()},
     * stejně jako pro EPO endpoint.
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $userId = $this->userId($request);
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $credentialId = (int) ($args['credentialId'] ?? 0);
        try {
            $this->stepUp->verify($request, $userId, $body, 'credential_delete');
            $deleted = $this->credentials->deleteOwned($credentialId, $userId);
        } catch (EpoSubmissionException $exception) {
            return Json::error(
                $response,
                $exception->errorCode,
                $exception->getMessage(),
                $exception->httpStatus,
                $exception->details,
            );
        }
        $this->logger->log(
            'certificate_vault_delete',
            $userId,
            'certificate',
            $credentialId,
            $deleted['payroll_selections_removed'] === []
                ? null
                : ['payroll_selections_removed' => $deleted['payroll_selections_removed']],
            $this->clientIp($request),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return Json::ok($response, [
            'deleted' => true,
            'payroll_selections_removed' => $deleted['payroll_selections_removed'],
        ]);
    }

    /** @param array<string,mixed> $body */
    private function flag(array $body, string $key): bool
    {
        return filter_var($body[$key] ?? false, FILTER_VALIDATE_BOOL);
    }

    private function clientIp(Request $request): ?string
    {
        return $this->ipMatcher->clientIpFromRequest($request->getServerParams());
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
