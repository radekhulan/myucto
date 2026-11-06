<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Reports;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\MonthlyReportSendRepository;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Document\DocumentIngestService;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Pdf\MonthlyReportPdfRenderer;
use MyInvoice\Service\Report\MonthlyReportService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Měsíční přehled klientovi (Fáze F, audit 2026-07, P3 návrh) — PDF balíček
 * NAD existujícími sestavami (výsledovka, rozvaha, saldokonto, DPH, termíny),
 * bez duplikace jejich logiky — viz MonthlyReportService.
 *
 *   GET  /api/accounting/reports/monthly-report/preview   — JSON data (náhled)
 *   GET  /api/accounting/reports/monthly-report/download  — PDF ke stažení
 *   POST /api/accounting/reports/monthly-report/send      — e-mail klientovi + archivace do DMS
 *   GET  /api/accounting/reports/monthly-report/history   — historie odeslání
 */
final class MonthlyReportAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    private const CZECH_MONTHS = [1 => 'leden', 'únor', 'březen', 'duben', 'květen', 'červen',
        'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];

    public function __construct(
        private readonly MonthlyReportService $service,
        private readonly MonthlyReportPdfRenderer $pdf,
        private readonly MonthlyReportSendRepository $sends,
        private readonly Mailer $mailer,
        private readonly DocumentStorage $storage,
        private readonly DocumentIngestService $ingest,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly LoggerInterface $log,
        private readonly Connection $db,
    ) {}

    public function preview(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        [$year, $month, $comment, $spread, $errResp] = $this->readPeriodParams($request, $response);
        if ($errResp !== null) return $errResp;

        try {
            $data = $this->service->build($supplierId, $year, $month, $comment, $spread);
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Měsíční přehled se nepodařilo sestavit: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Přehled se nepodařilo sestavit.', 500);
        }

        return Json::ok($response, $data);
    }

    public function download(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        [$year, $month, $comment, $spread, $errResp] = $this->readPeriodParams($request, $response);
        if ($errResp !== null) return $errResp;

        try {
            $data = $this->service->build($supplierId, $year, $month, $comment, $spread);
            $bytes = $this->pdf->render($data);
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Měsíční přehled se nepodařilo vygenerovat: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'PDF se nepodařilo vygenerovat.', 500);
        }

        $filename = sprintf('mesicni-prehled-%04d-%02d.pdf', $year, $month);
        $response->getBody()->write($bytes);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    public function send(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $body = (array) ($request->getParsedBody() ?? []);
        $year = (int) ($body['year'] ?? 0);
        $month = (int) ($body['month'] ?? 0);
        $comment = isset($body['comment']) ? trim((string) $body['comment']) : '';
        $to = array_values(array_filter(array_map('trim', (array) ($body['to'] ?? []))));
        $cc = array_values(array_filter(array_map('trim', (array) ($body['cc'] ?? []))));
        $subjectOverride = isset($body['subject_override']) ? trim((string) $body['subject_override']) : null;
        $spread = self::accrualSpreadFlag($body['accrual_spread'] ?? '0');
        if ($spread === null) {
            return Json::error($response, 'validation_failed', 'accrual_spread musí být 0 nebo 1.', 422);
        }

        if ($to === []) {
            return Json::error($response, 'no_recipients', 'Zadejte alespoň jednoho příjemce.', 400);
        }
        foreach ([...$to, ...$cc] as $em) {
            if (!filter_var($em, FILTER_VALIDATE_EMAIL)) {
                return Json::error($response, 'invalid_email', "Neplatný email: $em", 400);
            }
        }

        try {
            $data = $this->service->build($supplierId, $year, $month, $comment !== '' ? $comment : null, $spread);
            $pdfBytes = $this->pdf->render($data);
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Měsíční přehled se nepodařilo sestavit: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Přehled se nepodařilo sestavit.', 500);
        }

        $userId = $this->userId($request);
        $filename = sprintf('mesicni-prehled-%04d-%02d.pdf', $year, $month);
        $periodLabel = (self::CZECH_MONTHS[$month] ?? (string) $month) . ' ' . $year;

        // Dočasný soubor pro přílohu + archivaci do DMS (stejný soubor pro obojí).
        $tmpPath = $this->storage->tmpPath($supplierId);
        file_put_contents($tmpPath, $pdfBytes);

        $noteLines = [];
        if ($comment !== '') {
            foreach (preg_split('/\r\n|\r|\n/', $comment) as $line) {
                $line = trim((string) $line);
                if ($line !== '') $noteLines[] = $line;
            }
        }

        $smtpResponse = '';
        try {
            $smtpResponse = $this->mailer->sendTemplate(
                'monthly_report',
                'cs',
                $to,
                [
                    'period_label' => $periodLabel,
                    'has_vat'      => $data['vat'] !== null,
                    'note_lines'   => $noteLines,
                    'note_text'    => $comment,
                ],
                $subjectOverride,
                $cc,
                [],
                [['path' => $tmpPath, 'name' => $filename, 'contentType' => 'application/pdf']],
                $userId,
            );
        } catch (\Throwable $e) {
            @unlink($tmpPath);
            $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
            $this->logger->log('monthly_report.send_failed', $userId, 'monthly_report', null, [
                'to' => $to, 'cc' => $cc, 'year' => $year, 'month' => $month,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ], $ip, $request->getHeaderLine('User-Agent'), $supplierId);
            return Json::error($response, 'send_failed', 'Email se nepodařilo odeslat: ' . $e->getMessage(), 502);
        }

        // Archivace do Dokumentů (DMS) — best-effort, neshodí odeslání e-mailu.
        $documentId = null;
        try {
            $ingestResult = $this->ingest->ingestUploadedTemp($tmpPath, $supplierId, null, $filename, $userId);
            $documentId = $ingestResult['created_ids'][0] ?? null;
        } catch (\Throwable $e) {
            $this->log->warning('Měsíční přehled: archivace do DMS selhala: ' . $e->getMessage(), ['exception' => $e]);
            @unlink($tmpPath);
        }

        $sendId = $this->sends->insert($supplierId, $year, $month, $to, $cc, $comment !== '' ? $comment : null, $documentId, $smtpResponse, $userId);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('monthly_report.sent', $userId, 'monthly_report', $sendId, [
            'to' => $to, 'cc' => $cc, 'year' => $year, 'month' => $month,
            'document_id' => $documentId, 'smtp_response' => $smtpResponse,
        ] + ($spread ? ['accrual_spread' => true] : []), $ip, $request->getHeaderLine('User-Agent'), $supplierId);

        return Json::ok($response, [
            'sent_to' => $to, 'cc' => $cc, 'document_id' => $documentId, 'sent_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function history(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $limit = (int) ($request->getQueryParams()['limit'] ?? 30);
        return Json::ok($response, ['data' => $this->sends->history($supplierId, $limit)]);
    }

    /**
     * @return array{0:int,1:int,2:?string,3:bool,4:?Response}
     */
    private function readPeriodParams(Request $request, Response $response): array
    {
        $q = $request->getQueryParams();
        $year = (int) ($q['year'] ?? 0);
        $month = (int) ($q['month'] ?? 0);
        $comment = isset($q['comment']) && trim((string) $q['comment']) !== '' ? trim((string) $q['comment']) : null;
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            return [0, 0, null, false, Json::error($response, 'validation_failed', 'Zadejte platný rok a měsíc (1–12).', 422)];
        }
        $spread = self::accrualSpreadFlag($q['accrual_spread'] ?? '0');
        if ($spread === null) {
            return [0, 0, null, false, Json::error($response, 'validation_failed', 'accrual_spread musí být 0 nebo 1.', 422)];
        }
        return [$year, $month, $comment, $spread, null];
    }

    /** Volba `accrual_spread` z dotazu nebo těla; null = neplatná hodnota. */
    private static function accrualSpreadFlag(mixed $raw): ?bool
    {
        if (!is_scalar($raw)) {
            return null;
        }
        $value = trim((string) $raw);
        return in_array($value, ['', '0', '1'], true) ? $value === '1' : null;
    }
}
