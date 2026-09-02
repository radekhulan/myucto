<?php

declare(strict_types=1);

namespace MyInvoice\Action\PriceList;

use DateTimeImmutable;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PriceListItemRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\PriceListItemResolver;
use MyInvoice\Service\Invoice\PriceListResolutionException;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\License\CommercialFeatureAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PriceListItemAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly PriceListItemRepository $repo,
        private readonly PriceListItemResolver $resolver,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly CommercialFeatureAccess $commercialFeatures,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($unavailable = $this->unavailableForStock($supplierId, $response)) return $unavailable;
        $q = $request->getQueryParams();
        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($q['per_page'] ?? 50)));
        $currency = isset($q['currency']) && trim((string) $q['currency']) !== ''
            ? strtoupper(trim((string) $q['currency']))
            : null;
        if ($currency !== null && !$this->validCurrencyCode($currency)) {
            return Json::error($response, 'validation_failed', 'Neplatný kód měny.', 400);
        }
        try {
            $rateDate = new DateTimeImmutable((string) ($q['rate_date'] ?? 'today'));
        } catch (\Exception) {
            return Json::error($response, 'validation_failed', 'Neplatné datum kurzu.', 400);
        }
        $clientId = !empty($q['client_id']) ? (int) $q['client_id'] : 0;
        if ($clientId > 0 && !$this->repo->clientExists($supplierId, $clientId)) {
            return Json::error($response, 'validation_failed', 'Zákazník nebyl nalezen.', 400);
        }

        $contextualResolution = $currency !== null;
        $result = $this->repo->listForSupplier(
            $supplierId,
            trim((string) ($q['q'] ?? '')),
            !empty($q['include_archived']),
            $contextualResolution ? 1 : $page,
            $contextualResolution ? 200 : $perPage,
            $currency,
            $clientId > 0 ? $clientId : null,
            array_key_exists('prices_include_vat', $q) ? !empty($q['prices_include_vat']) : null,
        );
        if ($contextualResolution) {
            $candidates = $result['data'];
            $candidatePage = 2;
            while (count($candidates) < $result['total']) {
                $next = $this->repo->listForSupplier(
                    $supplierId,
                    trim((string) ($q['q'] ?? '')),
                    !empty($q['include_archived']),
                    $candidatePage++,
                    200,
                    $currency,
                    $clientId > 0 ? $clientId : null,
                    !empty($q['prices_include_vat']),
                );
                if ($next['data'] === []) break;
                array_push($candidates, ...$next['data']);
            }
            $targetCurrency = $this->repo->activeCurrencyByCode($supplierId, $currency);
            if ($targetCurrency === null) {
                return Json::error($response, 'validation_failed', 'Měna není pro dodavatele aktivní.', 400);
            }
            $resolved = [];
            foreach ([false, true] as $priceMode) {
                $modeIds = array_map(
                    static fn (array $row): int => (int) $row['id'],
                    array_filter(
                        $candidates,
                        static fn (array $row): bool => (bool) $row['prices_include_vat'] === $priceMode,
                    ),
                );
                if ($modeIds === []) continue;
                $resolved += $this->resolver->resolveAvailable(
                    array_values($modeIds),
                    $supplierId,
                    $clientId,
                    $targetCurrency['id'],
                    $priceMode,
                    $rateDate,
                );
            }
            $resolvedRows = array_values(array_filter(array_map(
                static function (array $row) use ($resolved): ?array {
                    $id = (int) $row['id'];
                    if (!isset($resolved[$id])) return null;
                    $row['resolved_price'] = $resolved[$id];
                    return $row;
                },
                $candidates,
            )));
            $result = [
                'data' => array_slice($resolvedRows, ($page - 1) * $perPage, $perPage),
                'total' => count($resolvedRows),
                'page' => $page,
                'per_page' => $perPage,
            ];
        }
        return Json::ok($response, [
            'data' => $result['data'],
            'meta' => [
                'total' => $result['total'],
                'page' => $result['page'],
                'per_page' => $result['per_page'],
                'pages' => max(1, (int) ceil($result['total'] / $result['per_page'])),
            ],
        ]);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($unavailable = $this->unavailableForStock($supplierId, $response)) return $unavailable;
        $item = $this->repo->find((int) ($args['id'] ?? 0), $supplierId);
        if ($item === null) return Json::error($response, 'not_found', 'Ceníková položka nebyla nalezena.', 404);
        $item['customer_overrides'] = $this->repo->customerOverrides($supplierId, (int) $item['id']);
        return Json::ok($response, $item);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request)) return $this->adminOnly($response);
        $supplierId = SupplierGuard::currentId($request);
        if ($unavailable = $this->unavailableForStock($supplierId, $response)) return $unavailable;
        $body = $this->normalizeBody((array) ($request->getParsedBody() ?? []));
        $error = $this->validateItem($supplierId, $body, true);
        if ($error !== null) return Json::error($response, 'validation_failed', $error, 400);

        try {
            $id = $this->repo->create($supplierId, $body, $body['prices']);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                return Json::error($response, 'duplicate_code', 'Ceníková položka s tímto kódem už existuje.', 409);
            }
            throw $e;
        } catch (\DomainException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }

        $this->log($request, 'price_list_item.created', $id, ['code' => $body['code']]);
        foreach ($body['prices'] as $price) {
            $this->log($request, 'price_list_item_price.created', $id, [
                'currency' => $price['currency_code'],
            ]);
        }
        return Json::ok($response, $this->repo->find($id, $supplierId), 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin($request)) return $this->adminOnly($response);
        $supplierId = SupplierGuard::currentId($request);
        if ($unavailable = $this->unavailableForStock($supplierId, $response)) return $unavailable;
        $id = (int) ($args['id'] ?? 0);
        $before = $this->repo->find($id, $supplierId);
        if ($before === null) {
            return Json::error($response, 'not_found', 'Ceníková položka nebyla nalezena.', 404);
        }
        $body = $this->normalizeBody((array) ($request->getParsedBody() ?? []));
        $error = $this->validateItem($supplierId, $body, false);
        if ($error !== null) return Json::error($response, 'validation_failed', $error, 400);

        try {
            $this->repo->update($id, $supplierId, $body, $body['prices']);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                return Json::error($response, 'duplicate_code', 'Ceníková položka s tímto kódem už existuje.', 409);
            }
            throw $e;
        } catch (\DomainException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }

        $after = $this->repo->find($id, $supplierId);
        $this->log($request, 'price_list_item.updated', $id, ['code' => $body['code']]);
        $this->logPriceChanges($request, $id, $before['prices'], $after['prices'] ?? []);
        return Json::ok($response, $after);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin($request)) return $this->adminOnly($response);
        $supplierId = SupplierGuard::currentId($request);
        if ($unavailable = $this->unavailableForStock($supplierId, $response)) return $unavailable;
        $id = (int) ($args['id'] ?? 0);
        if ($this->repo->find($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Ceníková položka nebyla nalezena.', 404);
        }
        $result = $this->repo->delete($id, $supplierId);
        $this->log($request, 'price_list_item.' . ($result['deleted'] ? 'deleted' : 'archived'), $id, $result);
        return Json::ok($response, $result);
    }

    public function upsertPrice(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin($request)) return $this->adminOnly($response);
        $supplierId = SupplierGuard::currentId($request);
        if ($unavailable = $this->unavailableForStock($supplierId, $response)) return $unavailable;
        $id = (int) ($args['id'] ?? 0);
        $currency = strtoupper((string) ($args['currencyCode'] ?? ''));
        if ($this->repo->find($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Ceníková položka nebyla nalezena.', 404);
        }
        if (!$this->validCurrencyCode($currency) || !$this->repo->activeCurrencyExists($supplierId, $currency)) {
            return Json::error($response, 'validation_failed', 'Měna není pro dodavatele aktivní.', 400);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $price = $this->validPrice($body['unit_price'] ?? null);
        if ($price === null) return Json::error($response, 'validation_failed', 'Cena musí být nezáporné číslo.', 400);

        $item = $this->repo->find($id, $supplierId);
        $existed = array_any($item['prices'] ?? [], static fn (array $row): bool => $row['currency_code'] === $currency);
        $this->repo->upsertPrice($supplierId, $id, $currency, $price, !empty($body['archived']));
        $this->log($request, 'price_list_item_price.' . ($existed ? 'updated' : 'created'), $id, ['currency' => $currency]);
        return Json::ok($response, $this->repo->find($id, $supplierId));
    }

    public function prices(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($unavailable = $this->unavailableForStock($supplierId, $response)) return $unavailable;
        $item = $this->repo->find((int) ($args['id'] ?? 0), $supplierId);
        if ($item === null) return Json::error($response, 'not_found', 'Ceníková položka nebyla nalezena.', 404);
        return Json::ok($response, $item['prices']);
    }

    public function deletePrice(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin($request)) return $this->adminOnly($response);
        $supplierId = SupplierGuard::currentId($request);
        if ($unavailable = $this->unavailableForStock($supplierId, $response)) return $unavailable;
        $id = (int) ($args['id'] ?? 0);
        $currency = strtoupper((string) ($args['currencyCode'] ?? ''));
        if ($this->repo->find($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Ceníková položka nebyla nalezena.', 404);
        }
        try {
            $result = $this->repo->deletePrice($supplierId, $id, $currency);
        } catch (\DomainException $e) {
            return Json::error($response, 'base_price_required', $e->getMessage(), 409);
        }
        $this->log($request, 'price_list_item_price.' . ($result['deleted'] ? 'deleted' : 'archived'), $id, [
            'currency' => $currency,
        ] + $result);
        return Json::ok($response, $result);
    }

    public function customerOverrides(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($unavailable = $this->unavailableForStock($supplierId, $response)) return $unavailable;
        $id = (int) ($args['id'] ?? 0);
        if ($this->repo->find($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Ceníková položka nebyla nalezena.', 404);
        }
        return Json::ok($response, $this->repo->customerOverrides($supplierId, $id));
    }

    public function upsertCustomerOverride(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin($request)) return $this->adminOnly($response);
        $supplierId = SupplierGuard::currentId($request);
        if ($unavailable = $this->unavailableForStock($supplierId, $response)) return $unavailable;
        $id = (int) ($args['id'] ?? 0);
        $clientId = (int) ($args['clientId'] ?? 0);
        $currency = strtoupper((string) ($args['currencyCode'] ?? ''));
        if ($this->repo->find($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Ceníková položka nebyla nalezena.', 404);
        }
        if (!$this->repo->clientExists($supplierId, $clientId)) {
            return Json::error($response, 'validation_failed', 'Zákazník nebyl nalezen.', 400);
        }
        if (!$this->validCurrencyCode($currency) || !$this->repo->activeCurrencyExists($supplierId, $currency)) {
            return Json::error($response, 'validation_failed', 'Měna není pro dodavatele aktivní.', 400);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $price = $this->validPrice($body['unit_price'] ?? null);
        if ($price === null) return Json::error($response, 'validation_failed', 'Cena musí být nezáporné číslo.', 400);

        $existed = array_any(
            $this->repo->customerOverrides($supplierId, $id),
            static fn (array $row): bool => $row['client_id'] === $clientId && $row['currency_code'] === $currency,
        );
        $this->repo->upsertCustomerOverride($supplierId, $id, $clientId, $currency, $price);
        $this->log($request, 'price_list_customer_override.' . ($existed ? 'updated' : 'created'), $id, [
            'client_id' => $clientId,
            'currency' => $currency,
        ]);
        return Json::ok($response, $this->repo->customerOverrides($supplierId, $id));
    }

    public function deleteCustomerOverride(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin($request)) return $this->adminOnly($response);
        $supplierId = SupplierGuard::currentId($request);
        if ($unavailable = $this->unavailableForStock($supplierId, $response)) return $unavailable;
        $id = (int) ($args['id'] ?? 0);
        $clientId = (int) ($args['clientId'] ?? 0);
        $currency = strtoupper((string) ($args['currencyCode'] ?? ''));
        if ($this->repo->find($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Ceníková položka nebyla nalezena.', 404);
        }
        $deleted = $this->repo->deleteCustomerOverride($supplierId, $id, $clientId, $currency);
        $this->log($request, 'price_list_customer_override.deleted', $id, [
            'client_id' => $clientId,
            'currency' => $currency,
            'deleted' => $deleted,
        ]);
        return Json::ok($response, ['deleted' => $deleted]);
    }

    public function resolve(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($unavailable = $this->unavailableForStock($supplierId, $response)) return $unavailable;
        $id = (int) ($args['id'] ?? 0);
        $q = $request->getQueryParams();
        $clientId = (int) ($q['client_id'] ?? 0);
        $currencyId = (int) ($q['currency_id'] ?? 0);
        if ($clientId > 0 && !$this->repo->clientExists($supplierId, $clientId)) {
            return Json::error($response, 'validation_failed', 'Zákazník nebyl nalezen.', 400);
        }
        try {
            $date = new DateTimeImmutable((string) ($q['rate_date'] ?? 'today'));
        } catch (\Exception) {
            return Json::error($response, 'validation_failed', 'Neplatné datum kurzu.', 400);
        }

        try {
            $resolved = $this->resolver->resolveMany(
                [$id],
                $supplierId,
                $clientId,
                $currencyId,
                !empty($q['prices_include_vat']),
                $date,
            );
        } catch (PriceListResolutionException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 409, [
                'price_list_item_id' => $e->priceListItemId,
            ]);
        }
        return Json::ok($response, $resolved[$id]);
    }

    private function normalizeBody(array $body): array
    {
        $prices = [];
        foreach ((array) ($body['prices'] ?? []) as $price) {
            if (!is_array($price)) continue;
            $prices[] = [
                'currency_code' => strtoupper(trim((string) ($price['currency_code'] ?? ''))),
                'unit_price' => $price['unit_price'] ?? null,
                'archived' => !empty($price['archived']),
            ];
        }
        return [
            'code' => trim((string) ($body['code'] ?? '')),
            'name' => trim((string) ($body['name'] ?? '')),
            'description' => trim((string) ($body['description'] ?? '')),
            'unit' => trim((string) ($body['unit'] ?? '')),
            'vat_rate_id' => (int) ($body['vat_rate_id'] ?? 0),
            'prices_include_vat' => !empty($body['prices_include_vat']),
            'base_currency_code' => strtoupper(trim((string) ($body['base_currency_code'] ?? ''))),
            'allow_exchange_rate_conversion' => !empty($body['allow_exchange_rate_conversion']),
            'archived' => !empty($body['archived']),
            'prices' => $prices,
        ];
    }

    private function validateItem(int $supplierId, array $body, bool $requirePrices): ?string
    {
        if (!preg_match('/^[a-z0-9._-]{1,50}$/i', (string) $body['code'])) {
            return 'Kód je povinný a smí obsahovat písmena, čísla, tečku, podtržítko a pomlčku.';
        }
        if ($body['name'] === '' || mb_strlen((string) $body['name']) > 150) return 'Název je povinný, maximálně 150 znaků.';
        if ($body['description'] === '' || mb_strlen((string) $body['description']) > 500) return 'Popis je povinný, maximálně 500 znaků.';
        if (!$this->repo->unitExists((string) $body['unit'])) return 'Neplatná měrná jednotka.';
        if (!$this->repo->vatRateExists((int) $body['vat_rate_id'])) return 'Neplatná sazba DPH.';
        $base = (string) $body['base_currency_code'];
        if (!$this->validCurrencyCode($base) || !$this->repo->activeCurrencyExists($supplierId, $base)) {
            return 'Základní měna není pro dodavatele aktivní.';
        }
        if ($requirePrices && $body['prices'] === []) return 'Je nutné zadat základní cenu.';

        $seen = [];
        $hasBase = false;
        foreach ($body['prices'] as $price) {
            $code = (string) $price['currency_code'];
            if (!$this->validCurrencyCode($code) || !$this->repo->activeCurrencyExists($supplierId, $code)) {
                return "Měna {$code} není pro dodavatele aktivní.";
            }
            if (isset($seen[$code])) return "Cena pro měnu {$code} je uvedena vícekrát.";
            $seen[$code] = true;
            if ($this->validPrice($price['unit_price']) === null) return "Cena pro měnu {$code} musí být nezáporné číslo.";
            if ($code === $base && empty($price['archived'])) $hasBase = true;
        }
        if ($requirePrices && !$hasBase) return 'Základní cena musí být aktivní.';
        return null;
    }

    private function validPrice(mixed $value): ?float
    {
        if (!is_numeric($value)) return null;
        $price = (float) $value;
        return is_finite($price) && $price >= 0 ? $price : null;
    }

    private function validCurrencyCode(string $code): bool
    {
        return preg_match('/^[A-Z]{3}$/', $code) === 1;
    }

    private function isAdmin(Request $request): bool
    {
        return RequestAuthorization::isSuperadmin($request);
    }

    private function unavailableForStock(int $supplierId, Response $response): ?Response
    {
        if (!$this->commercialFeatures->isAvailable()) return null;

        $stmt = $this->db->pdo()->prepare('SELECT stock_enabled FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        if (!(bool) $stmt->fetchColumn()) return null;

        return Json::error(
            $response,
            'price_list_unavailable',
            'Ceník není dostupný, protože firma používá skladový/e-shop modul.',
            409,
        );
    }

    private function adminOnly(Response $response): Response
    {
        return Json::error($response, 'forbidden', 'Ceník může spravovat pouze administrátor.', 403);
    }

    private function log(Request $request, string $action, int $entityId, array $details): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log(
            $action,
            isset($user['id']) ? (int) $user['id'] : null,
            'price_list_item',
            $entityId,
            $details,
            $ip,
            $request->getHeaderLine('User-Agent'),
        );
    }

    /** @param list<array<string,mixed>> $before @param list<array<string,mixed>> $after */
    private function logPriceChanges(Request $request, int $itemId, array $before, array $after): void
    {
        $old = [];
        foreach ($before as $row) $old[(string) $row['currency_code']] = $row;
        $new = [];
        foreach ($after as $row) $new[(string) $row['currency_code']] = $row;

        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $currency) {
            $was = $old[$currency] ?? null;
            $now = $new[$currency] ?? null;
            $action = null;
            if ($was === null && $now !== null) $action = 'created';
            elseif ($was !== null && $now === null) $action = 'deleted';
            elseif ($was !== null && $now !== null && empty($was['archived']) && !empty($now['archived'])) $action = 'archived';
            elseif ($was !== null && $now !== null && (
                (float) $was['unit_price'] !== (float) $now['unit_price']
                || (bool) $was['archived'] !== (bool) $now['archived']
            )) $action = 'updated';
            if ($action !== null) {
                $this->log($request, 'price_list_item_price.' . $action, $itemId, ['currency' => $currency]);
            }
        }
    }
}
