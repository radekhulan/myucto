<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Closing;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentExporter;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxDocumentRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class NonprofitTaxAssessmentAction
{
    use AccountingActionSupport;

    public function __construct(private readonly NonprofitTaxAssessmentService $service, private readonly NonprofitTaxAssessmentExporter $exporter,
        private readonly NonprofitTaxDocumentRenderer $documents) {}

    public function read(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, false, fn (int $supplier): array => $this->service->read($supplier, (int) $args['id']));
    }

    public function preview(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        return $this->handle($request, $response, false, fn (int $supplier): array => $this->service->preview($supplier, (int) $args['id'], (array) ($body['assessment'] ?? [])));
    }

    public function confirm(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        return $this->handle($request, $response, true, fn (int $supplier): array => $this->service->confirm($supplier, (int) $args['id'],
            (array) ($body['assessment'] ?? []), (string) ($body['input_hash'] ?? ''), (string) ($body['reason'] ?? ''),
            (string) ($body['idempotency_key'] ?? ''), $this->userId($request) ?? 0,
            isset($body['expected_assessment_id']) ? (int) $body['expected_assessment_id'] : null));
    }

    public function withdraw(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        return $this->handle($request, $response, true, fn (int $supplier): array => $this->service->withdraw($supplier, (int) $args['id'],
            (int) ($body['expected_id'] ?? 0), (string) ($body['reason'] ?? ''), (string) ($body['idempotency_key'] ?? ''), $this->userId($request) ?? 0));
    }

    public function export(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, false, fn (int $supplier): array => $this->exporter->export($supplier, (int) $args['assessmentId']));
    }

    public function history(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, false, fn (int $supplier): array => $this->service->history($supplier, (int) $args['id']));
    }

    public function exportPdf(Request $request, Response $response, array $args): Response
    {
        return $this->document($request, $response, $args, 'pdf');
    }

    public function exportXlsx(Request $request, Response $response, array $args): Response
    {
        return $this->document($request, $response, $args, 'xlsx');
    }

    private function document(Request $request, Response $response, array $args, string $format): Response
    {
        return $this->handle($request, $response, false, function (int $supplier) use ($response, $args, $format): Response {
            $id = (int) $args['assessmentId'];
            $payload = $this->exporter->export($supplier, $id);
            $bytes = $format === 'pdf' ? $this->documents->render($payload) : $this->documents->xlsx($payload);
            $response->getBody()->write($bytes);
            return $response->withHeader('Content-Type', $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->withHeader('Content-Disposition', 'attachment; filename="danove-posouzeni-' . $id . '.' . $format . '"')
                ->withHeader('Cache-Control', 'private, no-store')->withHeader('X-Content-Type-Options', 'nosniff');
        });
    }

    private function handle(Request $request, Response $response, bool $write, callable $work): Response
    {
        if ($write && !\MyInvoice\Security\RequestAuthorization::isSessionAuth($request)) {
            return Json::error($response, 'nonprofit_tax_session_only', 'Potvrzení a odvolání daňového posouzení vyžaduje přihlášenou relaci.', 403);
        }
        $allowed = $write ? $this->requireClose($request, $response, $error) : $this->requirePermission($request, $response, 'accounting', AccessLevel::READ, $error);
        if (!$allowed) return $error;
        try {
            $body = $request->getParsedBody() ?? [];
            if (!is_array($body)) NonprofitTaxRules::invalid('Požadavek musí obsahovat strukturované podklady.');
            foreach (['input_hash', 'reason', 'idempotency_key'] as $key) {
                if (array_key_exists($key, $body) && !is_string($body[$key])) NonprofitTaxRules::invalid('Otisk, důvod a klíč požadavku musí být textové.');
            }
            foreach (['expected_id', 'expected_assessment_id'] as $key) {
                if (isset($body[$key]) && (!is_int($body[$key]) || $body[$key] <= 0)) NonprofitTaxRules::invalid('Očekávaná revize musí mít platný číselný identifikátor.');
            }
            if (array_key_exists('assessment', $body) && !is_array($body['assessment'])) NonprofitTaxRules::invalid('Posouzení musí obsahovat strukturované podklady.');
            $result = $work($this->currentSupplierId($request));
            return $result instanceof Response ? $result : Json::ok($response, $result);
        } catch (NonprofitTaxAssessmentException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }
}
