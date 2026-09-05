<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountConflictException;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountOverlapException;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollInstitutionAccountValidator;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollInstitutionAccountsAction
{
    use PayrollActionSupport;
    use PayrollDeletionResponse;

    public function __construct(
        private readonly PayrollInstitutionAccountRepository $accounts,
        private readonly PayrollInstitutionAccountDeletionRepository $deletion,
        private readonly PayrollInstitutionAccountValidator $validator,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }

        try {
            $effectiveOn = $this->optionalDateQuery(
                $request->getQueryParams()['effective_on'] ?? null
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, [
            'accounts' => $this->accounts->list(
                $this->currentSupplierId($request),
                $effectiveOn,
            ),
        ]);
    }

    /** @param array<string,string> $args */
    public function detail(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }

        $account = $this->accounts->find(
            $this->currentSupplierId($request),
            (int) ($args['id'] ?? 0),
        );
        return $account === null
            ? Json::error($response, 'not_found', 'Účet instituce nebyl nalezen.', 404)
            : Json::ok($response, ['account' => $account]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }

        $supplierId = $this->currentSupplierId($request);
        try {
            $data = $this->validator->validateCreate(
                $this->input($request)
            );
            $account = $this->accounts->create(
                $supplierId,
                $data,
                $this->userId($request),
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (PayrollInstitutionAccountOverlapException $e) {
            return Json::error(
                $response,
                'institution_account_interval_overlap',
                $e->getMessage(),
                409,
            );
        }

        $this->audit($request, 'payroll.institution_account.created', $account);
        return Json::ok($response, ['account' => $account], 201);
    }

    /** @param array<string,string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }

        $body = $this->input($request);
        $version = filter_var($body['row_version'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($version === false) {
            return Json::error(
                $response,
                'validation_failed',
                'row_version musí být kladné celé číslo.',
                422,
            );
        }
        unset($body['row_version']);

        $supplierId = $this->currentSupplierId($request);
        try {
            $data = $this->validator->validateUpdate($body);
            $account = $this->accounts->update(
                $supplierId,
                (int) ($args['id'] ?? 0),
                $data,
                (int) $version,
                $this->userId($request),
            );
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (PayrollInstitutionAccountOverlapException $e) {
            return Json::error(
                $response,
                'institution_account_interval_overlap',
                $e->getMessage(),
                409,
            );
        } catch (PayrollInstitutionAccountConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        }

        if ($account === null) {
            return Json::error($response, 'not_found', 'Účet instituce nebyl nalezen.', 404);
        }

        $this->audit($request, 'payroll.institution_account.updated', $account);
        return Json::ok($response, ['account' => $account]);
    }

    /**
     * Odklidí duplicitní nebo omylem založený účet instituce.
     *
     * Právo je `payroll.settings`, tedy TOTÉŽ, kterým se účet zakládá a upravuje.
     * Před účtem, ze kterého se už platilo, chrání blokátory v repozitáři.
     *
     * @param array<string,string> $args
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $cascade = $this->deletion->delete(
                $this->currentSupplierId($request),
                (int) ($args['id'] ?? 0),
                $this->optionalRowVersion($this->input($request)['row_version'] ?? null),
                $this->userId($request),
                $this->ipMatcher->clientIpFromRequest($this->serverParams($request)),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\Throwable $e) {
            return $this->deletionError($response, $e);
        }

        return Json::ok($response, ['deleted' => true, 'cascade' => $cascade]);
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
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.settings',
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

    /** @param array<string,mixed> $account */
    private function audit(Request $request, string $action, array $account): void
    {
        $supplierId = $this->currentSupplierId($request);
        $accountId = $this->accountInt($account, 'id');
        $this->logger->log(
            $action,
            $this->userId($request),
            'payroll_institution_account',
            $accountId,
            [
                'institution_type' => $this->accountString($account, 'institution_type'),
                'institution_code' => $this->accountString($account, 'institution_code'),
                'currency_code' => $this->accountString($account, 'currency_code'),
                'valid_from' => $this->accountString($account, 'valid_from'),
                'valid_to' => $this->accountNullableString($account, 'valid_to'),
                'row_version' => $this->accountInt($account, 'row_version'),
            ],
            $this->ipMatcher->clientIpFromRequest($this->serverParams($request)),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
    }

    private function optionalDateQuery(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        if (!is_string($raw)) {
            throw new \InvalidArgumentException(
                'effective_on musí být platné datum YYYY-MM-DD.'
            );
        }
        $value = trim($raw);
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(
                'effective_on musí být platné datum YYYY-MM-DD.'
            );
        }
        return $value;
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

    /** @param array<string,mixed> $account */
    private function accountString(array $account, string $key): string
    {
        $value = $account[$key] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException("DTO účtu instituce nemá pole {$key}.");
        }
        return $value;
    }

    /** @param array<string,mixed> $account */
    private function accountNullableString(array $account, string $key): ?string
    {
        $value = $account[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \UnexpectedValueException("DTO účtu instituce nemá pole {$key}.");
        }
        return $value;
    }

    /** @param array<string,mixed> $account */
    private function accountInt(array $account, string $key): int
    {
        $value = $account[$key] ?? null;
        if (!is_int($value)) {
            throw new \UnexpectedValueException("DTO účtu instituce nemá pole {$key}.");
        }
        return $value;
    }
}
