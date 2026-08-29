<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Surcharge;

use MyInvoice\Repository\Payroll\PayrollSurchargePolicyNotFoundException;
use MyInvoice\Repository\Payroll\PayrollSurchargeRepository;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;

/**
 * Sjednané zásady zákonných příplatků § 114 až § 118 ZP — čtení a zápis.
 *
 * ── Proč to vzniká až teď a proč to bylo naléhavé ────────────────────────────
 *
 * Tabulka `payroll_employment_surcharge_policies` je od migrace 1624, výpočet
 * i materializace na ni od té doby spoléhají — ale zapsat do ní nešlo nic.
 * Výsledek nebyl „chybí featura", ale ZASEKNUTÝ MODUL: materializace příplatků
 * běží v téže transakci jako schválení docházky a je fail-closed, takže měsíc
 * s prací o svátku spadl na `holiday_arrangement_missing` a měsíc ve ztíženém
 * prostředí na `difficulty_factors_missing`. Uživatel to neměl jak vyřešit,
 * protože obojí se sjednává právě tady.
 *
 * ── Co se validuje a proč to nesmí obejít API ───────────────────────────────
 *
 * Kogentní podlahu („nejméně" u § 114, § 115 a § 117) hlídá
 * {@see PayrollSurchargePolicy::agreed()} proti sadě pravidel. Zásada se proto
 * NEJDŘÍV postaví jako doménový objekt a teprve když projde, uloží se — kdyby
 * se validovalo až při čtení, dala by se do databáze zaklikáním dostat sazba
 * pod zákonným minimem a projevila by se až na výplatní pásce.
 *
 * Sazby chodí v BÁZOVÝCH BODECH (25 % = 2500), ne jako desetinné číslo:
 * `DecimalRate` je na kanonický tvar citlivá a řetězec z JSONu ani z ovladače
 * databáze není zaručeně kanonický.
 */
final class PayrollEmploymentSurchargePolicyService
{
    private const RATE_FIELDS = [
        'overtime_rate_bp' => PayrollSurchargeKind::Overtime,
        'holiday_rate_bp' => PayrollSurchargeKind::Holiday,
        'night_rate_bp' => PayrollSurchargeKind::Night,
        'weekend_rate_bp' => PayrollSurchargeKind::Weekend,
        'difficult_environment_rate_bp' => PayrollSurchargeKind::DifficultEnvironment,
    ];

    public function __construct(
        private readonly PayrollSurchargeRepository $repository,
        private readonly PayrollRulesetProvider $rulesets,
    ) {}

    /**
     * Historie zásad vztahu i s tím, co platí BEZ nich.
     *
     * Výchozí zákonný stav se posílá vždycky, i když zásady existují: bez něj
     * by uživatel na obrazovce neviděl, co si vlastně sjednáním mění, a u § 115
     * je právě ten rozdíl to podstatné.
     *
     * @return array{
     *   policies:list<array<string,mixed>>,
     *   statutory_default:array<string,mixed>,
     *   kinds:list<array<string,mixed>>
     * }
     */
    public function forEmployment(
        int $supplierId,
        int $employmentId,
        string $effectiveOn,
    ): array {
        $ruleset = PayrollSurchargeRuleset::forDate($this->rulesets, $effectiveOn);
        $statutory = PayrollSurchargePolicy::statutoryDefault();

        $kinds = [];
        foreach (PayrollSurchargeKind::all() as $kind) {
            $rate = $ruleset->statutoryRate($kind);
            $kinds[] = [
                'kind' => $kind->value,
                'section' => $kind->section(),
                'label' => $kind->label(),
                'component_code' => $kind->componentCode(),
                'basis' => $ruleset->basis($kind)->value,
                'statutory_rate_basis_points' => PayrollSurchargePolicy::basisPointsOf($rate),
                // Podlézt zákonné minimum smí jen § 116 a § 118 — jen ty mají
                // větu „Je možné sjednat jinou minimální výši". Formulář to musí
                // vědět, aby nenabízel to, co server stejně odmítne.
                'allows_lower_agreed_rate' => $kind->allowsLowerAgreedRate(),
                'allows_compensatory_time_off' => $kind->allowsCompensatoryTimeOff(),
                'allows_quick_manual_entry' => $kind->allowsQuickManualEntry(),
            ];
        }

        return [
            'policies' => $this->repository->policiesForEmployment($supplierId, $employmentId),
            'statutory_default' => [
                'overtime_mode' => $statutory->overtimeMode->value,
                'holiday_mode' => $statutory->holidayMode->value,
                'difficult_environment_factors' => null,
            ],
            'kinds' => $kinds,
            'ruleset_id' => $ruleset->version->id,
        ];
    }

    /**
     * Založí novou verzi zásady k danému dni.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function save(
        int $supplierId,
        int $employmentId,
        array $input,
        ?int $userId,
    ): array {
        $validFrom = self::date($input['valid_from'] ?? null, 'valid_from');

        $id = $this->repository->savePolicy(
            $supplierId,
            $employmentId,
            $validFrom,
            $this->agreedFields($input, $validFrom),
            $userId,
        );

        $policies = $this->repository->policiesForEmployment($supplierId, $employmentId);
        foreach ($policies as $policy) {
            if ((int) ($policy['id'] ?? 0) === $id) {
                return $policy;
            }
        }

        throw new \RuntimeException('Uloženou zásadu příplatků se nepodařilo načíst.');
    }

    /**
     * Oprava obsahu existující verze.
     *
     * Účinnost (`valid_from`) se z těla požadavku VĚDOMĚ nebere, ani když ji
     * klient pošle: je to hranice proti předchozí, uzavřené verzi, jejíž
     * `valid_to` je z ní odvozené. Sada pravidel se proto vybírá podle data,
     * které verze UŽ MÁ — kogentní podlaha se musí měřit proti zákonu účinnému
     * v době sjednání, ne v době opravy.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function update(
        int $supplierId,
        int $employmentId,
        int $policyId,
        array $input,
    ): array {
        $existing = $this->repository->findPolicy($supplierId, $employmentId, $policyId)
            ?? throw new PayrollSurchargePolicyNotFoundException();
        $validFrom = self::date($existing['valid_from'] ?? null, 'valid_from');

        return $this->repository->updatePolicy(
            $supplierId,
            $employmentId,
            $policyId,
            $this->agreedFields($input, $validFrom),
            self::rowVersion($input),
        );
    }

    /**
     * Ukončení platnosti otevřené verze.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function close(
        int $supplierId,
        int $employmentId,
        int $policyId,
        array $input,
    ): array {
        return $this->repository->closePolicy(
            $supplierId,
            $employmentId,
            $policyId,
            self::date($input['valid_to'] ?? null, 'valid_to'),
            self::rowVersion($input),
        );
    }

    /**
     * Ověřený obsah sjednání pro zápis do databáze.
     *
     * Doménový objekt se postaví VŽDYCKY, ať jde o novou verzi nebo o opravu —
     * kdyby se kogentní podlaha kontrolovala jen při zakládání, dala by se
     * podlezená sazba dostat do databáze opravou hned po uložení.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function agreedFields(array $input, string $validFrom): array
    {
        $ruleset = PayrollSurchargeRuleset::forDate($this->rulesets, $validFrom);

        $overtime = self::mode($input['overtime_mode'] ?? null, 'overtime_mode');
        $holiday = self::mode($input['holiday_mode'] ?? null, 'holiday_mode');
        if ($holiday === PayrollSurchargeCompensationMode::IncludedInWage) {
            throw new \InvalidArgumentException(
                'Mzda sjednaná s přihlédnutím k práci ve svátek neexistuje; '
                . '§ 114 odst. 3 se týká jen práce přesčas.'
            );
        }
        $factors = self::nullableInt($input, 'difficult_environment_factors');
        if ($factors !== null && ($factors < 1 || $factors > 255)) {
            throw new \InvalidArgumentException(
                'Počet ztěžujících vlivů podle § 117 musí být 1 až 255.'
            );
        }

        $rates = [];
        foreach (self::RATE_FIELDS as $field => $kind) {
            $rates[$kind->value] = self::nullableInt($input, $field);
        }

        // Postaví se doménový objekt, a teprve když projde, uloží se. Tohle je
        // JEDINÉ místo, kde se kogentní podlaha kontroluje — kdyby se dala
        // obejít z API, byla by celá kontrola jen dekorace.
        PayrollSurchargePolicy::agreed($overtime, $holiday, $factors, $rates, $ruleset);

        return [
            'overtime_mode' => $overtime->value,
            'holiday_mode' => $holiday->value,
            'difficult_environment_factors' => $factors,
            'overtime_rate_bp' => $rates[PayrollSurchargeKind::Overtime->value],
            'holiday_rate_bp' => $rates[PayrollSurchargeKind::Holiday->value],
            'night_rate_bp' => $rates[PayrollSurchargeKind::Night->value],
            'weekend_rate_bp' => $rates[PayrollSurchargeKind::Weekend->value],
            'difficult_environment_rate_bp' =>
                $rates[PayrollSurchargeKind::DifficultEnvironment->value],
            'agreement_reference' => self::nullableString($input, 'agreement_reference', 191),
            'note' => self::nullableString($input, 'note', 500),
        ];
    }

    /** @param array<string,mixed> $input */
    private static function rowVersion(array $input): int
    {
        $value = filter_var(
            $input['row_version'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if (!is_int($value)) {
            throw new \InvalidArgumentException(
                'row_version musí být kladné celé číslo verze, kterou má úprava přepsat.'
            );
        }

        return $value;
    }

    private static function mode(mixed $value, string $field): PayrollSurchargeCompensationMode
    {
        $mode = is_string($value)
            ? PayrollSurchargeCompensationMode::tryFrom($value)
            : null;
        if ($mode === null) {
            throw new \InvalidArgumentException("{$field} obsahuje neznámý režim odměnění.");
        }

        return $mode;
    }

    private static function date(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException("{$field} musí být datum RRRR-MM-DD.");
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException("{$field} musí být datum RRRR-MM-DD.");
        }

        return $value;
    }

    /** @param array<string,mixed> $input */
    private static function nullableInt(array $input, string $field): ?int
    {
        $value = $input[$field] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (!is_int($number)) {
            throw new \InvalidArgumentException("{$field} musí být nezáporné celé číslo.");
        }
        if ($number > 50_000) {
            // Táž mez jako CHECK v migraci 1624 — proti překlepu o řád.
            throw new \InvalidArgumentException("{$field} překračuje podporovaný rozsah.");
        }

        return $number;
    }

    /** @param array<string,mixed> $input */
    private static function nullableString(array $input, string $field, int $maximum): ?string
    {
        $value = $input[$field] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException("{$field} musí být text.");
        }
        $value = trim($value);
        if (mb_strlen($value, 'UTF-8') > $maximum) {
            throw new \InvalidArgumentException(
                "{$field} může mít nejvýše {$maximum} znaků."
            );
        }

        return $value === '' ? null : $value;
    }
}
