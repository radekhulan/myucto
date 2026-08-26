<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final readonly class JmhzContentCorrectionPlan
{
    /**
     * @param list<JmhzContentCorrectionForm> $forms
     * @param array<int,JmhzContentCorrectionForm> $byEmploymentId
     */
    private function __construct(
        public array $forms,
        private array $byEmploymentId,
        public bool $includeSummary,
        public bool $includePvpoj,
    ) {}

    /** @param array<array-key,mixed> $forms */
    public static function create(array $forms): self
    {
        if ($forms === []) {
            throw new JmhzXmlException(
                'jmhz_content_correction_without_forms',
                'Obsahová oprava musí obsahovat alespoň jeden úplný formulář.',
            );
        }
        $byEmploymentId = [];
        $normalizedForms = [];
        $includeSummary = false;
        $includePvpoj = false;
        foreach ($forms as $form) {
            if (!$form instanceof JmhzContentCorrectionForm) {
                throw new \InvalidArgumentException(
                    'Plán obsahové opravy smí obsahovat jen formuláře JMHZ.',
                );
            }
            if (isset($byEmploymentId[$form->employmentId])) {
                throw new JmhzXmlException(
                    'jmhz_content_correction_duplicate_employment',
                    'Tentýž pracovněprávní vztah je v obsahové opravě uveden víc než jednou.',
                );
            }
            $normalizedForms[] = $form;
            $byEmploymentId[$form->employmentId] = $form;
            $includeSummary = $includeSummary || $form->affectsSummary;
            $includePvpoj = $includePvpoj || $form->affectsPvpoj;
        }
        JmhzSubmissionFlagMatrix::assertAllowed(
            JmhzSubmissionFlagMatrix::TYPE_AMENDMENT,
            $includeSummary,
            $includePvpoj,
            array_map(
                static fn (JmhzContentCorrectionForm $form): string => $form->formType,
                $normalizedForms,
            ),
        );

        return new self(
            $normalizedForms,
            $byEmploymentId,
            $includeSummary,
            $includePvpoj,
        );
    }

    public function formForEmployment(int $employmentId): ?JmhzContentCorrectionForm
    {
        return $this->byEmploymentId[$employmentId] ?? null;
    }
}
