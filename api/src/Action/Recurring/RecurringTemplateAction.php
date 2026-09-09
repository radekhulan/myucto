<?php

declare(strict_types=1);

namespace MyInvoice\Action\Recurring;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\RecurringTemplateRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\PeriodicityCalculator;
use MyInvoice\Service\Invoice\InvoiceMath;
use MyInvoice\Service\Invoice\PriceListResolutionException;
use MyInvoice\Service\Invoice\RecurringInvoiceGenerator;
use MyInvoice\Service\Invoice\RecurringPriceListService;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Validation\InvoiceAmountPolicy;
use MyInvoice\Support\Pagination;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * REST handlery pro pravidelné fakturace.
 *
 *   GET    /api/recurring                 → list
 *   POST   /api/recurring                 → create
 *   GET    /api/recurring/{id}            → detail
 *   PUT    /api/recurring/{id}            → update
 *   DELETE /api/recurring/{id}            → delete
 *   POST   /api/recurring/{id}/pause      → pause
 *   POST   /api/recurring/{id}/resume     → resume + přepočet next_run
 *   POST   /api/recurring/{id}/reschedule → oprava příštího termínu
 *   POST   /api/recurring/{id}/run-now    → manuální spuštění
 */
final class RecurringTemplateAction
{
    public function __construct(
        private readonly RecurringTemplateRepository $repo,
        private readonly RecurringInvoiceGenerator $generator,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
        private readonly InvoiceRepository $invoices,
        private readonly Config $config,
        private readonly \MyInvoice\Service\Currency\CnbExchangeRateClient $cnb,
        private readonly RecurringPriceListService $priceList,
        private readonly TenantReferenceGuard $tenantRefs,
    ) {}

    /**
     * BOLA guard (security report 2026-08, R2 #2 a #3) — cizí klíče z těla musí patřit
     * supplierovi šablony. revenue_category_id má vlastní kontrolu už ve validate()
     * (#119), tady je pro úplnost seznamu a jako jednotná chybová cesta.
     *
     * @return list<string>
     */
    private function badTenantRefs(int $supplierId, array $body): array
    {
        return $this->tenantRefs->violations(
            $supplierId,
            $body,
            ['client_id', 'project_id', 'currency_id', 'revenue_category_id'],
        );
    }

    public function list(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $filters = ['supplier_id' => $supplierId];
        $q = $request->getQueryParams();
        if (!empty($q['client_id'])) $filters['client_id'] = (int) $q['client_id'];
        if (!empty($q['status']))    $filters['status'] = (string) $q['status'];

        $page = max(1, (int) ($q['page'] ?? 1));
        $default = (int) $this->config->get('pagination.recurring_per_page', 50);
        $perPage = min(200, max(5, (int) ($q['per_page'] ?? $default)));

        // Řazení (server-side kvůli stránkování). Pro 'amount_czk' poskládáme dnešní
        // ČNB kurzy měn ve filtrované množině, ať se ranking nemixuje napříč měnami.
        $sort = in_array((string) ($q['sort'] ?? ''), ['client', 'next_run', 'amount_czk'], true)
            ? (string) $q['sort'] : '';
        $czkRates = [];
        if ($sort === 'amount_czk') {
            $today = new \DateTimeImmutable('today');
            foreach ($this->repo->distinctCurrencyCodes($filters) as $code) {
                $code = strtoupper($code);
                if ($code === 'CZK') { $czkRates['CZK'] = 1.0; continue; }
                $rate = $this->cnb->getRate($code, $today);
                $czkRates[$code] = $rate !== null ? (float) $rate['rate'] : 1.0;
            }
        }

        $result = $this->repo->list($filters, $page, $perPage, $sort, $czkRates);
        $this->applyCatalogEstimates($result['data']);
        // Souhrn částek do hlavičky: per měna z repo + přepočet na CZK (dnešní ČNB kurz)
        // pokud je víc měn. Počítá se přes celou filtrovanou množinu (ne jen stránku).
        $result['meta']['summary'] = $this->buildSummary($result['meta']['totals_by_currency'] ?? []);
        return Json::ok($response, $result);
    }

    /** @param list<array<string,mixed>> $templates */
    private function applyCatalogEstimates(array &$templates): void
    {
        $itemsByTemplate = $this->repo->itemsForTemplates(array_map(
            static fn (array $template): int => (int) $template['id'],
            $templates,
        ));
        $vatRates = $this->invoices->vatRateMap();
        $today = new \DateTimeImmutable('today');

        foreach ($templates as &$template) {
            $items = $itemsByTemplate[(int) $template['id']] ?? [];
            $hasDynamicCatalog = array_any($items, static fn (array $item): bool =>
                !empty($item['price_list_item_id']) && ($item['catalog_policy'] ?? 'fixed') !== 'fixed'
            );
            if (!$hasDynamicCatalog) continue;

            try {
                $effective = $this->priceList->resolveForGeneration(
                    $items,
                    (int) $template['supplier_id'],
                    (int) $template['client_id'],
                    (int) $template['currency_id'],
                    !empty($template['prices_include_vat']),
                    $today,
                );
                $mathItems = array_map(static fn (array $item): array => [
                    'quantity' => (float) $item['quantity'],
                    'unit_price_without_vat' => (float) $item['unit_price_without_vat'],
                    'vat_rate_snapshot' => (float) ($vatRates[(int) $item['vat_rate_id']] ?? 0),
                ], $effective);
                $totals = InvoiceMath::compute(
                    $mathItems,
                    !empty($template['reverse_charge']),
                    !empty($template['prices_include_vat']),
                )['totals'];
                $discount = min(100.0, max(0.0, (float) ($template['discount_percent'] ?? 0)));
                $template['total_with_vat'] = round($totals['with_vat'] * (100 - $discount) / 100, 2);

                $dates = array_values(array_filter(array_map(
                    static fn (array $item): ?string => $item['catalog_exchange_rate_date'] ?? null,
                    $effective,
                )));
                sort($dates);
                $template['catalog_total_is_estimate'] = $dates !== [];
                $template['catalog_estimate_rate_date'] = $dates !== [] ? end($dates) : null;
                $template['catalog_state'] = 'ok';
            } catch (PriceListResolutionException $e) {
                $template['catalog_state'] = $e->errorCode === 'price_list_item_archived'
                    ? 'archived'
                    : ($e->errorCode === 'price_list_review_required' ? 'changed' : 'error');
                $template['catalog_error'] = $e->getMessage();
            }
        }
        unset($template);
    }

    /**
     * @param list<array{currency:string,total:float}> $byCurrency
     * @return array{by_currency:list<array{currency:string,total:float}>, multi_currency:bool, total_czk:float, rates_complete:bool}
     */
    private function buildSummary(array $byCurrency): array
    {
        $today = new \DateTimeImmutable('today');
        $totalCzk = 0.0;
        $ratesComplete = true;
        foreach ($byCurrency as $row) {
            $code = strtoupper((string) $row['currency']);
            $amount = (float) $row['total'];
            if ($code === 'CZK') {
                $totalCzk += $amount;
                continue;
            }
            $rate = $this->cnb->getRate($code, $today);
            if ($rate === null) {
                $ratesComplete = false; // kurz nedostupný → do CZK součtu nezahrnuto
                continue;
            }
            $totalCzk += $amount * (float) $rate['rate'];
        }
        return [
            'by_currency'    => array_map(
                fn ($r) => ['currency' => strtoupper((string) $r['currency']), 'total' => round((float) $r['total'], 2)],
                $byCurrency
            ),
            'multi_currency' => count($byCurrency) > 1,
            'total_czk'      => round($totalCzk, 2),
            'rates_complete' => $ratesComplete,
        ];
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $tpl = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $tpl)) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }
        $estimated = [$tpl];
        $this->applyCatalogEstimates($estimated);
        $tpl = $estimated[0];
        return Json::ok($response, $tpl);
    }

    /**
     * GET /api/recurring/{id}/invoices
     * Vrátí flat list faktur vygenerovaných z této šablony (poslední první).
     */
    public function invoices(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $tpl = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $tpl)) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }

        $p = Pagination::fromQuery($request->getQueryParams());

        $stmtTotal = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM invoices i WHERE i.recurring_template_id = ?'
        );
        $stmtTotal->execute([$id]);
        $total = (int) $stmtTotal->fetchColumn();

        $limit = (int) $p['per_page'];
        $offset = (int) $p['offset'];
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.id, i.varsymbol, i.invoice_type, i.status, i.issue_date, i.due_date, i.paid_at,
                    i.total_with_vat, i.amount_to_pay, cur.code AS currency
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.recurring_template_id = ?
              ORDER BY i.issue_date DESC, i.id DESC
              LIMIT $limit OFFSET $offset"
        );
        $stmt->execute([$id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Cast numerics
        foreach ($rows as &$r) {
            $r['id']             = (int) $r['id'];
            $r['total_with_vat'] = (float) $r['total_with_vat'];
            $r['amount_to_pay']  = (float) $r['amount_to_pay'];
        }
        unset($r);

        return Json::ok($response, Pagination::envelope($rows, $total, $p['page'], $p['per_page']));
    }

    public function create(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $supplierId = SupplierGuard::currentId($request);
        $body['supplier_id'] = $supplierId;

        // PŘED prepareCatalogItems() — ten už s client_id/currency_id sahá do ceníku.
        if ($badRefs = $this->badTenantRefs($supplierId, $body)) {
            return Json::error($response, 'invalid_reference', TenantReferenceGuard::message($badRefs), 400);
        }

        // next_run_date = anchor_date pokud není explicitně zadané
        $body['next_run_date'] = $body['next_run_date'] ?? ($body['anchor_date'] ?? null);
        try {
            $body = $this->prepareCatalogItems($body, true);
        } catch (PriceListResolutionException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 409, [
                'price_list_item_id' => $e->priceListItemId,
            ]);
        }

        $errors = $this->validate($body);
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());

        $id = $this->repo->createWithItems($body, $userId, (array) ($body['items'] ?? []));

        $this->logger->log('recurring.created', $userId, 'recurring_template', $id, [
            'client_id' => $body['client_id'],
            'frequency' => $body['frequency'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id), 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $tpl = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $tpl)) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $body['supplier_id'] = (int) $tpl['supplier_id'];

        // PŘED jakoukoli prací s dodanými FK (snapshot check níž čte body['currency_id']).
        if ($badRefs = $this->badTenantRefs((int) $tpl['supplier_id'], $body)) {
            return Json::error($response, 'invalid_reference', TenantReferenceGuard::message($badRefs), 400);
        }

        $body['next_run_date'] = empty($tpl['last_run_date'])
            ? ($body['anchor_date'] ?? $tpl['anchor_date'])
            : $tpl['next_run_date'];

        // Pevnou částku nelze tiše reinterpretovat v jiné měně nebo mezi netto/brutto.
        // Změna zákazníka ji naopak záměrně zachová; uživatel ji může obnovit explicitně.
        $fixedSnapshotContextChanged = (int) ($body['currency_id'] ?? 0) !== (int) $tpl['currency_id']
            || (!empty($body['prices_include_vat']) !== !empty($tpl['prices_include_vat']));
        if ($fixedSnapshotContextChanged) {
            foreach ((array) ($body['items'] ?? []) as $item) {
                if (is_array($item)
                    && !empty($item['price_list_item_id'])
                    && ($item['catalog_policy'] ?? 'fixed') === 'fixed'
                    && empty($item['accept_catalog_changes'])
                ) {
                    return Json::error(
                        $response,
                        'fixed_catalog_snapshot_context_changed',
                        'Po změně měny nebo režimu cen je nutné ceníkovou položku znovu vybrat.',
                        409,
                        ['price_list_item_id' => (int) $item['price_list_item_id']],
                    );
                }
            }
        }

        try {
            $body = $this->prepareCatalogItems($body, false);
        } catch (PriceListResolutionException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 409, [
                'price_list_item_id' => $e->priceListItemId,
            ]);
        }

        $errors = $this->validate($body);
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        try {
            $this->repo->updateWithItems($id, $body, (array) ($body['items'] ?? []));
        } catch (\DomainException $e) {
            return Json::error($response, 'schedule_changed', $e->getMessage(), 409);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('recurring.updated', $user['id'] ?? null, 'recurring_template', $id, null, $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id));
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $tpl = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $tpl)) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }

        $this->repo->delete($id);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('recurring.deleted', $user['id'] ?? null, 'recurring_template', $id, [
            'name' => $tpl['name'] ?? null,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['deleted' => true]);
    }

    public function pause(Request $request, Response $response, array $args): Response
    {
        return $this->setStatus($request, $response, $args, 'paused', 'recurring.paused');
    }

    public function resume(Request $request, Response $response, array $args): Response
    {
        // Při resume zkontroluj, jestli next_run_date není v minulosti za end_date.
        $id = (int) ($args['id'] ?? 0);
        $tpl = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $tpl)) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }
        if (!empty($tpl['end_date']) && (string) $tpl['next_run_date'] > (string) $tpl['end_date']) {
            return Json::error($response, 'expired', 'Šablona vypršela (next_run > end_date).', 409);
        }
        return $this->setStatus($request, $response, $args, 'active', 'recurring.resumed');
    }

    public function reschedule(Request $request, Response $response, array $args): Response
    {
        try {
            return $this->repo->withScheduleLock((int) ($args['id'] ?? 0),
                fn (): Response => $this->rescheduleLocked($request, $response, $args));
        } catch (\DomainException $e) {
            return Json::error($response, 'schedule_changed', $e->getMessage(), 409);
        }
    }

    private function rescheduleLocked(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $tpl = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $tpl)) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $date = $body['next_run_date'] ?? null;
        if (!is_string($date) || !self::isValidDate($date)
            || $date < (new \DateTimeImmutable('today'))->format('Y-m-d')
            || $date < $tpl['anchor_date']
            || (!empty($tpl['end_date']) && $date > $tpl['end_date'])) {
            return Json::error($response, 'invalid_date', 'Zvolte platné datum od dneška v rozsahu platnosti šablony.', 400);
        }
        if (($body['expected_next_run_date'] ?? null) !== $tpl['next_run_date']) {
            return Json::error($response, 'schedule_changed', 'Plán se mezitím změnil. Obnovte detail a zkuste to znovu.', 409);
        }
        if ($date === $tpl['next_run_date']) {
            return Json::ok($response, $tpl);
        }
        // Otevřený koncept patří k původnímu období; přesunutím by ho cron opustil.
        if (($tpl['draft_open_mode'] ?? 'at_issue') === 'period_start') {
            $current = $this->repo->findPeriodInvoice($id, $tpl['next_run_date']);
            if ($current !== null && $current['status'] === 'draft') {
                return Json::error($response, 'period_draft_exists', 'Nejprve vyřešte otevřený koncept aktuálního období.', 409);
            }
        }
        if ($this->repo->findPeriodInvoice($id, $date) !== null) {
            return Json::error($response, 'period_invoice_exists', 'Pro zvolené datum již existuje faktura této šablony.', 409);
        }
        if (!$this->repo->reschedule($tpl, $date)) {
            return Json::error($response, 'schedule_changed', 'Plán se mezitím změnil. Obnovte detail a zkuste to znovu.', 409);
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('recurring.rescheduled', $user['id'] ?? null, 'recurring_template', $id, [
            'old_next_run_date' => $tpl['next_run_date'],
            'new_next_run_date' => $date,
        ], $ip, $request->getHeaderLine('User-Agent'));
        return Json::ok($response, $this->repo->find($id));
    }

    public function runNow(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $tpl = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $tpl)) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }
        if ($tpl['status'] === 'expired') {
            return Json::error($response, 'expired', 'Šablona vypršela.', 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $forcedIssueDate = !empty($body['issue_date']) ? (string) $body['issue_date'] : null;
        // draft=true → „Vygenerovat koncept": vytvoří draft i u šablony s auto_issue=true.
        $forceDraft = !empty($body['draft']);
        $advanceSchedule = $body['advance_schedule'] ?? true;
        if (!is_bool($advanceSchedule)) {
            return Json::error($response, 'invalid_schedule', 'Neplatná volba posunu plánu.', 400);
        }
        if (!$advanceSchedule && ($tpl['draft_open_mode'] ?? 'at_issue') === 'period_start') {
            return Json::error($response, 'invalid_schedule', 'Režim Na začátku období používá plánovaný koncept.', 400);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $ua = $request->getHeaderLine('User-Agent');

        try {
            if ($forceDraft && (string) ($tpl['draft_open_mode'] ?? 'at_issue') === 'period_start') {
                // period_start: ruční „Vygenerovat koncept" = stejný otevřený koncept jako
                // cron na začátku období (openDraft) — idempotentní, NEposouvá rozvrh,
                // koncept se pak vystaví den po next_run_date (cron issuePeriod), s datem
                // vystavení i DUZP na next_run_date (konci období).
                $d = $this->generator->openDraft($id, $userId, $ip, $ua, expectedNextRunDate: $tpl['next_run_date']);
                $result = [
                    'invoice_id'        => $d['invoice_id'],
                    'varsymbol'         => null,
                    'issued'            => false,
                    'sent_to'           => [],
                    'new_next_run_date' => $tpl['next_run_date'] ?? null,
                    'template_status'   => $tpl['status'] ?? 'active',
                ];
            } else {
                $result = $this->generator->generate($id, $forcedIssueDate, $userId, $ip, $ua, $forceDraft, $advanceSchedule, expectedNextRunDate: $tpl['next_run_date']);
            }
            // Úspěšné ruční vygenerování smaže případný banner z dřívějšího selhání cronu.
            $this->repo->clearLastError($id);
        } catch (\DomainException $e) {
            return Json::error($response, 'cannot_generate', $e->getMessage(), 409);
        } catch (\Throwable $e) {
            return Json::error($response, 'generation_failed', $e->getMessage(), 500);
        }

        return Json::ok($response, $result, 201);
    }

    private function setStatus(Request $request, Response $response, array $args, string $status, string $action): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $tpl = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $tpl)) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }
        $this->repo->setStatus($id, $status);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, $user['id'] ?? null, 'recurring_template', $id, null, $ip, $request->getHeaderLine('User-Agent'));
        return Json::ok($response, $this->repo->find($id));
    }

    /**
     * @return array<string, string[]>
     */
    private function validate(array $data): array
    {
        $err = [];

        if (empty($data['client_id']) || !is_numeric($data['client_id'])) {
            $err['client_id'][] = 'Klient je povinný';
        }
        if (empty($data['name']) || trim((string) $data['name']) === '') {
            $err['name'][] = 'Název šablony je povinný';
        }
        $frequency = (string) ($data['frequency'] ?? '');
        if (!in_array($frequency, PeriodicityCalculator::FREQUENCIES, true)) {
            $err['frequency'][] = 'Neplatná periodicita';
        }
        if (empty($data['anchor_date']) || !self::isValidDate((string) $data['anchor_date'])) {
            $err['anchor_date'][] = 'Neplatné datum zahájení';
        }
        if (!empty($data['end_date'])) {
            if (!self::isValidDate((string) $data['end_date'])) {
                $err['end_date'][] = 'Neplatné datum ukončení';
            } elseif (!empty($data['anchor_date']) && (string) $data['end_date'] < (string) $data['anchor_date']) {
                $err['end_date'][] = 'Datum ukončení musí být po zahájení';
            }
        }
        $endOfMonth = !empty($data['end_of_month']);
        $dom = $data['day_of_month'] ?? null;
        if ($endOfMonth && $dom !== null && $dom !== '') {
            $err['day_of_month'][] = 'Nelze kombinovat „poslední den měsíce" a konkrétní den.';
        }
        if (!$endOfMonth && $dom !== null && $dom !== '') {
            $domInt = (int) $dom;
            if ($domInt < 1 || $domInt > 28) {
                $err['day_of_month'][] = 'Den v měsíci musí být 1–28';
            }
        }
        if (empty($data['currency_id']) || (int) $data['currency_id'] <= 0) {
            $err['currency_id'][] = 'Neplatná měna';
        }
        $paymentMethod = (string) ($data['payment_method'] ?? 'bank_transfer');
        if (!in_array($paymentMethod, ['bank_transfer', 'card', 'cash', 'other'], true)) {
            $err['payment_method'][] = 'Neplatný způsob úhrady';
        }
        if (array_key_exists('tax_date_mode', $data) && $data['tax_date_mode'] !== null && $data['tax_date_mode'] !== '') {
            if (!in_array((string) $data['tax_date_mode'], ['same_as_issue', 'previous_month_last_day'], true)) {
                $err['tax_date_mode'][] = 'Neplatný režim DUZP';
            }
        }
        $draftOpenMode = (string) ($data['draft_open_mode'] ?? 'at_issue');
        if (!in_array($draftOpenMode, ['at_issue', 'period_start'], true)) {
            $err['draft_open_mode'][] = 'Neplatný režim otevření konceptu';
        }
        if ($draftOpenMode === 'period_start') {
            // „Otevřený koncept" zatím jen pro měsíční periodicitu a s auto-vystavením
            // (koncept se na konci období uzavře sám — viz issuePeriod()).
            if ($frequency !== 'monthly') {
                $err['draft_open_mode'][] = 'Režim „Na začátku období" lze použít jen u měsíční periodicity.';
            }
            if (empty($data['auto_issue'])) {
                $err['draft_open_mode'][] = 'Režim „Na začátku období" vyžaduje automatické vystavení.';
            }
        }
        if (array_key_exists('reminder_days_before', $data) && $data['reminder_days_before'] !== null && $data['reminder_days_before'] !== '') {
            if (!is_numeric($data['reminder_days_before']) || (int) $data['reminder_days_before'] < 0 || (int) $data['reminder_days_before'] > 14) {
                $err['reminder_days_before'][] = 'Připomenutí musí být 0–14 dní.';
            }
        }
        // auto_send_email vyžaduje auto_issue (nelze poslat draft)
        if (!empty($data['auto_send_email']) && empty($data['auto_issue'])) {
            $err['auto_send_email'][] = 'Automatické odeslání vyžaduje automatické vystavení.';
        }
        if (array_key_exists('discount_percent', $data) && $data['discount_percent'] !== null && $data['discount_percent'] !== '') {
            if (!is_numeric($data['discount_percent']) || (float) $data['discount_percent'] < 0 || (float) $data['discount_percent'] > 100) {
                $err['discount_percent'][] = 'Sleva musí být mezi 0 a 100 %';
            }
        }
        // Pevná kategorie tržby (#119) — IDOR guard: musí existovat a patřit dodavateli
        // šablony. Archivovanou nezakazujeme (edit dřív uložené šablony musí projít).
        if (!empty($data['revenue_category_id'])) {
            if (!is_numeric($data['revenue_category_id'])) {
                $err['revenue_category_id'][] = 'Neplatná kategorie tržby';
            } else {
                $stmt = $this->db->pdo()->prepare(
                    'SELECT COUNT(*) FROM revenue_categories WHERE id = ? AND supplier_id = ?'
                );
                $stmt->execute([(int) $data['revenue_category_id'], (int) $data['supplier_id']]);
                if ((int) $stmt->fetchColumn() === 0) {
                    $err['revenue_category_id'][] = 'Neplatná kategorie tržby';
                }
            }
        }
        // U non-bank-transfer ztrácí auto_send_email smysl (klient nemá co platit) — povolíme,
        // ale ne reminder; reminder cron už non-bank přeskakuje.

        $items = $data['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            $err['items'][] = 'Šablona musí mít alespoň jednu položku';
        } else {
            $knownCodes = null;
            foreach (array_values($items) as $i => $item) {
                if (!is_array($item)) { $err["items.{$i}"][] = 'Neplatná položka'; continue; }
                $err = array_merge($err, InvoiceAmountPolicy::validateItem($item, $i));
                // Klasifikace DPH na řádku šablony (migrace 1788) se přenáší na každou
                // vygenerovanou fakturu, takže překlep by tiše posílal roky plnění na
                // špatný řádek přiznání. Číselník se načte jen když ho někdo použil.
                $code = trim((string) ($item['vat_classification_code'] ?? ''));
                if ($code === '') { continue; }
                $knownCodes ??= $this->saleClassificationCodes((int) ($data['supplier_id'] ?? 0));
                if (!in_array($code, $knownCodes, true)) {
                    $err["items.{$i}"][] = "Neznámá klasifikace DPH „{$code}\"";
                }
            }
        }

        $amountError = InvoiceAmountPolicy::validatePositiveAmountToPay([
            'invoice_type' => (string) ($data['invoice_type'] ?? 'invoice'),
            'advance_paid_amount' => 0,
            'reverse_charge' => !empty($data['reverse_charge']),
            'discount_percent' => (float) ($data['discount_percent'] ?? 0),
            'items' => $items,
        ], $this->invoices->vatRateMap());
        if ($amountError !== null) {
            $err['amount_to_pay'][] = $amountError;
        }

        return $err;
    }

    /**
     * Kódy klasifikací použitelné na vystaveném dokladu (globální seed + kódy tenanta).
     *
     * @return list<string>
     */
    private function saleClassificationCodes(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT code FROM vat_classifications
              WHERE (supplier_id IS NULL OR supplier_id = ?)
                AND archived = 0
                AND direction IN ("sale", "both")'
        );
        $stmt->execute([$supplierId]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function prepareCatalogItems(array $body, bool $newTemplate): array
    {
        if (empty($body['client_id']) || empty($body['currency_id']) || empty($body['next_run_date'])) {
            return $body;
        }
        try {
            $issueDate = new \DateTimeImmutable((string) $body['next_run_date']);
        } catch (\Exception) {
            return $body;
        }

        $referenceDate = $issueDate;
        if (($body['invoice_type'] ?? 'invoice') !== 'proforma'
            && ($body['tax_date_mode'] ?? 'same_as_issue') === 'previous_month_last_day'
        ) {
            $referenceDate = $issueDate->modify('first day of this month')->modify('-1 day');
        }

        $body['items'] = $this->priceList->prepareForSave(
            (array) ($body['items'] ?? []),
            (int) $body['supplier_id'],
            (int) $body['client_id'],
            (int) $body['currency_id'],
            !empty($body['prices_include_vat']),
            $referenceDate,
            $newTemplate,
        );
        return $body;
    }

    private static function isValidDate(string $date): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }
}
