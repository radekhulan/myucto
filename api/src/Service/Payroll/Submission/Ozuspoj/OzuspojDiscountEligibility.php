<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Ozuspoj;

/**
 * Přehrání kontroly 291 katalogu kontrol MH nad vlastní evidencí záměrů.
 *
 * Kontrola 291 je u ČSSZ **propustná** — podání se přijme, sleva se ale neuzná
 * a zaměstnavatel se to dozví až z protokolu, kdy je pojistné dávno odvedené
 * ponížené. § 7c odst. 3 z toho udělá dluh na pojistném. Proto se stejná úvaha
 * musí udělat DŘÍV, u nás, a musí končit neuplatněním.
 *
 * Doslovné znění kontroly 291 (katalog kontrol MH 1.4.2.8):
 *
 * 1. „Trvání zaměstnání (10223, 10224) v průběhu vykazovaného měsíce (10010,
 *    10011), ze kterého je uplatňována sleva … musí spadat do období, na které
 *    byl oznámen záměr … Tedy (ZAMEST_OD) >= ZAMERY_SLEV.ZAMER_OD
 *    a (ZAMERY_SLEV.ZAMER_DO je nevyplněný nebo ZAMERY_SLEV.ZAMER_DO >= 10224)."
 *    `ZAMEST_OD` je přitom „vždy novější datum, buď 10223 (datum nástupu do
 *    zaměstnání) a nebo první den v měsíci".
 * 2. „ZAMERY_SLEV.DATUM_PRIJETI_FORMULARE ≤ …" — u období 01–03/2026 poslední
 *    den splatnosti pojistného, od 04/2026 datum přijetí měsíčního hlášení.
 *
 * Konec pokrytí se vědomě neposuzuje jen proti `10224`, ale proti konci trvání
 * zaměstnání V MĚSÍCI. § 7b odst. 4 žádá splnění podmínek „po celou dobu trvání
 * pracovního nebo služebního poměru v kalendářním měsíci"; záměr ukončený
 * v polovině měsíce tedy slevu za ten měsíc nezaloží, i když zaměstnání
 * pokračuje a `10224` je prázdné.
 */
final readonly class OzuspojDiscountEligibility
{
    public function __construct(
        private OzuspojClaimDeadlinePolicy $deadlines,
    ) {}

    /**
     * `claimedOn` je den, kdy se sleva skutečně uplatňuje, tedy den přijetí
     * měsíčního hlášení. Ve mzdovém výpočtu ještě neexistuje — tam se předává
     * `null` a horní mezí zůstává splatnost pojistného, kterou § 7c odst. 2
     * stejně nedovolí překročit.
     */
    public function assess(
        ?OzuspojIntentEvidence $intent,
        string $periodStart,
        string $periodEnd,
        ?string $employmentStartOn,
        ?string $employmentEndOn,
        ?string $claimedOn = null,
    ): OzuspojEligibilityVerdict {
        $intentDeadline = $this->deadlines->intentDeadlineFor($periodStart);
        $claimDeadline = $this->deadlines->claimDeadlineFor($periodStart);
        $transitional = $this->deadlines->isTransitionalQ12026($periodStart);
        $verdict = fn (
            OzuspojEligibilityOutcome $outcome,
            string $message,
        ): OzuspojEligibilityVerdict => new OzuspojEligibilityVerdict(
            $outcome,
            $message,
            $intentDeadline,
            $claimDeadline,
            $transitional,
        );

        if ($intent === null
            || !$intent->status->isEvidenced()
            || $intent->acceptedOn === null
        ) {
            return $verdict(
                OzuspojEligibilityOutcome::NotNotified,
                'Za zaměstnance není doložený přijatý záměr uplatňovat slevu (OZUSPOJ). Bez něj sleva podle § 7a odst. 5 nenáleží, proto se neuplatnila.',
            );
        }

        $coverageStart = $employmentStartOn !== null
            && $employmentStartOn > $periodStart
                ? $employmentStartOn
                : $periodStart;
        $coverageEnd = $employmentEndOn !== null
            && $employmentEndOn < $periodEnd
                ? $employmentEndOn
                : $periodEnd;
        if ($intent->intentFrom > $coverageStart
            || ($intent->intentTo !== null && $intent->intentTo < $coverageEnd)
        ) {
            return $verdict(
                OzuspojEligibilityOutcome::OutsideIntentPeriod,
                'Trvání zaměstnání v tomhle měsíci nespadá celé do období, na které je záměr oznámen. Sleva se neuplatnila; jinak by ji ČSSZ neuznala (kontrola 291).',
            );
        }

        if ($intent->acceptedOn > $intentDeadline
            || ($claimedOn !== null && $intent->acceptedOn > $claimedOn)
        ) {
            return $verdict(
                OzuspojEligibilityOutcome::NotifiedLate,
                'Záměr byl České správě sociálního zabezpečení doručen až po dni, kdy se sleva uplatňuje. § 7a odst. 5 žádá oznámení nejpozději s uplatněním slevy, takže se sleva neuplatnila.',
            );
        }

        if ($claimedOn !== null && $claimedOn > $claimDeadline) {
            return $verdict(
                OzuspojEligibilityOutcome::ClaimWindowClosed,
                $transitional
                    ? 'Slevu za leden až březen 2026 bylo nutné vykázat v měsíčním hlášení do 30. 6. 2026. Po tomhle dni ji ČSSZ neuzná, proto se neuplatnila.'
                    : 'Slevu lze uplatnit jen do dne splatnosti pojistného za tenhle měsíc (§ 7c odst. 2). Ten už uplynul, proto se sleva neuplatnila.',
            );
        }

        return $verdict(
            OzuspojEligibilityOutcome::Evidenced,
            'Záměr uplatňovat slevu je za tenhle měsíc doložený přijatým oznámením OZUSPOJ.',
        );
    }
}
