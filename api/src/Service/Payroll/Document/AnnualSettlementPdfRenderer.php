<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use Mpdf\Mpdf;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementResult;
use MyInvoice\Service\Pdf\MpdfFontConfig;
use MyInvoice\Service\Pdf\TwigCache;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Tisk výsledku ročního zúčtování.
 *
 * Sazba i cesta k archivaci jsou stejné jako u mzdového listu — doklad se
 * ukládá přes `PayrollDocumentService::archiveAnnualPdf()` a je obsahově
 * adresovaný, takže druhé vygenerování téhož výsledku vrátí týž soubor.
 */
final class AnnualSettlementPdfRenderer
{
    // v2: doklad rozepisuje úhrn na vlastní kumulace a na potvrzení od
    // předchozích plátců (§ 38ch odst. 3 a 4). Do v1 se úhrn tiskl jako jediné
    // číslo, ze kterého nešlo poznat, že část pochází od jiného plátce.
    // v3: u zúčtování s ročně uplatňovanými položkami (§ 38h odst. 6) doklad
    // ukazuje základ před odpočtem, nezdanitelné části po druzích (dary,
    // úroky, spoření na stáří) a slevu za zastavenou exekuci (§ 35 odst. 4).
    public const VERSION = 'mz-25-annual-settlement-v3';

    private ?Environment $twig = null;

    public function render(AnnualSettlementDocumentData $data): PayrollArtifact
    {
        $template = $data->toTemplateData();
        $template['renderer_version'] = self::VERSION;
        $body = $this->twig()->render('annual-settlement.twig', $template);
        $tmpDir = RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 10,
            'margin_bottom' => 14,
            'tempDir' => $tmpDir,
            ...MpdfFontConfig::options(),
        ]);
        $mpdf->SetTitle('Roční zúčtování ' . $data->taxYear);
        $mpdf->SetSubject(
            'Výpočet daně a roční zúčtování záloh a daňového zvýhodnění (§ 38ch ZDP)',
        );
        $mpdf->SetKeywords('mzdy, roční zúčtování, ' . self::VERSION);
        $mpdf->SetCreator('MyÚčto.cz');
        $mpdf->AddCustomProperty(
            'PayrollSourceSnapshotHMACSHA256',
            $data->sourceSnapshotSha256,
        );
        $mpdf->AddCustomProperty('PayrollRendererVersion', self::VERSION);
        $mpdf->SetHTMLFooter(
            '<table style="width:100%;border-top:0.3pt solid #DEC8D4;'
            . 'font-family:montserrat,dejavusans,sans-serif;font-size:7pt;color:#6B5C66">'
            . '<tr><td>Roční zúčtování ' . $data->taxYear . '</td>'
            . '<td style="text-align:center">Strana {PAGENO} / {nbpg}</td>'
            . '<td style="text-align:right">MyÚčto.cz</td></tr></table>',
        );
        $mpdf->WriteHTML($body);
        $pdf = $mpdf->Output('', 'S');
        if (!is_string($pdf) || !str_starts_with($pdf, '%PDF-')) {
            throw new \UnexpectedValueException(
                'mPDF nevytvořilo platný doklad ročního zúčtování.',
            );
        }
        $fileHash = hash('sha256', $pdf);

        return new PayrollArtifact(
            PayrollDocumentKind::AnnualSettlementResult,
            $pdf,
            'application/pdf',
            sprintf(
                'rocni-zuctovani-%d-%s.pdf',
                $data->taxYear,
                substr($fileHash, 0, 12),
            ),
            $data->sourceSnapshotSha256,
            AnnualSettlementResult::SCHEMA_VERSION,
            self::VERSION,
        );
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
                'minor_money',
                static function (int $minorUnits): string {
                    $sign = $minorUnits < 0 ? '-' : '';
                    $absolute = abs($minorUnits);

                    return $sign
                        . number_format(intdiv($absolute, 100), 0, ',', ' ')
                        . ','
                        . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
                },
            ));
        }

        return $this->twig;
    }
}
