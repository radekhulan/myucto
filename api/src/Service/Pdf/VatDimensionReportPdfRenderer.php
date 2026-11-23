<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use Mpdf\Mpdf;
use MyInvoice\Bootstrap;
use MyInvoice\Service\Report\VatDimensionReportXlsxExporter;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * PDF sestavy DPH podle dimenze — landscape A4, hlavička s firmou, obdobím a typem
 * dimenze, tabulka po hodnotách s kontrolními řádky a kontrola obratů účtu 343.
 */
final class VatDimensionReportPdfRenderer
{
    private ?Environment $twig = null;

    /** @param array<string,mixed> $report výstup VatDimensionReportService::build() */
    public function render(array $report): string
    {
        $body = $this->twig()->render('vat_by_dimension.twig', $report + [
            'columns' => VatDimensionReportXlsxExporter::COLUMNS,
        ]);
        $tmpDir = \MyInvoice\Infrastructure\Config\RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }
        $mpdf = new Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4-L',
            'orientation'   => 'L',
            'margin_left'   => 8,
            'margin_right'  => 8,
            'margin_top'    => 12,
            'margin_bottom' => 12,
            'tempDir'       => $tmpDir,
            'autoPageBreak' => true,
            ...MpdfFontConfig::options(),
        ]);
        $mpdf->SetTitle('DPH podle dimenze ' . (string) ($report['period']['label'] ?? ''));
        $mpdf->SetCreator('MyÚčto.cz');
        $mpdf->setFooter('{PAGENO} / {nbpg}');
        ChunkedHtmlWriter::write($mpdf, $body);
        return $mpdf->Output('', 'S');
    }

    private function twig(): Environment
    {
        if ($this->twig === null) {
            $loader = new FilesystemLoader([Bootstrap::rootDir() . '/api/templates/report']);
            $this->twig = new Environment($loader, [
                'autoescape' => 'html',
                'strict_variables' => false,
            ] + TwigCache::options('report'));
            $this->twig->addFilter(new \Twig\TwigFilter('cz_money', static function ($v) {
                return $v === null ? '' : number_format((float) $v, 2, ',', ' ');
            }));
            $this->twig->addFilter(new \Twig\TwigFilter('cz_date', static function ($v) {
                if (!$v) return '';
                try {
                    return (new \DateTimeImmutable((string) $v))->format('d.m.Y');
                } catch (\Throwable) {
                    return '';
                }
            }));
            $this->twig->addFilter(new \Twig\TwigFilter('cz_datetime', static function ($v) {
                if (!$v) return '';
                try {
                    return (new \DateTimeImmutable((string) $v))->format('d.m.Y H:i');
                } catch (\Throwable) {
                    return '';
                }
            }));
        }
        return $this->twig;
    }
}
