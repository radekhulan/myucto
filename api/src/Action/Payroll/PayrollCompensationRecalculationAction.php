<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Repository\Payroll\PayrollInputCancellationException;
use MyInvoice\Repository\Payroll\PayrollInputConflictException;
use MyInvoice\Repository\Payroll\PayrollRunConflictException;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Payroll\Absence\PayrollCompensationRecalculationBlockedException;
use MyInvoice\Service\Payroll\Absence\PayrollCompensationRecalculationService;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollYearClosedException;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotFixableException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollCompensationRecalculationAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollCompensationRecalculationService $recalculation,
        private readonly PayrollModuleAccess $access,
    ) {}

    public function preview(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll', AccessLevel::READ)) !== null) {
            return $error;
        }
        $query = $request->getQueryParams();
        try {
            $periods = array_values(array_filter(
                array_map('trim', explode(',', (string) ($query['periods'] ?? ''))),
                static fn (string $period): bool => $period !== '',
            ));
            $preview = $this->recalculation->preview(
                $this->currentSupplierId($request),
                $periods,
                self::ids($query['employment_ids'] ?? null),
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, $preview + [
            'can_apply' => RequestAuthorization::allows($request, 'payroll.inputs.write', AccessLevel::WRITE),
            'can_approve' => RequestAuthorization::allows($request, 'payroll.approve', AccessLevel::WRITE),
            'can_refresh' => RequestAuthorization::allows($request, 'payroll.calculate', AccessLevel::WRITE),
        ]);
    }

    public function apply(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, 'payroll.inputs.write', AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $idempotencyKey = trim($request->getHeaderLine('Idempotency-Key'));
        $userId = $this->userId($request);
        if (mb_strlen($idempotencyKey) < 8 || mb_strlen($idempotencyKey) > 190 || $userId === null) {
            return Json::error($response, 'validation_failed', 'Přepočet vyžaduje hlavičku Idempotency-Key (8 až 190 znaků).', 422);
        }
        try {
            $result = $this->recalculation->apply(
                $this->currentSupplierId($request),
                is_string($body['period'] ?? null) ? $body['period'] : '',
                self::ids($body['employment_ids'] ?? null),
                self::ids($body['input_ids'] ?? null),
                $userId,
                RequestAuthorization::allows($request, 'payroll.approve', AccessLevel::WRITE),
                RequestAuthorization::allows($request, 'payroll.calculate', AccessLevel::WRITE),
                $idempotencyKey,
            );
        } catch (PayrollCompensationRecalculationBlockedException $e) {
            return Json::error($response, 'payroll_run_approved', $e->getMessage(), 409, [
                'next_step' => $e->nextStep,
                'run_id' => $e->runId,
            ]);
        } catch (PayrollYearClosedException $e) {
            return self::yearClosedError($response, $e);
        } catch (PayrollInputConflictException|PayrollRunConflictException $e) {
            return Json::error($response, 'row_version_conflict', 'Náhrady nebo běh mezitím změnil někdo jiný. Načtěte náhled znovu.', 409);
        } catch (PayrollInputCancellationException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 409);
        } catch (PayrollRunSnapshotFixableException $e) {
            return Json::error($response, 'payroll_run_snapshot_fixable', $e->getMessage(), 409);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (\DomainException $e) {
            return Json::error($response, 'compensation_recalculation_conflict', $e->getMessage(), 409);
        }

        return Json::ok($response, $result);
    }

    /** @return list<int>|null */
    private static function ids(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Seznam identifikátorů není platný.');
        }
        $ids = [];
        foreach ($value as $id) {
            $int = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($int === false) {
                throw new \InvalidArgumentException('Seznam identifikátorů není platný.');
            }
            $ids[] = (int) $int;
        }

        return array_values(array_unique($ids));
    }

    private function authorize(Request $request, Response $response, string $permission, AccessLevel $level): ?Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
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
}
