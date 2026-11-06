<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use DOMDocument;
use MyInvoice\Service\Payroll\Submission\Jmhz\Xml2027\JmhzCancellationXml2027Serializer;

/**
 * Stornující podání (typ „S") — zneplatnění všech dosud podaných hlášení
 * za rozhodné období.
 *
 * Je to nejmenší podání, jaké JMHZ zná: pouze metadatová hlavička, žádná
 * souhrnná ani pojistná část a žádná součást. Počty balíků a formulářů se
 * neuvádějí, protože se nic neposílá. Právě proto má vlastní serializér —
 * do serializéru běžného hlášení by to přineslo větev, která nic nestaví.
 *
 * Tři vlastnosti, které se nesmí ztratit:
 *
 * 1. **Storno se váže na GUID řádného podání a tím ten GUID zaniká.** Nové
 *    řádné podání za totéž období proto musí dostat nový GUID; o tom rozhoduje
 *    `JmhzSubmissionGuidPolicy`, ne tenhle serializér.
 * 2. **Stornovat lze jen do 20. dne** měsíce následujícího po hlášeném období,
 *    s posunem na pracovní den. Lhůtu drží `JmhzDeadlinePolicy`.
 * 3. **Storno ruší VŠECHNA podání za období**, ne jen poslední. Potvrzení
 *    v UI proto musí vypsat, co všechno zaniká.
 */
final class JmhzCancellationXmlSerializer
{
    private const XMLNS = 'http://www.w3.org/2000/xmlns/';

    public function __construct(
        private readonly JmhzCancellationXml2027Serializer $serializer2027 = new JmhzCancellationXml2027Serializer(),
    ) {}

    /** Storno za období verze 2027 staví serializér 2027; o verzi rozhoduje období. */
    public static function isVersion2027(JmhzCancellationRequest $request): bool
    {
        return $request->year >= 2000 && $request->year <= 2999
            && JmhzSpecVersion::forPeriod($request->year, $request->month) === JmhzSpecVersion::V2027;
    }

    public function serialize(
        JmhzCancellationRequest $request,
        JmhzSubmissionEnvelope $envelope,
    ): string {
        if (self::isVersion2027($request)) {
            return $this->serializer2027->serialize($request, $envelope);
        }
        JmhzSubmissionFlagMatrix::assertAllowed(
            JmhzSubmissionFlagMatrix::TYPE_CANCELLATION,
            false,
            false,
            [],
        );
        if ($envelope->formGuids !== []) {
            throw new JmhzXmlException(
                'jmhz_cancellation_has_no_forms',
                'Stornující podání neobsahuje součásti, takže pro ně GUID nevzniká.',
            );
        }
        // GUID storna JE GUID řádného podání, které se ruší. Vygenerovat nový
        // by znamenalo stornovat něco, co u ČSSZ neexistuje.
        if (!hash_equals($request->regularSubmissionGuid, $envelope->submissionGuid)) {
            throw new JmhzXmlException(
                'jmhz_cancellation_guid_mismatch',
                'Stornující podání se musí vázat na GUID rušeného řádného podání.',
            );
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $root = $dom->createElementNS(JmhzSchemaCatalog::NS_PODANI, 'jmhz');
        $dom->appendChild($root);
        $root->setAttribute(
            'verze',
            (new JmhzSchemaCatalog())->entryPoint()['data_version'],
        );
        foreach ([
            'xmlns:so' => JmhzSchemaCatalog::NS_SOUHRN,
            'xmlns:pvpoj' => JmhzSchemaCatalog::NS_PVPOJ,
            'xmlns:form' => JmhzSchemaCatalog::NS_FORM,
        ] as $name => $namespace) {
            $root->setAttributeNS(self::XMLNS, $name, $namespace);
        }

        $vendor = $dom->createElementNS(JmhzSchemaCatalog::NS_PODANI, 'VENDOR');
        $vendor->setAttribute('productName', $envelope->productName);
        $vendor->setAttribute('productVersion', $envelope->productVersion);
        $root->appendChild($vendor);

        $header = $dom->createElementNS(JmhzSchemaCatalog::NS_PODANI, 'hlavicka');
        $root->appendChild($header);
        foreach ([
            'idPodani' => $request->regularSubmissionGuid,
            'typPodani' => JmhzSubmissionFlagMatrix::TYPE_CANCELLATION,
            'variabilniSymbol' => $request->variableSymbol,
            'mesic' => (string) $request->month,
            'rok' => (string) $request->year,
            'datumVyplneni' => $envelope->filledAt,
        ] as $name => $value) {
            $header->appendChild(
                $dom->createElementNS(JmhzSchemaCatalog::NS_PODANI, $name, $value),
            );
        }

        $xml = $dom->saveXML();
        if ($xml === false) {
            throw new JmhzXmlException(
                'jmhz_xml_serialization_failed',
                'XML stornujícího podání nelze serializovat.',
            );
        }

        return rtrim($xml, "\r\n");
    }
}
