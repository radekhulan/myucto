<?php

declare(strict_types=1);

namespace MyInvoice\Support;

/**
 * Rychlý filtr „měsíc a rok" pro dlouhé seznamy (podání, zprávy datové schránky).
 *
 * Why: přehledy rostou o desítky řádků měsíčně a nic se z nich nemaže, takže
 * i se stránkováním je hledání konkrétního měsíce listování naslepo. Filtr
 * je proto jeden a týž na všech takových seznamech — kdyby si ho každý postavil
 * po svém, rozešly by se tvary parametru i to, co znamená prázdná hodnota.
 *
 * Parametry jsou dva a nezávislé: `year` (RRRR) a `month` (1–12). Samotný rok
 * dává celý rok, samotný měsíc dává ten měsíc napříč roky (na to se lidé ptají
 * u sezónních věcí), obojí dohromady jeden měsíc. Prázdné = bez omezení.
 */
final readonly class PeriodFilter
{
    private function __construct(
        public ?int $year,
        public ?int $month,
    ) {}

    /**
     * Přečte filtr z query parametrů. Nesmyslná hodnota je CHYBA, ne tiché
     * ignorování — filtr, který se sám vypne, ukáže víc řádků, než uživatel
     * čeká, a on si toho nemusí všimnout.
     *
     * @param array<string,mixed> $params
     */
    public static function fromQuery(array $params): self
    {
        return new self(
            self::intOrNull($params['year'] ?? null, 1900, 2999, 'Rok'),
            self::intOrNull($params['month'] ?? null, 1, 12, 'Měsíc'),
        );
    }

    public static function none(): self
    {
        return new self(null, null);
    }

    public function isEmpty(): bool
    {
        return $this->year === null && $this->month === null;
    }

    /**
     * Podmínka do `WHERE` nad zadaným výrazem s datem, včetně parametrů.
     *
     * Porovnává se přes `YEAR()`/`MONTH()`, ne rozsahem: sloupce jsou tu různé
     * (`sent_at`, `delivered_at`, `period_start`) a část z nich je NULLable,
     * takže rozsah by musel řešit každý volající zvlášť. Seznamy jsou stránkované
     * a řádově v tisících, takže na plánu dotazu to nehraje roli.
     *
     * @return array{sql:string,params:list<int>}
     */
    public function sqlFor(string $dateExpression): array
    {
        $sql = '';
        $params = [];
        if ($this->year !== null) {
            $sql .= ' AND YEAR(' . $dateExpression . ') = ?';
            $params[] = $this->year;
        }
        if ($this->month !== null) {
            $sql .= ' AND MONTH(' . $dateExpression . ') = ?';
            $params[] = $this->month;
        }

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * Totéž pro tabulku, která rok a měsíc drží ve VLASTNÍCH sloupcích
     * (načtené protokoly ČSSZ). Přepočítávat je na datum jen kvůli filtru by
     * znamenalo skládat datum z hodnot, které můžou být `NULL` každá zvlášť.
     *
     * @return array{sql:string,params:list<int>}
     */
    public function sqlForColumns(string $yearColumn, string $monthColumn): array
    {
        $sql = '';
        $params = [];
        if ($this->year !== null) {
            $sql .= ' AND ' . $yearColumn . ' = ?';
            $params[] = $this->year;
        }
        if ($this->month !== null) {
            $sql .= ' AND ' . $monthColumn . ' = ?';
            $params[] = $this->month;
        }

        return ['sql' => $sql, 'params' => $params];
    }

    private static function intOrNull(mixed $value, int $min, int $max, string $label): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_scalar($value) || preg_match('/^\d{1,4}$/D', (string) $value) !== 1) {
            throw new \InvalidArgumentException($label . ' musí být celé číslo.');
        }
        $number = (int) $value;
        if ($number < $min || $number > $max) {
            throw new \InvalidArgumentException(
                sprintf('%s musí být v rozsahu %d–%d.', $label, $min, $max),
            );
        }

        return $number;
    }
}
