<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

/**
 * Identita podání přečtená ZE ZMRAZENÉHO XML.
 *
 * Proč ne z databáze: GovTalk obálka vyžaduje shodu variabilního symbolu
 * s hlavičkou datové věty a storno se váže na GUID, který ČSSZ opravdu dostala.
 * Kdyby se tyhle hodnoty dohledávaly jinde, mohla by se shoda tiše rozejít —
 * a rozejde se přesně ve chvíli, kdy na tom záleží (dotaz na stav pod cizím
 * symbolem, storno podání, které u ČSSZ neexistuje).
 *
 * Čte se jen hlavička; zbytek dokumentu tahle vrstva nezajímá.
 */
final readonly class JmhzFrozenSubmissionIdentity
{
    private function __construct(
        public string $submissionGuid,
        public string $variableSymbol,
        public int $month,
        public int $year,
        public string $submissionType,
    ) {}

    public static function read(string $xml): self
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new JmhzXmlException(
                'jmhz_submission_frozen_xml_unreadable',
                'Zmrazené XML podání JMHZ nelze načíst.',
            );
        }
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('p', JmhzSchemaCatalog::NS_PODANI);

        $guid = self::textAt($xpath, '/p:jmhz/p:hlavicka/p:idPodani');
        $variableSymbol = self::textAt($xpath, '/p:jmhz/p:hlavicka/p:variabilniSymbol');
        if (preg_match(
            '/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/D',
            $guid,
        ) !== 1
            || preg_match('/^\d{10}$/D', $variableSymbol) !== 1
        ) {
            throw new JmhzXmlException(
                'jmhz_submission_frozen_identity_invalid',
                'Zmrazené podání JMHZ nenese platný GUID nebo variabilní symbol.',
            );
        }
        $month = self::textAt($xpath, '/p:jmhz/p:hlavicka/p:mesic');
        $year = self::textAt($xpath, '/p:jmhz/p:hlavicka/p:rok');
        if (preg_match('/^([1-9]|1[0-2])$/D', $month) !== 1
            || preg_match('/^[12][0-9]{3}$/D', $year) !== 1
        ) {
            throw new JmhzXmlException(
                'jmhz_submission_frozen_period_invalid',
                'Zmrazené podání JMHZ nenese platné rozhodné období.',
            );
        }

        return new self(
            strtoupper($guid),
            $variableSymbol,
            (int) $month,
            (int) $year,
            self::textAt($xpath, '/p:jmhz/p:hlavicka/p:typPodani'),
        );
    }

    private static function textAt(\DOMXPath $xpath, string $path): string
    {
        $nodes = $xpath->query($path);
        $node = $nodes === false ? null : $nodes->item(0);
        if ($node === null) {
            throw new JmhzXmlException(
                'jmhz_submission_frozen_identity_missing',
                "Zmrazené podání JMHZ neobsahuje {$path}.",
            );
        }

        return trim($node->textContent);
    }
}
