<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Funding;

use MyInvoice\Repository\NonprofitTaxAssessmentRepository;
use MyInvoice\Repository\NonprofitTaxPagedAssessmentRepository;
use MyInvoice\Repository\NonprofitTaxSourceRepository;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentGraphReceipt;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6HistoricProviderFactory;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6HistoricProviderSet;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6ReadMode;
use MyInvoice\Service\Tax\Nonprofit\J6FundingCapacity;
use MyInvoice\Service\Tax\Nonprofit\J6FundingProjection;
use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoAssessmentSource;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;

/**
 * Jediná čtecí cesta modulu zdrojů financování k potvrzeným posouzením J6.
 *
 * Legacy revize se čtou z uloženého JSON jako dosud. Revize uložená jen ve stránkovaném
 * formátu se čte po ověřených stránkách neměnného grafu a do PHP se z ní dostane jen
 * omezená projekce řádků, fondů, vazeb a použití, které se týkají vybraných zdrojů.
 */
final readonly class FundingAssessmentEvidence
{
    public const int MAX_CHOICES = 500;
    private const int MAX_COLLECTED = 20000;
    private const array CHOICE_TITLES = ['gift', 'public_operating_grant'];
    private const array ROW_FIELDS = ['id', 'line_id', 'entry_id', 'account_id', 'account_code', 'date', 'kind',
        'amount_cents', 'activity_kind_id', 'origin', 'decision', 'classification', 'reference_current'];

    public function __construct(
        private NonprofitTaxAssessmentRepository $legacy,
        private NonprofitTaxPagedAssessmentRepository $paged,
        private NonprofitTaxSourceRepository $sources,
        private J6FundingCapacity $capacity,
        private J6HistoricProviderFactory $historic,
        private J6FundingProjection $projection,
    ) {}

    /**
     * Poslední revize každého období, které má potvrzené posouzení, s příznakem aktuálnosti.
     *
     * @return list<array<string,mixed>>
     */
    public function receipts(int $supplier, bool $lock): array
    {
        return array_map(fn (array $row): array => $this->receipt($supplier, $row, $lock),
            $this->legacy->latestOfConfirmedPeriods($supplier, $lock));
    }

    /** Poslední revize období (i odvolaná) s příznakem aktuálnosti, nebo null. */
    public function periodReceipt(int $supplier, int $period, bool $lock): ?array
    {
        $row = $this->legacy->latest($supplier, $period, $lock);
        return $row === null ? null : $this->receipt($supplier, $row, $lock);
    }

    /**
     * Omezená projekce potvrzeného posouzení pro vybrané zdroje (J6FundingProjection, legacy i stránkované).
     *
     * @param list<string> $anchors kotvy zdrojů
     * @param list<string> $members členové zdrojů (včetně kotev)
     * @return array{rows: array<string,array>, pools: array<string,list<string>>, links: list<array>, usage: list<array>}
     */
    public function project(array $receipt, array $anchors, array $members): array
    {
        $revision = $receipt['storage'] === 'legacy' ? $receipt['legacy'] : $receipt['paged'];
        return $this->projection->project($revision, $anchors, $members, [], self::ROW_FIELDS);
    }

    /**
     * Navrhované účelové zdroje (dar, provozní dotace) z aktuálního potvrzeného posouzení období.
     *
     * @return array{0: list<array>, 1: bool} [zdroje, existují další]
     */
    public function candidates(array $receipt): array
    {
        if ($receipt['storage'] === 'legacy') {
            $row = $receipt['legacy'];
            $catalog = array_column($row['result']['rows'], null, 'id') + ($row['snapshot']['funding_reference_sources'] ?? []);
            $pools = [];
            foreach ($row['assessment']['funding_sources'] ?? [] as $pool) $pools[$pool['anchor']] = $pool['members'];
            return self::options($catalog, $pools);
        }
        $providers = $this->providers($receipt);
        $pools = [];
        $claimed = [];
        foreach ($providers->decisions->fundingMemberPages(500) as $page) {
            foreach ($page as $member) {
                if ($member['pool_kind'] !== 'revenue') continue;
                $pools[$member['anchor']][] = $member['member_id'];
                $claimed[$member['member_id']] = true;
                if (count($claimed) > self::MAX_COLLECTED) self::tooLarge();
            }
        }
        $candidate = static fn (array $row): bool => ($row['kind'] ?? null) === 'revenue'
            && in_array($row['decision']['title'] ?? null, self::CHOICE_TITLES, true);
        $catalog = $this->rows($providers, $claimed, $candidate);
        return self::options($catalog, $pools);
    }

    private function receipt(int $supplier, array $row, bool $lock): array
    {
        $base = ['id' => $row['id'], 'period_id' => $row['period_id'], 'status' => $row['status'],
            'ruleset' => $row['ruleset'], 'input_hash' => $row['input_hash']];
        if (!NonprofitDppoAssessmentSource::pagedOnly($row)) {
            return $base + ['storage' => 'legacy', 'legacy' => $row, 'paged' => null,
                'usage_count' => count($row['result']['funding_usage'] ?? []),
                'current' => $this->legacyCurrent($supplier, $row, $lock)];
        }
        $paged = $this->paged->find($supplier, $row['id'], $lock);
        $graph = $paged['graph_receipt'];
        if (!$graph instanceof J6AssessmentGraphReceipt) self::changed();
        return $base + ['storage' => 'paged', 'legacy' => null, 'paged' => $paged,
            'usage_count' => (int) ($graph->counts['usage'] ?? 0), 'current' => $this->projection->pagedCurrent($paged, $lock)];
    }

    private function legacyCurrent(int $supplier, array $row, bool $lock): bool
    {
        if ($row['status'] !== 'confirmed' || $row['ruleset'] !== NonprofitTaxRules::VERSION) return false;
        try {
            $snapshot = $this->sources->snapshot($supplier, $row['period_id'], $lock);
        } catch (NonprofitTaxAssessmentException $failure) {
            // Legacy revize za stránkovaně posouzeným obdobím aktuální být nemůže
            // (stejně jako v uzávěrkové kontrole J6); legacy snímek ji doložit neumí.
            if ($failure->errorCode === 'nonprofit_tax_paged_history_required') return false;
            throw $failure;
        }
        $current = $snapshot['findings'] === [] && hash_equals($row['input_hash'], $snapshot['input_hash']);
        if ($current) $this->capacity->assertCurrentReceipt($supplier, $row);
        return $current;
    }

    private function providers(array $receipt): J6HistoricProviderSet
    {
        return $this->historic->create($this->paged->binding($receipt['paged']), J6ReadMode::HISTORIC);
    }

    /**
     * Řádky výsledku a historických zdrojů, které jsou vyžádané nebo projdou filtrem.
     * Každý řádek nese jen pole potřebná pro podklad zdroje.
     *
     * @param array<string,true> $needed
     * @param callable(array): bool $accept
     * @return array<string,array>
     */
    private function rows(J6HistoricProviderSet $providers, array $needed, callable $accept): array
    {
        $rows = [];
        $pages = (static function () use ($providers): iterable {
            yield from $providers->calculation->pages('result', 500);
            yield from $providers->sources->fundingReferencePages(500);
        })();
        foreach ($pages as $page) {
            foreach ($page as $row) {
                $id = $row['id'] ?? null;
                if (!is_string($id) || isset($rows[$id]) || (!isset($needed[$id]) && !$accept($row))) continue;
                $rows[$id] = array_intersect_key($row, array_flip(self::ROW_FIELDS));
                if (count($rows) > self::MAX_COLLECTED) self::tooLarge();
            }
        }
        return $rows;
    }

    /**
     * @param array<string,array> $catalog
     * @param array<string,list<string>> $pools
     * @return array{0: list<array>, 1: bool}
     */
    private static function options(array $catalog, array $pools): array
    {
        $claimed = [];
        foreach ($pools as $members) foreach ($members as $id) $claimed[$id] = true;
        foreach ($catalog as $id => $row) {
            if (!isset($claimed[$id]) && $row['kind'] === 'revenue' && $row['amount_cents'] > 0
                && (($row['reference_current'] ?? true) === true)) {
                $pools[$id] = [$id];
            }
        }
        $options = [];
        foreach ($pools as $anchor => $members) {
            $base = $catalog[$anchor] ?? null;
            $title = $base['decision']['title'] ?? '';
            if (!in_array($title, self::CHOICE_TITLES, true)) continue;
            $amount = 0;
            foreach ($members as $id) {
                if (!isset($catalog[$id])) continue 2;
                $amount += $catalog[$id]['amount_cents'];
            }
            if ($amount <= 0) continue;
            if (count($options) === self::MAX_CHOICES) return [$options, true];
            $options[] = ['anchor' => (string) $anchor, 'members' => $members, 'kind' => $title === 'gift' ? 'gift' : 'operating_grant',
                'date' => $base['date'], 'account_code' => $base['account_code'], 'amount_cents' => $amount,
                'evidence' => $base['decision']['evidence']];
        }
        return [$options, false];
    }

    private static function tooLarge(): never
    {
        throw new NonprofitTaxAssessmentException('nonprofit_funding_assessment_too_large',
            'Posouzení obsahuje pro zdroj financování příliš mnoho navázaných řádků.', 422);
    }

    private static function changed(): never
    {
        throw new NonprofitTaxAssessmentException('nonprofit_tax_funding_changed',
            'Dříve potvrzené financování nákladu vyžaduje nové posouzení účetních podkladů.', 409);
    }
}
