<?php

declare(strict_types=1);

namespace MyInvoice\Action\Document;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\Document\AttachmentCheck\AttachmentCheckService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Kontrola zaúčtovaných dokladů proti vytěžení příloh.
 *
 *   GET  /api/attachment-checks?state=open|acknowledged|all  … uložené rozdíly firmy (přehled rozporů)
 *   POST /api/attachment-checks/recheck {from?, to?}          … hromadný přepočet
 *   GET  /api/purchase-invoices/{id}/attachment-check                    … živé porovnání dokladu (odznak)
 *   POST /api/purchase-invoices/{id}/attachment-check/acknowledge        … potvrzení „v pořádku" {sha256, reason}
 *   GET|POST /api/invoices/{id}/attachment-check[/acknowledge]
 *   GET|POST /api/accounting/cash-documents/{id}/attachment-check[/acknowledge]
 *
 * Odznak a potvrzení visí pod cestou dokladu, takže oprávnění je oprávnění k dokladu
 * (RoutePermissionMap); přehled a přepočet patří k sekci Dokumenty → Skeny k dokladům.
 */
final class AttachmentCheckAction
{
    public function __construct(private readonly AttachmentCheckService $checks) {}

    /** GET /api/attachment-checks */
    public function list(Request $request, Response $response): Response
    {
        $sid = SupplierGuard::currentId($request);
        if ($sid === 0) {
            return Json::error($response, 'no_supplier', 'Chybí kontext firmy.', 400);
        }
        $state = (string) ($request->getQueryParams()['state'] ?? 'open');
        return Json::ok($response, $this->checks->listMismatches($sid, $state) + ['state' => $state]);
    }

    /** POST /api/attachment-checks/recheck */
    public function recheck(Request $request, Response $response): Response
    {
        $sid = SupplierGuard::currentId($request);
        if ($sid === 0) {
            return Json::error($response, 'no_supplier', 'Chybí kontext firmy.', 400);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $dates = [];
        foreach (['from', 'to'] as $k) {
            $v = isset($body[$k]) && is_string($body[$k]) && $body[$k] !== '' ? $body[$k] : null;
            if ($v !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1) {
                return Json::error($response, 'validation_failed', 'Datum musí být ve tvaru RRRR-MM-DD.', 422);
            }
            $dates[$k] = $v;
        }
        return Json::ok($response, $this->checks->recheckAll($sid, $dates['from'], $dates['to']));
    }

    /** @param array<string,string> $args */
    public function showPurchaseInvoice(Request $request, Response $response, array $args): Response
    {
        return $this->show($request, $response, 'purchase_invoice', (int) $args['id']);
    }

    /** @param array<string,string> $args */
    public function acknowledgePurchaseInvoice(Request $request, Response $response, array $args): Response
    {
        return $this->acknowledge($request, $response, 'purchase_invoice', (int) $args['id']);
    }

    /** @param array<string,string> $args */
    public function showInvoice(Request $request, Response $response, array $args): Response
    {
        return $this->show($request, $response, 'invoice', (int) $args['id']);
    }

    /** @param array<string,string> $args */
    public function acknowledgeInvoice(Request $request, Response $response, array $args): Response
    {
        return $this->acknowledge($request, $response, 'invoice', (int) $args['id']);
    }

    /** @param array<string,string> $args */
    public function showCashDocument(Request $request, Response $response, array $args): Response
    {
        return $this->show($request, $response, 'cash_document', (int) $args['id']);
    }

    /** @param array<string,string> $args */
    public function acknowledgeCashDocument(Request $request, Response $response, array $args): Response
    {
        return $this->acknowledge($request, $response, 'cash_document', (int) $args['id']);
    }

    private function show(Request $request, Response $response, string $type, int $id): Response
    {
        $sid = SupplierGuard::currentId($request);
        if ($sid === 0) {
            return Json::error($response, 'no_supplier', 'Chybí kontext firmy.', 400);
        }
        $rows = $this->checks->evaluate($sid, $type, $id);
        return Json::ok($response, ['rows' => array_map(self::view(...), $rows), 'summary' => self::summary($rows)]);
    }

    private function acknowledge(Request $request, Response $response, string $type, int $id): Response
    {
        $sid = SupplierGuard::currentId($request);
        if ($sid === 0) {
            return Json::error($response, 'no_supplier', 'Chybí kontext firmy.', 400);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $sha = (string) ($body['sha256'] ?? '');
        if (preg_match('/^[0-9a-f]{64}$/', $sha) !== 1) {
            return Json::error($response, 'validation_failed', 'Chybí příloha, ke které se potvrzení vztahuje.', 422);
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $res = $this->checks->acknowledge($sid, $type, $id, $sha, (string) ($body['reason'] ?? ''), $userId > 0 ? $userId : null);
        if (!$res['ok']) {
            return match ($res['error'] ?? '') {
                'reason_required' => Json::error($response, 'reason_required', 'Uveďte důvod, proč je doklad v pořádku.', 422),
                'nothing_to_acknowledge' => Json::error($response, 'nothing_to_acknowledge', 'Doklad s přílohou nemá žádný rozdíl k potvrzení.', 409),
                default => Json::error($response, 'not_found', 'Doklad nebo příloha nenalezena.', 404),
            };
        }
        $rows = $this->checks->evaluate($sid, $type, $id);
        return Json::ok($response, ['rows' => array_map(self::view(...), $rows), 'summary' => self::summary($rows)]);
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function view(array $r): array
    {
        unset($r['fingerprint'], $r['ack_by']);
        return $r;
    }

    /**
     * Souhrn pro odznak: `none` = doklad nemá vytěženou přílohu (nekontroluje se),
     * `ok`, `acknowledged`, `warning` / `info` = otevřený rozdíl dané závažnosti.
     *
     * @param list<array<string,mixed>> $rows
     * @return array{state:string, open:int, compared:int}
     */
    private static function summary(array $rows): array
    {
        $open = array_values(array_filter($rows, static fn (array $r): bool => $r['open']));
        $compared = count(array_filter($rows, static fn (array $r): bool => $r['status'] !== 'skipped'));
        $state = match (true) {
            $open !== [] => in_array('warning', array_column($open, 'severity'), true) ? 'warning' : 'info',
            array_filter($rows, static fn (array $r): bool => $r['acknowledged']) !== [] => 'acknowledged',
            $compared > 0 => 'ok',
            default => 'none',
        };
        return ['state' => $state, 'open' => count($open), 'compared' => $compared];
    }
}
