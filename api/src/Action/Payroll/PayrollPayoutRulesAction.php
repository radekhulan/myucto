<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollPayoutRuleConflictException;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Net\PayrollPayoutRuleDefaultsService;
use MyInvoice\Service\Payroll\Net\PayrollPayoutRuleInput;
use MyInvoice\Service\Payroll\Net\PayrollPayoutRuleService;
use MyInvoice\Service\Payroll\Net\PayrollPayoutRuleWarnings;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Výplatní pravidla osoby — CRUD nad `payroll_payout_rules`.
 *
 * Do téhle třídy tabulka neměla žádnou zapisovací cestu, takže
 * PayoutAllocationService neměl co alokovat a plný mzdový modul neuměl
 * zaplatit nikoho. Endpointy jsou schválně vedené pod osobou
 * (`/people/{employeeId}/payout-rules`), ne pod globálním id pravidla — scope
 * na (supplier_id, employee_id) pak platí i pro URL, ne jen pro SQL.
 */
final class PayrollPayoutRulesAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollPayoutRuleService $rules,
        private readonly PayrollPayoutRuleDefaultsService $defaults,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array{employeeId:string} $args */
    public function list(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        $employeeId = (int) $args['employeeId'];
        try {
            $rules = $this->rules->listForEmployee($supplierId, $employeeId);
            $payload = [
                'rules' => $rules,
                'proposal' => $this->defaults->proposeFor($supplierId, $employeeId),
                'warnings' => PayrollPayoutRuleWarnings::forRules($rules),
            ];
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        }

        return Json::ok($response, $payload);
    }

    /** @param array{employeeId:string} $args */
    public function create(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        $employeeId = (int) $args['employeeId'];
        try {
            $rule = $this->rules->create(
                $supplierId,
                $employeeId,
                PayrollPayoutRuleInput::fromRequest($this->input($request)),
            );
            $this->audit($request, 'payroll.payout_rule.created', $rule);
        } catch (\ValueError|\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\DomainException $e) {
            return Json::error($response, 'invalid_payout_rule', $e->getMessage(), 409);
        }

        return Json::ok($response, [
            'rule' => $rule,
            'warnings' => PayrollPayoutRuleWarnings::forRules([$rule]),
        ], 201);
    }

    /** @param array{employeeId:string,ruleId:string} $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        $employeeId = (int) $args['employeeId'];
        try {
            $body = $this->input($request);
            $rule = $this->rules->update(
                $supplierId,
                $employeeId,
                (int) $args['ruleId'],
                PayrollPayoutRuleInput::fromRequest($body),
                $this->positiveInt($body['row_version'] ?? null, 'row_version'),
            );
            $this->audit($request, 'payroll.payout_rule.updated', $rule);
        } catch (\ValueError|\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (PayrollPayoutRuleConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\DomainException $e) {
            return Json::error($response, 'invalid_payout_rule', $e->getMessage(), 409);
        }

        return Json::ok($response, [
            'rule' => $rule,
            'warnings' => PayrollPayoutRuleWarnings::forRules([$rule]),
        ]);
    }

    /**
     * Deaktivace, ne smazání — pravidlo je zmrazené v historických snapshotech
     * a odkazují na něj zaúčtované alokace.
     *
     * @param array{employeeId:string,ruleId:string} $args
     */
    public function deactivate(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        $employeeId = (int) $args['employeeId'];
        try {
            $body = $this->input($request);
            $rowVersion = $this->positiveInt(
                $body['row_version'] ?? $request->getQueryParams()['row_version'] ?? null,
                'row_version',
            );
            $rule = $this->rules->deactivate(
                $supplierId,
                $employeeId,
                (int) $args['ruleId'],
                $rowVersion,
            );
            $this->audit($request, 'payroll.payout_rule.deactivated', $rule);
        } catch (\ValueError|\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (PayrollPayoutRuleConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\DomainException $e) {
            return Json::error($response, 'invalid_payout_rule', $e->getMessage(), 409);
        }

        return Json::ok($response, [
            'rule' => $rule,
            'warnings' => PayrollPayoutRuleWarnings::forRules([$rule]),
        ]);
    }

    /** @param array{employeeId:string} $args */
    public function applyDefaults(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        $employeeId = (int) $args['employeeId'];
        try {
            $rules = $this->defaults->applyDefaults($supplierId, $employeeId);
            foreach ($rules as $rule) {
                if ($rule['is_active'] === true) {
                    $this->audit(
                        $request,
                        'payroll.payout_rule.defaults_applied',
                        $rule,
                    );
                }
            }
        } catch (\ValueError|\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (\OutOfBoundsException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\DomainException $e) {
            return Json::error($response, 'invalid_payout_rule', $e->getMessage(), 409);
        }

        return Json::ok($response, [
            'rules' => $rules,
            'proposal' => $this->defaults->proposeFor($supplierId, $employeeId),
            'warnings' => PayrollPayoutRuleWarnings::forRules($rules),
        ], 201);
    }

    private function authorize(
        Request $request,
        Response $response,
        AccessLevel $level,
    ): ?Response {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        // Výplatní pravidlo je součást osobní karty (kam patří i výplatní účty
        // a jejich ověření pod `payroll.person.write`), ne mzdový vstup ani
        // platba. Kdo smí měnit účet zaměstnance, smí i určit, kam mzda půjde;
        // opačně by právo na účty bez práva na pravidla bylo k ničemu.
        $permission = $level === AccessLevel::WRITE ? 'payroll.person.write' : 'payroll';
        if (!$this->requirePermission($request, $response, $permission, $level, $error)) {
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

        return $body === null ? [] : PayrollTimeValue::row($body, 'request_body');
    }

    private function positiveInt(mixed $value, string $field): int
    {
        $result = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if (!is_int($result)) {
            throw new \InvalidArgumentException(
                "Pole {$field} musí být kladné celé číslo.",
            );
        }

        return $result;
    }

    /** @param array<string,mixed> $rule */
    private function audit(Request $request, string $action, array $rule): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'payroll_payout_rule',
            PayrollTimeValue::int($rule['id'] ?? null, 'id'),
            [
                'employee_id' => PayrollTimeValue::int(
                    $rule['employee_id'] ?? null,
                    'employee_id',
                ),
                'destination_kind' => $rule['destination_kind'] ?? null,
                'destination_reference' => $rule['destination_reference'] ?? null,
                'allocation_kind' => $rule['allocation_kind'] ?? null,
                'amount_minor' => $rule['amount_minor'] ?? null,
                'basis_points' => $rule['basis_points'] ?? null,
                'priority_no' => $rule['priority_no'] ?? null,
                'is_active' => $rule['is_active'] ?? null,
                'row_version' => PayrollTimeValue::int(
                    $rule['row_version'] ?? null,
                    'row_version',
                ),
            ],
            $this->ipMatcher->clientIpFromRequest(
                PayrollTimeValue::row($request->getServerParams(), 'server_params'),
            ),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}
