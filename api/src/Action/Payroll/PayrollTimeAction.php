<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollTimeConflictException;
use MyInvoice\Repository\Payroll\PayrollTimeLockedException;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Time\PayrollTimeCsvImportService;
use MyInvoice\Service\Payroll\Time\PayrollJmhzWorkSummaryConflictException;
use MyInvoice\Service\Payroll\Time\PayrollTimeService;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeException;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeInputMaterializer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollTimeAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollTimeService $time,
        private readonly PayrollTimeCsvImportService $imports,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
        private readonly PayrollSurchargeInputMaterializer $surchargeInputs,
    ) {}

    public function month(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll', AccessLevel::READ)) !== null) {
            return $error;
        }
        $query = $request->getQueryParams();
        $period = is_string($query['period'] ?? null)
            ? $query['period']
            : gmdate('Y-m');
        $incomplete = filter_var(
            $query['incomplete'] ?? false,
            FILTER_VALIDATE_BOOL,
        );
        $employmentId = self::narrowingId($query, 'employment_id');
        try {
            return Json::ok($response, $this->time->overview(
                $this->currentSupplierId($request),
                $period,
                $incomplete,
                max(1, min(
                    PayrollTimeService::LIST_MAX_LIMIT,
                    (int) ($query['limit'] ?? PayrollTimeService::LIST_DEFAULT_LIMIT),
                )),
                max(0, (int) ($query['offset'] ?? 0)),
                $employmentId,
            ));
        } catch (\InvalidArgumentException $e) {
            return $this->validation($response, $e);
        }
    }

    /** @param array<string,string> $args */
    public function calendar(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll.time.write',
            AccessLevel::WRITE,
        )) !== null) {
            return $error;
        }
        try {
            $calendar = $this->time->createCalendar(
                $this->currentSupplierId($request),
                $this->routeId($args, 'employmentId'),
                $this->input($request),
                $this->userId($request),
            );
            $this->audit(
                $request,
                'payroll.time.calendar_version_created',
                'payroll_work_calendar',
                PayrollTimeValue::int($calendar['id'] ?? null, 'id'),
                [
                    'employment_id' => PayrollTimeValue::int(
                        $calendar['employment_id'] ?? null,
                        'employment_id',
                    ),
                    'valid_from' => PayrollTimeValue::string(
                        $calendar['valid_from'] ?? null,
                        'valid_from',
                    ),
                    'valid_to' => $calendar['valid_to'],
                    'row_version' => PayrollTimeValue::int(
                        $calendar['row_version'] ?? null,
                        'row_version',
                    ),
                ],
            );
            return Json::ok($response, ['calendar' => $calendar], 201);
        } catch (PayrollTimeLockedException $e) {
            return Json::error($response, 'payroll_time_locked', $e->getMessage(), 409);
        } catch (PayrollTimeConflictException $e) {
            return $this->conflict($response, $e);
        } catch (\InvalidArgumentException $e) {
            return $this->validation($response, $e);
        }
    }

    public function shift(Request $request, Response $response): Response
    {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll.time.write',
            AccessLevel::WRITE,
        )) !== null) {
            return $error;
        }
        try {
            $result = $this->time->saveShift(
                $this->currentSupplierId($request),
                $this->input($request),
                $this->userId($request),
            );
            $shift = $result['shift'];
            $this->audit(
                $request,
                $shift['status'] === 'published'
                    ? 'payroll.time.shift_published'
                    : 'payroll.time.shift_saved',
                'payroll_shift',
                PayrollTimeValue::int($shift['id'] ?? null, 'id'),
                [
                    'employment_id' => PayrollTimeValue::int(
                        $shift['employment_id'] ?? null,
                        'employment_id',
                    ),
                    'series_key' => PayrollTimeValue::string(
                        $shift['series_key'] ?? null,
                        'series_key',
                    ),
                    'revision_no' => PayrollTimeValue::int(
                        $shift['revision_no'] ?? null,
                        'revision_no',
                    ),
                    'status' => PayrollTimeValue::string($shift['status'] ?? null, 'status'),
                    'remote_work' => PayrollTimeValue::bool(
                        $shift['remote_work'] ?? null,
                        'remote_work',
                    ),
                    'standby_minutes' => PayrollTimeValue::int(
                        $shift['standby_minutes'] ?? null,
                        'standby_minutes',
                    ),
                ],
            );
            return Json::ok($response, $result, 201);
        } catch (PayrollTimeLockedException $e) {
            return Json::error($response, 'payroll_time_locked', $e->getMessage(), 409);
        } catch (PayrollTimeConflictException $e) {
            return $this->conflict($response, $e);
        } catch (\InvalidArgumentException $e) {
            return $this->validation($response, $e);
        }
    }

    public function entry(Request $request, Response $response): Response
    {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll.time.write',
            AccessLevel::WRITE,
        )) !== null) {
            return $error;
        }
        try {
            $result = $this->time->saveEntry(
                $this->currentSupplierId($request),
                $this->input($request),
                $this->userId($request),
            );
            $entry = $result['entry'];
            $this->audit(
                $request,
                'payroll.time.entry_saved',
                'payroll_time_entry',
                PayrollTimeValue::int($entry['id'] ?? null, 'id'),
                [
                    'employment_id' => PayrollTimeValue::int(
                        $entry['employment_id'] ?? null,
                        'employment_id',
                    ),
                    'series_key' => PayrollTimeValue::string(
                        $entry['series_key'] ?? null,
                        'series_key',
                    ),
                    'revision_no' => PayrollTimeValue::int(
                        $entry['revision_no'] ?? null,
                        'revision_no',
                    ),
                    'category' => PayrollTimeValue::string(
                        $entry['category'] ?? null,
                        'category',
                    ),
                    'source_kind' => PayrollTimeValue::string(
                        $entry['source_kind'] ?? null,
                        'source_kind',
                    ),
                ],
            );
            return Json::ok($response, $result, 201);
        } catch (PayrollTimeLockedException $e) {
            return Json::error($response, 'payroll_time_locked', $e->getMessage(), 409);
        } catch (PayrollTimeConflictException $e) {
            return $this->conflict($response, $e);
        } catch (\InvalidArgumentException $e) {
            return $this->validation($response, $e);
        }
    }

    /**
     * Zápis souhlasu zaměstnance s prací přesčas nad nařízený rozsah
     * (§ 93 odst. 3 zákoníku práce).
     */
    public function overtimeConsent(Request $request, Response $response): Response
    {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll.time.write',
            AccessLevel::WRITE,
        )) !== null) {
            return $error;
        }
        try {
            $consent = $this->time->saveOvertimeConsent(
                $this->currentSupplierId($request),
                $this->input($request),
                $this->userId($request),
            );
            $this->audit(
                $request,
                'payroll.time.overtime_consent_saved',
                'payroll_overtime_consent',
                PayrollTimeValue::int($consent['id'] ?? null, 'id'),
                [
                    'employment_id' => PayrollTimeValue::int(
                        $consent['employment_id'] ?? null,
                        'employment_id',
                    ),
                    'valid_from' => PayrollTimeValue::string(
                        $consent['valid_from'] ?? null,
                        'valid_from',
                    ),
                    'valid_to' => $consent['valid_to'],
                ],
            );
            return Json::ok($response, ['consent' => $consent], 201);
        } catch (\DomainException $e) {
            return Json::error($response, 'payroll_time_conflict', $e->getMessage(), 409);
        } catch (\InvalidArgumentException $e) {
            return $this->validation($response, $e);
        }
    }

    /**
     * Zápis zákazu práce přesčas u chráněné skupiny (§ 240 odst. 3 zákoníku
     * práce).
     */
    public function overtimeProtection(Request $request, Response $response): Response
    {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll.time.write',
            AccessLevel::WRITE,
        )) !== null) {
            return $error;
        }
        try {
            $protection = $this->time->saveOvertimeProtection(
                $this->currentSupplierId($request),
                $this->input($request),
                $this->userId($request),
            );
            $this->audit(
                $request,
                'payroll.time.overtime_protection_saved',
                'payroll_overtime_protection',
                PayrollTimeValue::int($protection['id'] ?? null, 'id'),
                [
                    'employment_id' => PayrollTimeValue::int(
                        $protection['employment_id'] ?? null,
                        'employment_id',
                    ),
                    'protection' => PayrollTimeValue::string(
                        $protection['protection'] ?? null,
                        'protection',
                    ),
                    'valid_from' => PayrollTimeValue::string(
                        $protection['valid_from'] ?? null,
                        'valid_from',
                    ),
                    'valid_to' => $protection['valid_to'],
                ],
            );
            return Json::ok($response, ['protection' => $protection], 201);
        } catch (\DomainException $e) {
            return Json::error($response, 'payroll_time_conflict', $e->getMessage(), 409);
        } catch (\InvalidArgumentException $e) {
            return $this->validation($response, $e);
        }
    }

    /**
     * Zápis náhradního volna za práci přesčas (§ 93 odst. 5 zákoníku práce).
     */
    public function overtimeCompensation(Request $request, Response $response): Response
    {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll.time.write',
            AccessLevel::WRITE,
        )) !== null) {
            return $error;
        }
        try {
            $compensation = $this->time->saveOvertimeCompensation(
                $this->currentSupplierId($request),
                $this->input($request),
                $this->userId($request),
            );
            $this->audit(
                $request,
                'payroll.time.overtime_compensation_saved',
                'payroll_overtime_compensation',
                PayrollTimeValue::int($compensation['id'] ?? null, 'id'),
                [
                    'employment_id' => PayrollTimeValue::int(
                        $compensation['employment_id'] ?? null,
                        'employment_id',
                    ),
                    'overtime_date' => PayrollTimeValue::string(
                        $compensation['overtime_date'] ?? null,
                        'overtime_date',
                    ),
                    'minutes' => PayrollTimeValue::int(
                        $compensation['minutes'] ?? null,
                        'minutes',
                    ),
                ],
            );
            return Json::ok($response, ['compensation' => $compensation], 201);
        } catch (\DomainException $e) {
            return Json::error($response, 'payroll_time_conflict', $e->getMessage(), 409);
        } catch (\InvalidArgumentException $e) {
            return $this->validation($response, $e);
        }
    }

    /** Vyrovnávací období podle § 93 odst. 4 zákoníku práce. */
    public function overtimeAveragingPeriods(Request $request, Response $response): Response
    {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll.time.read',
            AccessLevel::READ,
        )) !== null) {
            return $error;
        }

        return Json::ok($response, [
            'periods' => $this->time->overtimeAveragingPeriods(
                $this->currentSupplierId($request),
            ),
        ]);
    }

    public function overtimeAveragingPeriod(Request $request, Response $response): Response
    {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll.time.write',
            AccessLevel::WRITE,
        )) !== null) {
            return $error;
        }
        try {
            $period = $this->time->saveOvertimeAveragingPeriod(
                $this->currentSupplierId($request),
                $this->input($request),
                $this->userId($request),
            );
            $this->audit(
                $request,
                'payroll.time.overtime_averaging_period_saved',
                'payroll_overtime_averaging_period',
                PayrollTimeValue::int($period['id'] ?? null, 'id'),
                [
                    'valid_from' => PayrollTimeValue::string(
                        $period['valid_from'] ?? null,
                        'valid_from',
                    ),
                    'weeks' => PayrollTimeValue::int($period['weeks'] ?? null, 'weeks'),
                    'basis' => PayrollTimeValue::string($period['basis'] ?? null, 'basis'),
                ],
            );
            return Json::ok($response, ['period' => $period], 201);
        } catch (\DomainException $e) {
            return Json::error($response, 'payroll_time_conflict', $e->getMessage(), 409);
        } catch (\InvalidArgumentException $e) {
            return $this->validation($response, $e);
        }
    }

    public function previewImport(Request $request, Response $response): Response
    {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll.time.write',
            AccessLevel::WRITE,
        )) !== null) {
            return $error;
        }
        try {
            $input = $this->input($request);
            $preview = $this->imports->preview(
                $this->currentSupplierId($request),
                $this->string($input, 'period'),
                $this->string($input, 'format'),
                $this->string($input, 'original_name'),
                $this->string($input, 'content'),
            );
            unset($preview['_accepted'], $preview['_errors']);
            return Json::ok($response, ['preview' => $preview]);
        } catch (\InvalidArgumentException $e) {
            return $this->validation($response, $e);
        }
    }

    public function import(Request $request, Response $response): Response
    {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll.time.write',
            AccessLevel::WRITE,
        )) !== null) {
            return $error;
        }
        try {
            $input = $this->input($request);
            $result = $this->imports->import(
                $this->currentSupplierId($request),
                $this->string($input, 'period'),
                $this->string($input, 'format'),
                $this->string($input, 'original_name'),
                $this->string($input, 'content'),
                $this->userId($request),
            );
            $this->audit(
                $request,
                'payroll.time.import_recorded',
                'payroll_time_import',
                PayrollTimeValue::int($result['id'] ?? null, 'id'),
                [
                    'format' => PayrollTimeValue::string($result['format'] ?? null, 'format'),
                    'status' => PayrollTimeValue::string($result['status'] ?? null, 'status'),
                    'total_rows' => PayrollTimeValue::int(
                        $result['total_rows'] ?? null,
                        'total_rows',
                    ),
                    'accepted_rows' => PayrollTimeValue::int(
                        $result['accepted_rows'] ?? null,
                        'accepted_rows',
                    ),
                    'rejected_rows' => PayrollTimeValue::int(
                        $result['rejected_rows'] ?? null,
                        'rejected_rows',
                    ),
                    'duplicate_rows' => PayrollTimeValue::int(
                        $result['duplicate_rows'] ?? null,
                        'duplicate_rows',
                    ),
                    'replayed' => PayrollTimeValue::bool(
                        $result['replayed'] ?? false,
                        'replayed',
                    ),
                ],
            );
            return Json::ok($response, ['import' => $result], 201);
        } catch (PayrollTimeConflictException $e) {
            return $this->conflict($response, $e);
        } catch (\InvalidArgumentException $e) {
            return $this->validation($response, $e);
        }
    }

    /** @param array<string,string> $args */
    public function approve(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll.approve',
            AccessLevel::WRITE,
        )) !== null) {
            return $error;
        }
        try {
            $supplierId = $this->currentSupplierId($request);
            $pdo = $this->db->pdo();
            $ownsTransaction = !$pdo->inTransaction();
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            }
            try {
                $month = $this->time->approve(
                    $supplierId,
                    $this->routePeriod($args),
                    $this->input($request),
                    $this->userId($request),
                );
                // Zákonné příplatky § 114 až § 118 se do mzdy promítají PRÁVĚ
                // TEĎ, ve stejné transakci jako schválení docházky, a ze stejného
                // důvodu jako náhrada mzdy při DPN v `PayrollAbsenceAction`:
                // schválení evidence je okamžik, kdy se ze skutkového stavu stává
                // nárok. Vázat to na `lock_inputs` by znamenalo, že příplatek
                // vznikne až tehdy, když už se vstupy nesmějí měnit; vázat to na
                // tlačítko v UI by znamenalo, že se na něj dá zapomenout — a to
                // je u zákonného nároku nedoplatek, který nikdo neuvidí.
                //
                // Chybějící podklad shodí i schválení docházky. Je to záměr:
                // měsíc s prací ve svátek bez sjednané zásady nebo s prací ve
                // ztíženém prostředí bez počtu vlivů NENÍ schválitelná evidence,
                // protože se z ní nedá spočítat mzda.
                $surcharges = $this->surchargeInputs->materialize(
                    $supplierId,
                    PayrollTimeValue::int($month['employment_id'] ?? null, 'employment_id'),
                    PayrollTimeValue::string($month['period_start'] ?? null, 'period_start'),
                    $this->userId($request),
                );
                if ($ownsTransaction) {
                    $pdo->commit();
                }
            } catch (\Throwable $e) {
                if ($ownsTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            $this->auditMonth($request, 'payroll.time.month_approved', $month);
            return Json::ok($response, ['month' => $month, 'surcharges' => $surcharges]);
        } catch (PayrollSurchargeException $e) {
            return Json::error($response, $e->reason, $e->getMessage(), 409);
        } catch (PayrollTimeLockedException $e) {
            return Json::error($response, 'payroll_time_locked', $e->getMessage(), 409);
        } catch (PayrollJmhzWorkSummaryConflictException $e) {
            return Json::error(
                $response,
                'payroll_jmhz_work_summary_stale',
                $e->getMessage(),
                409,
            );
        } catch (PayrollTimeConflictException $e) {
            return $this->conflict($response, $e);
        } catch (\InvalidArgumentException $e) {
            return $this->validation($response, $e);
        }
    }

    /** @param array<string,string> $args */
    public function reopen(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize(
            $request,
            $response,
            'payroll.reopen',
            AccessLevel::WRITE,
        )) !== null) {
            return $error;
        }
        try {
            $month = $this->time->reopen(
                $this->currentSupplierId($request),
                $this->routePeriod($args),
                $this->input($request),
                $this->userId($request),
            );
            $this->auditMonth($request, 'payroll.time.month_reopened', $month);
            return Json::ok($response, ['month' => $month]);
        } catch (PayrollTimeConflictException $e) {
            return $this->conflict($response, $e);
        } catch (\InvalidArgumentException $e) {
            return $this->validation($response, $e);
        }
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
                'Docházka je dostupná pouze z přihlášené relace.',
                403,
            );
        }
        $error = null;
        if (!$this->requirePermission($request, $response, $permission, $level, $error)) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }
        return null;
    }

    private function validation(Response $response, \InvalidArgumentException $e): Response
    {
        return Json::error($response, 'validation_failed', $e->getMessage(), 422);
    }

    private function conflict(Response $response, PayrollTimeConflictException $e): Response
    {
        return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
            'current_row_version' => $e->currentVersion,
        ]);
    }

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $parsed = $request->getParsedBody();
        if (!is_array($parsed)) {
            return [];
        }
        $result = [];
        foreach ($parsed as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $input */
    private function string(array $input, string $key): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value)) {
            throw new \InvalidArgumentException("{$key} je povinné.");
        }
        return $value;
    }

    /** @param array<string,string> $args */
    private function routeId(array $args, string $key): int
    {
        $value = filter_var($args[$key] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($value === false) {
            throw new \InvalidArgumentException("{$key} není platné ID.");
        }
        return (int) $value;
    }

    /** @param array<string,string> $args */
    private function routePeriod(array $args): string
    {
        $period = $args['period'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}$/D', $period)) {
            throw new \InvalidArgumentException('period musí být ve formátu YYYY-MM.');
        }
        return $period;
    }

    /** @param array<string,mixed> $month */
    private function auditMonth(Request $request, string $action, array $month): void
    {
        $this->audit(
            $request,
            $action,
            'payroll_time_month',
            PayrollTimeValue::int($month['id'] ?? null, 'id'),
            [
                'employment_id' => PayrollTimeValue::int(
                    $month['employment_id'] ?? null,
                    'employment_id',
                ),
                'period_start' => PayrollTimeValue::string(
                    $month['period_start'] ?? null,
                    'period_start',
                ),
                'status' => PayrollTimeValue::string($month['status'] ?? null, 'status'),
                'revision_no' => PayrollTimeValue::int(
                    $month['revision_no'] ?? null,
                    'revision_no',
                ),
                'row_version' => PayrollTimeValue::int(
                    $month['row_version'] ?? null,
                    'row_version',
                ),
                'reason_recorded' => $action === 'payroll.time.month_reopened',
            ],
        );
    }

    /** @param array<string,mixed> $payload */
    private function audit(
        Request $request,
        string $action,
        string $entityType,
        int $entityId,
        array $payload,
    ): void {
        $this->logger->log(
            $action,
            $this->userId($request),
            $entityType,
            $entityId,
            $payload,
            $this->ipMatcher->clientIpFromRequest($this->serverParams($request)),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }

    /** @return array<string,mixed> */
    private function serverParams(Request $request): array
    {
        $result = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
