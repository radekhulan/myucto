<?php

declare(strict_types=1);

namespace MyInvoice\Action\Submission;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\DocumentFolderRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\SubmissionInboxStorageSettingsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SubmissionInboxStorageSettingsAction
{
    public function __construct(
        private readonly SubmissionInboxStorageSettingsService $settings,
        private readonly DocumentFolderRepository $folders,
        private readonly ActivityLogger $logger,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        return Json::ok($response, [
            'items' => $this->settings->list($supplierId),
            'folders' => $this->folders->listAll($supplierId),
        ]);
    }

    /** @param array<string,string> $args */
    public function save(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        if (!isset($body['row_version']) || !is_numeric($body['row_version']) || (int) $body['row_version'] < 0) {
            return Json::error($response, 'row_version_required', 'Chybí verze nastavení.', 400);
        }
        if (!array_key_exists('base_folder_id', $body)) {
            return Json::error($response, 'base_folder_id_required', 'Chybí cílová složka archivu.', 400);
        }
        $expectedVersion = (int) $body['row_version'];
        $supplierId = SupplierGuard::currentId($request);
        $environment = (string) ($args['environment'] ?? '');
        $userId = $this->userId($request);

        try {
            if ($body['base_folder_id'] === null || $body['base_folder_id'] === '') {
                $this->settings->clear($supplierId, $environment, $expectedVersion);
                $item = null;
            } elseif (!is_numeric($body['base_folder_id']) || (int) $body['base_folder_id'] <= 0) {
                return Json::error($response, 'folder_id_invalid', 'Vyberte platnou cílovou složku.', 400);
            } else {
                $item = $this->settings->save(
                    $supplierId,
                    $environment,
                    (int) $body['base_folder_id'],
                    $expectedVersion,
                    $userId,
                );
            }
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        $this->logger->log(
            'databox_inbox_storage_settings_save',
            $userId,
            'databox',
            $supplierId,
            ['environment' => $environment, 'base_folder_id' => $item['base_folder_id'] ?? null],
            null,
            null,
            $supplierId,
        );
        return Json::ok($response, ['item' => $item]);
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
                'Nastavení datové schránky lze spravovat jen z webového rozhraní.',
                403,
            );
        }
        return null;
    }

    private function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return isset($user['id']) ? (int) $user['id'] : 0;
    }
}
