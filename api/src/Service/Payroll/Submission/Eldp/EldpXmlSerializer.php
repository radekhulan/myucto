<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Eldp;

/**
 * Deterministický zápis evidenčního listu do XML.
 *
 * Struktura i pořadí elementů se řídí připnutým oficiálním typem `eldpType`
 * z `jmhz-1.4.3.4/formCommonTypes.xsd`. Bajty musí být stabilní: zmrazený
 * artefakt se při každém dalším čtení znovu porovnává se svým otiskem, takže
 * jakákoliv nedeterminovanost (pořadí klíčů, odsazení, konce řádků) by
 * z dříve platného evidenčního listu udělala neověřitelný.
 */
final class EldpXmlSerializer
{
    /**
     * Pořadí podle sekvence `vylouceneDnyType`.
     *
     * Jen složky § 16 odst. 4 zákona č. 155/1995 Sb. Typ `vylouceneDnyType`
     * nese i vyloučené DNY podle § 18 odst. 7 zákona č. 187/2006 Sb.
     * (10366 a rozpad 10473–10475), ale ty sem NEPATŘÍ a doplnit je není
     * sjednocení, nýbrž chyba: evidenční list je doklad DŮCHODOVÉHO
     * pojištění, kdežto § 18 odst. 7 krátí rozhodné období denního
     * vyměřovacího základu NEMOCENSKÝCH dávek a vykazuje se měsíčně
     * v hlášení ({@see \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1XmlSerializer}).
     */
    private const EXCLUDED_ORDER = [
        'docasNeschopnost',
        'penezitaPomocMaterstvi',
        'osetrovaniClenaRodiny',
        'otcovska',
        'vyloucenePar16',
    ];

    public function serialize(EldpAnnualStatement $statement): string
    {
        $lines = [];
        $lines[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $lines[] = '<' . EldpSchemaCatalog::ROOT_ELEMENT
            . ' xmlns="' . EldpSchemaCatalog::NAMESPACE_URI . '">';
        foreach ($statement->sections() as $section) {
            $lines[] = '  <eldp>';
            $lines[] = '    ' . self::element('kod', self::text($section, 'code'));
            $lines[] = '    ' . self::element('platnostOd', self::text($section, 'valid_from'));
            $lines[] = '    ' . self::element('platnostDo', self::text($section, 'valid_to'));
            $lines[] = '    ' . self::element('pocetDnu', self::number($section, 'insurance_days'));
            $lines[] = '    ' . self::element(
                'vymerovaciZaklad',
                self::number($section, 'assessment_base_czk'),
            );
            $excluded = $section['excluded_days'] ?? null;
            if (!is_array($excluded)) {
                throw new EldpValidationException(
                    'eldp_xml_source_invalid',
                    'Sekce evidenčního listu nemá rozpad vyloučených dob.',
                );
            }
            $lines[] = '    <vylouceneDny>';
            $lines[] = '      ' . self::element(
                'vylouceneDobyCelkem',
                self::number($section, 'excluded_days_total'),
            );
            foreach (self::EXCLUDED_ORDER as $key) {
                $lines[] = '      ' . self::element($key, self::number($excluded, $key));
            }
            $lines[] = '    </vylouceneDny>';
            $lines[] = '    <odecitaneDny>';
            $lines[] = '      ' . self::element(
                'odecitaneDobyCelkem',
                self::number($section, 'deducted_days_total'),
            );
            $lines[] = '    </odecitaneDny>';
            $lines[] = '  </eldp>';
        }
        $lines[] = '</' . EldpSchemaCatalog::ROOT_ELEMENT . '>';

        return implode("\n", $lines) . "\n";
    }

    private static function element(string $name, string $value): string
    {
        return "<{$name}>"
            . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            . "</{$name}>";
    }

    /** @param array<string,mixed> $source */
    private static function text(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new EldpValidationException(
                'eldp_xml_source_invalid',
                "Sekce evidenčního listu nemá vyplněné pole {$key}.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $source */
    private static function number(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (!is_int($value) || $value < 0) {
            throw new EldpValidationException(
                'eldp_xml_source_invalid',
                "Pole {$key} evidenčního listu musí být nezáporné celé číslo.",
            );
        }

        return (string) $value;
    }
}
