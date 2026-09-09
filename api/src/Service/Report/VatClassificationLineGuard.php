<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

/**
 * Kód klasifikace NENÍ číslo řádku přiznání.
 *
 * Původní seed (migrace 0037) pojmenoval většinu kódů číslem řádku, na který mířily
 * (`1` → ř. 1, `40` → ř. 40). U čtyř kódů to ale neplatí — kód se jmenuje stejně jako
 * ÚPLNĚ JINÝ řádek DPHDP3, seed to spletl a musely to opravovat migrace
 * `0063_vat_classifications_import_rc_and_cleanup.sql` a
 * `0106_vat_classifications_repair_drift.sql`. Nejdražší z těch záměn byl kód `42`
 * (přijaté plnění BEZ nároku na odpočet) namířený na ř. 42 (odpočet při dovozu zboží,
 * kdy daň vyměřuje celní úřad) — tichý neexistující odpočet v přiznání i v KH.
 *
 * Globální seed uživatel needituje, ale vlastní per-tenant kód si smí vytvořit
 * pod libovolným jménem — včetně `42` — a pak mu ručně nastavit řádek. Tahle třída je
 * jediné místo, kde je ta past pojmenovaná, a volají ji všechny zápisové cesty číselníku
 * ({@see \MyInvoice\Action\Codebook\VatClassificationsAction::validate()}).
 *
 * Záměrně je to VÝČET, ne pravidlo „číselný kód se nesmí rovnat řádku": dvojice
 * `1`→1, `2`→2, `20`→20, `40`→40, `41`→41 jsou správně a zakazovat je nelze. Výčet drží
 * jen ty kolize, které v číselníku PROKAZATELNĚ nastaly a musely se opravovat migrací —
 * doplňovat sem kód „pro jistotu" znamená zablokovat legitimní per-tenant klasifikaci.
 */
final class VatClassificationLineGuard
{
    /**
     * Kódy, jejichž jméno se shoduje s číslem JINÉHO řádku přiznání.
     *
     * `line` = řádek, který se pro tenhle kód zakazuje (ten stejnojmenný),
     * `code_means` = co kód znamená, `line_means` = co znamená stejnojmenný řádek,
     * `correct` = kam kód ve skutečnosti patří (null = nikam, nevykazuje se).
     *
     * @var array<string, array{line: string, code_means: string, line_means: string, correct: ?string}>
     */
    private const COLLISIONS = [
        '3' => [
            'line' => '3',
            'code_means' => 'osvobozené tuzemské plnění bez nároku na odpočet (§ 51)',
            'line_means' => 'pořízení zboží z jiného členského státu v základní sazbě',
            'correct' => '50',
        ],
        '22' => [
            'line' => '22',
            'code_means' => 'poskytnutí služby do jiného členského státu',
            'line_means' => 'vývoz zboží (§ 66)',
            'correct' => '21',
        ],
        '26' => [
            'line' => '26',
            'code_means' => 'vývoz zboží do 3. země',
            'line_means' => 'ostatní plnění s místem plnění mimo tuzemsko s nárokem na odpočet '
                . '(na to je kód 26s)',
            'correct' => '22',
        ],
        '42' => [
            'line' => '42',
            'code_means' => 'přijaté tuzemské plnění BEZ nároku na odpočet',
            'line_means' => 'odpočet daně při dovozu zboží, kdy daň vyměřuje celní úřad',
            'correct' => null,
        ],
    ];

    /**
     * Vrátí důvod odmítnutí, míří-li kód na stejnojmenný řádek, který znamená něco jiného.
     * `null` = dvojice je v pořádku.
     */
    public static function lineCollision(?string $code, ?string $line): ?string
    {
        $code = self::normalize($code);
        $line = self::normalize($line);
        if ($code === null || $line === null) {
            return null;
        }
        $collision = self::COLLISIONS[$code] ?? null;
        if ($collision === null || $collision['line'] !== $line) {
            return null;
        }

        return sprintf(
            'Kód „%s" se jmenuje stejně jako řádek %s přiznání, ale znamená něco jiného: '
                . 'kód %s = %s, zatímco řádek %s = %s. Číslo kódu není číslo řádku — %s',
            $code,
            $collision['line'],
            $code,
            $collision['code_means'],
            $collision['line'],
            $collision['line_means'],
            $collision['correct'] === null
                ? 'tenhle kód do přiznání nepatří vůbec (nech řádek prázdný).'
                : sprintf('pro tenhle význam je správný řádek %s.', $collision['correct']),
        );
    }

    /**
     * Kódy hlídané výčtem — pro testy a pro nápovědu v UI.
     *
     * @return list<string>
     */
    public static function collidingCodes(): array
    {
        return array_keys(self::COLLISIONS);
    }

    private static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
