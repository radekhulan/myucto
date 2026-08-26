<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

/**
 * Zadání stornujícího podání. Ověřuje se při vzniku, ne až při serializaci —
 * storno je nevratné a časově uzavřené, takže neplatné zadání se nemá dostat
 * ani k sestavení XML.
 */
final readonly class JmhzCancellationRequest
{
    private function __construct(
        public string $regularSubmissionGuid,
        public string $variableSymbol,
        public int $year,
        public int $month,
    ) {}

    public static function create(
        string $regularSubmissionGuid,
        string $variableSymbol,
        int $year,
        int $month,
        JmhzDeadlinePolicy $deadlines = new JmhzDeadlinePolicy(),
        ?string $today = null,
    ): self {
        if (preg_match('/^\d{10}$/D', $variableSymbol) !== 1) {
            throw new JmhzXmlException(
                'jmhz_xml_variable_symbol_invalid',
                'Variabilní symbol zaměstnavatele musí mít deset číslic.',
            );
        }
        if ($month < 1 || $month > 12) {
            throw new JmhzXmlException(
                'jmhz_xml_period_invalid',
                'Rozhodné období storna musí být platný kalendářní měsíc.',
            );
        }
        $periodStart = sprintf('%04d-%02d-01', $year, $month);
        if (!$deadlines->cancellationAllowed($periodStart)) {
            throw new JmhzXmlException(
                'jmhz_cancellation_transition_period_forbidden',
                'ČSSZ nepovoluje storno JMHZ za leden až březen 2026;'
                    . ' případnou změnu je nutné podat jako obsahovou opravu.',
            );
        }
        $window = $deadlines->forPeriod($periodStart);
        // Storno lze podat jen do konce lhůty pro řádné podání; potom už jen
        // opravným hlášením. Po lhůtě je odmítnutí jediná správná odpověď —
        // odeslané storno by u ČSSZ zrušilo víc, než uživatel čeká.
        // Lhůta je kalendářní a čte se českým kalendářem — `gmdate()` by v poslední
        // den lhůty do 02:00 SELČ hlásil ještě předchozí den a naopak.
        $evaluatedOn = $today ?? date('Y-m-d');
        if (strcmp($evaluatedOn, $window->dueOn) > 0) {
            throw new JmhzXmlException(
                'jmhz_cancellation_window_closed',
                "Lhůta pro storno za období {$month}/{$year} skončila {$window->dueOn};"
                    . ' napravit to lze jen opravným hlášením.',
            );
        }

        return new self(
            strtoupper($regularSubmissionGuid),
            $variableSymbol,
            $year,
            $month,
        );
    }
}
