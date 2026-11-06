<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use Mpdf\Mpdf;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Měsíční přehled klientovi — JEDEN PDF dokument sestavený z existujících sestav
 * (výsledovka měsíc+YTD, rozvaha, saldokonto top po splatnosti, DPH, termíny).
 *
 * Na rozdíl od faktur/plateb tu nejde o sloučení už vyrenderovaných cizích PDF
 * (na to by byl potřeba setasign/fpdi, který stack nemá) — sekce se skládají jako
 * fragmenty JEDNÉ Twig šablony oddělené mPDF `<pagebreak>` tagem a celé se
 * vyrenderují jedním `WriteHTML()` voláním. Jednodušší, žádná nová závislost,
 * a house style (fonty/CSS) je tak jednotný napříč sekcemi.
 */
final class MonthlyReportPdfRenderer
{
    private ?Environment $twig = null;

    /**
     * @param array<string,mixed> $data MonthlyReportService::build() výstup
     */
    public function render(array $data): string
    {
        $body = $this->twig()->render(($data['accounting_framework'] ?? '') === 'cz_504' ? 'monthly-report-504.twig' : 'monthly-report.twig', $data);

        $tmpDir = RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }

        $mpdf = new Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'orientation'   => 'P',
            'margin_left'   => 14,
            'margin_right'  => 14,
            'margin_top'    => 14,
            'margin_bottom' => 14,
            'tempDir'       => $tmpDir,
            'autoPageBreak' => true,
            ...MpdfFontConfig::options(),
        ]);
        $period = (array) ($data['period'] ?? []);
        $mpdf->SetTitle('Měsíční přehled ' . (string) ($period['year'] ?? '') . '-' . (string) ($period['month'] ?? ''));
        $mpdf->SetCreator('MyÚčto.cz');
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }

    private function twig(): Environment
    {
        if ($this->twig === null) {
            $loader = new FilesystemLoader([
                Bootstrap::rootDir() . '/api/templates/monthly-report',
                Bootstrap::rootDir() . '/api/templates/report',
            ]);
            $this->twig = new Environment($loader, [
                'autoescape'       => 'html',
                'strict_variables' => false,
            ] + TwigCache::options('monthly-report'));
            $this->twig->addFilter(new \Twig\TwigFilter('cz_money', static function ($v) {
                return number_format((float) $v, 2, ',', ' ');
            }));
            $this->twig->addFilter(new \Twig\TwigFilter('cz_date', static function ($v) {
                if (!$v) {
                    return '';
                }
                try {
                    return (new \DateTimeImmutable((string) $v))->format('d.m.Y');
                } catch (\Throwable) {
                    return '';
                }
            }));
            $this->twig->addFilter(new \Twig\TwigFilter('cz_month', static function ($m) {
                $names = [1 => 'leden', 'únor', 'březen', 'duben', 'květen', 'červen',
                    'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];
                return $names[(int) $m] ?? (string) $m;
            }));
        }
        return $this->twig;
    }
}
