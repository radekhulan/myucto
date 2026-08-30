<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use PDO;

/**
 * § 79 a § 79a ZDPH — odpočet při registraci a jeho snížení při zrušení registrace (ř. 45).
 *
 * Dvě protilehlé situace na jednom řádku přiznání, obě jednorázové a obě drahé, když se
 * na ně zapomene: při registraci se o nevyužitý nárok jednorázově přijde, při zrušení
 * registrace je neprovedené snížení doměrek. Systém je neuměl vůbec — `odp_rez_nar` měl
 * v repozitáři nula výskytů a generátor ř. 45 vědomě přeskakoval.
 *
 * ── Znaménko a období ───────────────────────────────────────────────────────
 * Neurčuji je odhadem, říká je doslova anotace XSD u `odp_rez_nar`: nárok při registraci
 * KLADNĚ v přiznání za období, do něhož spadá den vzniku plátcovství; snížení při zrušení
 * ZÁPORNĚ v přiznání za POSLEDNÍ zdaňovací období registrace. Období tedy řídí
 * `effective_on`, ne datum pořízení majetku.
 *
 * ── Co se počítá a co se zadává ─────────────────────────────────────────────
 * Podmínku „je součástí obchodního majetku ke dni registrace" (§ 79 odst. 1) systém
 * z přijatých faktur nevidí — materiál mohl být spotřebován, zboží prodáno. Odvozovat
 * nárok z pouhé existence faktury v okně by znamenalo uplatnit odpočet z věcí, které
 * firma nemá. Položky proto zadává účetní; systém ověřuje a dopočítává to, co z dat
 * ověřit LZE:
 *   - lhůtu 12 měsíců přede dnem registrace (§ 79 odst. 1 a 2),
 *   - výši snížení u dlouhodobého majetku podle § 79a odst. 2 → § 78d obdobně.
 *
 * ── Výše snížení při zrušení registrace ─────────────────────────────────────
 * U zásob se vrací odpočet CELÝ — žádná lhůta tam neběží. U dlouhodobého majetku jen
 * poměrná část podle roků ZBÝVAJÍCÍCH do konce lhůty pro úpravu odpočtu:
 *
 *     snížení = daň na vstupu / délka lhůty × počet zbývajících roků
 *
 * Po uplynutí lhůty se nevrací nic — majetek už úpravě nepodléhá a tvrdit opak by
 * znamenalo vracet daň, kterou stát nárokovat nemůže.
 *
 * Read-only vůči účetnictví: nic neúčtuje, jen eviduje a počítá.
 */
final class Section79Service
{
    /**
     * Fallback default, kdyby daný rok neměl v TaxConstants klíč (nemělo by nastat).
     * Primárně se čte {@see TaxConstantsRepository::forYear()} pro rok registrace.
     */
    public const CLAIM_WINDOW_MONTHS = 12;

    /** § 78 odst. 3 ZDPH — lhůta pro úpravu odpočtu u dlouhodobého majetku (roky), fallback. */
    public const ADJUSTMENT_PERIOD_YEARS = [5, 10];

    public function __construct(
        private readonly Connection $db,
        private readonly TaxConstantsRepository $taxConstants,
    ) {}

    /**
     * Položky s vypočtenou částkou do ř. 45 za dané období.
     *
     * @return list<array{
     *   id:int, kind:string, label:string, acquired_on:string, effective_on:string,
     *   asset_kind:string, period_years:?int, vat_amount:float,
     *   amount:float, applies:bool, reason:string
     * }>
     */
    public function preview(int $supplierId, string $periodFrom, string $periodTo): array
    {
        $out = [];
        foreach ($this->itemsForPeriod($supplierId, $periodFrom, $periodTo) as $row) {
            $out[] = $this->evaluate($row);
        }

        return $out;
    }

    /**
     * Součet do ř. 45 (`odp_rez_nar`) za období. Kladně nárok při registraci, záporně
     * snížení při zrušení. Zaokrouhleno na celé Kč (XSD `fractionDigits=0`) až po sečtení
     * položek zaokrouhlených jednotlivě — shodně s rozpisem, který uvidí účetní.
     */
    public function totalForReturn(int $supplierId, string $periodFrom, string $periodTo): float
    {
        $total = 0.0;
        foreach ($this->preview($supplierId, $periodFrom, $periodTo) as $item) {
            if ($item['applies']) {
                $total += $item['amount'];
            }
        }

        return round($total);
    }

    /**
     * Zaeviduje položku. Vrací id záznamu.
     *
     * @param 'registration'|'deregistration' $kind
     * @param 'inventory'|'fixed_asset' $assetKind
     * @param array{purchase_invoice_id?:?int, asset_id?:?int, note?:?string, created_by?:?int} $links
     */
    public function register(
        int $supplierId,
        string $kind,
        string $label,
        string $acquiredOn,
        string $effectiveOn,
        string $assetKind,
        float $vatAmount,
        ?int $periodYears = null,
        array $links = [],
    ): int {
        if (!in_array($kind, ['registration', 'deregistration'], true)) {
            throw new \InvalidArgumentException('Druh je registration (§ 79) nebo deregistration (§ 79a).');
        }
        if (!in_array($assetKind, ['inventory', 'fixed_asset'], true)) {
            throw new \InvalidArgumentException('Druh majetku je inventory nebo fixed_asset.');
        }
        if ($vatAmount <= 0) {
            throw new \InvalidArgumentException('Daň na vstupu musí být kladná; znaménko do přiznání určuje druh položky.');
        }
        $allowedPeriodYears = $this->adjustmentPeriodYears((int) substr($acquiredOn, 0, 4));
        if ($assetKind === 'fixed_asset' && !in_array($periodYears, $allowedPeriodYears, true)) {
            // Bez lhůty by u dlouhodobého majetku nešlo spočítat poměrnou část a tiché
            // dosazení pětiletky by u stavby vrátilo dvojnásobek toho, co má.
            throw new \InvalidArgumentException(
                'U dlouhodobého majetku je lhůta pro úpravu odpočtu podle § 78 odst. 3 pět nebo deset let.'
            );
        }
        if ($acquiredOn > $effectiveOn) {
            throw new \InvalidArgumentException('Majetek nelze pořídit až po dni registrace / zrušení registrace.');
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO vat_registration_corrections
                (supplier_id, kind, label, acquired_on, effective_on, asset_kind,
                 period_years, vat_amount, purchase_invoice_id, asset_id, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId,
            $kind,
            $label,
            $acquiredOn,
            $effectiveOn,
            $assetKind,
            $assetKind === 'fixed_asset' ? $periodYears : null,
            round($vatAmount, 2),
            $links['purchase_invoice_id'] ?? null,
            $links['asset_id'] ?? null,
            $links['note'] ?? null,
            $links['created_by'] ?? null,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function delete(int $supplierId, int $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM vat_registration_corrections WHERE supplier_id = ? AND id = ?')
            ->execute([$supplierId, $id]);
    }

    /**
     * Vyhodnotí jednu položku — částka do ř. 45 i důvod, proč (ne)vstupuje.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function evaluate(array $row): array
    {
        $kind = (string) $row['kind'];
        $vat = round((float) $row['vat_amount'], 2);
        $acquiredOn = (string) $row['acquired_on'];
        $effectiveOn = (string) $row['effective_on'];

        if ($kind === 'registration') {
            // § 79 odst. 1 a 2 — okno tolika měsíců přede dnem vzniku plátcovství.
            $windowMonths = (int) ($this->taxConstants->forYear((int) substr($effectiveOn, 0, 4))['s79_claim_window_months']
                ?? self::CLAIM_WINDOW_MONTHS);
            $windowStart = (new \DateTimeImmutable($effectiveOn))
                ->modify('-' . $windowMonths . ' months')
                ->format('Y-m-d');
            if ($acquiredOn < $windowStart) {
                return $this->row($row, 0.0, false, sprintf(
                    'Pořízeno %s, tedy před začátkem lhůty %s — nárok podle § 79 odst. 1 nevzniká.',
                    $acquiredOn,
                    $windowStart,
                ));
            }

            return $this->row($row, $vat, true, sprintf(
                'Nárok při registraci (§ 79 odst. 1); pořízeno %s ve lhůtě od %s.',
                $acquiredOn,
                $windowStart,
            ));
        }

        // § 79a — snížení uplatněného odpočtu, do přiznání se ZÁPORNÝM znaménkem.
        if ((string) $row['asset_kind'] === 'inventory') {
            return $this->row($row, -$vat, true,
                'Zásoby — vrací se celý uplatněný odpočet (§ 79a odst. 1).');
        }

        $periodYears = max(1, (int) $row['period_years']);
        $elapsed = (int) substr($effectiveOn, 0, 4) - (int) substr($acquiredOn, 0, 4);
        $remaining = $periodYears - $elapsed;

        if ($remaining <= 0) {
            return $this->row($row, 0.0, false, sprintf(
                'Lhůta pro úpravu odpočtu (%d let od %s) uplynula — snížení se neprovádí.',
                $periodYears,
                substr($acquiredOn, 0, 4),
            ));
        }

        $amount = round($vat / $periodYears * $remaining, 2);

        return $this->row($row, -$amount, true, sprintf(
            'Dlouhodobý majetek — %d z %d roků lhůty zbývá, vrací se %d/%d z %s Kč (§ 79a odst. 2 → § 78d).',
            $remaining,
            $periodYears,
            $remaining,
            $periodYears,
            number_format($vat, 2, ',', ' '),
        ));
    }

    /**
     * Položky, jejichž rozhodný den spadá do období přiznání. Rozhoduje `effective_on`
     * (den registrace / zrušení), NE datum pořízení majetku — období vykázání určuje
     * právě ono, viz anotace XSD.
     *
     * @return list<array<string,mixed>>
     */
    private function itemsForPeriod(int $supplierId, string $periodFrom, string $periodTo): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, kind, label, acquired_on, effective_on, asset_kind, period_years, vat_amount
               FROM vat_registration_corrections
              WHERE supplier_id = ? AND effective_on BETWEEN ? AND ?
           ORDER BY effective_on, id'
        );
        $stmt->execute([$supplierId, $periodFrom, $periodTo]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** § 78 odst. 3 ZDPH — přípustné lhůty pro úpravu odpočtu u dlouhodobého majetku (roky). */
    private function adjustmentPeriodYears(int $year): array
    {
        $value = $this->taxConstants->forYear($year)['vat_adjustment_period_years'] ?? self::ADJUSTMENT_PERIOD_YEARS;

        return array_map('intval', (array) $value);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function row(array $row, float $amount, bool $applies, string $reason): array
    {
        return [
            'id'           => (int) $row['id'],
            'kind'         => (string) $row['kind'],
            'label'        => (string) $row['label'],
            'acquired_on'  => (string) $row['acquired_on'],
            'effective_on' => (string) $row['effective_on'],
            'asset_kind'   => (string) $row['asset_kind'],
            'period_years' => $row['period_years'] === null ? null : (int) $row['period_years'],
            'vat_amount'   => round((float) $row['vat_amount'], 2),
            'amount'       => $amount,
            'applies'      => $applies,
            'reason'       => $reason,
        ];
    }
}
