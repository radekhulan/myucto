<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Pair;

use MyInvoice\Service\Accounting\JournalLineAmount;

/**
 * Dvojice zápisů typovaných zapisovačů (modul nemovitostí SVJ, kroky uzávěrky 504),
 * které nejdou přes PostingService a jejichž řádky hlídají vlastní důkazy.
 *
 * Jediné místo, které rozhoduje, jak se řádky takového zápisu v jednoduché formě
 * rozdělí ({@see pair()}), a jediné místo, kde důkazy porovnávají plánované řádky
 * (tvar složeného zápisu, ze kterého jsou i uložené otisky) se zapsaným zápisem
 * ({@see matches()}, {@see expectedFor()}). Obě strany volají tutéž deterministickou
 * funkci, takže důkaz zápisu po dvojicích je stejně přísný jako porovnání řádek po řádku.
 *
 * Pravidlo rozdělení:
 *  1. Je-li každá dvojice po sobě jdoucích řádků platná dvojice (builder skládá složky
 *     po dvojicích předkontací: pohledávka / fond, odhad / dohad, 933 / výsledkový účet),
 *     řádky se jen očíslují; pořadí, částky i `line_no` zůstávají, takže na zápis dál
 *     sedí i pozice řádků, na které se odkazují evidence modulu (účinky vyúčtování).
 *  2. Skládá-li builder zápis po pohybech (jeden řádek a za ním jeho protiřádky, např.
 *     vyúčtování služby: MD záloha / Dal několik dílčích nákladů), páruje se uvnitř
 *     každého pohybu (1 : N); pohyby zůstávají v pořadí a v pohybu jde díl hlavního
 *     řádku vždy před svým protiřádkem ({@see runs()}).
 *  3. Jinak rozhodne {@see JournalPairer} nad celým zápisem (1 : N, shodné částky).
 *  4. Co nejde ani tak, zůstane složené a vede se jako nezpůsobilé (`ineligible`).
 *
 * Firma se složenou formou tudy nejde: řádky i otisky zůstávají bajtově stejné.
 */
final class TypedJournalPairs
{
    /** Důvod nezpůsobilého typovaného zápisu: prefix k důvodu párovače. */
    public const REASON_PREFIX = 'typed_';

    /** Režim zapisovače: dvojice po sobě jdoucích řádků, jinak rozdělení párovačem. */
    public const SPLIT = 'split';
    /**
     * Režim zapisovače: jen dvojice po sobě jdoucích řádků, bez dělení řádků. Pro zápisy,
     * jejichž čtenáři porovnávají řádky jako množinu se stejným počtem (uzávěrka a otevření
     * rámce 504, {@see \MyInvoice\Service\Accounting\JournalLineComparison::sameLines()}).
     */
    public const CONSECUTIVE = 'consecutive';

    public const REASON_NOT_CONSECUTIVE = 'not_consecutive';

    /**
     * Rozdělí řádky typovaného zápisu na dvojice.
     *
     * @param list<array<string,mixed>> $lines
     * @param self::SPLIT|self::CONSECUTIVE $mode
     * @return array{lines: list<array<string,mixed>>, status: 'paired'|'unpairable', reason?: string}
     */
    public static function pair(array $lines, string $mode = self::SPLIT): array
    {
        $lines = self::withoutCarriedPairs($lines);
        if ($lines === []) {
            return ['lines' => [], 'status' => JournalPairer::STATUS_PAIRED];
        }
        if (self::consecutive($lines)) {
            foreach ($lines as $i => $line) {
                $lines[$i]['pair_no'] = intdiv($i, 2) + 1;
            }
            return ['lines' => $lines, 'status' => JournalPairer::STATUS_PAIRED];
        }
        if ($mode === self::CONSECUTIVE) {
            return ['lines' => $lines, 'status' => JournalPairer::STATUS_UNPAIRABLE, 'reason' => self::REASON_NOT_CONSECUTIVE];
        }
        $runs = self::runs($lines);
        if ($runs !== null) {
            foreach ($runs as $run => $indexes) {
                foreach ($indexes as $i) {
                    $lines[$i]['pair_group'] = 'typed:run:' . $run;
                }
            }
            $result = JournalPairer::pair($lines);
            if ($result['status'] === JournalPairer::STATUS_PAIRED) {
                return $result;
            }
            $lines = array_map(static function (array $line): array {
                unset($line['pair_group']);
                return $line;
            }, $lines);
        }
        return JournalPairer::pair($lines);
    }

    /**
     * Rozklad zápisu na pohyby: řádek se stranou prvního řádku zápisu a za ním jeden
     * nebo více řádků opačné účinné strany, každý pohyb vyrovnaný v haléřích. Řádky
     * s vlastním `pair_group` nebo zápis, který tak složený není, vrací null.
     *
     * @param list<array<string,mixed>> $lines
     * @return list<list<int>>|null indexy řádků po pohybech
     */
    public static function runs(array $lines): ?array
    {
        $head = JournalPairInvariant::effectiveSide($lines[0] ?? []);
        if ($head === null) {
            return null;
        }
        $runs = [];
        $balance = [];
        foreach (array_values($lines) as $i => $line) {
            if (isset($line['pair_group'])) {
                return null;
            }
            $side = JournalPairInvariant::effectiveSide($line);
            if ($side === null) {
                return null;
            }
            $cents = JournalLineAmount::cents($line['amount']);
            if ($side === $head) {
                if ($runs !== [] && count($runs[array_key_last($runs)]) < 2) {
                    return null;
                }
                $runs[] = [$i];
                $balance[] = $cents;
                continue;
            }
            $runs[array_key_last($runs)][] = $i;
            $balance[array_key_last($balance)] -= $cents;
        }
        if (count($runs[array_key_last($runs)]) < 2 || array_filter($balance, static fn (int $b): bool => $b !== 0) !== []) {
            return null;
        }
        return $runs;
    }

    /**
     * Řádky bez čísla a textu dvojice. Plánované řádky modulu nemovitostí přebírají
     * řádky zdrojových zápisů (protiřádek banky, řádek předpisu) i s jejich dvojicí;
     * ta do nového zápisu nepatří. Řídicí klíče builderu (`pair_group`) zůstávají.
     *
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    public static function withoutCarriedPairs(array $lines): array
    {
        return array_map(static function (array $line): array {
            unset($line['pair_no'], $line['line_text']);
            return $line;
        }, array_values($lines));
    }

    /** @param list<array<string,mixed>> $lines */
    public static function hasPairs(array $lines): bool
    {
        foreach ($lines as $line) {
            if (JournalPairInvariant::pairNoOf($line) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * Všechny tvary, ve kterých smí plánované řádky ležet v deníku po dvojicích:
     * rozdělení typovaného zapisovače ({@see pair()}), rozdělení PostingService
     * ({@see JournalPairer::pair()}, platba SVJ přes zaúčtování dokladu) a k oběma
     * zrcadlo storna: protizápis zápisu po dvojicích zachová pořadí řádků originálu,
     * takže v dvojici je první řádek s opačnou účinnou stranou, než by dal párovač.
     *
     * @param list<array<string,mixed>> $expected řádky v tvaru složeného zápisu
     * @return list<list<array<string,mixed>>>
     */
    public static function candidates(array $expected): array
    {
        $base = self::withoutCarriedPairs($expected);
        $out = [];
        foreach ([self::pair($base), JournalPairer::pair($base)] as $result) {
            if ($result['status'] !== JournalPairer::STATUS_PAIRED) {
                continue;
            }
            foreach ([$result['lines'], self::swapped($result['lines'])] as $candidate) {
                if (!in_array($candidate, $out, true)) {
                    $out[] = $candidate;
                }
            }
        }
        return $out;
    }

    /**
     * Odpovídají zapsané řádky plánovaným? Bez dvojic beze změny podle `$canonical`
     * (řádek po řádku). Má-li jedna strana dvojice, porovná se s tvary
     * {@see candidates()} druhé strany. Pořadí argumentů nerozhoduje.
     *
     * @param list<array<string,mixed>> $a
     * @param list<array<string,mixed>> $b
     * @param callable(list<array<string,mixed>>):mixed $canonical kanonický tvar řádků důkazu
     */
    public static function matches(array $a, array $b, callable $canonical): bool
    {
        $same = static fn (array $x, array $y): bool => $canonical($x) === $canonical($y);
        if ($same($a, $b)) {
            return true;
        }
        foreach ([[$a, $b], [$b, $a]] as [$paired, $planned]) {
            if (!self::hasPairs($paired)) {
                continue;
            }
            foreach (self::candidates($planned) as $candidate) {
                if ($same($candidate, $paired)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Plánované řádky v tvaru, v jakém leží zapsaný zápis: zápis bez dvojic = plán beze
     * změny, zápis po dvojicích = ten tvar z {@see candidates()}, který se shoduje
     * v účtech, účinných stranách a částkách (pro důkazy, které porovnávají řádek po
     * řádku podle pozice). Když se neshoduje žádný, vrací první tvar a důkaz selže sám.
     *
     * @param list<array<string,mixed>> $expected
     * @param list<array<string,mixed>> $actual
     * @return list<array<string,mixed>>
     */
    public static function expectedFor(array $expected, array $actual): array
    {
        if (!self::hasPairs($actual)) {
            return array_values($expected);
        }
        $candidates = self::candidates($expected);
        $byCode = true;
        foreach ([...array_values($expected), ...array_values($actual)] as $line) {
            if (!isset($line['account_code'])) {
                $byCode = false;
            }
        }
        $want = self::shape(array_values($actual), $byCode);
        foreach ($candidates as $candidate) {
            if (self::shape($candidate, $byCode) === $want) {
                return $candidate;
            }
        }
        return $candidates[0] ?? array_values($expected);
    }

    /**
     * Každé dvě po sobě jdoucí řádky jsou platná dvojice (sudý počet).
     *
     * @param list<array<string,mixed>> $lines
     */
    private static function consecutive(array $lines): bool
    {
        if (count($lines) % 2 !== 0) {
            return false;
        }
        for ($i = 0; $i < count($lines); $i += 2) {
            if (JournalPairInvariant::pairViolation($lines[$i], $lines[$i + 1]) !== null) {
                return false;
            }
        }
        return true;
    }

    /**
     * Prohodí řádky uvnitř každé dvojice (zrcadlo storna).
     *
     * @param list<array<string,mixed>> $lines dvojice vedle sebe
     * @return list<array<string,mixed>>
     */
    private static function swapped(array $lines): array
    {
        $out = [];
        for ($i = 0; $i + 1 < count($lines); $i += 2) {
            $out[] = $lines[$i + 1];
            $out[] = $lines[$i];
        }
        return $out;
    }

    /**
     * Tvar řádků pro výběr kandidáta: účet (kód, nebo id, když kód chybí), účinná strana
     * a částka v haléřích.
     *
     * @param list<array<string,mixed>> $lines
     * @return list<array{string,?string,int}>
     */
    private static function shape(array $lines, bool $byCode): array
    {
        return array_map(static fn (array $line): array => [
            $byCode ? (string) $line['account_code'] : 'id:' . (int) ($line['account_id'] ?? 0),
            JournalPairInvariant::effectiveSide($line),
            JournalLineAmount::cents($line['amount']),
        ], array_values($lines));
    }
}
