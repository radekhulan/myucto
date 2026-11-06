<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use Mpdf\Mpdf;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Pdf\MpdfFontConfig;
use MyInvoice\Service\Pdf\TwigCache;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class PayslipPdfRenderer
{
    /**
     * Zvyšuje se spolu se šablonou, stejně jako u mzdového listu.
     *
     * Dřív se zvyšovat nesměla: běhový archiv uměl pro tutéž revizi vydat jen
     * jeden doklad, takže jiná verze při opakovaném spuštění dávky narazila na
     * `uq_payroll_document_revision`. Od té doby se {@see PayrollDocumentService
     * ::archive()} chová jako roční archiv — táž revize s jinou verzí šablony
     * nebo rendereru vydá DALŠÍ ČLÁNEK ŘETĚZU (`supersedes`,
     * `document_revision_no + 1`), shodná verze vrátí hotový doklad a
     * archivované PDF zůstává bajt na bajt stejné, protože se z uloženého
     * souboru jen vydává.
     *
     * v2: páska tiskne podklad nezdanění (osvobozeno / není předmětem daně)
     * a dva podsoučty.
     *
     * v3: patička a auditní řádek tisknou číslo revize běhu (revize č. 2),
     * ne ID řádku databáze. Pásky archivované ve v2 se vydávají dál ze
     * souboru beze změny.
     *
     * v4: hutná sazba na jednu stranu A4 podle toho, co zaměstnance zajímá —
     * docházka, dovolená, rozpad mzdy, pojistné, daň a výplata. Účetní
     * a výpočtová stopa (kontace, otisky) z pásky zmizela; revize zůstává
     * v patičce, otisky v metadatech PDF.
     */
    public const VERSION = 'mz-16-payslip-v4';
    public const HOURLY_VERSION = 'mz-16-payslip-v5';

    public static function versionFor(PayslipDocumentData $data): string
    {
        return $data->hourlyClaims === [] ? self::VERSION : self::HOURLY_VERSION;
    }

    private ?Environment $twig = null;

    public function render(PayslipDocumentData $data): RenderedPayslipDocument
    {
        $body = $this->renderHtml($data);

        $tmpDir = RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => 'P',
            'margin_left' => 11,
            'margin_right' => 11,
            'margin_top' => 10,
            'margin_bottom' => 13,
            'tempDir' => $tmpDir,
            'autoPageBreak' => true,
            ...MpdfFontConfig::options(),
        ]);
        $mpdf->SetTitle('Výplatní páska ' . $data->period);
        $mpdf->SetSubject('Výplatní páska, revize ' . $data->revisionLabel());
        $mpdf->SetKeywords('mzdy, výplatní páska, ' . self::versionFor($data));
        $mpdf->SetCreator('MyÚčto.cz');
        $mpdf->AddCustomProperty('PayrollRevision', $data->revisionId);
        if ($data->revisionNumber !== null) {
            $mpdf->AddCustomProperty('PayrollRevisionNumber', (string) $data->revisionNumber);
        }
        $mpdf->AddCustomProperty('PayrollSourceSnapshotSHA256', $data->sourceSnapshotSha256);
        $mpdf->AddCustomProperty('PayrollRendererVersion', self::versionFor($data));
        $mpdf->SetHTMLFooter(self::footerHtml($data));
        $mpdf->WriteHTML($body);
        $pdfBytes = $mpdf->Output('', 'S');
        if (!is_string($pdfBytes)) {
            throw new \UnexpectedValueException('mPDF did not return payslip bytes.');
        }

        return RenderedPayslipDocument::fromPdf($pdfBytes, $data, self::versionFor($data));
    }

    public static function footerHtml(PayslipDocumentData $data): string
    {
        $footerPeriod = htmlspecialchars($data->period, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $footerRevision = htmlspecialchars($data->revisionLabel(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<table style="width:100%; border-collapse:collapse; border-top:0.3pt solid #DEC8D4;'
            . ' font-family:montserrat,dejavusans,sans-serif; font-size:7pt; color:#6B5C66;">'
            . '<tr><td style="padding-top:1.5mm; text-align:left;">Výplatní páska ' . $footerPeriod
            . ' | revize ' . $footerRevision . '</td>'
            . '<td style="padding-top:1.5mm; text-align:center;">Strana {PAGENO} / {nbpg}</td>'
            . '<td style="padding-top:1.5mm; text-align:right;">MyÚčto.cz</td></tr></table>';
    }

    /**
     * Vysázené tělo pásky před tiskem do PDF — aby šlo v testu ověřit, co na
     * dokladu stojí (z PDF to kvůli podsazeným fontům spolehlivě nejde).
     */
    public function renderHtml(PayslipDocumentData $data): string
    {
        $templateData = $data->toTemplateData();
        $templateData['renderer_version'] = self::versionFor($data);

        return $this->twig()->render('payslip.twig', $templateData);
    }

    /** Tisíciny hodiny jako hodiny s nejvýš dvěma desetinnými místy („7,5"). */
    public static function formatMillihours(?int $millihours): string
    {
        if ($millihours === null) {
            return '—';
        }
        $formatted = number_format($millihours / 1000, 2, ',', ' ');

        return rtrim(rtrim($formatted, '0'), ',');
    }

    private function twig(): Environment
    {
        if ($this->twig === null) {
            $this->twig = new Environment(
                new FilesystemLoader([Bootstrap::rootDir() . '/api/templates/payroll']),
                [
                    'autoescape' => 'html',
                    'strict_variables' => true,
                ] + TwigCache::options('payroll'),
            );
            $this->twig->addFilter(new \Twig\TwigFilter(
                'hours',
                static fn (?int $millihours): string => self::formatMillihours($millihours),
            ));
            $this->twig->addFilter(new \Twig\TwigFilter(
                'minutes_hours',
                static fn (?int $minutes): string => self::formatMillihours(
                    $minutes === null ? null : (int) round($minutes * 1000 / 60),
                ),
            ));
            $this->twig->addFilter(new \Twig\TwigFilter(
                'minor_money',
                static function (int $minorUnits): string {
                    $sign = $minorUnits < 0 ? '-' : '';
                    $absolute = abs($minorUnits);
                    $whole = intdiv($absolute, 100);
                    $minor = $absolute % 100;

                    return $sign
                        . number_format($whole, 0, ',', ' ')
                        . ','
                        . str_pad((string) $minor, 2, '0', STR_PAD_LEFT);
                },
            ));
        }

        return $this->twig;
    }
}
