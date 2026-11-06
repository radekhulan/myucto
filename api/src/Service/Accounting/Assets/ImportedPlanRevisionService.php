<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Assets;

use MyInvoice\Http\IsoDate;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AssetRepository;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use Psr\Clock\ClockInterface;
use PDO;

/** Řízená změna budoucího odhadu. Nepřepisuje původní import ani účetní skutečnost. */
final class ImportedPlanRevisionService
{
    public function __construct(
        private readonly Connection $db,
        private readonly AssetRepository $assets,
        private readonly AssetService $service,
        private readonly AccountingPeriodRepository $periods,
        private readonly ClockInterface $clock,
        private readonly ActivityLogger $logger,
    ) {}

    public function preview(int $supplierId, int $id, array $input, int $userId): array
    {
        return $this->prepare($supplierId, $id, $input, $userId, false)['preview'];
    }

    public function revise(int $supplierId, int $id, array $input, int $userId): array
    {
        $pdo = $this->db->pdo();
        return TransactionFailure::retrying($pdo, fn (): array => (new DimensionPeriodGuard($this->db))->transaction(
            function () use ($supplierId, $id, $input, $userId): array {
                $prepared = $this->prepare($supplierId, $id, $input, $userId, true);
                $this->assets->update($supplierId, $id, ['imported_depreciation_plan' => $prepared['plan']]);
                $this->logger->log('asset.plan_revised', $userId, 'asset', $id,
                    $prepared['plan']['revisions'][array_key_last($prepared['plan']['revisions'])], supplierId: $supplierId);
                return ['asset' => $this->service->get($supplierId, $id), 'warnings' => []];
            },
        ));
    }

    private function prepare(int $supplierId, int $id, array $input, int $userId, bool $locking): array
    {
        $from = $input['effective_from'] ?? null;
        $months = $input['remaining_months'] ?? null;
        $reason = $input['reason'] ?? null;
        if (!is_string($from) || strlen($from) !== 7 || !IsoDate::isValid($from . '-01')
            || !is_int($months) || $months < 1 || $months > 3000 || !is_string($reason)
            || trim($reason) === '' || mb_strlen(trim($reason)) > 2000
            || !is_int($input['expected_revision'] ?? null) || $input['expected_revision'] < 0 || $userId < 1) {
            throw new AssetException('validation_failed', 'Vyplňte platný měsíc účinnosti, počet měsíců (1–3000), důvod (nejvýše 2000 znaků) a číslo revize.');
        }
        $suffix = $locking ? ' FOR UPDATE' : '';
        $pdo = $this->db->pdo();
        $snapshot = $this->periods->fiscalCalendarSnapshot($supplierId, $locking);
        $calendar = $snapshot['calendar'];
        $periods = $snapshot['periods'];
        $calendarFingerprint = $snapshot['fingerprint'];
        $asset = $locking ? $this->assets->findForUpdate($supplierId, $id) : $this->assets->find($supplierId, $id);
        if ($asset === null) throw new AssetException('not_found', 'Majetek nenalezen.', 404);
        if ($asset['status'] !== 'in_use' || $asset['imported_depreciation_plan'] === null) {
            throw new AssetException('imported_plan_revision_not_allowed', 'Revizi lze provést jen u převzatého plánu majetku v užívání.');
        }
        if (!$this->service->supportsImportedPlan($supplierId)) {
            throw new AssetException('imported_plan_revision_not_allowed', 'Revizi převzatého plánu lze provést jen ve firmě s podvojným účetnictvím.');
        }
        $plan = $asset['imported_depreciation_plan'];
        $history = $plan['revisions'] ?? [];
        if ($input['expected_revision'] !== count($history)) $this->stale();
        if (count($history) >= 1000) throw new AssetException('validation_failed', 'Plán dosáhl nejvyššího počtu 1000 revizí.');
        if ($locking && (($input['expected_calendar_start_month'] ?? null) !== $calendar->startMonth()
            || ($input['expected_calendar_fingerprint'] ?? null) !== $calendarFingerprint)) $this->stale();
        $query = $pdo->prepare('SELECT * FROM depreciation_entries WHERE supplier_id = ? AND asset_id = ? ORDER BY kind, fiscal_year' . $suffix);
        $query->execute([$supplierId, $id]);
        $entries = $query->fetchAll(PDO::FETCH_ASSOC);
        $ctx = DepreciationContext::fromAsset($asset, $calendar, $this->assets->improvements($id), $entries);
        $current = ImportedDepreciationPlan::accounting($ctx);
        if ($from < $this->clock->now()->format('Y-m') || ($history !== [] && $from < $history[array_key_last($history)]['effective_from'])) {
            throw new AssetException('imported_plan_revision_not_allowed', 'Revize nesmí zpětně měnit minulé měsíce ani předchozí účinný interval.');
        }
        $tail = array_values(array_filter($current, static fn (array $m): bool => $m['month'] >= $from));
        $remaining = array_sum(array_column($tail, 'amount_cents'));
        if ($tail === [] || $tail[0]['month'] !== $from || $remaining <= 0 || count($current) - count($tail) + $months + $ctx->openingAccMonths > 3000) {
            throw new AssetException('validation_failed', 'Měsíc musí být součástí zbývajícího plánu s kladnou zůstatkovou částkou; celý plán smí mít nejvýše 3000 měsíců.');
        }
        $newMonthIndex = (int) substr($from, 0, 4) * 12 + (int) substr($from, 5, 2) - 1 + $months - 1;
        if (intdiv($newMonthIndex, 12) > 9999) throw new AssetException('validation_failed', 'Konec plánu přesahuje podporované datum.');
        $newEnd = sprintf('%04d-%02d', intdiv($newMonthIndex, 12), $newMonthIndex % 12 + 1);
        $last = max($current[array_key_last($current)]['month'], $newEnd);
        $firstYear = $calendar->fiscalYearOfDate($from . '-01');
        $lastYear = $calendar->fiscalYearOfDate($last . '-01');
        foreach ($entries as $entry) {
            if ((int) $entry['fiscal_year'] >= $firstYear && (int) $entry['fiscal_year'] <= $lastYear) $this->locked();
        }
        foreach ($periods as $period) {
            $year = (int) $period['fiscal_year'];
            $touchesPlan = ($year >= $firstYear && $year <= $lastYear)
                || ($period['starts_on'] <= $calendar->periodEnd($lastYear) && $period['ends_on'] >= $calendar->periodStart($firstYear));
            if (!$touchesPlan) continue;
            if ($period['status'] !== 'open') $this->locked();
            // Měsíční engine používá pravidelný fiskální rok; přechodové období by změnilo roční součty.
            if ($period['starts_on'] !== $calendar->periodStart($year) || $period['ends_on'] !== $calendar->periodEnd($year)) {
                throw new AssetException('imported_plan_revision_not_allowed', 'Revize nepodporuje zkrácené ani přechodové účetní období v dotčeném plánu.');
            }
        }
        $query = $pdo->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ?' . $suffix);
        $query->execute([$supplierId]);
        $lockedUntil = $query->fetchColumn();
        if (is_string($lockedUntil) && $lockedUntil >= $from . '-01') $this->locked();
        $revision = ['revision' => count($history) + 1, 'effective_from' => $from, 'remaining_months' => $months,
            'remaining_amount_cents' => $remaining, 'reason' => trim($reason), 'user_id' => $userId,
            'created_at' => $this->clock->now()->format(DATE_ATOM), 'fiscal_start_month' => $calendar->startMonth()];
        $plan['revisions'] = [...$history, $revision];
        $after = array_replace($asset, ['imported_depreciation_plan' => $plan]);
        $afterCtx = DepreciationContext::fromAsset($after, $calendar, $ctx->improvements, $entries);
        return ['plan' => $plan, 'preview' => ['revision' => $revision['revision'], 'effective_from' => $from,
            'remaining_months' => $months, 'remaining_amount' => $remaining / 100.0,
            'calendar_start_month' => $calendar->startMonth(), 'calendar_fingerprint' => $calendarFingerprint,
            'before' => $this->service->planForAsset($asset, $ctx), 'after' => $this->service->planForAsset($after, $afterCtx)]];
    }

    private function stale(): never
    {
        throw new AssetException('imported_plan_revision_stale', 'Plán nebo účetní kalendář se mezitím změnil. Načtěte nový náhled.', 409);
    }

    private function locked(): never
    {
        throw new AssetException('imported_plan_revision_locked', 'Revize zasahuje do potvrzeného odpisu nebo uzamčeného či uzavíraného období.', 409);
    }
}
