<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

/**
 * Čte uložené XML písemnosti EPO (`<Pisemnost><DPHDP3>…`) věrně tak, jak odešlo:
 * kód tiskopisu a všechny věty v pořadí dokumentu se všemi atributy jako text.
 *
 * Nic nepřepočítává ani neformátuje — tisk podaného výkazu má odpovídat XML, ne
 * aktuálním datům. Na rozdíl od {@see KhSubmittedXmlReader}, který z KH bere jen
 * částky pro porovnání po dokladech, se tady nevynechává žádná věta ani atribut.
 */
final class SubmittedEpoXmlReader
{
    /**
     * @return array{form:string, version:?string, records:list<array{name:string, attrs:array<string,string>}>}
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
            throw new \RuntimeException('Uložené XML podání nelze načíst.');
        }

        $form = $doc->getName() === 'Pisemnost' ? ($doc->children()[0] ?? null) : $doc;
        if (!$form instanceof \SimpleXMLElement || $form->getName() === '') {
            throw new \RuntimeException('Uložené XML neobsahuje tiskopis.');
        }

        $records = [];
        foreach ($form->children() as $child) {
            $attrs = [];
            foreach ($child->attributes() as $name => $value) {
                $attrs[(string) $name] = (string) $value;
            }
            $records[] = ['name' => $child->getName(), 'attrs' => $attrs];
        }
        $version = isset($form['verzePis']) ? (string) $form['verzePis'] : null;

        return ['form' => $form->getName(), 'version' => $version, 'records' => $records];
    }
}
