<?php

declare(strict_types=1);

namespace MyInvoice\Action\Codebook;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ExpenseCategoryRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Expense categories CRUD:
 *   GET    /api/expense-categories             — list (zahrne archived?)
 *   POST   /api/expense-categories             — create
 *   PUT    /api/expense-categories/{id}        — update
 *   DELETE /api/expense-categories/{id}        — hard pokud neused, jinak soft (archived=1)
 *
 * List endpoint je accessible všem (pro picker v Editor), CUD jen admin/accountant.
 */
final class ExpenseCategoriesAction
{
    public function __construct(
        private readonly ExpenseCategoryRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $includeArchived = !empty($request->getQueryParams()['include_archived']);
        return Json::ok($response, $this->repo->listForTenant($supplierId, $includeArchived));
    }

    public function create(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'settings.company.write', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $err = $this->validate($body);
        if ($err !== null) return Json::error($response, 'validation_failed', $err, 400);

        try {
            $id = $this->repo->create($supplierId, $body);
        } catch (\PDOException $e) {
            // UNIQUE (supplier_id, code) violation
            if (str_contains($e->getMessage(), 'Duplicate')) {
                return Json::error($response, 'duplicate_code', "Kód '{$body['code']}' už existuje.", 409);
            }
            return Json::internalError($response, 'create_failed', $e->getMessage(), 500);
        }

        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('expense_category.created', $userId, 'expense_category', $id, $body,
            $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id, $supplierId), 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'settings.company.write', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        $existing = $this->repo->find($id, $supplierId);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Kategorie nenalezena.', 404);
        }
        // Vynechaný klíč = uložená hodnota (issue #113).
        $merged = array_replace(
            array_intersect_key($existing, array_flip(['code', 'label', 'fixed_or_var', 'display_order', 'archived'])),
            $body,
        );
        $err = $this->validate($merged);
        if ($err !== null) return Json::error($response, 'validation_failed', $err, 400);

        try {
            $this->repo->update($id, $supplierId, $merged);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                return Json::error($response, 'duplicate_code', "Kód '{$merged['code']}' už existuje.", 409);
            }
            return Json::internalError($response, 'update_failed', $e->getMessage(), 500);
        }
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('expense_category.updated', $userId, 'expense_category', $id, $body,
            $ip, $request->getHeaderLine('User-Agent'));
        return Json::ok($response, $this->repo->find($id, $supplierId));
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'settings.company.write', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->repo->find($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Kategorie nenalezena.', 404);
        }
        $result = $this->repo->delete($id, $supplierId);
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('expense_category.' . ($result['deleted'] ? 'deleted' : 'archived'),
            $userId, 'expense_category', $id, $result,
            $ip, $request->getHeaderLine('User-Agent'));
        return Json::ok($response, $result);
    }

    private function validate(array $body): ?string
    {
        $code = trim((string) ($body['code'] ?? ''));
        $label = trim((string) ($body['label'] ?? ''));
        if ($code === '') return 'Kód je povinný.';
        if (!preg_match('/^[a-z0-9_-]{1,20}$/i', $code)) {
            return 'Kód: povolené znaky A-Z, a-z, 0-9, _, - (max 20).';
        }
        if ($label === '' || strlen($label) > 100) {
            return 'Label povinný, max 100 znaků.';
        }
        $fov = $body['fixed_or_var'] ?? 'variable';
        if (!in_array($fov, ['fixed', 'variable'], true)) {
            return 'fixed_or_var musí být "fixed" nebo "variable".';
        }
        return null;
    }
}
