<?php

declare(strict_types=1);

namespace MyInvoice\Action\Project;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ProjectRepository;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DELETE /api/projects/{id}
 * Tvrdé smazání. Faktury a výkazy práce zůstanou bez zakázky, selže (409) jen kvůli
 * faktuře v uzavřeném období nebo za uzamčeným datem.
 * Pro „soft" odstavení slouží archive endpoint.
 */
final class DeleteProjectAction
{
    public function __construct(
        private readonly ProjectRepository $repo,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly DocumentLockService $locks,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if (!SupplierGuard::owns($request, $this->repo->find($id))) {
            return Json::error($response, 'not_found', 'Zakázka nenalezena.', 404);
        }

        $supplierId = SupplierGuard::currentId($request);
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare('SELECT id FROM invoices WHERE supplier_id = ? AND project_id = ? FOR UPDATE');
            $stmt->execute([$supplierId, $id]);
            $invoiceIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

            // Zakázka je jen analytický štítek, faktury zůstanou bez ní. Doklad v uzavřeném
            // období nebo za uzamčeným datem se ale měnit nesmí.
            foreach (array_chunk($invoiceIds, 500) as $batch) {
                foreach ($this->locks->lockedMapForSources($supplierId, 'invoice', $batch) as $lock) {
                    if ($lock->inClosedPeriod || $lock->inClosingPeriod || $lock->dateLocked) {
                        if ($ownTx) {
                            $pdo->rollBack();
                        }
                        return Json::error(
                            $response,
                            'has_locked_invoices',
                            RequestAuthorization::allows($request, 'invoices')
                                ? 'Zakázku nelze smazat — má faktury v uzavřeném období nebo za uzamčeným datem. Místo toho ji archivuj.'
                                : 'Zakázku nelze smazat kvůli uzamčeným dokladům. Místo toho ji archivuj.',
                            409,
                        );
                    }
                }
            }

            $pdo->prepare('UPDATE invoices SET project_id = NULL WHERE supplier_id = ? AND project_id = ?')->execute([$supplierId, $id]);
            $pdo->prepare('UPDATE work_reports SET project_id = NULL WHERE project_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('project.deleted', $user['id'] ?? null, 'project', $id, $invoiceIds === [] ? null : ['detached_invoices' => count($invoiceIds)], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['deleted' => true]);
    }
}
