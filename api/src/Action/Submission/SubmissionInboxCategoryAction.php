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
use MyInvoice\Service\Submission\SubmissionInboxCategoryService;
use MyInvoice\Service\Submission\SubmissionInboxService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Kategorie a pravidla příchozích zpráv datové schránky, ruční přeřazení
 * a stav přečtení.
 *
 * Stejná brána jako seznam zpráv ({@see SubmissionInboxAction}): oprávnění
 * `settings.signing`, jen webové rozhraní. Každá změna se zapíše do auditní
 * stopy firmy.
 */
final class SubmissionInboxCategoryAction
{
    public function __construct(
        private readonly SubmissionInboxCategoryService $categories,
        private readonly SubmissionInboxService $inbox,
        private readonly ActivityLogger $logger,
    ) {}

    public function overview(Request $request, Response $response): Response
    {
        return $this->run($request, $response, AccessLevel::READ, function (int $supplierId) use ($response): Response {
            return Json::ok($response, $this->categories->overview($supplierId));
        });
    }

    public function createCategory(Request $request, Response $response): Response
    {
        return $this->run($request, $response, AccessLevel::WRITE, function (int $supplierId) use ($request, $response): Response {
            $body = $this->body($request);
            $id = $this->categories->createCategory($supplierId, (string) ($body['name'] ?? ''));
            $this->audit($request, $supplierId, 'category_created', $id);

            return Json::ok($response, $this->categories->overview($supplierId), 201);
        });
    }

    /** @param array<string,string> $args */
    public function updateCategory(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, AccessLevel::WRITE, function (int $supplierId) use ($request, $response, $args): Response {
            $id = $this->positiveId($args);
            $name = $this->body($request)['name'] ?? null;
            $this->categories->renameCategory($supplierId, $id, is_string($name) ? $name : null);
            $this->audit($request, $supplierId, 'category_renamed', $id);

            return Json::ok($response, $this->categories->overview($supplierId));
        });
    }

    /** @param array<string,string> $args */
    public function deleteCategory(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, AccessLevel::WRITE, function (int $supplierId) use ($request, $response, $args): Response {
            $id = $this->positiveId($args);
            $this->categories->deleteCategory($supplierId, $id);
            $this->audit($request, $supplierId, 'category_deleted', $id);

            return Json::ok($response, $this->categories->overview($supplierId));
        });
    }

    public function createRule(Request $request, Response $response): Response
    {
        return $this->run($request, $response, AccessLevel::WRITE, function (int $supplierId) use ($request, $response): Response {
            $body = $this->body($request);
            $id = $this->categories->createRule(
                $supplierId,
                (int) ($body['category_id'] ?? 0),
                (string) ($body['match_field'] ?? ''),
                (string) ($body['pattern'] ?? ''),
                $this->userId($request),
            );
            $this->audit($request, $supplierId, 'rule_saved', $id);

            return Json::ok($response, $this->categories->overview($supplierId), 201);
        });
    }

    /** @param array<string,string> $args */
    public function updateRule(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, AccessLevel::WRITE, function (int $supplierId) use ($request, $response, $args): Response {
            $id = $this->positiveId($args);
            $this->categories->updateRule($supplierId, $id, (int) ($this->body($request)['category_id'] ?? 0));
            $this->audit($request, $supplierId, 'rule_saved', $id);

            return Json::ok($response, $this->categories->overview($supplierId));
        });
    }

    /** @param array<string,string> $args */
    public function deleteRule(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, AccessLevel::WRITE, function (int $supplierId) use ($request, $response, $args): Response {
            $id = $this->positiveId($args);
            $this->categories->deleteRule($supplierId, $id);
            $this->audit($request, $supplierId, 'rule_deleted', $id);

            return Json::ok($response, $this->categories->overview($supplierId));
        });
    }

    /** @param array<string,string> $args */
    public function assign(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, AccessLevel::WRITE, function (int $supplierId) use ($request, $response, $args): Response {
            $messageId = $this->positiveId($args);
            $message = $this->inbox->findById($supplierId, $messageId);
            if ($message === null) {
                throw new SubmissionChannelException('not_found', 'Zpráva nebyla nalezena.', 404);
            }
            $body = $this->body($request);
            $this->categories->assignMessage(
                $supplierId,
                $messageId,
                (int) ($body['category_id'] ?? 0),
                ($body['apply_to_sender'] ?? false) === true,
                $message['sender_box_id'] !== null ? (string) $message['sender_box_id'] : null,
                $this->userId($request),
            );
            $this->audit($request, $supplierId, 'message_assigned', $messageId, 'submission_inbox_message');

            return Json::ok($response, ['item' => $this->inbox->findById($supplierId, $messageId)]);
        });
    }

    /** @param array<string,string> $args */
    public function markRead(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, AccessLevel::WRITE, function (int $supplierId) use ($request, $response, $args): Response {
            $messageId = $this->positiveId($args);
            $read = ($this->body($request)['read'] ?? true) !== false;
            if (!$this->inbox->markRead($supplierId, $messageId, $read, $this->userId($request))) {
                throw new SubmissionChannelException('not_found', 'Zpráva nebyla nalezena.', 404);
            }

            return Json::ok($response, ['item' => $this->inbox->findById($supplierId, $messageId)]);
        });
    }

    /** @param callable(int):Response $work */
    private function run(Request $request, Response $response, AccessLevel $level, callable $work): Response
    {
        if (!RequestAuthorization::allows($request, 'settings.signing', $level)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění.', 403);
        }
        if ($this->userId($request) <= 0) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response, 'Datová schránka se obsluhuje jen z webového rozhraní.');
        }
        if (!$this->categories->isAvailable()) {
            return Json::error($response, 'not_available', 'Kategorie zpráv nejsou v databázi k dispozici (chybí migrace 1982).', 409);
        }
        try {
            return $work(SupplierGuard::currentId($request));
        } catch (SubmissionChannelException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        return (array) ($request->getParsedBody() ?? []);
    }

    /** @param array<string,string> $args */
    private function positiveId(array $args): int
    {
        $value = (string) ($args['id'] ?? '');
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new \InvalidArgumentException('ID musí být kladné celé číslo.');
        }
        return (int) $value;
    }

    private function audit(
        Request $request,
        int $supplierId,
        string $operation,
        int $entityId,
        string $entity = 'submission_inbox_category',
    ): void {
        $this->logger->log(
            'databox.inbox_' . $operation,
            $this->userId($request),
            $entity,
            $entityId,
            ['operation' => $operation],
            null,
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
    }

    private function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);

        return (int) ($user['id'] ?? 0);
    }
}
