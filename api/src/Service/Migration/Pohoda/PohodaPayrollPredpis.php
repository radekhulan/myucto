<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

/**
 * Předpis mezd v deníku pro mzdové závazky bez vlastního zápisu (mzda zaměstnance, odvody
 * s předkontací „bez"): jejich předpis nese interní doklad mezd. Sdílí ho převod
 * ({@see OtherItemImporter}) a kontrola převedené firmy ({@see PohodaPayrollItemCheck}),
 * oba ho staví stejně přes {@see fromEntries()}.
 *
 * Měsíc předpisu závazku ({@see month()}): mzdy se zúčtují k poslednímu dni měsíce a závazek
 * nese datum výplaty nebo splatnosti v dalším měsíci. Měsíc dokladu tedy platí, jen když
 * hlavní předpis měsíce na účtu závazku (zápis s největší částkou na Dal účtu) je datovaný
 * nejpozději dnem dokladu, jinak je to měsíc předchozí. Drobný zápis na začátku měsíce
 * (oprava, srážka na Má dáti) měsíc nemění. Každý závazek tak patří právě k jednomu měsíci
 * a výsledek nezávisí na pořadí dokladů.
 *
 * Kapacita ({@see covers()}, {@see consume()}) = co předpis měsíce na syntetickém účtu nese
 * (v haléřích), zmenšené o závazky, které už na něm leží. Účet bez předpisu v deníku a měsíc
 * před začátkem převzatého deníku se nehlídají (předpis je jinde nebo v počátečním stavu).
 */
final class PohodaPayrollPredpis
{
    /** Haléřové zaokrouhlení mezd: koruna rezervy. */
    public const TOLERANCE_CENTS = 100;

    /**
     * @param array<string,array<string,int>> $capacity klíč účtu (`331|commitment`) => měsíc => haléře
     * @param array<string,array<string,string>> $mainDates klíč účtu => měsíc => den hlavního předpisu
     * @param array<string,string> $fallbackDates měsíc => den zaúčtování mezd mimo převzatý deník
     *        (načtení z PAMICY); platí pro účet, který v měsíci předpis nemá
     * @param string|null $startMonth první měsíc převzatého deníku
     */
    public function __construct(
        private array $capacity,
        private readonly array $mainDates,
        private readonly array $fallbackDates,
        private readonly ?string $startMonth,
    ) {}

    /**
     * Předpis ze zápisů deníku: jeden řádek = zápis a účet s částkou na Dal (u pohledávky
     * na Má dáti) v haléřích. Kapacita sčítá zápisy měsíce, den předpisu je den zápisu
     * s největší částkou.
     *
     * @param iterable<array{key:string,date:string,cents:int}> $entries
     * @param array<string,string> $fallbackDates
     */
    public static function fromEntries(iterable $entries, array $fallbackDates, ?string $startMonth): self
    {
        $capacity = [];
        $main = [];
        foreach ($entries as $e) {
            $month = substr($e['date'], 0, 7);
            $capacity[$e['key']][$month] = ($capacity[$e['key']][$month] ?? 0) + $e['cents'];
            $current = $main[$e['key']][$month] ?? null;
            if ($e['cents'] > 0 && ($current === null || $e['cents'] > $current['cents']
                    || ($e['cents'] === $current['cents'] && $e['date'] > $current['date']))) {
                $main[$e['key']][$month] = ['cents' => $e['cents'], 'date' => substr($e['date'], 0, 10)];
            }
        }
        $dates = array_map(static fn (array $months): array => array_map(static fn (array $m): string => $m['date'], $months), $main);
        return new self($capacity, $dates, $fallbackDates, $startMonth);
    }

    public function month(string $key, string $date): string
    {
        $own = substr($date, 0, 7);
        $main = $this->mainDates[$key][$own] ?? $this->fallbackDates[$own] ?? null;
        return $main !== null && $main <= substr($date, 0, 10) ? $own : self::previous($own);
    }

    public static function previous(string $month): string
    {
        return (new \DateTimeImmutable($month . '-01'))->modify('-1 month')->format('Y-m');
    }

    /** Volný předpis měsíce závazku v haléřích, `null` = nehlídá se. */
    public function remaining(string $key, string $date): ?int
    {
        if (!isset($this->capacity[$key]) || $this->startMonth === null) {
            return null;
        }
        $month = $this->month($key, $date);
        return $month < $this->startMonth ? null : ($this->capacity[$key][$month] ?? 0);
    }

    public function covers(string $key, string $date, int $cents): bool
    {
        $remaining = $this->remaining($key, $date);
        return $remaining === null || $cents <= $remaining + self::TOLERANCE_CENTS;
    }

    public function consume(string $key, string $date, int $cents): void
    {
        if ($this->remaining($key, $date) !== null) {
            $month = $this->month($key, $date);
            $this->capacity[$key][$month] = ($this->capacity[$key][$month] ?? 0) - $cents;
        }
    }

    /** Klíč kapacity: syntetický účet a agenda (`commitment` = Dal, `receivable` = Má dáti). */
    public static function key(string $account, string $agenda): string
    {
        return substr((string) preg_replace('/\D+/', '', $account), 0, 3) . '|' . $agenda;
    }
}
