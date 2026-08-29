<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollRunConflictException;
use MyInvoice\Repository\Payroll\PayrollRunDeletionException;
use MyInvoice\Repository\Payroll\PayrollRunIdempotencyException;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollPeriodOwnedException;
use MyInvoice\Service\Payroll\PayrollPeriodOwnershipService;
use MyInvoice\Service\Payroll\PayrollYearClosedException;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandResult;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandService;
use MyInvoice\Service\Payroll\Run\PayrollRunPaymentsUnsettledException;
use MyInvoice\Service\Payroll\Run\PayrollRunWorkflow;
use MyInvoice\Service\Payroll\Run\PayrollRunStatus;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollRunsAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollRunCommandService $commands,
        private readonly PayrollRunRepository $runs,
        private readonly PayrollRunWorkflow $workflow,
        private readonly PayrollModuleAccess $access,
        private readonly PayrollPeriodOwnershipService $ownership,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /**
     * Stav rezervace mzdového období.
     *
     * @param array<string,string> $args
     */
    public function periodOwnership(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll',
            AccessLevel::READ,
        )) !== null) {
            return $error;
        }
        try {
            [$year, $month] = self::periodParts($args['period'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, [
            'ownership' => $this->ownership->legacyClaimStatus(
                $this->currentSupplierId($request),
                $year,
                $month,
            ),
        ]);
    }

    /**
     * Uvolnění rezervace, kterou drží PŮVODNÍ ruční zaúčtování.
     *
     * Bez téhle cesty stačilo, aby legacy větev (i z cronu) zabrala měsíc dřív
     * než modul, a mzdový běh za ten měsíc už nešlo založit jinak než ručním
     * zásahem do databáze. Uvolnění je fail-closed — služba ho odmítne, dokud
     * za období existuje aktivní legacy zaúčtování nebo záznam ve mzdovém
     * listu — a je auditované včetně povinného důvodu.
     *
     * @param array<string,string> $args
     */
    public function releaseLegacyPeriod(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll.reopen',
            AccessLevel::WRITE,
        )) !== null) {
            return $error;
        }
        $body = $this->input($request);
        try {
            [$year, $month] = self::periodParts($args['period'] ?? null);
            $this->ownership->releaseLegacy(
                $this->currentSupplierId($request),
                $year,
                $month,
                $this->requiredUserId($request),
                $this->requiredString($body, 'reason'),
                $this->clientIp($request),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (PayrollPeriodOwnedException $e) {
            return Json::error(
                $response,
                'payroll_period_owned',
                $e->getMessage(),
                409,
            );
        } catch (\InvalidArgumentException|\DomainException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, [
            'released' => true,
            'ownership' => $this->ownership->legacyClaimStatus(
                $this->currentSupplierId($request),
                $year,
                $month,
            ),
        ]);
    }

    /** @return array{0:int,1:int} */
    private static function periodParts(mixed $period): array
    {
        if (!is_string($period)
            || preg_match('/^([0-9]{4})-(0[1-9]|1[0-2])$/D', $period, $m) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Období musí mít formát YYYY-MM.',
            );
        }

        return [(int) $m[1], (int) $m[2]];
    }

    private function clientIp(Request $request): string
    {
        $serverParams = $request->getServerParams();

        return $this->ipMatcher->clientIpFromRequest($serverParams);
    }

    public function list(Request $request, Response $response): Response
    {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll',
            AccessLevel::READ,
        )) !== null) {
            return $error;
        }
        try {
            $period = $this->optionalPeriod(
                $request->getQueryParams()['period'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        $query = $request->getQueryParams();
        // Období je volitelné, takže bez stropu tenhle endpoint četl kompletní
        // mzdovou historii firmy. Strop je tvrdý (ne jen výchozí), aby ho nešlo
        // obejít parametrem z URL.
        $limit = max(1, min(
            PayrollRunRepository::LIST_MAX_LIMIT,
            (int) ($query['limit'] ?? PayrollRunRepository::LIST_DEFAULT_LIMIT),
        ));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        $page = $this->runs->list(
            $this->currentSupplierId($request),
            $period === null ? null : "{$period}-01",
            $limit,
            $offset,
        );
        $items = $page['items'];
        foreach ($items as &$item) {
            $status = PayrollTimeValue::string(
                $item['status'] ?? null,
                'run.status',
            );
            $revisionId = ($item['revision_id'] ?? null) === null
                ? null
                : PayrollTimeValue::int(
                    $item['revision_id'],
                    'run.revision_id',
                );
            $item['available_commands'] = array_map(
                static fn ($command): string => $command->value,
                $this->workflow->availableCommands(
                    PayrollRunStatus::from($status),
                ),
            );
            $item['validations'] = $revisionId === null
                ? []
                : $this->runs->validations(
                    $this->currentSupplierId($request),
                    $revisionId,
                );
            $deletion = $this->runs->canDelete(
                $this->currentSupplierId($request),
                PayrollTimeValue::int($item['id'] ?? null, 'run.id'),
            );
            $item['can_delete'] = $deletion !== null && $deletion->canDelete;
        }
        unset($item);

        // Klíč `runs` zůstává, aby stávající volající nespadli; `total`/`limit`/`offset`
        // přibyly vedle něj, protože seznam už nemusí být úplný.
        return Json::ok($response, [
            'runs' => $items,
            'total' => $page['total'],
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * Detail běhu s CELÝM výsledkovým snapshotem — objemná data, která seznam
     * záměrně neposílá. Frontend si je dotahuje pro jeden rozbalený běh.
     *
     * @param array<string,string> $args
     */
    public function detail(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll',
            AccessLevel::READ,
        )) !== null) {
            return $error;
        }
        $run = $this->runs->detail(
            $this->currentSupplierId($request),
            (int) ($args['id'] ?? 0),
        );
        if ($run === null) {
            return Json::error($response, 'not_found', 'Mzdový běh neexistuje.', 404);
        }

        return Json::ok($response, ['run' => $run]);
    }

    /** @param array<string,string> $args */
    public function history(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll',
            AccessLevel::READ,
        )) !== null) {
            return $error;
        }
        $history = $this->runs->history(
            $this->currentSupplierId($request),
            (int) ($args['id'] ?? 0),
        );
        if ($history === null) {
            return Json::error($response, 'not_found', 'Mzdový běh neexistuje.', 404);
        }

        return Json::ok($response, ['history' => $history]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll.inputs.write',
            AccessLevel::WRITE,
        )) !== null) {
            return $error;
        }
        $body = $this->input($request);
        $officeId = $body['office_id'] ?? null;
        if ($officeId !== null) {
            $officeId = filter_var($officeId, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if (!is_int($officeId)) {
                return Json::error(
                    $response,
                    'validation_failed',
                    'office_id musí být kladné celé číslo.',
                    422,
                );
            }
        }
        try {
            $run = $this->commands->createRun(
                $this->currentSupplierId($request),
                $this->requiredString($body, 'period_start'),
                $this->requiredString($body, 'payment_date'),
                $officeId,
                $this->requiredUserId($request),
            );
        } catch (PayrollYearClosedException $e) {
            return Json::error($response, 'payroll_year_closed', $e->getMessage(), 409);
        } catch (PayrollPeriodOwnedException $e) {
            return Json::error($response, 'payroll_period_owned', $e->getMessage(), 409);
        } catch (\InvalidArgumentException|\DomainException|\OutOfBoundsException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        return Json::ok($response, ['run' => $run], 201);
    }

    /** @param array<string,string> $args */
    public function delete(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll.inputs.write',
            AccessLevel::WRITE,
        )) !== null) {
            return $error;
        }
        $body = $this->input($request);
        $version = filter_var($body['row_version'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $runId = filter_var($args['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if (!is_int($version) || !is_int($runId)) {
            return Json::error(
                $response,
                'validation_failed',
                'Smazání vyžaduje platné ID běhu a row_version.',
                422,
            );
        }
        try {
            $this->commands->deleteRun(
                $this->currentSupplierId($request),
                $runId,
                $version,
                $this->requiredUserId($request),
            );
        } catch (PayrollYearClosedException $e) {
            return Json::error($response, 'payroll_year_closed', $e->getMessage(), 409);
        } catch (PayrollRunConflictException $e) {
            return Json::error(
                $response,
                'row_version_conflict',
                $e->getMessage(),
                409,
                ['current_row_version' => $e->currentVersion],
            );
        } catch (PayrollRunDeletionException $e) {
            return Json::error(
                $response,
                $e->errorCode,
                $e->getMessage(),
                409,
            );
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return Json::error(
                $response,
                'validation_failed',
                $e->getMessage(),
                422,
            );
        }

        return Json::ok($response, [
            'deleted' => true,
            'run_id' => $runId,
        ]);
    }

    /** @param array<string,string> $args */
    public function command(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        $command = (string) ($args['command'] ?? '');
        $permission = match ($command) {
            'calculate' => 'payroll.calculate',
            'review', 'request_correction' => 'payroll.review',
            'approve' => 'payroll.approve',
            'reopen' => 'payroll.reopen',
            // `post` vytváří účetní zápis v hlavní knize — patří pod stejné
            // právo jako ostatní mzdové zaúčtování („Zaúčtovat mzdy"), ne pod
            // právo na zápis mzdových vstupů.
            'post' => 'payroll.post',
            // `prepare_payments` materializuje platební závazky a `mark_paid`
            // uzavírá platební ledger. Obojí je táž agenda jako platební dávky
            // a párování úhrad, které už `payroll.payments` chrání.
            'prepare_payments', 'mark_paid' => 'payroll.payments',
            // `close` je poslední pečeť běhu — období se tím uzavře stejně
            // závazně, jako ho `approve` schválil. `cancel` je naopak nevratné
            // zneplatnění už rozpracovaného (i schváleného) běhu, tedy stejná
            // třída zásahu jako odemknutí. Ani jedno není zápis mzdového
            // vstupu, takže catch-all `payroll.inputs.write` na ně nestačí.
            'close' => 'payroll.approve',
            'cancel' => 'payroll.reopen',
            default => 'payroll.inputs.write',
        };
        if (($error = $this->authorize(
            $request,
            $response,
            $permission,
            AccessLevel::WRITE,
        )) !== null) {
            return $error;
        }
        $body = $this->input($request);
        $version = filter_var($body['row_version'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $runId = filter_var($args['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $idempotencyKey = trim($request->getHeaderLine('Idempotency-Key'));
        if (!is_int($version) || !is_int($runId) || $idempotencyKey === '') {
            return Json::error(
                $response,
                'validation_failed',
                'Příkaz vyžaduje row_version a hlavičku Idempotency-Key.',
                422,
            );
        }
        $reason = isset($body['reason']) && is_string($body['reason'])
            ? trim($body['reason'])
            : '';
        try {
            $result = $this->dispatch(
                $command,
                $this->currentSupplierId($request),
                $runId,
                $version,
                $idempotencyKey,
                $this->requiredUserId($request),
                $reason,
            );
        } catch (PayrollYearClosedException $e) {
            return Json::error($response, 'payroll_year_closed', $e->getMessage(), 409);
        } catch (PayrollRunConflictException $e) {
            return Json::error(
                $response,
                'row_version_conflict',
                $e->getMessage(),
                409,
                ['current_row_version' => $e->currentVersion],
            );
        } catch (PayrollRunIdempotencyException $e) {
            return Json::error(
                $response,
                'idempotency_conflict',
                $e->getMessage(),
                409,
            );
        } catch (PayrollRunPaymentsUnsettledException $e) {
            $hasIncomingRefund =
                $e->coverage['incoming_unsettled_count'] > 0;
            return Json::error(
                $response,
                $hasIncomingRefund
                    ? 'payroll_incoming_refund_unresolved'
                    : 'payroll_payments_unsettled',
                $e->getMessage(),
                422,
                [
                    'required_minor' => $e->coverage['required_minor'],
                    'settled_minor' => $e->coverage['settled_minor'],
                    'uncovered_count' => count($e->coverage['uncovered']),
                    'incoming_unsettled_count' =>
                        $e->coverage['incoming_unsettled_count'],
                    'liability_count' => $e->coverage['liability_count'],
                ],
            );
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        return Json::ok($response, $this->serialize($result));
    }

    private function dispatch(
        string $command,
        int $supplierId,
        int $runId,
        int $version,
        string $idempotencyKey,
        int $userId,
        string $reason,
    ): PayrollRunCommandResult {
        return match ($command) {
            'lock_inputs' => $this->commands->lockInputs(
                $supplierId, $runId, $version, $idempotencyKey, $userId,
            ),
            'calculate' => $this->commands->calculate(
                $supplierId, $runId, $version, $idempotencyKey, $userId,
            ),
            'review' => $this->commands->review(
                $supplierId, $runId, $version, $idempotencyKey, $userId,
            ),
            'approve' => $this->commands->approve(
                $supplierId, $runId, $version, $idempotencyKey, $userId,
            ),
            'request_correction' => $this->commands->requestCorrection(
                $supplierId,
                $runId,
                $version,
                $idempotencyKey,
                $userId,
                $reason,
            ),
            'reopen' => $this->commands->reopen(
                $supplierId,
                $runId,
                $version,
                $idempotencyKey,
                $userId,
                $reason,
            ),
            'cancel' => $this->commands->cancel(
                $supplierId,
                $runId,
                $version,
                $idempotencyKey,
                $userId,
                $reason,
            ),
            'post' => $this->commands->post(
                $supplierId, $runId, $version, $idempotencyKey, $userId,
            ),
            'prepare_payments' => $this->commands->preparePayments(
                $supplierId, $runId, $version, $idempotencyKey, $userId,
            ),
            'mark_paid' => $this->commands->markPaid(
                $supplierId, $runId, $version, $idempotencyKey, $userId,
            ),
            'close' => $this->commands->close(
                $supplierId, $runId, $version, $idempotencyKey, $userId,
            ),
            default => throw new \InvalidArgumentException(
                'Nepodporovaný příkaz mzdového běhu.',
            ),
        };
    }

    /** @return array<string,mixed> */
    private function serialize(PayrollRunCommandResult $result): array
    {
        return [
            'command' => $result->command->value,
            'from_status' => $result->from->value,
            'to_status' => $result->to->value,
            'run' => $result->run,
            'revision' => $result->revision,
            'idempotent_replay' => $result->idempotentReplay,
            'outcome' => $result->outcome?->toArray(),
        ];
    }

    private function authorize(
        Request $request,
        Response $response,
        string $permission,
        AccessLevel $level,
    ): ?Response {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            );
        }
        $error = null;
        if (!$this->requirePermission(
            $request,
            $response,
            $permission,
            $level,
            $error,
        )) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? PayrollTimeValue::row($body, 'request_body') : [];
    }

    /** @param array<string,mixed> $body */
    private function requiredString(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException("Pole {$key} je povinné.");
        }
        return trim($value);
    }

    private function requiredUserId(Request $request): int
    {
        return $this->userId($request)
            ?? throw new \DomainException('Uživatel mzdového příkazu není dostupný.');
    }

    private function optionalPeriod(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)
            || preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException('Období musí mít formát YYYY-MM.');
        }
        return $value;
    }
}
