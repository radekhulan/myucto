<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eet;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Eet\EetCommandException;
use MyInvoice\Service\Eet\EetCorrectionService;
use MyInvoice\Service\Eet\EetDispatchService;
use MyInvoice\Service\Eet\EetQueueService;
use MyInvoice\Service\Eet\EetRecordQuery;
use MyInvoice\Service\Eet\EetSalesSummaryService;
use MyInvoice\Service\Eet\PaymentEventService;
use MyInvoice\Service\Export\CsvWriter;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/**
 * Evidence EET firmy.
 *
 *   GET  /api/eet/records                                seznam (keyset ?cursor=, filtry, limit ≤ 100)
 *   GET  /api/eet/records/{id}                           detail + pokusy (XML jen s eet.correct v relaci)
 *   POST /api/eet/records/{id}/retry                     okamžité opakování
 *   POST /api/eet/records/{id}/corrections/preview       náhled opravy
 *   POST /api/eet/records/{id}/corrections               záporná oprava (Idempotency-Key)
 *   GET  /api/eet/queue                                  souhrn fronty
 *   GET  /api/eet/summary                                souhrn tržeb dne a měsíce (Domů v mobilu)
 *   GET  /api/eet/export                                 CSV po dávkách (filtr období)
 *   GET  /api/invoices/{id}/eet                          EET souhrn faktury
 */
final class EetRecordAction
{
    use RespondsWithEetErrors;

    public function __construct(
        private readonly EetRecordQuery $query,
        private readonly EetDispatchService $dispatch,
        private readonly EetCorrectionService $corrections,
        private readonly EetQueueService $queue,
        private readonly EetSalesSummaryService $sales,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        return $this->respond($response, fn (): array => $this->query->list(
            $supplierId,
            $q,
            isset($q['cursor']) ? (string) $q['cursor'] : null,
            isset($q['limit']) ? (int) $q['limit'] : 50,
        ));
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $detail = $this->query->detail($supplierId, (int) ($args['id'] ?? 0), RequestAuthorization::canViewEetAttemptXml($request));
        if ($detail === null) {
            return Json::error($response, 'not_found', 'Záznam EET nenalezen.', 404);
        }
        return Json::ok($response, $detail);
    }

    public function retry(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $recordId = (int) ($args['id'] ?? 0);
        return $this->respond($response, function () use ($request, $supplierId, $recordId): array {
            if ($this->query->find($supplierId, $recordId) === null) {
                throw EetCommandException::notFound('Záznam EET nenalezen.');
            }
            $result = $this->dispatch->retryNow($supplierId, $recordId);
            $this->logger->log('eet.record_retry', self::userId($request) ?: null, 'eet_record', $recordId,
                ['status' => $result['status']], $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                $request->getHeaderLine('User-Agent'), $supplierId);
            return $result;
        });
    }

    public function correctionPreview(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        return $this->respond($response, fn (): array => $this->corrections->preview($supplierId, (int) ($args['id'] ?? 0), self::body($request)));
    }

    public function correction(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $userId = self::userId($request);
        $recordId = (int) ($args['id'] ?? 0);
        return $this->respond($response, function () use ($request, $supplierId, $userId, $recordId): array {
            $key = PaymentEventService::validateKey($request->getHeaderLine('Idempotency-Key'));
            $result = $this->corrections->create($supplierId, $userId, $recordId, $key, self::body($request));
            if (!$result['idempotent_replay']) {
                $this->logger->log('eet.correction_created', $userId ?: null, 'eet_record', $recordId, [
                    'correction_record_id' => $result['correction']['record_id'] ?? null,
                    'amount' => $result['amount'] ?? null,
                    'reason' => $result['correction']['correction_reason'] ?? null,
                ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'), $supplierId);
            }
            return $result;
        }, 201);
    }

    public function queue(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->queue->summary(SupplierGuard::currentId($request)));
    }

    public function summary(Request $request, Response $response): Response
    {
        $date = $request->getQueryParams()['date'] ?? null;
        return $this->respond($response, fn (): array => $this->sales->summary(SupplierGuard::currentId($request), is_string($date) ? $date : null));
    }

    public function invoice(Request $request, Response $response, array $args): Response
    {
        // Route vyžaduje `eet`; souhrn ale vypovídá o faktuře, takže i čtení faktur.
        if (($denied = EetActionSupport::deny($request, $response, 'invoices', AccessLevel::READ)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $invoiceId = (int) ($args['id'] ?? 0);
        $out = ['items' => $this->query->forInvoice($supplierId, $invoiceId)];
        // Dobropis: kolik z evidovaných tržeb opravované faktury zbývá opravit, aby UI
        // u vrácení peněz ukázalo, co se zaeviduje jako záporná tržba.
        $parent = $this->query->creditNoteParent($supplierId, $invoiceId);
        if ($parent !== null) {
            $out['refund'] = $parent + ['evidenced' => $this->corrections->refundableForInvoice($supplierId, $parent['invoice_id'])];
        }
        return Json::ok($response, $out);
    }

    /**
     * CSV exportu po dávkách do dočasného proudu (nad 2 MB na disku), bez XML a tajemství.
     */
    public function export(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $fp = fopen('php://temp/maxmemory:2097152', 'w+');
        if ($fp === false) {
            return Json::error($response, 'export_failed', 'Export se nepodařilo připravit.', 500);
        }
        try {
            fwrite($fp, "\xEF\xBB\xBF");
            fputcsv($fp, ['ID', 'Druh', 'Opravuje ID', 'Důvod opravy', 'Prostředí', 'EIČ', 'Provozovna', 'Pokladní zařízení',
                'Pořadové číslo', 'Čas tržby', 'Celková tržba', 'Určeno k čerpání', 'Čerpání', 'Stav', 'POK', 'Potvrzeno',
                'Po lhůtě', 'Lhůta', 'EIČ pověřujícího', 'Pověření více poplatníky'], ';', '"', '');
            foreach ($this->query->exportRows($supplierId, $q) as $row) {
                fputcsv($fp, [
                    (int) $row['id'],
                    (string) $row['kind'],
                    $row['corrects_record_id'] !== null ? (int) $row['corrects_record_id'] : '',
                    (string) ($row['correction_reason'] ?? ''),
                    (string) $row['environment'],
                    CsvWriter::safe($row['eic']),
                    $row['unit_number'] !== null ? (int) $row['unit_number'] : '',
                    CsvWriter::safe($row['device_code'] ?? ''),
                    CsvWriter::safe($row['sequence_number'] ?? ''),
                    (new \DateTimeImmutable((string) $row['sale_at'] . (string) $row['sale_at_offset']))->format(DATE_ATOM),
                    str_replace('.', ',', (string) $row['total_amount']),
                    str_replace('.', ',', (string) ($row['credit_topup_amount'] ?? '')),
                    str_replace('.', ',', (string) ($row['credit_draw_amount'] ?? '')),
                    // `external` = tržbu evidoval jiný systém (e-shop), MyÚčto ji neodesílalo.
                    (string) $row['status'],
                    CsvWriter::safe($row['pok'] ?? ''),
                    (string) ($row['confirmed_at'] ?? ''),
                    (int) $row['overdue'] === 1 ? 'ano' : 'ne',
                    (string) $row['deadline_at'],
                    CsvWriter::safe($row['delegating_eic'] ?? ''),
                    (int) ($row['multiple_delegators'] ?? 0) === 1 ? 'ano' : 'ne',
                ], ';', '"', '');
            }
        } catch (EetCommandException $e) {
            fclose($fp);
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->extra);
        }
        rewind($fp);
        $label = preg_replace('/[^0-9-]/', '', (string) ($q['from'] ?? '')) ?: 'vse';
        return $response
            ->withBody(new Stream($fp))
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="eet-' . $label . '.csv"')
            ->withHeader('Cache-Control', 'no-store');
    }
}
