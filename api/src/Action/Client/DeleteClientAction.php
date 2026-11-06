<?php

declare(strict_types=1);

namespace MyInvoice\Action\Client;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DELETE /api/clients/{id}
 * Tvrdé smazání. Selže (409), pokud na klientovi existují faktury nebo zakázky.
 * Pro „soft" odstavení slouží archive endpoint.
 */
final class DeleteClientAction
{
    public function __construct(
        private readonly ClientRepository $repo,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $client = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $client)) {
            return Json::error($response, 'not_found', 'Klient nenalezen.', 404);
        }

        if ((new \MyInvoice\Service\Property\PropertyHistoryUseGuard($this->db))->clientHasHistory((int) $client['supplier_id'], $id)) {
            return Json::error($response, 'has_dependencies', 'Kontakt nelze smazat, je součástí právní historie SVJ. Místo toho jej archivujte.', 409);
        }

        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoices WHERE client_id = ?');
        $stmt->execute([$id]);
        $invoices = (int) $stmt->fetchColumn();
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM projects WHERE client_id = ?');
        $stmt->execute([$id]);
        $projects = (int) $stmt->fetchColumn();
        // Také přijaté faktury — klient může být v roli vendor (FK purchase_invoices.vendor_id
        // má default RESTRICT, takže SQL by tak jako tak selhal — radši friendly 409).
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM purchase_invoices WHERE vendor_id = ?');
        $stmt->execute([$id]);
        $purchases = (int) $stmt->fetchColumn();
        if ($invoices > 0 || $projects > 0 || $purchases > 0) {
            return Json::error(
                $response,
                'has_dependencies',
                "Klienta nelze smazat — má {$invoices} vystavených faktur, {$purchases} přijatých faktur a {$projects} zakázek. Místo toho ho archivuj.",
                409,
            );
        }

        try {
            $deleted = (new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($this->db))->transaction(function () use ($client, $id): bool {
                $supplier = (int) $client['supplier_id'];
                (new \MyInvoice\Service\Property\PropertyCatalog($this->db))->lockContext($supplier);
                if ((new \MyInvoice\Service\Property\PropertyHistoryUseGuard($this->db))->clientHasHistory($supplier, $id, true)) return false;
                $stmt = $this->db->pdo()->prepare('DELETE FROM clients WHERE id = ? AND supplier_id = ?');
                $stmt->execute([$id, $supplier]);
                return $stmt->rowCount() === 1;
            });
            if (!$deleted) return Json::error($response, 'has_dependencies', 'Kontakt nelze smazat, je součástí právní historie SVJ nebo se mezitím změnil. Místo toho jej archivujte.', 409);
        } catch (\PDOException $failure) {
            if ((int) ($failure->errorInfo[1] ?? 0) !== 1451) {
                throw $failure;
            }
            return Json::error($response, 'has_dependencies', 'Kontakt nelze smazat, používají jej další záznamy. Místo toho jej archivujte.', 409);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('client.deleted', $user['id'] ?? null, 'client', $id, null, $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['deleted' => true]);
    }
}
