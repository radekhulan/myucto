<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\TaxBonus;

/**
 * Jedna žádost o poukázání chybějící částky na daňovém bonusu — tři řádky
 * tiskopisu a období, za které se žádá.
 *
 * Částky jsou v CELÝCH KORUNÁCH, protože obě schémata (`dpzmb1_epo2.xsd`,
 * `dpzdb1_epo2.xsd`) mají u `kc_*` `fractionDigits="0"`. Převod z haléřů dělá
 * {@see TaxBonusClaimCalculator}, který na nedělitelný zbytek upozorní; tady
 * už je hodnota taková, jaká půjde do XML.
 */
final readonly class TaxBonusClaim
{
    public const FORM_MONTHLY = 'dpzmb1';
    public const FORM_ANNUAL = 'dpzdb1';

    /**
     * @param string $formCode `dpzmb1` (§ 35d odst. 5) nebo `dpzdb1` (§ 35d odst. 9).
     * @param int $bonusYear Rok období. U DPZMB1 jde o rok měsíce (`bonus_rok`),
     *        u DPZDB1 o zdaňovací období (`bonus_zdobd`).
     * @param ?int $bonusMonth Měsíc (`bonus_mesic`) — jen u DPZMB1, u DPZDB1 null.
     * @param string $bonusDate Datum výplaty bonusu (`d_bonus`), formát `Y-m-d`.
     * @param int $bonusTotalCzk Ř. 1 `kc_bonus_celk` — úhrn vyplacených bonusů.
     * @param int $advancesCzk Ř. 2 `kc_zalohy` — úhrn sražených záloh použitých
     *        na úhradu bonusů; XSD vyžaduje 0 ≤ ř. 2 ≤ ř. 1.
     * @param int $ownFundsCzk Ř. 3 `kc_bonus_vl` — vyplaceno z vlastních prostředků,
     *        tedy částka, o jejíž poukázání se žádá.
     * @param list<string> $warnings
     */
    public function __construct(
        public string $formCode,
        public int $bonusYear,
        public ?int $bonusMonth,
        public string $bonusDate,
        public int $bonusTotalCzk,
        public int $advancesCzk,
        public int $ownFundsCzk,
        public array $warnings = [],
    ) {
        if (!in_array($formCode, [self::FORM_MONTHLY, self::FORM_ANNUAL], true)) {
            throw new \InvalidArgumentException(
                'Neznámý tiskopis žádosti o daňový bonus: ' . $formCode,
            );
        }
        if ($formCode === self::FORM_MONTHLY
            ? ($bonusMonth === null || $bonusMonth < 1 || $bonusMonth > 12)
            : $bonusMonth !== null
        ) {
            throw new \InvalidArgumentException(
                'DPZMB1 se podává za měsíc, DPZDB1 za zdaňovací období.',
            );
        }
        // Kritické kontroly z obou XSD. Držíme je tady, ne až u generování XML:
        // podání odmítnuté portálem na kontrole je pro uživatele stejná ztráta
        // jako chyba ve výpočtu, jen se projeví později.
        if ($bonusTotalCzk <= 0) {
            throw new \DomainException(
                'Žádost lze podat jen z kladné částky vyplacených bonusů (ř. 1).',
            );
        }
        if ($advancesCzk < 0 || $advancesCzk > $bonusTotalCzk) {
            throw new \DomainException(
                'Úhrn záloh (ř. 2) musí být mezi nulou a úhrnem bonusů (ř. 1).',
            );
        }
        if ($ownFundsCzk !== $bonusTotalCzk - $advancesCzk) {
            throw new \DomainException(
                'Vlastní prostředky (ř. 3) musí být rozdílem řádků 1 a 2.',
            );
        }
        if ($ownFundsCzk <= 0) {
            throw new \DomainException(
                'Není o co žádat — bonusy se celé pokryly ze sražených záloh.',
            );
        }
        if (\DateTimeImmutable::createFromFormat('!Y-m-d', $bonusDate) === false) {
            throw new \InvalidArgumentException(
                'Datum výplaty bonusu není platné datum.',
            );
        }
    }

    /** Tvar `d.m.Y`, který obě schémata chtějí v `d_bonus` (`dateInMultiFormat`). */
    public function bonusDateEpo(): string
    {
        return (new \DateTimeImmutable($this->bonusDate))->format('j.n.Y');
    }

    /** @return array<string,mixed> */
    public function toSummary(): array
    {
        return [
            'form_code' => $this->formCode,
            'bonus_year' => $this->bonusYear,
            'bonus_month' => $this->bonusMonth,
            'bonus_date' => $this->bonusDate,
            'kc_bonus_celk' => $this->bonusTotalCzk,
            'kc_zalohy' => $this->advancesCzk,
            'kc_bonus_vl' => $this->ownFundsCzk,
            'warnings' => $this->warnings,
        ];
    }
}
