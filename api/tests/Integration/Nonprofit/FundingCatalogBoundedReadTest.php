<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Nonprofit;

use MyInvoice\Repository\Nonprofit\FundingRepository;
use MyInvoice\Service\Nonprofit\Catalog\NonprofitResourceCatalog;
use MyInvoice\Service\Nonprofit\Funding\FundingService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Tests\Support\NonprofitResourceCaseFixture;
use MyInvoice\Tests\Support\ResourceReadBearerFixture;
use PHPUnit\Framework\TestCase;

final class FundingCatalogBoundedReadTest extends TestCase
{
    use ResourceReadBearerFixture;
    use NonprofitResourceCaseFixture;

    public function testRecordedMetadataDoesNotInventCurrentUsageOrPurposeDecision(): void
    {
        $source = $this->fundingSource();
        $before = $this->domainDmlCounters();
        $result = $this->container->get(NonprofitResourceCatalog::class)->funding($this->supplier);
        self::assertSame($before, $this->domainDmlCounters());
        self::assertCount(1, $result['items']);
        $item = $result['items'][0];
        self::assertSame($source['id'], $item['id']);
        self::assertSame('recorded_chain_verified', $item['proof_status']);
        self::assertNull($item['source_current']);
        self::assertNull($item['used_cents']);
        self::assertNull($item['budget_remaining_cents']);
        self::assertNull($item['available_cents']);
        self::assertNull($item['usage_count']);
        self::assertSame('verified', $result['coverage']['revision_history']);
        self::assertSame('unknown', $result['coverage']['purpose_decision']);
        self::assertSame('not_available', $result['coverage']['usage_links']);
        self::assertSame('not_assessed', $result['coverage']['eligibility']);
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        foreach ([self::PRIVATE_SENTINEL, 'PRIVATE-SOURCE-KEY', 'policy_json', 'basis_json', 'request_json',
            'request_hash', 'record_hash', 'parent_hash', 'created_by', 'reason', 'evidence'] as $private) {
            self::assertStringNotContainsString($private, $json);
        }
    }

    public function testSelectedHistoryUsesFiveHundredRowKeysetBatchesAndSkipsUnrelatedChain(): void
    {
        $first = $this->fundingSource(false);
        $repository = $this->container->get(FundingRepository::class);
        $original = $repository->latest($this->supplier)['PRIVATE-SOURCE-KEY'];
        $policy = $original['policy'];
        $policy['source_anchor'] = 'line:999999001';
        $policy['source_members'] = [$policy['source_anchor']];
        $previous = null;
        $secondId = null;
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO nonprofit_funding_revisions
                (supplier_id,period_id,source_key,revision_no,previous_id,parent_hash,provider_id,assessment_id,
                 policy_json,basis_json,request_json,reason,idempotency_key,request_hash,record_hash,created_by)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        for ($revision = 1; $revision <= 501; ++$revision) {
            $policy['budget_cents'] = 70000 + $revision;
            $request = ['schema' => 1, 'revision' => $revision];
            if ($previous !== null) {
                $row = array_replace($previous, [
                    'revision_no' => $revision, 'previous_id' => $previous['id'], 'parent_hash' => $previous['record_hash'],
                    'policy' => $policy, 'request' => $request, 'idempotency_key' => 'second-source-' . $revision,
                    'request_hash' => NonprofitTaxRules::hash($request),
                ]);
                $row['record_hash'] = FundingRepository::recordHash($row);
                $insert->execute([
                    $this->supplier, $this->period, 'SECOND-SOURCE', $revision, $row['previous_id'], $row['parent_hash'],
                    $row['provider_id'], $row['assessment_id'], NonprofitTaxRules::canonicalJson($policy),
                    NonprofitTaxRules::canonicalJson($row['basis']), NonprofitTaxRules::canonicalJson($request),
                    $row['reason'], $row['idempotency_key'], $row['request_hash'], $row['record_hash'], $this->actor,
                ]);
                $row['id'] = (int) $this->db->pdo()->lastInsertId();
                $previous = $row;
                continue;
            }
            $previous = $repository->append(
                $this->supplier,
                $this->period,
                'SECOND-SOURCE',
                $previous,
                $policy,
                $original['basis'],
                'Synthetic bounded history',
                'second-source-' . $revision,
                NonprofitTaxRules::hash($request),
                $this->actor,
                $request,
            );
            $secondId ??= $previous['id'];
        }
        $catalog = $this->container->get(NonprofitResourceCatalog::class);
        $beforeDml = $this->domainDmlCounters();
        $before = $this->sessionStatus();
        $detail = $catalog->funding($this->supplier, id: $first['id']);
        $afterDetail = $this->sessionStatus();
        self::assertSame($first['id'], $detail['item']['id']);
        self::assertSame(1, $detail['item']['revision_no']);
        self::assertLessThanOrEqual(20, $afterDetail['Rows_sent'] - $before['Rows_sent']);
        self::assertLessThanOrEqual(10, $afterDetail['Com_select'] - $before['Com_select']);
        $list = $catalog->funding($this->supplier, limit: 100);
        $afterList = $this->sessionStatus();
        self::assertSame($beforeDml, $this->domainDmlCounters());
        self::assertCount(2, $list['items']);
        self::assertSame($secondId, $list['items'][1]['id']);
        self::assertSame(501, $list['items'][1]['revision_no']);
        self::assertSame('70501', $list['items'][1]['budget_cents']);
        self::assertNull($list['next_cursor']);
        self::assertLessThanOrEqual(530, $afterList['Rows_sent'] - $afterDetail['Rows_sent']);
        self::assertLessThanOrEqual(10, $afterList['Com_select'] - $afterDetail['Com_select']);
    }

    public function testForeignOrNonStableIdIsHiddenAndCorruptChainFailsClosed(): void
    {
        $source = $this->fundingSource(false);
        $repository = $this->container->get(FundingRepository::class);
        $previous = $repository->latest($this->supplier)['PRIVATE-SOURCE-KEY'];
        $policy = $previous['policy'];
        ++$policy['budget_cents'];
        $request = ['schema' => 1, 'revision' => 2];
        $revision = $repository->append($this->supplier, $this->period, 'PRIVATE-SOURCE-KEY', $previous,
            $policy, $previous['basis'], 'Synthetic second revision', 'stable-id-second',
            NonprofitTaxRules::hash($request), $this->actor, $request);
        $catalog = $this->container->get(NonprofitResourceCatalog::class);
        foreach ([[$this->otherSupplier, $source['id']], [$this->supplier, $revision['id']]] as [$supplier, $id]) {
            try {
                $catalog->funding($supplier, id: $id);
                self::fail('Foreign or non-stable funding id was exposed.');
            } catch (NonprofitTaxAssessmentException $e) {
                self::assertSame('nonprofit_catalog_not_found', $e->errorCode);
            }
        }
        $this->db->pdo()->prepare(
            "INSERT INTO nonprofit_funding_revisions
                (supplier_id,period_id,source_key,revision_no,previous_id,parent_hash,provider_id,assessment_id,
                 policy_json,basis_json,request_json,reason,idempotency_key,request_hash,record_hash,created_by)
             SELECT supplier_id,period_id,source_key,revision_no+1,id,record_hash,provider_id,assessment_id,
                    policy_json,basis_json,request_json,reason,CONCAT(idempotency_key,'-corrupt'),request_hash,REPEAT('0',64),created_by
               FROM nonprofit_funding_revisions WHERE supplier_id=? AND id=?"
        )->execute([$this->supplier, $revision['id']]);
        try {
            $catalog->funding($this->supplier);
            self::fail('Corrupt funding chain returned a partial catalog.');
        } catch (NonprofitTaxAssessmentException $e) {
            self::assertSame('nonprofit_funding_integrity', $e->errorCode);
        }
    }

    public function testRehashedHundredThousandMemberRevisionFailsBeforeOversizedJsonReachesPhp(): void
    {
        $this->fundingSource(false);
        $template = $this->container->get(FundingRepository::class)->latest($this->supplier)['PRIVATE-SOURCE-KEY'];
        $members = [];
        for ($member = 1; $member <= 100001; ++$member) {
            $members[] = 'line:' . $member;
        }
        $policy = $template['policy'];
        $policy['source_anchor'] = $members[0];
        $policy['source_members'] = $members;
        $basis = ['assessment_id' => $template['assessment_id']];
        $request = ['expected_revision' => 0];
        $row = [
            'supplier_id' => $this->supplier,
            'period_id' => $this->period,
            'source_key' => 'OVERSIZED-SOURCE',
            'revision_no' => 1,
            'previous_id' => null,
            'parent_hash' => str_repeat('0', 64),
            'provider_id' => $template['provider_id'],
            'assessment_id' => $template['assessment_id'],
            'policy' => $policy,
            'basis' => $basis,
            'reason' => 'Synthetic oversized historic revision',
            'idempotency_key' => 'oversized-source',
            'request_hash' => NonprofitTaxRules::hash($request),
            'created_by' => $this->actor,
            'request' => $request,
        ];
        $row['record_hash'] = FundingRepository::recordHash($row);
        $this->db->pdo()->prepare(
            'INSERT INTO nonprofit_funding_revisions
                (supplier_id,period_id,source_key,revision_no,previous_id,parent_hash,provider_id,assessment_id,
                 policy_json,basis_json,request_json,reason,idempotency_key,request_hash,record_hash,created_by)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $row['supplier_id'], $row['period_id'], $row['source_key'], $row['revision_no'], $row['previous_id'],
            $row['parent_hash'], $row['provider_id'], $row['assessment_id'], NonprofitTaxRules::canonicalJson($policy),
            NonprofitTaxRules::canonicalJson($basis), NonprofitTaxRules::canonicalJson($request), $row['reason'],
            $row['idempotency_key'], $row['request_hash'], $row['record_hash'], $row['created_by'],
        ]);
        unset($members, $policy, $basis, $request, $row, $template);
        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage(true);

        foreach ([
            fn () => $this->container->get(NonprofitResourceCatalog::class)->funding($this->supplier, limit: 100),
            fn () => $this->container->get(FundingService::class)->read($this->supplier),
        ] as $read) {
            try {
                $read();
                self::fail('Oversized rehashed funding revision was materialized.');
            } catch (NonprofitTaxAssessmentException $failure) {
                self::assertSame('nonprofit_funding_integrity', $failure->errorCode);
            }
        }

        self::assertLessThan(8 * 1024 * 1024, memory_get_peak_usage(true) - $before);
    }

    private function sessionStatus(): array
    {
        $rows = $this->db->pdo()->query("SHOW SESSION STATUS WHERE Variable_name IN ('Com_select','Rows_sent')")
            ->fetchAll(\PDO::FETCH_KEY_PAIR);
        return array_map('intval', $rows);
    }
}
