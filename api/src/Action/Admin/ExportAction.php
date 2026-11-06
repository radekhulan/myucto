<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\DemoReadOnlyMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Export\CsvWriter;
use MyInvoice\Service\Export\ExportFilename;
use MyInvoice\Service\Export\ExportPeriod;
use MyInvoice\Service\Export\ExportPeriodResolver;
use MyInvoice\Service\Export\IsdocExporter;
use MyInvoice\Service\Export\MergedInvoicePdfExporter;
use MyInvoice\Service\Export\MoneyS3XmlExporter;
use MyInvoice\Service\Export\PohodaXmlExporter;
use MyInvoice\Service\Export\StereoXmlExporter;
use MyInvoice\Service\Signing\Pdf\PdfSigningService;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;
use ZipArchive;

/**
 * Generický export faktur za měsíc nebo čtvrtletí do různých formátů:
 *
 *   GET /api/admin/export?format=pdf-zip|isdoc|pohoda|stereo|money_s3|csv&month=YYYY-MM[&type=invoice][&date_by=issue|tax]
 *   GET /api/admin/export?format=pdf-zip|isdoc|pohoda|stereo|money_s3|csv&period=quarterly&year=YYYY&quarter=1..4
 *
 * Sdílený filter: period + type + date_by + supplier_id (z X-Supplier-Id middleware).
 * Per-format: výstup MIME a filename.
 *
 * Přístup: admin nebo accountant.
 */
final class ExportAction
{
    private const MAX_MERGED_INVOICES = 200;

    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceRepository $repo,
        private readonly InvoicePdfRenderer $pdf,
        private readonly IsdocExporter $isdoc,
        private readonly PohodaXmlExporter $pohoda,
        private readonly StereoXmlExporter $stereo,
        private readonly MergedInvoicePdfExporter $mergedPdf,
        private readonly MoneyS3XmlExporter $moneyS3,
        private readonly ExportPeriodResolver $periodResolver,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly PdfSigningService $pdfSigning,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        // readonly smí exportovat data (čtení), jen nesmí nic měnit
        if (!RequestAuthorization::allows($request, 'utilities.export', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }

        $q = $request->getQueryParams();
        $format = (string) ($q['format'] ?? 'pdf-zip');
        try {
            $period = $this->periodResolver->resolve($q);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
        $dateBy = (string) ($q['date_by'] ?? 'issue');
        $type   = (string) ($q['type'] ?? '');
        $sid    = SupplierGuard::currentId($request);
        $mergePdf = filter_var($q['merge_pdf'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $signPdf = filter_var($q['sign_pdf'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (($mergePdf || $signPdf) && $format !== 'pdf-zip') {
            return Json::error($response, 'validation_failed', 'Volby merge_pdf a sign_pdf lze použít jen pro PDF export.', 400);
        }
        if ($signPdf && !$mergePdf) {
            return Json::error($response, 'validation_failed', 'Podepsat lze pouze sloučený PDF export.', 400);
        }

        // Najdi faktury za období + supplier scope.
        try {
            $ids = $this->findInvoiceIds($sid, $period, $dateBy, $type);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
        if (empty($ids)) {
            return Json::error($response, 'no_invoices', "Za období {$period->label} nejsou žádné vystavené faktury.", 404);
        }

        try {
            $userId = isset($user['id']) ? (int) $user['id'] : null;
            [$filename, $content, $mime] = match ($format) {
                'pdf-zip' => $mergePdf
                    ? $this->buildMergedPdf($ids, $sid, $period, $type, $userId, $signPdf, !DemoReadOnlyMiddleware::enabled($request))
                    : $this->buildPdfZip($ids, $period, $type, $userId),
                'isdoc'   => $this->buildIsdoc($ids, $period, $userId),
                'pohoda'  => $this->buildPohoda($ids, $sid, $period),
                'stereo'  => $this->buildStereo($ids, $period),
                'csv'     => $this->buildCsv($sid, $period, $dateBy, $type),
                'money_s3' => $this->buildMoneyS3($ids, $period),
                default   => throw new \InvalidArgumentException("Neznámý formát: $format"),
            };
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        } catch (\LengthException $e) {
            return Json::error($response, 'too_many', $e->getMessage(), 422);
        } catch (\DomainException $e) {
            return Json::error($response, 'signature_unavailable', $e->getMessage(), 422);
        } catch (\Throwable $e) {
            return Json::internalError($response, 'export_failed', $e->getMessage(), 500);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoices.exported', $user['id'] ?? null, null, null, [
            'format' => $format,
            'period' => $period->label,
            'period_type' => $period->type,
            'month' => $period->month,
            'quarter' => $period->quarter,
            'date_from' => $period->dateFrom,
            'date_to_exclusive' => $period->dateToExclusive,
            'type' => $type ?: null,
            'count' => count($ids),
            'merge_pdf' => $mergePdf,
            'signed_pdf' => $signPdf,
        ], $ip, $request->getHeaderLine('User-Agent'));

        // Stream content out
        $response->getBody()->write($content);
        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Disposition', self::attachmentDisposition($filename))
            ->withHeader('Content-Length', (string) strlen($content));
    }

    /** Content-Disposition podle RFC 6266: ASCII `filename` jako záloha a přesný název ve `filename*` (UTF-8). */
    private static function attachmentDisposition(string $filename): string
    {
        $ascii = ExportFilename::sanitize($filename, 'export');
        return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
    }

    /** @return int[] */
    private function findInvoiceIds(int $sid, ExportPeriod $period, string $dateBy, string $type): array
    {
        if (!in_array($dateBy, ['issue', 'tax'], true)) {
            throw new \InvalidArgumentException('Parametr date_by musí být issue nebo tax.');
        }
        $dateExpr = $dateBy === 'tax' ? 'COALESCE(tax_date, issue_date)' : 'issue_date';
        $params = [$sid, $period->dateFrom, $period->dateToExclusive];
        $typeFilter = '';
        if ($type !== '' && in_array($type, ['invoice', 'proforma', 'credit_note', 'cancellation'], true)) {
            $typeFilter = ' AND invoice_type = ?';
            $params[] = $type;
        }
        $sql = "SELECT id FROM invoices
                 WHERE supplier_id = ?
                   AND $dateExpr >= ?
                   AND $dateExpr < ?
                   AND status IN ('issued','sent','reminded','paid')
                   $typeFilter
              ORDER BY $dateExpr, id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param int[] $ids
     * @return array{0:string,1:string,2:string} [filename, content, mime]
     */
    private function buildPdfZip(array $ids, ExportPeriod $period, string $type, ?int $userId): array
    {
        $tmpZip = tempnam(sys_get_temp_dir(), 'inv-zip-') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Nelze vytvořit ZIP.');
        }
        foreach ($ids as $id) {
            try {
                $path = $this->pdf->render($id, false, $userId);
                if (!is_file($path)) continue;
                $inv = $this->repo->find($id);
                $typeLabel = match ($inv['invoice_type'] ?? 'invoice') {
                    'proforma'     => 'Proforma',
                    'credit_note'  => 'Dobropis',
                    'cancellation' => 'Storno',
                    default        => 'Faktura',
                };
                $vs = $inv['varsymbol'] ?? ('draft-' . $id);
                // Sanitize ZIP entry name — defense-in-depth proti zip-slip přes
                // importovaný varsymbol (security report @andrejtomci #3 DiD).
                $vs = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $vs);
                $zip->addFile($path, "$typeLabel-$vs.pdf");
            } catch (\Throwable) { /* skip failing ones */ }
        }
        $zip->close();

        $content = (string) file_get_contents($tmpZip);
        @unlink($tmpZip);
        $base = "myucto-{$period->label}" . ($type ? "-$type" : '');
        return ["$base.zip", $content, 'application/zip'];
    }

    /**
     * @param int[] $ids
     * @return array{0:string,1:string,2:string}
     */
    private function buildMergedPdf(
        array $ids,
        int $supplierId,
        ExportPeriod $period,
        string $type,
        ?int $userId,
        bool $sign,
        bool $persistRates,
    ): array {
        if (count($ids) > self::MAX_MERGED_INVOICES) {
            throw new \LengthException(sprintf(
                'Do jednoho PDF lze sloučit nejvýše %d faktur, období jich obsahuje %d. Zvol kratší období nebo použij ZIP export.',
                self::MAX_MERGED_INVOICES,
                count($ids),
            ));
        }

        $stmt = $this->db->pdo()->prepare('SELECT * FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($supplier === false) {
            throw new \RuntimeException('Dodavatel nebyl nalezen.');
        }

        $result = $this->mergedPdf->export($ids, $supplier, $userId, $sign, $persistRates);
        try {
            $content = file_get_contents($result['path']);
            if ($content === false) {
                throw new \RuntimeException('Sloučené PDF nelze načíst.');
            }
        } finally {
            @unlink($result['path']);
        }

        $base = "myucto-{$period->label}" . ($type ? "-$type" : '');
        return ["$base.pdf", $content, 'application/pdf'];
    }

    /**
     * @param int[] $ids
     * @return array{0:string,1:string,2:string}
     */
    private function buildIsdoc(array $ids, ExportPeriod $period, ?int $userId): array
    {
        $r = $this->isdoc->export(
            $ids,
            $period->label,
            fn (string $xml, array $invoice): string => $this->pdfSigning->signIsdocIfEnabled(
                $xml,
                ['id' => (int) ($invoice['supplier_id'] ?? 0)],
                (int) $invoice['id'],
                $userId,
            ),
        );
        return [$r['filename'], $r['content'], $r['mime']];
    }

    /**
     * @param int[] $ids
     * @return array{0:string,1:string,2:string}
     */
    private function buildPohoda(array $ids, int $sid, ExportPeriod $period): array
    {
        $r = $this->pohoda->export($ids, $sid, $period->label);
        return [$r['filename'], $r['content'], $r['mime']];
    }

    /**
     * @param int[] $ids
     * @return array{0:string,1:string,2:string}
     */
    private function buildStereo(array $ids, ExportPeriod $period): array
    {
        $r = $this->stereo->export($ids, $period->label);
        return [$r['filename'], $r['content'], $r['mime']];
    }

    /**
     * CSV přehled vydaných faktur za období (UTF-8 BOM, `;`) — jeden soubor pro
     * účetní/Excel. Stejný výběr dokladů jako ostatní formáty (findInvoiceIds),
     * jen s plnými sloupci pro tabulku.
     *
     * @return array{0:string,1:string,2:string}
     */
    private function buildCsv(int $sid, ExportPeriod $period, string $dateBy, string $type): array
    {
        $dateExpr = $dateBy === 'tax' ? 'COALESCE(i.tax_date, i.issue_date)' : 'i.issue_date';
        $params = [$sid, $period->dateFrom, $period->dateToExclusive];
        $typeFilter = '';
        if ($type !== '' && in_array($type, ['invoice', 'proforma', 'credit_note', 'cancellation'], true)) {
            $typeFilter = ' AND i.invoice_type = ?';
            $params[] = $type;
        }
        $sql = "SELECT i.varsymbol, i.invoice_type,
                       c.company_name AS client_company_name,
                       p.name AS project_name,
                       i.issue_date, i.tax_date, i.due_date,
                       cur.code AS currency,
                       i.total_without_vat, i.total_vat, i.total_with_vat, i.amount_to_pay,
                       i.status, i.paid_at
                  FROM invoices i
                  JOIN clients c ON c.id = i.client_id
             LEFT JOIN projects p ON p.id = i.project_id
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND $dateExpr >= ?
                   AND $dateExpr < ?
                   AND i.status IN ('issued','sent','reminded','paid')
                   $typeFilter
              ORDER BY $dateExpr, i.id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $header = [
            'VS', 'Typ', 'Klient', 'Zakázka', 'Vystaveno', 'DUZP', 'Splatnost',
            'Měna', 'Bez DPH', 'DPH', 'Celkem', 'K úhradě', 'Stav', 'Zaplaceno',
        ];
        $csvRows = [];
        foreach ($rows as $r) {
            $csvRows[] = [
                CsvWriter::safe($r['varsymbol'] ?? ''),
                CsvWriter::safe($r['invoice_type'] ?? ''),
                CsvWriter::safe($r['client_company_name'] ?? ''),
                CsvWriter::safe($r['project_name'] ?? ''),
                $r['issue_date'] ?? '',
                $r['tax_date'] ?? '',
                $r['due_date'] ?? '',
                $r['currency'] ?? '',
                number_format((float) ($r['total_without_vat'] ?? 0), 2, '.', ''),
                number_format((float) ($r['total_vat'] ?? 0), 2, '.', ''),
                number_format((float) ($r['total_with_vat'] ?? 0), 2, '.', ''),
                number_format((float) ($r['amount_to_pay'] ?? 0), 2, '.', ''),
                $r['status'] ?? '',
                $r['paid_at'] ?? '',
            ];
        }

        return ["myucto-{$period->label}.csv", CsvWriter::build($header, $csvRows), 'text/csv; charset=utf-8'];
    }

    /**
     * @param int[] $ids
     * @return array{0:string,1:string,2:string}
     */
    private function buildMoneyS3(array $ids, ExportPeriod $period): array
    {
        $r = $this->moneyS3->export($ids, $period->label);
        return [$r['filename'], $r['content'], $r['mime']];
    }
}
