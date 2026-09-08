<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use Mpdf\Mpdf;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Společný základ pro PDF renderery účetních sestav (Epic F2).
 *
 * Poskytuje lazy Twig prostředí nad api/templates/report (filtry cz_money,
 * cz_date — shodné s DphBookPdfRenderer) a factory na mPDF instanci
 * (A4 landscape default, výkazy si přepnou na portrait přes $overrides).
 */
abstract class ReportPdfRendererBase
{
    private ?Environment $twig = null;

    /**
     * @param array<string,mixed> $data výstup příslušné report service (spec F2 §2.4–2.7)
     */
    abstract public function render(array $data): string;

    /**
     * @param array<string,mixed> $data
     */
    protected function renderTemplate(string $template, array $data): string
    {
        return $this->twig()->render($template, $data);
    }

    /**
     * @param array<string,mixed> $overrides přepis default configu (např. format 'A4' + orientation 'P')
     */
    protected function mpdf(array $overrides = []): Mpdf
    {
        $tmpDir = RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }

        $config = [
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
        ];

        $mpdf = new Mpdf(array_replace($config, $overrides));
        $mpdf->SetCreator('MyÚčto.cz');
        return $mpdf;
    }

    /**
     * Patička tiskové sestavy: název sestavy, číslo strany, původce a okamžik tisku.
     *
     * Číslování stran vychází z §13 ZoÚ (účetní deník i hlavní kniha jsou zákonné
     * knihy a musí mít číslované strany). Původce a datum tisku patří na archivovaný
     * výtisk ze stejného důvodu, z jakého je tam má každý konkurenční systém: bez
     * nich se u papíru v šanonu nedá zjistit, odkud a kdy pochází.
     *
     * `{PAGENO}`/`{nbpg}` jsou mPDF vestavěné tokeny, `|` odděluje levou, střední
     * a pravou část patičky.
     *
     * NEPOUŽÍVAT na úředních tiskopisech a na dokladech, které jsou samy o sobě
     * právním jednáním (přehledy pro pojišťovny a ČSSZ, dohoda o zápočtu,
     * pokladní a skladové doklady, objednávky) — tam patička nepatří.
     */
    protected function withPageNumbers(Mpdf $mpdf, string $left = ''): void
    {
        $mpdf->SetFooter(
            $left
            . '|Strana {PAGENO} / {nbpg}|'
            . 'Zpracováno v MyÚčto.cz · ' . (new \DateTimeImmutable())->format('d.m.Y H:i')
        );
    }

    private function twig(): Environment
    {
        if ($this->twig === null) {
            $loader = new FilesystemLoader([
                Bootstrap::rootDir() . '/api/templates/report',
            ]);
            $this->twig = new Environment($loader, [
                'autoescape' => 'html',
                'strict_variables' => false,
            ] + TwigCache::options('report'));
            $this->twig->addFilter(new \Twig\TwigFilter('cz_money', function ($v) {
                return number_format((float) $v, 2, ',', ' ');
            }));
            $this->twig->addFilter(new \Twig\TwigFilter('cz_date', function ($v) {
                if (!$v) return '';
                try {
                    return (new \DateTimeImmutable((string) $v))->format('d.m.Y');
                } catch (\Throwable) {
                    return '';
                }
            }));
        }
        return $this->twig;
    }
}
