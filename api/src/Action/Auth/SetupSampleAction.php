<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Sample\SampleDataGenerator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/auth/setup-sample
 *
 * Vygeneruje rozsáhlý syntetický dataset (24 klientů, 12 dodavatelů,
 * 120 vydaných a 120 přijatých faktur, pokladna, sklad, e-shopové číselníky,
 * banka, majetek a účetní deník) pro prvního admin usera a prvního supplieru.
 *
 * VYŽADUJE auth jako admin (po /setup je auto-login). Eliminuje veřejné okno,
 * kdy by anonymous mohl vyrobit data před adminem.
 *
 * Guard: žádné business data ještě neexistují (clients/invoices/projects = 0).
 * Pokud existují, vrátí 409 — endpoint je jen pro úplně čistou DB.
 */
final class SetupSampleAction
{
    public function __construct(
        private readonly SampleDataGenerator $generator,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Jen admin.', 403);
        }
        $adminId = (int) ($user['id'] ?? 0);

        $pdo = $this->db->pdo();
        $supplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Supplier neexistuje (setup byl bez supplier kroku).', 409);
        }

        // Setup-only window: pokud už existují data, neumožni opětovný seed
        $invoicesCount = (int) $pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn();
        $clientsCount = (int) $pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();
        $projectsCount = (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
        if ($invoicesCount > 0 || $clientsCount > 0 || $projectsCount > 0) {
            return Json::error(
                $response,
                'setup_done',
                'Sample data už nelze přidat — v systému už existují vlastní data.',
                409
            );
        }

        try {
            $result = $this->generator->generate($supplierId, $adminId);
        } catch (\Throwable $e) {
            return Json::internalError($response, 'sample_failed', 'Generování sample dat selhalo: ' . $e->getMessage(), 500);
        }

        return Json::ok($response, $result, 201);
    }
}
