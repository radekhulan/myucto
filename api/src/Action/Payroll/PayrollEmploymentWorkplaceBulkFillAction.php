<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollEmploymentWorkplaceBulkFill;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Hromadné doplnění místa výkonu práce ({@see PayrollEmploymentWorkplaceBulkFill}).
 *
 * Náhled i zápis chrání `payroll.employment.write` — tentýž zápis jako oprava
 * podmínek na kartě vztahu. Náhled vypisuje vztahy celé firmy, a slouží jen
 * k rozhodnutí o zápisu. Stejně jako karta jen se session.
 */
final class PayrollEmploymentWorkplaceBulkFillAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollEmploymentWorkplaceBulkFill $bulk,
        private readonly PayrollModuleAccess $access,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array<string,string> $args */
    public function preview(Request $request, Response $response, array $args = []): Response
    {
        $error = null;
        $body = $this->body($request, $response, $error);
        if ($body === null) {
            return $this->errorResponse($error);
        }
        $employmentIds = $body['employment_ids'] ?? null;
        if ($employmentIds !== null && (!is_array($employmentIds) || !array_is_list($employmentIds))) {
            return $this->invalid($response, 'employment_ids musí být seznam nebo null.');
        }

        try {
            $preview = $this->bulk->preview(
                $this->currentSupplierId($request),
                $this->text($body, 'period_start'),
                $employmentIds,
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($response, $exception->getMessage());
        }

        return Json::ok($response, ['preview' => $preview]);
    }

    /** @param array<string,string> $args */
    public function apply(Request $request, Response $response, array $args = []): Response
    {
        $error = null;
        $body = $this->body($request, $response, $error);
        if ($body === null) {
            return $this->errorResponse($error);
        }
        $employmentIds = $body['employment_ids'] ?? null;
        if (!is_array($employmentIds) || !array_is_list($employmentIds)) {
            return $this->invalid($response, 'employment_ids musí být seznam vztahů z náhledu.');
        }

        try {
            $result = $this->bulk->apply(
                $this->currentSupplierId($request),
                $this->text($body, 'period_start'),
                $this->text($body, 'municipality_code'),
                $this->text($body, 'country_code'),
                $employmentIds,
                $this->userId($request),
                $this->clientIp($request),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($response, $exception->getMessage());
        }

        return Json::ok($response, ['result' => $result]);
    }

    /** @return array<string,mixed>|null */
    private function body(Request $request, Response $response, ?Response &$error): ?array
    {
        if (!RequestAuthorization::actsAsSession($request)) {
            $error = Json::sessionRequired($response);
            return null;
        }
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.employment.write',
            AccessLevel::WRITE,
            $error,
        )) {
            return null;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return null;
        }
        $parsed = $request->getParsedBody();
        if (!is_array($parsed) || array_is_list($parsed)) {
            $error = $this->invalid($response, 'Tělo požadavku musí být objekt.');
            return null;
        }
        $body = [];
        foreach ($parsed as $key => $value) {
            if (is_string($key)) {
                $body[$key] = $value;
            }
        }

        return $body;
    }

    /** @param array<string,mixed> $body */
    private function text(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value)) {
            throw new \InvalidArgumentException("{$key} musí být text.");
        }

        return $value;
    }

    private function invalid(Response $response, string $message): Response
    {
        return Json::error($response, 'validation_failed', $message, 422);
    }

    private function clientIp(Request $request): ?string
    {
        $params = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return $this->ipMatcher->clientIpFromRequest($params);
    }

    private function errorResponse(?Response $error): Response
    {
        return $error ?? throw new \LogicException('Chybí chybová HTTP odpověď.');
    }
}
