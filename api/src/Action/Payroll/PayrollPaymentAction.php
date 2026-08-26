<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Payment\PayrollEnforcementLiabilityMaterializer;
use MyInvoice\Service\Payroll\Payment\PayrollHealthInsuranceLiabilityMaterializer;
use MyInvoice\Service\Payroll\Payment\PayrollIncomingRefundReconciliationCommand;
use MyInvoice\Service\Payroll\Payment\PayrollIncomingRefundReconciliationResult;
use MyInvoice\Service\Payroll\Payment\PayrollIncomeTaxLiabilityMaterializer;
use MyInvoice\Service\Payroll\Payment\PayrollNetWageLiabilityMaterializer;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentBatchBuilder;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentDownloadGrantService;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentEvidenceReference;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentExportService;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentQueryService;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentReconciliationCommand;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentReconciliationQueryService;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentReconciliationResult;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentReconciliationService;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentReversalCommand;
use MyInvoice\Service\Payroll\Payment\PayrollPersonAccountVerificationConflictException;
use MyInvoice\Service\Payroll\Payment\PayrollPersonAccountVerificationService;
use MyInvoice\Service\Payroll\Payment\PayrollSocialInsuranceLiabilityMaterializer;
use MyInvoice\Service\Payroll\Payment\PayrollRiskySavingsLiabilityMaterializer;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollPaymentAction
{
    use PayrollActionSupport;

    private int $savepointSequence = 0;

    public function __construct(
        private readonly PayrollPaymentQueryService $queries,
        private readonly PayrollPaymentReconciliationQueryService $reconciliationQueries,
        private readonly PayrollPaymentReconciliationService $reconciliation,
        private readonly PayrollNetWageLiabilityMaterializer $netWages,
        private readonly PayrollHealthInsuranceLiabilityMaterializer $healthInsurance,
        private readonly PayrollSocialInsuranceLiabilityMaterializer $socialInsurance,
        private readonly PayrollIncomeTaxLiabilityMaterializer $incomeTax,
        private readonly PayrollEnforcementLiabilityMaterializer $enforcement,
        private readonly PayrollRiskySavingsLiabilityMaterializer $riskySavings,
        private readonly PayrollPersonAccountVerificationService $accountVerification,
        private readonly PayrollPaymentBatchBuilder $batchBuilder,
        private readonly PayrollPaymentExportService $exportService,
        private readonly PayrollPaymentDownloadGrantService $downloadGrants,
        private readonly PayrollModuleAccess $moduleAccess,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
    ) {}

    /** @param array<string,string> $args */
    public function listLiabilities(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->authorize(
            $request,
            $response,
            'payroll.payments',
            AccessLevel::READ,
            $error,
        )) {
            return $this->errorResponse($error);
        }
        $query = $request->getQueryParams();
        $periodValue = $query['period'] ?? null;
        if (!is_string($periodValue)) {
            return Json::error(
                $response,
                'validation_failed',
                'Mzdové období musí být text ve tvaru RRRR-MM.',
                422,
            );
        }
        $period = trim($periodValue);
        // Strop je tvrdý, ne jen výchozí — z URL ho zvednout nejde.
        $limit = max(1, min(
            PayrollPaymentQueryService::LIST_MAX_LIMIT,
            (int) ($query['limit'] ?? PayrollPaymentQueryService::LIST_DEFAULT_LIMIT),
        ));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        try {
            $page = $this->queries->listForPeriod(
                $this->currentSupplierId($request),
                $period,
                $limit,
                $offset,
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        // Klíč `items` zůstává kvůli stávajícím volajícím; `total`/`limit`/
        // `offset` přibyly vedle něj. `totals` jsou součty za CELÉ období —
        // frontend je dřív sčítal z `items`, což by se stránkováním tiše
        // změnilo na „součet téhle stránky". U peněz je to nepřijatelné.
        return Json::ok($response, [
            'period' => $period,
            'items' => $page['items'],
            'total' => $page['total'],
            'totals' => $page['totals'],
            'limit' => $limit,
            'offset' => $offset,
        ])
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** @param array<string,string> $args */
    public function listPayerOptions(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->authorize(
            $request,
            $response,
            'payroll.payments',
            AccessLevel::READ,
            $error,
        )) {
            return $this->errorResponse($error);
        }
        try {
            $items = $this->queries->payerOptions(
                $this->currentSupplierId($request),
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        return Json::ok($response, ['items' => $items])
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** @param array<string,string> $args */
    public function listBatches(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->authorize(
            $request,
            $response,
            'payroll.payments',
            AccessLevel::READ,
            $error,
        )) {
            return $this->errorResponse($error);
        }
        $periodValue = $request->getQueryParams()['period'] ?? null;
        if (!is_string($periodValue)) {
            return Json::error(
                $response,
                'validation_failed',
                'Mzdové období musí být text ve tvaru RRRR-MM.',
                422,
            );
        }
        $period = trim($periodValue);
        try {
            $items = $this->queries->batchesForPeriod(
                $this->currentSupplierId($request),
                $period,
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        return Json::ok($response, [
            'period' => $period,
            'items' => $items,
        ])->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** @param array<string,string> $args */
    public function listReconciliation(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->authorize(
            $request,
            $response,
            'payroll.payments',
            AccessLevel::READ,
            $error,
        )) {
            return $this->errorResponse($error);
        }
        $periodValue = $request->getQueryParams()['period'] ?? null;
        if (!is_string($periodValue)) {
            return Json::error(
                $response,
                'validation_failed',
                'Mzdové období musí být text ve tvaru RRRR-MM.',
                422,
            );
        }
        $query = $request->getQueryParams();
        try {
            $result = $this->reconciliationQueries->forPeriod(
                $this->currentSupplierId($request),
                trim($periodValue),
                max(1, min(
                    PayrollPaymentReconciliationQueryService::LIST_MAX_LIMIT,
                    (int) ($query['limit']
                        ?? PayrollPaymentReconciliationQueryService::LIST_DEFAULT_LIMIT),
                )),
                max(0, (int) ($query['offset'] ?? 0)),
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
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /**
     * Serverové hledání v nabídce pickeru párování plateb.
     *
     * Nabídky se dřív posílaly celé, protože stránkovat je nešlo — z pickeru
     * by se stalo „vybrat jde jen to, co je na první straně". Tady se místo
     * stránky vrací nejvýš `limit` nejlepších shod a `truncated` říká, že jich
     * je víc. Bez toho příznaku by uživatel oříznutou nabídku četl jako úplnou.
     *
     * @param array<string,string> $args
     */
    public function searchReconciliationOptions(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->authorize(
            $request,
            $response,
            'payroll.payments',
            AccessLevel::READ,
            $error,
        )) {
            return $this->errorResponse($error);
        }
        $query = $request->getQueryParams();
        $periodValue = $query['period'] ?? null;
        $kindValue = $query['kind'] ?? null;
        if (!is_string($periodValue) || !is_string($kindValue)) {
            return Json::error(
                $response,
                'validation_failed',
                'Nabídka párování vyžaduje období a druh.',
                422,
            );
        }
        $filters = [
            'search' => is_string($query['q'] ?? null) ? trim($query['q']) : '',
            'currency' => is_string($query['currency'] ?? null)
                ? strtoupper(trim($query['currency']))
                : '',
            'direction' => is_string($query['direction'] ?? null)
                ? trim($query['direction'])
                : '',
            'usage' => is_string($query['usage'] ?? null) ? trim($query['usage']) : 'match',
            'cash_document_id' => (int) ($query['cash_document_id'] ?? 0),
        ];
        if ($filters['currency'] !== ''
            && preg_match('/^[A-Z]{3}$/D', $filters['currency']) !== 1
        ) {
            return Json::error(
                $response,
                'validation_failed',
                'Měna musí být třípísmenný kód.',
                422,
            );
        }
        if ($filters['direction'] !== ''
            && !in_array($filters['direction'], ['outgoing', 'incoming'], true)
        ) {
            return Json::error(
                $response,
                'validation_failed',
                'Směr platby musí být outgoing nebo incoming.',
                422,
            );
        }
        try {
            $result = $this->reconciliationQueries->searchOptions(
                $this->currentSupplierId($request),
                trim($periodValue),
                trim($kindValue),
                $filters,
                // Strop je tvrdý, ne jen výchozí — z URL ho zvednout nejde.
                max(1, min(
                    PayrollPaymentReconciliationQueryService::PICKER_MAX_LIMIT,
                    (int) ($query['limit']
                        ?? PayrollPaymentReconciliationQueryService::PICKER_DEFAULT_LIMIT),
                )),
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        return Json::ok($response, $result + ['kind' => trim($kindValue)])
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** @param array<string,string> $args */
    public function matchPayment(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->authorize(
            $request,
            $response,
            'payroll.payments',
            AccessLevel::WRITE,
            $error,
        )) {
            return $this->errorResponse($error);
        }
        $body = $request->getParsedBody();
        if (!is_array($body) || array_is_list($body)) {
            return Json::error(
                $response,
                'validation_failed',
                'Tělo požadavku musí být objekt.',
                422,
            );
        }
        $allocationId = $body['allocation_id'] ?? null;
        $amountMinor = $body['amount_minor'] ?? null;
        $idempotencyKey = $body['idempotency_key'] ?? null;
        $rawEvidence = $body['evidence'] ?? null;
        $userId = $this->userId($request);
        if (!is_int($allocationId)
            || !is_int($amountMinor)
            || !is_string($idempotencyKey)
            || $userId === null
        ) {
            return Json::error(
                $response,
                'validation_failed',
                'Párování vyžaduje alokaci, částku, důkaz a idempotentní klíč.',
                422,
            );
        }
        try {
            $evidence = $this->evidenceReference($rawEvidence);
            $supplierId = $this->currentSupplierId($request);
            $result = $this->transaction(function () use (
                $supplierId,
                $allocationId,
                $amountMinor,
                $evidence,
                $idempotencyKey,
                $userId,
                $request,
            ): PayrollPaymentReconciliationResult {
                $result = $this->reconciliation->match(
                    new PayrollPaymentReconciliationCommand(
                        $supplierId,
                        $allocationId,
                        $amountMinor,
                        $evidence,
                        trim($idempotencyKey),
                        $userId,
                    ),
                );
                $this->logPaymentActivity(
                    $request,
                    $result->replayed
                        ? 'payroll.payment_match_replayed'
                        : 'payroll.payment_matched',
                    'payroll_payment_match',
                    $result->id,
                    [
                        'allocation_id' => $result->allocationId,
                        'amount_minor' => $result->amountMinor,
                        'evidence_kind' => $result->evidenceKind,
                        'actual_payment_date' =>
                            $result->actualPaymentDate,
                    ],
                    $supplierId,
                    $userId,
                );

                return $result;
            });
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        } catch (\DomainException $exception) {
            return Json::error(
                $response,
                'payment_match_blocked',
                $exception->getMessage(),
                409,
            );
        } catch (\PDOException $exception) {
            return $this->reconciliationConflict(
                $response,
                $exception,
            );
        }

        return Json::ok(
            $response,
            ['event' => $this->reconciliationResult($result)],
            201,
        )->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** @param array<string,string> $args */
    public function matchIncomingRefund(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->authorize(
            $request,
            $response,
            'payroll.payments',
            AccessLevel::WRITE,
            $error,
        )) {
            return $this->errorResponse($error);
        }
        $body = $request->getParsedBody();
        if (!is_array($body) || array_is_list($body)) {
            return Json::error(
                $response,
                'validation_failed',
                'Tělo požadavku musí být objekt.',
                422,
            );
        }
        $liabilityId = $body['liability_id'] ?? null;
        $amountMinor = $body['amount_minor'] ?? null;
        $idempotencyKey = $body['idempotency_key'] ?? null;
        $rawEvidence = $body['evidence'] ?? null;
        $userId = $this->userId($request);
        if (!is_int($liabilityId)
            || !is_int($amountMinor)
            || !is_string($idempotencyKey)
            || $userId === null
        ) {
            return Json::error(
                $response,
                'validation_failed',
                'Přijatá vratka vyžaduje závazek, částku, důkaz a idempotentní klíč.',
                422,
            );
        }
        try {
            $evidence = $this->evidenceReference($rawEvidence);
            $supplierId = $this->currentSupplierId($request);
            $result = $this->transaction(function () use (
                $supplierId,
                $liabilityId,
                $amountMinor,
                $evidence,
                $idempotencyKey,
                $userId,
                $request,
            ): PayrollIncomingRefundReconciliationResult {
                $result = $this->reconciliation->matchIncomingRefund(
                    new PayrollIncomingRefundReconciliationCommand(
                        $supplierId,
                        $liabilityId,
                        $amountMinor,
                        $evidence,
                        trim($idempotencyKey),
                        $userId,
                    ),
                );
                $this->logPaymentActivity(
                    $request,
                    $result->replayed
                        ? 'payroll.incoming_refund_replayed'
                        : 'payroll.incoming_refund_matched',
                    'payroll_payment_match',
                    $result->id,
                    [
                        'liability_id' => $result->liabilityId,
                        'amount_minor' => $result->amountMinor,
                        'evidence_kind' => $result->evidenceKind,
                        'actual_payment_date' =>
                            $result->actualPaymentDate,
                    ],
                    $supplierId,
                    $userId,
                );

                return $result;
            });
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        } catch (\DomainException $exception) {
            return Json::error(
                $response,
                'incoming_refund_match_blocked',
                $exception->getMessage(),
                409,
            );
        } catch (\PDOException $exception) {
            return $this->reconciliationConflict($response, $exception);
        }

        return Json::ok(
            $response,
            ['event' => $this->incomingRefundResult($result)],
            201,
        )->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** @param array<string,string> $args */
    public function reverseIncomingRefund(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->authorize(
            $request,
            $response,
            'payroll.payments',
            AccessLevel::WRITE,
            $error,
        )) {
            return $this->errorResponse($error);
        }
        $body = $request->getParsedBody();
        if (!is_array($body) || array_is_list($body)) {
            return Json::error(
                $response,
                'validation_failed',
                'Tělo požadavku musí být objekt.',
                422,
            );
        }
        $sourceMatchId = $body['source_match_id'] ?? null;
        $amountMinor = $body['amount_minor'] ?? null;
        $idempotencyKey = $body['idempotency_key'] ?? null;
        $rawEvidence = $body['evidence'] ?? null;
        $userId = $this->userId($request);
        if (!is_int($sourceMatchId)
            || !is_int($amountMinor)
            || !is_string($idempotencyKey)
            || $userId === null
        ) {
            return Json::error(
                $response,
                'validation_failed',
                'Reverze vratky vyžaduje původní příjem, částku, důkaz a idempotentní klíč.',
                422,
            );
        }
        try {
            $evidence = $this->evidenceReference($rawEvidence);
            $supplierId = $this->currentSupplierId($request);
            $result = $this->transaction(function () use (
                $supplierId,
                $sourceMatchId,
                $amountMinor,
                $evidence,
                $idempotencyKey,
                $userId,
                $request,
            ): PayrollIncomingRefundReconciliationResult {
                $result = $this->reconciliation->reverseIncomingRefund(
                    new PayrollPaymentReversalCommand(
                        $supplierId,
                        $sourceMatchId,
                        $amountMinor,
                        $evidence,
                        trim($idempotencyKey),
                        $userId,
                    ),
                );
                $this->logPaymentActivity(
                    $request,
                    $result->replayed
                        ? 'payroll.incoming_refund_reversal_replayed'
                        : 'payroll.incoming_refund_reversed',
                    'payroll_payment_match',
                    $result->id,
                    [
                        'liability_id' => $result->liabilityId,
                        'source_match_id' => $result->sourceMatchId,
                        'amount_minor' => $result->amountMinor,
                        'evidence_kind' => $result->evidenceKind,
                    ],
                    $supplierId,
                    $userId,
                );

                return $result;
            });
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        } catch (\DomainException $exception) {
            return Json::error(
                $response,
                'incoming_refund_reversal_blocked',
                $exception->getMessage(),
                409,
            );
        } catch (\PDOException $exception) {
            return $this->reconciliationConflict($response, $exception);
        }

        return Json::ok(
            $response,
            ['event' => $this->incomingRefundResult($result)],
            201,
        )->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** @param array<string,string> $args */
    public function reversePayment(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->authorize(
            $request,
            $response,
            'payroll.payments',
            AccessLevel::WRITE,
            $error,
        )) {
            return $this->errorResponse($error);
        }
        $body = $request->getParsedBody();
        if (!is_array($body) || array_is_list($body)) {
            return Json::error(
                $response,
                'validation_failed',
                'Tělo požadavku musí být objekt.',
                422,
            );
        }
        $sourceMatchId = $body['source_match_id'] ?? null;
        $amountMinor = $body['amount_minor'] ?? null;
        $idempotencyKey = $body['idempotency_key'] ?? null;
        $rawEvidence = $body['evidence'] ?? null;
        $userId = $this->userId($request);
        if (!is_int($sourceMatchId)
            || !is_int($amountMinor)
            || !is_string($idempotencyKey)
            || $userId === null
        ) {
            return Json::error(
                $response,
                'validation_failed',
                'Reverze vyžaduje původní platbu, částku, důkaz a idempotentní klíč.',
                422,
            );
        }
        try {
            $evidence = $this->evidenceReference($rawEvidence);
            $supplierId = $this->currentSupplierId($request);
            $result = $this->transaction(function () use (
                $supplierId,
                $sourceMatchId,
                $amountMinor,
                $evidence,
                $idempotencyKey,
                $userId,
                $request,
            ): PayrollPaymentReconciliationResult {
                $result = $this->reconciliation->reverse(
                    new PayrollPaymentReversalCommand(
                        $supplierId,
                        $sourceMatchId,
                        $amountMinor,
                        $evidence,
                        trim($idempotencyKey),
                        $userId,
                    ),
                );
                $this->logPaymentActivity(
                    $request,
                    $result->replayed
                        ? 'payroll.payment_reversal_replayed'
                        : 'payroll.payment_reversed',
                    'payroll_payment_match',
                    $result->id,
                    [
                        'source_match_id' => $result->sourceMatchId,
                        'amount_minor' => $result->amountMinor,
                        'evidence_kind' => $result->evidenceKind,
                        'actual_payment_date' =>
                            $result->actualPaymentDate,
                    ],
                    $supplierId,
                    $userId,
                );

                return $result;
            });
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        } catch (\DomainException $exception) {
            return Json::error(
                $response,
                'payment_reversal_blocked',
                $exception->getMessage(),
                409,
            );
        } catch (\PDOException $exception) {
            return $this->reconciliationConflict(
                $response,
                $exception,
            );
        }

        return Json::ok(
            $response,
            ['event' => $this->reconciliationResult($result)],
            201,
        )->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** @param array<string,string> $args */
    public function createBatch(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->authorize(
            $request,
            $response,
            'payroll.payments',
            AccessLevel::WRITE,
            $error,
        )) {
            return $this->errorResponse($error);
        }
        $body = $request->getParsedBody();
        if (!is_array($body) || array_is_list($body)) {
            return Json::error(
                $response,
                'validation_failed',
                'Tělo požadavku musí být objekt.',
                422,
            );
        }
        $format = $body['export_format'] ?? null;
        $payerReference = $body['payer_reference'] ?? null;
        $rawItems = $body['items'] ?? null;
        if (!is_string($format)
            || !is_string($payerReference)
            || !is_array($rawItems)
            || !array_is_list($rawItems)
        ) {
            return Json::error(
                $response,
                'validation_failed',
                'Dávka vyžaduje formát, účet plátce a seznam závazků.',
                422,
            );
        }
        $items = [];
        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem) || array_is_list($rawItem)) {
                return Json::error(
                    $response,
                    'validation_failed',
                    'Každá položka dávky musí být objekt.',
                    422,
                );
            }
            $liabilityId = $rawItem['liability_id'] ?? null;
            $amountMinor = $rawItem['amount_minor'] ?? null;
            if (!is_int($liabilityId) || !is_int($amountMinor)) {
                return Json::error(
                    $response,
                    'validation_failed',
                    'Závazek i částka dávky musí být celá čísla.',
                    422,
                );
            }
            $items[] = [
                'liability_id' => $liabilityId,
                'amount_minor' => $amountMinor,
            ];
        }
        $userId = $this->userId($request);
        if ($userId === null) {
            return Json::error(
                $response,
                'session_required',
                'Chybí přihlášený uživatel.',
                403,
            );
        }
        $supplierId = $this->currentSupplierId($request);
        try {
            $result = $this->transaction(function () use (
                $supplierId,
                $format,
                $payerReference,
                $items,
                $userId,
                $request,
            ): array {
                $result = $this->batchBuilder->build(
                    $supplierId,
                    trim($format),
                    trim($payerReference),
                    $items,
                    $userId,
                );
                $this->logPaymentActivity(
                    $request,
                    $result['created']
                        ? 'payroll.payment_batch_created'
                        : 'payroll.payment_batch_replayed',
                    'payroll_payment_batch',
                    $result['batch_id'],
                    [
                        'export_format' => $result['export_format'],
                        'item_count' => $result['declared_item_count'],
                        'total_minor' => $result['declared_total_minor'],
                    ],
                    $supplierId,
                    $userId,
                );

                return $result;
            });
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        } catch (\DomainException $exception) {
            return Json::error(
                $response,
                'payment_batch_blocked',
                $exception->getMessage(),
                409,
            );
        }

        return Json::ok($response, $result, 201);
    }

    /** @param array{batchId:string} $args */
    public function generateExport(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->authorize(
            $request,
            $response,
            'payroll.payments',
            AccessLevel::WRITE,
            $error,
        )) {
            return $this->errorResponse($error);
        }
        $body = $request->getParsedBody();
        $idempotencyKey = is_array($body) && !array_is_list($body)
            ? ($body['idempotency_key'] ?? null)
            : null;
        $batchId = (int) $args['batchId'];
        $userId = $this->userId($request);
        if (!is_string($idempotencyKey)
            || $batchId <= 0
            || $userId === null
        ) {
            return Json::error(
                $response,
                'validation_failed',
                'Export vyžaduje dávku a idempotentní klíč.',
                422,
            );
        }
        $supplierId = $this->currentSupplierId($request);
        try {
            $result = $this->exportService->export(
                $supplierId,
                $batchId,
                trim($idempotencyKey),
                $userId,
                function (array $export) use (
                    $request,
                    $supplierId,
                    $userId,
                ): void {
                    $this->logPaymentActivity(
                        $request,
                        $export['created']
                            ? 'payroll.payment_export_generated'
                            : 'payroll.payment_export_replayed',
                        'payroll_payment_export',
                        $export['export_id'],
                        [
                            'batch_id' => $export['batch_id'],
                            'export_format' =>
                                $export['export_format'],
                            'revision_no' =>
                                $export['export_revision_no'],
                            'file_sha256' => $export['file_sha256'],
                            'size_bytes' => $export['size_bytes'],
                        ],
                        $supplierId,
                        $userId,
                    );
                },
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        } catch (\DomainException $exception) {
            return Json::error(
                $response,
                'payment_export_blocked',
                $exception->getMessage(),
                409,
            );
        }

        return Json::ok($response, [
            'export_id' => $result['export_id'],
            'batch_id' => $result['batch_id'],
            'export_format' => $result['export_format'],
            'export_revision_no' => $result['export_revision_no'],
            'source_snapshot_hash' =>
                $result['source_snapshot_hash'],
            'file_sha256' => $result['file_sha256'],
            'size_bytes' => $result['size_bytes'],
            'mime_type' => $result['mime_type'],
            'suggested_filename' => $result['suggested_filename'],
            'created' => $result['created'],
            'replayed' => $result['replayed'],
        ], 201);
    }

    /** @param array{exportId:string} $args */
    public function createDownloadGrant(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->authorize(
            $request,
            $response,
            'payroll.payments',
            AccessLevel::WRITE,
            $error,
        )) {
            return $this->errorResponse($error);
        }
        $body = $request->getParsedBody();
        if ($body !== null
            && (!is_array($body) || array_is_list($body))
        ) {
            return Json::error(
                $response,
                'validation_failed',
                'Tělo požadavku musí být objekt.',
                422,
            );
        }
        $ttl = is_array($body) && array_key_exists('ttl_seconds', $body)
            ? $body['ttl_seconds']
            : 120;
        $exportId = (int) $args['exportId'];
        $userId = $this->userId($request);
        if (!is_int($ttl) || $exportId <= 0 || $userId === null) {
            return Json::error(
                $response,
                'validation_failed',
                'Download grant vyžaduje export a platnou dobu platnosti.',
                422,
            );
        }
        $supplierId = $this->currentSupplierId($request);
        try {
            $result = $this->downloadGrants->issue(
                $supplierId,
                $exportId,
                $userId,
                $ttl,
                function (array $grant) use (
                    $request,
                    $supplierId,
                    $userId,
                ): void {
                    $this->logPaymentActivity(
                        $request,
                        'payroll.payment_export_grant_issued',
                        'payroll_payment_export',
                        $grant['export_id'],
                        [
                            'grant_id' => $grant['grant_id'],
                            'ttl_seconds' => $grant['ttl_seconds'],
                        ],
                        $supplierId,
                        $userId,
                    );
                },
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        } catch (\DomainException $exception) {
            return Json::error(
                $response,
                'not_found',
                $exception->getMessage(),
                404,
            );
        }

        return Json::ok($response, $result, 201)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** @param array<string,string> $args */
    public function downloadExport(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->authorize(
            $request,
            $response,
            'payroll.payments',
            AccessLevel::WRITE,
            $error,
        )) {
            return $this->errorResponse($error);
        }
        $body = $request->getParsedBody();
        $token = is_array($body) && !array_is_list($body)
            ? ($body['token'] ?? null)
            : null;
        $userId = $this->userId($request);
        if (!is_string($token) || $userId === null) {
            return Json::error(
                $response,
                'validation_failed',
                'Stažení vyžaduje platný jednorázový token.',
                422,
            );
        }
        $supplierId = $this->currentSupplierId($request);
        try {
            $file = $this->downloadGrants->consume(
                $supplierId,
                $userId,
                trim($token),
                function (array $download) use (
                    $request,
                    $supplierId,
                    $userId,
                ): void {
                    $this->logPaymentActivity(
                        $request,
                        'payroll.payment_export_downloaded',
                        'payroll_payment_export',
                        $download['export_id'],
                        [
                            'file_sha256' => $download['file_sha256'],
                            'size_bytes' => $download['size_bytes'],
                        ],
                        $supplierId,
                        $userId,
                    );
                },
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        } catch (\DomainException $exception) {
            return Json::error(
                $response,
                'download_grant_invalid',
                $exception->getMessage(),
                409,
            );
        }
        $filename = preg_replace(
            '/[^A-Za-z0-9._-]+/',
            '-',
            $file['suggested_filename'],
        );
        if (!is_string($filename) || $filename === '') {
            $filename = 'mzdovy-platebni-export';
        }
        $response->getBody()->write($file['bytes']);

        return $response
            ->withHeader('Content-Type', $file['mime_type'])
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . $filename . '"',
            )
            ->withHeader('Content-Length', (string) $file['size_bytes'])
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader(
                'Content-Security-Policy',
                "default-src 'none'; sandbox",
            );
    }

    /** @param array{revisionId:string} $args */
    public function materializeNetWages(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->authorize(
            $request,
            $response,
            'payroll.payments',
            AccessLevel::WRITE,
            $error,
        )) {
            return $this->errorResponse($error);
        }
        $userId = $this->userId($request);
        $revisionId = (int) $args['revisionId'];
        if ($userId === null || $revisionId <= 0) {
            return Json::error(
                $response,
                'validation_failed',
                'Požadavek na vytvoření platebních závazků není platný.',
                422,
            );
        }
        $supplierId = $this->currentSupplierId($request);
        try {
            $result = $this->transaction(
                function () use (
                    $supplierId,
                    $revisionId,
                    $userId,
                    $request,
                ): array {
                    $result = $this->netWages->materialize(
                        $supplierId,
                        $revisionId,
                        $userId,
                    );
                    $this->activity->log(
                        'payroll.payment_liabilities_materialized',
                        $userId,
                        'payroll_run_revision',
                        $revisionId,
                        [
                            'created_count' => $result['created_count'],
                            'liability_count' =>
                                count($result['liability_ids']),
                        ],
                        $this->ipMatcher->clientIpFromRequest(
                            $this->serverParameters($request),
                        ),
                        $request->getHeaderLine('User-Agent'),
                        $supplierId,
                    );

                    return $result;
                },
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        } catch (\DomainException $exception) {
            return Json::error(
                $response,
                'payment_liability_blocked',
                $exception->getMessage(),
                409,
            );
        }

        return Json::ok($response, $result, 201);
    }

    /** @param array{revisionId:string} $args */
    public function materializeLiabilities(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->authorize(
            $request,
            $response,
            'payroll.payments',
            AccessLevel::WRITE,
            $error,
        )) {
            return $this->errorResponse($error);
        }
        $userId = $this->userId($request);
        $revisionId = (int) $args['revisionId'];
        if ($userId === null || $revisionId <= 0) {
            return Json::error(
                $response,
                'validation_failed',
                'Požadavek na vytvoření platebních závazků není platný.',
                422,
            );
        }
        $supplierId = $this->currentSupplierId($request);
        $liabilityIds = [];
        $createdCount = 0;
        $issues = [];
        foreach ([
            'net_wage' => fn (): array => $this->netWages->materialize(
                $supplierId,
                $revisionId,
                $userId,
            ),
            'health_insurance' => fn (): array =>
                $this->healthInsurance->materialize(
                    $supplierId,
                    $revisionId,
                    $userId,
                ),
            'social_insurance' => fn (): array =>
                $this->socialInsurance->materialize(
                    $supplierId,
                    $revisionId,
                    $userId,
                ),
            'income_tax' => fn (): array => $this->incomeTax->materialize(
                $supplierId,
                $revisionId,
                $userId,
            ),
            'enforcement' => fn (): array => $this->enforcement->materialize(
                $supplierId,
                $revisionId,
                $userId,
            ),
            'risky_savings' => fn (): array => $this->riskySavings->materialize(
                $supplierId,
                $revisionId,
                $userId,
            ),
        ] as $liabilityKind => $materialize) {
            try {
                $result = $this->transaction(
                    function () use (
                        $materialize,
                        $liabilityKind,
                        $supplierId,
                        $revisionId,
                        $userId,
                        $request,
                    ): array {
                        $result = $materialize();
                        $this->activity->log(
                            'payroll.payment_liabilities_materialized',
                            $userId,
                            'payroll_run_revision',
                            $revisionId,
                            [
                                'liability_kind' => $liabilityKind,
                                'created_count' => $result['created_count'],
                                'liability_count' =>
                                    count($result['liability_ids']),
                            ],
                            $this->ipMatcher->clientIpFromRequest(
                                $this->serverParameters($request),
                            ),
                            $request->getHeaderLine('User-Agent'),
                            $supplierId,
                        );

                        return $result;
                    },
                );
                $liabilityIds = [
                    ...$liabilityIds,
                    ...$result['liability_ids'],
                ];
                $createdCount += $result['created_count'];
            } catch (\InvalidArgumentException|\DomainException $exception) {
                $issues[] = [
                    'liability_kind' => $liabilityKind,
                    'reason' => 'blocked',
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return Json::ok($response, [
            'liability_ids' => $liabilityIds,
            'created_count' => $createdCount,
            'preparation_issues' => $issues,
        ], $createdCount > 0 ? 201 : 200);
    }

    /** @param array{employeeId:string,accountId:string} $args */
    public function verifyPersonAccount(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->authorize(
            $request,
            $response,
            'payroll.person.write',
            AccessLevel::WRITE,
            $error,
        )) {
            return $this->errorResponse($error);
        }
        $body = $request->getParsedBody();
        if (!is_array($body) || array_is_list($body)) {
            return Json::error(
                $response,
                'validation_failed',
                'Tělo požadavku musí být objekt.',
                422,
            );
        }
        $verificationSource = $body['verification_source'] ?? null;
        $verifiedOn = $body['verified_on'] ?? null;
        $rowVersion = filter_var(
            $body['row_version'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if (!is_string($verificationSource)
            || !is_string($verifiedOn)
            || !is_int($rowVersion)
        ) {
            return Json::error(
                $response,
                'validation_failed',
                'Ověření účtu vyžaduje zdroj, datum a platnou verzi účtu.',
                422,
            );
        }
        $userId = $this->userId($request);
        $supplierId = $this->currentSupplierId($request);
        if ($userId === null) {
            return Json::error($response, 'session_required', 'Chybí přihlášený uživatel.', 403);
        }
        try {
            $employeeId = (int) $args['employeeId'];
            $accountId = (int) $args['accountId'];
            $account = $this->transaction(
                function () use (
                    $supplierId,
                    $employeeId,
                    $accountId,
                    $verificationSource,
                    $verifiedOn,
                    $rowVersion,
                    $userId,
                    $request,
                ): array {
                    $account = $this->accountVerification->verify(
                        $supplierId,
                        $employeeId,
                        $accountId,
                        trim($verificationSource),
                        trim($verifiedOn),
                        $userId,
                        $rowVersion,
                    );
                    $this->activity->log(
                        'payroll.person_account_verified',
                        $userId,
                        'payroll_person_account',
                        (int) $account['id'],
                        [
                            'employee_id' => $employeeId,
                            'verification_source' =>
                                $account['verification_source'],
                            'verified_on' => $account['verified_on'],
                            'row_version' => $account['row_version'],
                        ],
                        $this->ipMatcher->clientIpFromRequest(
                            $this->serverParameters($request),
                        ),
                        $request->getHeaderLine('User-Agent'),
                        $supplierId,
                    );

                    return $account;
                },
            );
        } catch (PayrollPersonAccountVerificationConflictException $exception) {
            return Json::error(
                $response,
                'row_version_conflict',
                $exception->getMessage(),
                409,
                ['current_row_version' => $exception->currentVersion],
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        } catch (\DomainException) {
            return Json::error(
                $response,
                'not_found',
                'Zaměstnanecký účet nebyl nalezen nebo jej nelze ověřit.',
                404,
            );
        }

        return Json::ok($response, ['account' => $account]);
    }

    private function evidenceReference(
        mixed $rawEvidence,
    ): PayrollPaymentEvidenceReference {
        if (!is_array($rawEvidence) || array_is_list($rawEvidence)) {
            throw new \InvalidArgumentException(
                'Platební důkaz musí být objekt.',
            );
        }
        $kind = $rawEvidence['kind'] ?? null;
        if ($kind === 'bank') {
            $statementId = $rawEvidence['bank_statement_id'] ?? null;
            $transactionId = $rawEvidence['bank_transaction_id'] ?? null;
            if (!is_int($statementId) || !is_int($transactionId)) {
                throw new \InvalidArgumentException(
                    'Bankovní důkaz vyžaduje výpis a pohyb.',
                );
            }

            return PayrollPaymentEvidenceReference::bank(
                $statementId,
                $transactionId,
            );
        }
        if ($kind === 'cash') {
            $cashDocumentId = $rawEvidence['cash_document_id'] ?? null;
            if (!is_int($cashDocumentId)) {
                throw new \InvalidArgumentException(
                    'Pokladní důkaz vyžaduje pokladní doklad.',
                );
            }

            return PayrollPaymentEvidenceReference::cash($cashDocumentId);
        }
        throw new \InvalidArgumentException(
            'Druh platebního důkazu není podporován.',
        );
    }

    /** @return array<string,int|string|bool|null> */
    private function reconciliationResult(
        PayrollPaymentReconciliationResult $result,
    ): array {
        return [
            'id' => $result->id,
            'allocation_id' => $result->allocationId,
            'event_kind' => $result->eventKind,
            'source_match_id' => $result->sourceMatchId,
            'amount_minor' => $result->amountMinor,
            'evidence_kind' => $result->evidenceKind,
            'bank_statement_id' => $result->bankStatementId,
            'bank_transaction_id' => $result->bankTransactionId,
            'cash_document_id' => $result->cashDocumentId,
            'actual_payment_date' => $result->actualPaymentDate,
            'evidence_amount_minor' => $result->evidenceAmountMinor,
            'evidence_currency_code' =>
                $result->evidenceCurrencyCode,
            'evidence_fact_hash' => $result->evidenceFactHash,
            'replayed' => $result->replayed,
        ];
    }

    /** @return array<string,int|string|bool|null> */
    private function incomingRefundResult(
        PayrollIncomingRefundReconciliationResult $result,
    ): array {
        return [
            'id' => $result->id,
            'allocation_id' => null,
            'liability_id' => $result->liabilityId,
            'event_kind' => $result->eventKind,
            'source_match_id' => $result->sourceMatchId,
            'amount_minor' => $result->amountMinor,
            'evidence_kind' => $result->evidenceKind,
            'bank_statement_id' => $result->bankStatementId,
            'bank_transaction_id' => $result->bankTransactionId,
            'cash_document_id' => $result->cashDocumentId,
            'actual_payment_date' => $result->actualPaymentDate,
            'evidence_amount_minor' => $result->evidenceAmountMinor,
            'evidence_currency_code' =>
                $result->evidenceCurrencyCode,
            'evidence_fact_hash' => $result->evidenceFactHash,
            'replayed' => $result->replayed,
        ];
    }

    private function reconciliationConflict(
        Response $response,
        \PDOException $exception,
    ): Response {
        if (!in_array((string) $exception->getCode(), ['23000', '45000'], true)) {
            throw $exception;
        }

        return Json::error(
            $response,
            'payment_evidence_conflict',
            'Platební důkaz mezitím použil jiný proces. Obnovte data a akci zopakujte.',
            409,
        );
    }

    private function authorize(
        Request $request,
        Response $response,
        string $permission,
        AccessLevel $access,
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
        return $this->requirePermission(
            $request,
            $response,
            $permission,
            $access,
            $error,
        ) && $this->requirePayrollEnabled(
            $request,
            $response,
            $this->moduleAccess,
            $error,
        );
    }

    private function errorResponse(?Response $error): Response
    {
        return $error ?? throw new \LogicException('Chybí chybová HTTP odpověď.');
    }

    /** @param array<string,mixed> $payload */
    private function logPaymentActivity(
        Request $request,
        string $action,
        string $entityType,
        int $entityId,
        array $payload,
        int $supplierId,
        int $userId,
    ): void {
        $this->activity->log(
            $action,
            $userId,
            $entityType,
            $entityId,
            $payload,
            $this->ipMatcher->clientIpFromRequest(
                $this->serverParameters($request),
            ),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        $savepoint = null;
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $savepoint = 'payroll_payment_action_'
                . ++$this->savepointSequence;
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            $result = $callback();
            if ($ownsTransaction) {
                $pdo->commit();
            } elseif ($savepoint !== null) {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            } elseif ($savepoint !== null) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    private function serverParameters(Request $request): array
    {
        $result = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
