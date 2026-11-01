<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use Mpdf\Mpdf;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\WorkReportRepository;
use MyInvoice\Service\Signing\Pdf\PdfSigningService;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Renderuje samostatný PDF jen výkazu víceprací (Vykaz-XYZ.pdf).
 * Použito jako příloha emailu žádosti o schválení.
 *
 * Nesdílí cache s InvoicePdfRenderer — vždy regeneruje (výkaz se může měnit
 * mezi requesty na schválení).
 */
final class WorkReportPdfRenderer
{
    use SignsPdf;

    private ?Environment $twig = null;

    /** Aktuální locale pro Twig funkci `t()` — přenastavuje render(). */
    private string $locale = 'cs';

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly WorkReportRepository $workReports,
        private readonly Connection $db,
        private readonly PdfSigningService $pdfSigning,
    ) {}

    /**
     * Vyrendrované PDF výkazu do souboru a vrátí cestu.
     * Throw RuntimeException pokud faktura/výkaz neexistuje.
     */
    public function render(int $invoiceId, ?int $userId = null): string
    {
        $invoice = $this->invoices->find($invoiceId);
        if ($invoice === null) {
            throw new \RuntimeException("Faktura #{$invoiceId} nenalezena");
        }
        $workReport = $this->workReports->findByInvoice($invoiceId, (int) $invoice['supplier_id']);
        if ($workReport === null) {
            throw new \RuntimeException("Výkaz pro fakturu #{$invoiceId} neexistuje");
        }
        $workReport = TimeBillingPdfPresenter::workReport($workReport);

        $supplier = $this->resolveSupplier($invoice);
        // Logo + branding sdílené s fakturou (3 varianty hlavičky + accent barvy).
        $logoPath = PdfBranding::logoPath($supplier, (int) ($invoice['supplier_id'] ?? 0));

        $cssPath = Bootstrap::rootDir() . '/styles/invoice.css';
        $css = is_file($cssPath) ? (string) file_get_contents($cssPath) : '';
        // Akcent, nebo úsporná černobílá varianta, stejně jako faktura (PdfBranding::themeCss).
        $css .= PdfBranding::themeCss($supplier);

        $locale = (string) ($invoice['language'] ?? 'cs');
        $this->locale = $locale;
        $twig = $this->twig();

        $body = $twig->render('work_report.twig', [
            'invoice'        => $invoice,
            'supplier'       => $supplier,
            'work_report'    => $workReport,
            'locale'         => $locale,
            'date_format'    => $locale === 'en' ? 'M j, Y' : 'j. n. Y',
            'decimal_sep'    => $locale === 'en' ? '.' : ',',
            // NBSP (U+00A0) oddělovač tisíců — drží číslo na jednom řádku v mPDF
            // (white-space:nowrap mPDF v úzkých buňkách nedodrží). Sjednoceno s fakturou.
            'thousand_sep'   => $locale === 'en' ? ',' : "\u{00A0}",
            'css'            => '',
            'logo_path'      => $logoPath,
            'logo_show_name' => $logoPath !== null && !empty($supplier['pdf_logo_show_name']),
        ]);

        $rootDir = Bootstrap::rootDir();
        $tmpDir = \MyInvoice\Infrastructure\Config\RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }

        $mpdf = new Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'margin_top'    => 15,
            'margin_bottom' => 18,
            'margin_left'   => 15,
            'margin_right'  => 15,
            'tempDir'       => $tmpDir,
            'autoPageBreak' => true,
            ...MpdfFontConfig::options(),
        ]);
        $mpdf->SetTitle('');
        $mpdf->SetAuthor('');
        $mpdf->SetCreator('MyÚčto.cz');

        if ($css !== '') {
            $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
        }
        $mpdf->WriteHTML($body, \Mpdf\HTMLParserMode::HTML_BODY);

        $supplierId = (int) ($invoice['supplier_id'] ?? 1);
        $issueDate = new \DateTimeImmutable($invoice['issue_date']);
        $dir = \MyInvoice\Infrastructure\Config\RuntimePaths::storage('work-reports') . '/sup-' . $supplierId . '/' . $issueDate->format('Y-m');
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        $vs = $invoice['varsymbol'] ?: ('draft-' . $invoice['id']);
        // Sanitize filesystem-bezpečně (security report @andrejtomci #3 DiD)
        $vs = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $vs);
        $path = "$dir/Vykaz-$vs.pdf";

        $tmpPath = $path . '.new';
        $mpdf->Output($tmpPath, \Mpdf\Output\Destination::FILE);

        // Podpis PDF (PAdES) — má-li dodavatel zapnuto; měkký fallback při chybě.
        $tmpPath = $this->signPdfIfEnabled(
            $tmpPath, $this->resolveSupplier($invoice), $this->pdfSigning,
            'work_report', (int) $invoice['id'], $userId,
        );

        if (is_file($path)) @unlink($path);
        if (!@rename($tmpPath, $path)) {
            $path = $tmpPath;
        }
        return $path;
    }

    private function twig(): Environment
    {
        if ($this->twig !== null) {
            return $this->twig;
        }

        $loader = new FilesystemLoader(dirname(__DIR__, 3) . '/templates/invoice');
        $this->twig = new Environment($loader, [
            'autoescape' => 'html',
            'strict_variables' => false,
        ] + TwigCache::options('invoice'));

        // Locale nesmí být zachycené v closure — viz InvoicePdfRenderer::twig().
        $this->twig->addFunction(new \Twig\TwigFunction('t', function (string $cs, string $en) {
            return $this->locale === 'en' ? $en : $cs;
        }));

        return $this->twig;
    }

    private function resolveSupplier(array $invoice): array
    {
        $sid = (int) ($invoice['supplier_id'] ?? 0);
        $live = [];
        if ($sid > 0) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT s.*, co.iso2 AS country_iso2, co.name_cs AS country_name_cs, co.name_en AS country_name_en
                   FROM supplier s LEFT JOIN countries co ON co.id = s.country_id WHERE s.id = ?'
            );
            $stmt->execute([$sid]);
            $live = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        }
        if (!empty($invoice['supplier_snapshot'])) {
            $snap = is_string($invoice['supplier_snapshot'])
                ? json_decode($invoice['supplier_snapshot'], true)
                : $invoice['supplier_snapshot'];
            if (is_array($snap)) {
                // Snapshot je primární (historie), live data fallback na chybějící klíče.
                return array_merge($live, $snap);
            }
        }
        return $live;
    }

}
