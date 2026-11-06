<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Xml2027;

use DOMDocument;
use DOMElement;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecVersion;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionEnvelope;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;

/**
 * Jmenné prostory a kořen datové věty měsíčního hlášení ve verzi 2027
 * (XSD 1.5.0.3, `api/xsd/jmhz/jmhz-1.5.0.3`).
 *
 * Proti verzi 2026 se mění všechny čtyři prostory (`.../2027`), přibyl
 * `simpleTypes/2027`, který nese jen typy (žádný element), a formulář
 * zaměstnání včetně své hlavičky leží v prostoru `form`, ne `podani`.
 * Prefixy se deklarují na kořeni stejně jako u verze 2026.
 */
final class Jmhz2027Schema
{
    public const NS_PODANI = JmhzSchemaCatalog::NS_PODANI_2027;
    public const NS_FORM = JmhzSchemaCatalog::NS_FORM_2027;
    public const NS_SOUHRN = 'http://schemas.cssz.cz/JMHZ/souhrn/2027';
    public const NS_PVPOJ = 'http://schemas.cssz.cz/JMHZ/PVPOJ/2027';

    private const XMLNS = 'http://www.w3.org/2000/xmlns/';

    /** Verze datové věty (`jmhz/@verze`) podle připnutého balíku 2027. */
    public static function dataVersion(): string
    {
        return (new JmhzSchemaCatalog())->entryPointFor(JmhzSpecVersion::V2027)['data_version'];
    }

    /**
     * Kořen `jmhz` s deklaracemi prefixů a blokem `VENDOR` (`vendorType`).
     *
     * `SENDER` (`senderType` s novým `VlastniIdPodani`) se nepíše ze stejného
     * důvodu jako ve verzi 2026: DIS ho nezpracovává a aplikace e-mailovou
     * notifikaci u JMHZ nesbírá.
     */
    public static function root(DOMDocument $dom, JmhzSubmissionEnvelope $envelope): DOMElement
    {
        $root = $dom->createElementNS(self::NS_PODANI, 'jmhz');
        $dom->appendChild($root);
        $root->setAttribute('verze', self::dataVersion());
        foreach ([
            'xmlns:so' => self::NS_SOUHRN,
            'xmlns:pvpoj' => self::NS_PVPOJ,
            'xmlns:form' => self::NS_FORM,
        ] as $name => $namespace) {
            $root->setAttributeNS(self::XMLNS, $name, $namespace);
        }
        $vendor = $dom->createElementNS(self::NS_PODANI, 'VENDOR');
        $vendor->setAttribute('productName', $envelope->productName);
        $vendor->setAttribute('productVersion', $envelope->productVersion);
        $root->appendChild($vendor);

        return $root;
    }

    public static function save(DOMDocument $dom, string $message): string
    {
        $dom->formatOutput = true;
        $xml = $dom->saveXML();
        if ($xml === false) {
            throw new JmhzXmlException('jmhz_xml_serialization_failed', $message);
        }

        return rtrim($xml, "\r\n");
    }

    /**
     * Datová věta 2027 smí vzniknout jen za období verze 2027. Hlášení za
     * 12/2026 ve tvaru 2027 by ČSSZ odmítla stejně jako opačný případ.
     */
    public static function assertPeriod(int $year, int $month): void
    {
        if (JmhzSpecVersion::forPeriod($year, $month) !== JmhzSpecVersion::V2027) {
            throw new JmhzXmlException(
                'jmhz_spec_version_mismatch',
                sprintf(
                    'Za období %d/%d se podává %s, datovou větu 2027 pro něj sestavit nelze.',
                    $month,
                    $year,
                    JmhzSpecVersion::forPeriod($year, $month)->label(),
                ),
            );
        }
    }
}
