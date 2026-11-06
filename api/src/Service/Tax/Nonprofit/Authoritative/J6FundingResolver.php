<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;

/**
 * Vyhodnocení financování a ročního testu činností nad pracovním úložištěm J6.
 * Veškeré mezivýsledky jsou dočasné tabulky téže instance úložiště.
 */
final readonly class J6FundingResolver
{
    private const string ID = 'VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL';
    private const string CODE = 'VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL';
    private const string JSON = 'LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL';
    private const string KIND = 'VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL';

    public function __construct(private J6WorkingStore $store) {}

    private function label(): string
    {
        return J6WorkingStore::LABEL;
    }

    public function prepare(bool $hasFunding): void
    {
        $id = self::ID;
        $kind = self::KIND;
        $json = self::JSON;
        $this->store->exec("CREATE TEMPORARY TABLE {resolved_pools} (
            pool_kind $kind,
            anchor $id,
            amount_cents BIGINT NOT NULL,
            deductible_cents BIGINT NOT NULL,
            prior_drawdown_cents BIGINT NOT NULL DEFAULT 0,
            payload_json $json CHECK(JSON_VALID(payload_json)),
            PRIMARY KEY(pool_kind,anchor)
        ) ENGINE=InnoDB");
        $this->store->exec("CREATE TEMPORARY TABLE {resolved_pool_members} (
            pool_kind $kind,
            anchor $id,
            member_id $id,
            PRIMARY KEY(pool_kind,anchor,member_id),
            UNIQUE KEY uq_j6_resolved_kind(pool_kind,member_id),
            KEY ix_j6_resolved_member(member_id,pool_kind,anchor)
        ) ENGINE=InnoDB");
        $this->store->exec("CREATE TEMPORARY TABLE {funding_link_eval} (
            source_anchor $id,
            cost_id $id,
            cost_anchor $id,
            amount_cents BIGINT NOT NULL CHECK(amount_cents>0),
            overlap_cents BIGINT NOT NULL CHECK(overlap_cents>=0),
            payload_json $json CHECK(JSON_VALID(payload_json)),
            PRIMARY KEY(source_anchor,cost_id),
            KEY ix_j6_eval_cost(cost_id,source_anchor),
            KEY ix_j6_eval_pool(cost_anchor,source_anchor,cost_id)
        ) ENGINE=InnoDB");
        $this->declaredPools();
        $this->implicitPools($hasFunding);
        $this->history();
        $this->links();
        $this->capacities();
    }

    public function resolveActivities(): int
    {
        $id = self::ID;
        $this->store->exec("CREATE TEMPORARY TABLE {activity_nodes} (
            activity_kind_id $id PRIMARY KEY,
            resolved TINYINT NOT NULL DEFAULT 0 CHECK(resolved IN (0,1))
        ) ENGINE=InnoDB");
        $this->store->exec("INSERT INTO {activity_nodes}(activity_kind_id)
            SELECT DISTINCT activity_kind_id FROM {sources} WHERE current_row=1 AND regime='pending'");
        $this->store->exec("CREATE TEMPORARY TABLE {activity_edges} (
            parent_activity $id,
            child_activity $id,
            PRIMARY KEY(parent_activity,child_activity),
            KEY ix_j6_edges_child(child_activity,parent_activity)
        ) ENGINE=InnoDB");
        $this->store->exec("INSERT IGNORE INTO {activity_edges}(parent_activity,child_activity)
            SELECT src.activity_kind_id,cost.activity_kind_id
            FROM {funding_link_eval} e JOIN {sources} src ON src.source_id=e.source_anchor
            JOIN {sources} cost ON cost.source_id=e.cost_id
            WHERE src.regime='pending' AND cost.regime='pending' AND cost.deductible_cents<>0");
        $this->store->exec("CREATE TEMPORARY TABLE {activity_group_results} (
            group_id {$this->label()} PRIMARY KEY,
            activity_kind_id $id,
            cohort {$this->label()},
            revenue_cents BIGINT NOT NULL,
            cost_cents BIGINT NOT NULL,
            regime VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL CHECK(regime IN ('subject','out_of_scope')),
            contribution_cents BIGINT NOT NULL
        ) ENGINE=InnoDB");
        $groups = 0;
        $markResolved = $this->store->prepare('UPDATE {activity_nodes} SET resolved=1 WHERE activity_kind_id=?');
        while ((int) $this->store->scalar('SELECT COUNT(*) FROM {activity_nodes} WHERE resolved=0') > 0) {
            $activity = $this->store->scalar("SELECT n.activity_kind_id FROM {activity_nodes} n WHERE n.resolved=0
                AND NOT EXISTS(SELECT 1 FROM {activity_edges} e JOIN {activity_nodes} p ON p.activity_kind_id=e.parent_activity
                    WHERE e.child_activity=n.activity_kind_id AND p.resolved=0)
                ORDER BY n.activity_kind_id LIMIT 1");
            if (!is_string($activity)) {
                $groups += $this->resolveConditionalCycle();
                break;
            }
            $groups += $this->resolveActivity($activity);
            $markResolved->execute([$activity]);
        }
        $this->resolvedFunding();
        return $groups;
    }

    public function activityRows(): iterable
    {
        $rows = $this->store->stream('SELECT group_id,activity_kind_id,cohort,revenue_cents,cost_cents,regime,contribution_cents
            FROM {activity_group_results}', [], 'group_id');
        foreach ($rows as $row) {
            yield ['group' => $row['group_id'], 'activity_kind_id' => $row['activity_kind_id'], 'cohort' => $row['cohort'],
                'revenue_cents' => (int) $row['revenue_cents'], 'cost_cents' => (int) $row['cost_cents'],
                'regime' => $row['regime'], 'contribution_cents' => (int) $row['contribution_cents']];
        }
    }

    public function externalAdjustmentRows(): iterable
    {
        $rows = $this->store->stream("SELECT anchor,payload_json,prior_drawdown_cents,amount_cents
            FROM {resolved_pools} WHERE pool_kind='revenue' AND amount_cents-prior_drawdown_cents<0", [], 'anchor');
        foreach ($rows as $row) {
            $payload = json_decode($row['payload_json'], true, flags: JSON_THROW_ON_ERROR);
            $review = $payload['prior_period_review'];
            yield ['evidence' => $review['evidence'], 'legal_basis' => $review['legal_basis'],
                'adjustment_cents' => $review['adjustment_cents'], 'funding_anchor' => $row['anchor'],
                'prior_drawdown_cents' => (int) $row['prior_drawdown_cents'], 'net_source_cents' => (int) $row['amount_cents'],
                'included_in_bridge' => false];
        }
    }

    public function finalRows(): iterable
    {
        $this->materializeExclusions('final_exclusions');
        $rows = $this->store->stream('SELECT s.source_id,s.row_json,s.regime AS stored_regime,s.method AS stored_method,
                g.regime AS group_regime,COALESCE(x.excluded_cents,0) AS funded_excluded_cents
            FROM {sources} s
            LEFT JOIN {activity_group_results} g ON g.group_id=s.group_id
            LEFT JOIN {final_exclusions} x ON x.cost_id=s.source_id
            WHERE s.current_row=1', [], 'source_id');
        foreach ($rows as $stored) {
            $row = json_decode($stored['row_json'], true, flags: JSON_THROW_ON_ERROR);
            $regime = $stored['stored_regime'] === 'pending' ? $stored['group_regime'] : $stored['stored_regime'];
            if (!is_string($regime)) throw new \UnexpectedValueException('J6 activity result is missing.');
            // Výnosy roční test přepíše už v pracovní tabulce, proto se porovnává s původním
            // zařazením řádku: každý řádek vyřešený testem dostane konečný způsob zdanění.
            $original = $row['classification']['regime'] ?? null;
            $row['classification']['regime'] = $regime;
            if ($original !== $regime) {
                $row['classification']['method'] = $regime === 'subject' ? 'return' : 'none';
            }
            $excluded = (int) $stored['funded_excluded_cents'];
            $row['funded_excluded_cents'] = $excluded;
            if ($row['kind'] === 'expense') {
                $row['deductible_cents'] = $regime === 'out_of_scope' ? 0
                    : ($row['amount_cents'] <=> 0) * (abs($row['deductible_cents']) - abs($excluded));
            }
            yield $row;
        }
    }

    public function usageRows(): iterable
    {
        $id = self::ID;
        $this->store->dropTable('usage_draw');
        $this->store->exec("CREATE TEMPORARY TABLE {usage_draw} (source_anchor $id PRIMARY KEY) ENGINE=InnoDB
            SELECT source_anchor,SUM(amount_cents) AS drawdown_cents FROM {funding_link_eval} GROUP BY source_anchor");
        $this->store->dropTable('usage_members');
        $this->store->exec("CREATE TEMPORARY TABLE {usage_members} (pool_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            anchor $id, PRIMARY KEY(pool_kind,anchor)) ENGINE=InnoDB
            SELECT pool_kind,anchor,COUNT(*) AS member_count FROM {resolved_pool_members} GROUP BY pool_kind,anchor");
        $rows = $this->store->stream("SELECT p.anchor,m.member_id,p.amount_cents AS net_source_cents,p.prior_drawdown_cents,
                COALESCE(u.drawdown_cents,0) AS drawdown_cents,
                p.amount_cents-p.prior_drawdown_cents-COALESCE(u.drawdown_cents,0) AS remaining_cents
            FROM {resolved_pools} p JOIN {resolved_pool_members} m ON m.pool_kind=p.pool_kind AND m.anchor=p.anchor
            LEFT JOIN {usage_draw} u ON u.source_anchor=p.anchor
            JOIN {usage_members} c ON c.pool_kind=p.pool_kind AND c.anchor=p.anchor
            WHERE p.pool_kind='revenue' AND (u.drawdown_cents IS NOT NULL OR p.prior_drawdown_cents<>0 OR c.member_count>1)",
            [], 'anchor,member_id');
        foreach ($rows as $row) {
            $row = ['anchor' => $row['anchor'], 'member_id' => $row['member_id'],
                'net_source_cents' => (int) $row['net_source_cents'], 'prior_drawdown_cents' => (int) $row['prior_drawdown_cents'],
                'drawdown_cents' => (int) $row['drawdown_cents'], 'remaining_cents' => (int) $row['remaining_cents']];
            $row['usage_record_hash'] = NonprofitTaxRules::hash(['domain' => 'J6FUNDINGUSAGE2', 'row' => $row]);
            yield $row;
        }
    }

    private function declaredPools(): void
    {
        $rows = $this->store->stream("SELECT p.pool_kind,p.anchor,p.payload_json,a.member_count,a.source_count,a.anchor_count,
                a.valid_anchor_count,a.eligible_count,a.current_count,a.activity_count,a.title_count,a.regime_count,
                a.amount_cents,a.deductible_cents
            FROM {funding_pools} p JOIN (
                SELECT p.pool_kind,p.anchor,
                    COUNT(m.member_id) AS member_count,
                    COALESCE(SUM(s.source_id IS NOT NULL),0) AS source_count,
                    COALESCE(SUM(m.member_id=p.anchor),0) AS anchor_count,
                    COALESCE(SUM(m.member_id=p.anchor AND s.source_id IS NOT NULL AND s.amount_cents>0),0) AS valid_anchor_count,
                    COALESCE(SUM(CASE WHEN p.pool_kind='expense' THEN s.kind='expense'
                        ELSE s.kind='revenue' AND s.title IN ('gift','public_operating_grant','public_investment_grant') END),0) AS eligible_count,
                    COALESCE(SUM(s.current_row=1 OR s.reference_current=1),0) AS current_count,
                    COUNT(DISTINCT s.activity_kind_id) AS activity_count,
                    COUNT(DISTINCT CASE WHEN p.pool_kind='revenue' THEN s.title END) AS title_count,
                    COUNT(DISTINCT CASE WHEN p.pool_kind='revenue' THEN s.regime END) AS regime_count,
                    COALESCE(SUM(s.amount_cents),0) AS amount_cents,
                    COALESCE(SUM(s.deductible_cents),0) AS deductible_cents
                FROM {funding_pools} p
                LEFT JOIN {funding_members} m ON m.pool_kind=p.pool_kind AND m.anchor=p.anchor
                LEFT JOIN {sources} s ON s.source_id=m.member_id
                GROUP BY p.pool_kind,p.anchor
            ) a ON a.pool_kind=p.pool_kind AND a.anchor=p.anchor", [], 'pool_kind,anchor');
        $insertPool = $this->store->prepare('INSERT INTO {resolved_pools}(pool_kind,anchor,amount_cents,deductible_cents,payload_json) VALUES(?,?,?,?,?)');
        foreach ($rows as $row) {
            $payload = json_decode($row['payload_json'], true, flags: JSON_THROW_ON_ERROR);
            NonprofitTaxRules::evidence($payload['evidence'] ?? null);
            $count = (int) $row['member_count'];
            if ($count === 0 || (int) $row['anchor_count'] !== 1 || (int) $row['valid_anchor_count'] !== 1) {
                $this->review('Financování vyžaduje jednoznačný původní kladný řádek a doložené členy včetně oprav.');
            }
            if ((int) $row['source_count'] !== $count || (int) $row['eligible_count'] !== $count) {
                NonprofitTaxRules::invalid('Fond financování nemá jednoznačný původní kladný řádek a doložené členy včetně oprav.');
            }
            if ((int) $row['current_count'] !== $count) {
                $this->review('Historický zdroj financování nemá aktuálně ověřený účetní podklad.');
            }
            if ((int) $row['activity_count'] !== 1 || ($row['pool_kind'] === 'revenue'
                && ((int) $row['title_count'] !== 1 || (int) $row['regime_count'] !== 1))) {
                $this->review('Spojení zdrojů s odlišnou činností nebo právním režimem vyžaduje samostatné posouzení.');
            }
            $insertPool->execute([$row['pool_kind'], $row['anchor'], (int) $row['amount_cents'],
                (int) $row['deductible_cents'], $row['payload_json']]);
        }
        $this->store->exec('INSERT INTO {resolved_pool_members}(pool_kind,anchor,member_id)
            SELECT pool_kind,anchor,member_id FROM {funding_members}');
    }

    private function implicitPools(bool $hasFunding): void
    {
        $negative = $this->store->scalar("SELECT 1 FROM {sources} s LEFT JOIN {funding_members} m
            ON m.pool_kind=s.kind AND m.member_id=s.source_id
            WHERE m.member_id IS NULL AND s.amount_cents<0 AND (s.kind='revenue' OR ?=1) LIMIT 1", [$hasFunding ? 1 : 0]);
        if ($negative !== false) {
            $this->review('Vrácení zdroje nebo nákladu musí mít doloženou vazbu na původní řádek financování.');
        }
        $this->store->exec("INSERT INTO {resolved_pools}(pool_kind,anchor,amount_cents,deductible_cents,payload_json)
            SELECT s.kind,s.source_id,s.amount_cents,s.deductible_cents,'{}'
            FROM {sources} s LEFT JOIN {funding_members} m ON m.pool_kind=s.kind AND m.member_id=s.source_id
            WHERE m.member_id IS NULL AND s.current_row=1 AND s.amount_cents>0
                AND (s.kind='expense' OR s.title IN ('gift','public_operating_grant','public_investment_grant'))");
        $this->store->exec('INSERT INTO {resolved_pool_members}(pool_kind,anchor,member_id)
            SELECT r.pool_kind,r.anchor,r.anchor FROM {resolved_pools} r
            WHERE NOT EXISTS(SELECT 1 FROM {funding_members} m WHERE m.pool_kind=r.pool_kind AND m.anchor=r.anchor)');
    }

    private function history(): void
    {
        $id = self::ID;
        $this->store->exec("CREATE TEMPORARY TABLE {history_pool_map} (
            assessment_id BIGINT NOT NULL,
            history_anchor $id,
            current_anchor $id,
            PRIMARY KEY(assessment_id,history_anchor,current_anchor)
        ) ENGINE=InnoDB");
        $this->store->exec("INSERT INTO {history_pool_map}(assessment_id,history_anchor,current_anchor)
            SELECT DISTINCT h.assessment_id,h.anchor,p.anchor
            FROM {funding_history} h JOIN {resolved_pools} p ON p.pool_kind='revenue'
            WHERE h.anchor=p.anchor OR EXISTS (
                SELECT 1 FROM {funding_history} hm JOIN {resolved_pool_members} pm
                    ON pm.pool_kind='revenue' AND pm.anchor=p.anchor AND pm.member_id=hm.member_id
                WHERE hm.assessment_id=h.assessment_id AND hm.anchor=h.anchor
            )");
        $bad = $this->store->scalar("SELECT 1 FROM {history_pool_map} x
            WHERE x.history_anchor<>x.current_anchor
                OR (SELECT COUNT(*) FROM {history_pool_map} y WHERE y.assessment_id=x.assessment_id AND y.history_anchor=x.history_anchor)<>1
                OR EXISTS(SELECT 1 FROM {funding_history} h WHERE h.assessment_id=x.assessment_id AND h.anchor=x.history_anchor
                    AND (h.status<>'confirmed' OR h.current_proof<>1))
                OR EXISTS(SELECT 1 FROM {funding_history} h WHERE h.assessment_id=x.assessment_id AND h.anchor=x.history_anchor
                    AND NOT EXISTS(SELECT 1 FROM {resolved_pool_members} p
                        WHERE p.pool_kind='revenue' AND p.anchor=x.current_anchor AND p.member_id=h.member_id))
            LIMIT 1");
        if ($bad !== false) $this->review('Předchozí čerpání nebo odvolané posouzení nemá průkaznou návaznost na současný zdroj financování.');
        $this->store->exec("CREATE TEMPORARY TABLE {history_drawdowns} (anchor $id PRIMARY KEY, drawdown_cents BIGINT NOT NULL) ENGINE=InnoDB");
        $this->store->exec('INSERT INTO {history_drawdowns}(anchor,drawdown_cents)
            SELECT u.anchor,SUM(u.drawdown_cents) FROM (
                SELECT h.assessment_id,h.anchor,MIN(h.drawdown_cents) AS drawdown_cents
                FROM {funding_history} h GROUP BY h.assessment_id,h.anchor
            ) u GROUP BY u.anchor');
        $this->store->exec("UPDATE {resolved_pools} p LEFT JOIN {history_drawdowns} d ON d.anchor=p.anchor
            SET p.prior_drawdown_cents=COALESCE(d.drawdown_cents,0) WHERE p.pool_kind='revenue'");
    }

    private function links(): void
    {
        $rows = $this->store->stream("SELECT l.source_id,l.cost_id,l.payload_json,r.amount_cents AS source_amount,r.prior_drawdown_cents,
                c.amount_cents AS cost_amount,c.deductible_cents,m.anchor AS cost_anchor
            FROM {funding_links} l
            LEFT JOIN {resolved_pools} r ON r.pool_kind='revenue' AND r.anchor=l.source_id
            LEFT JOIN {sources} c ON c.source_id=l.cost_id AND c.current_row=1
            LEFT JOIN {resolved_pool_members} m ON m.pool_kind='expense' AND m.member_id=l.cost_id", [], 'source_id,cost_id');
        $insert = $this->store->prepare('INSERT INTO {funding_link_eval}(source_anchor,cost_id,cost_anchor,amount_cents,overlap_cents,payload_json)
            VALUES(?,?,?,?,?,?)');
        foreach ($rows as $row) {
            $payload = json_decode($row['payload_json'], true, flags: JSON_THROW_ON_ERROR);
            NonprofitTaxRules::evidence($payload['evidence'] ?? null);
            $amount = $payload['amount_cents'] ?? null;
            if (!is_int($amount) || $amount <= 0 || $row['source_amount'] === null || $row['cost_amount'] === null
                || $row['cost_anchor'] === null || (int) $row['source_amount'] <= 0 || (int) $row['cost_amount'] <= 0) {
                NonprofitTaxRules::invalid('Vazba financování musí spojovat kladný doložený příjem a náklad.');
            }
            $allowed = (int) $row['deductible_cents'];
            $cost = (int) $row['cost_amount'];
            $overlap = $allowed === $cost ? $amount : ($allowed === 0 ? 0 : ($payload['deductible_funded_cents'] ?? null));
            if (!is_int($overlap) || $overlap < max(0, $amount + $allowed - $cost) || $overlap > min($amount, $allowed)) {
                NonprofitTaxRules::invalid('U částečně uznatelného nákladu musí být doložen překryv financované a uznatelné části.');
            }
            $insert->execute([$row['source_id'], $row['cost_id'], $row['cost_anchor'], $amount, $overlap, $row['payload_json']]);
        }
    }

    private function capacities(): void
    {
        $bad = $this->store->scalar("SELECT 1 FROM (
                SELECT e.source_anchor AS anchor
                FROM {funding_link_eval} e JOIN {resolved_pools} p ON p.pool_kind='revenue' AND p.anchor=e.source_anchor
                GROUP BY e.source_anchor,p.amount_cents,p.prior_drawdown_cents
                HAVING SUM(e.amount_cents)>GREATEST(0,p.amount_cents-p.prior_drawdown_cents)
                UNION ALL
                SELECT e.cost_id
                FROM {funding_link_eval} e JOIN {sources} s ON s.source_id=e.cost_id
                GROUP BY e.cost_id,s.amount_cents HAVING SUM(e.amount_cents)>s.amount_cents
                UNION ALL
                SELECT e.cost_anchor
                FROM {funding_link_eval} e JOIN {resolved_pools} p ON p.pool_kind='expense' AND p.anchor=e.cost_anchor
                GROUP BY e.cost_anchor,p.amount_cents HAVING SUM(e.amount_cents)>GREATEST(0,p.amount_cents)
            ) overdrawn LIMIT 1");
        if ($bad !== false) NonprofitTaxRules::invalid('Čerpání překračuje čistý zdroj po vrácení a předchozím čerpání nebo čistý náklad po opravách.');
    }

    /** Financovaná a z testu vyloučená část nákladu podle aktuálního režimu zdroje. */
    private function materializeExclusions(string $table): void
    {
        $id = self::ID;
        $this->store->dropTable($table);
        $this->store->exec("CREATE TEMPORARY TABLE {" . $table . "} (cost_id $id PRIMARY KEY, excluded_cents BIGINT NOT NULL) ENGINE=InnoDB");
        $this->store->exec("INSERT INTO {" . $table . "}(cost_id,excluded_cents)
            SELECT e.cost_id,SUM(e.overlap_cents)
            FROM {funding_link_eval} e JOIN {sources} src ON src.source_id=e.source_anchor
            WHERE src.regime IN ('exempt','out_of_scope') GROUP BY e.cost_id");
    }

    private function resolveActivity(string $activity): int
    {
        $this->materializeExclusions('activity_exclusions');
        $stmt = $this->store->prepare("SELECT s.group_id,s.cohort,
                SUM(CASE WHEN s.kind='revenue' THEN s.amount_cents ELSE 0 END) AS revenue_cents,
                SUM(CASE WHEN s.kind='expense' THEN s.deductible_cents-COALESCE(x.excluded_cents,0) ELSE 0 END) AS cost_cents
            FROM {sources} s LEFT JOIN {activity_exclusions} x ON x.cost_id=s.source_id
            WHERE s.current_row=1 AND s.activity_kind_id=? AND s.regime='pending'
            GROUP BY s.group_id,s.cohort ORDER BY s.group_id");
        $stmt->execute([$activity]);
        $groups = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        $positive = $nonpositive = false;
        foreach ($groups as $group) {
            if ($group['cohort'] !== '') {
                (int) $group['revenue_cents'] > (int) $group['cost_cents'] ? $positive = true : $nonpositive = true;
            }
        }
        $mixed = $positive && $nonpositive;
        $insert = $this->store->prepare('INSERT INTO {activity_group_results}(group_id,activity_kind_id,cohort,revenue_cents,cost_cents,regime,contribution_cents)
            VALUES(?,?,?,?,?,?,?)');
        $update = $this->store->prepare("UPDATE {sources} SET regime=?,method=? WHERE current_row=1 AND kind='revenue' AND group_id=? AND regime='pending'");
        $count = 0;
        foreach ($groups as $group) {
            $revenue = (int) $group['revenue_cents'];
            $cost = (int) $group['cost_cents'];
            $out = $mixed ? $revenue <= $cost : $revenue < $cost;
            $regime = $out ? 'out_of_scope' : 'subject';
            $insert->execute([$group['group_id'], $activity, $group['cohort'], $revenue, $cost, $regime, $out ? 0 : $revenue - $cost]);
            $update->execute([$regime, $regime === 'subject' ? 'return' : 'none', $group['group_id']]);
            ++$count;
        }
        return $count;
    }

    private function resolveConditionalCycle(): int
    {
        $id = self::ID;
        $this->store->exec("CREATE TEMPORARY TABLE {cycle_groups} (
            group_id {$this->label()} PRIMARY KEY,
            bit INT NOT NULL CHECK(bit>=0),
            UNIQUE KEY uq_j6_cycle_bit(bit)
        ) ENGINE=InnoDB");
        $this->store->exec("INSERT INTO {cycle_groups}(group_id,bit)
            SELECT d.group_id,ROW_NUMBER() OVER(ORDER BY d.group_id)-1 FROM (
                SELECT DISTINCT src.group_id FROM {funding_link_eval} e
                JOIN {sources} src ON src.source_id=e.source_anchor
                WHERE src.current_row=1 AND src.kind='revenue' AND src.regime='pending'
            ) d");
        $this->store->exec("CREATE TEMPORARY TABLE {cycle_revenues} (source_id $id PRIMARY KEY, group_id {$this->label()}) ENGINE=InnoDB");
        $this->store->exec("INSERT INTO {cycle_revenues}(source_id,group_id)
            SELECT s.source_id,s.group_id FROM {sources} s JOIN {cycle_groups} g ON g.group_id=s.group_id
            WHERE s.current_row=1 AND s.kind='revenue' AND s.regime='pending'");
        $this->store->exec($this->groupResultSchema('cycle_solution'));
        $count = (int) $this->store->scalar('SELECT COUNT(*) FROM {cycle_groups}');
        if ($count === 0 || $count > 10) $this->cycleReview();
        $keys = [];
        $groups = $this->store->prepare('SELECT group_id,bit FROM {cycle_groups} ORDER BY bit');
        $groups->execute();
        foreach ($groups->fetchAll(\PDO::FETCH_ASSOC) as $group) $keys[(int) $group['bit']] = $group['group_id'];
        $groups->closeCursor();
        $reset = $this->store->prepare("UPDATE {sources} s JOIN {cycle_revenues} c ON c.source_id=s.source_id
            SET s.regime='pending',s.method='return'");
        $assume = $this->store->prepare("UPDATE {sources} SET regime=?,method=? WHERE current_row=1 AND kind='revenue' AND group_id=?");
        $solution = false;
        $uncertain = false;
        for ($state = 0; $state < (1 << $count); ++$state) {
            $reset->execute();
            $assumed = [];
            foreach ($keys as $bit => $groupId) {
                $regime = ($state & (1 << $bit)) !== 0 ? 'out_of_scope' : 'subject';
                $assume->execute([$regime, $regime === 'subject' ? 'return' : 'none', $groupId]);
                $assumed[$groupId] = $regime;
            }
            try {
                $this->resolvedFunding();
            } catch (NonprofitTaxAssessmentException) {
                $uncertain = true;
                continue;
            }
            $this->candidateGroupResults();
            $lookup = $this->store->prepare('SELECT regime FROM {candidate_group_results} WHERE group_id=?');
            $matches = true;
            foreach ($assumed as $groupId => $regime) {
                $lookup->execute([$groupId]);
                $actual = $lookup->fetchColumn();
                $lookup->closeCursor();
                if ($actual !== $regime) {
                    $matches = false;
                    break;
                }
            }
            if (!$matches) continue;
            if ($solution) $this->cycleReview();
            $this->store->exec('DELETE FROM {cycle_solution}');
            $this->store->exec('INSERT INTO {cycle_solution} SELECT * FROM {candidate_group_results}');
            $solution = true;
        }
        if (!$solution || $uncertain) $this->cycleReview();
        $reset->execute();
        $this->store->exec('INSERT INTO {activity_group_results} SELECT * FROM {cycle_solution}');
        $this->store->exec("UPDATE {sources} s JOIN {cycle_solution} c ON c.group_id=s.group_id
            SET s.regime=c.regime,s.method=CASE c.regime WHEN 'subject' THEN 'return' ELSE 'none' END
            WHERE s.current_row=1 AND s.kind='revenue' AND s.regime='pending'");
        $this->store->exec('UPDATE {activity_nodes} n JOIN (SELECT DISTINCT activity_kind_id FROM {cycle_solution}) c
            ON c.activity_kind_id=n.activity_kind_id SET n.resolved=1');
        $this->resolvedFunding();
        return (int) $this->store->scalar('SELECT COUNT(*) FROM {cycle_solution}');
    }

    private function groupResultSchema(string $table): string
    {
        $id = self::ID;
        return "CREATE TEMPORARY TABLE {" . $table . "} (
            group_id {$this->label()} PRIMARY KEY,
            activity_kind_id $id,
            cohort {$this->label()},
            revenue_cents BIGINT NOT NULL,
            cost_cents BIGINT NOT NULL,
            regime VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            contribution_cents BIGINT NOT NULL
        ) ENGINE=InnoDB";
    }

    private function candidateGroupResults(): void
    {
        $id = self::ID;
        $this->store->dropTable('candidate_group_results');
        $this->store->dropTable('candidate_group_totals');
        $this->store->dropTable('candidate_activity_mix');
        $this->materializeExclusions('candidate_exclusions');
        $this->store->exec("CREATE TEMPORARY TABLE {candidate_group_totals} (
            group_id {$this->label()} PRIMARY KEY,
            activity_kind_id $id,
            cohort {$this->label()},
            revenue_cents BIGINT NOT NULL,
            cost_cents BIGINT NOT NULL,
            KEY ix_j6_candidate_activity(activity_kind_id)
        ) ENGINE=InnoDB");
        $this->store->exec("INSERT INTO {candidate_group_totals}(group_id,activity_kind_id,cohort,revenue_cents,cost_cents)
            SELECT s.group_id,s.activity_kind_id,s.cohort,
                SUM(CASE WHEN s.kind='revenue' THEN s.amount_cents ELSE 0 END),
                SUM(CASE WHEN s.kind='expense' THEN s.deductible_cents-COALESCE(x.excluded_cents,0) ELSE 0 END)
            FROM {sources} s LEFT JOIN {candidate_exclusions} x ON x.cost_id=s.source_id
            WHERE s.current_row=1 AND EXISTS(SELECT 1 FROM {cycle_groups} c WHERE c.group_id=s.group_id)
            GROUP BY s.group_id,s.activity_kind_id,s.cohort");
        $this->store->exec("CREATE TEMPORARY TABLE {candidate_activity_mix} (
            activity_kind_id $id PRIMARY KEY,
            positive TINYINT NOT NULL,
            nonpositive TINYINT NOT NULL
        ) ENGINE=InnoDB");
        $this->store->exec("INSERT INTO {candidate_activity_mix}(activity_kind_id,positive,nonpositive)
            SELECT activity_kind_id,
                MAX(CASE WHEN cohort<>'' AND revenue_cents>cost_cents THEN 1 ELSE 0 END),
                MAX(CASE WHEN cohort<>'' AND revenue_cents<=cost_cents THEN 1 ELSE 0 END)
            FROM {candidate_group_totals} GROUP BY activity_kind_id");
        $this->store->exec($this->groupResultSchema('candidate_group_results'));
        $this->store->exec("INSERT INTO {candidate_group_results}(group_id,activity_kind_id,cohort,revenue_cents,cost_cents,regime,contribution_cents)
            SELECT g.group_id,g.activity_kind_id,g.cohort,g.revenue_cents,g.cost_cents,
                CASE WHEN (m.positive=1 AND m.nonpositive=1 AND g.revenue_cents<=g.cost_cents)
                        OR (NOT(m.positive=1 AND m.nonpositive=1) AND g.revenue_cents<g.cost_cents)
                    THEN 'out_of_scope' ELSE 'subject' END,
                CASE WHEN (m.positive=1 AND m.nonpositive=1 AND g.revenue_cents<=g.cost_cents)
                        OR (NOT(m.positive=1 AND m.nonpositive=1) AND g.revenue_cents<g.cost_cents)
                    THEN 0 ELSE g.revenue_cents-g.cost_cents END
            FROM {candidate_group_totals} g JOIN {candidate_activity_mix} m ON m.activity_kind_id=g.activity_kind_id");
    }

    private function resolvedFunding(): void
    {
        $costs = $this->store->stream("SELECT s.source_id,s.amount_cents,s.deductible_cents,
                COALESCE(SUM(CASE WHEN src.regime IN ('exempt','out_of_scope') THEN e.amount_cents ELSE 0 END),0) AS exempt_total,
                COALESCE(SUM(CASE WHEN src.regime IN ('exempt','out_of_scope') THEN e.overlap_cents ELSE 0 END),0) AS excluded_total
            FROM {sources} s JOIN {funding_link_eval} e ON e.cost_id=s.source_id
            JOIN {sources} src ON src.source_id=e.source_anchor GROUP BY s.source_id,s.amount_cents,s.deductible_cents", [], 'source_id');
        foreach ($costs as $row) NonprofitTaxRules::cumulativeFundingOverlap(
            (int) $row['exempt_total'], (int) $row['excluded_total'], (int) $row['amount_cents'], (int) $row['deductible_cents']);
        $pools = $this->store->stream("SELECT p.anchor,p.amount_cents,p.deductible_cents,
                COALESCE(SUM(CASE WHEN src.regime IN ('exempt','out_of_scope') THEN e.amount_cents ELSE 0 END),0) AS exempt_total,
                COALESCE(SUM(CASE WHEN src.regime IN ('exempt','out_of_scope') THEN e.overlap_cents ELSE 0 END),0) AS excluded_total
            FROM {resolved_pools} p JOIN {funding_link_eval} e ON e.cost_anchor=p.anchor
            JOIN {sources} src ON src.source_id=e.source_anchor WHERE p.pool_kind='expense'
            GROUP BY p.anchor,p.amount_cents,p.deductible_cents", [], 'anchor');
        foreach ($pools as $row) NonprofitTaxRules::cumulativeFundingOverlap(
            (int) $row['exempt_total'], (int) $row['excluded_total'], (int) $row['amount_cents'], (int) $row['deductible_cents']);
        $negative = $this->store->stream("SELECT p.anchor,p.payload_json,p.prior_drawdown_cents,p.amount_cents FROM {resolved_pools} p
            WHERE p.pool_kind='revenue' AND p.amount_cents-p.prior_drawdown_cents<0", [], 'anchor');
        foreach ($negative as $row) {
            $payload = json_decode($row['payload_json'], true, flags: JSON_THROW_ON_ERROR);
            $review = $payload['prior_period_review'] ?? null;
            if (!is_array($review) || !is_int($review['adjustment_cents'] ?? null)) {
                $this->review('Vrácení zdroje zasahuje do dříve potvrzeného čerpání. Je nutné doložit samostatné daňové posouzení předchozích období.');
            }
            NonprofitTaxRules::evidence($review['evidence'] ?? null);
            NonprofitTaxRules::evidence($review['legal_basis'] ?? null);
        }
    }

    private function cycleReview(): never
    {
        $this->review('Kruhová závislost financování a ročního testu nemá doložené jediné řešení a vyžaduje samostatné posouzení.');
    }

    private function review(string $message): never
    {
        throw new NonprofitTaxAssessmentException('nonprofit_tax_review_required', $message);
    }
}
