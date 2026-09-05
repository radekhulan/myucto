<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Component\PayrollInputImportService;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollInputImportsAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollInputImportService $imports,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {
    }

    public function preview(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        try {
            $body = $this->input($request);
            $preview = $this->imports->preview(
                $this->currentSupplierId($request),
                $this->string($body, 'period'),
                $this->string($body, 'format'),
                $this->string($body, 'source_name'),
                $this->content($body),
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        return Json::ok($response, ['preview' => $this->publicResult($preview)]);
    }

    public function apply(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        try {
            $body = $this->input($request);
            $result = $this->imports->apply(
                $this->currentSupplierId($request),
                $this->string($body, 'period'),
                $this->string($body, 'format'),
                $this->string($body, 'source_name'),
                $this->content($body),
                $this->userId($request),
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        $this->logger->log(
            'payroll.input_import.applied',
            $this->userId($request),
            'payroll_input_import',
            PayrollTimeValue::int($result['id'] ?? null, 'id'),
            [
                'period_start' => $result['period_start'],
                'source_kind' => $result['source_kind'],
                'status' => $result['status'],
                'row_count' => $result['row_count'],
                'accepted_count' => $result['accepted_count'],
                'rejected_count' => $result['rejected_count'],
                'duplicate_count' => $result['duplicate_count'],
                'replayed' => $result['replayed'] ?? false,
            ],
            $this->ipMatcher->clientIpFromRequest($this->serverParams($request)),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
        return Json::ok($response, ['import' => $result], 201);
    }

    private function authorize(Request $request, Response $response): ?Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.inputs.write',
            AccessLevel::WRITE,
            $error,
        )) {
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
        return is_array($body) ? PayrollTimeValue::row($body, 'request_body') : [];
    }

    /** @param array<string,mixed> $body */
    private function string(array $body, string $key): string
    {
        return PayrollTimeValue::string($body[$key] ?? null, $key);
    }

    /** @param array<string,mixed> $body */
    private function content(array $body): string
    {
        $encoded = $this->string($body, 'content_base64');
        if (strlen($encoded) > 6_700_000) {
            throw new \InvalidArgumentException('Importní obsah překračuje bezpečný limit.');
        }
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('content_base64 není platné Base64.');
        }
        return $decoded;
    }

    /** @param array<string,mixed> $result
     *  @return array<string,mixed>
     */
    private function publicResult(array $result): array
    {
        return array_filter(
            $result,
            static fn (string $key): bool => !str_starts_with($key, '_'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** @return array<string,mixed> */
    private function serverParams(Request $request): array
    {
        return PayrollTimeValue::row($request->getServerParams(), 'server_params');
    }
}
