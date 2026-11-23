<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\Accrual\AccrualBookService;
use MyInvoice\Service\Accounting\Accrual\AccrualBookXlsxExporter;
use MyInvoice\Service\Accounting\Accrual\AccrualClosingProjection;
use MyInvoice\Service\Accounting\Accrual\AccrualException;
use MyInvoice\Service\Accounting\Accrual\AccrualItemRepository;
use MyInvoice\Service\Accounting\Accrual\AccrualItemService;
use MyInvoice\Service\Accounting\Accrual\AccrualPostingService;
use MyInvoice\Service\Accounting\Accrual\AccrualReconciliationService;
use MyInvoice\Service\Accounting\Accrual\PohodaAccrualTakeoverService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\DocumentActivityFeed;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\AccrualBookPdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Agenda Časové rozlišení (jen podvojné účetnictví): položky na 381 až 385, plán rozpouštění,
 * zaúčtování, předčasné ukončení, odsouhlasení s hlavní knihou, rozpis k datu a převzetí
 * z POHODY. Čtení `accounting.accruals`, správa `accounting.accruals.write`, zaúčtování navíc
 * `accounting.journal.post` ({@see RequestAuthorization::canPostAccruals()}).
 */
final class AccrualAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly Connection $db,
        private readonly AccrualItemService $items,
        private readonly AccrualItemRepository $repository,
        private readonly AccrualPostingService $posting,
        private readonly AccrualReconciliationService $reconciliation,
        private readonly AccrualClosingProjection $projection,
        private readonly PohodaAccrualTakeoverService $takeover,
        private readonly AccrualBookService $book,
        private readonly AccrualBookXlsxExporter $xlsx,
        private readonly AccrualBookPdfRenderer $pdf,
        private readonly DocumentActivityFeed $feed,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, false, $err)) return $err;
        return $this->run($response, fn () => $this->items->list($this->currentSupplierId($request), $request->getQueryParams()));
    }

    /** @param array<string,string> $args */
    public function get(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, false, $err)) return $err;
        return $this->run($response, fn () => $this->items->get($this->currentSupplierId($request), (int) $args['id'], $request->getQueryParams()['as_of'] ?? null));
    }

    /** @param array<string,string> $args */
    public function schedule(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, false, $err)) return $err;
        return $this->run($response, function () use ($request, $args): array {
            $sid = $this->currentSupplierId($request);
            $item = $this->items->get($sid, (int) $args['id']);
            return ['item_id' => $item['id'], 'schedule' => $item['schedule'], 'links' => $item['links']];
        });
    }

    /** @param array<string,string> $args */
    public function activity(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, false, $err)) return $err;
        if ($this->repository->find($this->currentSupplierId($request), (int) $args['id']) === null) {
            return Json::error($response, 'accruals.error.not_found', 'Položka časového rozlišení neexistuje.', 404);
        }
        return Json::ok($response, $this->feed->forEntity('accrual_item', (int) $args['id']));
    }

    public function planPreview(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, false, $err)) return $err;
        return $this->run($response, fn () => ['rows' => $this->items->previewPlan((array) ($request->getParsedBody() ?? []))]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, true, $err)) return $err;
        return $this->run($response, function () use ($request): array {
            $item = $this->items->create($this->currentSupplierId($request), (array) ($request->getParsedBody() ?? []), $this->userId($request));
            $this->log($request, 'accrual_item.created', (int) $item['id'], ['item_no' => $item['item_no'], 'status' => $item['status']]);
            return $item;
        }, 201);
    }

    /** @param array<string,string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, true, $err)) return $err;
        return $this->run($response, function () use ($request, $args): array {
            $sid = $this->currentSupplierId($request);
            $before = $this->repository->find($sid, (int) $args['id']);
            $item = $this->items->update($sid, (int) $args['id'], (array) ($request->getParsedBody() ?? []), $this->userId($request));
            $this->log($request, 'accrual_item.updated', (int) $item['id'], ['changes' => self::diff($before ?? [], $item)]);
            return $item;
        });
    }

    /** @param array<string,string> $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, true, $err)) return $err;
        return $this->run($response, function () use ($request, $args): array {
            $item = $this->items->delete($this->currentSupplierId($request), (int) $args['id']);
            $this->log($request, 'accrual_item.deleted', (int) $args['id'], ['item_no' => $item['item_no']]);
            return ['deleted' => true];
        });
    }

    /** @param array<string,string> $args */
    public function activate(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, true, $err)) return $err;
        return $this->run($response, function () use ($request, $args): array {
            $item = $this->items->activate($this->currentSupplierId($request), (int) $args['id'], $this->userId($request));
            $this->log($request, 'accrual_item.activated', (int) $args['id']);
            return $item;
        });
    }

    /** @param array<string,string> $args */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, true, $err)) return $err;
        return $this->run($response, function () use ($request, $args): array {
            $item = $this->items->cancel($this->currentSupplierId($request), (int) $args['id'], $this->userId($request));
            $this->log($request, 'accrual_item.cancelled', (int) $args['id']);
            return $item;
        });
    }

    /** @param array<string,string> $args */
    public function rowPreview(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, false, $err)) return $err;
        return $this->run($response, fn () => $this->posting->previewRow($this->currentSupplierId($request), (int) $args['id'], (int) $args['row']));
    }

    /** @param array<string,string> $args */
    public function postRow(Request $request, Response $response, array $args): Response
    {
        if (!$this->guardPost($request, $response, $err)) return $err;
        return $this->run($response, function () use ($request, $args): array {
            $item = $this->posting->postRow($this->currentSupplierId($request), (int) $args['id'], (int) $args['row'], $this->meta($request));
            $this->log($request, 'accrual_item.release_posted', (int) $args['id'], ['schedule_id' => (int) $args['row']]);
            return $item;
        });
    }

    /** @param array<string,string> $args */
    public function unpostRow(Request $request, Response $response, array $args): Response
    {
        if (!$this->guardPost($request, $response, $err)) return $err;
        return $this->run($response, function () use ($request, $args): array {
            $item = $this->posting->unpostRow($this->currentSupplierId($request), (int) $args['id'], (int) $args['row'], $this->meta($request));
            $this->log($request, 'accrual_item.release_reversed', (int) $args['id'], ['schedule_id' => (int) $args['row']]);
            return $item;
        });
    }

    /** @param array<string,string> $args */
    public function terminationPreview(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, false, $err)) return $err;
        $q = $request->getQueryParams();
        return $this->run($response, fn () => $this->posting->terminationPreview($this->currentSupplierId($request), (int) $args['id'],
            substr((string) ($q['date'] ?? ''), 0, 10), isset($q['termination_account_code']) ? (string) $q['termination_account_code'] : null));
    }

    /** @param array<string,string> $args */
    public function terminate(Request $request, Response $response, array $args): Response
    {
        if (!$this->guardPost($request, $response, $err)) return $err;
        return $this->run($response, function () use ($request, $args): array {
            $body = (array) ($request->getParsedBody() ?? []);
            $item = $this->posting->terminate($this->currentSupplierId($request), (int) $args['id'], [
                'date' => (string) ($body['date'] ?? ''),
                'termination_account_code' => isset($body['termination_account_code']) ? (string) $body['termination_account_code'] : null,
                'reason' => isset($body['reason']) ? (string) $body['reason'] : null,
                'reverse_future' => !empty($body['reverse_future']),
                'confirm_linked' => !empty($body['confirm_linked']),
            ], $this->meta($request));
            $this->log($request, 'accrual_item.terminated', (int) $args['id'], ['date' => $item['terminated_on'],
                'entry_id' => $item['termination_entry_id'], 'reverse_future' => !empty($body['reverse_future'])]);
            return $item;
        });
    }

    /** @param array<string,string> $args */
    public function cancelTermination(Request $request, Response $response, array $args): Response
    {
        if (!$this->guardPost($request, $response, $err)) return $err;
        return $this->run($response, function () use ($request, $args): array {
            $item = $this->posting->cancelTermination($this->currentSupplierId($request), (int) $args['id'], $this->meta($request));
            $this->log($request, 'accrual_item.termination_cancelled', (int) $args['id']);
            return $item;
        });
    }

    /** @param array<string,string> $args */
    public function addLink(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, true, $err)) return $err;
        return $this->run($response, function () use ($request, $args): array {
            $body = (array) ($request->getParsedBody() ?? []);
            $result = $this->reconciliation->assign($this->currentSupplierId($request), (int) $args['id'], (int) ($body['entry_id'] ?? 0),
                (string) ($body['account_code'] ?? ''), $body['amount'] ?? null, $this->userId($request));
            $this->log($request, 'accrual_item.entry_linked', (int) $args['id'], $result);
            return $this->items->get($this->currentSupplierId($request), (int) $args['id']);
        }, 201);
    }

    /** @param array<string,string> $args */
    public function removeLink(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, true, $err)) return $err;
        return $this->run($response, function () use ($request, $args): array {
            $sid = $this->currentSupplierId($request);
            $link = null;
            foreach ($this->repository->links($sid, (int) $args['id']) as $l) {
                if ($l['id'] === (int) $args['link']) {
                    $link = $l;
                }
            }
            if ($link === null) {
                throw new AccrualException('link_not_found', 'Vazba neexistuje.', 404);
            }
            if ($link['matched_by'] === 'schedule') {
                throw new AccrualException('link_from_schedule', 'Vazbu zápisu agendy zrušíte stornem rozpuštění nebo ukončení.', 409);
            }
            $this->repository->deleteLink($sid, (int) $args['id'], (int) $args['link']);
            $this->log($request, 'accrual_item.entry_unlinked', (int) $args['id'], ['entry_id' => $link['journal_entry_id'], 'amount' => $link['amount']]);
            return $this->items->get($sid, (int) $args['id']);
        });
    }

    public function sourceCandidates(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, false, $err)) return $err;
        $q = $request->getQueryParams();
        return $this->run($response, fn () => $this->items->sourceCandidates($this->currentSupplierId($request),
            (string) ($q['source_type'] ?? ''), (int) ($q['source_id'] ?? 0)));
    }

    public function reconcile(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, false, $err)) return $err;
        return $this->run($response, fn () => $this->reconciliation->reconcile($this->currentSupplierId($request), $request->getQueryParams()['as_of'] ?? null));
    }

    public function unallocated(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, false, $err)) return $err;
        $q = $request->getQueryParams();
        $account = isset($q['account']) && preg_match('/^[0-9.]{1,20}$/', (string) $q['account']) === 1 ? (string) $q['account'] : null;
        return $this->run($response, fn () => $this->reconciliation->unallocated($this->currentSupplierId($request), $q['as_of'] ?? null, $account));
    }

    public function projection(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, false, $err)) return $err;
        return $this->run($response, fn () => $this->projection->list($this->currentSupplierId($request), $request->getQueryParams()['as_of'] ?? null));
    }

    public function closingPending(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, false, $err)) return $err;
        return $this->run($response, function () use ($request): array {
            $period = $this->period($request, (int) ($request->getQueryParams()['period_id'] ?? 0));
            return ['period_id' => (int) $period['id'], 'ends_on' => $period['ends_on']]
                + $this->posting->closingPending($this->currentSupplierId($request), (string) $period['starts_on'], (string) $period['ends_on']);
        });
    }

    /** @param array<string,string> $args */
    public function closingPost(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, false, $err)) return $err;
        if (!RequestAuthorization::canPostAccrualsInClosing($request)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění zaúčtovat rozpuštění v uzávěrce.', 403);
        }
        return $this->run($response, function () use ($request, $args): array {
            $period = $this->period($request, (int) $args['period']);
            $result = $this->posting->postYearlyForClosing($this->currentSupplierId($request), (string) $period['starts_on'], (string) $period['ends_on'], $this->meta($request));
            $this->logger->log('accrual_item.closing_posted', $this->userId($request), 'accounting_period', (int) $period['id'], $result,
                $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'), $this->currentSupplierId($request));
            return $result;
        });
    }

    public function takeoverPreview(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, true, $err)) return $err;
        return $this->run($response, fn () => $this->takeover->preview($this->currentSupplierId($request)));
    }

    public function takeoverApply(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, true, $err)) return $err;
        return $this->run($response, function () use ($request): array {
            $result = $this->takeover->apply($this->currentSupplierId($request), $this->userId($request));
            $this->logger->log('accrual_item.pohoda_takeover', $this->userId($request), 'supplier', $this->currentSupplierId($request),
                ['created' => $result['created'], 'updated' => $result['updated'], 'opening_remainder' => $result['summary']['opening_remainder']],
                $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'), $this->currentSupplierId($request));
            return $result;
        });
    }

    public function book(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, false, $err)) return $err;
        $q = $request->getQueryParams();
        $format = (string) ($q['format'] ?? '');
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            return Json::error($response, 'accruals.error.invalid_format', 'Formát musí být pdf nebo xlsx.', 422);
        }
        try {
            $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
            $book = $this->book->build($this->currentSupplierId($request), $q, $user);
            if ($format === 'xlsx') {
                $out = $this->xlsx->export($book);
                $bytes = $out['bytes'];
                $mime = $out['mime'];
            } else {
                $bytes = $this->pdf->render($book);
                $mime = 'application/pdf';
            }
        } catch (AccrualException $e) {
            return Json::error($response, 'accruals.error.' . $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        $response->getBody()->write($bytes);
        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Disposition', 'attachment; filename="rozpis-casoveho-rozliseni-' . $book['meta']['as_of'] . '.' . $format . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @return array<string,mixed> */
    private function period(Request $request, int $periodId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, starts_on, ends_on FROM accounting_periods WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$this->currentSupplierId($request), $periodId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new AccrualException('period_not_found', 'Účetní období neexistuje.', 404);
        }
        return $row;
    }

    private function guard(Request $request, Response $response, bool $write, ?Response &$err): bool
    {
        if (!$this->requirePermission($request, $response, 'accounting.accruals', AccessLevel::READ, $err)) return false;
        if ($write && !$this->requirePermission($request, $response, 'accounting.accruals.write', AccessLevel::WRITE, $err)) return false;
        return $this->requireDoubleEntry($this->db, $this->currentSupplierId($request), $response, $err);
    }

    private function guardPost(Request $request, Response $response, ?Response &$err): bool
    {
        if (!$this->guard($request, $response, true, $err)) return false;
        if (!RequestAuthorization::canPostAccruals($request)) {
            $err = Json::error($response, 'forbidden', 'Nemáte oprávnění účtovat v agendě časového rozlišení.', 403);
            return false;
        }
        return true;
    }

    /** @return array<string,mixed> */
    private function meta(Request $request): array
    {
        return [
            'user_id' => $this->userId($request),
            'ip' => $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            'user_agent' => mb_substr($request->getHeaderLine('User-Agent'), 0, 255),
        ];
    }

    private function run(Response $response, callable $call, int $status = 200): Response
    {
        try {
            return Json::ok($response, $call(), $status);
        } catch (AccrualException $e) {
            return Json::error($response, 'accruals.error.' . $e->errorCode, $e->getMessage(), $e->httpStatus,
                array_filter(['field' => $e->field] + $e->details, static fn ($v): bool => $v !== null));
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
    }

    /** @param array<string,mixed> $context */
    private function log(Request $request, string $action, int $id, array $context = []): void
    {
        $this->logger->log($action, $this->userId($request), 'accrual_item', $id, $context,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $this->currentSupplierId($request));
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array<string, array{0:mixed,1:mixed}>
     */
    private static function diff(array $before, array $after): array
    {
        $out = [];
        foreach (['title', 'contract_ref', 'note', 'counter_account_code', 'termination_account_code', 'partner_name', 'basis_amount',
            'period_from', 'period_to', 'method', 'cost_center'] as $k) {
            if (($before[$k] ?? null) != ($after[$k] ?? null)) {
                $out[$k] = [$before[$k] ?? null, $after[$k] ?? null];
            }
        }
        return $out;
    }
}
