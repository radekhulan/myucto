<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceOverviewException;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSubmissionService;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Podání zdravotním pojišťovnám: co se hlásí, do kdy, a příprava přehledu
 * o platbě do platformy podání.
 *
 * Endpointy jsou session-only stejně jako zbytek zdravotní agendy —
 * veřejné bearer API se pro podání záměrně neotvírá.
 */
final class PayrollHealthNotificationAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly HealthInsuranceSubmissionService $service,
        private readonly PayrollModuleAccess $access,
    ) {}

    /** Schopnosti agendy: schémata, kanály po pojišťovnách, katalog povinností. */
    public function capability(
        Request $request,
        Response $response,
    ): Response {
        if (!$this->guard($request, $response, AccessLevel::READ, $error)) {
            return $this->guardFailure($error);
        }

        return Json::ok(
            $response,
            $this->service->capability($this->currentSupplierId($request)),
        );
    }

    /** @param array{employmentId:string} $args */
    public function duties(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->guard($request, $response, AccessLevel::READ, $error)) {
            return $this->guardFailure($error);
        }

        try {
            $items = $this->service->duties(
                $this->currentSupplierId($request),
                $this->routePositiveInt($args, 'employmentId'),
                $this->onDate($request),
            );
        } catch (HealthNotificationException $exception) {
            return Json::error(
                $response,
                $exception->errorCode,
                $exception->getMessage(),
                422,
            );
        } catch (\OutOfBoundsException $exception) {
            return Json::error(
                $response,
                'not_found',
                $exception->getMessage(),
                404,
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        return Json::ok($response, ['items' => $items]);
    }

    /**
     * Přehled povinností za mzdové období.
     *
     * Filtr i stránka jsou parametry SERVERU. Kdyby se filtrovalo až
     * v prohlížeči, `total` by popisoval jiný seznam, než účetní vidí, a
     * „nic k podání" by mohlo znamenat jen „na téhle stránce nic není".
     */
    public function periodDuties(
        Request $request,
        Response $response,
    ): Response {
        if (!$this->guard($request, $response, AccessLevel::READ, $error)) {
            return $this->guardFailure($error);
        }

        $query = $request->getQueryParams();
        try {
            $result = $this->service->dutiesForPeriod(
                $this->currentSupplierId($request),
                'production',
                $this->period($query),
                [
                    'insurer_code' => $this->optionalString(
                        $query,
                        'insurer_code',
                        '/^[0-9]{3}$/D',
                    ),
                    'kind' => $this->optionalString(
                        $query,
                        'kind',
                        '/^[a-z_]{1,64}$/D',
                    ),
                    'reported' => $this->optionalBool($query, 'reported'),
                    'undocumented_code_only' => $this->optionalBool(
                        $query,
                        'undocumented_code_only',
                    ) === true,
                ],
                (int) ($query['limit']
                    ?? HealthInsuranceSubmissionService::PERIOD_DEFAULT_LIMIT),
                (int) ($query['offset'] ?? 0),
            );
        } catch (HealthNotificationException $exception) {
            return Json::error(
                $response,
                $exception->errorCode,
                $exception->getMessage(),
                422,
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        return Json::ok($response, $result)
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /** @param array{employmentId:string} $args */
    public function registerObligations(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->guard($request, $response, AccessLevel::WRITE, $error)) {
            return $this->guardFailure($error);
        }

        try {
            $items = $this->service->registerObligations(
                $this->currentSupplierId($request),
                'production',
                $this->routePositiveInt($args, 'employmentId'),
                $this->onDate($request),
                $this->userId($request),
            );
        } catch (HealthNotificationException $exception) {
            return Json::error(
                $response,
                $exception->errorCode,
                $exception->getMessage(),
                422,
            );
        } catch (\OutOfBoundsException $exception) {
            return Json::error(
                $response,
                'not_found',
                $exception->getMessage(),
                404,
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        return Json::ok($response, ['items' => $items]);
    }

    public function registerPeriodObligations(
        Request $request,
        Response $response,
    ): Response {
        if (!$this->guard($request, $response, AccessLevel::WRITE, $error)) {
            return $this->guardFailure($error);
        }

        try {
            $result = $this->service->registerPeriodObligations(
                $this->currentSupplierId($request),
                'production',
                $this->period($request->getQueryParams()),
                $this->userId($request),
            );
        } catch (HealthNotificationException $exception) {
            return Json::error(
                $response,
                $exception->errorCode,
                $exception->getMessage(),
                422,
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        return Json::ok($response, $result)
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /** @param array{revisionId:string,insurerCode:string} $args */
    public function preparePaymentOverview(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->guard($request, $response, AccessLevel::WRITE, $error)) {
            return $this->guardFailure($error);
        }

        try {
            $body = (array) ($request->getParsedBody() ?? []);
            $environment = (string) ($body['environment'] ?? 'production');
            if (!in_array($environment, ['production', 'test'], true)) {
                return Json::error(
                    $response,
                    'invalid_environment',
                    'Neznámé prostředí podání.',
                    400,
                );
            }
            $result = $this->service->preparePaymentOverview(
                $this->currentSupplierId($request),
                $environment,
                $this->routePositiveInt($args, 'revisionId'),
                (string) ($args['insurerCode'] ?? ''),
                $this->userId($request),
            );
        } catch (HealthNotificationException $exception) {
            return Json::error(
                $response,
                $exception->errorCode,
                $exception->getMessage(),
                422,
            );
        } catch (HealthInsuranceOverviewException $exception) {
            return Json::error(
                $response,
                $exception->validationCode,
                $exception->getMessage(),
                422,
            );
        } catch (\OutOfBoundsException $exception) {
            return Json::error(
                $response,
                'not_found',
                $exception->getMessage(),
                404,
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        return Json::ok($response, $result);
    }

    /** @param array<string,mixed> $query */
    private function period(array $query): string
    {
        $value = $query['period'] ?? null;
        if ($value === null || $value === '') {
            return (new \DateTimeImmutable(
                'now',
                new \DateTimeZone('Europe/Prague'),
            ))->format('Y-m');
        }
        if (!is_string($value)
            || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Parametr period musí mít tvar RRRR-MM.',
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $query */
    private function optionalString(
        array $query,
        string $field,
        string $pattern,
    ): ?string {
        $value = $query[$field] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match($pattern, $value) !== 1) {
            throw new \InvalidArgumentException(
                "Parametr {$field} má neplatnou hodnotu.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $query */
    private function optionalBool(array $query, string $field): ?bool
    {
        $value = $query[$field] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        $parsed = filter_var(
            $value,
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE,
        );
        if ($parsed === null) {
            throw new \InvalidArgumentException(
                "Parametr {$field} musí být true nebo false.",
            );
        }

        return $parsed;
    }

    private function onDate(Request $request): string
    {
        $params = $request->getQueryParams();
        $value = $params['on_date'] ?? null;
        if ($value === null || $value === '') {
            return (new \DateTimeImmutable(
                'now',
                new \DateTimeZone('Europe/Prague'),
            ))->format('Y-m-d');
        }
        if (!is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Parametr on_date musí mít tvar RRRR-MM-DD.',
            );
        }

        return $value;
    }

    private function guard(
        Request $request,
        Response $response,
        AccessLevel $minimum,
        ?Response &$error,
    ): bool {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            $error = Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            );

            return false;
        }
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.submissions',
            $minimum,
            $error,
        )) {
            return false;
        }

        return $this->requirePayrollEnabled(
            $request,
            $response,
            $this->access,
            $error,
        );
    }

    private function guardFailure(?Response $error): Response
    {
        return $error
            ?? throw new \LogicException(
                'Payroll zdravotní oznámení guard selhalo bez odpovědi.',
            );
    }

    /** @param array<string,string> $args */
    private function routePositiveInt(array $args, string $field): int
    {
        $value = $args[$field] ?? null;
        if (!is_string($value)
            || preg_match('/^[1-9][0-9]*$/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException(
                "Parametr {$field} musí být kladné celé číslo.",
            );
        }

        return (int) $value;
    }
}
