<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final readonly class JmhzContentCorrectionForm
{
    private const UUID_V7_PATTERN =
        '/^[0-9A-F]{8}-[0-9A-F]{4}-7[0-9A-F]{3}-[0-9A-F]{4}-[0-9A-F]{12}$/D';
    private const UUID_PATTERN =
        '/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/D';

    private function __construct(
        public int $employmentId,
        public string $formType,
        public string $previousState,
        public ?string $sourceFormGuid,
        public bool $affectsSummary,
        public bool $affectsPvpoj,
    ) {
        if ($employmentId <= 0) {
            throw new JmhzXmlException(
                'jmhz_content_correction_employment_invalid',
                'Obsahová oprava musí být svázaná s konkrétním pracovním vztahem.',
            );
        }
        (new JmhzSubmissionGuidPolicy())->forForm(
            JmhzSubmissionFlagMatrix::TYPE_AMENDMENT,
            $formType,
            $previousState,
        );
        if ($sourceFormGuid !== null
            && preg_match(self::UUID_PATTERN, $sourceFormGuid) !== 1
        ) {
            throw new JmhzXmlException(
                'jmhz_content_correction_source_guid_invalid',
                'Původní GUID opravovaného formuláře musí být kanonický UUID.',
            );
        }
    }

    public static function amendAccepted(
        int $employmentId,
        string $sourceFormGuid,
        bool $affectsSummary,
        bool $affectsPvpoj,
    ): self {
        return new self(
            $employmentId,
            JmhzSubmissionFlagMatrix::TYPE_AMENDMENT,
            JmhzSubmissionGuidPolicy::FORM_ACCEPTED,
            strtoupper(trim($sourceFormGuid)),
            $affectsSummary,
            $affectsPvpoj,
        );
    }

    public static function replaceMissing(
        int $employmentId,
        bool $affectsSummary,
        bool $affectsPvpoj,
    ): self {
        return self::replacement(
            $employmentId,
            JmhzSubmissionGuidPolicy::FORM_NONE,
            $affectsSummary,
            $affectsPvpoj,
        );
    }

    public static function replaceRejected(
        int $employmentId,
        bool $affectsSummary,
        bool $affectsPvpoj,
    ): self {
        return self::replacement(
            $employmentId,
            JmhzSubmissionGuidPolicy::FORM_REJECTED,
            $affectsSummary,
            $affectsPvpoj,
        );
    }

    public static function replaceCancelled(
        int $employmentId,
        bool $affectsSummary,
        bool $affectsPvpoj,
    ): self {
        return self::replacement(
            $employmentId,
            JmhzSubmissionGuidPolicy::FORM_CANCELLED,
            $affectsSummary,
            $affectsPvpoj,
        );
    }

    public function assertEnvelopeGuid(string $formGuid): void
    {
        $formGuid = strtoupper($formGuid);
        $strategy = (new JmhzSubmissionGuidPolicy())->forForm(
            JmhzSubmissionFlagMatrix::TYPE_AMENDMENT,
            $this->formType,
            $this->previousState,
        );
        if ($strategy === JmhzSubmissionGuidPolicy::ORIGINAL_FORM_GUID) {
            if ($this->sourceFormGuid === null
                || !hash_equals($this->sourceFormGuid, $formGuid)
            ) {
                throw new JmhzXmlException(
                    'jmhz_content_correction_form_guid_changed',
                    'Oprava přijatého formuláře musí použít jeho původní GUID.',
                );
            }

            return;
        }
        if (preg_match(self::UUID_V7_PATTERN, $formGuid) !== 1) {
            throw new JmhzXmlException(
                'jmhz_content_correction_new_guid_invalid',
                'Nově podávaný formulář typu R musí dostat nový UUIDv7.',
            );
        }
    }

    private static function replacement(
        int $employmentId,
        string $previousState,
        bool $affectsSummary,
        bool $affectsPvpoj,
    ): self {
        return new self(
            $employmentId,
            JmhzSubmissionFlagMatrix::TYPE_REGULAR,
            $previousState,
            null,
            $affectsSummary,
            $affectsPvpoj,
        );
    }
}
