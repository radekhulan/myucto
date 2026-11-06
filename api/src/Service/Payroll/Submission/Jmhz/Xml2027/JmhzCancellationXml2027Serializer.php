<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Xml2027;

use DOMDocument;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCancellationRequest;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzComponentCancellation;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPackageSplitter;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionEnvelope;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionFlagMatrix;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;

/**
 * Storno celého podání (typ S) a storno součástí v opravném podání (typ O se
 * součástmi typu S) ve verzi 2027.
 *
 * Pravidla jsou stejná jako ve verzi 2026
 * ({@see \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCancellationXmlSerializer},
 * {@see \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzComponentCancellationXmlSerializer}),
 * jen tvar: jmenné prostory 2027 a stornující součást je
 * `formulareZamestnani/form:formularZamestnani` s hlavičkou v prostoru `form`.
 * Verzi určuje rozhodné období storna, takže storno za 12/2026 podané v roce
 * 2027 jde dál verzí 2026 a sem se nedostane.
 */
final class JmhzCancellationXml2027Serializer
{
    public function __construct(
        private readonly JmhzPackageSplitter $packageSplitter = new JmhzPackageSplitter(),
    ) {}

    public function serialize(JmhzCancellationRequest $request, JmhzSubmissionEnvelope $envelope): string
    {
        Jmhz2027Schema::assertPeriod($request->year, $request->month);
        JmhzSubmissionFlagMatrix::assertAllowed(JmhzSubmissionFlagMatrix::TYPE_CANCELLATION, false, false, []);
        if ($envelope->formGuids !== []) {
            throw new JmhzXmlException(
                'jmhz_cancellation_has_no_forms',
                'Stornující podání neobsahuje součásti, takže pro ně GUID nevzniká.',
            );
        }
        if (!hash_equals($request->regularSubmissionGuid, $envelope->submissionGuid)) {
            throw new JmhzXmlException(
                'jmhz_cancellation_guid_mismatch',
                'Stornující podání se musí vázat na GUID rušeného řádného podání.',
            );
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $root = Jmhz2027Schema::root($dom, $envelope);
        $header = $dom->createElementNS(Jmhz2027Schema::NS_PODANI, 'hlavicka');
        $root->appendChild($header);
        foreach ([
            'idPodani' => $request->regularSubmissionGuid,
            'typPodani' => JmhzSubmissionFlagMatrix::TYPE_CANCELLATION,
            'variabilniSymbol' => $request->variableSymbol,
            'mesic' => (string) $request->month,
            'rok' => (string) $request->year,
            'datumVyplneni' => $envelope->filledAt,
        ] as $name => $value) {
            $header->appendChild($dom->createElementNS(Jmhz2027Schema::NS_PODANI, $name, $value));
        }

        return Jmhz2027Schema::save($dom, 'XML stornujícího podání nelze serializovat.');
    }

    /**
     * @param list<JmhzComponentCancellation> $cancellations
     * @return list<string>
     */
    public function serializeComponentPackages(
        JmhzCancellationRequest $request,
        array $cancellations,
        JmhzSubmissionEnvelope $envelope,
    ): array {
        Jmhz2027Schema::assertPeriod($request->year, $request->month);
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
            $packages[] = $this->componentPackage(
                $request,
                $chunk,
                $envelope->forPackage($index + 1, count($chunks)),
                count($cancellations),
            );
        }

        return $packages;
    }

    /** @param list<JmhzComponentCancellation> $cancellations */
    private function componentPackage(
        JmhzCancellationRequest $request,
        array $cancellations,
        JmhzSubmissionEnvelope $envelope,
        int $total,
    ): string {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $root = Jmhz2027Schema::root($dom, $envelope);
        $header = $dom->createElementNS(Jmhz2027Schema::NS_PODANI, 'hlavicka');
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
            'formularePocetVBaliku' => (string) count($cancellations),
            'formularePocetCelkem' => (string) $total,
        ] as $name => $value) {
            $header->appendChild($dom->createElementNS(Jmhz2027Schema::NS_PODANI, $name, $value));
        }
        $forms = $dom->createElementNS(Jmhz2027Schema::NS_PODANI, 'formulareZamestnani');
        $root->appendChild($forms);
        foreach ($cancellations as $cancellation) {
            $form = $dom->createElementNS(Jmhz2027Schema::NS_FORM, 'form:formularZamestnani');
            $forms->appendChild($form);
            $formHeader = $dom->createElementNS(Jmhz2027Schema::NS_FORM, 'form:hlavicka');
            $form->appendChild($formHeader);
            $formHeader->appendChild($dom->createElementNS(
                Jmhz2027Schema::NS_FORM,
                'form:idFormulare',
                $cancellation->formGuid,
            ));
            $formHeader->appendChild($dom->createElementNS(
                Jmhz2027Schema::NS_FORM,
                'form:typFormulare',
                JmhzSubmissionFlagMatrix::TYPE_CANCELLATION,
            ));
        }

        return Jmhz2027Schema::save($dom, 'XML opravného podání nelze serializovat.');
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
