<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\GoPay;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\GoPay\GoPayException;
use MyInvoice\Service\Accounting\GoPay\GoPayService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GoPayAction
{
    use AccountingActionSupport;

    public function __construct(
        private readonly GoPayService $service,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function settings(Request $request, Response $response): Response
    {
        try {
            return Json::ok($response, $this->service->settings(
                $this->currentSupplierId($request),
                (string) ($request->getQueryParams()['currency'] ?? 'CZK'),
            ));
        } catch (\Throwable $e) {
            return $this->error($response, $e);
        }
    }

    public function saveSettings(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'bank.post', AccessLevel::WRITE, $err)) {
            return $err;
        }
        try {
            $result = $this->service->saveSettings(
                $this->currentSupplierId($request),
                (array) ($request->getParsedBody() ?? []),
                $this->userId($request),
            );
            $this->log($request, 'gopay.settings_updated', null, ['currency' => $result['settings']['currency'] ?? 'CZK']);
            return Json::ok($response, $result);
        } catch (\Throwable $e) {
            return $this->error($response, $e);
        }
    }

    public function list(Request $request, Response $response): Response
    {
        try {
            return Json::ok($response, ['items' => $this->service->listClearings($this->currentSupplierId($request))]);
        } catch (\Throwable $e) {
            return $this->error($response, $e);
        }
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        try {
            return Json::ok($response, $this->service->detail($this->currentSupplierId($request), (int) ($args['id'] ?? 0)));
        } catch (\Throwable $e) {
            return $this->error($response, $e);
        }
    }

    public function payoutCandidate(Request $request, Response $response, array $args): Response
    {
        try {
            return Json::ok($response, [
                'candidate' => $this->service->payoutCandidateForTransaction(
                    $this->currentSupplierId($request),
                    (int) ($args['transactionId'] ?? 0),
                ),
            ]);
        } catch (\Throwable $e) {
            return $this->error($response, $e);
        }
    }

    public function associatePayout(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'bank.match', AccessLevel::WRITE, $err)
            || !$this->requirePermission($request, $response, 'bank.post', AccessLevel::WRITE, $err)) {
            return $err;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $transactionId = (int) ($body['transaction_id'] ?? 0);
        if ($transactionId <= 0) {
            return Json::error($response, 'gopay.invalid_transaction', 'Chybí bankovní pohyb.', 422);
        }
        try {
            $result = $this->service->associatePayoutTransaction(
                $this->currentSupplierId($request),
                (int) ($args['id'] ?? 0),
                $transactionId,
                $this->userId($request),
            );
            $this->log($request, 'gopay.payout_matched', (int) ($result['id'] ?? 0), [
                'clearing_id' => $result['clearing_id'] ?? null,
                'bank_transaction_id' => $transactionId,
                'provisional' => ($result['payout_issue_code'] ?? null) === 'email_notice_provisional',
            ]);
            return Json::ok($response, $result);
        } catch (\Throwable $e) {
            return $this->error($response, $e);
        }
    }

    public function import(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'bank.import', AccessLevel::WRITE, $err)
            || !$this->requirePermission($request, $response, 'bank.post', AccessLevel::WRITE, $err)) {
            return $err;
        }
        $file = $request->getUploadedFiles()['file'] ?? null;
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'gopay.no_file', 'Vyber GoPay XML soubor.', 400);
        }
        if (($file->getSize() ?? 0) > 2_097_152) {
            return Json::error($response, 'gopay.invalid_file_size', 'GoPay XML překračuje limit 2 MB.', 413);
        }
        try {
            $content = $file->getStream()->getContents();
            if (strlen($content) > 2_097_152) {
                return Json::error($response, 'gopay.invalid_file_size', 'GoPay XML překračuje limit 2 MB.', 413);
            }
            $result = $this->service->import(
                $this->currentSupplierId($request),
                $this->userId($request),
                $file->getClientFilename() ?: 'GoPay-clearing.xml',
                $content,
            );
            $clearing = $result['clearing'];
            $this->log($request, $result['duplicate'] ? 'gopay.clearing_reprocessed' : 'gopay.clearing_imported',
                (int) ($clearing['id'] ?? 0), [
                    'clearing_id' => $clearing['clearing_id'] ?? null,
                    'status' => $clearing['status'] ?? null,
                    'issue_count' => $clearing['issue_count'] ?? null,
                ]);
            return Json::ok($response, $result, $result['duplicate'] ? 200 : 201);
        } catch (\Throwable $e) {
            return $this->error($response, $e);
        }
    }

    public function process(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'bank.post', AccessLevel::WRITE, $err)) {
            return $err;
        }
        try {
            $result = $this->service->process(
                $this->currentSupplierId($request),
                (int) ($args['id'] ?? 0),
                $this->userId($request),
            );
            $this->log($request, 'gopay.clearing_reprocessed', (int) ($result['id'] ?? 0), [
                'clearing_id' => $result['clearing_id'] ?? null,
                'status' => $result['status'] ?? null,
                'issue_count' => $result['issue_count'] ?? null,
            ]);
            return Json::ok($response, $result);
        } catch (\Throwable $e) {
            return $this->error($response, $e);
        }
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin smí mazat GoPay vyúčtování.', 403);
        }
        try {
            $clearingId = (int) ($args['id'] ?? 0);
            $result = $this->service->delete(
                $this->currentSupplierId($request),
                $clearingId,
                $this->userId($request),
            );
            $this->log($request, 'gopay.clearing_deleted', $clearingId, [
                'deleted_entry_ids' => $result['deleted_entry_ids'],
                'preserved_bank_entry_id' => $result['preserved_bank_entry_id'],
            ]);
            return Json::ok($response, $result);
        } catch (\Throwable $e) {
            return $this->error($response, $e);
        }
    }

    public function download(Request $request, Response $response, array $args): Response
    {
        try {
            $file = $this->service->download($this->currentSupplierId($request), (int) ($args['id'] ?? 0));
            $safeName = preg_replace('/[\x00-\x1f"\\\\]/', '_', $file['file_name']) ?: 'GoPay-clearing.xml';
            $response->getBody()->write($file['content']);
            return $response
                ->withHeader('Content-Type', 'application/xml; charset=UTF-8')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $safeName . '"')
                ->withHeader('Content-Length', (string) strlen($file['content']))
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('Cache-Control', 'private, no-store');
        } catch (\Throwable $e) {
            return $this->error($response, $e);
        }
    }

    private function error(Response $response, \Throwable $e): Response
    {
        if ($e instanceof GoPayException) {
            return Json::error($response, 'gopay.' . $e->errorCode, $e->getMessage(), $e->httpStatus,
                $e->extra === [] ? [] : ['params' => $e->extra]);
        }
        return $this->mapPostingError($response, $e);
    }

    /** @param array<string,mixed> $payload */
    private function log(Request $request, string $action, ?int $entityId, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            $entityId === null ? null : 'gopay_clearing',
            $entityId,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}
