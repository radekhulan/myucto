<?php

declare(strict_types=1);

namespace MyInvoice\Service\Epo;

/**
 * Řekne, ČÍM se ručně nahrané XML liší od archivovaného snapshotu.
 *
 * U asistovaného podání jde soubor přes ruce a přes formulář EPO: účetní tam může
 * hodnotu doupravit, portál může přiznání znovu vygenerovat. Když se pak nahraje
 * zpátky, samotná neshoda otisku říká jen „tohle není ono" — a to je informace,
 * se kterou se nedá nic dělat. Rozdíl po položkách naopak rovnou ukáže, jestli šlo
 * o úpravu čísla (pak snapshot NEODPOVÍDÁ podanému a je potřeba vygenerovat nový),
 * nebo jen o jiný formulář či období (pak byl nahrán soubor od jiného podání).
 *
 * Porovnává se struktura, ne bajty: whitespace ani pořadí atributů nehrají roli.
 */
final class EpoSubmissionXmlComparator
{
    private const MAX_DIFFERENCES = 40;

    /**
     * @return array{
     *   comparable:bool,
     *   form_code:?string,expected_form_code:?string,form_match:?bool,
     *   difference_count:int,
     *   differences:list<array{path:string,expected:?string,actual:?string}>
     * }
     */
    public function compare(string $expectedXml, string $actualXml): array
    {
        $empty = [
            'comparable' => false,
            'form_code' => null,
            'expected_form_code' => null,
            'form_match' => null,
            'difference_count' => 0,
            'differences' => [],
        ];

        $expected = $this->load($expectedXml);
        $actual = $this->load($actualXml);
        if ($expected === null || $actual === null) {
            return $empty;
        }

        $expectedForm = $this->formCode($expected);
        $actualForm = $this->formCode($actual);

        $expectedValues = $this->flatten($expected);
        $actualValues = $this->flatten($actual);

        $differences = [];
        foreach ($expectedValues as $path => $value) {
            if (!array_key_exists($path, $actualValues)) {
                $differences[$path] = ['path' => $path, 'expected' => $value, 'actual' => null];
            } elseif ($actualValues[$path] !== $value) {
                $differences[$path] = ['path' => $path, 'expected' => $value, 'actual' => $actualValues[$path]];
            }
        }
        foreach ($actualValues as $path => $value) {
            if (!array_key_exists($path, $expectedValues)) {
                $differences[$path] = ['path' => $path, 'expected' => null, 'actual' => $value];
            }
        }

        return [
            'comparable' => true,
            'form_code' => $actualForm,
            'expected_form_code' => $expectedForm,
            'form_match' => $expectedForm !== null && $actualForm !== null
                ? $expectedForm === $actualForm
                : null,
            'difference_count' => count($differences),
            'differences' => array_slice(array_values($differences), 0, self::MAX_DIFFERENCES),
        ];
    }

    private function load(string $xml): ?\DOMDocument
    {
        if ($xml === '' || strlen($xml) > 20 * 1024 * 1024) {
            return null;
        }
        libxml_use_internal_errors(true);
        libxml_clear_errors();
        $dom = new \DOMDocument();
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        return $ok ? $dom : null;
    }

    private function formCode(\DOMDocument $dom): ?string
    {
        $known = [
            'dphdp3', 'dphkh1', 'dphshv', 'dpfdp5', 'dpfdp7', 'dppdp9', 'ossei1',
            'dpzmb1', 'dpzdb1',
        ];
        foreach ((new \DOMXPath($dom))->query('//*') ?: [] as $node) {
            $name = strtolower((string) $node->localName);
            if (in_array($name, $known, true)) {
                return $name;
            }
        }
        return null;
    }

    /**
     * Plochá mapa `cesta@atribut => hodnota`. Opakující se sourozenci dostanou pořadové
     * číslo, jinak by se řádky kontrolního hlášení navzájem přebily a rozdíl by zmizel.
     *
     * @return array<string,string>
     */
    private function flatten(\DOMDocument $dom): array
    {
        $values = [];
        $walk = function (\DOMElement $element, string $prefix) use (&$walk, &$values): void {
            foreach ($element->attributes ?? [] as $attribute) {
                if ($attribute instanceof \DOMAttr) {
                    $values[$prefix . '@' . $attribute->localName] = $this->normalize($attribute->value);
                }
            }
            $counts = [];
            $hasChildElement = false;
            foreach ($element->childNodes as $child) {
                if (!$child instanceof \DOMElement) {
                    continue;
                }
                $hasChildElement = true;
                $name = (string) $child->localName;
                $counts[$name] = ($counts[$name] ?? 0) + 1;
                $walk($child, $prefix . '/' . $name . '[' . $counts[$name] . ']');
            }
            if (!$hasChildElement) {
                $text = $this->normalize($element->textContent);
                if ($text !== '') {
                    $values[$prefix] = $text;
                }
            }
        };

        $root = $dom->documentElement;
        if ($root !== null) {
            $walk($root, (string) $root->localName);
        }
        return $values;
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
