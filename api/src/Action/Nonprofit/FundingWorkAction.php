<?php
declare(strict_types=1);
namespace MyInvoice\Action\Nonprofit;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Nonprofit\Funding\Work\FundingWorkException;
use MyInvoice\Service\Nonprofit\Funding\Work\FundingWorkService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Pracovní evidence čerpání dotací a účelových darů (#403). Interní routy pod
 * /api/accounting/periods/{id}/funding-work, jen přihlášená relace (ApiScopeMiddleware).
 * Čtení: `accounting` READ; zápis: {@see RequestAuthorization::canManageNonprofitFundingWork()}.
 */
final class FundingWorkAction
{
    use AccountingActionSupport;

    public function __construct(private readonly FundingWorkService $service) {}

    public function overview(Request $request, Response $response, array $args): Response
    {
        return $this->read($request, $response, fn(int $s) => $this->service->overview($s, (int) $args['id']));
    }

    public function choices(Request $request, Response $response, array $args): Response
    {
        return $this->read($request, $response, fn(int $s) => $this->service->choices($s, (int) $args['id'], $this->query($request, 'search')));
    }

    public function incomeCandidates(Request $request, Response $response, array $args): Response
    {
        return $this->read($request, $response, fn(int $s) => $this->service->incomeCandidates($s, (int) $args['id'], $this->query($request, 'search'), $this->page($request)));
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        return $this->read($request, $response, fn(int $s) => $this->service->detail($s, (int) $args['id'], (int) $args['sid']));
    }

    public function costCandidates(Request $request, Response $response, array $args): Response
    {
        return $this->read($request, $response, fn(int $s) => $this->service->costCandidates($s, (int) $args['id'], (int) $args['sid'], $this->query($request, 'search'), $this->page($request)));
    }

    public function costLine(Request $request, Response $response, array $args): Response
    {
        return $this->read($request, $response, fn(int $s) => $this->service->costLineAllocations($s, (int) $args['id'], (int) $args['lid']));
    }

    public function create(Request $request, Response $response, array $args): Response
    {
        return $this->write($request, $response, function (int $s, array $body, int $actor) use ($args) {
            return $this->service->create($s, (int) $args['id'], $body, $actor);
        }, 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        return $this->write($request, $response, function (int $s, array $body, int $actor) use ($args) {
            [$version, $reason] = [$this->version($body), $this->text($body, 'reason')];
            unset($body['expected_version'], $body['reason']);
            return $this->service->update($s, (int) $args['id'], (int) $args['sid'], $body, $version, $reason, $actor);
        });
    }

    public function cancel(Request $request, Response $response, array $args): Response
    {
        return $this->write($request, $response, fn(int $s, array $body, int $actor) => $this->service->cancel($s, (int) $args['id'], (int) $args['sid'], $this->version($body), $this->text($body, 'reason'), $actor));
    }

    public function addIncomes(Request $request, Response $response, array $args): Response
    {
        return $this->write($request, $response, fn(int $s, array $body, int $actor) => $this->service->addIncomes($s, (int) $args['id'], (int) $args['sid'], is_array($body['line_ids'] ?? null) ? $body['line_ids'] : [], $actor));
    }

    public function removeIncome(Request $request, Response $response, array $args): Response
    {
        return $this->write($request, $response, fn(int $s, array $body, int $actor) => $this->service->removeIncome($s, (int) $args['id'], (int) $args['iid'], $this->version($body), $this->text($body, 'reason'), $actor));
    }

    public function relinkIncome(Request $request, Response $response, array $args): Response
    {
        return $this->write($request, $response, fn(int $s, array $body, int $actor) => $this->service->relinkIncome($s, (int) $args['id'], (int) $args['iid'], $this->lineId($body), $this->version($body), $actor));
    }

    public function allocate(Request $request, Response $response, array $args): Response
    {
        return $this->write($request, $response, fn(int $s, array $body, int $actor) => $this->service->allocate($s, (int) $args['id'], (int) $args['sid'], is_array($body['items'] ?? null) ? $body['items'] : [], $actor));
    }

    public function updateAllocation(Request $request, Response $response, array $args): Response
    {
        return $this->write($request, $response, fn(int $s, array $body, int $actor) => $this->service->updateAllocation($s, (int) $args['id'], (int) $args['aid'], $body['amount_cents'] ?? null, $body['note'] ?? null, $this->version($body), $actor));
    }

    public function removeAllocation(Request $request, Response $response, array $args): Response
    {
        return $this->write($request, $response, fn(int $s, array $body, int $actor) => $this->service->removeAllocation($s, (int) $args['id'], (int) $args['aid'], $this->version($body), $this->text($body, 'reason'), $actor));
    }

    public function relinkAllocation(Request $request, Response $response, array $args): Response
    {
        return $this->write($request, $response, fn(int $s, array $body, int $actor) => $this->service->relinkAllocation($s, (int) $args['id'], (int) $args['aid'], $this->lineId($body), $body['amount_cents'] ?? null, $this->version($body), $actor));
    }

    private function read(Request $request, Response $response, callable $work): Response
    {
        if (!$this->requirePermission($request, $response, 'accounting', AccessLevel::READ, $error)) return $error;
        return $this->run($response, fn() => $work($this->currentSupplierId($request)), 200);
    }

    private function write(Request $request, Response $response, callable $work, int $status = 200): Response
    {
        if (!RequestAuthorization::canManageNonprofitFundingWork($request)) return Json::error($response, 'forbidden', 'Pro tuto akci nemáte oprávnění.', 403);
        $actor = $this->userId($request);
        if ($actor === null) return Json::error($response, 'forbidden', 'Pro tuto akci nemáte oprávnění.', 403);
        $body = $request->getParsedBody();
        if ($body !== null && !is_array($body)) return Json::error($response, 'funding_work_invalid', 'Neplatné tělo požadavku.', 422);
        return $this->run($response, fn() => $work($this->currentSupplierId($request), $body ?? [], $actor), $status);
    }

    private function run(Response $response, callable $work, int $status): Response
    {
        try {
            return Json::ok($response, $work(), $status);
        } catch (FundingWorkException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->extra);
        } catch (\PDOException $e) {
            // Transakci už odvolal a vrátil DimensionPeriodGuard; souběžný zápis jiného uživatele = zkusit znovu.
            if (TransactionFailure::lockConflict($e)) return Json::error($response, 'funding_work_busy', 'Evidenci právě mění jiný uživatel. Zkuste to prosím znovu.', 409);
            throw $e;
        }
    }

    private function query(Request $request, string $key): string
    {
        $value = $request->getQueryParams()[$key] ?? '';
        if (!is_string($value)) throw new FundingWorkException('funding_work_invalid', 'Neplatný parametr dotazu.');
        return $value;
    }

    private function page(Request $request): int
    {
        $page = $request->getQueryParams()['page'] ?? '1';
        if (!is_string($page) || !preg_match('/^[1-9][0-9]{0,5}$/D', $page)) throw new FundingWorkException('funding_work_invalid', 'Neplatná stránka.');
        return (int) $page;
    }

    private function version(array $body): int
    {
        $version = $body['expected_version'] ?? null;
        if (!is_int($version) || $version <= 0) throw new FundingWorkException('funding_work_invalid', 'Chybí očekávaná verze záznamu.');
        return $version;
    }

    private function lineId(array $body): int
    {
        $line = $body['line_id'] ?? null;
        if (!is_int($line) || $line <= 0) throw new FundingWorkException('funding_work_invalid', 'Neplatný řádek deníku.');
        return $line;
    }

    private function text(array $body, string $key): string
    {
        $value = $body[$key] ?? '';
        if (!is_string($value)) throw new FundingWorkException('funding_work_invalid', 'Neplatný text.');
        return $value;
    }
}
