<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\Product\ProductPostingDefaults;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Výchozí dimenze klienta a zakázky (Firma → Dimenze, migrace 1861).
 *
 *   GET|PUT /api/clients/{id}/dimensions        — výchozí dimenze klienta (odběratel i dodavatel)
 *   GET|PUT /api/projects/{id}/dimensions       — výchozí dimenze zakázky
 *   GET|PUT /api/stock/items/{id}/dimensions    — výchozí dimenze produktu (skladové karty)
 *   GET|PUT /api/eshop/categories/{id}/dimensions — výchozí dimenze kategorie produktů
 *   GET|PUT /api/accounting/bank-accounts/{id}/dimensions — výchozí dimenze vlastního bankovního
 *            účtu (jen podvojné účetnictví, jako ostatní úpravy účtu na stránce Kontace účtů)
 *   GET     /api/stock/items/{id}/posting-defaults — účet a dimenze položky z produktu (produkt > kategorie)
 *   GET     /api/accounting/dimensions/prefill  — předvyplnění hlavičky dokladu v editoru
 *            ?client_id=&project_id=&invoice_id=|purchase_invoice_id=
 *            &history=1&exclude_purchase_invoice_id= — návrh z posledního dokladu dodavatele (client_id)
 *
 * Práva zrcadlí RoutePermissionMap: čtení = `clients`/`projects` READ, uložení =
 * stejné právo jako úprava karty (`clients`/`projects` WRITE). Předvyplnění patří
 * k editoru dokladu, a tedy k dimenzím — `accounting` READ.
 */
final class DimensionDefaultsAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly Connection $db,
        private readonly DimensionService $dimensions,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly ProductPostingDefaults $productDefaults,
    ) {}

    public function getClient(Request $request, Response $response, array $args): Response
    {
        return $this->read($request, $response, 'client', (int) ($args['id'] ?? 0));
    }

    public function saveClient(Request $request, Response $response, array $args): Response
    {
        return $this->save($request, $response, 'client', (int) ($args['id'] ?? 0));
    }

    public function getProject(Request $request, Response $response, array $args): Response
    {
        // Delegování zakázky nezahrnuje účetní výchozí hodnoty (stejně jako její kategorii tržeb).
        if (RequestAuthorization::isClientType($request)) return Json::error($response, 'forbidden_permission', 'Účetní dimenze spravuje interní role.', 403);
        return $this->read($request, $response, 'project', (int) ($args['id'] ?? 0));
    }

    public function saveProject(Request $request, Response $response, array $args): Response
    {
        if (RequestAuthorization::isClientType($request)) return Json::error($response, 'forbidden_permission', 'Účetní dimenze spravuje interní role.', 403);
        return $this->save($request, $response, 'project', (int) ($args['id'] ?? 0));
    }

    public function getProduct(Request $request, Response $response, array $args): Response
    {
        return $this->read($request, $response, 'product', (int) ($args['id'] ?? 0));
    }

    public function saveProduct(Request $request, Response $response, array $args): Response
    {
        return $this->save($request, $response, 'product', (int) ($args['id'] ?? 0));
    }

    public function getCategory(Request $request, Response $response, array $args): Response
    {
        return $this->read($request, $response, 'product_category', (int) ($args['id'] ?? 0));
    }

    public function saveCategory(Request $request, Response $response, array $args): Response
    {
        return $this->save($request, $response, 'product_category', (int) ($args['id'] ?? 0));
    }

    public function getBankAccount(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireDoubleEntry($this->db, $this->currentSupplierId($request), $response, $err)) return $err;
        return $this->read($request, $response, 'bank_account', (int) ($args['id'] ?? 0));
    }

    public function saveBankAccount(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireDoubleEntry($this->db, $this->currentSupplierId($request), $response, $err)) return $err;
        return $this->save($request, $response, 'bank_account', (int) ($args['id'] ?? 0));
    }

    /**
     * Výchozí účet a dimenze položky dokladu z produktu (produkt > kategorie) — editor
     * je předvyplní při výběru karty. Stejné hodnoty použije zaúčtování u položky,
     * která je nemá vyplněné.
     */
    public function productPostingDefaults(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'stock', AccessLevel::READ, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        $productId = (int) ($args['id'] ?? 0);
        $accounts = $this->productDefaults->accountsFor($supplierId, [$productId]);
        if (!isset($accounts[$productId])) {
            return Json::error($response, 'not_found', 'Skladová karta nenalezena.', 404);
        }
        $dims = $this->dimensions->productPrefill($supplierId, $productId);
        $own = static fn (string $kind): ?string => ($accounts[$productId][$kind]['source'] ?? null) === 'product'
            ? $accounts[$productId][$kind]['code']
            : null;
        return Json::ok($response, [
            'own_revenue_account_code' => $own('revenue'),
            'own_expense_account_code' => $own('expense'),
            'revenue_account_code' => $accounts[$productId]['revenue']['code'] ?? null,
            'revenue_account_source' => $accounts[$productId]['revenue']['source'] ?? null,
            'expense_account_code' => $accounts[$productId]['expense']['code'] ?? null,
            'expense_account_source' => $accounts[$productId]['expense']['source'] ?? null,
            'dimensions' => (object) $dims['header'],
            'dimension_sources' => (object) $dims['sources'],
        ]);
    }

    public function prefill(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'accounting', AccessLevel::READ, $err)) return $err;
        $q = $request->getQueryParams();
        $id = static fn (string $key): ?int => isset($q[$key]) && (int) $q[$key] > 0 ? (int) $q[$key] : null;
        [$linkedType, $linkedId] = match (true) {
            $id('invoice_id') !== null => ['invoice', $id('invoice_id')],
            $id('purchase_invoice_id') !== null => ['purchase_invoice', $id('purchase_invoice_id')],
            default => [null, null],
        };
        $result = $this->dimensions->prefill(
            $this->currentSupplierId($request),
            $id('client_id'),
            $id('project_id'),
            $linkedType,
            $linkedId,
            ($q['history'] ?? null) === '1',
            $id('exclude_purchase_invoice_id'),
        );
        return Json::ok($response, ['header' => (object) $result['header'], 'sources' => (object) $result['sources']]);
    }

    private function read(Request $request, Response $response, string $entity, int $id): Response
    {
        if (!$this->requirePermission($request, $response, self::permission($entity), AccessLevel::READ, $err)) return $err;
        try {
            return Json::ok($response, ['dimensions' => (object) $this->dimensions->entityDefaults(
                $this->currentSupplierId($request),
                $entity,
                $id,
            )]);
        } catch (DimensionException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    private function save(Request $request, Response $response, string $entity, int $id): Response
    {
        if (!$this->requirePermission($request, $response, self::permission($entity, AccessLevel::WRITE), AccessLevel::WRITE, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $saved = $this->dimensions->saveEntityDefaults($supplierId, $entity, $id, (array) ($body['dimensions'] ?? []));
        } catch (DimensionException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        $this->logger->log(
            'dimension.defaults_updated',
            $this->userId($request),
            $entity,
            $id,
            ['dimensions' => $saved],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
        return Json::ok($response, ['dimensions' => (object) $saved]);
    }

    private static function permission(string $entity, AccessLevel $level = AccessLevel::READ): string
    {
        return match ($entity) {
            'project' => 'projects',
            // Zápis = totéž právo, jakým karta a kategorie ukládají účty (StockItemAction /
            // CategoryAction::requireWrite); routa navíc hlídá stock.items.write / eshop.write.
            'product' => $level === AccessLevel::WRITE ? 'accounting' : 'stock',
            'product_category' => $level === AccessLevel::WRITE ? 'accounting' : 'eshop',
            // Stejné právo jako ostatní úpravy vlastního účtu (SupplierBankAccountAction).
            'bank_account' => 'accounting',
            default => 'clients',
        };
    }
}
