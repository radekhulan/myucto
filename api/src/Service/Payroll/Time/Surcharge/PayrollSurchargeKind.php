<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Surcharge;

/**
 * Zákonné příplatky ke mzdě podle § 114 až § 118 zákoníku práce.
 *
 * Hodnota výčtu je ZÁROVEŇ kategorie evidence docházky
 * (`payroll_time_entries.category`, migrace 1201). Není to náhoda ani zkratka:
 * kategorie docházky říká, ZA JAKOU DOBU příplatek náleží, a jiný klíč než ten
 * by znamenal překlad mezi dvěma číselníky a tím i místo, kde se dá ztratit
 * hodina. Kategorie `regular` mezi příplatky nepatří — je to prostě odpracovaná
 * doba bez příznaku.
 *
 * ── Kategorie jsou PŘEKRYVNÉ PŘÍZNAKY, ne disjunktní řezy ────────────────────
 *
 * Odpracovaná doba měsíce se v {@see \MyInvoice\Service\Payroll\Time\PayrollTimeService}
 * počítá jako `regular + overtime`; `night`, `weekend`, `holiday`
 * a `difficult_environment` se do součtu NEPŘIČÍTAJÍ. Kdyby to byly samostatné
 * řezy, měsíční souhrn by odpracovanou dobu podhodnocoval o každou noční
 * a víkendovou hodinu — a to by byla nápadná vada, kterou by evidence dávno
 * vytkla. Jsou to tedy PŘÍZNAKY NAD TÝMIŽ HODINAMI.
 *
 * Pro příplatky z toho plyne to podstatné: hodina odpracovaná v noci o víkendu
 * má řádek `regular` (nebo `overtime`) a k tomu řádky `night` a `weekend`,
 * a náleží za ni OBA příplatky vedle sebe. Sčítají se, nekonkurují si.
 * Přesčas v noci o víkendu nese tři příplatky současně, práce ve svátek, který
 * padne na sobotu, dva (§ 115 i § 118 — ani jeden z nich druhý nevylučuje).
 *
 * Z překryvnosti plyne i integritní mez, kterou hlídá
 * {@see PayrollSurchargeEvidence}: příznakových minut nemůže být za den víc než
 * minut odpracovaných.
 */
enum PayrollSurchargeKind: string
{
    /** § 114 — mzda nebo náhradní volno za práci přesčas. */
    case Overtime = 'overtime';

    /** § 115 — mzda, náhradní volno nebo náhrada mzdy za svátek. */
    case Holiday = 'holiday';

    /** § 116 — mzda za noční práci. */
    case Night = 'night';

    /** § 118 — mzda za práci v sobotu a v neděli. */
    case Weekend = 'weekend';

    /** § 117 — mzda a příplatek za práci ve ztíženém pracovním prostředí. */
    case DifficultEnvironment = 'difficult_environment';

    /** Kategorie evidence docházky, ze které se příplatek počítá. */
    public function timeEntryCategory(): string
    {
        return $this->value;
    }

    public function rulesetRateKey(): string
    {
        return "surcharge.{$this->value}.rate";
    }

    public function rulesetBasisKey(): string
    {
        return "surcharge.{$this->value}.basis";
    }

    /** Kód mzdové složky v {@see \MyInvoice\Service\Payroll\Component\PayrollComponentDefaults}. */
    public function componentCode(): string
    {
        return match ($this) {
            self::Overtime => 'PRIPLATEK_PRESCAS',
            self::Holiday => 'PRIPLATEK_SVATEK',
            self::Night => 'PRIPLATEK_NOCNI',
            self::Weekend => 'PRIPLATEK_VIKEND',
            self::DifficultEnvironment => 'PRIPLATEK_ZTIZENE_PROSTREDI',
        };
    }

    public function section(): string
    {
        return match ($this) {
            self::Overtime => '§ 114',
            self::Holiday => '§ 115',
            self::Night => '§ 116',
            self::DifficultEnvironment => '§ 117',
            self::Weekend => '§ 118',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Overtime => 'Příplatek za práci přesčas',
            self::Holiday => 'Příplatek za práci ve svátek',
            self::Night => 'Příplatek za noční práci',
            self::Weekend => 'Příplatek za práci v sobotu a v neděli',
            self::DifficultEnvironment => 'Příplatek za práci ve ztíženém pracovním prostředí',
        };
    }

    /**
     * Smí se sjednat NIŽŠÍ sazba, než je zákonné minimum?
     *
     * § 116 a § 118 obsahují větu „Je možné sjednat jinou minimální výši a způsob
     * určení příplatku" — tam tedy dohoda smí jít i pod desetinu průměrného
     * výdělku. § 114, § 115 ani § 117 takovou větu NEMAJÍ; jejich „nejméně" je
     * tvrdá podlaha a sjednat se dá jen víc.
     *
     * Rozdíl je proto vlastností ustanovení, ne konfigurace: kdyby to byl další
     * sloupec v tabulce zásad, dalo by se zaklikáním obejít kogentní minimum.
     */
    public function allowsLowerAgreedRate(): bool
    {
        return $this === self::Night || $this === self::Weekend;
    }

    /**
     * Lze místo příplatku poskytnout náhradní volno?
     *
     * § 114 (přesčas) a § 115 (svátek) ano, u ostatních zákon žádnou takovou
     * alternativu nezná.
     */
    public function allowsCompensatoryTimeOff(): bool
    {
        return $this === self::Overtime || $this === self::Holiday;
    }

    /**
     * Lze druh zadat RUČNĚ v rychlém měsíčním vstupu, bez evidence docházky?
     *
     * § 116, § 117 a § 118 se do mzdy jinak než docházkou nedostanou vůbec,
     * a firma, která docházku nevede, by tím o zákonný nárok přišla. § 115 je
     * na tom stejně; že se u něj příplatek vyplatí jen při sjednané zásadě, je
     * podmínka nároku, ne důvod, proč by hodiny nešly zadat.
     *
     * § 114 sem NEPATŘÍ, ačkoli ručně zadat jde: rychlé zadání pro něj má
     * VLASTNÍ pole s vlastním rozpadem na dosaženou mzdu a příplatek
     * ({@see \MyInvoice\Repository\Payroll\PayrollQuickInputRepository}).
     * Druhé pole na týž nárok by nebylo pohodlí, ale další způsob, jak ho
     * vyplatit dvakrát.
     */
    public function allowsQuickManualEntry(): bool
    {
        return $this !== self::Overtime;
    }

    /**
     * Druhy ručního zadání v pořadí, ve kterém je vidí uživatel.
     *
     * Pořadí je věcné, ne podle čísla paragrafu: noční a víkend jsou to, co
     * směnný provoz vyplňuje každý měsíc; svátek přijde párkrát do roka
     * a ztížené prostředí se týká menšiny pracovišť.
     *
     * @return list<self>
     */
    public static function quickManualEntry(): array
    {
        return [self::Night, self::Weekend, self::Holiday, self::DifficultEnvironment];
    }

    /**
     * `external_id` mzdového vstupu, kterým rychlé zadání drží tenhle druh.
     *
     * Týž tvar jako u ostatních polí rychlého zadání (`quick-monthly:<KÓD>`),
     * aby se vstup z ručního zadání poznal od vstupu z docházky
     * (`surcharge:<druh>:<období>:<pořadí>`) na první pohled i v dotazu.
     */
    public function quickExternalId(): string
    {
        return 'quick-monthly:' . $this->componentCode();
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }
}
