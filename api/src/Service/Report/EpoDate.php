<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

/**
 * Datum ve tvaru, který EPO schémata popisují typem `dateInMultiFormat`
 * (`j.n.Y` — den a měsíc bez vodicí nuly, rok čtyřmístně).
 *
 * Aplikace drží data v ISO `Y-m-d`, tiskopisy je chtějí česky. Převod byl dosud
 * rozepsaný v každém generátoru zvlášť (`TaxStatementXmlBuilder::applyDiscoveryDate()`,
 * `TaxBonusClaim::bonusDateEpo()`), a to je přesně ta duplicita, na které se
 * kopie dřív nebo později rozejdou. Ověření tvaru a převod patří na jedno místo.
 */
final class EpoDate
{
    /**
     * ISO `Y-m-d` → `j.n.Y`. Vstup musí být kalendářně platné datum, ne jen
     * řetězec správného tvaru — `2025-02-30` projde regulárním výrazem, ale
     * datum to není.
     */
    public static function toEpo(string $isoDate, string $label): string
    {
        return self::requireIso($isoDate, $label)->format('j.n.Y');
    }

    public static function requireIso(string $isoDate, string $label): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $isoDate);
        if ($date === false || $date->format('Y-m-d') !== $isoDate) {
            throw new \InvalidArgumentException(
                $label . ' není platné datum ve tvaru RRRR-MM-DD.',
            );
        }

        return $date;
    }
}
