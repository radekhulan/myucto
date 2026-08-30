<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use PDO;

/**
 * Úprava odpočtu daně u dlouhodobého majetku — § 78 až § 78e ZDPH (ř. 60 přiznání).
 *
 * Systém tuhle povinnost do teď neměl vůbec: atribut `uprav_odp` měl v celém repozitáři
 * nula výskytů. U nemovitosti nebo majetku pořízeného s kráceným nárokem (§ 76) je
 * přitom úprava POVINNÁ po celou 5/10letou lhůtu — a nikdo na ni neupozornil ani ji
 * nespočítal.
 *
 * ── Co služba počítá ────────────────────────────────────────────────────────
 * Pro každý evidovaný majetek ve lhůtě porovná POMĚR, v jakém se odpočet uplatnil při
 * pořízení, s poměrem v daném roce. Podle § 78a odst. 3 se úprava provede jen tehdy,
 * když se poměry liší o VÍC NEŽ 10 procentních bodů — do deseti bodů se neupravuje.
 *
 *     úprava = původní daň × (aktuální poměr − původní poměr) / 100 / délka lhůty
 *
 * Částka může být kladná i záporná (XSD anotace `uprav_odp`): poměr použití se posune
 * oběma směry a úprava ho následuje.
 *
 * ── Odkud se bere „aktuální poměr" ──────────────────────────────────────────
 * U majetku pořízeného s KRÁCENÝM nárokem (§ 76) je to vypořádací koeficient roku —
 * ten systém umí spočítat z dat ({@see DphPriznaniBuilder::computeAnnualCoefficient}).
 * U majetku s POMĚRNÝM odpočtem (§ 75) koeficient neplatí; poměr určuje skutečné
 * použití, které z dokladů odvodit nelze, a proto se bere ručně zadaná hodnota roku.
 * Chybí-li, majetek se do úpravy nezapočítá a vrátí se jako `needs_input` — tiše
 * dosadit původní poměr by znamenalo tvrdit, že se použití nezměnilo, což nevíme.
 *
 * ── Co záměrně NEŘEŠÍ ───────────────────────────────────────────────────────
 * Jednorázové úpravy podle § 78d (dodání zboží ve lhůtě), § 78da (nemovitost) a § 78e
 * (zničení, ztráta, odcizení). Ty se uvádějí v období, kdy nastaly, ne v posledním
 * období roku, a jejich spouštěčem je konkrétní událost na dokladu. Vedeno jako
 * samostatná díra — polovičatá implementace by tu byla horší než žádná, protože by
 * budila dojem, že je pokryto všechno.
 *
 * Read-only vůči účetnictví: nic neúčtuje, jen počítá a eviduje.
 */
final class VatDeductionAdjustmentService
{
    /**
     * Fallback default, kdyby daný rok neměl v TaxConstants klíč (nemělo by nastat).
     * Primárně se čte {@see TaxConstantsRepository::forYear()} pro rok úpravy.
     */
    public const RATIO_TOLERANCE_POINTS = 10;

    /** § 78 odst. 3 ZDPH — přípustné lhůty pro úpravu odpočtu (roky), fallback. */
    public const PERIOD_YEARS = [5, 10];

    public function __construct(
        private readonly Connection $db,
        private readonly TaxConstantsRepository $taxConstants,
    ) {}

    /**
     * Majetek ve lhůtě úpravy pro daný rok i s vypočtenou úpravou.
     *
     * @param ?int $annualCoefficientPct vypořádací koeficient § 76 pro rok; `null` = neznámý
     *
     * @return list<array{
     *   id:int, label:string, acquired_on:string, period_years:int,
     *   original_vat:float, original_ratio_pct:int, current_ratio_pct:?int,
     *   amount:float, applies:bool, needs_input:bool, reason:string
     * }>
     */
    public function previewYear(int $supplierId, int $year, ?int $annualCoefficientPct = null): array
    {
        $tolerance = (int) ($this->taxConstants->forYear($year)['vat_adjustment_tolerance_points'] ?? self::RATIO_TOLERANCE_POINTS);
        $out = [];
        foreach ($this->itemsInPeriod($supplierId, $year) as $row) {
            $originalRatio = (int) $row['original_ratio_pct'];
            $manualRatio = $row['current_ratio_pct'] !== null ? (int) $row['current_ratio_pct'] : null;

            // Ruční hodnota roku má přednost — u § 75 je jediným zdrojem pravdy
            // a u § 76 umožňuje účetní koeficient přebít doloženým skutečným použitím.
            $currentRatio = $manualRatio ?? $annualCoefficientPct;

            if ($currentRatio === null) {
                $out[] = $this->row($row, null, 0.0, false, true, 'Chybí poměr použití pro tento rok.');
                continue;
            }

            $diff = $currentRatio - $originalRatio;
            if (abs($diff) <= $tolerance) {
                $out[] = $this->row($row, $currentRatio, 0.0, false, false, sprintf(
                    'Rozdíl %+d p. b. nepřesahuje %d — § 78a odst. 3 úpravu nevyžaduje.',
                    $diff,
                    $tolerance,
                ));
                continue;
            }

            $periodYears = max(1, (int) $row['period_years']);
            $amount = round((float) $row['original_vat'] * $diff / 100 / $periodYears, 2);

            $out[] = $this->row($row, $currentRatio, $amount, true, false, sprintf(
                'Rozdíl %+d p. b.; 1/%d z %s Kč.',
                $diff,
                $periodYears,
                number_format((float) $row['original_vat'], 2, ',', ' '),
            ));
        }

        return $out;
    }

    /**
     * Součet úprav za rok pro ř. 60 přiznání. Zaokrouhleno na celé Kč (XSD
     * `fractionDigits=0`), sečteno až po zaokrouhlení jednotlivých položek —
     * shodně s tím, jak se v rozpisu ukáže účetní.
     */
    public function totalForReturn(int $supplierId, int $year, ?int $annualCoefficientPct = null): float
    {
        $total = 0.0;
        foreach ($this->previewYear($supplierId, $year, $annualCoefficientPct) as $item) {
            if ($item['applies']) {
                $total += $item['amount'];
            }
        }

        return round($total);
    }

    /**
     * Zaeviduje majetek do lhůty úpravy. Vrací id záznamu.
     *
     * @param array{purchase_invoice_id?:?int, asset_id?:?int, note?:?string} $links
     */
    public function register(
        int $supplierId,
        string $label,
        string $acquiredOn,
        int $periodYears,
        float $originalVat,
        int $originalRatioPct,
        array $links = [],
    ): int {
        $allowedPeriodYears = array_map('intval', (array) (
            $this->taxConstants->forYear((int) substr($acquiredOn, 0, 4))['vat_adjustment_period_years'] ?? self::PERIOD_YEARS
        ));
        if (!in_array($periodYears, $allowedPeriodYears, true)) {
            throw new \InvalidArgumentException('Lhůta úpravy je podle § 78 odst. 3 pět nebo deset let.');
        }
        if ($originalRatioPct < 0 || $originalRatioPct > 100) {
            throw new \InvalidArgumentException('Poměr uplatnění odpočtu musí být 0–100 %.');
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO vat_deduction_adjustments
                (supplier_id, purchase_invoice_id, asset_id, label, acquired_on,
                 period_years, original_vat, original_ratio_pct, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId,
            $links['purchase_invoice_id'] ?? null,
            $links['asset_id'] ?? null,
            $label,
            $acquiredOn,
            $periodYears,
            round($originalVat, 2),
            $originalRatioPct,
            $links['note'] ?? null,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Ruční poměr použití pro rok (§ 75, nebo doložené skutečné použití u § 76). */
    public function setYearRatio(int $supplierId, int $adjustmentId, int $year, int $ratioPct): void
    {
        if ($ratioPct < 0 || $ratioPct > 100) {
            throw new \InvalidArgumentException('Poměr použití musí být 0–100 %.');
        }

        $this->db->pdo()->prepare(
            'INSERT INTO vat_deduction_adjustment_years
                (adjustment_id, supplier_id, year, current_ratio_pct, amount)
             VALUES (?, ?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE current_ratio_pct = VALUES(current_ratio_pct)'
        )->execute([$adjustmentId, $supplierId, $year, $ratioPct]);
    }

    /**
     * Majetek, jehož lhůta úpravy pokrývá daný rok.
     *
     * Lhůta běží od roku pořízení včetně: u pětileté lhůty a pořízení v roce 2024 jde
     * o roky 2024–2028. Rok pořízení sám úpravě nepodléhá (odpočet se v něm uplatnil),
     * ale v evidenci zůstává, aby šlo rozpis zobrazit celý.
     *
     * @return list<array<string,mixed>>
     */
    private function itemsInPeriod(int $supplierId, int $year): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.id, a.label, a.acquired_on, a.period_years, a.original_vat,
                    a.original_ratio_pct, y.current_ratio_pct
               FROM vat_deduction_adjustments a
          LEFT JOIN vat_deduction_adjustment_years y
                 ON y.adjustment_id = a.id AND y.year = ?
              WHERE a.supplier_id = ?
                AND YEAR(a.acquired_on) < ?
                AND YEAR(a.acquired_on) + a.period_years > ?
           ORDER BY a.acquired_on, a.id'
        );
        $stmt->execute([$year, $supplierId, $year, $year]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function row(array $row, ?int $currentRatio, float $amount, bool $applies, bool $needsInput, string $reason): array
    {
        return [
            'id'                 => (int) $row['id'],
            'label'              => (string) $row['label'],
            'acquired_on'        => (string) $row['acquired_on'],
            'period_years'       => (int) $row['period_years'],
            'original_vat'       => round((float) $row['original_vat'], 2),
            'original_ratio_pct' => (int) $row['original_ratio_pct'],
            'current_ratio_pct'  => $currentRatio,
            'amount'             => $amount,
            'applies'            => $applies,
            'needs_input'        => $needsInput,
            'reason'             => $reason,
        ];
    }
}
