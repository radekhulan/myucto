<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DELETE /api/admin/imports/{id}
 *
 * Ruční smazání import jobu (escape hatch). Na rozdíl od cancel mizí job úplně —
 * uklidí zaseknuté i dokončené joby z UI. Scope na tenanta, admin/účetní.
 */
final class DeleteImportJobAction
{
    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'utilities.import', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }

        $id = (int) ($args['id'] ?? 0);
        $supplierId = SupplierGuard::currentId($request);
        $job = $this->jobs->find($id, $supplierId);
        if (($job['source'] ?? '') === 'myucto_import') {
            return Json::error($response, 'native_import', 'Nativní obnovu spravujte v průvodci MyÚčto.', 403);
        }
        if (!$this->jobs->delete($id, $supplierId)) {
            return Json::error($response, 'not_found',
                'Job nelze smazat (neexistuje nebo patří jinému tenantovi).', 404);
        }

        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('import.deleted', $userId, 'import_job', $id, null,
            $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['ok' => true, 'deleted' => true]);
    }
}
