<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Import\ImportBatchEraser;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/admin/import/batches/{batch}/delete
 *
 * Zahodí doklady jedné importní dávky. Zákazník migrující účetnictví si první dávku
 * téměř nikdy nenahraje správně (chybně zadaný export, jiný rozsah období) a mazat
 * tisíce dokladů po jednom nejde.
 *
 * Smaže se výhradně doklad nezaúčtovaný, nezamčený a bez úhrady; cokoli jiného se
 * vrátí v `skipped` s důvodem a zůstane na jednodokladové cestě, kde uživatel vidí
 * storno zápisu a rozhoduje o něm ({@see ImportBatchEraser}).
 *
 * `?ack_retention=1` je vědomé přehlasování retenční lhůty (§ 31 ZoÚ, § 35a ZDPH) —
 * SMÍ ho použít jen superadmin, stejně jako u jednodokladového mazání, a přehlasované
 * doklady se zapisují do auditní stopy.
 */
final class DeleteImportBatchAction
{
    public function __construct(
        private readonly ImportBatchEraser $eraser,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'invoices.delete', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Chybí oprávnění mazat doklady.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí kontext firmy.', 400);
        }

        $batchId = trim((string) ($args['batch'] ?? ''));
        if ($batchId === '' || preg_match('/^[A-Za-z0-9]{4,32}$/', $batchId) !== 1) {
            return Json::error($response, 'invalid_batch', 'Neplatný identifikátor dávky.', 400);
        }

        $ackRetention = ($request->getQueryParams()['ack_retention'] ?? '') === '1'
            && RequestAuthorization::isCompanyAdmin($request);

        $result = $this->eraser->erase($supplierId, $batchId, null, null, $ackRetention);

        $deleted = $result['deleted']['invoices'] + $result['deleted']['purchase_invoices'];
        $this->logger->log('import.batch_deleted', $user['id'] ?? null, 'import_batch', null, [
            'batch'                => $batchId,
            'deleted_invoices'     => $result['deleted']['invoices'],
            'deleted_purchases'    => $result['deleted']['purchase_invoices'],
            'skipped'              => count($result['skipped']),
            // Prošlapaná retenční lhůta MUSÍ zůstat dohledatelná — bez toho by se
            // z vědomého přehlasování stalo obyčejné smazání.
            'retention_overridden' => $result['retention_overridden'],
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $supplierId);

        return Json::ok($response, [
            'deleted' => $deleted,
            'deleted_invoices' => $result['deleted']['invoices'],
            'deleted_purchase_invoices' => $result['deleted']['purchase_invoices'],
            'skipped' => $result['skipped'],
            'retention_overridden' => count($result['retention_overridden']),
        ]);
    }
}
