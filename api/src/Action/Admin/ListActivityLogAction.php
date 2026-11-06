<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/admin/activity-log
 * Query: ?action=&user_id=&entity_type=&entity_id=&limit=100&offset=0
 *
 * Vrací poslední záznamy z activity_log + JOIN users.
 * Admin only.
 */
final class ListActivityLogAction
{
    public function __construct(private readonly Connection $db) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $q = $request->getQueryParams();
        $where = [];
        $params = [];
        if (!empty($q['action'])) {
            $where[] = 'al.action = ?';
            $params[] = (string) $q['action'];
        }
        if (!empty($q['user_id'])) {
            $where[] = 'al.user_id = ?';
            $params[] = (int) $q['user_id'];
        }
        if (!empty($q['entity_type'])) {
            $where[] = 'al.entity_type = ?';
            $params[] = (string) $q['entity_type'];
        }
        if (!empty($q['entity_id'])) {
            $where[] = 'al.entity_id = ?';
            $params[] = (int) $q['entity_id'];
        }
        if (!empty($q['supplier_id'])) {
            $where[] = 'al.supplier_id = ?';
            $params[] = (int) $q['supplier_id'];
        }
        $limit = max(1, min(500, (int) ($q['limit'] ?? 100)));
        $offset = max(0, (int) ($q['offset'] ?? 0));

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $sql = "SELECT al.id, al.supplier_id, al.user_id, u.email AS user_email, u.name AS user_name,
                       al.action, al.entity_type, al.entity_id, al.payload, al.ip, al.created_at
                  FROM activity_log al
             LEFT JOIN users u ON u.id = al.user_id
                  $whereSql
              ORDER BY al.id DESC
                 LIMIT $limit OFFSET $offset";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Decode JSON payload + převed IP z packed binary na čitelnou string
        foreach ($rows as &$r) {
            if ($r['payload'] !== null) {
                $r['payload'] = json_decode((string) $r['payload'], true);
                // Starší záznamy ručního spuštění cronu nesou absolutní cesty serveru
                // (PHP, log). Řádky auditu jsou v hashovém řetězci a nepřepisují se,
                // proto se cesty skrývají až při čtení.
                if ($r['action'] === 'admin.cron.run_now' && is_array($r['payload'])) {
                    unset($r['payload']['php_bin']);
                    foreach (['log_file', 'diag'] as $key) {
                        if (is_string($r['payload'][$key] ?? null)) {
                            $r['payload'][$key] = RunCronJobAction::withoutPaths($r['payload'][$key]);
                        }
                    }
                }
            }
            if ($r['ip'] !== null && $r['ip'] !== '') {
                $r['ip'] = @inet_ntop($r['ip']) ?: null;
            } else {
                $r['ip'] = null;
            }
            $r['id'] = (int) $r['id'];
            $r['user_id'] = $r['user_id'] !== null ? (int) $r['user_id'] : null;
            $r['entity_id'] = $r['entity_id'] !== null ? (int) $r['entity_id'] : null;
            $r['supplier_id'] = $r['supplier_id'] !== null ? (int) $r['supplier_id'] : null;
        }

        // Total count for pagination
        $countSql = "SELECT COUNT(*) FROM activity_log al $whereSql";
        $countStmt = $this->db->pdo()->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Distinct actions for filter dropdown
        $actions = $this->db->pdo()->query(
            "SELECT action, COUNT(*) AS cnt FROM activity_log GROUP BY action ORDER BY action"
        )->fetchAll(\PDO::FETCH_ASSOC);

        return Json::ok($response, [
            'data'    => $rows,
            'total'   => $total,
            'limit'   => $limit,
            'offset'  => $offset,
            'actions' => $actions,
        ]);
    }
}
