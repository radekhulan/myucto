<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollEmploymentBulkClosure;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Hromadné ukončení skončených dohod a archivace ({@see PayrollEmploymentBulkClosure}).
 *
 * Náhled i zápis chrání `payroll.employment.write` — totéž právo jako ruční
 * přechod stavu na kartě vztahu. Stejně jako karta jen se session.
 */
final class PayrollEmploymentBulkClosureAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollEmploymentBulkClosure $bulk,
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

        try {
            $preview = $this->bulk->preview(
                $this->currentSupplierId($request),
                $this->months($body, 'inactivity_months', 3),
                $this->months($body, 'archive_after_months', 12),
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
        $items = $body['items'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            return $this->invalid($response, 'items musí být seznam vztahů z náhledu.');
        }

        try {
            $result = $this->bulk->apply(
                $this->currentSupplierId($request),
                $items,
                $this->userId($request),
                $this->clientIp($request),
                $request->getHeaderLine('User-Agent'),
                null,
                $this->months($body, 'inactivity_months', 3),
                $this->months($body, 'archive_after_months', 12),
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($response, $exception->getMessage());
        }

        return Json::ok($response, ['result' => $result]);
    }

    /** @param array<string,mixed> $body */
    private function months(array $body, string $key, int $default): int
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return $default;
        }
        $value = filter_var($body[$key], FILTER_VALIDATE_INT);
        if (!is_int($value)) {
            throw new \InvalidArgumentException("{$key} musí být celé číslo.");
        }

        return $value;
    }

    /** @return array<string,mixed>|null */
    private function body(Request $request, Response $response, ?Response &$error): ?array
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
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
        if ($parsed === null) {
            return [];
        }
        if (!is_array($parsed) || ($parsed !== [] && array_is_list($parsed))) {
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
