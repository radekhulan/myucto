<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use Mpdf\Mpdf;
use MyInvoice\Bootstrap;
use MyInvoice\Service\Report\KhEvidenceService;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * PDF soupisu dokladů oddílu KH (issue #142) — landscape A4, hlavička s firmou,
 * obdobím, filtrem, zdrojem a okamžikem sestavení.
 */
final class KhEvidencePdfRenderer
{
    private ?Environment $twig = null;

    /** @param array<string,mixed> $report výstup KhEvidenceService */
    public function render(array $report): string
    {
        $body = $this->twig()->render('kh_evidence.twig', $report + [
            'section_labels' => KhEvidenceService::SECTION_LABELS,
            'status_labels'  => KhEvidenceService::STATUS_LABELS,
            'source_label'   => KhEvidenceService::sourceLabel($report),
            'filter_label'   => KhEvidenceService::filterLabel((string) $report['section']),
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
        $mpdf->SetTitle('Soupis dokladů KH ' . (string) ($report['period']['label'] ?? ''));
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
