<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\PausalSchedule;
use MyInvoice\Service\Tax\TaxConstants;

/**
 * Roční daňové konstanty: DB override (tabulka `tax_constants`, migrace 0079)
 * s fallbackem na ověřené defaulty v {@see TaxConstants}.
 *
 * Záměr: kód drží jediný ověřený zdroj (TaxConstants), admin může přes číselník
 * roční hodnoty přepsat bez nového release. Override = jeden řádek JSON na rok.
 */
final class TaxConstantsRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Efektivní konstanty pro rok: DB override má přednost per klíč, chybějící
     * klíče doplní default z kódu (override uložený starší verzí aplikace nezná
     * později přidané konstanty — bez merge by je "ztratil").
     *
     * Obecné služby mohou použít nejbližší známou sadu; doménové kalkulátory,
     * u nichž je historický fallback nebezpečný, ověřují rok výsledné sady.
     * @return array<string,mixed>
     */
    public function forYear(int $year): array
    {
        $override = $this->override($year);
        if ($override !== null) {
            $default = in_array($year, TaxConstants::availableYears(), true)
                ? TaxConstants::forYear($year)
                : ['year' => $year];
            return $this->merge($default, $override, $year);
        }
        if (in_array($year, TaxConstants::availableYears(), true)) {
            return TaxConstants::forYear($year);
        }
        $fallback = $this->nearestKnownYear($year);
        $defaultYear = in_array($fallback, TaxConstants::availableYears(), true)
            ? $fallback
            : $this->nearestBuiltInYear($fallback);
        $default = TaxConstants::forYear($defaultYear);
        $fallbackOverride = $this->override($fallback);
        return $fallbackOverride !== null ? $this->merge($default, $fallbackOverride, $year) : $default;
    }

    /** Konstanty pro daňové přiznání bez meziričního fallbacku. */
    public function forExactYear(int $year): array
    {
        $override = $this->override($year);
        if (in_array($year, TaxConstants::availableYears(), true)) {
            $default = TaxConstants::forYear($year);
            return $override !== null ? $this->merge($default, $override, $year) : $default;
        }
        if ($override !== null) {
            return $this->merge(['year' => $year], $override, $year);
        }
        throw new \OutOfRangeException('Pro rok ' . $year . ' nejsou ověřené daňové konstanty ani DB override.');
    }

    /**
     * Sloučení defaultu s override + přepočet odvozených klíčů.
     *
     * `pausal_annual` je odvozená hodnota z `pausal_monthly`, takže se po merge
     * VŽDY přepočítá — jinak by ji uložený (a po legislativní změně zastaralý)
     * override tiše přebil. Override, který zná jen roční částky (uložený starší
     * verzí aplikace), si svoji roční hodnotu ponechá; rozvrh se k ní jen dopočítá.
     *
     * @param array<string,mixed> $default
     * @param array<string,mixed> $override
     * @return array<string,mixed>
     */
    private function merge(array $default, array $override, int $year): array
    {
        $legacy = !isset($override['pausal_monthly']) && isset($override['pausal_annual']);
        if ($legacy) {
            unset($default['pausal_monthly']);
        }
        return TaxConstants::withDerived($this->deepMerge($default, $override), $year);
    }

    private function nearestKnownYear(int $year): int
    {
        $dbYears = array_map(
            'intval',
            $this->db->pdo()->query('SELECT year FROM tax_constants')->fetchAll(\PDO::FETCH_COLUMN)
        );
        $known = array_unique([...TaxConstants::availableYears(), ...$dbYears]);
        $below = array_filter($known, static fn (int $y): bool => $y < $year);
        return $below !== [] ? max($below) : min($known);
    }

    private function nearestBuiltInYear(int $year): int
    {
        $known = TaxConstants::availableYears();
        $below = array_filter($known, static fn (int $y): bool => $y < $year);
        return $below !== [] ? max($below) : min($known);
    }

    /** Limit KH pro rozdělení A.4/A.5 a B.2/B.3 (nad → jednotlivě, do → sumace). */
    public function khItemThreshold(int $year): float
    {
        return (float) $this->forYear($year)['kh_item_threshold'];
    }

    /**
     * Celounijní práh pro přeshraniční B2C plnění (§ 8 odst. 3 / § 10i ZDPH).
     * Je v EUR, ne v Kč — je společný pro všechny členské státy.
     */
    public function ossThresholdEur(int $year): float
    {
        return (float) $this->forYear($year)['oss_threshold_eur'];
    }

    /** Základní sazba DPH (§ 47 ZDPH) pro rok — např. pro samovyměření RC. */
    public function vatRateStandard(int $year): float
    {
        return (float) $this->forYear($year)['vat_rate_standard'];
    }

    /**
     * Přirážka k repo sazbě ČNB u zákonného úroku z prodlení (NV č. 351/2013 Sb., § 2),
     * v procentních bodech. Roky mimo číselník spadnou na nejbližší známý rok — přirážka
     * je 8 bodů beze změny od 1. 7. 2013, tedy pro celou dobu, kterou pokrývají repo sazby.
     */
    public function penaltyRepoSurchargePoints(int $year): float
    {
        return (float) ($this->forYear($year)['penalty_repo_surcharge_points']
            ?? \MyInvoice\Service\Penalty\PenaltyInterestCalculator::SURCHARGE_POINTS);
    }

    /**
     * Práh pro bucket "základní vs snížená sazba" (EPO formuláře mají právě dva
     * sloupce zakl_dane1/zakl_dane2) = střed mezi sazbami daného roku. Pro 21/12 %
     * je to 16,5 — řadí korektně i historickou sníženou 15 % (< 16,5 → snížená),
     * a na rozdíl od dřívějšího natvrdo 20,5 přežije změnu základní sazby.
     */
    public function vatBucketThreshold(int $year): float
    {
        $c = $this->forYear($year);
        return ((float) $c['vat_rate_standard'] + (float) $c['vat_rate_reduced']) / 2.0;
    }

    /**
     * DB override pro rok, nebo null když není.
     * @return array<string,mixed>|null
     */
    public function override(int $year): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT data FROM tax_constants WHERE year = ?');
        $stmt->execute([$year]);
        $json = $stmt->fetchColumn();
        if ($json === false || $json === null) {
            return null;
        }
        $data = json_decode((string) $json, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Seznam roků pro editor: sjednocení defaultních roků a roků s DB override,
     * každý s efektivními daty a příznakem, zda jde o override.
     * @return list<array{year:int,is_override:bool,data:array<string,mixed>}>
     */
    public function listEffective(): array
    {
        $dbYears = array_map(
            'intval',
            $this->db->pdo()->query('SELECT year FROM tax_constants')->fetchAll(\PDO::FETCH_COLUMN)
        );
        $all = array_values(array_unique([...TaxConstants::availableYears(), ...$dbYears]));
        rsort($all);

        $out = [];
        foreach ($all as $year) {
            $override = $this->override($year);
            $out[] = [
                'year'        => $year,
                'is_override' => $override !== null,
                // Merge jako forYear() — starý override nesmí v editoru "ztratit"
                // později přidané konstanty.
                'data'        => $override !== null
                    ? $this->merge(
                        in_array($year, TaxConstants::availableYears(), true)
                            ? TaxConstants::forYear($year)
                            : ['year' => $year],
                        $override,
                        $year,
                    )
                    : TaxConstants::forYear($year),
            ];
        }
        return $out;
    }

    /**
     * Uloží/přepíše override pro rok.
     *
     * Je-li přítomný rozvrh měsíčních záloh, roční částka se NEUKLÁDÁ — je
     * odvozená a uložená kopie by se po další změně sazby rozešla s realitou.
     * @param array<string,mixed> $data
     */
    public function upsert(int $year, array $data): void
    {
        $data['year'] = $year; // konzistence s klíčem
        if (isset($data['pausal_monthly'])) {
            $data['pausal_monthly'] = PausalSchedule::normalize($data['pausal_monthly']);
            unset($data['pausal_annual']);
        }
        $json = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->db->pdo()->prepare(
            'INSERT INTO tax_constants (year, data) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE data = VALUES(data)'
        )->execute([$year, $json]);
    }

    /** Smaže override (reset na default). Vrací true, pokud řádek existoval. */
    public function reset(int $year): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM tax_constants WHERE year = ?');
        $stmt->execute([$year]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Hloubkový merge — override smí přepsat jediný vnořený klíč, aniž by
     * shodil své sourozence (seznamy se nahrazují celé).
     *
     * @param array<string|int,mixed> $base
     * @param array<string|int,mixed> $override
     * @return array<string|int,mixed>
     */
    private function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($value)) {
                $base[$key] = $this->deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}
