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

class AnnualTaxCertificatePdfRenderer
{
    public const VERSION = 'mz-annual-tax-certificate-2026-v3';

    private ?Environment $twig = null;

    public function render(
        AnnualTaxCertificateDocumentData $data,
    ): PayrollArtifact {
        $template = $data->toTemplateData();
        $template['renderer_version'] = self::VERSION;
        $body = $this->twig()->render(
            'annual-tax-certificate.twig',
            $template,
        );
        $tmpDir = RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => 'P',
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 10,
            'margin_bottom' => 14,
            'tempDir' => $tmpDir,
            ...MpdfFontConfig::options(),
        ]);
        $mpdf->SetTitle(
            'Potvrzení o zdanitelných příjmech ' . $data->taxYear,
        );
        $mpdf->SetSubject(
            'Roční potvrzení ze schválených mzdových revizí a doložených úhrad',
        );
        $mpdf->SetKeywords(
            'mzdy, zdanitelné příjmy, '
            . (string) $data->form['form_number']
            . ', '
            . self::VERSION,
        );
        $mpdf->SetCreator('MyÚčto.cz');
        $mpdf->AddCustomProperty(
            'PayrollSourceSnapshotHMACSHA256',
            $data->sourceSnapshotSha256,
        );
        $mpdf->AddCustomProperty('PayrollRendererVersion', self::VERSION);
        $mpdf->SetHTMLFooter(
            '<table style="width:100%;border-top:0.3pt solid #DEC8D4;'
            . 'font-family:montserrat,dejavusans,sans-serif;font-size:7pt;'
            . 'color:#6B5C66"><tr><td>Daňové potvrzení '
            . $data->taxYear
            . '</td><td style="text-align:center">Strana {PAGENO} / {nbpg}</td>'
            . '<td style="text-align:right">MyÚčto.cz</td></tr></table>',
        );
        $mpdf->WriteHTML($body);
        $pdf = $mpdf->Output('', 'S');
        if (!is_string($pdf) || !str_starts_with($pdf, '%PDF-')) {
            throw new \UnexpectedValueException(
                'mPDF nevytvořilo platné roční daňové potvrzení.',
            );
        }
        $fileHash = hash('sha256', $pdf);
        $prefix = $data->kind
            === PayrollDocumentKind::TaxableIncomeAdvanceCertificate
                ? 'potvrzeni-zdanitelne-prijmy-zalohova-dan'
                : 'potvrzeni-zdanitelne-prijmy-srazkova-dan';

        return new PayrollArtifact(
            $data->kind,
            $pdf,
            'application/pdf',
            sprintf(
                '%s-%d-%s.pdf',
                $prefix,
                $data->taxYear,
                substr($fileHash, 0, 12),
            ),
            $data->sourceSnapshotSha256,
            AnnualTaxCertificateDocumentData::SCHEMA_VERSION,
            self::VERSION,
        );
    }

    private function twig(): Environment
    {
        if ($this->twig === null) {
            $this->twig = new Environment(
                new FilesystemLoader([
                    Bootstrap::rootDir() . '/api/templates/payroll',
                ]),
                [
                    'autoescape' => 'html',
                    'strict_variables' => true,
                ] + TwigCache::options('payroll'),
            );
            $this->twig->addFilter(new \Twig\TwigFilter(
                'whole_czk',
                static fn (int $amount): string =>
                    number_format($amount, 0, ',', ' '),
            ));
        }

        return $this->twig;
    }
}
