<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\OtherItemBankMatchService;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Accounting\Obligations\ExistingObligationSourceService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class OtherItemAction
{
    use AccountingActionSupport;

    public function __construct(
        private readonly OtherItemService $service,
        private readonly OtherItemBankMatchService $bankMatch,
        private readonly ExistingObligationSourceService $sources,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::READ, $err)) return $err;
        $q = $request->getQueryParams();
        $filters = [];
        foreach (['side', 'status', 'kind', 'q', 'from', 'to', 'sort_by', 'sort_dir'] as $field) {
            if (isset($q[$field]) && is_scalar($q[$field])) $filters[$field] = trim((string) $q[$field]);
        }
        if (isset($q['kind_id']) && is_scalar($q['kind_id']) && trim((string) $q['kind_id']) !== '') {
            $kindId = filter_var($q['kind_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($kindId === false) {
                return Json::error($response, 'other_items.error.invalid_kind', 'Neplatný druh dokladu.', 422);
            }
            $filters['kind_id'] = $kindId;
        }
        $hasFrom = isset($filters['from']);
        $hasTo = isset($filters['to']);
        $from = $hasFrom ? $filters['from'] : ($hasTo ? '1000-01-01' : date('Y-m-d', strtotime('-1 year')));
        $to = $hasTo ? $filters['to'] : ($hasFrom ? '9999-12-31' : date('Y-m-d', strtotime('+1 year')));
        $fromDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $toDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if ($fromDate === false || $fromDate->format('Y-m-d') !== $from
            || $toDate === false || $toDate->format('Y-m-d') !== $to || $from > $to) {
            return Json::error($response, 'other_items.error.invalid_date', 'Neplatný rozsah splatnosti.', 422);
        }
        $result = $this->service->list(
            $this->currentSupplierId($request), $filters,
            (int) ($q['page'] ?? 1), (int) ($q['per_page'] ?? 50),
        );
        $derived = [];
        $sid = $this->currentSupplierId($request);
        $forecastFrom = max($from, date('Y-m-d'));
        $forecastTo = min($to, date('Y-m-d', strtotime('+90 days')));
        if (RequestAuthorization::allows($request, 'reports', AccessLevel::READ)) {
            $derived = $this->sources->taxAdvances($sid, $from, $to);
            if ($forecastFrom <= $forecastTo) {
                array_push($derived, ...$this->sources->taxForecasts($sid, $forecastFrom, $forecastTo));
            }
        }
        if (RequestAuthorization::allows($request, 'payroll.payments', AccessLevel::READ)) {
            array_push($derived, ...$this->sources->payrollLiabilities($sid, $from, $to));
            if ($forecastFrom <= $forecastTo) {
                array_push($derived, ...$this->sources->payrollForecasts($sid, $forecastFrom, $forecastTo));
            }
        }
        $result['sources'] = array_values(array_map(
            static function (array $row): array {
                $row['remaining_amount'] = $row['remaining'];
                unset($row['remaining']);
                return $row;
            },
            array_filter($derived, static fn (array $row): bool =>
                (!isset($filters['status']) || $filters['status'] === 'all' || (float) $row['remaining'] > 0)
                && (!isset($filters['side']) || $row['side'] === $filters['side'])
                && !isset($filters['kind_id'])
                && (!isset($filters['kind']) || $row['kind'] === $filters['kind'])
                && (!isset($filters['status']) || $filters['status'] === 'all'
                    || $filters['status'] === 'open' && $row['status'] !== 'paid'
                    || $row['status'] === $filters['status'])
                && (!isset($filters['q']) || stripos((string) $row['title'], $filters['q']) !== false)
            ),
        ));
        return Json::ok($response, $result);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::READ, $err)) return $err;
        return $this->run($response, fn () => $this->service->get($this->currentSupplierId($request), (int) $args['id']));
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::WRITE, $err)) return $err;
        return $this->run($response, function () use ($request) {
            $item = $this->service->create($this->currentSupplierId($request), (array) ($request->getParsedBody() ?? []), $this->userId($request));
            $this->log($request, 'other_item.created', (int) $item['id']);
            return $item;
        }, 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::WRITE, $err)) return $err;
        return $this->run($response, function () use ($request, $args) {
            $id = (int) $args['id'];
            $this->assertSessionForAutoPostDraft($request, $id, 'Upravit');
            $item = $this->service->update($this->currentSupplierId($request), $id, (array) ($request->getParsedBody() ?? []), $this->userId($request));
            $this->log($request, 'other_item.updated', $id);
            return $item;
        });
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::WRITE, $err)) return $err;
        return $this->run($response, function () use ($request, $args) {
            $id = (int) $args['id'];
            $this->assertSessionForAutoPostDraft($request, $id, 'Smazat');
            $this->service->deleteDraft($this->currentSupplierId($request), $id);
            $this->log($request, 'other_item.deleted', $id);
            return ['deleted' => true];
        });
    }

    public function post(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::WRITE, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if ($this->service->isDoubleEntry($supplierId)
            && !$this->requirePermission($request, $response, 'accounting.journal.post', AccessLevel::WRITE, $err)) return $err;
        return $this->run($response, function () use ($request, $args, $supplierId) {
            $id = (int) $args['id'];
            $item = $this->service->post($supplierId, $id, $this->userId($request));
            $this->log($request, 'other_item.posted', $id);
            return $item;
        });
    }

    public function reverse(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::WRITE, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        return $this->run($response, function () use ($request, $args, $supplierId, $response) {
            $id = (int) $args['id'];
            $existing = $this->service->get($supplierId, $id);
            if ($existing['journal_entry_id'] !== null
                && !$this->requirePermission($request, $response, 'accounting.journal.post', AccessLevel::WRITE, $err)) {
                throw new OtherItemException('forbidden', 'Pro storno zaúčtovaného dokladu nemáš oprávnění.', 403);
            }
            // Položka ze zůstatku nese předpis v řádku zápisu; storno mění saldokonto stejně
            // jako zrušení rozpadu zápisu, proto potřebuje i právo zápisu do deníku.
            if ($existing['journal_entry_id'] === null && $this->service->hasJournalOrigin($supplierId, $id)
                && !$this->requirePermission($request, $response, 'accounting.journal.write', AccessLevel::WRITE, $err)) {
                throw new OtherItemException('forbidden', 'Pro storno položky ze zůstatku zápisu nemáš oprávnění.', 403);
            }
            $body = (array) ($request->getParsedBody() ?? []);
            $item = $this->service->reverse($supplierId, $id, (string) ($body['reason'] ?? ''),
                $this->userId($request), isset($body['entry_date']) && $body['entry_date'] !== '' ? (string) $body['entry_date'] : null);
            $this->log($request, 'other_item.reversed', $id);
            return $item;
        });
    }

    public function repost(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::WRITE, $err)) return $err;
        if (!$this->requirePermission($request, $response, 'accounting.journal.post', AccessLevel::WRITE, $err)) return $err;
        return $this->run($response, function () use ($request, $args) {
            $id = (int) $args['id'];
            $item = $this->service->repost($this->currentSupplierId($request), $id,
                (array) ($request->getParsedBody() ?? []), $this->userId($request));
            $this->log($request, 'other_item.reposted', $id);
            return $item;
        });
    }

    public function allocations(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::READ, $err)) return $err;
        return $this->run($response, function () use ($request, $args): array {
            $canReadBank = RequestAuthorization::allows($request, 'bank', AccessLevel::READ);
            $canReadCash = RequestAuthorization::allows($request, 'cash', AccessLevel::READ);
            $rows = $this->service->allocations($this->currentSupplierId($request), (int) $args['id']);
            return ['items' => array_values(array_filter($rows, static fn (array $row): bool =>
                $row['bank_transaction_id'] !== null ? $canReadBank : $canReadCash))];
        });
    }

    public function paymentCandidates(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::READ, $err)) return $err;
        $canReadBank = RequestAuthorization::allows($request, 'bank', AccessLevel::READ);
        $canReadCash = RequestAuthorization::allows($request, 'cash', AccessLevel::READ);
        if (!$canReadBank && !$canReadCash) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        $q = $request->getQueryParams();
        return $this->run($response, function () use ($request, $args, $q, $canReadBank, $canReadCash): array {
            return ['items' => $this->service->paymentCandidates(
                $this->currentSupplierId($request), (int) $args['id'],
                (string) ($q['q'] ?? ''), (int) ($q['limit'] ?? 20), $canReadBank, $canReadCash)];
        });
    }

    /** Otevřené ostatní položky pro dialog „Spárovat" u bankovního pohybu. */
    public function bankMatchCandidates(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::READ, $err)) return $err;
        if (RequestAuthorization::isClientType($request)
            || !RequestAuthorization::allows($request, 'bank', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        $q = $request->getQueryParams();
        $txId = (int) ($q['bank_transaction_id'] ?? 0);
        if ($txId <= 0) {
            return Json::error($response, 'validation_failed', 'Chybí bankovní pohyb.', 422);
        }
        $search = is_string($q['q'] ?? null) ? mb_substr(trim($q['q']), 0, 100) : '';
        return $this->run($response, fn (): array => ['candidates' => $this->bankMatch->candidates(
            $this->currentSupplierId($request), $txId, $search, (int) ($q['limit'] ?? 25))]);
    }

    /** Přiřadí bankovní pohyb položce; nezaúčtovaný pohyb zároveň zaúčtuje proti jejímu účtu. */
    public function matchBankTransaction(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::WRITE, $err)) return $err;
        if (RequestAuthorization::isClientType($request) || !$this->canManagePayment($request, 'bank')) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        $txId = (int) (((array) ($request->getParsedBody() ?? []))['bank_transaction_id'] ?? 0);
        if ($txId <= 0) {
            return Json::error($response, 'validation_failed', 'Chybí bankovní pohyb.', 422);
        }
        return $this->run($response, function () use ($request, $args, $txId) {
            $id = (int) $args['id'];
            $item = $this->bankMatch->match($this->currentSupplierId($request), $txId, $id,
                $this->userId($request), $this->auditMeta($request),
                // Stejná práva jako ruční zaúčtování pohybu (POST /bank-transactions/{id}/post).
                RequestAuthorization::allows($request, 'bank.post', AccessLevel::WRITE)
                    && RequestAuthorization::allows($request, 'accounting', AccessLevel::WRITE));
            $this->logger->log('other_item.payment_allocated', $this->userId($request), 'other_item', $id,
                ['bank_transaction_id' => $txId],
                $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                $request->getHeaderLine('User-Agent'), $this->currentSupplierId($request));
            return $item;
        });
    }

    public function allocate(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::WRITE, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        $bankId = (int) ($body['bank_transaction_id'] ?? 0);
        $cashId = (int) ($body['cash_document_id'] ?? 0);
        if (($bankId > 0 && $cashId <= 0 && !$this->canManagePayment($request, 'bank'))
            || ($cashId > 0 && $bankId <= 0 && !$this->canManagePayment($request, 'cash'))) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        return $this->run($response, function () use ($request, $args) {
            $id = (int) $args['id'];
            $item = $this->service->allocate($this->currentSupplierId($request), $id,
                (array) ($request->getParsedBody() ?? []), $this->userId($request));
            $this->log($request, 'other_item.payment_allocated', $id);
            return $item;
        });
    }

    public function unallocate(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::WRITE, $err)) return $err;
        return $this->run($response, function () use ($request, $args) {
            $id = (int) $args['id'];
            $supplierId = $this->currentSupplierId($request);
            $allocationId = (int) $args['allocation_id'];
            $allocation = null;
            foreach ($this->service->allocations($supplierId, $id) as $row) {
                if ((int) $row['id'] === $allocationId) {
                    $allocation = $row;
                    break;
                }
            }
            if ($allocation === null) {
                throw new OtherItemException('payment_not_found', 'Úhrada nebyla nalezena.', 404);
            }
            $source = $allocation['bank_transaction_id'] !== null ? 'bank' : 'cash';
            if (!$this->canManagePayment($request, $source)) {
                throw new OtherItemException('forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
            }
            $item = $this->service->unallocate($supplierId, $id, $allocationId);
            $this->log($request, 'other_item.payment_unallocated', $id);
            return $item;
        });
    }

    /**
     * Koncept z opakování s automatickým účtováním zaúčtuje cron bez dalšího
     * potvrzení. Kdyby ho upravil token, zaúčtoval by se obsah, který žádný
     * člověk neviděl; kdyby ho token smazal, tiše by zmizelo jeho zaúčtování.
     * Obojí proto necháváme webovému rozhraní. Vypnout automatiku (pozastavit
     * nebo přepnout rozvrh bez auto_post) token smí, tím účtování jen zastaví.
     */
    private function assertSessionForAutoPostDraft(Request $request, int $id, string $action): void
    {
        if (!RequestAuthorization::isSessionAuth($request)
            && $this->service->belongsToAutoPostSchedule($this->currentSupplierId($request), $id)) {
            throw new OtherItemException('auto_post_session_only',
                'Koncept patří do opakování s automatickým účtováním. ' . $action . ' ho lze jen ve webovém rozhraní.', 403);
        }
    }

    private function canManagePayment(Request $request, string $source): bool
    {
        if ($source === 'bank') {
            return RequestAuthorization::allows($request, 'bank', AccessLevel::READ)
                && RequestAuthorization::allows($request, 'bank.match', AccessLevel::WRITE);
        }
        return RequestAuthorization::allows($request, 'cash', AccessLevel::READ)
            && RequestAuthorization::allows($request, 'cash.document.write', AccessLevel::WRITE);
    }

    private function run(Response $response, callable $call, int $status = 200): Response
    {
        try {
            return Json::ok($response, $call(), $status);
        } catch (OtherItemException $e) {
            return Json::error($response, 'other_items.error.' . $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (ClosingException $e) {
            return Json::error($response, 'other_items.error.' . $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
    }

    private function log(Request $request, string $action, int $id): void
    {
        $this->logger->log($action, $this->userId($request), 'other_item', $id, [],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $this->currentSupplierId($request));
    }
}
