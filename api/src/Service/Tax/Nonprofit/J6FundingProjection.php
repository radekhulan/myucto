<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit;

use MyInvoice\Repository\NonprofitTaxAssessmentRepository;
use MyInvoice\Repository\NonprofitTaxPagedAssessmentRepository;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentGraphReceipt;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6CurrentProviderFactory;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6HistoricProviderFactory;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6HistoricProviderSet;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6ReadMode;

/**
 * Jediná čtecí cesta k financování zaznamenanému v jedné revizi posouzení J6.
 *
 * Legacy revize (i dosud neuložený výsledek ve tvaru legacy) se čte z uloženého JSON, revize
 * uložená jen ve stránkovaném formátu po ověřených stránkách neměnného grafu. Obě úložiště
 * vydávají stejné stránky fondů, členů, vazeb, použití a řádků a výběr nad nimi je společný.
 * Krytí nákladu, navazující čerpání i politiky zdrojů proto vidí obě cesty stejně a do PHP
 * se dostane jen omezená část stránkované revize.
 */
final readonly class J6FundingProjection
{
    private const int PAGE = 500;
    private const int MAX_POOL_MEMBERS = 1000;
    private const int MAX_COLLECTED = 20000;

    public function __construct(
        private NonprofitTaxAssessmentRepository $legacy,
        private NonprofitTaxPagedAssessmentRepository $paged,
        private J6HistoricProviderFactory $historic,
        private J6CurrentProviderFactory $current,
    ) {}

    /** Revize se čte ze stránkovaného grafu (řádek stránkovaného repozitáře nebo revize bez legacy JSON). */
    public static function isPaged(array $revision): bool
    {
        return ($revision['graph_receipt'] ?? null) instanceof J6AssessmentGraphReceipt
            || NonprofitDppoAssessmentSource::pagedOnly($revision);
    }

    /** Řádek stránkovaného repozitáře s ověřitelným grafem k revizi. */
    public function pagedRow(array $revision): array
    {
        if (($revision['graph_receipt'] ?? null) instanceof J6AssessmentGraphReceipt) return $revision;
        $row = $this->paged->find($revision['supplier_id'], $revision['id']);
        if (!$row['graph_receipt'] instanceof J6AssessmentGraphReceipt) self::changed();
        return $row;
    }

    /** Stránkovaná revize je potvrzená a odpovídá dnešním účetním podkladům období. */
    public function pagedCurrent(array $revision, bool $lock): bool
    {
        $row = $this->pagedRow($revision);
        $graph = $row['graph_receipt'];
        if ($row['status'] !== 'confirmed' || $graph->ruleset !== NonprofitTaxRules::VERSION) return false;
        $fingerprint = $this->current->fingerprint($row['supplier_id'], $row['period_id'], $lock);
        return $fingerprint->findingCount === 0 && hash_equals($fingerprint->inputHash, $graph->snapshotInputHash);
    }

    /**
     * Omezená projekce revize pro vybrané zdroje a náklady.
     *
     * @param list<string> $anchors kotvy zdrojů
     * @param list<string> $members členové zdrojů (kotvy se doplní)
     * @param list<string> $costs nákladové řádky, jejichž vazby a fondy se mají vrátit
     * @param list<string>|null $fields pole vrácených řádků, null vrátí celé řádky
     * @return array{rows: array<string,array>, pools: array<string,list<string>>, cost_pools: array<string,array>, links: list<array>, usage: list<array>}
     */
    public function project(array $revision, array $anchors, array $members, array $costs = [], ?array $fields = null): array
    {
        $source = $this->open($revision);
        $anchorSet = array_fill_keys($anchors, true);
        $memberSet = array_fill_keys($members, true) + $anchorSet;
        $costSet = array_fill_keys($costs, true);

        $pools = $this->sourcePools($source, $anchorSet);
        $costPools = $this->costPoolsOf($source, $costSet);
        $links = $this->linksOf($source, $memberSet, $costSet);
        $usage = $this->usageOf($source, $anchorSet, $memberSet);

        $needed = $memberSet;
        foreach ($links as $link) $needed[$link['cost_id']] = true;
        foreach ($usage as $row) foreach ($row['members'] as $id) $needed[$id] = true;
        foreach ($costPools as $pool) foreach ($pool['members'] as $id) $needed[$id] = true;
        if (count($needed) > self::MAX_COLLECTED) self::tooLarge();

        return ['rows' => $this->rowsOf($source, $needed, $fields), 'pools' => $pools, 'cost_pools' => $costPools,
            'links' => $links, 'usage' => $usage];
    }

    /**
     * Vazby financování, jejichž zdroj nebo náklad patří k vybraným řádkům, v pořadí revize.
     *
     * @param list<string> $sources
     * @param list<string> $costs
     * @return list<array>
     */
    public function links(array $revision, array $sources, array $costs): array
    {
        return $this->linksOf($this->open($revision), array_fill_keys($sources, true), array_fill_keys($costs, true));
    }

    /**
     * Deklarované fondy nákladů, které obsahují vybrané nákladové řádky.
     *
     * @param list<string> $costs
     * @return array<string,array{anchor: string, members: list<string>, declared: array}>
     */
    public function costPools(array $revision, array $costs): array
    {
        return $this->costPoolsOf($this->open($revision), array_fill_keys($costs, true));
    }

    /**
     * Řádky výsledku a historických zdrojů podle identifikátoru, v pořadí revize.
     *
     * @param list<string> $ids
     * @return array<string,array>
     */
    public function rows(array $revision, array $ids, ?array $fields = null): array
    {
        if (count($ids) > self::MAX_COLLECTED) self::tooLarge();
        return $this->rowsOf($this->open($revision), array_fill_keys($ids, true), $fields);
    }

    /**
     * Podklad krytí nákladů jedné revize: všechny vazby, fondy příjmů použitých zdrojů,
     * deklarované fondy nákladů a samostatné náklady s vazbou a řádky jejich členů.
     *
     * @return array{links: list<array>, income_pools: array<string,list<string>>, cost_pools: list<array{anchor: string, members: list<string>}>, rows: array<string,array>}
     */
    public function coverageFacts(array $revision): array
    {
        $source = $this->open($revision);
        $links = [];
        $sources = [];
        $costs = [];
        foreach ($this->linkPagesOf($source) as $page) {
            foreach ($page as $link) {
                $links[] = $link;
                $sources[$link['source_id']] = true;
                $costs[$link['cost_id']] = true;
                if (count($links) > self::MAX_COLLECTED) self::tooLarge();
            }
        }
        $income = [];
        $declared = [];
        $collected = 0;
        foreach ($this->memberPagesOf($source) as $page) {
            foreach ($page as $member) {
                if ($member['pool_kind'] === 'revenue') {
                    if (isset($sources[$member['anchor']])) $income[$member['anchor']][] = $member['member_id'];
                    elseif (isset($sources[$member['member_id']])) self::changed();
                    else continue;
                } elseif ($member['pool_kind'] === 'expense') {
                    $declared[$member['anchor']][] = $member['member_id'];
                } else {
                    continue;
                }
                if (++$collected > self::MAX_COLLECTED) self::tooLarge();
            }
        }
        foreach (array_keys($sources) as $id) $income[$id] ??= [$id];
        $pools = [];
        $pooled = [];
        foreach ($declared as $anchor => $members) {
            $pools[] = ['anchor' => (string) $anchor, 'members' => $members];
            foreach ($members as $id) $pooled[$id] = true;
        }
        foreach (array_keys($costs) as $id) if (!isset($pooled[$id])) $pools[] = ['anchor' => (string) $id, 'members' => [(string) $id]];

        $needed = [];
        foreach ($income as $members) foreach ($members as $id) $needed[$id] = true;
        foreach ($pools as $pool) foreach ($pool['members'] as $id) $needed[$id] = true;
        if (count($needed) > self::MAX_COLLECTED) self::tooLarge();
        return ['links' => $links, 'income_pools' => $income, 'cost_pools' => $pools,
            'rows' => $this->rowsOf($source, $needed, ['id', 'line_id', 'kind', 'amount_cents'])];
    }

    /** Stránky řádků výsledku (bez historických zdrojů). */
    public function resultPages(array $revision): iterable
    {
        yield from $this->resultPagesOf($this->open($revision));
    }

    /** Stránky použití zdrojů po členech: anchor, member_id a částky čerpání. */
    public function usagePages(array $revision): iterable
    {
        yield from $this->usagePagesOf($this->open($revision));
    }

    private function open(array $revision): J6HistoricProviderSet|array
    {
        if (self::isPaged($revision)) {
            return $this->historic->create($this->paged->binding($this->pagedRow($revision)), J6ReadMode::HISTORIC);
        }
        if (!array_key_exists('result', $revision)) return $this->legacy->find($revision['supplier_id'], $revision['id']);
        return $revision;
    }

    /** @param array<string,true> $anchorSet */
    private function sourcePools(J6HistoricProviderSet|array $source, array $anchorSet): array
    {
        if ($anchorSet === []) return [];
        $pools = [];
        foreach ($this->memberPagesOf($source) as $page) {
            foreach ($page as $member) {
                if ($member['pool_kind'] !== 'revenue' || !isset($anchorSet[$member['anchor']])) continue;
                $pools[$member['anchor']][] = $member['member_id'];
                if (count($pools[$member['anchor']]) > self::MAX_POOL_MEMBERS) self::tooLarge();
            }
        }
        foreach ($pools as &$members) sort($members, SORT_STRING);
        unset($members);
        return $pools;
    }

    /** @param array<string,true> $costSet */
    private function costPoolsOf(J6HistoricProviderSet|array $source, array $costSet): array
    {
        if ($costSet === []) return [];
        $anchors = [];
        foreach ($this->memberPagesOf($source) as $page) {
            foreach ($page as $member) {
                if ($member['pool_kind'] === 'expense' && isset($costSet[$member['member_id']])) $anchors[$member['anchor']] = [];
            }
        }
        if ($anchors === []) return [];
        if (count($anchors) > self::MAX_POOL_MEMBERS) self::tooLarge();
        foreach ($this->memberPagesOf($source) as $page) {
            foreach ($page as $member) {
                if ($member['pool_kind'] !== 'expense' || !isset($anchors[$member['anchor']])) continue;
                $anchors[$member['anchor']][] = $member['member_id'];
                if (count($anchors[$member['anchor']]) > self::MAX_POOL_MEMBERS) self::tooLarge();
            }
        }
        $pools = [];
        foreach ($this->poolPagesOf($source) as $page) {
            foreach ($page as $pool) {
                if ($pool['pool_kind'] !== 'expense' || !isset($anchors[$pool['anchor']]) || isset($pools[$pool['anchor']])) continue;
                $members = $anchors[$pool['anchor']];
                sort($members, SORT_STRING);
                // Legacy fond nese kotvu i členy přímo v deklaraci; stránkovaný je má v samostatných záznamech.
                $pools[$pool['anchor']] = ['anchor' => $pool['anchor'], 'members' => $members,
                    'declared' => $pool['payload'] + ['anchor' => $pool['anchor'], 'members' => $members]];
            }
        }
        if (count($pools) !== count($anchors)) self::changed();
        return $pools;
    }

    /**
     * @param array<string,true> $sourceSet
     * @param array<string,true> $costSet
     */
    private function linksOf(J6HistoricProviderSet|array $source, array $sourceSet, array $costSet): array
    {
        if ($sourceSet === [] && $costSet === []) return [];
        $links = [];
        foreach ($this->linkPagesOf($source) as $page) {
            foreach ($page as $link) {
                if (!isset($sourceSet[$link['source_id'] ?? '']) && !isset($costSet[$link['cost_id'] ?? ''])) continue;
                $links[] = $link;
                if (count($links) > self::MAX_COLLECTED) self::tooLarge();
            }
        }
        return $links;
    }

    /**
     * Použití fondů, jejichž kotva nebo některý člen patří k vybraným zdrojům, vždy se všemi členy.
     *
     * @param array<string,true> $anchorSet
     * @param array<string,true> $memberSet
     */
    private function usageOf(J6HistoricProviderSet|array $source, array $anchorSet, array $memberSet): array
    {
        if ($anchorSet === [] && $memberSet === []) return [];
        $touched = [];
        foreach ($this->usagePagesOf($source) as $page) {
            foreach ($page as $row) {
                if (isset($anchorSet[$row['anchor']]) || isset($memberSet[$row['member_id']])) $touched[$row['anchor']] = true;
            }
        }
        if ($touched === []) return [];
        if (count($touched) > self::MAX_POOL_MEMBERS) self::tooLarge();
        $usage = [];
        foreach ($this->usagePagesOf($source) as $page) {
            foreach ($page as $row) {
                if (!isset($touched[$row['anchor']])) continue;
                $usage[$row['anchor']] ??= ['anchor' => $row['anchor'], 'members' => [],
                    'net_source_cents' => $row['net_source_cents'], 'prior_drawdown_cents' => $row['prior_drawdown_cents'],
                    'drawdown_cents' => $row['drawdown_cents'], 'remaining_cents' => $row['remaining_cents']];
                $usage[$row['anchor']]['members'][] = $row['member_id'];
                if (count($usage[$row['anchor']]['members']) > self::MAX_POOL_MEMBERS) self::tooLarge();
            }
        }
        return array_values($usage);
    }

    /** @param array<string,true> $needed */
    private function rowsOf(J6HistoricProviderSet|array $source, array $needed, ?array $fields): array
    {
        if ($needed === []) return [];
        $keep = $fields === null ? null : array_flip($fields);
        $rows = [];
        $pages = (function () use ($source): iterable {
            yield from $this->resultPagesOf($source);
            yield from $this->referencePagesOf($source);
        })();
        foreach ($pages as $page) {
            foreach ($page as $row) {
                $id = $row['id'] ?? null;
                if (!is_string($id) || isset($rows[$id]) || !isset($needed[$id])) continue;
                unset($row['result_row_hash']);
                $rows[$id] = $keep === null ? $row : array_intersect_key($row, $keep);
            }
        }
        return $rows;
    }

    private function resultPagesOf(J6HistoricProviderSet|array $source): iterable
    {
        if ($source instanceof J6HistoricProviderSet) {
            yield from $source->calculation->pages('result', self::PAGE);
            return;
        }
        yield from self::pages($source['result']['rows'] ?? []);
    }

    private function referencePagesOf(J6HistoricProviderSet|array $source): iterable
    {
        if ($source instanceof J6HistoricProviderSet) {
            yield from $source->sources->fundingReferencePages(self::PAGE);
            return;
        }
        yield from self::pages(array_values($source['snapshot']['funding_reference_sources'] ?? []));
    }

    private function poolPagesOf(J6HistoricProviderSet|array $source): iterable
    {
        if ($source instanceof J6HistoricProviderSet) {
            yield from $source->decisions->fundingPoolPages(self::PAGE);
            return;
        }
        $rows = [];
        foreach (['revenue' => 'funding_sources', 'expense' => 'funding_costs'] as $kind => $field) {
            foreach ($source['assessment'][$field] ?? [] as $pool) {
                $rows[] = ['pool_kind' => $kind, 'anchor' => (string) ($pool['anchor'] ?? ''), 'payload' => $pool];
            }
        }
        yield from self::pages($rows);
    }

    private function memberPagesOf(J6HistoricProviderSet|array $source): iterable
    {
        if ($source instanceof J6HistoricProviderSet) {
            yield from $source->decisions->fundingMemberPages(self::PAGE);
            return;
        }
        $rows = [];
        foreach (['revenue' => 'funding_sources', 'expense' => 'funding_costs'] as $kind => $field) {
            foreach ($source['assessment'][$field] ?? [] as $pool) {
                foreach ($pool['members'] ?? [] as $member) {
                    $rows[] = ['pool_kind' => $kind, 'anchor' => (string) ($pool['anchor'] ?? ''), 'member_id' => (string) $member];
                }
            }
        }
        yield from self::pages($rows);
    }

    /** Vazby ve tvaru legacy: source_id, cost_id a doložené částky vazby. */
    private function linkPagesOf(J6HistoricProviderSet|array $source): iterable
    {
        if ($source instanceof J6HistoricProviderSet) {
            foreach ($source->decisions->fundingLinkPages(self::PAGE) as $page) {
                yield array_map(static fn (array $link): array => ['source_id' => $link['source_id'],
                    'cost_id' => $link['cost_id']] + $link['payload'], $page);
            }
            return;
        }
        yield from self::pages($source['assessment']['funding'] ?? []);
    }

    private function usagePagesOf(J6HistoricProviderSet|array $source): iterable
    {
        if ($source instanceof J6HistoricProviderSet) {
            yield from $source->calculation->pages('usage', self::PAGE);
            return;
        }
        $rows = [];
        foreach ($source['result']['funding_usage'] ?? [] as $usage) {
            foreach ($usage['members'] as $member) {
                $rows[] = ['anchor' => $usage['anchor'], 'member_id' => $member,
                    'net_source_cents' => $usage['net_source_cents'], 'prior_drawdown_cents' => $usage['prior_drawdown_cents'],
                    'drawdown_cents' => $usage['drawdown_cents'], 'remaining_cents' => $usage['remaining_cents']];
            }
        }
        yield from self::pages($rows);
    }

    private static function pages(array $rows): iterable
    {
        foreach (array_chunk($rows, self::PAGE) as $page) yield $page;
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
