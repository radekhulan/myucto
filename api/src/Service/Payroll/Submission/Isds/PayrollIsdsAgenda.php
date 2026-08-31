<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Isds;

use MyInvoice\Service\Submission\Channel\SubmissionChannelException;

/**
 * Jedna mzdová agenda, u které je kanál datové schránky DOLOŽENÝ až do tvaru
 * zprávy: kód v evidenci, lidský název do věci a adresát pro obě prostředí.
 *
 * Adresát je tu dvakrát — kódem do číselníku `submission_recipients` A doloženým
 * ID schránky. Číselník je editovatelný (aby šlo doplnit místně příslušnou
 * OSSZ), takže se na něj u mzdových údajů nespoléhá slepě: přepsané ID podání
 * zastaví, místo aby ho poslalo jinam. Sedm znaků ID schránky nemá kontrolní
 * číslici, takže překlep se neodhalí ničím jiným než porovnáním se zdrojem.
 */
final readonly class PayrollIsdsAgenda
{
    public function __construct(
        /** `agenda_code` v `payroll_obligations` i v `submission_outbox`. */
        public string $code,
        /** Lidský název do věci datové zprávy. */
        public string $label,
        public string $recipientCodeProduction,
        public string $recipientCodeTest,
        public string $documentedBoxIdProduction,
        public string $documentedBoxIdTest,
        /** Čím je kanál doložený — cituje se v chybě, ne jen v komentáři. */
        public string $sourceNote,
    ) {}

    public function recipientCode(string $environment): string
    {
        return $this->isProduction($environment)
            ? $this->recipientCodeProduction
            : $this->recipientCodeTest;
    }

    public function documentedBoxId(string $environment): string
    {
        return $this->isProduction($environment)
            ? $this->documentedBoxIdProduction
            : $this->documentedBoxIdTest;
    }

    private function isProduction(string $environment): bool
    {
        $normalized = strtolower(trim($environment));
        if (!in_array($normalized, ['production', 'test'], true)) {
            throw new SubmissionChannelException(
                'payroll_isds_environment_unknown',
                'Pro tohle prostředí není doložená datová schránka ČSSZ.',
                422,
            );
        }

        return $normalized === 'production';
    }
}
