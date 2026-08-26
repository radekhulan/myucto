<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollRiskySavingsRepository;
use MyInvoice\Repository\Payroll\PayrollRiskySavingsConflictException;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\RiskySavings\PayrollRiskySavingsPolicy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollRiskySavingsAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollRiskySavingsRepository $repository,
        private readonly PayrollRiskySavingsPolicy $policy,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($denied = $this->authorize(
            $request,
            $response,
            'payroll',
            AccessLevel::READ,
        )) !== null) {
            return $denied;
        }
        try {
            $period = $this->month($request->getQueryParams()['period'] ?? null);
            return $this->noStore(Json::ok($response, [
                'items' => $this->repository->listPeriod(
                    $this->currentSupplierId($request),
                    $period,
                ),
                'minimum_shift_eighths' =>
                    PayrollRiskySavingsPolicy::MINIMUM_SHIFT_EIGHTHS,
                'rate_basis_points' => PayrollRiskySavingsPolicy::RATE_BASIS_POINTS,
            ]));
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }
    }

    public function save(Request $request, Response $response): Response
    {
        $body = is_array($request->getParsedBody())
            ? $request->getParsedBody() : [];
        $status = ($body['approve'] ?? false) === true ? 'approved' : 'draft';
        $permission = $status === 'approved'
            ? 'payroll.approve' : 'payroll.inputs.write';
        if (($denied = $this->authorize(
            $request,
            $response,
            $permission,
            AccessLevel::WRITE,
        )) !== null) {
            return $denied;
        }
        try {
            $period = $this->month($body['period'] ?? null);
            $accountId = $this->positiveInt(
                $body['institution_account_id'] ?? null,
                'institution_account_id',
            );
            $paymentTarget = $this->repository->paymentTarget(
                $this->currentSupplierId($request),
                $accountId,
                $this->policy->dueOn($period),
            );
            $evidence = [
                'status' => $status,
                'source_evidence_id' => $this->optionalPositiveInt(
                    $body['source_evidence_id'] ?? null,
                    'source_evidence_id',
                ),
                'row_version' => $this->optionalPositiveInt(
                    $body['row_version'] ?? null,
                    'row_version',
                ),
                'risk_factor' => $this->riskFactor(
                    $body['risk_factor'] ?? null,
                ),
                'work_category' => 3,
                'qualifying_shift_eighths' => $this->nonNegativeInt(
                    $body['qualifying_shift_eighths'] ?? null,
                    'qualifying_shift_eighths',
                ),
                'right_claimed_on' => $this->date(
                    $body['right_claimed_on'] ?? null,
                    'right_claimed_on',
                ),
                'employee_informed_on' => $this->optionalDate(
                    $body['employee_informed_on'] ?? null,
                    'employee_informed_on',
                ),
                'pension_company' => $paymentTarget['payment_target_name'],
                'product_reference' => $this->requiredText(
                    $body['product_reference'] ?? null,
                    'product_reference',
                ),
                'institution_account_id' => $accountId,
                'institution_account_row_version' =>
                    $paymentTarget['institution_account_row_version'],
                'institution_account_hash' =>
                    $paymentTarget['institution_account_hash'],
                'institution_account_masked' =>
                    $paymentTarget['institution_account_masked'],
                'variable_symbol' => $this->paymentSymbol(
                    $body['variable_symbol']
                        ?? $paymentTarget['default_variable_symbol'],
                    10,
                ),
                'specific_symbol' => $this->paymentSymbol(
                    $body['specific_symbol']
                        ?? $paymentTarget['default_specific_symbol'],
                    10,
                ),
                'payment_message' => $this->optionalText(
                    $body['payment_message'] ?? null,
                    190,
                ),
                'evidence_reference' => $this->optionalText(
                    $body['evidence_reference'] ?? null,
                ),
            ];
            if ($status === 'approved') {
                $issues = $this->policy->issues($evidence, $period);
                if ($issues !== []) {
                    throw new \InvalidArgumentException(
                        implode(' ', array_map($this->issueMessage(...), $issues)),
                    );
                }
            }
            $saved = $this->repository->saveEvidence(
                $this->currentSupplierId($request),
                $this->positiveInt($body['employment_id'] ?? null, 'employment_id'),
                $period,
                $evidence,
                $this->userId($request)
                    ?? throw new \LogicException('Přihlášený uživatel nebyl nalezen.'),
            );
            $this->audit($request, $saved);
            return $this->noStore(Json::ok($response, [
                'evidence' => $saved,
            ]));
        } catch (PayrollRiskySavingsConflictException $exception) {
            return Json::error(
                $response,
                'row_version_conflict',
                $exception->getMessage(),
                409,
                ['current_row_version' => $exception->currentVersion],
            );
        } catch (\OutOfBoundsException $exception) {
            return Json::error($response, 'not_found', $exception->getMessage(), 404);
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
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

    private function month(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Období musí být ve formátu YYYY-MM.');
        }
        return $this->date($value . '-01', 'period');
    }

    private function date(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException(
                $this->fieldLabel($field) . ' musí být datum ve formátu RRRR-MM-DD.',
            );
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(
                $this->fieldLabel($field) . ' musí být datum ve formátu RRRR-MM-DD.',
            );
        }
        return $value;
    }

    private function positiveInt(mixed $value, string $field): int
    {
        $result = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($result === false) {
            throw new \InvalidArgumentException(
                $this->fieldLabel($field) . ' musí být kladné celé číslo.',
            );
        }
        return (int) $result;
    }

    private function optionalPositiveInt(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->positiveInt($value, $field);
    }

    private function nonNegativeInt(mixed $value, string $field): int
    {
        $result = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 2480],
        ]);
        if ($result === false) {
            throw new \InvalidArgumentException(
                $this->fieldLabel($field) . ' musí být nezáporné celé číslo.',
            );
        }
        return (int) $result;
    }

    private function requiredText(mixed $value, string $field): string
    {
        $text = $this->optionalText($value);
        if ($text === null) {
            throw new \InvalidArgumentException(
                $this->fieldLabel($field) . ' je povinná hodnota.',
            );
        }
        return $text;
    }

    private function optionalText(mixed $value, int $maxLength = 500): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Textová hodnota není platná.');
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException('Textová hodnota není platná.');
        }
        return $value;
    }

    private function optionalDate(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->date($value, $field);
    }

    private function riskFactor(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, [
            'vibration', 'cold', 'heat', 'dynamic_physical_load',
        ], true)) {
            throw new \InvalidArgumentException(
                'Vyberte zákonný rizikový faktor 3. kategorie.',
            );
        }
        return $value;
    }

    private function paymentSymbol(mixed $value, int $maxLength): ?string
    {
        $symbol = $this->optionalText($value, $maxLength);
        if ($symbol !== null && preg_match('/^[0-9]+$/D', $symbol) !== 1) {
            throw new \InvalidArgumentException(
                'Platební symbol smí obsahovat jen číslice.',
            );
        }
        return $symbol;
    }

    private function issueMessage(string $issue): string
    {
        return match ($issue) {
            'risky_savings_evidence_not_approved' =>
                'Evidence musí být před použitím schválena.',
            'risky_savings_risk_factor_invalid' =>
                'Vyberte jeden ze zákonných rizikových faktorů 3. kategorie.',
            'risky_savings_work_category_invalid' =>
                'Povinné spoření lze evidovat jen pro práci 3. kategorie.',
            'risky_savings_shift_eighths_invalid' =>
                'Zadejte platný počet osmin rizikových směn.',
            'risky_savings_claim_date_invalid' =>
                'Zadejte platné datum uplatnění práva zaměstnancem.',
            'risky_savings_pension_company_missing' =>
                'Doplňte penzijní společnost.',
            'risky_savings_product_reference_missing' =>
                'Doplňte identifikaci smlouvy nebo produktu.',
            'risky_savings_payment_target_invalid' =>
                'Vyberte platný ověřený účet penzijní společnosti.',
            'risky_savings_payment_target_changed' =>
                'Ověřený účet penzijní společnosti se změnil. Zkontrolujte jej a podklad znovu schvalte.',
            default => 'Zkontrolujte podklady povinného spoření.',
        };
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'period' => 'Mzdové období',
            'employment_id' => 'Pracovní vztah',
            'institution_account_id' => 'Ověřený účet penzijní společnosti',
            'source_evidence_id' => 'Upravovaný podklad',
            'row_version' => 'Verze podkladu',
            'qualifying_shift_eighths' => 'Rozsah rizikové práce',
            'right_claimed_on' => 'Datum uplatnění práva',
            'employee_informed_on' => 'Datum informování zaměstnance',
            'product_reference' => 'Identifikace smlouvy nebo produktu',
            default => 'Zadaná hodnota',
        };
    }

    /** @param array<string,mixed> $evidence */
    private function audit(Request $request, array $evidence): void
    {
        $this->logger->log(
            'payroll.risky_savings.evidence_saved',
            $this->userId($request),
            'payroll_risky_savings_evidence',
            (int) $evidence['id'],
            [
                'employment_id' => (int) $evidence['employment_id'],
                'period_start' => (string) $evidence['period_start'],
                'revision_no' => (int) $evidence['revision_no'],
                'status' => (string) $evidence['status'],
                'risk_factor' => (string) $evidence['risk_factor'],
                'work_category' => (int) $evidence['work_category'],
                'qualifying_shift_eighths' =>
                    (int) $evidence['qualifying_shift_eighths'],
                'information_duty_recorded' =>
                    $evidence['employee_informed_on'] !== null,
                'institution_account_id' =>
                    (int) $evidence['institution_account_id'],
                'row_version' => (int) $evidence['row_version'],
            ],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}
