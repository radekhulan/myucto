<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\RequestAuthorization;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/auth/api-me — connection-test pro API klienty (Make, Zapier, vlastní skripty).
 *
 * Funguje pro bearer i session auth. Vrátí:
 *   - user: { id, email, name, role }
 *   - supplier: { id, company_name, display_name } — efektivní scope tokenu/session
 *   - auth_method: 'bearer' | 'session'
 *   - token: { id, prefix, scope, allow_payroll_submission_docs, expires_at } | null
 *     (jen pro bearer)
 */
final class ApiMeAction
{
    public function __construct(private readonly Connection $db) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (empty($user)) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }

        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $supplier = null;
        if ($supplierId > 0) {
            $stmt = $this->db->pdo()->prepare('SELECT id, company_name, display_name FROM supplier WHERE id = ?');
            $stmt->execute([$supplierId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $supplier = [
                    'id'           => (int) $row['id'],
                    'company_name' => $row['company_name'],
                    'display_name' => $row['display_name'],
                ];
            }
        }

        $method = RequestAuthorization::authMethod($request);
        $tokenOut = null;
        if ($method === 'bearer') {
            $tok = (array) $request->getAttribute(AuthMiddleware::ATTR_API_TOKEN, []);
            $tokenOut = [
                'id'         => (int) ($tok['id'] ?? 0),
                'name'       => $tok['name'] ?? null,
                'prefix'     => $tok['prefix'] ?? null,
                'scope'      => $tok['scope'] ?? null,
                // Volitelné schopnosti musí být vidět zvenčí. Bez nich se skript,
                // kterému chybí přístup, nemá čeho chytit — vypadá to jako prázdná
                // data, ne jako zavřený kanál.
                'allow_payroll_submission_docs' =>
                    RequestAuthorization::tokenAllowsPayrollSubmissionDocs($request),
                'expires_at' => $tok['expires_at'] ?? null,
            ];
        }

        return Json::ok($response, [
            'user' => [
                'id'    => (int) $user['id'],
                'email' => $user['email'],
                'name'  => $user['name'],
                'role'  => $user['role_summary'] ?? null,
                'is_superadmin' => (bool) ($user['is_superadmin'] ?? false),
            ],
            'supplier'    => $supplier,
            'auth_method' => $method,
            'token'       => $tokenOut,
        ]);
    }
}
