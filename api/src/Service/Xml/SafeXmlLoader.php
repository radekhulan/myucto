<?php

declare(strict_types=1);

namespace MyInvoice\Service\Xml;

/**
 * Jediné bezpečné načtení nedůvěryhodného XML do DOM.
 *
 * Proč nestačí `loadXML()` s kontrolou DOCTYPE regexem nad surovými bajty:
 *
 *   1. DOCTYPE v UTF-16 (mezi znaky nulové bajty) regex nevidí, libxml ho
 *      přesto načte. Rozhoduje proto uzel DOCTYPE, jak ho vidí parser
 *      (XMLReader), a po načtení ještě `$dom->doctype`.
 *   2. Velikost vstupu neříká nic o ceně DOM stromu. Plochý dokument `<a/>x…`
 *      má jeden uzel na pár bajtů a libxml2 na každý alokuje ~100+ B mimo
 *      `memory_limit` PHP. 20 MiB takového XML tak stojí přes 1 GB nativní
 *      paměti. Počet uzlů se proto spočítá proudově (XMLReader drží jen
 *      aktuální uzel) DŘÍV, než se postaví strom, a nad strop se nestaví vůbec.
 *
 * Bílé mezery mezi elementy se do stromu nenačítají (LIBXML_NOBLANKS), a proto
 * se ani nepočítají. UTF-8 BOM i deklarované kódování řeší libxml jako dřív.
 */
final class SafeXmlLoader
{
    /**
     * Výchozí strop uzlů (elementy, atributy, text). Legitimní 20 MiB ISDOC
     * (~35 tisíc řádků) má ~850 tisíc uzlů a DOM kolem 200 MB; libxml2 stojí
     * ~130 B na uzel, takže strop drží nejhorší případ zhruba tam, místo GB.
     */
    public const DEFAULT_MAX_NODES = 1_500_000;

    /**
     * @param int $maxBytes Strop velikosti vstupu podle formátu.
     * @param int $options  Další LIBXML_* volby (NONET, NOBLANKS a COMPACT jsou vždy).
     * @throws UnsafeXmlException
     */
    public static function load(
        string $xml,
        int $maxBytes,
        int $options = 0,
        int $maxNodes = self::DEFAULT_MAX_NODES,
    ): \DOMDocument {
        if (strlen($xml) > $maxBytes) {
            throw new UnsafeXmlException(UnsafeXmlException::TOO_LARGE, 'XML je příliš velké.');
        }
        if (trim($xml) === '') {
            throw new UnsafeXmlException(UnsafeXmlException::INVALID, 'XML je prázdné.');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            self::scan($xml, $maxNodes);

            $dom = new \DOMDocument();
            $dom->preserveWhiteSpace = false;
            $dom->resolveExternals = false;
            $dom->substituteEntities = false;
            $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT | $options);
            if (!$loaded || $dom->documentElement === null) {
                throw new UnsafeXmlException(UnsafeXmlException::INVALID, 'XML není platný dokument.', self::firstError());
            }
            // Pojistka nad tím, co parser opravdu načetl (stejná kontrola jako při průchodu).
            if ($dom->doctype !== null) {
                throw new UnsafeXmlException(UnsafeXmlException::DOCTYPE, 'XML obsahuje DOCTYPE, což není povoleno.');
            }
            return $dom;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Proudový průchod bez stavby stromu: odmítne DOCTYPE v jakémkoli kódování
     * a dokument s víc uzly, než unese strop. Chybu syntaxe nechává na loadXML().
     */
    private static function scan(string $xml, int $maxNodes): void
    {
        $reader = new \XMLReader();
        if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)) {
            throw new UnsafeXmlException(UnsafeXmlException::INVALID, 'XML není platný dokument.', self::firstError());
        }
        try {
            $nodes = 0;
            while (@$reader->read()) {
                $type = $reader->nodeType;
                if ($type === \XMLReader::DOC_TYPE) {
                    throw new UnsafeXmlException(UnsafeXmlException::DOCTYPE, 'XML obsahuje DOCTYPE, což není povoleno.');
                }
                // Ignorovatelné mezery DOM s LIBXML_NOBLANKS zahodí; ty pod
                // xml:space="preserve" (SIGNIFICANT_WHITESPACE) ale zůstanou, počítají se.
                if ($type === \XMLReader::END_ELEMENT || $type === \XMLReader::WHITESPACE) {
                    continue;
                }
                $nodes++;
                if ($type === \XMLReader::ELEMENT) {
                    $nodes += $reader->attributeCount;
                }
                if ($nodes > $maxNodes) {
                    throw new UnsafeXmlException(
                        UnsafeXmlException::TOO_MANY_NODES,
                        'XML má příliš mnoho uzlů na bezpečné zpracování.',
                    );
                }
            }
        } finally {
            $reader->close();
        }
    }

    private static function firstError(): string
    {
        $errors = libxml_get_errors();
        return $errors !== [] ? trim($errors[0]->message) : '';
    }
}
