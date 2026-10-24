<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\TechnicalSupplierAccessRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Přiřazení technického rozsahu provádí pouze superadmin z vlastní webové relace. */
final class TechnicalSupplierAccessAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly TechnicalSupplierAccessRepository $access,
        private readonly ActivityLogger $audit,
    ) {}

    public function get(Request $request, Response $response, array $args): Response
    {
        if (!$this->allowed($request)) return Json::error($response, 'forbidden_permission', 'Pro tuto akci nemáš oprávnění.', 403);
        $id = (int) ($args['id'] ?? 0);
        if (!$this->isTechnicalUser($id)) return Json::error($response, 'not_found', 'Správce instance nenalezen.', 404);
        return Json::ok($response, $this->scopeResponse($id));
    }

    public function put(Request $request, Response $response, array $args): Response
    {
        if (!$this->allowed($request)) return Json::error($response, 'forbidden_permission', 'Pro tuto akci nemáš oprávnění.', 403);
        $id = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        if (array_diff(array_keys($body), ['all_suppliers', 'supplier_ids']) !== []
            || !is_bool($body['all_suppliers'] ?? null) || !is_array($body['supplier_ids'] ?? null)
            || !array_is_list($body['supplier_ids'])) {
            return Json::error($response, 'validation_failed', 'Zadejte all_suppliers a seznam supplier_ids.', 400);
        }
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->access->replaceForUser($id, $body['all_suppliers'], $body['supplier_ids']);
            $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
            $this->audit->log('instance_admin.scope_updated', (int) ($user['id'] ?? 0), 'user', $id,
                ['all_suppliers' => $body['all_suppliers'], 'supplier_ids' => $body['supplier_ids']]);
            $pdo->commit();
        } catch (\InvalidArgumentException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return Json::ok($response, $this->scopeResponse($id));
    }

    private function scopeResponse(int $id): array
    {
        $scope = $this->access->scopeForUser($id);
        $stmt = $this->db->pdo()->prepare("SELECT s.id AS supplier_id,
            COALESCE(NULLIF(s.display_name, ''), s.company_name) AS name
            FROM user_technical_suppliers t JOIN supplier s ON s.id = t.supplier_id
            WHERE t.user_id = ? ORDER BY t.supplier_id");
        $stmt->execute([$id]);
        $scope['suppliers'] = array_map(static fn (array $row): array => [
            'supplier_id' => (int) $row['supplier_id'], 'name' => (string) $row['name'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
        return $scope;
    }

    private function allowed(Request $request): bool
    {
        return RequestAuthorization::isSessionAuth($request) && RequestAuthorization::isSuperadmin($request);
    }

    private function isTechnicalUser(int $id): bool
    {
        $stmt = $this->db->pdo()->prepare("SELECT 1 FROM users u JOIN roles r ON r.id = u.role_id
            WHERE u.id = ?
              AND r.role_type = 'instance_admin' AND r.system_key = 'instance_admin'");
        $stmt->execute([$id]);
        return $stmt->fetchColumn() !== false;
    }
}
