<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

/**
 * Storno jedné součásti individualizované části: konkrétní pracovněprávní
 * vztah se v už podaném hlášení zneplatňuje.
 *
 * GUID součásti je PŮVODNÍ GUID z řádného podání — na ten se storno referuje.
 * Nový GUID se zakládá jen tehdy, když původní součást platná nikdy nebyla
 * (byla zamítnutá nebo sama stornovaná); o tom rozhoduje
 * `JmhzSubmissionGuidPolicy`, ne tenhle objekt.
 */
final readonly class JmhzComponentCancellation
{
    private function __construct(
        public string $formGuid,
        public string $personExternalIdentifier,
        public string $employmentExternalIdentifier,
    ) {}

    public static function create(
        string $formGuid,
        string $personExternalIdentifier,
        string $employmentExternalIdentifier,
    ): self {
        if (preg_match(
            '/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/D',
            $formGuid,
        ) !== 1) {
            throw new JmhzXmlException(
                'jmhz_envelope_guid_invalid',
                'GUID stornované součásti musí být kanonický UUID.',
            );
        }
        if (preg_match('/^\d{10}$/D', $personExternalIdentifier) !== 1) {
            throw new JmhzXmlException(
                'jmhz_cancellation_person_invalid',
                'IK MPSV stornované součásti musí mít deset číslic.',
            );
        }
        if (preg_match('/^\d{1,22}$/D', $employmentExternalIdentifier) !== 1) {
            throw new JmhzXmlException(
                'jmhz_cancellation_employment_invalid',
                'ID pracovněprávního vztahu stornované součásti není platné.',
            );
        }

        return new self(
            strtoupper($formGuid),
            $personExternalIdentifier,
            $employmentExternalIdentifier,
        );
    }
}
