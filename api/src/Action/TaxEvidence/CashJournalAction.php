<?php

declare(strict_types=1);

namespace MyInvoice\Action\TaxEvidence;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CashJournalNoteRepository;
use MyInvoice\Service\Accounting\Reports\ReportXlsxExporter;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\TaxEvidenceCashJournalPdfRenderer;
use MyInvoice\Service\TaxEvidence\CashJournalService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Peněžní deník daňové evidence (Epic DE, A3). READ-ONLY.
 *
 *   GET /api/tax-evidence/cash-journal        — deník + totály + checks{} + warnings[]
 *   GET /api/tax-evidence/cash-journal/export — PDF / XLSX (?format=pdf|xlsx)
 *
 * Params: `year` (celý rok) NEBO `from`+`to` (YYYY-MM-DD). Bez parametrů = aktuální rok.
 */
final class CashJournalAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly CashJournalService $service,
        private readonly TaxEvidenceCashJournalPdfRenderer $pdf,
        private readonly ReportXlsxExporter $xlsx,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly LoggerInterface $log,
        private readonly Connection $db,
        private readonly CashJournalNoteRepository $notes,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        $params = $this->validateParams($request, $response, $err);
        if ($params === null) return $err;
        if (!$this->requireTaxEvidenceForYear($this->db, $supplierId, $params['opts']['year'], $response, $err)) return $err;

        try {
            $data = $this->service->build($supplierId, $params['from'], $params['to'], $params['opts']);
        } catch (\Throwable $e) {
            $this->log->error('Peněžní deník se nepodařilo sestavit: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Deník se nepodařilo vytvořit.', 500);
        }

        // Poznámky a počty příloh pohybů (jen pro obrazovku, export je nenese).
        $notes = $this->notes->briefForSupplier($supplierId);
        $attachments = $this->notes->attachmentCounts($supplierId);
        foreach ($data['rows'] as &$row) {
            $key = $row['source_type'] . ':' . $row['source_id'];
            $row['notes'] = $notes[$key] ?? [];
            $row['attachment_entity'] = CashJournalNoteRepository::ATTACHMENT_ENTITY[$row['source_type']] ?? null;
            $row['attachment_count'] = $attachments[$key] ?? 0;
        }
        unset($row);

        return Json::ok($response, $data);
    }

    public function export(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        $params = $this->validateParams($request, $response, $err);
        if ($params === null) return $err;
        if (!$this->requireTaxEvidenceForYear($this->db, $supplierId, $params['opts']['year'], $response, $err)) return $err;

        $format = strtolower(trim((string) ($request->getQueryParams()['format'] ?? '')));
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            return Json::error($response, 'validation_failed', "format musí být 'pdf' nebo 'xlsx'.", 422);
        }

        try {
            $data = $this->service->build($supplierId, $params['from'], $params['to'], $params['opts']);
            $out = $format === 'pdf'
                ? [
                    'bytes'    => $this->pdf->render($data),
                    'filename' => sprintf('penezni-denik-%s.pdf', (string) ($data['year'] ?? '')),
                    'mime'     => 'application/pdf',
                ]
                : $this->xlsx->cashJournal($data);
        } catch (\Throwable $e) {
            $this->log->error('Peněžní deník se nepodařilo sestavit: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Deník se nepodařilo vytvořit.', 500);
        }

        $this->logger->log('report.tax_evidence_export', $this->userId($request), 'report', null,
            ['report' => 'cash_journal', 'format' => $format],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $supplierId);

        $response->getBody()->write($out['bytes']);
        return $response
            ->withHeader('Content-Type', $out['mime'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $out['filename'] . '"')
            ->withHeader('Content-Length', (string) strlen($out['bytes']))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * @return array{from:string, to:string, opts:array{year:int}}|null
     */
    private function validateParams(Request $request, Response $response, ?Response &$err): ?array
    {
        $q = $request->getQueryParams();

        $from = trim((string) ($q['from'] ?? ''));
        $to   = trim((string) ($q['to'] ?? ''));

        if ($from !== '' || $to !== '') {
            foreach (['from' => $from, 'to' => $to] as $key => $v) {
                if (!$this->isDate($v)) {
                    $err = Json::error($response, 'validation_failed', "{$key} musí být datum (YYYY-MM-DD).", 422);
                    return null;
                }
            }
            if ($from > $to) {
                $err = Json::error($response, 'validation_failed', 'from nesmí být větší než to.', 422);
                return null;
            }
            $err = null;
            return ['from' => $from, 'to' => $to, 'opts' => ['year' => (int) substr($from, 0, 4)]];
        }

        $year = (int) ($q['year'] ?? date('Y'));
        if ($year < 2000 || $year > 2100) {
            $err = Json::error($response, 'validation_failed', 'year musí být rok mezi 2000 a 2100.', 422);
            return null;
        }
        $err = null;
        return [
            'from' => sprintf('%04d-01-01', $year),
            'to'   => sprintf('%04d-12-31', $year),
            'opts' => ['year' => $year],
        ];
    }

    private function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
