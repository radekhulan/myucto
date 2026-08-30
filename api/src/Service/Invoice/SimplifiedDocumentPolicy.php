<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * § 30 a § 30a ZDPH — kdy lze vystavit ZJEDNODUŠENÝ daňový doklad.
 *
 * Systém tenhle institut neznal vůbec, přestože se vystavuje při každém pokladním prodeji
 * a pokladnu systém má.
 *
 * ── Co zjednodušený doklad je ───────────────────────────────────────────────
 * Pořád je to daňový doklad, jen s méně náležitostmi: nemusí obsahovat označení ani DIČ
 * odběratele, jednotkovou cenu, základ daně ani výši daně (§ 30a odst. 1). Proto se
 * eviduje PŘÍZNAKEM na faktuře, ne zvláštním typem dokladu.
 *
 * ── Limit ───────────────────────────────────────────────────────────────────
 * Celková částka za plnění VČETNĚ DANĚ nesmí být vyšší než 10 000 Kč (§ 30 odst. 1).
 * Rozhoduje částka s daní, ne základ — na základ se dá bez povšimnutí vejít i doklad,
 * který je s daní nad limitem.
 *
 * ── Výjimky jsou to podstatné (§ 30 odst. 2) ────────────────────────────────
 * Zjednodušený doklad NELZE vystavit u:
 *   • dodání zboží do jiného členského státu osvobozeného s nárokem na odpočet (ř. 20),
 *   • prodeje zboží na dálku s místem plnění v tuzemsku,
 *   • plnění v režimu přenesené daňové povinnosti (ř. 25).
 *
 * Ve všech třech případech odběratel své identifikační údaje na dokladu POTŘEBUJE — bez
 * nich se plnění nedá vykázat v souhrnném ani kontrolním hlášení. Vystavit zjednodušený
 * doklad tam, kde nesmí, tedy neznamená jen formální vadu: rozbije to výkazy.
 *
 * Vývoz do 3. země (ř. 22) mezi výjimkami NENÍ — § 30 odst. 2 ho neuvádí a dopisovat
 * zákazy nad rámec zákona by uživateli bránilo v něčem, co mu zákon dovoluje.
 */
final class SimplifiedDocumentPolicy
{
    /**
     * Fallback default, kdyby daný rok neměl v TaxConstants klíč (nemělo by nastat).
     * Primárně se čte {@see \MyInvoice\Repository\TaxConstantsRepository::forYear()}
     * pro rok dokladu — volající ho předává jako `$limitWithVat`.
     */
    public const LIMIT_WITH_VAT = 10000.0;

    /**
     * Klasifikace, u kterých § 30 odst. 2 zjednodušený doklad zakazuje — podle řádku
     * přiznání, ne podle kódu: kódy jsou v číselníku editovatelné, řádek přiznání ne.
     */
    private const FORBIDDEN_LINES = [
        '20' => 'dodání zboží do jiného členského státu (§ 30 odst. 2 písm. a)',
        '25' => 'režim přenesené daňové povinnosti (§ 30 odst. 2 písm. c)',
    ];

    /**
     * Důvod, proč zjednodušený doklad vystavit NELZE; `null` = lze.
     *
     * @param array<string,mixed> $invoice hlavička dokladu
     */
    public static function rejectionReason(array $invoice, ?string $dphdp3Line, float $limitWithVat = self::LIMIT_WITH_VAT): ?string
    {
        $totalWithVat = abs((float) ($invoice['total_with_vat'] ?? 0));
        if ($totalWithVat > $limitWithVat) {
            return sprintf(
                'Zjednodušený daňový doklad lze vystavit jen do %s Kč včetně daně '
                    . '(§ 30 odst. 1 ZDPH); doklad je na %s Kč.',
                number_format($limitWithVat, 0, ',', ' '),
                number_format($totalWithVat, 2, ',', ' '),
            );
        }

        if (!empty($invoice['reverse_charge'])) {
            return 'V režimu přenesené daňové povinnosti nelze zjednodušený daňový doklad '
                . 'vystavit (§ 30 odst. 2 ZDPH) — odběratel na dokladu potřebuje své DIČ, '
                . 'jinak plnění nelze uvést v kontrolním hlášení.';
        }

        if ($dphdp3Line !== null && isset(self::FORBIDDEN_LINES[$dphdp3Line])) {
            return sprintf(
                'U tohoto plnění nelze zjednodušený daňový doklad vystavit — %s. '
                    . 'Odběratel na dokladu potřebuje své identifikační údaje, jinak plnění '
                    . 'nelze vykázat v souhrnném ani kontrolním hlášení.',
                self::FORBIDDEN_LINES[$dphdp3Line],
            );
        }

        return null;
    }
}
