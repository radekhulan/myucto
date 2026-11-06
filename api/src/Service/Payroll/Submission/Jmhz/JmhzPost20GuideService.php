<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Repository\Payroll\PayrollAbsenceRepository;
use MyInvoice\Repository\Payroll\PayrollJmhzPost20Repository;
use MyInvoice\Repository\Payroll\PayrollRegistrationEventRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionCalendar;
use Psr\Clock\ClockInterface;

/**
 * Průvodce postupem po 20. dni (Pravidla podání JMHZ 1.4.5, kap. 1,
 * „Postup po 20. dni v kalendářním měsíci", ř. 752-860, a tabulka typových
 * situací v kap. 8).
 *
 * Storno součásti po 20. dni nejde; nápravu tvoří dvojice podání, která
 * k sobě patří: registrační akce REGZEC a opravná měsíční hlášení za
 * dotčená období. Systémové kontroly ČSSZ pořadí nehlídají, ale referent
 * zpracovává obojí najednou, proto průvodce ukazuje oba kroky vedle sebe
 * a jejich stav:
 *
 * - **A. `invalid_employment`**: soudní rozhodnutí o absolutní neplatnosti
 *   PPV, nesprávně označený nebo přihlášený PPV → REGZEC akce 8 (storno)
 *   a opravná hlášení na nulové hodnoty za všechna relevantní období,
 * - **B. `late_termination`**: zapomenutá nebo zpětná odhláška → REGZEC
 *   akce 2 (odhláška) a nulová opravná hlášení za měsíce po skončení;
 *   metodicky nejdřív opravná hlášení, pak odhláška,
 * - **C. `date_change`**: změna nebo oprava data nástupu či skončení →
 *   REGZEC akce 3 nebo 4 a opravná hlášení za dotčená období.
 *
 * Průvodce nic nepodává ani neukládá. Situaci volí účetní výslovně; stav se
 * skládá z existujících registračních událostí a opravných podání, takže
 * je dohledatelné, že obě části byly podány. Nulové opravné hlášení měsíce
 * staví {@see JmhzZeroAmendmentSubmissionService}.
 */
final readonly class JmhzPost20GuideService
{
    public const SITUATIONS = [
        'invalid_employment' => [8],
        'late_termination' => [2],
        'date_change' => [3, 4],
    ];

    /** Kolik měsíců zpět průvodce hledá podaná hlášení. */
    public const LOOKBACK_MONTHS = 36;

    /** Blokace nulové anulace formuláře, který nesl příjem nebo pojistné. */
    private const ORIGINAL_NOT_ZERO = 'jmhz_zero_amendment_original_not_zero';

    /**
     * Varianty nápravy formuláře s příjmem nebo pojistným za měsíc, který
     * neměl být hlášen (Pravidla podání 1.4.6, tabulka typových situací,
     * str. 115-117). Účetní musí jednu výslovně zvolit; nulová anulace ze
     * zmrazeného originálu zůstává pro takový formulář zablokovaná.
     *
     * - `returned` (VAR 1, plnění vráceno): oprava mzdového běhu měsíce
     *   a obsahová oprava z nové přípravy, která přepočte formulář, souhrnnou
     *   i pojistnou část (PVPOJ za všechny zaměstnance měsíce),
     * - `not_returned` (VAR 2, plnění nevráceno): vynulovaný standardní
     *   formulář a původní hodnoty 1:1 ve formuláři Odložený příjem, souhrn
     *   a PVPOJ beze změny; aplikace ho zatím nesestaví, podává se ručně.
     *
     * V obou variantách se podá i REGZEC a po lhůtě vždy opravné hlášení.
     */
    private const VARIANTS = [
        ['key' => 'returned', 'route' => 'content_correction', 'supported' => true],
        ['key' => 'not_returned', 'route' => 'manual_deferred_income', 'supported' => false],
    ];

    public function __construct(
        private PayrollJmhzPost20Repository $repository,
        private PayrollRegistrationEventRepository $events,
        private PayrollRegistrationIdentityRepository $identities,
        private PayrollAbsenceRepository $employments,
        private JmhzZeroAmendmentSubmissionService $amendments,
        private ClockInterface $clock,
    ) {}

    /** @return array<string,mixed> */
    public function guide(int $supplierId, string $environment, int $employmentId, string $situation): array
    {
        $actions = self::SITUATIONS[$situation] ?? throw new JmhzXmlException(
            'jmhz_post20_situation_unknown',
            'Vyberte typovou situaci po 20. dni: neplatný pracovní vztah, zapomenutá'
                . ' odhláška, nebo změna data nástupu či skončení.',
        );
        if ($supplierId <= 0 || $employmentId <= 0 || !in_array($environment, ['test', 'production'], true)) {
            throw new \InvalidArgumentException('Rozsah průvodce postupem po 20. dni není platný.');
        }
        $bounds = $this->employments->employmentBounds($supplierId, $employmentId);
        if ($bounds['start'] === null) {
            throw new \DomainException('Pracovní vztah nebyl nalezen.');
        }
        $warnings = [];
        if ($situation === 'late_termination' && $bounds['end'] === null) {
            $warnings[] = 'jmhz_post20_end_date_missing';
        }

        $registration = $this->registration($supplierId, $environment, $employmentId, $actions);
        $months = $this->months($supplierId, $environment, $employmentId, $situation, $bounds, $warnings);

        $pending = array_filter(
            $months,
            static fn (array $month): bool => in_array(
                $month['action'],
                ['annul', 'choose_variant', 'review', 'manual'],
                true,
            ),
        );
        $status = match (true) {
            $registration['submitted'] && $pending === [] => 'complete',
            $registration['submitted'] || $months !== [] && $pending === [] => 'partially_done',
            default => 'open',
        };

        return [
            'environment' => $environment,
            'employment_id' => $employmentId,
            'situation' => $situation,
            'employment_start' => $bounds['start'],
            'employment_end' => $bounds['end'],
            'regzec_actions' => $actions,
            'registration' => $registration,
            'months' => $months,
            'status' => $status,
            'order' => $situation === 'invalid_employment' ? 'together' : 'amendments_first',
            'lookback_months' => self::LOOKBACK_MONTHS,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * Registrační události vztahu s akcí, kterou situace vyžaduje.
     *
     * @param list<int> $actions
     * @return array{events:list<array<string,mixed>>,prepared:bool,submitted:bool}
     */
    private function registration(int $supplierId, string $environment, int $employmentId, array $actions): array
    {
        $events = [];
        foreach ($this->events->listForEmployment($supplierId, $environment, $employmentId) as $event) {
            $action = (int) ($event['action_code'] ?? 0);
            if (!in_array($action, $actions, true)) {
                continue;
            }
            $events[] = [
                'id' => (int) $event['id'],
                'action_code' => $action,
                'effective_on' => (string) $event['effective_on'],
                'submitted' => (bool) ($event['consumed'] ?? false),
            ];
        }

        return [
            'events' => $events,
            'prepared' => $events !== [],
            'submitted' => array_filter($events, static fn (array $event): bool => $event['submitted']) !== [],
        ];
    }

    /**
     * Podaná řádná hlášení, která nesou formulář vztahu za měsíc dotčený
     * situací, a co s každým udělat.
     *
     * @param array{start:?string,end:?string} $bounds
     * @param list<string> $warnings
     * @return list<array<string,mixed>>
     */
    private function months(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $situation,
        array $bounds,
        array &$warnings,
    ): array {
        $externalId = $this->identities->activeExternalId($supplierId, $employmentId, $environment, 'id_ppv');
        if ($externalId === null) {
            $warnings[] = 'jmhz_post20_employment_identifier_missing';

            return [];
        }
        $today = PayrollSubmissionCalendar::today($this->clock->now());
        $toPeriod = substr($today, 0, 7) . '-01';
        $fromPeriod = (new \DateTimeImmutable($toPeriod))
            ->modify('-' . self::LOOKBACK_MONTHS . ' months')
            ->format('Y-m-d');

        $months = [];
        foreach ($this->repository->regularSubmissions(
            $supplierId,
            $environment,
            JmhzSubmissionBridgeService::AGENDA_CODE,
            $fromPeriod,
            $toPeriod,
        ) as $submission) {
            $periodStart = $submission['period_start'];
            // Odhláška mění jen konec vztahu; měsíc nástupu se jí netýká.
            $scope = self::scope(
                $periodStart,
                $situation === 'late_termination' ? ['start' => null, 'end' => $bounds['end']] : $bounds,
            );
            if ($scope === 'inside' && $situation !== 'invalid_employment') {
                continue;
            }
            try {
                $candidates = $this->amendments->candidates(
                    $supplierId,
                    $environment,
                    $submission['submission_id'],
                    $externalId['value_hash'],
                );
            } catch (JmhzXmlException|\DomainException $exception) {
                $warnings[] = 'jmhz_post20_submission_unreadable';

                continue;
            }
            foreach ($candidates['forms'] as $form) {
                $amendments = $form['amendments'];
                $action = self::monthAction($amendments, $scope, $form['annullable'], $form['blocker']);
                $months[] = [
                    'period' => substr($periodStart, 0, 7),
                    'submission_id' => $submission['submission_id'],
                    'submission_status' => $submission['status'],
                    'form_guid' => $form['form_guid'],
                    'scope' => $scope,
                    'action' => $action,
                    'annullable' => $form['annullable'],
                    'blocker' => $form['blocker'],
                    'amendments' => $amendments,
                    'variants' => $action === 'choose_variant' ? self::VARIANTS : [],
                ];
            }
        }

        return $months;
    }

    /**
     * Co s formulářem měsíce udělat. Formulář mimo období vztahu, který nesl
     * příjem nebo pojistné, nulovou anulací opravit nejde
     * ({@see JmhzZeroAmendmentXmlSerializer}); účetní volí variantu
     * {@see self::VARIANTS}.
     *
     * @param list<mixed> $amendments
     * @param array{code:string,message:string}|null $blocker
     */
    public static function monthAction(array $amendments, string $scope, bool $annullable, ?array $blocker): string
    {
        return match (true) {
            $amendments !== [] => 'amended',
            $scope === 'outside' && $annullable => 'annul',
            $scope === 'outside' && ($blocker['code'] ?? null) === self::ORIGINAL_NOT_ZERO => 'choose_variant',
            $scope === 'partial' => 'review',
            default => 'manual',
        };
    }

    /** @return list<array{key:string,route:string,supported:bool}> */
    public static function variants(): array
    {
        return self::VARIANTS;
    }

    /**
     * Poloha měsíce vůči období vztahu podle evidence: celý mimo (anuluje se
     * nulovým opravným hlášením), částečně (opravuje se obsahovou opravou
     * z nové přípravy), nebo celý uvnitř.
     *
     * @param array{start:?string,end:?string} $bounds
     */
    private static function scope(string $periodStart, array $bounds): string
    {
        $periodEnd = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
        $start = $bounds['start'];
        $end = $bounds['end'];
        if (($end !== null && strcmp($end, $periodStart) < 0)
            || ($start !== null && strcmp($start, $periodEnd) > 0)
        ) {
            return 'outside';
        }
        if (($start !== null && strcmp($start, $periodStart) > 0)
            || ($end !== null && strcmp($end, $periodEnd) < 0)
        ) {
            return 'partial';
        }

        return 'inside';
    }
}
