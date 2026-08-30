<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Document\PayrollAnnualDocumentBatchQueueService;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKind;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Roční dokumenty za VÍC lidí přes serverovou frontu.
 *
 * Oprávnění i tvar odpovědi jsou schválně tytéž jako u měsíční dávky
 * výplatních pásek ({@see PayrollDocumentAction::generateBatch()}): účetní
 * nemá poznat, že pod tím jsou dvě tabulky.
 */
final class PayrollAnnualDocumentBatchAction
{
    use PayrollActionSupport;

    private const KINDS = [
        'payroll-sheet' => PayrollDocumentKind::PayrollSheet,
        'advance' => PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
        'withholding' => PayrollDocumentKind::TaxableIncomeWithholdingCertificate,
    ];

    public function __construct(
        private readonly PayrollAnnualDocumentBatchQueueService $batch,
        private readonly PayrollModuleAccess $moduleAccess,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array<string,string> $args */
    public function enqueue(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.documents',
            AccessLevel::WRITE,
            $error,
        ) || !$this->requirePayrollEnabled($request, $response, $this->moduleAccess, $error)) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        $supplierId = $this->currentSupplierId($request);
        $userId = $this->userId($request);
        $year = (int) ($args['year'] ?? 0);
        $kind = self::KINDS[$args['kind'] ?? ''] ?? null;
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $scope = is_string($body['scope'] ?? null) ? $body['scope'] : '';
        $employeeId = self::positiveInt($body['employee_id'] ?? null);
        if ($userId === null
            || $kind === null
            || $year < 2000
            || $year > 2199
            || !in_array($scope, ['selected', 'all'], true)
            || ($scope === 'selected' && $employeeId === null)
        ) {
            return Json::error(
                $response,
                'validation_failed',
                'Požadavek na roční dávku dokumentů je neplatný.',
                422,
            );
        }
        try {
            $batch = $this->batch->enqueue(
                $supplierId,
                $year,
                $kind,
                $scope,
                $scope === 'selected' ? $employeeId : null,
                $userId,
                $request->getHeaderLine('Idempotency-Key'),
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        } catch (\DomainException $exception) {
            return Json::error(
                $response,
                'annual_document_batch_empty',
                $exception->getMessage(),
                422,
            );
        } catch (\Throwable) {
            return Json::error(
                $response,
                'annual_document_batch_failed',
                'Roční dávku dokumentů nelze bezpečně založit.',
                409,
            );
        }
        $this->activity->log(
            'payroll.annual_document_batch_queued',
            $userId,
            'payroll_annual_document_batch',
            (int) $batch['id'],
            [
                'tax_year' => $year,
                'document_kind' => $kind->value,
                'scope' => $scope,
                'item_count' => $batch['item_count'],
                'status' => $batch['status'],
            ],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return Json::ok($response, ['batch' => $batch], 202)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** @param array<string,string> $args */
    public function detail(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.documents',
            AccessLevel::READ,
            $error,
        ) || !$this->requirePayrollEnabled($request, $response, $this->moduleAccess, $error)) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        $batch = $this->batch->detail(
            $this->currentSupplierId($request),
            (int) ($args['batchId'] ?? 0),
        );
        if ($batch === null) {
            return Json::error($response, 'not_found', 'Roční dávka dokumentů nebyla nalezena.', 404);
        }
        return Json::ok($response, ['batch' => $batch])
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** @param array<string,string> $args */
    public function items(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.documents',
            AccessLevel::READ,
            $error,
        ) || !$this->requirePayrollEnabled($request, $response, $this->moduleAccess, $error)) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        $query = $request->getQueryParams();
        try {
            $items = $this->batch->items(
                $this->currentSupplierId($request),
                (int) ($args['batchId'] ?? 0),
                max(1, min(100, (int) ($query['limit'] ?? 50))),
                max(0, (int) ($query['offset'] ?? 0)),
            );
        } catch (\OutOfBoundsException) {
            return Json::error($response, 'not_found', 'Roční dávka dokumentů nebyla nalezena.', 404);
        }
        return Json::ok($response, $items)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** @param array<string,string> $args */
    public function retryItem(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.documents',
            AccessLevel::WRITE,
            $error,
        ) || !$this->requirePayrollEnabled($request, $response, $this->moduleAccess, $error)) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        try {
            $item = $this->batch->retry(
                $this->currentSupplierId($request),
                (int) ($args['batchId'] ?? 0),
                (int) ($args['itemId'] ?? 0),
            );
        } catch (\DomainException $exception) {
            return Json::error(
                $response,
                'annual_document_batch_retry_invalid',
                $exception->getMessage(),
                409,
            );
        }
        return Json::ok($response, ['item' => $item], 202)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            $parsed = (int) $value;
            return $parsed > 0 ? $parsed : null;
        }
        return null;
    }
}
