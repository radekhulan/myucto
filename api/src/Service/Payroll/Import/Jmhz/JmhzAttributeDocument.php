<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Jmhz;

use DOMDocument;
use DOMElement;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;

/**
 * Měsíční hlášení JMHZ, které jiný mzdový program uložil po atributech datového
 * slovníku (PAMICA: `MHitems.Data`), složené zpátky do XML, jaké čte
 * {@see JmhzReportReader}.
 *
 * Proč přes XML a ne vlastním překladem atributů na vlastnosti formuláře: mapa
 * elementů na vlastnosti {@see JmhzReportForm} je jediná a žije ve čtečce. Druhý
 * překlad by se od ní dřív nebo později rozešel a stejné hlášení by z XML a z PAMICA
 * dalo jiný formulář. Cesta atributu v XML je ve slovníku (`xsd_mapping`), takže
 * složení nic nedomýšlí.
 *
 * Co se převádí:
 *  - atribut s cestou `hlavicka.*` jde do hlavičky součásti (`formularOsoby/hlavicka`),
 *    ostatní do těla formuláře; druh těla (`bezPriznaku`, `cinnostKS`, …) nese PAMICA
 *    v atributu 1, který slovník nezná,
 *  - opakovaná skupina (dítě, jiná osoba, sekce ELDP) se pozná podle jména elementu;
 *    první opakovaný element na cestě bere pořadí `order`, druhý `order2`,
 *  - hodnoty podle datového typu slovníku: datum `d.m.rrrr` na `rrrr-mm-dd`, příznak
 *    `A`/`N` na `true`/`false`, desetinná čárka na tečku,
 *  - prázdná hodnota se vynechá (hlášení neuvedený údaj neobsahuje), stejně tak atribut,
 *    který slovník nezná; v uloženém obsahu podání zůstávají oba.
 *
 * Příznak záznamu (`flag`) se nebere v úvahu: PAMICA jím označuje údaje, které do XML
 * neposílá, protože nejsou povinné (fond pracovní doby, funkční požitky „ne"), ale jsou
 * to pořád její údaje o vztahu.
 */
final class JmhzAttributeDocument
{
    /** Atribut PAMICA s druhem těla formuláře; slovník JMHZ ho nezná. */
    public const VARIANT_ATTRIBUTE = 1;

    /** Opakovatelné elementy hlášení (XSD `maxOccurs` > 1). */
    private const REPEATED = ['vyzivovaneDite', 'jinaOsoba', 'partner', 'dite', 'eldp', 'obdobi', 'uzivatel', 'riziko', 'kolektivniSmlouva'];

    /** @var array<int,array{path:?string,regzec:?string,type:string}>|null */
    private static ?array $dictionary = null;

    /**
     * Atributy slovníku: ID => cesta v měsíčním hlášení, cesta v registraci a datový typ.
     *
     * @return array<int,array{path:?string,regzec:?string,type:string}>
     */
    public static function dictionary(?string $resourceRoot = null): array
    {
        if (self::$dictionary !== null && $resourceRoot === null) {
            return self::$dictionary;
        }
        $manifest = (new JmhzSpecPackageCatalog($resourceRoot))->load(
            JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
        );
        $out = [];
        foreach ($manifest['payload']['dictionary_attributes'] ?? [] as $attribute) {
            if (!is_array($attribute) || !is_string($attribute['attribute_id'] ?? null)) {
                continue;
            }
            $out[(int) $attribute['attribute_id']] = [
                'path' => self::path($attribute['xsd_mapping'] ?? null),
                'regzec' => self::path($attribute['regzec_xsd_mapping'] ?? null),
                'type' => (string) ($attribute['data_type'] ?? 'text'),
            ];
        }
        if ($resourceRoot === null) {
            self::$dictionary = $out;
        }

        return $out;
    }

    /**
     * ID atributů, které slovník nezná, takže je složení formuláře ({@see self::form()})
     * vynechá. Atribut druhu těla ({@see self::VARIANT_ATTRIBUTE}) mezi ně nepatří. Převod je
     * hlásí varováním, aby se údaj z cizího programu neztratil tiše (matice zdroje, brána G1).
     *
     * @param list<array{id:int}> $attributes
     * @return list<int>
     */
    public static function unknownAttributeIds(array $attributes): array
    {
        $dictionary = self::dictionary();
        $out = [];
        foreach ($attributes as $attribute) {
            $id = (int) $attribute['id'];
            if ($id !== self::VARIANT_ATTRIBUTE && !isset($dictionary[$id])) {
                $out[$id] = true;
            }
        }
        $ids = array_keys($out);
        sort($ids);

        return $ids;
    }

    /**
     * Hlášení s jedinou součástí: hlavička podání a formulář osoby z atributů.
     *
     * @param array{guid:string,type:string,year:int,month:int,filled_at:string} $header
     * @param list<array{id:int,order:int,order2:int,value:string}> $attributes atributy formuláře
     */
    public static function form(array $header, array $attributes): DOMDocument
    {
        $dictionary = self::dictionary();
        $document = new DOMDocument('1.0', 'UTF-8');
        $root = $document->createElementNS(JmhzSchemaCatalog::NS_PODANI, 'jmhz');
        $document->appendChild($root);
        $head = self::child($document, $root, JmhzSchemaCatalog::NS_PODANI, 'hlavicka');
        foreach ([
            'idPodani' => $header['guid'],
            'typPodani' => $header['type'],
            'mesic' => (string) $header['month'],
            'rok' => (string) $header['year'],
            'datumVyplneni' => $header['filled_at'],
        ] as $name => $value) {
            self::child($document, $head, JmhzSchemaCatalog::NS_PODANI, $name)->textContent = $value;
        }
        $forms = self::child($document, $root, JmhzSchemaCatalog::NS_PODANI, 'formulareOsob');
        $form = self::child($document, $forms, JmhzSchemaCatalog::NS_PODANI, 'formularOsoby');
        $formHead = self::child($document, $form, JmhzSchemaCatalog::NS_PODANI, 'hlavicka');

        $variant = 'bezPriznaku';
        foreach ($attributes as $attribute) {
            if ($attribute['id'] === self::VARIANT_ATTRIBUTE && in_array($attribute['value'], JmhzReportForm::VARIANTS, true)) {
                $variant = $attribute['value'];
            }
        }
        $body = self::child($document, $form, JmhzSchemaCatalog::NS_FORM, $variant);

        /** @var array<string,DOMElement> $nodes */
        $nodes = [];
        foreach ($attributes as $attribute) {
            $entry = $dictionary[$attribute['id']] ?? null;
            $path = $entry['path'] ?? null;
            if ($path === null || $attribute['value'] === '') {
                continue;
            }
            $value = self::value($attribute['value'], $entry['type']);
            $segments = explode('.', $path);
            if ($segments[0] === 'hlavicka') {
                if (count($segments) === 2) {
                    self::child($document, $formHead, JmhzSchemaCatalog::NS_PODANI, $segments[1])->textContent = $value;
                }
                continue;
            }
            $parent = $body;
            $key = '';
            $repeated = 0;
            $last = count($segments) - 1;
            foreach ($segments as $depth => $segment) {
                $index = 0;
                if (in_array($segment, self::REPEATED, true)) {
                    $index = $repeated === 0 ? $attribute['order'] : $attribute['order2'];
                    $repeated++;
                }
                $key .= '/' . $segment . '#' . $index;
                if ($depth === $last) {
                    $leaf = $nodes[$key] ?? null;
                    if ($leaf === null) {
                        $leaf = self::child($document, $parent, JmhzSchemaCatalog::NS_FORM, $segment);
                        $nodes[$key] = $leaf;
                    }
                    $leaf->textContent = $value;
                    break;
                }
                $nodes[$key] ??= self::child($document, $parent, JmhzSchemaCatalog::NS_FORM, $segment);
                $parent = $nodes[$key];
            }
        }

        return $document;
    }

    /** Hodnota atributu v zápisu XSD podle datového typu slovníku. */
    public static function value(string $value, string $type): string
    {
        $value = trim($value);
        if (in_array($type, ['datum', 'datumčas'], true)
            && preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$/D', $value, $m) === 1
        ) {
            $date = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
            if ($type === 'datumčas' && isset($m[4])) {
                return sprintf('%sT%02d:%02d:%02d', $date, (int) $m[4], (int) $m[5], (int) ($m[6] ?? 0));
            }

            return $date;
        }
        if ($type === 'příznak') {
            return match (mb_strtoupper($value)) {
                'A', 'ANO', 'TRUE', '1' => 'true',
                'N', 'NE', 'FALSE', '0' => 'false',
                default => $value,
            };
        }
        if ($type === 'číslo') {
            return str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $value);
        }

        return $value;
    }

    private static function child(DOMDocument $document, DOMElement $parent, string $namespace, string $name): DOMElement
    {
        $element = $document->createElementNS($namespace, $name);
        $parent->appendChild($element);

        return $element;
    }

    private static function path(mixed $mapping): ?string
    {
        if (!is_string($mapping)) {
            return null;
        }
        $path = preg_replace('/\s*\(ID [\d\/]+\)$/D', '', trim($mapping));

        return is_string($path) && $path !== '' ? $path : null;
    }
}
