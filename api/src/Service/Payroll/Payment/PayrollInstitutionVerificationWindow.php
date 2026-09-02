<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

/**
 * Do kdy smí být ověřený platební účet, aby se z něj dal udělat závazek.
 *
 * ── Původní pravidlo a co na něm nesedělo ───────────────────────────────────
 * Materializace závazků odmítala účet, jehož `verified_on` je POZDĚJŠÍ než den
 * splatnosti. Smysl je zřejmý: nikdo nemá posílat peníze na účet, který v době
 * platby nikdo neověřil.
 *
 * Jenže tím padne každé ZPĚTNÉ zpracování období. Firma, která přebírá starší
 * měsíce z ruční rekapitulace (nebo dodělává zameškaný měsíc), má účty ověřené
 * dnes a splatnosti dávno za sebou — a materializace ji odmítne s hláškou
 * o neúčinném ověření, přestože účet ověřený JE a žádná platba se v době
 * splatnosti přes aplikaci neposílala. Jediná „cesta ven" by byla přepsat
 * datum ověření na dřívější, tedy zfalšovat doklad o ověření.
 *
 * ── Pravidlo teď ────────────────────────────────────────────────────────────
 * Ověření musí existovat NEJPOZDĚJI ke dni splatnosti, a u období, jehož
 * splatnost už uplynula, nejpozději dnes. Formálně: `verified_on` nesmí být
 * pozdější než pozdější z dvojice (splatnost, dnešek).
 *
 * Pro běžný běh se nic nemění — splatnost je v budoucnu, takže platí původní
 * mez. Kontrola „účet nesmí být ověřený až po platbě" zůstává i zpětně, jen se
 * měří k okamžiku, kdy se závazek zakládá: dřív než teď zaplatit nešlo.
 * Skutečnou úhradu pak stejně dokládá až spárování s bankovním pohybem.
 */
final class PayrollInstitutionVerificationWindow
{
    /**
     * Nejzazší přípustné datum ověření účtu pro závazek splatný `$dueOn`.
     *
     * @param ?string $today jen pro testy; jinak dnešek
     */
    public static function latestAcceptable(string $dueOn, ?string $today = null): string
    {
        $today ??= date('Y-m-d');

        return $dueOn >= $today ? $dueOn : $today;
    }
}
