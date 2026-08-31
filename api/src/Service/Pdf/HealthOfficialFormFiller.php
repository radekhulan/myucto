<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use Mpdf\Mpdf;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationException;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthOfficialForm;
use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfParser\Type\PdfArray;
use setasign\Fpdi\PdfParser\Type\PdfDictionary;
use setasign\Fpdi\PdfParser\Type\PdfNumeric;
use setasign\Fpdi\PdfParser\Type\PdfString;
use setasign\Fpdi\PdfParser\Type\PdfType;

/**
 * Vyplní připnutý úřední tiskopis zdravotní pojišťovny.
 *
 * ## Proč se text kreslí, a ne zapisuje do polí formuláře
 *
 * Nabízí se vyplnit AcroForm pole (`/V` + vygenerovaný vzhled). U TĚCHTO
 * tiskopisů to ale tiše komolí česká jména. Písmo, které tiskopis pro pole
 * předepisuje, je do souboru vložené s kódováním WinAnsi, a to nezná
 * `č ď ě ň ř ť ů`; generátor vzhledu buď spadne (vložené písmo), nebo znak
 * nahradí otazníkem (standardní Helvetica). Kontrola „hodnota po uložení
 * sedí" na to nepřijde, protože porovnává `/V`, ne to, co je na papíře —
 * z „Řehořová" se stane „?eho?ová" a podání odejde s jiným jménem, než jaké
 * je v datové větě.
 *
 * Proto se stránka tiskopisu vezme jako podklad a hodnoty se vysází vlastním
 * monospace písmem s plnou diakritikou, přesně do obdélníků, které jsou
 * v tiskopisu definované pro jeho pole. Souřadnice se tedy nikde nepíšou
 * ručně — čtou se ze souboru, takže po výměně tiskopisu sedí samy a
 * chybějící pole se pozná okamžitě.
 *
 * Vedlejší, ale podstatný důsledek: výstup je plochý dokument bez formulářové
 * vrstvy. Vypadá stejně ve všech prohlížečích, dá se z něj vytěžit text
 * a nedá se omylem přepsat.
 */
final class HealthOfficialFormFiller extends ReportPdfRendererBase
{
    /** Písmo hodnot: monospace s plnou diakritikou (viz {@see MpdfFontConfig}). */
    private const FONT = 'geistmono';

    private const FONT_SIZE_MAX = 9.0;
    private const FONT_SIZE_MIN = 5.5;
    private const PADDING_MM = 0.8;

    /** Tahle třída tiskopis vyplňuje, žádnou Twig sestavu nerenderuje. */
    public function render(array $data): string
    {
        throw new \LogicException(
            'HealthOfficialFormFiller vyplňuje úřední tiskopis, ne Twig sestavu.',
        );
    }

    /**
     * @param array<string,string> $values název pole tiskopisu => text
     * @param list<string>         $marks  názvy zaškrtávacích polí (křížek)
     */
    public function fill(
        HealthOfficialForm $form,
        array $values,
        array $marks = [],
        string $title = '',
    ): string {
        $layout = $this->layout($form);
        $rects = $layout['rects'];

        $missing = array_values(array_diff($form->fieldNames, array_keys($rects)));
        if ($missing !== []) {
            throw new HealthNotificationException(
                'zp_official_form_changed',
                sprintf(
                    'Úřední tiskopis %s nemá pole %s — struktura se změnila.',
                    $form->formNumber,
                    implode(', ', $missing),
                ),
            );
        }
        foreach ([...array_keys($values), ...$marks] as $name) {
            if (!in_array($name, $form->fieldNames, true)) {
                throw new HealthNotificationException(
                    'zp_official_form_field_unknown',
                    sprintf(
                        'Pole „%s" na úředním tiskopisu %s není.',
                        $name,
                        $form->formNumber,
                    ),
                );
            }
        }

        $mpdf = $this->mpdf([
            'format' => [$layout['width_mm'], $layout['height_mm']],
            'orientation' => 'P',
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 0,
            'autoPageBreak' => false,
        ]);
        if ($title !== '') {
            $mpdf->SetTitle($title);
        }
        $mpdf->AddPage();
        $mpdf->SetSourceFile(StreamReader::createByString($form->bytes));
        $mpdf->UseTemplate(
            $mpdf->ImportPage(1),
            0,
            0,
            $layout['width_mm'],
            $layout['height_mm'],
        );
        $mpdf->SetTextColor(0, 0, 0);

        foreach ($values as $name => $text) {
            $text = trim($text);
            if ($text === '') {
                continue;
            }
            $rect = $rects[$name];
            $width = $rect['w'] - (2 * self::PADDING_MM);
            $size = $this->fittingSize($mpdf, $text, $width, $name, $form);
            $mpdf->SetFont(self::FONT, 'B', $size);
            $mpdf->SetXY($rect['x'] + self::PADDING_MM, $rect['y']);
            $mpdf->Cell($width, $rect['h'], $text, 0, 0, 'L');
        }
        foreach ($marks as $name) {
            $rect = $rects[$name];
            $mpdf->SetFont(self::FONT, 'B', min(self::FONT_SIZE_MAX, $rect['h'] * 2.2));
            $mpdf->SetXY($rect['x'], $rect['y']);
            $mpdf->Cell($rect['w'], $rect['h'], 'X', 0, 0, 'C');
        }

        return $mpdf->Output('', 'S');
    }

    /**
     * Největší velikost písma, při které se hodnota do svého políčka vejde.
     * Když se nevejde ani nejmenší, je to tvrdá chyba: přetečený text by se
     * na tiskopisu překryl se sousedním údajem a nikdo by si toho nevšiml.
     */
    private function fittingSize(
        Mpdf $mpdf,
        string $text,
        float $widthMm,
        string $field,
        HealthOfficialForm $form,
    ): float {
        for ($size = self::FONT_SIZE_MAX; $size >= self::FONT_SIZE_MIN; $size -= 0.25) {
            $mpdf->SetFont(self::FONT, 'B', $size);
            if ($mpdf->GetStringWidth($text) <= $widthMm) {
                return $size;
            }
        }

        throw new HealthNotificationException(
            'zp_official_form_value_too_long',
            sprintf(
                'Údaj v poli „%s" (%d znaků) se na úřední tiskopis %s nevejde.',
                $field,
                mb_strlen($text),
                $form->formNumber,
            ),
        );
    }

    /**
     * Rozměry stránky a obdélníky polí ze samotného tiskopisu.
     *
     * Prochází se strom `/AcroForm /Fields`, ne anotace stránky: jména polí
     * bydlí u rodiče a widgety pod ním jméno nemají. Přepínač (`/Btn`) má
     * widget na každý stav, takže se klíčuje `Typ:/0` a `Typ:/1`.
     *
     * @return array{width_mm:float,height_mm:float,rects:array<string,array{x:float,y:float,w:float,h:float}>}
     */
    private function layout(HealthOfficialForm $form): array
    {
        try {
            $parser = new PdfParser(StreamReader::createByString($form->bytes));
            $catalog = $parser->getCatalog();
            $page = $this->firstPage($parser, $catalog);
            $media = PdfType::resolve(PdfDictionary::get($page, 'MediaBox'), $parser);
            if (!$media instanceof PdfArray) {
                throw new \RuntimeException('Tiskopis nemá MediaBox.');
            }
            $box = array_map(
                static fn ($v): float => (float) PdfType::resolve($v, $parser)->value,
                $media->value,
            );
            $widthPt = abs($box[2] - $box[0]);
            $heightPt = abs($box[3] - $box[1]);

            $acro = PdfType::resolve(PdfDictionary::get($catalog, 'AcroForm'), $parser);
            $fields = $acro instanceof PdfDictionary
                ? PdfType::resolve(PdfDictionary::get($acro, 'Fields'), $parser)
                : null;
            if (!$fields instanceof PdfArray) {
                throw new \RuntimeException('Tiskopis nemá pole formuláře.');
            }
            $rects = [];
            foreach ($fields->value as $field) {
                $this->collectRects($parser, $field, '', $heightPt, $rects);
            }
        } catch (\Throwable $e) {
            throw new HealthNotificationException(
                'zp_official_form_unreadable',
                sprintf(
                    'Úřední tiskopis %s se nepodařilo přečíst (%s).',
                    $form->formNumber,
                    $e->getMessage(),
                ),
            );
        }

        $mm = 25.4 / 72.0;

        return [
            'width_mm' => $widthPt * $mm,
            'height_mm' => $heightPt * $mm,
            'rects' => $rects,
        ];
    }

    /** @param array<string,array{x:float,y:float,w:float,h:float}> $rects */
    private function collectRects(
        PdfParser $parser,
        mixed $node,
        string $prefix,
        float $pageHeightPt,
        array &$rects,
    ): void {
        $dict = PdfType::resolve($node, $parser);
        if (!$dict instanceof PdfDictionary) {
            return;
        }
        $title = PdfType::resolve(PdfDictionary::get($dict, 'T'), $parser);
        $name = $title instanceof PdfString ? PdfString::unescape($title->value) : '';
        $full = $prefix === ''
            ? $name
            : ($name === '' ? $prefix : $prefix . '.' . $name);

        $kids = PdfType::resolve(PdfDictionary::get($dict, 'Kids'), $parser);
        if ($kids instanceof PdfArray && $kids->value !== []) {
            foreach ($kids->value as $kid) {
                $this->collectRects($parser, $kid, $full, $pageHeightPt, $rects);
            }

            return;
        }

        $rect = PdfType::resolve(PdfDictionary::get($dict, 'Rect'), $parser);
        if (!$rect instanceof PdfArray || $full === '') {
            return;
        }
        $key = $full . $this->appearanceStateSuffix($parser, $dict);
        $box = array_map(
            static function ($v) use ($parser): float {
                $value = PdfType::resolve($v, $parser);

                return $value instanceof PdfNumeric ? (float) $value->value : 0.0;
            },
            $rect->value,
        );
        $mm = 25.4 / 72.0;
        $rects[$key] = [
            'x' => min($box[0], $box[2]) * $mm,
            'y' => ($pageHeightPt - max($box[1], $box[3])) * $mm,
            'w' => abs($box[2] - $box[0]) * $mm,
            'h' => abs($box[3] - $box[1]) * $mm,
        ];
    }

    /** Přepínač má víc widgetů pod jedním jménem — rozliší je stav vzhledu. */
    private function appearanceStateSuffix(PdfParser $parser, PdfDictionary $widget): string
    {
        $ap = PdfType::resolve(PdfDictionary::get($widget, 'AP'), $parser);
        if (!$ap instanceof PdfDictionary) {
            return '';
        }
        $normal = PdfType::resolve(PdfDictionary::get($ap, 'N'), $parser);
        if (!$normal instanceof PdfDictionary) {
            return '';
        }
        foreach (array_keys($normal->value) as $state) {
            if ($state !== 'Off') {
                return ':/' . $state;
            }
        }

        return '';
    }

    private function firstPage(PdfParser $parser, PdfDictionary $catalog): PdfDictionary
    {
        $node = PdfType::resolve(PdfDictionary::get($catalog, 'Pages'), $parser);
        while ($node instanceof PdfDictionary) {
            $kids = PdfType::resolve(PdfDictionary::get($node, 'Kids'), $parser);
            if (!$kids instanceof PdfArray || $kids->value === []) {
                return $node;
            }
            $node = PdfType::resolve($kids->value[0], $parser);
        }

        throw new \RuntimeException('Tiskopis nemá stránku.');
    }
}
