<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Net;

/**
 * Ověřené podmínky dohody o srážce. Peníze jsou výhradně celá čísla v haléřích.
 *
 * Procento je jen odvozovací pomůcka: uloží se `basis_points` i základ
 * (`basis_amount_minor`) a z nich se DETERMINISTICKY spočítá `requested_minor`.
 * Do zmrazeného vstupního snapshotu mzdového běhu tak jde vždy pevná částka —
 * výpočtová větev MZ-13 (PayrollRunStatutoryCalculationService::deductions,
 * PayrollRunDeductionLedgerApprover) porovnává výsledek proti snapshotu na
 * korunu a nesmí záviset na základu, který v okamžiku zmrazení není znám.
 */
final readonly class DeductionAgreementTerms
{
    /**
     * `imported` = srážka převzatá z podkladů (import docházky, převod PAMICA),
     * za kterou není doložená dohoda o srážkách podle OZ; viz
     * {@see self::reportedAsWageDeduction()}.
     */
    public const KINDS = ['advance', 'meal', 'contribution', 'damage', 'other', 'imported'];

    /**
     * Právní titul srážky.
     *
     *  - `agreement` — dohoda o srážkách ze mzdy (§ 146 písm. b) ZP,
     *    § 2045 a násl. OZ);
     *  - `zp_147_1_c` — záloha na mzdu, plat nebo odměnu z dohody, kterou je
     *    zaměstnanec povinen vrátit, protože nebyly splněny podmínky pro její
     *    přiznání (§ 147 odst. 1 písm. c) ZP);
     *  - `zp_147_1_d` — nevyúčtovaná záloha na cestovní náhrady nebo jiná
     *    nevyúčtovaná záloha k plnění pracovních úkolů (písm. d);
     *  - `zp_147_1_e` — náhrada mzdy za dovolenou, na kterou zaměstnanec
     *    ztratil právo nebo mu nevzniklo, a náhrada podle § 192, na kterou
     *    právo nevzniklo (písm. e).
     *
     * Srážky podle § 147 odst. 1 písm. c) až e) provádí zaměstnavatel ze
     * zákona, BEZ dohody se zaměstnancem. Přednostně se ze mzdy srážejí jen
     * daň a pojistné (§ 148 odst. 1 ZP); ostatní srážky smějí být provedeny jen
     * v rozsahu výkonu rozhodnutí podle o. s. ř. (§ 148 odst. 2 ZP), tedy
     * z první třetiny (a nevyužité plně zabavitelné části) zbytku čisté mzdy
     * po nezabavitelné částce — nejsou přednostní pohledávkou (§ 279 odst. 2
     * o. s. ř. je nevypočítává). Pořadí se řídí dnem, kdy se srážky začaly
     * provádět, a vůči exekucím soutěží stejně jako pohledávky podle § 280
     * odst. 5 o. s. ř. (komentářová literatura k § 147–149 ZP). Při shodném dni
     * se uplatní pořadí výčtu § 147 odst. 1 (c před d před e) a zákonná srážka
     * před dohodou.
     *
     * Srážka k náhradě škody je možná JEN dohodou (§ 147 odst. 3 ZP).
     */
    public const LEGAL_BASES = ['agreement', 'zp_147_1_c', 'zp_147_1_d', 'zp_147_1_e'];

    /**
     * Atributy měsíčního hlášení JMHZ pro srážky ve prospěch zaměstnavatele
     * u zaměstnance s podporou zaměstnávání OZP (IN28, IN24). Pokyny MPSV k MH
     * 1.4.14, kap. 2.4.15:
     *
     *  - `10350` srážky k uspokojení plnění zaměstnavatele podle OZ (dohoda
     *    podle § 2045 OZ; příklad zaměstnanecká zápůjčka, splátky zálohy nad
     *    nárok; nikdy výkon rozhodnutí);
     *  - `10351` srážky k uhrazení škody (§ 250 a násl. ZP, jen dohodou);
     *  - `10352` srážky na závodní stravování podle § 236 ZP (ne stravenkový
     *    paušál);
     *  - `10353` srážky k uspokojení jiného závazku vůči zaměstnavateli podle
     *    § 146 písm. b) ZP (benefity, „splátka půjčky").
     *
     * Zápůjčka stojí v pokynech u 10350 i u 10353, takže zařazení se z titulu
     * srážky neodvozuje, ukládá ho účetní u dohody ({@see self::defaultJmhzTarget()}
     * je jen návrh do formuláře).
     */
    public const JMHZ_TARGETS = ['10350', '10351', '10352', '10353'];

    /**
     * Návrh zařazení podle titulu srážky; rozhoduje uložená hodnota.
     */
    public static function defaultJmhzTarget(string $deductionKind): ?string
    {
        return match ($deductionKind) {
            'advance' => '10350',
            'damage' => '10351',
            'meal' => '10352',
            'contribution', 'other' => '10353',
            default => null,
        };
    }

    /**
     * Pořadí titulu při shodném dni zahájení / doručení: výčet § 147 odst. 1
     * ZP, dohoda nakonec.
     */
    public static function legalBasisRank(string $legalBasis): int
    {
        return match ($legalBasis) {
            'zp_147_1_c' => 1,
            'zp_147_1_d' => 2,
            'zp_147_1_e' => 3,
            default => 4,
        };
    }

    public static function isStatutory(string $legalBasis): bool
    {
        return $legalBasis !== 'agreement';
    }

    /**
     * Patří srážka do příznaku JMHZ 10116 („Srážky na základě nařízeného soudního
     * nebo správního výkonu rozhodnutí, konkursu nebo dohody o srážkách z příjmu")?
     *
     * Pokyny MPSV k vyplnění měsíčního hlášení 1.4.13: „Uvede se ANO, pokud
     * zaměstnavatel provádí z příjmu zaměstnance srážky na základě nařízeného
     * soudního nebo správního výkonu rozhodnutí, konkursu nebo dohody o srážkách
     * z příjmu uzavřené podle občanského zákoníku, jinak NE." Srážku příspěvku
     * zaměstnance na závodní stravování (§ 236 ZP) vedou tytéž pokyny jako
     * samostatný druh (10352, vedle dohody podle OZ 10350), do 10116 tedy nepatří;
     * nepatří tam ani srážky ze zákona podle § 147 odst. 1 ZP (ani výkon
     * rozhodnutí, ani dohoda). Nepatří tam ani srážka převzatá z podkladů
     * docházky (`imported`): dohoda podle OZ za ní doložená není, typicky jde
     * o srážky za stravování z docházkového systému a PAMICA ji do 10116
     * nehlásí. Výkon rozhodnutí a insolvence řeší exekuční evidence mimo tuto
     * třídu.
     */
    public static function reportedAsWageDeduction(string $deductionKind, string $legalBasis): bool
    {
        return !self::isStatutory($legalBasis)
            && $deductionKind !== 'meal'
            && $deductionKind !== 'imported';
    }

    /**
     * Pásmo 1–9 je rezervované pro zákonné a exekuční pořadí, které se řeší
     * mimo tuto tabulku (modul exekucí). Pořadí uvnitř `DeductionPriorityResolver`
     * ale samo o sobě NIC nezaručuje — exekuce se počítá v jiném kroku pipeline.
     * Přednost zákonné a exekuční srážky drží až kapacita, kterou dohodám
     * přiděluje `GarnishmentCalculator::voluntaryDeductionCapacity()` (§ 148
     * odst. 2 zákoníku práce, § 276 a násl. OSŘ). Od doplnění `deliveredOn`
     * (nález E-03) to ale není nepodmíněná přednost: dohoda doručená plátci
     * mzdy DŘÍV než exekuční příkaz soutěží o obecnou nepřednostní část
     * společně s exekucemi podle § 280 odst. 5 o. s. ř., takže kapacita, kterou
     * dohody dostanou, může být větší než zbytek po exekucích. `priority_no`
     * rozhoduje až uvnitř téhož dne doručení. Viz MZ-13-W07.
     */
    public const PRIORITY_FLOOR = 10;
    public const PRIORITY_CEILING = 9999;

    public function __construct(
        public string $agreementReference,
        public string $title,
        public string $deductionKind,
        public int $priorityNo,
        public int $requestedMinor,
        public ?int $basisPoints,
        public ?int $basisAmountMinor,
        public ?int $totalLimitMinor,
        public string $validFrom,
        public ?string $validTo,
        public ?string $recipientReference,
        public ?string $note,
        /**
         * Den, kdy byla dohoda doručena plátci mzdy (§ 2045 odst. 2 OZ). Od něj
         * se podle § 148 odst. 2 zákoníku práce ve spojení s § 280 odst. 5
         * o. s. ř. odvozuje POŘADÍ dohody vůči exekučním pohledávkám.
         * `null` je legacy stav dohod zaevidovaných dřív, než se den doručení
         * ukládal — takové se řadí fail-closed až za všechny se známým datem.
         */
        public ?string $deliveredOn = null,
        public string $legalBasis = 'agreement',
        /**
         * Kolik z limitu dohody už srazil předchozí mzdový program, než firma začala
         * vést mzdy v MyÚčtu. Do ledgeru srážek nepatří (ten nese jen vlastní běhy),
         * ale limit dohody čerpá stejně. `null` = požadavek údaj nenese, úprava
         * dohody ponechá uloženou hodnotu.
         */
        public ?int $takenOverWithheldMinor = null,
        /**
         * Je věřitelem srážky zaměstnavatel (JMHZ 10349)? `null` = nerozhodnuto.
         * Srážka ze zákona (§ 147 odst. 1 ZP) ho nemá: do 10350 až 10353 nepatří.
         */
        public ?bool $creditorIsEmployer = null,
        /** Atribut JMHZ srážky ve prospěch zaměstnavatele (10350 až 10353). */
        public ?string $jmhzTarget = null,
        /**
         * Nesl požadavek zařazení pro JMHZ? Bez něj úprava dohody ponechá
         * uložené hodnoty (starší klienti a importy je neposílají).
         */
        public bool $jmhzClassificationProvided = false,
    ) {}

    /**
     * @param array<string,mixed> $body
     */
    public static function fromRequest(
        array $body,
        ?self $current = null,
    ): self {
        $reference = self::optionalText($body['agreement_reference'] ?? null, 'agreement_reference', 96)
            ?? $current?->agreementReference
            ?? self::generateReference();
        /*
         * ZŮSTÁVÁ POVINNÉ. Název je popiskem řádku srážky na výplatním lístku
         * (`PayslipDocumentSnapshotMapper::…`), a § 142 odst. 5 zákoníku práce
         * ukládá zaměstnavateli vydat zaměstnanci písemný doklad o jednotlivých
         * složkách mzdy a o PROVEDENÝCH SRÁŽKÁCH. Nepojmenovaná srážka tuhle
         * povinnost nesplní a generování pásky by na ní později spadlo.
         */
        $title = self::requiredText($body['title'] ?? null, 'title', 190);
        $kind = self::requiredText($body['deduction_kind'] ?? null, 'deduction_kind', 32);
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Titul srážky není podporovaný.');
        }
        $priority = self::integer($body['priority_no'] ?? null, 'priority_no');
        if ($priority < self::PRIORITY_FLOOR || $priority > self::PRIORITY_CEILING) {
            throw new \InvalidArgumentException(
                'Pořadí dobrovolné srážky musí být mezi '
                . self::PRIORITY_FLOOR . ' a ' . self::PRIORITY_CEILING
                . ' — nižší pásmo patří zákonným a exekučním srážkám.',
            );
        }

        $basisPoints = self::optionalInteger($body['basis_points'] ?? null, 'basis_points');
        $basisAmount = self::optionalInteger($body['basis_amount_minor'] ?? null, 'basis_amount_minor');
        if ($basisPoints === null && $basisAmount !== null) {
            throw new \InvalidArgumentException('Základ pro procentní srážku nemá zadané procento.');
        }
        if ($basisPoints !== null) {
            if ($basisPoints < 0 || $basisPoints > 10_000) {
                throw new \InvalidArgumentException('Procento srážky musí být mezi 0 a 100 %.');
            }
            if ($basisAmount === null || $basisAmount < 0) {
                throw new \InvalidArgumentException('Procentní srážka vyžaduje nezáporný základ.');
            }
            $requested = self::percentageAmount($basisAmount, $basisPoints);
        } else {
            $requested = self::integer($body['requested_minor'] ?? null, 'requested_minor');
        }
        if ($requested < 0) {
            throw new \InvalidArgumentException('Požadovaná srážka nesmí být záporná.');
        }

        $limit = self::optionalInteger($body['total_limit_minor'] ?? null, 'total_limit_minor');
        if ($limit !== null && $limit < 0) {
            throw new \InvalidArgumentException('Limit dohody nesmí být záporný.');
        }
        $takenOver = array_key_exists('taken_over_withheld_minor', $body)
            ? (self::optionalInteger($body['taken_over_withheld_minor'], 'taken_over_withheld_minor') ?? 0)
            : $current?->takenOverWithheldMinor;
        if ($takenOver !== null && $takenOver < 0) {
            throw new \InvalidArgumentException('Částka sražená předchozím programem nesmí být záporná.');
        }
        if ($limit !== null && $takenOver !== null && $takenOver > $limit) {
            throw new \InvalidArgumentException('Částka sražená předchozím programem nesmí převýšit celkový limit dohody.');
        }
        $validFrom = self::date($body['valid_from'] ?? null, 'valid_from');
        $validTo = self::optionalDate($body['valid_to'] ?? null, 'valid_to');
        if ($validTo !== null && $validTo < $validFrom) {
            throw new \InvalidArgumentException('Konec účinnosti nesmí předcházet začátku.');
        }
        // Doručení dohody plátci mzdy nemá k účinnosti srážek pevný vztah:
        // dohoda může být doručena dřív (typicky týž den, kdy byla uzavřena)
        // i později. Vylučuje se jen to, co je nesporně chybné — den doručení
        // po skončení účinnosti, kdy by z něj už nemohlo plynout žádné pořadí.
        $legalBasis = $body['legal_basis'] ?? $current?->legalBasis ?? 'agreement';
        if (!is_string($legalBasis) || !in_array($legalBasis, self::LEGAL_BASES, true)) {
            throw new \InvalidArgumentException('Právní titul srážky není podporovaný.');
        }
        $deliveredOn = self::optionalDate($body['delivered_on'] ?? null, 'delivered_on');
        if ($deliveredOn !== null && $validTo !== null && $deliveredOn > $validTo) {
            throw new \InvalidArgumentException(
                self::isStatutory($legalBasis)
                    ? 'Den zahájení srážek nesmí následovat až po konci účinnosti.'
                    : 'Den doručení dohody plátci mzdy nesmí následovat až po konci účinnosti.',
            );
        }
        if (self::isStatutory($legalBasis)) {
            if ($deliveredOn === null) {
                throw new \InvalidArgumentException(
                    'U srážky podle § 147 odst. 1 zákoníku práce vyplňte den zahájení srážek —'
                    . ' určuje pořadí srážky vůči exekucím a dohodám.',
                );
            }
            if ($kind === 'damage') {
                throw new \InvalidArgumentException(
                    'Náhradu škody lze srážet jen na základě dohody o srážkách ze mzdy'
                    . ' (§ 147 odst. 3 zákoníku práce), ne ze zákona.',
                );
            }
        }
        [$creditorIsEmployer, $jmhzTarget, $classificationProvided] = self::jmhzClassification(
            $body,
            $legalBasis,
            $current,
        );

        return new self(
            $reference,
            $title,
            $kind,
            $priority,
            $requested,
            $basisPoints,
            $basisPoints === null ? null : $basisAmount,
            $limit,
            $validFrom,
            $validTo,
            self::optionalText($body['recipient_reference'] ?? null, 'recipient_reference', 190),
            self::optionalText($body['note'] ?? null, 'note', 500),
            $deliveredOn,
            $legalBasis,
            $takenOver,
            $creditorIsEmployer,
            $jmhzTarget,
            $classificationProvided,
        );
    }

    /**
     * Věřitel a zařazení srážky pro JMHZ (10349 až 10353).
     *
     * Srážka ze zákona podle § 147 odst. 1 písm. c) až e) ZP se do 10350 až
     * 10353 nehlásí: datový slovník 1.4.1.6 vede 10353 jako srážky „podle § 146
     * písm. b) zákoníku práce", tedy na základě dohody o srážkách, a 10350 až
     * 10352 popisují pokyny MPSV k MH 1.4.14 (kap. 2.4.15) jako dohodu podle OZ,
     * náhradu škody dohodou a stravování podle § 236 ZP. Zákonné srážky jsou
     * § 146 písm. a), pokyny je u 10349 uvádějí jen jako výčet druhů srážek.
     *
     * @param array<string,mixed> $body
     * @return array{?bool,?string,bool}
     */
    private static function jmhzClassification(array $body, string $legalBasis, ?self $current): array
    {
        $provided = array_key_exists('creditor_is_employer', $body)
            || array_key_exists('jmhz_target', $body);
        if (!$provided) {
            if (self::isStatutory($legalBasis)) {
                return [null, null, true];
            }

            return [$current?->creditorIsEmployer, $current?->jmhzTarget, false];
        }
        $creditor = $body['creditor_is_employer'] ?? null;
        if ($creditor !== null && !is_bool($creditor)) {
            throw new \InvalidArgumentException(self::label('creditor_is_employer') . ' musí být Ano, nebo Ne.');
        }
        $target = $body['jmhz_target'] ?? null;
        if ($target === '') {
            $target = null;
        }
        if ($target !== null && (!is_string($target) || !in_array($target, self::JMHZ_TARGETS, true))) {
            throw new \InvalidArgumentException(self::label('jmhz_target') . ' musí být 10350, 10351, 10352, nebo 10353.');
        }
        if (self::isStatutory($legalBasis)) {
            if ($creditor !== null || $target !== null) {
                throw new \InvalidArgumentException(
                    'Srážka ze zákona (§ 147 odst. 1 zákoníku práce) se v JMHZ mezi srážky'
                    . ' ve prospěch zaměstnavatele (10350 až 10353) nehlásí, věřitele ani'
                    . ' zařazení u ní nevyplňujte.',
                );
            }

            return [null, null, true];
        }
        if ($creditor === true && $target === null) {
            throw new \InvalidArgumentException(
                'U srážky ve prospěch zaměstnavatele vyberte, jak se vykáže v JMHZ (10350 až 10353).',
            );
        }
        if ($creditor !== true && $target !== null) {
            throw new \InvalidArgumentException(
                'Zařazení pro JMHZ (10350 až 10353) patří jen ke srážce ve prospěch zaměstnavatele.',
            );
        }

        return [$creditor, $target, true];
    }

    public static function percentageAmount(int $baseMinorUnits, int $basisPoints): int
    {
        $whole = intdiv($baseMinorUnits, 10_000) * $basisPoints;
        $fraction = intdiv(($baseMinorUnits % 10_000) * $basisPoints, 10_000);

        return $whole + $fraction;
    }

    private static function generateReference(): string
    {
        return 'srazka-' . bin2hex(random_bytes(8));
    }

    /**
     * Lidský název pole pro chybové hlášky.
     *
     * Text výjimky projde beze změny až do formuláře dohody o srážkách; "Pole
     * basis_amount_minor musí být celé číslo" účetní neřekne, do kterého
     * políčka sáhnout. Neznámý klíč zůstává tak, jak je.
     */
    private const FIELD_LABELS = [
        'agreement_reference' => 'Reference dohody',
        'title' => 'Název dohody',
        'deduction_kind' => 'Titul srážky',
        'priority_no' => 'Pořadí',
        'requested_minor' => 'Požadovaná srážka',
        'basis_points' => 'Procento',
        'basis_amount_minor' => 'Základ pro procentní srážku',
        'total_limit_minor' => 'Celkový limit dohody',
        'taken_over_withheld_minor' => 'Sraženo předchozím programem',
        'valid_from' => 'Účinnost od',
        'valid_to' => 'Účinnost do',
        'delivered_on' => 'Doručeno plátci mzdy / zahájení srážek',
        'legal_basis' => 'Právní titul srážky',
        'creditor_is_employer' => 'Věřitelem je zaměstnavatel',
        'jmhz_target' => 'Vykázat v JMHZ jako',
        'recipient_reference' => 'Příjemce',
        'note' => 'Poznámka',
    ];

    private static function label(string $field): string
    {
        return self::FIELD_LABELS[$field] ?? $field;
    }

    private static function requiredText(mixed $value, string $field, int $max): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException(self::label($field) . ' musí být text.');
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('Vyplňte pole ' . self::label($field) . '.');
        }
        if (mb_strlen($trimmed, 'UTF-8') > $max) {
            throw new \InvalidArgumentException(
                self::label($field) . " může mít nejvýše {$max} znaků.",
            );
        }

        return $trimmed;
    }

    private static function optionalText(mixed $value, string $field, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::requiredText($value, $field, $max);
    }

    private static function integer(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]{0,17})$/D', $value) === 1) {
            return (int) $value;
        }

        throw new \InvalidArgumentException(self::label($field) . ' musí být číslo.');
    }

    private static function optionalInteger(mixed $value, string $field): ?int
    {
        return $value === null || $value === '' ? null : self::integer($value, $field);
    }

    private static function date(mixed $value, string $field): string
    {
        if (!is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException(
                self::label($field) . ' musí být datum ve tvaru RRRR-MM-DD.',
            );
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        if (!checkdate($month, $day, $year)) {
            throw new \InvalidArgumentException(self::label($field) . ' není platné datum.');
        }

        return $value;
    }

    private static function optionalDate(mixed $value, string $field): ?string
    {
        return $value === null || $value === '' ? null : self::date($value, $field);
    }
}
