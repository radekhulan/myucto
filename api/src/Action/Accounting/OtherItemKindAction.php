<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemKindService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Druhy ostatních pohledávek a závazků (migrace 2228) — vestavěné i vlastní.
 *
 *   GET    /api/accounting/other-items/kinds                         — seznam druhů firmy
 *   POST   /api/accounting/other-items/kinds                         — nový vlastní druh { side, name, own_series, prefix }
 *   PUT    /api/accounting/other-items/kinds/{id}                    — úprava vlastního druhu { name, is_active, own_series, prefix }
 *   PUT    /api/accounting/other-items/kinds/builtin/{side}/{code}   — úprava vestavěného druhu { is_active, own_series, prefix }
 *   DELETE /api/accounting/other-items/kinds/{id}                    — smazání nepoužitého vlastního druhu
 *
 * Zapnutí i vypnutí vlastní řady mění číselnou řadu, proto vyžaduje navíc stejné právo
 * jako úprava řad v Nástrojích (`accounting` zápis).
 */
final class OtherItemKindAction
{
    use AccountingActionSupport;

    public function __construct(
        private readonly OtherItemKindService $kinds,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::READ, $err)) return $err;
        return Json::ok($response, ['items' => $this->kinds->list($this->currentSupplierId($request))]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::WRITE, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        if (filter_var($body['own_series'] ?? false, FILTER_VALIDATE_BOOL)
            && !$this->requireWrite($request, $response, $err)) return $err;
        return $this->run($response, function () use ($request, $body): array {
            $kind = $this->kinds->create($this->currentSupplierId($request), $body, $this->userId($request));
            $this->log($request, 'other_item_kind.created', (int) $kind['id'], self::context($kind));
            return $kind;
        }, 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::WRITE, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        $id = (int) $args['id'];
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $current = $this->kinds->get($supplierId, $id);
        } catch (OtherItemException $e) {
            return Json::error($response, 'other_items.error.' . $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        if (OtherItemKindService::changesSeries($current, $body) && !$this->requireWrite($request, $response, $err)) return $err;
        return $this->run($response, function () use ($request, $supplierId, $id, $body, $current): array {
            $kind = $this->kinds->update($supplierId, $id, $body);
            $this->log($request, 'other_item_kind.updated', $id, self::context($kind, $current));
            return $kind;
        });
    }

    public function updateBuiltin(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::WRITE, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        $side = (string) $args['side'];
        $code = (string) $args['code'];
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $current = $this->kinds->getBuiltin($supplierId, $side, $code);
        } catch (OtherItemException $e) {
            return Json::error($response, 'other_items.error.' . $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        if (OtherItemKindService::changesSeries($current, $body) && !$this->requireWrite($request, $response, $err)) return $err;
        return $this->run($response, function () use ($request, $supplierId, $side, $code, $body, $current): array {
            $kind = $this->kinds->updateBuiltin($supplierId, $side, $code, $body, $this->userId($request));
            $this->log($request, 'other_item_kind.updated', (int) $kind['id'], self::context($kind, $current));
            return $kind;
        });
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::WRITE, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        $id = (int) $args['id'];
        try {
            $kind = $this->kinds->get($supplierId, $id);
        } catch (OtherItemException $e) {
            return Json::error($response, 'other_items.error.' . $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        // Mazání druhu s vlastní řadou maže i jeho řadu — stejné právo jako při založení.
        if ($kind['own_series'] && !$this->requireWrite($request, $response, $err)) return $err;
        return $this->run($response, function () use ($request, $supplierId, $id, $kind): array {
            $this->kinds->delete($supplierId, $id);
            $this->log($request, 'other_item_kind.deleted', $id, self::context($kind));
            return ['deleted' => true];
        });
    }

    private function run(Response $response, callable $call, int $status = 200): Response
    {
        try {
            return Json::ok($response, $call(), $status);
        } catch (OtherItemException $e) {
            return Json::error($response, 'other_items.error.' . $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    /**
     * Audit druhu: o jaký druh jde, jeho řada a u úpravy změněná pole (před → po).
     *
     * @param array<string,mixed> $kind
     * @param array<string,mixed>|null $before
     * @return array<string,mixed>
     */
    private static function context(array $kind, ?array $before = null): array
    {
        $context = [
            'side' => $kind['side'], 'builtin_code' => $kind['builtin_code'], 'name' => $kind['name'],
            'series_code' => OtherItemKindService::seriesCode((string) $kind['side']),
            'own_series' => (bool) $kind['own_series'], 'series_prefix' => $kind['series_prefix'] ?? null,
            'is_active' => (bool) $kind['is_active'],
        ];
        if ($before !== null) {
            $changes = [];
            foreach (['name', 'is_active', 'own_series'] as $field) {
                if ($before[$field] !== $kind[$field]) $changes[$field] = ['from' => $before[$field], 'to' => $kind[$field]];
            }
            $context['changes'] = $changes;
        }
        return $context;
    }

    /** @param array<string,mixed> $context */
    private function log(Request $request, string $action, int $id, array $context): void
    {
        $this->logger->log($action, $this->userId($request), 'other_item_kind', $id, $context,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $this->currentSupplierId($request));
    }
}
