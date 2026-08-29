<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\ForeignIncome;

use MyInvoice\Service\Report\EpoDate;

/**
 * Hlášení plátce daně o provedení srážky zajištění daně podle § 38e ZDP —
 * písemnost DPSZD1, tiskopis 25 5544.
 *
 * Stejně jako oznámení podle § 38da nemá zdaňovací období: podává se za každou
 * provedenou srážku zajištění daně. Celá věcná část je ve `VetaD`, ne v samostatné
 * větě — poplatník, příjem i částky jsou atributy hlavičky.
 *
 * ## Kdy zajištění daně vzniká
 *
 * Plátce sráží zajištění daně poplatníkovi, který **není daňovým rezidentem
 * členského státu EU ani dalšího státu EHP**, ze zdanitelných příjmů ze zdrojů
 * v ČR, z nichž se daň nevybírá srážkou (§ 38e odst. 1 a 2): 1 % z plnění
 * dluhopisu a vkladního listu a z prodeje investičních nástrojů, jinak 10 %.
 * U společníka veřejné obchodní společnosti a komplementáře komanditní
 * společnosti se místo sazby uvádí odkaz na § 16, resp. § 21 (odst. 3).
 * Zajištění se zaokrouhluje na celé koruny **nahoru** (odst. 5).
 *
 * ## Proč to není mzdové podání
 *
 * § 38e odst. 1 věta poslední: „K zajištění daně nejsou plátci daně povinni
 * v případě, kdy je záloha srážena z příjmů ze závislé činnosti." Ze mzdy tedy
 * zajištění daně nevznikne nikdy — hlášení se týká přijatých plnění, ne výplat
 * zaměstnancům. Aplikace u přijatých dokladů žádnou srážku zajištění neeviduje,
 * takže se všechny věcné údaje zadávají; dopočítat se nemá z čeho.
 *
 * Vyúčtování sraženého zajištění se nepodává vůbec (§ 38e odst. 12) — tohle
 * hlášení je jediné podání, které z § 38e plyne.
 */
final readonly class TaxSecurityNotice
{
    /** Typ hlášení (`hl_typ`). */
    public const TYP_RADNE = 'R';
    public const TYP_NASLEDNE = 'N';

    /** @var list<string> */
    public const TYPY = [self::TYP_RADNE, self::TYP_NASLEDNE];

    /**
     * Sazba zajištění daně (`sazba`) — zástupný znak, ne číslo. Některé volby
     * jsou sazbou, jiné odkazem na paragraf.
     */
    public const SAZBA_1_PROCENTO = 'A';
    public const SAZBA_10_PROCENT = 'B';
    public const SAZBA_ODKAZ_16 = 'C';
    public const SAZBA_ODKAZ_21 = 'D';
    /** Nulová sazba — jen v následném hlášení, pro nesprávně provedené zajištění. */
    public const SAZBA_NULA = 'E';

    /** @var list<string> */
    public const SAZBY = [
        self::SAZBA_1_PROCENTO,
        self::SAZBA_10_PROCENT,
        self::SAZBA_ODKAZ_16,
        self::SAZBA_ODKAZ_21,
        self::SAZBA_NULA,
    ];

    /**
     * @param string  $variant       `hl_typ`.
     * @param string  $incomeDescription `druh_prij` — druh zdanitelného příjmu
     *        s odkazem na příslušné ustanovení § 22 ZDP. Volný text, ne číselník.
     * @param string  $rate          `sazba` — A, B, C, D nebo E.
     * @param int     $incomeMinor   `kc_prijem` — zdanitelný příjem před srážkou,
     *        v haléřích. U plátce DPH bez daně z přidané hodnoty.
     * @param int     $securedTaxCzk `kc_zajisteni` — zajištění daně v celých
     *        korunách (§ 38e odst. 5 zaokrouhluje nahoru).
     * @param string  $receivableOn  `d_ucpripadu` — den vzniku pohledávky za plátcem.
     * @param string  $decisiveOn    `d_rozhodne` — datum výplaty, poukázání nebo připsání.
     * @param ?string $remittedOn    `d_odvodu` — datum odvodu zajištění.
     * @param ?string $permanentEstablishmentAddress `adr_provozovny_cr` — jen
     *        existuje-li stálá provozovna na území ČR.
     * @param ?string $note          `poznamka`.
     * @param list<string> $warnings
     */
    public function __construct(
        public string $variant,
        public ForeignPayee $payee,
        public string $incomeDescription,
        public string $rate,
        public int $incomeMinor,
        public int $securedTaxCzk,
        public string $receivableOn,
        public string $decisiveOn,
        public ?string $remittedOn = null,
        public ?string $permanentEstablishmentAddress = null,
        public ?string $note = null,
        public array $warnings = [],
    ) {
        if (!in_array($variant, self::TYPY, true)) {
            throw new \InvalidArgumentException(
                'Typ hlášení musí být R (řádné) nebo N (následné).',
            );
        }
        if (!in_array($rate, self::SAZBY, true)) {
            throw new \InvalidArgumentException(
                'Sazba zajištění daně musí být A, B, C, D nebo E.',
            );
        }
        if (trim($incomeDescription) === '') {
            throw new \InvalidArgumentException(
                'Uveď druh zdanitelného příjmu s odkazem na ustanovení § 22 ZDP.',
            );
        }

        // Kritické kontroly schématu: v řádném hlášení musí být obě částky > 0;
        // nulové smějí být jen v následném hlášení a jen se sazbou E.
        if ($incomeMinor < 0 || $securedTaxCzk < 0) {
            throw new \DomainException(
                'Zdanitelný příjem ani zajištění daně nesmí být záporné.',
            );
        }
        $isCorrection = $variant === self::TYP_NASLEDNE;
        if ($rate === self::SAZBA_NULA && !$isCorrection) {
            throw new \InvalidArgumentException(
                'Nulovou sazbu zajištění lze uvést jen v následném hlášení,'
                . ' u dříve nesprávně provedeného zajištění daně.',
            );
        }
        if (($incomeMinor === 0 || $securedTaxCzk === 0)
            && !($isCorrection && $rate === self::SAZBA_NULA)
        ) {
            throw new \DomainException(
                'V řádném hlášení musí být zdanitelný příjem i zajištění daně'
                . ' vyšší než nula.',
            );
        }

        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        foreach ([
            'Den vzniku pohledávky' => $receivableOn,
            'Rozhodné datum' => $decisiveOn,
        ] as $label => $date) {
            $parsed = EpoDate::requireIso($date, $label);
            if ($parsed->format('Y-m-d') < '1900-01-01' || $date > $today) {
                throw new \InvalidArgumentException(
                    $label . ' musí ležet mezi 1. 1. 1900 a dneškem.',
                );
            }
        }
        if ($remittedOn !== null) {
            EpoDate::requireIso($remittedOn, 'Datum odvodu zajištění daně');
            if ($remittedOn > $today) {
                throw new \InvalidArgumentException(
                    'Datum odvodu zajištění daně nesmí být v budoucnosti.',
                );
            }
        }
    }

    /** Rok, do kterého hlášení věcně patří — pro archivaci a název souboru. */
    public function periodYear(): int
    {
        return (int) substr($this->decisiveOn, 0, 4);
    }

    /** @return array<string,mixed> */
    public function toSummary(): array
    {
        return [
            'form_code' => 'dpszd1',
            'variant' => $this->variant,
            'payee' => $this->payee->toSummary(),
            'income_description' => $this->incomeDescription,
            'rate' => $this->rate,
            'income_minor' => $this->incomeMinor,
            'secured_tax_czk' => $this->securedTaxCzk,
            'receivable_on' => $this->receivableOn,
            'decisive_on' => $this->decisiveOn,
            'remitted_on' => $this->remittedOn,
            'permanent_establishment_address' => $this->permanentEstablishmentAddress,
            'note' => $this->note,
            'period_year' => $this->periodYear(),
            'warnings' => $this->warnings,
        ];
    }
}
