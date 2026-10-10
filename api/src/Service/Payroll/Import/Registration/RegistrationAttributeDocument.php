<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Registration;

use MyInvoice\Support\LocaleNumber;
use DOMDocument;
use DOMElement;
use DOMXPath;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzAttributeDocument;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSchemaCatalog;

/**
 * Věta registrace zaměstnance REGZEC25, kterou jiný mzdový program uložil po atributech
 * datového slovníku (PAMICA: `RegZAMitems.Data`), složená zpátky do XML, jaké čte
 * {@see RegistrationXmlReader}. Stejný princip jako {@see JmhzAttributeDocument}: mapa
 * údajů na evidenci je jediná a žije v importu registrací, tady se nic nepřekládá.
 *
 * Cestu atributu nese slovník (`regzec_xsd_mapping`): poslední článek je atribut XML,
 * články před ním elementy. Pořadí elementů a datový typ atributu se berou z připnutého
 * XSD (REGZEC25 má elementy jen jako nosiče atributů). Hodnoty se převádějí jen v zápisu:
 * datum `d.m.rrrr` na `rrrr-mm-dd`, příznak na `A`/`N`, číslo bez mezer. Prázdná hodnota
 * a atribut, který slovník nezná, se vynechají; přílohy (`attachs`) se nepřebírají.
 *
 * Co PAMICA ve větě nenese (pořadí věty, akce, den vyplnění), dodá volající jako
 * `$derived` z řádku věty; tyto hodnoty mají přednost.
 */
final class RegistrationAttributeDocument
{
    public const SENTENCE = 10014;
    public const ACTION = 10008;
    public const PREPARED_ON = 10005;

    /** Atributy věty, které slovník JMHZ nemá (pořadí věty je jen v XSD). */
    private const OUTSIDE_DICTIONARY = [self::SENTENCE => 'employees.employee.sqnr'];
    private const NS_XSD = 'http://www.w3.org/2001/XMLSchema';
    private const ROOT_PATH = 'employees.employee';
    private const REPEATED = ['pensionperiod'];
    private const SKIPPED = ['attachs'];

    /** @var array<string,array{children:list<string>,attributes:array<string,string>}>|null */
    private static ?array $schema = null;

    /**
     * @param list<array{id:int,order:int,value:string}> $attributes atributy věty
     * @param array<int,string> $derived atribut => hodnota, přebíjí atributy věty
     */
    public static function sentence(array $attributes, array $derived = []): DOMDocument
    {
        $catalog = (new PayrollRegistrationSchemaCatalog())->schemaFor('REGZEC25');
        $schema = self::schema($catalog['path']);
        $dictionary = JmhzAttributeDocument::dictionary();

        $values = [];
        foreach ($derived as $id => $value) {
            $values[] = ['id' => $id, 'order' => 0, 'value' => $value];
        }
        usort($attributes, static fn (array $a, array $b): int => [$a['id'], $a['order']] <=> [$b['id'], $b['order']]);
        array_push($values, ...$attributes);

        $document = new DOMDocument('1.0', 'UTF-8');
        $root = $document->createElementNS($catalog['namespace'], 'REGZEC');
        $document->appendChild($root);
        $employee = self::child($document, self::child($document, $root, 'employees'), 'employee');
        /** @var array<string,DOMElement> $nodes */
        $nodes = [self::ROOT_PATH => $employee];
        $set = [];
        foreach ($values as $attribute) {
            $path = $dictionary[$attribute['id']]['regzec'] ?? self::OUTSIDE_DICTIONARY[$attribute['id']] ?? null;
            $value = trim((string) $attribute['value']);
            if ($path === null || $value === '' || !str_starts_with($path, self::ROOT_PATH . '.')) {
                continue;
            }
            $segments = explode('.', substr($path, strlen(self::ROOT_PATH) + 1));
            $name = (string) array_pop($segments);
            if (array_intersect($segments, self::SKIPPED) !== []) {
                continue;
            }
            $key = self::ROOT_PATH;
            $schemaKey = self::ROOT_PATH;
            $parent = $employee;
            foreach ($segments as $segment) {
                $schemaKey .= '.' . $segment;
                $key .= '.' . $segment . (in_array($segment, self::REPEATED, true) ? '#' . $attribute['order'] : '');
                $nodes[$key] ??= self::child($document, $parent, $segment);
                $parent = $nodes[$key];
            }
            if (isset($set[$key . '@' . $name])) {
                continue;
            }
            $set[$key . '@' . $name] = true;
            $parent->setAttribute($name, self::value($value, $schema[$schemaKey]['attributes'][$name] ?? 'text'));
        }
        self::sort($employee, self::ROOT_PATH, $schema);

        return $document;
    }

    /** Hodnota v zápisu XSD podle typu atributu (`date`, `flag`, `number`, `text`). */
    public static function value(string $value, string $type): string
    {
        $value = trim($value);
        if ($type === 'date') {
            if (preg_match('/^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})/', $value, $m) === 1) {
                return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
            }
            if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m) === 1) {
                return $m[1];
            }
        }
        if ($type === 'flag') {
            return match (mb_strtolower($value)) {
                'a', 'ano', 'y', 'true', '1' => 'A',
                'n', 'ne', 'false', '0' => 'N',
                default => $value,
            };
        }
        if ($type === 'number') {
            return LocaleNumber::parse($value) ?? $value;
        }

        return $value;
    }

    /** @param array<string,array{children:list<string>,attributes:array<string,string>}> $schema */
    private static function sort(DOMElement $element, string $path, array $schema): void
    {
        $order = array_flip($schema[$path]['children'] ?? []);
        $children = [];
        foreach (iterator_to_array($element->childNodes) as $index => $child) {
            if ($child instanceof DOMElement) {
                self::sort($child, $path . '.' . $child->localName, $schema);
                $children[] = [$order[$child->localName] ?? PHP_INT_MAX, $index, $child];
            }
        }
        usort($children, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        foreach ($children as [, , $child]) {
            $element->appendChild($child);
        }
    }

    /**
     * Elementy a typy atributů pod větou `employee`: cesta => pořadí dětí a typ atributů.
     *
     * @return array<string,array{children:list<string>,attributes:array<string,string>}>
     */
    private static function schema(string $path): array
    {
        if (self::$schema !== null) {
            return self::$schema;
        }
        $document = new DOMDocument();
        $document->load($path);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('xs', self::NS_XSD);
        $types = [];
        foreach ($xpath->query('/xs:schema/xs:complexType[@name]') ?: [] as $type) {
            if ($type instanceof DOMElement) {
                $types[$type->getAttribute('name')] = $type;
            }
        }
        $out = [];
        $walk = static function (DOMElement $type, string $at) use (&$walk, &$out, $xpath, $types): void {
            $children = [];
            foreach ($xpath->query('xs:sequence/xs:element[@name] | xs:sequence/xs:choice/xs:element[@name]', $type) ?: [] as $element) {
                if (!$element instanceof DOMElement) {
                    continue;
                }
                $name = $element->getAttribute('name');
                $children[] = $name;
                $inner = $element->hasAttribute('type')
                    ? ($types[self::local($element->getAttribute('type'))] ?? null)
                    : $xpath->query('xs:complexType', $element)?->item(0);
                if ($inner instanceof DOMElement) {
                    $walk($inner, $at . '.' . $name);
                }
            }
            $attributes = [];
            foreach ($xpath->query('xs:attribute[@name]', $type) ?: [] as $attribute) {
                if ($attribute instanceof DOMElement) {
                    $base = $attribute->getAttribute('type')
                        ?: (string) ($xpath->query('xs:simpleType/xs:restriction/@base', $attribute)?->item(0)?->nodeValue ?? '');
                    $attributes[$attribute->getAttribute('name')] = match (self::local($base)) {
                        'date', 'dateTime' => 'date',
                        'simpleLType' => 'flag',
                        'simpleNType', 'decimal', 'int', 'integer' => 'number',
                        default => 'text',
                    };
                }
            }
            $out[$at] = ['children' => $children, 'attributes' => $attributes];
        };
        $walk($types['employeeType'], self::ROOT_PATH);

        return self::$schema = $out;
    }

    private static function local(string $qualified): string
    {
        $position = strrpos($qualified, ':');

        return $position === false ? $qualified : substr($qualified, $position + 1);
    }

    private static function child(DOMDocument $document, DOMElement $parent, string $name): DOMElement
    {
        $element = $document->createElementNS((string) $document->documentElement?->namespaceURI, $name);
        $parent->appendChild($element);

        return $element;
    }
}
