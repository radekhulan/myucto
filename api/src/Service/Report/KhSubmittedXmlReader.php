<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

/**
 * Čte věty oddílů A a B z XML kontrolního hlášení (DPHKH1) do tvaru řádků soupisu
 * ({@see KontrolniHlaseniBuilder::sectionDocuments()}), aby šlo podané hlášení porovnat
 * s aktuálními daty po dokladech (issue #142).
 *
 * Věty A.1–A.4, B.1, B.2 nesou jednotlivé doklady; A.5 a B.3 jsou souhrnné, proto z nich
 * vznikne jediný řádek souhrnu.
 */
final class KhSubmittedXmlReader
{
    /** Věty XML → oddíl soupisu, atribut DIČ a atribut data. */
    private const ITEMIZED = [
        'VetaA1' => ['A.1', 'dic_odb', 'duzp'],
        'VetaA2' => ['A.2', null, 'dppd'],
        'VetaA4' => ['A.4', 'dic_odb', 'dppd'],
        'VetaB1' => ['B.1', 'dic_dod', 'duzp'],
        'VetaB2' => ['B.2', 'dic_dod', 'dppd'],
    ];

    private const AGGREGATED = ['VetaA5' => 'A.5', 'VetaB3' => 'B.3'];

    /**
     * @return array<string, list<array<string,mixed>>> oddíl → řádky
     */
    public static function read(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        if ($doc === false) {
            throw new \RuntimeException('XML kontrolního hlášení nelze načíst.');
        }
        $root = $doc->getName() === 'DPHKH1' ? $doc : $doc->DPHKH1;
        if (!$root instanceof \SimpleXMLElement || $root->getName() !== 'DPHKH1') {
            throw new \RuntimeException('Soubor neobsahuje kontrolní hlášení (DPHKH1).');
        }

        $out = [];
        foreach (self::ITEMIZED as $veta => [$section, $dicAttr, $dateAttr]) {
            foreach ($root->{$veta} as $v) {
                $out[$section][] = self::itemRow($section, $v, $dicAttr, $dateAttr);
            }
        }
        foreach (self::AGGREGATED as $veta => $section) {
            foreach ($root->{$veta} as $v) {
                $b21 = self::cents($v['zakl_dane1']); $v21 = self::cents($v['dan1']);
                $b12 = self::cents($v['zakl_dane2']); $v12 = self::cents($v['dan2']);
                $out[$section][] = self::row($section, '', '', null, null, [
                    'base21' => $b21, 'vat21' => $v21, 'base12' => $b12, 'vat12' => $v12,
                    'base_total' => $b21 + $b12, 'vat_total' => $v21 + $v12,
                ], false, true);
            }
        }
        return $out;
    }

    private static function itemRow(string $section, \SimpleXMLElement $v, ?string $dicAttr, string $dateAttr): array
    {
        $dic = $dicAttr === null
            ? (string) $v['k_stat'] . (string) $v['vatid_dod']
            : (string) $v[$dicAttr];
        if ($section === 'A.1') {
            $cents = ['base21' => null, 'vat21' => null, 'base12' => null, 'vat12' => null,
                      'base_total' => self::cents($v['zakl_dane1']), 'vat_total' => 0];
        } else {
            $b21 = self::cents($v['zakl_dane1']); $v21 = self::cents($v['dan1']);
            $b12 = self::cents($v['zakl_dane2']); $v12 = self::cents($v['dan2']);
            $cents = ['base21' => $b21, 'vat21' => $v21, 'base12' => $b12, 'vat12' => $v12,
                      'base_total' => $b21 + $b12, 'vat_total' => $v21 + $v12];
        }
        $kod = (string) $v['kod_pred_pl'];
        return self::row(
            $section,
            (string) $v['c_evid_dd'],
            $dic,
            self::isoDate((string) $v[$dateAttr]),
            $kod !== '' ? $kod : null,
            $cents,
            (string) $v['zdph_44'] === 'P',
            false,
        );
    }

    /** @param array<string,?int> $cents */
    private static function row(string $section, string $doc, string $dic, ?string $date, ?string $kod, array $cents, bool $correction, bool $aggregate): array
    {
        return [
            'section'           => $section,
            'source'            => str_starts_with($section, 'A.') && $section !== 'A.2' ? 'sale' : 'purchase',
            'invoice_id'        => null,
            'doc_number'        => $doc,
            'internal_number'   => null,
            'counterparty_name' => '',
            'counterparty_dic'  => $dic,
            'tax_date'          => $date,
            'kod_pred_pl'       => $kod,
            'is_correction'     => $correction,
            'is_aggregate'      => $aggregate,
        ] + array_map(static fn (?int $c): ?float => $c === null ? null : $c / 100, $cents);
    }

    private static function cents(mixed $v): int
    {
        return (int) round(((float) (string) $v) * 100);
    }

    private static function isoDate(string $d): ?string
    {
        $dt = \DateTimeImmutable::createFromFormat('!d.m.Y', $d);
        return $dt === false ? null : $dt->format('Y-m-d');
    }
}
