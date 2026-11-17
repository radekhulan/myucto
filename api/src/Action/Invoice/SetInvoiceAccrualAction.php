<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Accounting\Closing\AccrualDeferralService;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * PUT /api/invoices/{id}/accrual — období časového rozlišení výnosu (384) u řádků.
 *
 * Smí i u vystavené a zaúčtované faktury: zápis faktury se nemění (výnos jde celý do
 * dne plnění), období čte až uzávěrka. Proto netřeba force-edit s přeúčtováním jako
 * u změny částek. Odmítá se (409) doklad v uzavřeném, revidovaném či schváleném období
 * a v uzavíraném období se zaúčtovaným odkladem: odklad už je součástí uzávěrky a změna
 * by rozjela jeho rozpuštění ({@see AccrualDeferralService::accrualChangeBlocker()}).
 *
 * Body: { items: [{ id: number, accrual_from: "RRRR-MM-DD"|null, accrual_to: "RRRR-MM-DD"|null }] }
 * Řádky, které v těle nejsou, se nemění; vynechaný klíč řádku drží uloženou hodnotu.
 */
final class SetInvoiceAccrualAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly AccrualDeferralService $accruals,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'invalid_id', 'Neplatné ID', 400);
        }

        $supplierId = SupplierGuard::currentId($request);
        $existing = $this->repo->find($id);
        if ($existing === null || (int) ($existing['supplier_id'] ?? 0) !== $supplierId) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        if (in_array((string) ($existing['status'] ?? ''), ['cancelled'], true)
            || in_array((string) ($existing['invoice_type'] ?? ''), ['cancellation'], true)) {
            return Json::error($response, 'not_editable', 'Stornovaný doklad nelze časově rozlišit.', 409);
        }
        $docDate = (string) ($existing['tax_date'] ?? $existing['issue_date'] ?? '');

        $body = (array) ($request->getParsedBody() ?? []);
        $items = $body['items'] ?? null;
        if (!is_array($items) || $items === []) {
            return Json::error($response, 'validation_failed', 'Pole items s řádky faktury je povinné.', 400);
        }
        $known = [];
        foreach ((array) ($existing['items'] ?? []) as $item) {
            $known[(int) $item['id']] = $item;
        }

        $changes = [];
        $errors = [];
        foreach (array_values($items) as $i => $item) {
            $itemId = is_array($item) ? (int) ($item['id'] ?? 0) : 0;
            if (!isset($known[$itemId])) {
                $errors["items.{$i}.id"][] = 'Řádek nepatří k této faktuře';
                continue;
            }
            // Vynechaný klíč = uložená hodnota (#113); řádek bez obou klíčů se nemění.
            $hasFrom = array_key_exists('accrual_from', $item);
            $hasTo = array_key_exists('accrual_to', $item);
            if (!$hasFrom && !$hasTo) {
                continue;
            }
            $from = trim((string) ($hasFrom ? $item['accrual_from'] : ($known[$itemId]['accrual_from'] ?? '')));
            $to = trim((string) ($hasTo ? $item['accrual_to'] : ($known[$itemId]['accrual_to'] ?? '')));
            if ($from === '' && $to === '') {
                $changes[$itemId] = [null, null];
                continue;
            }
            // Textový řádek nemá částku, takže ani výnos k rozlišení.
            if (\MyInvoice\Service\Invoice\InvoiceItemKind::isText($known[$itemId])) {
                $errors["items.{$i}.accrual_from"][] = 'Textový řádek nemá částku, časové rozlišení se na něj nenastavuje';
                continue;
            }
            [$validFrom, $validTo] = InvoiceRepository::accrualPeriod(['accrual_from' => $from, 'accrual_to' => $to]);
            if ($validFrom === null) {
                $errors["items.{$i}.accrual_from"][] = 'Období potřebuje obě data ve formátu RRRR-MM-DD a začátek nejpozději v den konce';
                continue;
            }
            $changes[$itemId] = [$validFrom, $validTo];
        }
        if ($errors !== []) {
            return Json::error($response, 'validation_failed', 'Neplatné období časového rozlišení.', 400, ['fields' => $errors]);
        }

        // Kontrola období a změna v jedné transakci pod zámkem období firmy: souběžná uzávěrka
        // nesmí mezi nimi zaúčtovat odklad z původního období.
        $guard = new DimensionPeriodGuard($this->db);
        [$blocked, $log] = $guard->transaction(function () use ($guard, $supplierId, $id, $docDate, $changes, $known): array {
            $blocked = $this->accruals->accrualChangeBlocker($supplierId, 'invoice', $id, $docDate, $guard->lockPeriods($supplierId));
            if ($blocked !== null) {
                return [$blocked, []];
            }
            $update = $this->db->pdo()->prepare('UPDATE invoice_items SET accrual_from = ?, accrual_to = ? WHERE id = ? AND invoice_id = ?');
            $log = [];
            foreach ($changes as $itemId => [$from, $to]) {
                $before = [$known[$itemId]['accrual_from'] ?? null, $known[$itemId]['accrual_to'] ?? null];
                if ($before === [$from, $to]) {
                    continue;
                }
                $update->execute([$from, $to, $itemId, $id]);
                $log[] = ['item_id' => $itemId, 'from' => $before, 'to' => [$from, $to]];
            }
            return [null, $log];
        });
        if ($blocked !== null) {
            return Json::error($response, $blocked[0], $blocked[1], 409);
        }

        if ($log !== []) {
            $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
            $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
            $this->logger->log('invoice.accrual_changed', $user['id'] ?? null, 'invoice', $id, [
                'items' => $log,
            ], $ip, $request->getHeaderLine('User-Agent'));
        }

        return Json::ok($response, $this->repo->find($id));
    }
}
