<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use DOMDocument;
use MyInvoice\Service\Payroll\Submission\Jmhz\Xml2027\JmhzCancellationXml2027Serializer;

/**
 * Opravné podání, které pouze stornuje součásti individualizované části:
 * konkrétní pracovněprávní vztahy se v už podaném hlášení zneplatňují.
 *
 * Je to druhá polovina časově kritické cesty. Storno celého podání
 * (`JmhzCancellationXmlSerializer`) ruší za období všechno; tohle ruší jen
 * vyjmenované vztahy a zbytek hlášení nechává platný.
 *
 * Tvar plyne z pravidel podání a z katalogu kontrol:
 *
 * - podání je typu **O** a váže se na GUID řádného podání (kap. 9),
 * - součást je typu **S** a nese POUZE hlavičku, ne datovou část (kontrola 237),
 * - GUID součásti je PŮVODNÍ GUID z řádného podání — na ten se storno referuje,
 * - v jednom opravném hlášení nelze u téhož vztahu kombinovat storno a nový
 *   řádný formulář (kap. 4).
 *
 * **Co tahle vrstva ověřit nemůže:** kontrolu 211, tedy že po stornu zbude
 * aspoň jedna platná součást. To se rozhoduje nad všemi podáními za období,
 * ne nad jedním XML. Nezbude-li žádná, musí se místo toho stornovat celé podání.
 */
final class JmhzComponentCancellationXmlSerializer
{
    private const XMLNS = 'http://www.w3.org/2000/xmlns/';

    public function __construct(
        private readonly JmhzPackageSplitter $packageSplitter = new JmhzPackageSplitter(),
        private readonly ?JmhzCancellationXml2027Serializer $serializer2027 = null,
    ) {}

    /**
     * Storno součástí v jediném balíku; nad limit balíku viz
     * {@see self::serializePackages()}.
     *
     * @param list<JmhzComponentCancellation> $cancellations
     */
    public function serialize(
        JmhzCancellationRequest $request,
        array $cancellations,
        JmhzSubmissionEnvelope $envelope,
    ): string {
        $packages = $this->serializePackages($request, $cancellations, $envelope);
        if (count($packages) !== 1) {
            throw new JmhzXmlException(
                'jmhz_xml_form_limit_exceeded',
                'Nad 1500 stornovaných součástí se opravné hlášení dělí do dílčích balíků'
                    . ' (serializePackages).',
            );
        }

        return $packages[0];
    }

    /**
     * Storno součástí rozdělené do dílčích balíků. Pravidla podání JMHZ 1.4.5,
     * kap. 3: opravné hlášení s více než 1500 opravenými nebo stornovanými
     * součástmi se dělí do více dílčích podání; každý balík nese GUID řádného
     * podání a týž čas vyplnění, liší se pořadím a počtem součástí.
     *
     * @param list<JmhzComponentCancellation> $cancellations
     * @return list<string>
     */
    public function serializePackages(
        JmhzCancellationRequest $request,
        array $cancellations,
        JmhzSubmissionEnvelope $envelope,
    ): array {
        if (JmhzCancellationXmlSerializer::isVersion2027($request)) {
            return ($this->serializer2027 ?? new JmhzCancellationXml2027Serializer($this->packageSplitter))
                ->serializeComponentPackages($request, $cancellations, $envelope);
        }
        if ($cancellations === []) {
            throw new JmhzXmlException(
                'jmhz_amendment_without_components',
                'Opravné podání bez jediné součásti neopravuje nic.',
            );
        }
        JmhzSubmissionFlagMatrix::assertAllowed(
            JmhzSubmissionFlagMatrix::TYPE_AMENDMENT,
            false,
            false,
            array_fill(0, count($cancellations), JmhzSubmissionFlagMatrix::TYPE_CANCELLATION),
        );
        $this->assertDistinct($cancellations);
        if (!hash_equals($request->regularSubmissionGuid, $envelope->submissionGuid)) {
            throw new JmhzXmlException(
                'jmhz_cancellation_guid_mismatch',
                'Opravné podání se musí vázat na GUID opravovaného řádného podání.',
            );
        }

        $chunks = $this->packageSplitter->split($cancellations);
        $packages = [];
        foreach ($chunks as $index => $chunk) {
            $packages[] = $this->package(
                $request,
                $chunk,
                $envelope->forPackage($index + 1, count($chunks)),
                count($cancellations),
            );
        }

        return $packages;
    }

    /** @param list<JmhzComponentCancellation> $cancellations */
    private function package(
        JmhzCancellationRequest $request,
        array $cancellations,
        JmhzSubmissionEnvelope $envelope,
        int $total,
    ): string {
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

        $count = count($cancellations);
        $header = $dom->createElementNS(JmhzSchemaCatalog::NS_PODANI, 'hlavicka');
        $root->appendChild($header);
        foreach ([
            'idPodani' => $request->regularSubmissionGuid,
            'typPodani' => JmhzSubmissionFlagMatrix::TYPE_AMENDMENT,
            'variabilniSymbol' => $request->variableSymbol,
            'mesic' => (string) $request->month,
            'rok' => (string) $request->year,
            'datumVyplneni' => $envelope->filledAt,
            'balikPoradi' => (string) $envelope->packageOrdinal,
            'balikyPocet' => (string) $envelope->packageCount,
            'formularePocetVBaliku' => (string) $count,
            'formularePocetCelkem' => (string) $total,
        ] as $name => $value) {
            $header->appendChild(
                $dom->createElementNS(JmhzSchemaCatalog::NS_PODANI, $name, $value),
            );
        }

        $forms = $dom->createElementNS(JmhzSchemaCatalog::NS_PODANI, 'formulareOsob');
        $root->appendChild($forms);
        foreach ($cancellations as $cancellation) {
            $form = $dom->createElementNS(JmhzSchemaCatalog::NS_PODANI, 'formularOsoby');
            $forms->appendChild($form);
            $formHeader = $dom->createElementNS(JmhzSchemaCatalog::NS_PODANI, 'hlavicka');
            $form->appendChild($formHeader);
            // Stornující součást nese jen hlavičku. Datová část by tvrdila, že
            // se něco vykazuje, přitom se ruší.
            $formHeader->appendChild($dom->createElementNS(
                JmhzSchemaCatalog::NS_PODANI,
                'idFormulare',
                $cancellation->formGuid,
            ));
            $formHeader->appendChild($dom->createElementNS(
                JmhzSchemaCatalog::NS_PODANI,
                'typFormulare',
                JmhzSubmissionFlagMatrix::TYPE_CANCELLATION,
            ));
        }

        $xml = $dom->saveXML();
        if ($xml === false) {
            throw new JmhzXmlException(
                'jmhz_xml_serialization_failed',
                'XML opravného podání nelze serializovat.',
            );
        }

        return rtrim($xml, "\r\n");
    }

    /** @param list<JmhzComponentCancellation> $cancellations */
    private function assertDistinct(array $cancellations): void
    {
        $guids = [];
        $employments = [];
        foreach ($cancellations as $cancellation) {
            if (isset($guids[$cancellation->formGuid])) {
                throw new JmhzXmlException(
                    'jmhz_cancellation_duplicate_form',
                    'Tatáž součást je v opravném podání stornovaná víc než jednou.',
                );
            }
            $guids[$cancellation->formGuid] = true;
            // Kap. 4: u téhož vztahu nelze v jednom opravném hlášení kombinovat
            // storno s dalším formulářem. Dvě storna téhož vztahu jsou tentýž
            // rozpor, jen méně nápadný.
            if (isset($employments[$cancellation->employmentExternalIdentifier])) {
                throw new JmhzXmlException(
                    'jmhz_cancellation_duplicate_employment',
                    'Tentýž pracovněprávní vztah je v opravném podání uveden víc než jednou.',
                );
            }
            $employments[$cancellation->employmentExternalIdentifier] = true;
        }
    }
}
