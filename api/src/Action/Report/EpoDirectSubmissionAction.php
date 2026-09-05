<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\EpoSigningCredentialRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Epo\EpoDirectSubmissionService;
use MyInvoice\Service\Epo\EpoSigningCredentialService;
use MyInvoice\Service\Epo\EpoStepUpService;
use MyInvoice\Service\Epo\EpoSubmissionException;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

final class EpoDirectSubmissionAction
{
    public function __construct(
        private readonly EpoSigningCredentialRepository $credentials,
        private readonly EpoSigningCredentialService $credentialService,
        private readonly EpoDirectSubmissionService $submissions,
        private readonly EpoStepUpService $stepUp,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function credentials(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response)) !== null) {
            return $denied;
        }
        return Json::ok($response, $this->credentialService->listOwnedForSupplier(
            $this->userId($request),
            SupplierGuard::currentId($request),
        ));
    }

    public function uploadCredential(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response)) !== null) {
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
                    'Vyberte soubor certifikátu P12 nebo PFX.',
                    400,
                );
            }
            $name = strtolower((string) $file->getClientFilename());
            if (!preg_match('/\.(p12|pfx)$/', $name)) {
                throw new EpoSubmissionException(
                    'invalid_certificate_file',
                    'Certifikát musí být ve formátu P12 nebo PFX.',
                    415,
                );
            }
            $bytes = $file->getStream()->getContents();
            $credential = $this->credentialService->import(
                $userId,
                $supplierId,
                (string) ($body['label'] ?? ''),
                $bytes,
                (string) ($body['pfx_password'] ?? ''),
            );
            $this->audit(
                $request,
                'report.epo_credential_created',
                $userId,
                $supplierId,
                (int) $credential['id'],
                ['fingerprint' => $credential['fingerprint_sha256']],
                'epo_signing_credential',
            );
            return Json::ok($response, $credential, 201);
        } catch (EpoSubmissionException $e) {
            return $this->error($response, $e);
        }
    }

    public function setCredentialSupplier(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($denied = $this->guard($request, $response)) !== null) {
            return $denied;
        }
        $userId = $this->userId($request);
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $this->stepUp->verify($request, $userId, $body, 'credential_supplier_access');
            $enabled = filter_var(
                $body['enabled'] ?? false,
                FILTER_VALIDATE_BOOL,
            );
            $credentialId = (int) ($args['credentialId'] ?? 0);
            if (!$enabled && $this->credentials->linkedSupplierProfileCount(
                $credentialId,
                $userId,
                $supplierId,
            ) > 0) {
                throw new EpoSubmissionException(
                    'credential_in_use',
                    'Certifikát používá podpisový profil této firmy. Nejdříve jej od profilu odpojte.',
                    409,
                );
            }
            if (!$this->credentials->setSupplierEnabled(
                $credentialId,
                $userId,
                $supplierId,
                $enabled,
                $userId,
            )) {
                throw new EpoSubmissionException(
                    'credential_not_found',
                    'Certifikát nebyl nalezen.',
                    404,
                );
            }
            $this->audit(
                $request,
                'report.epo_credential_supplier_access',
                $userId,
                $supplierId,
                $credentialId,
                ['enabled' => $enabled],
                'epo_signing_credential',
            );
            return Json::ok($response, [
                'credentials' => $this->credentialService->listOwnedForSupplier($userId, $supplierId),
            ]);
        } catch (EpoSubmissionException $e) {
            return $this->error($response, $e);
        }
    }

    public function deleteCredential(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($denied = $this->guard($request, $response)) !== null) {
            return $denied;
        }
        $userId = $this->userId($request);
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $this->stepUp->verify($request, $userId, $body, 'credential_delete');
            $credentialId = (int) ($args['credentialId'] ?? 0);
            $linkedProfiles = $this->credentials->linkedProfileCount($credentialId, $userId);
            if ($linkedProfiles > 0) {
                throw new EpoSubmissionException(
                    'credential_in_use',
                    'Certifikát používá podpisový profil. Nejdříve jej od profilu odpojte.',
                    409,
                    ['linked_profiles_count' => $linkedProfiles],
                );
            }
            if (!$this->credentials->deleteOwned($credentialId, $userId)) {
                throw new EpoSubmissionException(
                    'credential_not_found',
                    'Certifikát nebyl nalezen.',
                    404,
                );
            }
            $this->audit(
                $request,
                'report.epo_credential_deleted',
                $userId,
                $supplierId,
                $credentialId,
                [],
                'epo_signing_credential',
            );
            return Json::ok($response, ['deleted' => true]);
        } catch (EpoSubmissionException $e) {
            return $this->error($response, $e);
        }
    }

    public function test(Request $request, Response $response, array $args): Response
    {
        return $this->submissionOperation(
            $request,
            $response,
            $args,
            fn (int $submissionId, int $supplierId, int $userId, array $body): array =>
                $this->submissions->test(
                    $submissionId,
                    $supplierId,
                    $userId,
                    (int) ($body['credential_id'] ?? 0),
                ),
            'report.epo_direct_test',
            'direct_test',
        );
    }

    public function submit(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response)) !== null) {
            return $denied;
        }
        $userId = $this->userId($request);
        $supplierId = SupplierGuard::currentId($request);
        $submissionId = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $this->stepUp->verify($request, $userId, $body, 'direct_submission');
            $result = $this->submissions->submit(
                $submissionId,
                $supplierId,
                $userId,
                (int) ($body['attempt_id'] ?? 0),
            );
            $this->audit(
                $request,
                'report.epo_direct_submitted',
                $userId,
                $supplierId,
                $submissionId,
                [
                    'attempt_id' => $result['attempt_id'] ?? null,
                    'status' => $result['status'] ?? null,
                    'reference' => $result['reference'] ?? null,
                    'environment' => $result['environment'] ?? null,
                ],
            );
            return Json::ok($response, $result);
        } catch (EpoSubmissionException $e) {
            $this->auditFailure($request, $userId, $supplierId, $submissionId, $e);
            return $this->error($response, $e);
        }
    }

    public function refreshStatus(Request $request, Response $response, array $args): Response
    {
        return $this->submissionOperation(
            $request,
            $response,
            $args,
            fn (int $submissionId, int $supplierId, int $userId, array $body): array =>
                $this->submissions->refreshStatus(
                    $submissionId,
                    $supplierId,
                    $userId,
                    (int) ($body['attempt_id'] ?? 0),
                ),
            'report.epo_direct_status_refreshed',
        );
    }

    public function recoverConfirmation(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response)) !== null) {
            return $denied;
        }
        $userId = $this->userId($request);
        $supplierId = SupplierGuard::currentId($request);
        $submissionId = (int) ($args['id'] ?? 0);
        $attemptId = (int) ($args['attemptId'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $this->stepUp->verify($request, $userId, $body, 'direct_confirmation_recovery');
            $file = $request->getUploadedFiles()['file'] ?? null;
            if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
                throw new EpoSubmissionException(
                    'confirmation_file_required',
                    'Vyberte potvrzení EPO ve formátu P7S nebo P7M.',
                    400,
                );
            }
            $name = strtolower((string) $file->getClientFilename());
            if (!preg_match('/\.(p7s|p7m)$/', $name)) {
                throw new EpoSubmissionException(
                    'invalid_confirmation_file',
                    'Potvrzení musí být ve formátu P7S nebo P7M.',
                    415,
                );
            }
            $size = $file->getSize();
            if ($size !== null && $size > 10 * 1024 * 1024) {
                throw new EpoSubmissionException(
                    'confirmation_file_too_large',
                    'Potvrzení může mít nejvýše 10 MB.',
                    413,
                );
            }
            $result = $this->submissions->recoverConfirmation(
                $submissionId,
                $supplierId,
                $userId,
                $attemptId,
                $file->getStream()->getContents(),
            );
            $this->audit(
                $request,
                'report.epo_direct_confirmation_recovered',
                $userId,
                $supplierId,
                $submissionId,
                ['attempt_id' => $attemptId, 'status' => $result['status'] ?? null],
            );
            return Json::ok($response, $result);
        } catch (EpoSubmissionException $e) {
            $this->auditFailure($request, $userId, $supplierId, $submissionId, $e);
            return $this->error($response, $e);
        }
    }

    /**
     * Odhalení hesla pro dotaz na stav podání.
     *
     * Heslo vydá EPO jednou, v dodejce, a bez něj se na Daňovém portálu nedá dohledat
     * stav ani stáhnout oficiální opis. Aplikace ho jinak drží jen zašifrované, takže
     * jediná cesta ven vede tudy: vědomá akce se step-up ověřením a záznamem v auditu.
     * Odpověď se proto nesmí cachovat.
     */
    public function revealStatePassword(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response)) !== null) {
            return $denied;
        }
        $userId = $this->userId($request);
        $supplierId = SupplierGuard::currentId($request);
        $submissionId = (int) ($args['id'] ?? 0);
        $attemptId = (int) ($args['attemptId'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $this->stepUp->verify($request, $userId, $body, 'state_password_reveal');
            $result = $this->submissions->revealStatePassword($submissionId, $supplierId, $attemptId);
            $this->audit(
                $request,
                'report.epo_state_password_revealed',
                $userId,
                $supplierId,
                $submissionId,
                // Samotné heslo se do auditu NEZAPISUJE — stačí, že je vidět KDO a KDY.
                ['attempt_id' => $attemptId, 'reference' => $result['reference']],
            );
            return Json::ok($response, $result)
                ->withHeader('Cache-Control', 'private, no-store');
        } catch (EpoSubmissionException $e) {
            $this->auditFailure($request, $userId, $supplierId, $submissionId, $e);
            return $this->error($response, $e);
        }
    }

    public function resolveAsNotSubmitted(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response)) !== null) {
            return $denied;
        }
        $userId = $this->userId($request);
        $supplierId = SupplierGuard::currentId($request);
        $submissionId = (int) ($args['id'] ?? 0);
        $attemptId = (int) ($args['attemptId'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $this->stepUp->verify($request, $userId, $body, 'direct_submission_resolution');
            if (($body['verified_not_submitted'] ?? false) !== true) {
                throw new EpoSubmissionException(
                    'resolution_confirmation_required',
                    'Potvrďte, že jste výsledek ověřili přímo v EPO.',
                    400,
                );
            }
            $result = $this->submissions->resolveAsNotSubmitted(
                $submissionId,
                $supplierId,
                $userId,
                $attemptId,
                (string) ($body['note'] ?? ''),
            );
            $this->audit(
                $request,
                'report.epo_direct_resolved_not_submitted',
                $userId,
                $supplierId,
                $submissionId,
                ['attempt_id' => $attemptId],
            );
            return Json::ok($response, $result);
        } catch (EpoSubmissionException $e) {
            $this->auditFailure($request, $userId, $supplierId, $submissionId, $e);
            return $this->error($response, $e);
        }
    }

    /**
     * @param callable(int,int,int,array<string,mixed>):array<string,mixed> $operation
     */
    private function submissionOperation(
        Request $request,
        Response $response,
        array $args,
        callable $operation,
        string $event,
        ?string $stepUpPurpose = null,
    ): Response {
        if (($denied = $this->guard($request, $response)) !== null) {
            return $denied;
        }
        $userId = $this->userId($request);
        $supplierId = SupplierGuard::currentId($request);
        $submissionId = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            if ($stepUpPurpose !== null) {
                $this->stepUp->verify($request, $userId, $body, $stepUpPurpose);
            }
            $result = $operation($submissionId, $supplierId, $userId, $body);
            $this->audit(
                $request,
                $event,
                $userId,
                $supplierId,
                $submissionId,
                [
                    'attempt_id' => $result['attempt_id'] ?? null,
                    'status' => $result['status'] ?? null,
                    'passed' => $result['passed'] ?? null,
                    'environment' => $result['environment'] ?? null,
                ],
            );
            return Json::ok($response, $result);
        } catch (EpoSubmissionException $e) {
            $this->auditFailure($request, $userId, $supplierId, $submissionId, $e);
            return $this->error($response, $e);
        } catch (\MyInvoice\Service\Epo\EpoException $e) {
            $wrapped = new EpoSubmissionException(
                $e->errorCode,
                $e->getMessage(),
                $e->httpStatus,
            );
            $this->auditFailure($request, $userId, $supplierId, $submissionId, $wrapped);
            return $this->error($response, $wrapped);
        }
    }

    private function guard(Request $request, Response $response): ?Response
    {
        if (!RequestAuthorization::allows($request, 'reports.submit', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění.', 403);
        }
        if ($this->userId($request) <= 0) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response, 'Přímé EPO operace lze provádět jen z webového rozhraní.');
        }
        return null;
    }

    private function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (int) ($user['id'] ?? 0);
    }

    private function error(Response $response, EpoSubmissionException $error): Response
    {
        return Json::error(
            $response,
            $error->errorCode,
            $error->getMessage(),
            $error->httpStatus,
            $error->details,
        );
    }

    /** @param array<string,mixed> $details */
    private function audit(
        Request $request,
        string $event,
        int $userId,
        int $supplierId,
        int $entityId,
        array $details,
        string $entityType = 'tax_submission',
    ): void {
        $this->logger->log(
            $event,
            $userId,
            $entityType,
            $entityId,
            $details,
            $this->clientIp($request),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
    }

    private function auditFailure(
        Request $request,
        int $userId,
        int $supplierId,
        int $submissionId,
        EpoSubmissionException $error,
    ): void {
        $this->audit(
            $request,
            'report.epo_direct_failed',
            $userId,
            $supplierId,
            $submissionId,
            ['error_code' => $error->errorCode],
        );
    }

    private function clientIp(Request $request): ?string
    {
        return $this->ipMatcher->clientIpFromRequest($request->getServerParams());
    }
}
