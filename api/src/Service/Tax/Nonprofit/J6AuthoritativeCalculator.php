<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentDecisionProvider;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AuthoritativeSourceProvider;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6CalculationHeader;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6CalculationReceipt;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6CalculationSink;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6ExternalSortQueue;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6LegacyProjectionSink;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6FundingResolver;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6WorkingStore;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\LegacyArrayJ6CalculationSink;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\LegacyArrayJ6DecisionProvider;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\LegacyArrayJ6SourceProvider;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\NonprofitTaxSnapshotHasher;

final class J6AuthoritativeCalculator
{
    public function __construct(private readonly Connection $db) {}

    public function calculate(J6AuthoritativeSourceProvider $sources, J6AssessmentDecisionProvider $decisions, J6CalculationSink $sink): J6CalculationReceipt
    {
        $queues = [];
        $working = new J6WorkingStore($this->db->pdo());
        try {
            $sourceHeader = $sources->header();
            $assessment = $decisions->header();
            NonprofitTaxRules::assessment($assessment + ['activities' => [], 'decisions' => [], 'funding' => [],
                'funding_sources' => [], 'funding_costs' => []]);
            $decisionHash = hash_init('sha256');
            hash_update($decisionHash, "J6DECISIONINPUT2\0" . NonprofitTaxRules::canonicalJson($assessment) . "\0");
            foreach ($decisions->activityPages(500) as $page) {
                foreach ($page as $record) {
                    $id = (string) ($record['activity_kind_id'] ?? '');
                    $payload = $record['payload'] ?? null;
                    if ($id === '' || !is_array($payload)) NonprofitTaxRules::invalid('Číselník činností posouzení není jednoznačný.');
                    NonprofitTaxRules::assessment(['activities' => [$payload]]);
                    $recordHash = (string) ($record['record_hash'] ?? '');
                    $working->appendActivity($id, $payload, $recordHash);
                    self::updateInputHash($decisionHash, ['activity_kind_id' => $id, 'payload' => $payload,
                        'record_hash' => $recordHash]);
                }
            }
            $hasFunding = false;
            foreach ($decisions->fundingPoolPages(500) as $page) {
                foreach ($page as $record) {
                    $kind = (string) ($record['pool_kind'] ?? '');
                    $anchor = (string) ($record['anchor'] ?? '');
                    $payload = $record['payload'] ?? null;
                    if (!is_array($payload)) NonprofitTaxRules::invalid('Fond financování nemá strukturovaný podklad.');
                    NonprofitTaxRules::assessment([$kind === 'revenue' ? 'funding_sources' : 'funding_costs' => [$payload]]);
                    $recordHash = (string) ($record['record_hash'] ?? '');
                    $working->appendPool($kind, $anchor, $payload, $recordHash);
                    self::updateInputHash($decisionHash, ['pool_kind' => $kind, 'anchor' => $anchor,
                        'payload' => $payload, 'record_hash' => $recordHash]);
                    $hasFunding = true;
                }
            }
            foreach ($decisions->fundingMemberPages(500) as $page) {
                foreach ($page as $record) {
                    $kind = (string) ($record['pool_kind'] ?? '');
                    $anchor = (string) ($record['anchor'] ?? '');
                    $member = (string) ($record['member_id'] ?? '');
                    $working->appendMember($kind, $anchor, $member);
                    self::updateInputHash($decisionHash, ['pool_kind' => $kind, 'anchor' => $anchor, 'member_id' => $member]);
                    $hasFunding = true;
                }
            }
            foreach ($decisions->fundingLinkPages(500) as $page) {
                foreach ($page as $record) {
                    $sourceId = (string) ($record['source_id'] ?? '');
                    $costId = (string) ($record['cost_id'] ?? '');
                    $payload = $record['payload'] ?? null;
                    if (!is_array($payload)) NonprofitTaxRules::invalid('Vazba financování nemá strukturovaný podklad.');
                    NonprofitTaxRules::assessment(['funding' => [$payload + ['source_id' => $sourceId, 'cost_id' => $costId]]]);
                    $recordHash = (string) ($record['record_hash'] ?? '');
                    $working->appendLink($sourceId, $costId, $payload, $recordHash);
                    self::updateInputHash($decisionHash, ['source_id' => $sourceId, 'cost_id' => $costId,
                        'payload' => $payload, 'record_hash' => $recordHash]);
                    $hasFunding = true;
                }
            }
            $sourceQueue = $queues[] = new J6ExternalSortQueue();
            $decisionQueue = $queues[] = new J6ExternalSortQueue();
            $snapshotHasher = new NonprofitTaxSnapshotHasher();
            $snapshotRoot = $snapshotHasher->startRoot();
            $snapshotOrdinal = $snapshotRows = $snapshotFindings = 0;
            $sourceHash = hash_init('sha256');
            hash_update($sourceHash, "J6SOURCEINPUT2\0" . NonprofitTaxRules::canonicalJson($sourceHeader->data) . "\0");
            foreach ($sources->pages(null, 500) as $page) {
                if ($page->rows !== [] || $page->findings !== []) {
                    $chunk = $snapshotHasher->chunk($snapshotOrdinal, $page);
                    $snapshotHasher->updateRoot($snapshotRoot, $chunk['descriptor']);
                    $snapshotRows += $chunk['descriptor']['row_count'];
                    $snapshotFindings += $chunk['descriptor']['finding_count'];
                    ++$snapshotOrdinal;
                }
                if ($page->findings !== []) NonprofitTaxRules::invalid('Zdrojový podklad obsahuje nevyřešená zjištění.');
                foreach ($page->rows as $row) {
                    $id = $row['id'] ?? null;
                    if (!is_string($id) || $id === '') NonprofitTaxRules::invalid('Zdrojový řádek nemá jednoznačný identifikátor.');
                    self::updateInputHash($sourceHash, $row);
                    $sourceQueue->append($id, $row);
                }
            }
            $snapshotRootHash = $snapshotHasher->finishRoot($snapshotRoot, $snapshotOrdinal);
            $snapshotInputHash = $snapshotHasher->input($sourceHeader, $snapshotHasher->header($sourceHeader),
                $snapshotRootHash, $snapshotRows, $snapshotFindings, $snapshotOrdinal);
            if (!hash_equals($decisions->context()->sourceInputHash, $snapshotInputHash)) {
                throw new NonprofitTaxAssessmentException('nonprofit_tax_draft_changed',
                    'Koncept posouzení nebo jeho účetní podklad se změnil.', 409);
            }
            foreach ($decisions->decisionPages(500) as $page) {
                foreach ($page as $record) {
                    $id = $record['source_id'] ?? null;
                    if (!is_string($id) || $id === '' || !is_array($record['payload'] ?? null)) NonprofitTaxRules::invalid('Rozhodnutí nemá jednoznačný zdrojový řádek.');
                    self::updateInputHash($decisionHash, $record);
                    $decisionQueue->append($id, $record);
                }
            }
            if ($hasFunding) {
                foreach ($sources->fundingReferencePages(500) as $page) {
                    foreach ($page as $row) {
                        if (!is_array($row)) NonprofitTaxRules::invalid('Historický zdroj financování nemá strukturovaný podklad.');
                        self::updateInputHash($sourceHash, ['record_kind' => 'funding_reference', 'payload' => $row]);
                        $working->appendSource($row, false);
                    }
                }
                foreach ($sources->fundingHistoryPages(500) as $page) {
                    foreach ($page as $row) {
                        if (!is_array($row)) NonprofitTaxRules::invalid('Historie čerpání nemá strukturovaný podklad.');
                        self::updateInputHash($sourceHash, ['record_kind' => 'funding_history', 'payload' => $row]);
                        $working->appendHistory($row);
                    }
                }
            }
            $sourceInputHash = hash_final($sourceHash);
            $decisionInputHash = hash_final($decisionHash);
            $calculationInputHash = NonprofitTaxRules::hash(['domain' => 'J6CALCINPUT2', 'ruleset' => NonprofitTaxRules::VERSION,
                'source_input_hash' => $sourceInputHash, 'decision_input_hash' => $decisionInputHash]);
            $header = new J6CalculationHeader($sourceHeader->data['supplier_id'], $sourceHeader->data['period_id'],
                NonprofitTaxRules::VERSION, $sourceInputHash, $decisionInputHash, $calculationInputHash,
                $sources->context()->sourceManifestId, null, $assessment, $snapshotInputHash);
            $sink->begin($header);

            foreach ($working->fundingReferenceEvidencePages(500) as $page) $sink->appendFundingReferencePage($page);
            foreach ($working->fundingHistoryEvidencePages(500) as $page) $sink->appendFundingHistoryPage($page);

            $decisionRecordCount = 0;
            $activityDecisionPage = [];
            foreach ($working->activities() as $activity) {
                $activityId = $activity['activity_kind_id'];
                $activityPayload = $activity['payload'];
                $activityDecisionPage[] = ['record_kind' => 'activity', 'activity_kind_id' => $activityId,
                    'payload' => $activityPayload, 'decision_record_hash' => $activity['record_hash']];
                ++$decisionRecordCount;
                if (count($activityDecisionPage) === 500) {
                    $sink->appendDecisionPage($activityDecisionPage);
                    $activityDecisionPage = [];
                }
            }
            if ($activityDecisionPage !== []) $sink->appendDecisionPage($activityDecisionPage);

            $manual = array_key_exists('method', $assessment);
            $profile = $manual ? NonprofitTaxRules::documentedProfile($assessment, $sourceHeader->data['period'])
                : NonprofitTaxRules::profile($assessment['profile'] ?? [], $sourceHeader->data['period']);
            $assertedProfile = $manual ? ($assessment['profile']['entity_kind'] ?? 'documented') : $profile;
            if (isset($sourceHeader->data['legal_profile']) && ($sourceHeader->data['legal_profile']['profile']['kind'] ?? null) !== $assertedProfile) {
                NonprofitTaxRules::invalid('Postavení posouzení musí souhlasit s potvrzeným právním profilem období.');
            }
            if (($sourceHeader->data['svj_module_proof'] ?? []) !== [] && $assertedProfile !== 'svj') {
                NonprofitTaxRules::invalid('Potvrzený profil SVJ nelze posuzovat jako veřejně prospěšného poplatníka.');
            }

            $classified = $queues[] = new J6ExternalSortQueue();
            $sourceRows = $sourceQueue->records();
            $decisionRows = $decisionQueue->records();
            $sourceRows->rewind();
            $decisionRows->rewind();
            $decisionPage = [];
            $count = 0;
            $previousId = null;
            while ($sourceRows->valid() || $decisionRows->valid()) {
                if (!$sourceRows->valid() || !$decisionRows->valid()) NonprofitTaxRules::invalid('Počet zdrojových řádků a rozhodnutí se neshoduje.');
                $source = $sourceRows->current();
                $decisionRecord = $decisionRows->current();
                $sourceId = (string) ($source['id'] ?? '');
                $decisionId = (string) ($decisionRecord['source_id'] ?? '');
                if ($sourceId === '' || $sourceId !== $decisionId || $sourceId === $previousId) {
                    NonprofitTaxRules::invalid('Každý zdrojový řádek musí mít právě jedno vlastní posouzení.');
                }
                $decision = $decisionRecord['payload'];
                $activityKind = (string) ($source['activity_kind_id'] ?? '');
                $row = $this->classifySource($source, $decision,
                    [$activityKind => $working->activity($activityKind)], $profile, $manual, $assertedProfile);
                $row['_source_row_hash'] = NonprofitTaxRules::hash(['domain' => 'J6SOURCE2', 'row' => $source]);
                $row['_decision_record_hash'] = (string) ($decisionRecord['record_hash'] ?? NonprofitTaxRules::hash($decisionRecord));
                $working->appendSource($row, true);
                $classified->append((string) $row['group'], $row);
                $decisionPage[] = ['record_kind' => 'source', 'source_id' => $sourceId,
                    'decision_record_hash' => $row['_decision_record_hash'], 'payload' => $decision];
                if (count($decisionPage) === 500) {
                    $sink->appendDecisionPage($decisionPage);
                    $decisionPage = [];
                }
                ++$count;
                ++$decisionRecordCount;
                $previousId = $sourceId;
                $sourceRows->next();
                $decisionRows->next();
            }
            if ($decisionPage !== []) $sink->appendDecisionPage($decisionPage);

            if ($hasFunding) {
                $fundingDecisionPage = [];
                foreach ($working->fundingDecisionRecords() as $record) {
                    $fundingDecisionPage[] = $record;
                    ++$decisionRecordCount;
                    if (count($fundingDecisionPage) === 500) {
                        $sink->appendDecisionPage($fundingDecisionPage);
                        $fundingDecisionPage = [];
                    }
                }
                if ($fundingDecisionPage !== []) $sink->appendDecisionPage($fundingDecisionPage);

                $resolver = new J6FundingResolver($working);
                $resolver->prepare(true);
                $groupCount = $resolver->resolveActivities();
                if ($sink instanceof J6LegacyProjectionSink) {
                    $page = [];
                    foreach ($resolver->activityRows() as $activity) {
                        $page[] = $activity;
                        if (count($page) === 500) { $sink->appendLegacyActivityPage($page); $page = []; }
                    }
                    if ($page !== []) $sink->appendLegacyActivityPage($page);
                    $page = [];
                    foreach ($resolver->externalAdjustmentRows() as $adjustment) {
                        $page[] = $adjustment;
                        if (count($page) === 500) { $sink->appendLegacyExternalAdjustmentPage($page); $page = []; }
                    }
                    if ($page !== []) $sink->appendLegacyExternalAdjustmentPage($page);
                }
                $resultPage = $coveragePage = [];
                $accounting = $excluded = $addedBack = 0;
                foreach ($resolver->finalRows() as $row) {
                    [$result, $coverage, $accountingDelta, $excludedDelta, $addedBackDelta] = self::finishRow($row);
                    $accounting += $accountingDelta;
                    $excluded += $excludedDelta;
                    $addedBack += $addedBackDelta;
                    $resultPage[] = $result;
                    $coveragePage[] = $coverage;
                    if (count($resultPage) === 500) {
                        $sink->appendResultPage($resultPage);
                        $sink->appendCoveragePage($coveragePage);
                        $resultPage = $coveragePage = [];
                    }
                }
                if ($resultPage !== []) {
                    $sink->appendResultPage($resultPage);
                    $sink->appendCoveragePage($coveragePage);
                }
                $usagePage = [];
                $usageCount = 0;
                foreach ($resolver->usageRows() as $usage) {
                    $usagePage[] = $usage;
                    ++$usageCount;
                    if (count($usagePage) === 500) {
                        $sink->appendFundingUsagePage($usagePage);
                        $usagePage = [];
                    }
                }
                if ($usagePage !== []) $sink->appendFundingUsagePage($usagePage);
                return $sink->complete(self::summary($profile, $manual, $count, $decisionRecordCount, $groupCount,
                    $usageCount, $accounting, $excluded, $addedBack));
            }

            $rowsForResolution = $queues[] = new J6ExternalSortQueue();
            $groupsByActivity = $queues[] = new J6ExternalSortQueue();
            $group = null;
            $aggregate = null;
            foreach ($classified->records() as $row) {
                $rowsForResolution->append((string) $row['group'], $row);
                if ($row['classification']['regime'] !== 'pending') continue;
                if ($group !== $row['group']) {
                    if ($aggregate !== null) $groupsByActivity->append((string) $aggregate['activity_kind_id'], $aggregate);
                    $group = $row['group'];
                    $aggregate = ['group' => $group, 'activity_kind_id' => (string) $row['activity_kind_id'],
                        'cohort' => (string) ($row['decision']['cohort'] ?? ''), 'revenue_cents' => 0, 'cost_cents' => 0];
                }
                if ($row['kind'] === 'revenue') $aggregate['revenue_cents'] += $row['amount_cents'];
                else $aggregate['cost_cents'] += $row['deductible_cents'];
            }
            if ($aggregate !== null) $groupsByActivity->append((string) $aggregate['activity_kind_id'], $aggregate);

            $groupReplay = $queues[] = new J6ExternalSortQueue();
            $activityStates = $queues[] = new J6ExternalSortQueue();
            $activity = null;
            $positive = $nonpositive = false;
            foreach ($groupsByActivity->records() as $item) {
                if ($activity !== $item['activity_kind_id']) {
                    if ($activity !== null) $activityStates->append($activity, ['activity_kind_id' => $activity,
                        'mixed' => $positive && $nonpositive]);
                    $activity = $item['activity_kind_id'];
                    $positive = $nonpositive = false;
                }
                if ($item['cohort'] !== '') {
                    $item['revenue_cents'] > $item['cost_cents'] ? $positive = true : $nonpositive = true;
                }
                $groupReplay->append((string) $item['activity_kind_id'], $item);
            }
            if ($activity !== null) $activityStates->append($activity, ['activity_kind_id' => $activity,
                'mixed' => $positive && $nonpositive]);

            $resolutions = $queues[] = new J6ExternalSortQueue();
            $states = $activityStates->records();
            $states->rewind();
            $currentState = $states->valid() ? $states->current() : null;
            $groupCount = 0;
            $legacyActivityPage = [];
            foreach ($groupReplay->records() as $item) {
                while ($currentState !== null && strcmp($currentState['activity_kind_id'], $item['activity_kind_id']) < 0) {
                    $states->next();
                    $currentState = $states->valid() ? $states->current() : null;
                }
                if ($currentState === null || $currentState['activity_kind_id'] !== $item['activity_kind_id']) {
                    throw new \UnexpectedValueException('J6 activity state is missing.');
                }
                $out = $currentState['mixed'] ? $item['revenue_cents'] <= $item['cost_cents'] : $item['revenue_cents'] < $item['cost_cents'];
                $item['regime'] = $out ? 'out_of_scope' : 'subject';
                $item['contribution_cents'] = $out ? 0 : $item['revenue_cents'] - $item['cost_cents'];
                $resolutions->append((string) $item['group'], $item);
                if ($sink instanceof J6LegacyProjectionSink) {
                    $legacyActivityPage[] = $item;
                    if (count($legacyActivityPage) === 500) {
                        $sink->appendLegacyActivityPage($legacyActivityPage);
                        $legacyActivityPage = [];
                    }
                }
                ++$groupCount;
            }
            while ($states->valid()) $states->next();
            if ($legacyActivityPage !== []) $sink->appendLegacyActivityPage($legacyActivityPage);

            $finalRows = $queues[] = new J6ExternalSortQueue();
            $resolutionRows = $resolutions->records();
            $resolutionRows->rewind();
            $resolution = $resolutionRows->valid() ? $resolutionRows->current() : null;
            $accounting = $excluded = $addedBack = 0;
            foreach ($rowsForResolution->records() as $row) {
                if ($row['classification']['regime'] === 'pending') {
                    while ($resolution !== null && strcmp($resolution['group'], $row['group']) < 0) {
                        $resolutionRows->next();
                        $resolution = $resolutionRows->valid() ? $resolutionRows->current() : null;
                    }
                    if ($resolution === null || $resolution['group'] !== $row['group']) throw new \UnexpectedValueException('J6 activity resolution is missing.');
                    $row['classification']['regime'] = $resolution['regime'];
                    $row['classification']['method'] = $resolution['regime'] === 'subject' ? 'return' : 'none';
                    if ($row['kind'] === 'expense' && $resolution['regime'] === 'out_of_scope') $row['deductible_cents'] = 0;
                }
                $row['funded_excluded_cents'] = 0;
                [$result, $coverage, $accountingDelta, $excludedDelta, $addedBackDelta] = self::finishRow($row);
                $accounting += $accountingDelta;
                $excluded += $excludedDelta;
                $addedBack += $addedBackDelta;
                $finalRows->append((string) $row['id'], ['result' => $result, 'coverage' => $coverage]);
            }
            while ($resolutionRows->valid()) $resolutionRows->next();

            $resultPage = $coveragePage = [];
            foreach ($finalRows->records() as $pair) {
                $resultPage[] = $pair['result'];
                $coveragePage[] = $pair['coverage'];
                if (count($resultPage) === 500) {
                    $sink->appendResultPage($resultPage);
                    $sink->appendCoveragePage($coveragePage);
                    $resultPage = $coveragePage = [];
                }
            }
            if ($resultPage !== []) {
                $sink->appendResultPage($resultPage);
                $sink->appendCoveragePage($coveragePage);
            }
            return $sink->complete(self::summary($profile, $manual, $count, $decisionRecordCount, $groupCount,
                0, $accounting, $excluded, $addedBack));
        } catch (\Throwable $failure) {
            $sink->abort();
            throw $failure;
        } finally {
            unset($sourceRows, $decisionRows, $states, $resolutionRows);
            foreach ($queues as $queue) $queue->closeAndDelete();
            $working->closeAndDelete();
        }
    }

    private static function updateInputHash(\HashContext $context, array $record): void
    {
        $json = NonprofitTaxRules::canonicalJson($record);
        hash_update($context, pack('N', strlen($json)) . $json);
    }

    private static function finishRow(array $row): array
    {
        $accounting = $excluded = $addedBack = 0;
        if ($row['kind'] === 'revenue') {
            $accounting = $row['amount_cents'];
            $row['adjustment_cents'] = $row['classification']['regime'] !== 'subject'
                || $row['classification']['method'] === 'final_withholding' ? -$row['amount_cents'] : 0;
            $excluded = -$row['adjustment_cents'];
        } else {
            $accounting = -$row['amount_cents'];
            $row['adjustment_cents'] = $row['amount_cents'] - $row['deductible_cents'];
            $addedBack = $row['adjustment_cents'];
        }
        $sourceRowHash = $row['_source_row_hash'] ?? null;
        $decisionRecordHash = $row['_decision_record_hash'] ?? null;
        if (!is_string($sourceRowHash) || !is_string($decisionRecordHash)) {
            throw new \UnexpectedValueException('J6 result row has no immutable source and decision binding.');
        }
        unset($row['_source_row_hash'], $row['_decision_record_hash']);
        $resultHash = NonprofitTaxRules::hash(['domain' => 'J6RESULT2', 'row' => $row]);
        $factHashes = [];
        foreach ($row['module_facts'] ?? [] as $schema => $fact) {
            $factHashes[$schema] = NonprofitTaxRules::hash(['domain' => 'J6FACT2', 'fact' => $fact]);
        }
        ksort($factHashes, SORT_STRING);
        $coverage = ['source_id' => $row['id'], 'source_line_id' => $row['line_id'] ?? null,
            'source_row_hash' => $sourceRowHash, 'decision_record_hash' => $decisionRecordHash,
            'result_row_hash' => $resultHash, 'module_fact_hashes' => $factHashes];
        $coverage['coverage_record_hash'] = NonprofitTaxRules::hash(['domain' => 'J6COVERAGE2', 'row' => $coverage]);
        $row['result_row_hash'] = $resultHash;
        return [$row, $coverage, $accounting, $excluded, $addedBack];
    }

    private static function summary(string $profile, bool $manual, int $sourceCount, int $decisionCount,
        int $groupCount, int $usageCount, int $accounting, int $excluded, int $addedBack): array
    {
        return ['schema' => 'nonprofit-j6-summary.v2', 'ruleset' => NonprofitTaxRules::VERSION,
            'profile' => $profile, 'assessment_method' => $manual ? 'documented_manual' : 'ruleset',
            'scope' => 'classification_bridge_before_general_adjustments', 'source_row_count' => $sourceCount,
            'decision_record_count' => $decisionCount, 'activity_group_count' => $groupCount,
            'funding_usage_count' => $usageCount, 'accounting_result_cents' => $accounting,
            'excluded_revenue_cents' => $excluded, 'cost_addbacks_cents' => $addedBack,
            'assessed_result_cents' => $accounting - $excluded + $addedBack,
            'external_adjustment_cents' => 0, 'dppo_supported' => false];
    }

    public function calculateLegacyArray(array $snapshot, array $assessment): array
    {
        NonprofitTaxRules::assessment($assessment);
        $sources = new LegacyArrayJ6SourceProvider($snapshot);
        $context = $sources->context();
        $decisions = new LegacyArrayJ6DecisionProvider($assessment, $context->supplierId, $context->periodId, $sources->inputHash());
        $sourceOrder = $groupOrder = $poolOrder = [];
        foreach ((array) ($snapshot['sources'] ?? []) as $source) {
            $id = (string) ($source['id'] ?? '');
            $sourceOrder[] = $id;
            $decision = (array) (($assessment['decisions'] ?? [])[$id] ?? []);
            $groupOrder[] = (string) ($source['activity_kind_id'] ?? '') . ':' . (string) ($decision['cohort'] ?? '');
        }
        foreach ((array) ($assessment['funding_sources'] ?? []) as $pool) $poolOrder[] = (string) ($pool['anchor'] ?? '');
        foreach (array_merge((array) ($snapshot['sources'] ?? []), array_values((array) ($snapshot['funding_reference_sources'] ?? []))) as $source) {
            if (($source['kind'] ?? null) === 'revenue'
                && in_array($source['decision']['title'] ?? (($assessment['decisions'] ?? [])[$source['id']]['title'] ?? null),
                    ['gift', 'public_operating_grant', 'public_investment_grant'], true)) {
                $poolOrder[] = (string) ($source['id'] ?? '');
            }
        }
        $sink = new LegacyArrayJ6CalculationSink($assessment, $sourceOrder,
            array_values(array_unique($groupOrder)), array_values(array_unique($poolOrder)));
        $this->calculate($sources, $decisions, $sink);
        return $sink->result();
    }

    public function classifySource(array $source, array $decision, array $activities, string $profile, bool $manual, string $assertedProfile): array
    {
        NonprofitTaxRules::evidence($decision['evidence'] ?? null);
        $amount = $source['amount_cents'] ?? null;
        if (!is_int($amount)) NonprofitTaxRules::invalid('Částka zdroje musí být vyjádřena v celých haléřích.');
        $kind = (string) ($source['activity_kind_id'] ?? '');
        NonprofitTaxRules::evidence($activities[$kind]['evidence'] ?? null);
        $cohort = (string) ($decision['cohort'] ?? '');
        $declared = $activities[$kind]['cohorts'] ?? [];
        if ($declared !== []) {
            if ($cohort === '' || !array_key_exists($cohort, $declared)) NonprofitTaxRules::invalid('Zdroj nemá doloženou cenovou kohortu.');
            NonprofitTaxRules::evidence($declared[$cohort]);
        } elseif ($cohort !== '') {
            NonprofitTaxRules::invalid('Cenová kohorta musí mít předem doložené podmínky.');
        }
        $row = $source + ['decision' => $decision, 'group' => $kind . ':' . $cohort];
        if (($source['kind'] ?? null) === 'revenue') {
            $row['classification'] = $manual ? NonprofitTaxRules::documentedClassification($decision, 'revenue') : NonprofitTaxRules::revenue($profile, $decision);
            if ($row['classification']['method'] === 'final_withholding') {
                $facts = $decision['facts'] ?? [];
                $gross = $facts['gross_interest_cents'] ?? null;
                $withheld = $facts['withholding_cents'] ?? null;
                if (!is_int($gross) || !is_int($withheld) || $gross - $withheld !== $amount
                    || abs($withheld) > abs($gross) || $withheld * ($gross <=> 0) < 0) {
                    NonprofitTaxRules::invalid('Čistý bankovní úrok musí souhlasit s doloženým hrubým úrokem a sraženou daní.');
                }
            }
            $row['deductible_cents'] = 0;
        } elseif (($source['kind'] ?? null) === 'expense') {
            $allowed = $decision['deductible_cents'] ?? null;
            if (!is_int($allowed) || abs($allowed) > abs($amount) || $allowed * ($amount <=> 0) < 0) NonprofitTaxRules::invalid('Uznatelná část nákladu musí odpovídat znaménku a rozsahu zdrojové částky.');
            NonprofitTaxRules::evidence($decision['legal_basis'] ?? null);
            if (!empty($source['mandatory_non_deductible']) && $allowed !== 0) NonprofitTaxRules::invalid('Daňově neuznatelný zdrojový náklad nelze potvrdit jako uznatelný.');
            $title = $decision['title'] ?? '';
            if (!in_array($title, ['cost', 'nonbusiness_cost', 'excluded_cost', 'withholding_interest_cost'], true)) NonprofitTaxRules::invalid('Náklad nemá podporovaný účel.');
            if ($title === 'excluded_cost' && $allowed !== 0) NonprofitTaxRules::invalid('Vyloučený náklad nemůže být daňově uznatelný.');
            if ($title === 'withholding_interest_cost' && !in_array($profile, ['narrow_vpp', 'broad_vpp'], true)) NonprofitTaxRules::invalid('Výjimka nákladů na srážkové úroky vyžaduje veřejně prospěšného poplatníka.');
            $row['classification'] = $manual ? NonprofitTaxRules::documentedClassification($decision, 'expense')
                : ['regime' => $title === 'nonbusiness_cost' && $profile === 'narrow_vpp' ? 'pending' : 'subject',
                    'method' => 'cost', 'legal_basis' => $decision['legal_basis']];
            $row['deductible_cents'] = $allowed;
        } else {
            NonprofitTaxRules::invalid('Neznámý druh zdrojového řádku.');
        }
        $row['classification'] = NonprofitTaxRules::svjClassification($source, $decision, $row['classification'], $assertedProfile);
        return $row;
    }

    public function fundingIncomePools(array $result,array $snapshot,array $assessment):array
    {
        $rows=array_column($result['rows'],null,'id');
        return $this->pools($rows+($snapshot['funding_reference_sources']??[]),$assessment['funding_sources']??[],'revenue',$rows,($assessment['funding']??[])!==[]);
    }

    public function fundingCostPools(array $result,array $assessment):array
    {
        $rows=array_column($result['rows'],null,'id');
        return $this->pools($rows,$assessment['funding_costs']??[],'expense',$rows,($assessment['funding']??[])!==[]);
    }

    private function pools(array $catalog, array $declared, string $kind, array $currentRows, bool $hasFunding): array
    {
        $eligible = static fn (array $row): bool => $row['kind'] === $kind && ($kind === 'expense'
            || in_array($row['decision']['title'] ?? '', ['gift', 'public_operating_grant', 'public_investment_grant'], true));
        $pools = $claimed = [];
        foreach ($declared as $pool) {
            NonprofitTaxRules::evidence($pool['evidence'] ?? null);
            $anchor = $pool['anchor'] ?? null;
            $members = $pool['members'] ?? null;
            if (!is_string($anchor) || !is_array($members) || !array_is_list($members) || $members === []
                || !in_array($anchor, $members, true) || !isset($catalog[$anchor]) || $catalog[$anchor]['amount_cents'] <= 0) $this->review('Financování vyžaduje jednoznačný původní kladný řádek a doložené členy včetně oprav.');
            $base = $catalog[$anchor];
            $total = $deductible = 0;
            foreach ($members as $id) {
                if (!is_string($id) || !isset($catalog[$id]) || isset($claimed[$id]) || !$eligible($catalog[$id])) NonprofitTaxRules::invalid('Člen financování chybí, patří k jinému druhu nebo je použit opakovaně.');
                $row = $catalog[$id];
                if (!isset($currentRows[$id]) && ($row['reference_current'] ?? false) !== true) $this->review('Historický zdroj financování nemá aktuálně ověřený účetní podklad.');
                if ($row['activity_kind_id'] !== $base['activity_kind_id'] || ($kind === 'revenue'
                    && ($row['decision']['title'] !== $base['decision']['title'] || $row['classification']['regime'] !== $base['classification']['regime']))) {
                    $this->review('Spojení zdrojů s odlišnou činností nebo právním režimem vyžaduje samostatné posouzení.');
                }
                $claimed[$id] = true;
                $total += $row['amount_cents'];
                $deductible += $row['deductible_cents'] ?? 0;
            }
            sort($members, SORT_STRING);
            $pools[$anchor] = ['id' => $anchor, 'members' => $members, 'amount_cents' => $total, 'deductible_cents' => $deductible,
                'classification' => $base['classification'], 'prior_period_review' => $pool['prior_period_review'] ?? null];
        }
        foreach ($currentRows as $id => $row) {
            if (!$eligible($row) || isset($claimed[$id])) continue;
            if ($row['amount_cents'] < 0 && ($hasFunding || $kind === 'revenue')) $this->review('Vrácení zdroje nebo nákladu musí mít doloženou vazbu na původní řádek financování.');
            if ($row['amount_cents'] <= 0) continue;
            $pools[$id] = ['id' => $id, 'members' => [$id], 'amount_cents' => $row['amount_cents'],
                'deductible_cents' => $row['deductible_cents'] ?? 0, 'classification' => $row['classification']];
        }
        return $pools;
    }

    private function review(string $message): never
    {
        throw new NonprofitTaxAssessmentException('nonprofit_tax_review_required', $message);
    }
}
