<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\ForeignIncome;

use MyInvoice\Service\Report\EpoDate;

/**
 * Oznámení o příjmech plynoucích do zahraničí podle § 38da ZDP — písemnost
 * DPSHL1, tiskopis 25 5478.
 *
 * Není to vyúčtování a nemá zdaňovací období: podává se **za každý příjem a
 * každý druh příjmu zvlášť**, ve lhůtě pro odvod sražené daně (§ 38da odst. 2),
 * u osvobozeného příjmu do 31. ledna následujícího roku. Proto tu není dvanáct
 * měsíců jako u DPZVD6, ale jeden příjem a k němu nejvýš 99 odvodů.
 *
 * ## Kdy se oznámení podává
 *
 * Když plátce vyplácí daňovému nerezidentovi příjem ze zdrojů v ČR, ze kterého
 * se daň vybírá srážkou. Navíc i tehdy, když je takový příjem od daně osvobozen
 * nebo ho smlouva o zamezení dvojího zdanění nechává zdanit jen druhému státu —
 * ale jen u licenčních poplatků, dividend a úroků (§ 38da odst. 1 písm. a) a b)).
 * U úroků se osvobozený příjem neoznamuje, dokud souhrn za kalendářní měsíc
 * nepřesáhne 300 000 Kč (odst. 5 písm. a)).
 *
 * ## Co ZÁMĚRNĚ nepochází z aplikace
 *
 * Všechno. Údaje o příjmu zadává uživatel, protože je aplikace nemá:
 *
 * - **Mzdy do oznámení nepatří.** § 38da odst. 5 písm. b) z oznamovací
 *   povinnosti vylučuje příjem podle § 6 odst. 4 a mzdový modul sráží daň
 *   zvláštní sazbou jedině podle § 6 odst. 4. Číselník `k_rozl_prij` to
 *   potvrzuje i z druhé strany — kód pro § 22 odst. 1 písm. b) (závislá
 *   činnost) v něm vůbec není. Jediný druh příjmu z číselníku, který se může
 *   týkat osoby v mzdovém modulu, je odměna člena orgánu právnické osoby, a tu
 *   modul daní zálohou, ne srážkou.
 * - **Dividendy, licenční poplatky, úroky ani platby za služby do zahraničí**
 *   aplikace neeviduje vůbec — na straně přijatých dokladů žádná srážková daň
 *   neexistuje.
 *
 * Dopočítávat se proto nedá nic; jediné, co aplikace přidává, je věta plátce,
 * kontrola kritických pravidel schématu, XSD validace, archivace a odeslání.
 */
final readonly class ForeignIncomeNotice
{
    /** Typ oznámení (`hl_typ`). */
    public const TYP_RADNE = 'R';
    public const TYP_NASLEDNE = 'N';

    /** @var list<string> */
    public const TYPY = [self::TYP_RADNE, self::TYP_NASLEDNE];

    /** Způsob úhrady (`zp_uhrady`). */
    public const UHRADA_POPLATNIKOVI = 'U';
    public const ZAUCTOVANI_ZAVAZKU = 'Z';

    /** @var list<string> */
    public const ZPUSOBY_UHRADY = [self::UHRADA_POPLATNIKOVI, self::ZAUCTOVANI_ZAVAZKU];

    /**
     * Rok úhrady se místo data smí uvést až od roku 2021 (kritická kontrola
     * u `r_uhrady`), datum úhrady nesmí být starší než 2014 (u `d_uhrady`).
     */
    public const MIN_ROK_UHRADY = 2021;
    public const MIN_ROK_DATA_UHRADY = 2014;

    /**
     * @param string  $variant       `hl_typ` — R (řádné) nebo N (následné).
     * @param ?string $discoveredOn  `d_zjist` — u následného oznámení, ISO `Y-m-d`.
     * @param int     $incomeKind    `c_druh_prij` z {@see ForeignIncomeKindCatalog}.
     * @param int     $rateTenthsOfPercent Sazba daně `sazba` v desetinách procenta
     *        (150 = 15,0 %). Celé číslo, ne float — desetinné místo se v XML
     *        nesmí posunout zaokrouhlením.
     * @param string  $paymentMode   `zp_uhrady`.
     * @param ?string $paymentDate   `d_uhrady`, ISO `Y-m-d`. Vzájemně se vylučuje s `$paymentYear`.
     * @param ?int    $paymentYear   `r_uhrady` — jen u osvobozeného příjmu.
     * @param int     $paidAmountMinor `kc_uhrady` v haléřích.
     * @param int     $taxBaseMinor  `kc_zakldane` v haléřích.
     * @param int     $withheldTaxCzk `sraz_dan` v celých korunách.
     * @param ?string $withholdingDueOn `d_povsraz` — den povinnosti srazit (§ 38d odst. 1, 2).
     * @param ?string $remittanceDueOn  `d_splat` — splatnost odvodu (§ 38d odst. 3).
     * @param ?int    $grossIncomeMinor `kc_hrubprij` — jen u příjmu zvyšovaného o povinné pojistné.
     * @param ?int    $mandatoryInsuranceCzk `kc_pojistne` — totéž, v celých korunách.
     * @param ?int    $foreignGrossMinor `kc_hrubprij_zahr` — hrubý příjem v cizí měně.
     * @param ?string $foreignGrossCurrency `mena_hr_prij` — ISO kód měny.
     * @param ?string $paymentCurrency `k_meny` — měna, ve které byla platba provedena.
     * @param ?int    $exchangeRateThousandths `kurz` v tisícinách (24_500 = 24,500).
     * @param ?string $note          `poznamka`.
     * @param list<ForeignIncomeRemittance> $remittances Odvody sražené daně (`VetaU`).
     * @param list<string> $warnings
     */
    public function __construct(
        public string $variant,
        public ?string $discoveredOn,
        public ForeignPayee $payee,
        public int $incomeKind,
        public int $rateTenthsOfPercent,
        public string $paymentMode,
        public ?string $paymentDate,
        public ?int $paymentYear,
        public int $paidAmountMinor,
        public int $taxBaseMinor,
        public int $withheldTaxCzk,
        public ?string $withholdingDueOn = null,
        public ?string $remittanceDueOn = null,
        public ?int $grossIncomeMinor = null,
        public ?int $mandatoryInsuranceCzk = null,
        public ?int $foreignGrossMinor = null,
        public ?string $foreignGrossCurrency = null,
        public ?string $paymentCurrency = null,
        public ?int $exchangeRateThousandths = null,
        public ?string $note = null,
        public array $remittances = [],
        public array $warnings = [],
    ) {
        if (!in_array($variant, self::TYPY, true)) {
            throw new \InvalidArgumentException(
                'Typ oznámení musí být R (řádné) nebo N (následné).',
            );
        }
        if (!in_array($paymentMode, self::ZPUSOBY_UHRADY, true)) {
            throw new \InvalidArgumentException(
                'Způsob úhrady musí být U (úhrada poplatníkovi) nebo Z (zaúčtování závazku).',
            );
        }
        ForeignIncomeKindCatalog::require($incomeKind);

        // Kritická kontrola: datum úhrady NEBO rok úhrady, nikdy obojí a nikdy nic.
        if (($paymentDate === null) === ($paymentYear === null)) {
            throw new \InvalidArgumentException(
                'Vyplň datum úhrady, nebo rok úhrady — právě jedno z toho.',
            );
        }
        if ($paymentDate !== null) {
            $parsed = EpoDate::requireIso($paymentDate, 'Datum úhrady poplatníkovi');
            if ((int) $parsed->format('Y') < self::MIN_ROK_DATA_UHRADY) {
                throw new \InvalidArgumentException(
                    'Datum úhrady nesmí být starší než rok ' . self::MIN_ROK_DATA_UHRADY . '.',
                );
            }
            if (!ForeignIncomeKindCatalog::isEffectiveOn($incomeKind, $paymentDate)) {
                throw new \InvalidArgumentException(
                    'Zvolený druh příjmu k datu úhrady ještě neplatil.',
                );
            }
        }
        if ($paymentYear !== null) {
            if ($paymentYear < self::MIN_ROK_UHRADY) {
                throw new \InvalidArgumentException(
                    'Rok úhrady nesmí být starší než ' . self::MIN_ROK_UHRADY . '.',
                );
            }
            // Rok místo data se vyplňuje jen u osvobozeného příjmu, a ten se
            // oznamuje jen u licenčních poplatků, dividend a úroků.
            if (!ForeignIncomeKindCatalog::allowsExemptReporting($incomeKind)) {
                throw new \InvalidArgumentException(
                    'Rok úhrady místo data se uvádí jen u osvobozeného příjmu'
                    . ' (licenční poplatky, dividendy, úroky).',
                );
            }
        }

        if ($rateTenthsOfPercent < 0 || $rateTenthsOfPercent > 1000) {
            throw new \InvalidArgumentException(
                'Sazba daně musí být v rozmezí 0 až 100 %.',
            );
        }
        if ($rateTenthsOfPercent === 0 && !ForeignIncomeKindCatalog::allowsExemptReporting($incomeKind)) {
            throw new \InvalidArgumentException(
                'Nulovou sazbu lze oznámit jen u osvobozeného příjmu'
                . ' (licenční poplatky, dividendy, úroky).',
            );
        }

        foreach ([
            'Částka uhrazená poplatníkovi' => $paidAmountMinor,
            'Základ daně' => $taxBaseMinor,
            'Sražená daň' => $withheldTaxCzk,
        ] as $label => $amount) {
            if ($amount < 0) {
                throw new \DomainException($label . ' nesmí být záporná.');
            }
        }
        if ($grossIncomeMinor !== null && $grossIncomeMinor < 0) {
            throw new \DomainException('Hrubý příjem nesmí být záporný.');
        }
        if ($mandatoryInsuranceCzk !== null && $mandatoryInsuranceCzk < 0) {
            throw new \DomainException('Povinné pojistné nesmí být záporné.');
        }
        if ($foreignGrossMinor !== null && $foreignGrossMinor < 0) {
            throw new \DomainException('Hrubý příjem v cizí měně nesmí být záporný.');
        }
        // Řádky 27a a 27b tiskopisu tvoří dvojici — pojistné bez hrubého příjmu
        // nemá k čemu patřit.
        if (($grossIncomeMinor === null) !== ($mandatoryInsuranceCzk === null)) {
            throw new \InvalidArgumentException(
                'Hrubý příjem a povinné pojistné se vyplňují společně, nebo vůbec.',
            );
        }
        if (($foreignGrossMinor === null) !== ($foreignGrossCurrency === null)) {
            throw new \InvalidArgumentException(
                'Hrubý příjem v cizí měně se uvádí spolu s kódem měny.',
            );
        }
        if ($foreignGrossCurrency !== null) {
            self::assertCurrency($foreignGrossCurrency);
        }
        if ($paymentCurrency !== null) {
            self::assertCurrency($paymentCurrency);
        }
        if ($exchangeRateThousandths !== null && $exchangeRateThousandths <= 0) {
            throw new \DomainException('Kurz musí být kladný.');
        }
        if ($variant === self::TYP_NASLEDNE && $discoveredOn !== null) {
            EpoDate::requireIso($discoveredOn, 'Datum zjištění důvodů pro následné oznámení');
        }
        foreach ([$withholdingDueOn, $remittanceDueOn] as $date) {
            if ($date !== null) {
                EpoDate::requireIso($date, 'Datum na tiskopisu');
            }
        }
        if (count($remittances) > 99) {
            throw new \InvalidArgumentException(
                'Oznámení pojme nejvýš 99 odvodů sražené daně.',
            );
        }
    }

    /** Osvobozený příjem — sazba 0 a jen rok úhrady místo data. */
    public function isExemptIncome(): bool
    {
        return $this->rateTenthsOfPercent === 0;
    }

    /** Rok, do kterého oznámení věcně patří — pro archivaci a název souboru. */
    public function periodYear(): int
    {
        if ($this->paymentYear !== null) {
            return $this->paymentYear;
        }

        return (int) substr((string) $this->paymentDate, 0, 4);
    }

    /** Úhrn odvodů sražené daně v celých korunách. */
    public function remittedTotalCzk(): int
    {
        $total = 0;
        foreach ($this->remittances as $remittance) {
            $total += $remittance->amountCzk;
        }

        return $total;
    }

    /** @return array<string,mixed> */
    public function toSummary(): array
    {
        return [
            'form_code' => 'dpshl1',
            'variant' => $this->variant,
            'discovered_on' => $this->discoveredOn,
            'payee' => $this->payee->toSummary(),
            'income_kind' => $this->incomeKind,
            'income_kind_group' => ForeignIncomeKindCatalog::group($this->incomeKind),
            'income_kind_label' => ForeignIncomeKindCatalog::label($this->incomeKind),
            'rate_tenths_of_percent' => $this->rateTenthsOfPercent,
            'payment_mode' => $this->paymentMode,
            'payment_date' => $this->paymentDate,
            'payment_year' => $this->paymentYear,
            'paid_amount_minor' => $this->paidAmountMinor,
            'tax_base_minor' => $this->taxBaseMinor,
            'withheld_tax_czk' => $this->withheldTaxCzk,
            'withholding_due_on' => $this->withholdingDueOn,
            'remittance_due_on' => $this->remittanceDueOn,
            'gross_income_minor' => $this->grossIncomeMinor,
            'mandatory_insurance_czk' => $this->mandatoryInsuranceCzk,
            'foreign_gross_minor' => $this->foreignGrossMinor,
            'foreign_gross_currency' => $this->foreignGrossCurrency,
            'payment_currency' => $this->paymentCurrency,
            'exchange_rate_thousandths' => $this->exchangeRateThousandths,
            'note' => $this->note,
            'exempt_income' => $this->isExemptIncome(),
            'period_year' => $this->periodYear(),
            'remittances' => array_map(
                static fn (ForeignIncomeRemittance $row): array => $row->toSummary(),
                $this->remittances,
            ),
            'remitted_total_czk' => $this->remittedTotalCzk(),
            'warnings' => $this->warnings,
        ];
    }

    private static function assertCurrency(string $code): void
    {
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new \InvalidArgumentException(
                'Kód měny musí být trojmístný kód velkými písmeny (např. EUR).',
            );
        }
    }
}
