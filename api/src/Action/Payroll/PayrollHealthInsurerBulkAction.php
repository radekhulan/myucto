<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollHealthInsurerBulkAssignment;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Hromadné zadání zdravotní pojišťovny ({@see PayrollHealthInsurerBulkAssignment}).
 *
 * Právo `payroll.person.write` — tentýž zápis zákonné evidence jako na kartě
 * osoby. Jen se session, stejně jako karta.
 */
final class PayrollHealthInsurerBulkAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollHealthInsurerBulkAssignment $bulk,
        private readonly PayrollModuleAccess $access,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array<string,string> $args */
    public function preview(Request $request, Response $response, array $args = []): Response
    {
        $error = null;
        $body = $this->body($request, $response, $error);
        if ($body === null) {
            return $error ?? throw new \LogicException('Chybí chybová HTTP odpověď.');
        }
        try {
            $preview = $this->bulk->preview(
                $this->currentSupplierId($request),
                is_string($body['period_start'] ?? null) ? $body['period_start'] : '',
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        }

        return Json::ok($response, ['preview' => $preview]);
    }

    /** @param array<string,string> $args */
    public function apply(Request $request, Response $response, array $args = []): Response
    {
        $error = null;
        $body = $this->body($request, $response, $error);
        if ($body === null) {
            return $error ?? throw new \LogicException('Chybí chybová HTTP odpověď.');
        }
        $assignments = $body['assignments'] ?? null;
        if (!is_array($assignments) || !array_is_list($assignments)) {
            return Json::error($response, 'validation_failed', 'assignments musí být seznam osob s pojišťovnou.', 422);
        }
        $params = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }
        try {
            $result = $this->bulk->apply(
                $this->currentSupplierId($request),
                $assignments,
                $this->userId($request),
                $this->ipMatcher->clientIpFromRequest($params),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
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
        if (!$this->requirePermission($request, $response, 'payroll.person.write', AccessLevel::WRITE, $error)) {
            return null;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return null;
        }
        $parsed = $request->getParsedBody();
        if (!is_array($parsed) || array_is_list($parsed)) {
            $error = Json::error($response, 'validation_failed', 'Tělo požadavku musí být objekt.', 422);
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
}
