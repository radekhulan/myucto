<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Repository\Payroll\PayrollTimeConflictException;
use MyInvoice\Repository\Payroll\PayrollTimeLockedException;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Payroll\PayrollHourlyEvidenceBody;
use MyInvoice\Service\Payroll\PayrollHourlyEvidenceService;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollRunClosedException;
use MyInvoice\Service\Payroll\PayrollYearClosedException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollHourlyEvidenceAction
{
    use PayrollActionSupport;

    public function __construct(private readonly PayrollHourlyEvidenceService $service, private readonly PayrollModuleAccess $access) {}

    public function show(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, $args, false);
    }

    public function save(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, $args, true);
    }

    private function handle(Request $request, Response $response, array $args, bool $write): Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) return Json::sessionRequired($response);
        $error = null;
        if (!$this->requirePermission($request, $response, $write ? 'payroll.time.write' : 'payroll', $write ? AccessLevel::WRITE : AccessLevel::READ, $error)) return $error;
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) return $error;
        $supplierId = $this->currentSupplierId($request);
        $employmentId = (int) ($args['id'] ?? 0);
        $input = $write ? $request->getParsedBody() : $request->getQueryParams();
        $input = is_array($input) ? $input : [];
        try {
            $period = PayrollHourlyEvidenceBody::period($input['period'] ?? null);
            if ($write) $this->service->save($supplierId, $employmentId, $period, $input, $this->userId($request));
            $offset = $write ? 0 : filter_var($input['offset'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100_000]]);
            if ($offset === false) throw new \InvalidArgumentException('Neplatná stránka historie.');
            $data = $this->service->view($supplierId, $employmentId, $period, $offset);
            $data['can_write'] = $data['can_write'] && RequestAuthorization::allows($request, 'payroll.time.write', AccessLevel::WRITE);
            return Json::ok($response, $data, $write ? 201 : 200);
        } catch (PayrollTimeConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, ['current_row_version' => $e->currentVersion]);
        } catch (PayrollYearClosedException $e) {
            return self::yearClosedError($response, $e);
        } catch (PayrollRunClosedException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 409, ['run' => $e->run]);
        } catch (PayrollTimeLockedException $e) {
            return Json::error($response, 'payroll_inputs_locked', $e->getMessage(), 409);
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
    }
}
