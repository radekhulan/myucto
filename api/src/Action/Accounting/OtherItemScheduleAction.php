<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\OtherItemScheduleService;
use MyInvoice\Service\Accounting\OtherItemService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class OtherItemScheduleAction
{
    use AccountingActionSupport;

    public function __construct(
        private readonly OtherItemScheduleService $service,
        private readonly OtherItemService $items,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::READ, $err)) return $err;
        return $this->run($response, fn () => ['items' => $this->service->list($this->currentSupplierId($request))]);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::READ, $err)) return $err;
        return $this->run($response, fn () => $this->service->get($this->currentSupplierId($request), (int) $args['id']));
    }

    public function create(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::WRITE, $err)) return $err;
        return $this->run($response, function () use ($request, $args) {
            $supplierId = $this->currentSupplierId($request);
            $body = (array) ($request->getParsedBody() ?? []);
            if (($body['auto_post'] ?? false) === true) {
                $this->assertAutoPostChannel($request);
                $this->assertAutoPostPermission($request, $supplierId);
            }
            return $this->service->create($supplierId, (int) $args['item_id'], $body, $this->userId($request));
        }, 201);
    }

    public function generate(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::WRITE, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        return $this->run($response, function () use ($request, $args, $body) {
            $supplierId = $this->currentSupplierId($request);
            $schedule = $this->service->get($supplierId, (int) $args['id']);
            if ($schedule['auto_post']) {
                $this->assertAutoPostChannel($request);
                $this->assertAutoPostPermission($request, $supplierId);
            }
            return $this->service->generate($supplierId, (int) $args['id'], (string) ($body['through'] ?? ''), $this->userId($request), $this->canAutoPost($request, $supplierId));
        });
    }

    public function status(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::WRITE, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        if (array_key_exists('auto_post', $body) && !is_bool($body['auto_post'])) {
            return Json::error($response, 'other_items.error.invalid_auto_post', 'Volba automatického účtování musí být boolean.', 422);
        }
        return $this->run($response, function () use ($request, $args, $body) {
            $supplierId = $this->currentSupplierId($request);
            $schedule = $this->service->get($supplierId, (int) $args['id']);
            if (($body['auto_post'] ?? false) === true
                || ($body['status'] ?? '') === 'active' && ($body['auto_post'] ?? $schedule['auto_post'])) {
                $this->assertAutoPostChannel($request);
                $this->assertAutoPostPermission($request, $supplierId);
            }
            return $this->service->setStatus($supplierId, (int) $args['id'], (string) ($body['status'] ?? ''), $body['auto_post'] ?? null, $this->canAutoPost($request, $supplierId));
        });
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::WRITE, $err)) return $err;
        return $this->run($response, function () use ($request, $args) {
            $this->service->delete($this->currentSupplierId($request), (int) $args['id']);
            return ['deleted' => true];
        });
    }

    public function installments(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::READ, $err)) return $err;
        return $this->run($response, fn () => ['items' => $this->service->installments($this->currentSupplierId($request),
            (int) $args['item_id'])]);
    }

    public function setInstallments(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::WRITE, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        $rows = $body['items'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            return Json::error($response, 'other_items.error.invalid_installments', 'Zadejte seznam splátek.', 422);
        }
        return $this->run($response, fn () => ['items' => $this->service->setInstallments(
            $this->currentSupplierId($request), (int) $args['item_id'], $rows)]);
    }

    /** Návrh splátkového kalendáře úvěru (anuita, lineární); nic neukládá. */
    public function planInstallments(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::READ, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        return $this->run($response, fn () => ['items' => $this->service->planLoan(
            $this->currentSupplierId($request), (int) $args['item_id'], $body)]);
    }

    /**
     * Automatické účtování opakování smí zapnout, obnovit nebo spustit jen člověk
     * v aplikaci. API token (a MCP nad ním) má k ostatním položkám zápisovou
     * výjimku jen pro koncepty, viz ApiScopeMiddleware::BEARER_WRITE_EXCEPTIONS;
     * zapnutá automatika by z tokenu udělala účtující kanál přes cron.
     */
    private function assertAutoPostChannel(Request $request): void
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            throw new OtherItemException('auto_post_session_only',
                'Automatické účtování opakování lze zapnout jen ve webovém rozhraní. Přes API token vzniknou pouze koncepty.', 403);
        }
    }

    private function assertAutoPostPermission(Request $request, int $supplierId): void
    {
        if (!$this->canAutoPost($request, $supplierId)) {
            throw new OtherItemException('forbidden', 'Pro automatické účtování nemáte oprávnění.', 403);
        }
    }

    private function canAutoPost(Request $request, int $supplierId): bool
    {
        return !$this->items->isDoubleEntry($supplierId)
            || RequestAuthorization::allows($request, 'accounting.journal.post', AccessLevel::WRITE);
    }

    private function run(Response $response, callable $callback, int $status = 200): Response
    {
        try {
            return Json::ok($response, $callback(), $status);
        } catch (OtherItemException $e) {
            return Json::error($response, 'other_items.error.' . $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (ClosingException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
    }
}
