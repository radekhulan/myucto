<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\DocumentFolderRepository;
use MyInvoice\Repository\TaxSubmissionEpoRepository;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\Epo\EpoAssistedConfirmationService;
use MyInvoice\Service\Epo\EpoException;
use MyInvoice\Service\Epo\EpoSubmissionException;
use MyInvoice\Service\Epo\EpoSubmissionService;
use MyInvoice\Service\Epo\TaxSubmissionDocumentService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Stream;

final class TaxSubmissionEpoAction
{
    private const MAX_FILES = 20;

    public function __construct(
        private readonly Connection $db,
        private readonly TaxSubmissionRepository $submissions,
        private readonly TaxSubmissionEpoRepository $epo,
        private readonly EpoSubmissionService $service,
        private readonly TaxSubmissionDocumentService $documents,
        private readonly EpoAssistedConfirmationService $assisted,
        private readonly DocumentStorage $storage,
        private readonly DocumentFolderRepository $folders,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Config $config,
    ) {}

    public function settings(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        return Json::ok($response, RequestAuthorization::isClientType($request)
            ? ['epo_environment' => $this->environment()]
            : $this->settingsPayload($supplierId));
    }

    public function updateSettings(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::canManageTaxSubmissions($request)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $current = $this->epo->settings($supplierId);
        $sent = [];
        foreach (['vat_root_folder_id', 'income_tax_root_folder_id'] as $key) {
            if (array_key_exists($key, $body)) {
                $sent[$key] = $this->nullableInt($body[$key]);
            }
        }
        $vatFolder = array_key_exists('vat_root_folder_id', $sent)
            ? $sent['vat_root_folder_id']
            : $current['vat_root_folder_id'];
        $incomeFolder = array_key_exists('income_tax_root_folder_id', $sent)
            ? $sent['income_tax_root_folder_id']
            : $current['income_tax_root_folder_id'];
        foreach ($sent as $folderId) {
            if ($folderId !== null && $this->folders->find($folderId, $supplierId) === null) {
                return Json::error(
                    $response,
                    'folder_not_found',
                    'Vybraná složka nebyla nalezena.',
                    404,
                );
            }
        }

        $userId = $this->userId($request);
        $this->epo->saveSettings($supplierId, $vatFolder, $incomeFolder, $userId);
        $this->logger->log(
            'report.epo_settings_updated',
            $userId,
            'supplier',
            $supplierId,
            [
                'vat_root_folder_id' => $vatFolder,
                'income_tax_root_folder_id' => $incomeFolder,
            ],
            $this->clientIp($request),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
        return Json::ok($response, $this->settingsPayload($supplierId));
    }

    public function handoff(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $submissionId = (int) ($args['id'] ?? 0);
        if (($denied = $this->deniedSubmission($request, $response, $submissionId, $supplierId)) !== null) {
            return $denied;
        }
        $userId = $this->userId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $replaceActive = filter_var(
            $body['replace_active'] ?? false,
            FILTER_VALIDATE_BOOL,
        );

        try {
            $result = $this->service->createHandoff(
                $submissionId,
                $supplierId,
                $userId,
                $replaceActive,
            );
        } catch (EpoException|EpoSubmissionException $e) {
            $this->logger->log(
                'report.epo_handoff_failed',
                $userId,
                'tax_submission',
                $submissionId,
                [
                    'error_code' => $e->errorCode,
                    'http_status' => $e->httpStatus,
                    'remote_http_status' => $e instanceof EpoException ? $e->remoteHttpStatus : null,
                ],
                $this->clientIp($request),
                $request->getHeaderLine('User-Agent'),
                $supplierId,
            );
            return Json::error(
                $response,
                $e->errorCode,
                $e->getMessage(),
                $e->httpStatus,
                $e instanceof EpoSubmissionException ? $e->details : [],
            );
        }

        // URL záměrně NENÍ součástí auditu. Je pouze v této no-store odpovědi.
        $this->logger->log(
            'report.epo_handoff_created',
            $userId,
            'tax_submission',
            $submissionId,
            [
                'attempt_id' => $result['attempt_id'],
                'expires_at' => $result['expires_at'],
                'archive_folder_id' => $result['archive_folder_id'],
                'source_document_id' => $result['source_document_id'],
                'replaced_active' => $replaceActive,
                'environment' => $result['environment'],
            ],
            $this->clientIp($request),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return Json::ok($response, $result, 201);
    }

    public function uploadArtifacts(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $submissionId = (int) ($args['id'] ?? 0);
        $submission = $this->submissions->find($submissionId, $supplierId);
        if ($submission === null) {
            return Json::error($response, 'not_found', 'Podání nebylo nalezeno.', 404);
        }

        if (!RequestAuthorization::canAccessTaxSubmission($request, $submission)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění.', 403);
        }

        $uploaded = $request->getUploadedFiles();
        $files = isset($uploaded['file'])
            ? (is_array($uploaded['file']) ? array_values($uploaded['file']) : [$uploaded['file']])
            : [];
        if ($files === []) {
            return Json::error($response, 'no_file', 'Nebyl vybrán žádný soubor.', 400);
        }
        if (count($files) > self::MAX_FILES) {
            return Json::error($response, 'too_many_files', 'Najednou lze nahrát nejvýše 20 souborů.', 413);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $attemptId = $this->nullableInt($body['attempt_id'] ?? null);
        if (
            $attemptId !== null
            && !$this->epo->attemptBelongsToSubmission($attemptId, $submissionId, $supplierId)
        ) {
            return Json::error($response, 'attempt_not_found', 'Pokus o předání nebyl nalezen.', 404);
        }
        $userId = $this->userId($request);
        // Prostředí se bere z pokusu, ke kterému se soubory přikládají — jen tak se
        // zkušební dodejka („Testovací zařízení – nelze učinit platné podání") porovná
        // proti správné identitě pečeti. Bez pokusu platí současné nastavení instance.
        $environment = $this->attemptEnvironment($submissionId, $supplierId, $attemptId);
        $source = null;
        $created = [];
        $errors = [];
        $confirmation = null;
        $hint = null;
        foreach ($files as $file) {
            if (!$file instanceof UploadedFileInterface) {
                continue;
            }
            $originalName = trim((string) $file->getClientFilename());
            if ($file->getError() !== UPLOAD_ERR_OK || $originalName === '') {
                $errors[] = ['name' => $originalName ?: '?', 'code' => 'upload_error'];
                $this->logArtifactFailure(
                    $request,
                    $supplierId,
                    $submissionId,
                    $attemptId,
                    $userId,
                    'upload_error',
                );
                continue;
            }

            $tmp = $this->storage->tmpPath($supplierId);
            $ownsTransaction = false;
            try {
                $file->moveTo($tmp);
                $this->documents->validateArtifactFile($tmp, $originalName);
                $pdo = $this->db->pdo();
                $ownsTransaction = !$pdo->inTransaction();
                if ($ownsTransaction) {
                    $pdo->beginTransaction();
                }
                if ($source === null) {
                    $source = $this->documents->ensureSourceXml(
                        $submission,
                        $supplierId,
                        $attemptId,
                        $userId,
                    );
                }
                $result = $this->assisted->ingest(
                    $tmp,
                    $originalName,
                    $submission,
                    $supplierId,
                    $attemptId,
                    $userId,
                    $environment,
                );
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                $created[] = $result['artifact'];
                // Dodejka má přednost před tiskem: PDF opis je jen odečtený text,
                // zatímco P7S je podepsaný důkaz. Když přijde obojí, předvyplní se
                // podle dodejky.
                if (is_array($result['confirmation'] ?? null)) {
                    $confirmation = $result['confirmation'];
                }
                if ($hint === null && is_array($result['hint'] ?? null)) {
                    $hint = $result['hint'];
                }
                $this->logger->log(
                    'report.epo_artifact_uploaded',
                    $userId,
                    'tax_submission',
                    $submissionId,
                    [
                        'attempt_id' => $attemptId,
                        'document_id' => $result['artifact']['document_id'] ?? null,
                        'artifact_kind' => $result['artifact']['artifact_kind'] ?? null,
                        'verification_status' => $result['artifact']['verification_status'] ?? null,
                        // Heslo pro dotaz na stav se do auditu NEZAPISUJE — stačí, že je
                        // vidět, jestli se ho podařilo převzít.
                        'reference' => $result['confirmation']['reference'] ?? null,
                        'state_password_stored' => $result['confirmation']['status_query_available'] ?? null,
                    ],
                    $this->clientIp($request),
                    $request->getHeaderLine('User-Agent'),
                    $supplierId,
                );
            } catch (EpoSubmissionException $e) {
                @unlink($tmp);
                if ($ownsTransaction && $this->db->pdo()->inTransaction()) {
                    $this->db->pdo()->rollBack();
                    $source = null;
                }
                $errors[] = ['name' => $originalName, 'code' => $e->errorCode, 'message' => $e->getMessage()];
                $this->logArtifactFailure(
                    $request,
                    $supplierId,
                    $submissionId,
                    $attemptId,
                    $userId,
                    $e->errorCode,
                );
            } catch (\Throwable) {
                @unlink($tmp);
                if ($ownsTransaction && $this->db->pdo()->inTransaction()) {
                    $this->db->pdo()->rollBack();
                    $source = null;
                }
                $errors[] = ['name' => $originalName, 'code' => 'upload_failed'];
                $this->logArtifactFailure(
                    $request,
                    $supplierId,
                    $submissionId,
                    $attemptId,
                    $userId,
                    'upload_failed',
                );
            }
        }

        if ($created === [] && $errors !== []) {
            return Json::error(
                $response,
                'upload_failed',
                'Žádný soubor se nepodařilo nahrát.',
                400,
                ['errors' => $errors],
            );
        }

        return Json::ok($response, [
            'created' => $created,
            'errors' => $errors,
            'source_artifact' => $source,
            // Co se z nahraných souborů přečetlo. `confirmation` pochází z ověřené
            // dodejky, `hint` jen z textu PDF opisu — UI podle toho předvyplní
            // „Označit jako podané" a rozliší, čím si je aplikace jistá.
            'confirmation' => $confirmation,
            'hint' => $hint,
            'artifacts' => $this->epo->artifacts($submissionId, $supplierId),
            'attempts' => $this->epo->attempts($submissionId, $supplierId),
        ]);
    }

    public function downloadArtifact(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::canExportTaxSubmission($request)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění exportovat podání.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        if (($denied = $this->deniedSubmission($request, $response, (int) ($args['id'] ?? 0), $supplierId)) !== null) {
            return $denied;
        }
        $artifact = $this->epo->artifact(
            (int) ($args['artifactId'] ?? 0),
            (int) ($args['id'] ?? 0),
            $supplierId,
        );
        if ($artifact === null) {
            return Json::error($response, 'not_found', 'Soubor nebyl nalezen.', 404);
        }
        $path = $this->storage->pathFor(
            $supplierId,
            (string) $artifact['document_sha256'],
            (string) $artifact['filename'],
        );
        if (!is_file($path)) {
            return Json::error($response, 'not_found', 'Soubor nebyl nalezen na disku.', 404);
        }

        $safeName = preg_replace('/[\r\n"\\\\]/', '_', (string) $artifact['original_name']);
        $stream = new Stream(fopen($path, 'rb'));
        return $response
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $safeName . '"')
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->withHeader('Cache-Control', 'private, no-store')
            ->withBody($stream);
    }

    private function guard(Request $request, Response $response, AccessLevel $level): ?Response
    {
        $permission = $level === AccessLevel::WRITE ? 'reports.submit' : 'reports';
        if (!($level === AccessLevel::READ
            ? RequestAuthorization::canReadTaxSubmissions($request)
            : RequestAuthorization::allows($request, $permission, $level)
        )) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění.', 403);
        }
        return null;
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return isset($user['id']) ? (int) $user['id'] : null;
    }

    /** @return array<string,mixed> */
    private function settingsPayload(int $supplierId): array
    {
        return [
            ...$this->epo->settings($supplierId),
            'folders' => $this->folders->listAll($supplierId),
            'epo_environment' => $this->environment(),
        ];
    }

    private function environment(): string
    {
        return $this->config->get('epo_test', false) ? 'test' : 'production';
    }

    private function deniedSubmission(Request $request, Response $response, int $id, int $supplierId): ?Response
    {
        $submission = $this->submissions->find($id, $supplierId);
        if ($submission === null) {
            return Json::error($response, 'not_found', 'Podání nebylo nalezeno.', 404);
        }
        return RequestAuthorization::canAccessTaxSubmission($request, $submission)
            ? null
            : Json::error($response, 'forbidden', 'Nemáte oprávnění.', 403);
    }

    private function clientIp(Request $request): ?string
    {
        return $this->ipMatcher->clientIpFromRequest($request->getServerParams());
    }

    private function attemptEnvironment(int $submissionId, int $supplierId, ?int $attemptId): string
    {
        if ($attemptId !== null) {
            foreach ($this->epo->attempts($submissionId, $supplierId) as $attempt) {
                if ((int) $attempt['id'] === $attemptId) {
                    $environment = strtolower(trim((string) ($attempt['epo_environment'] ?? '')));
                    if ($environment === 'test' || $environment === 'production') {
                        return $environment;
                    }
                }
            }
        }
        // Musí odpovídat tomu, co se skutečně použije v Bootstrapu — jinak by UI
        // hlásilo „production" a podávalo se do zkušebního prostředí.
        return (new \MyInvoice\Service\System\ManagedModeGuard($this->config))->effectiveFlag(
            \MyInvoice\Service\System\ManagedModeGuard::KEY_EPO_TEST,
            (bool) $this->config->get('epo_test', false),
        ) ? 'test' : 'production';
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }
        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    private function logArtifactFailure(
        Request $request,
        int $supplierId,
        int $submissionId,
        ?int $attemptId,
        ?int $userId,
        string $errorCode,
    ): void {
        $this->logger->log(
            'report.epo_artifact_rejected',
            $userId,
            'tax_submission',
            $submissionId,
            [
                'attempt_id' => $attemptId,
                'error_code' => $errorCode,
            ],
            $this->clientIp($request),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
    }
}
