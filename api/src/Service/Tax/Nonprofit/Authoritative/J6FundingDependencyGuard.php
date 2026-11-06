<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\NonprofitTaxPagedAssessmentRepository;
use MyInvoice\Service\Tax\Nonprofit\J6FundingProjection;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;

final readonly class J6FundingDependencyGuard
{
    private const array FUNDED_TITLES = ['gift', 'public_operating_grant', 'public_investment_grant'];

    public function __construct(
        private Connection $db,
        private NonprofitTaxPagedAssessmentRepository $assessments,
        private J6FundingProjection $funding,
    ) {}

    public function assertNoLaterUsage(int $supplierId, int $periodId, array $assessment): void
    {
        if (($assessment['supplier_id'] ?? null) !== $supplierId || ($assessment['period_id'] ?? null) !== $periodId
            || ($assessment['status'] ?? null) !== 'confirmed') {
            throw new \InvalidArgumentException('J6 funding dependency identity is invalid.');
        }
        $this->assertRevisionsUnused($supplierId, $periodId, [$assessment]);
    }

    /**
     * Zdroje z revizí období nesmí čerpat potvrzené posouzení pozdějšího období.
     * Revize může být legacy, stránkovaná i dosud neuložený výsledek ve tvaru legacy
     * (['result' => …]); legacy i stránkovaná pozdější období se čtou přes J6FundingProjection.
     *
     * @param list<array> $revisions
     */
    public function assertRevisionsUnused(int $supplierId, int $periodId, array $revisions): void
    {
        if ($revisions === []) return;
        $bounds = $this->assessments->periodBounds($supplierId, $periodId, true);
        $store = new J6WorkingStore($this->db->pdo());
        try {
            $store->exec('CREATE TEMPORARY TABLE {target_members}
                (member_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY) ENGINE=InnoDB');
            $store->exec('CREATE TEMPORARY TABLE {candidate_members}
                (member_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY) ENGINE=InnoDB');
            $target = $store->prepare('INSERT IGNORE INTO {target_members}(member_id) VALUES(?)');
            foreach ($revisions as $revision) {
                foreach ($this->funding->resultPages($revision) as $rows) {
                    foreach ($rows as $row) {
                        if (($row['kind'] ?? null) === 'revenue'
                            && in_array($row['decision']['title'] ?? null, self::FUNDED_TITLES, true)) {
                            $this->member($target, $row['id'] ?? null);
                        }
                    }
                }
                foreach ($this->funding->usagePages($revision) as $rows) {
                    foreach ($rows as $row) {
                        $this->member($target, $row['anchor'] ?? null);
                        $this->member($target, $row['member_id'] ?? null);
                    }
                }
            }
            if ((int) $store->scalar('SELECT COUNT(*) FROM {target_members}') === 0) return;

            $afterStartsOn = '';
            $afterPeriodId = 0;
            do {
                $later = $this->assessments->effectiveAfterPage($supplierId, $periodId, $bounds['ends_on'],
                    $afterStartsOn, $afterPeriodId, 100, true);
                foreach ($later as $revision) {
                    $afterStartsOn = $revision['period_starts_on'];
                    $afterPeriodId = $revision['period_id'];
                    if ($revision['status'] !== 'confirmed') continue;
                    $store->exec('DELETE FROM {candidate_members}');
                    $candidate = $store->prepare('INSERT IGNORE INTO {candidate_members}(member_id) VALUES(?)');
                    foreach ($this->funding->usagePages($revision) as $rows) {
                        foreach ($rows as $row) {
                            $this->member($candidate, $row['anchor'] ?? null);
                            $this->member($candidate, $row['member_id'] ?? null);
                        }
                        if ($this->overlaps($store)) $this->review();
                    }
                }
            } while (count($later) === 100);
        } finally {
            unset($candidate, $target);
            $store->closeAndDelete();
        }
    }
    private function member(\PDOStatement $insert, mixed $member): void
    {
        if (!is_string($member) || $member === '') throw new \UnexpectedValueException('J6 funding member identity is invalid.');
        $insert->execute([$member]);
    }

    private function overlaps(J6WorkingStore $store): bool
    {
        return $store->scalar('SELECT 1 FROM {target_members} t JOIN {candidate_members} c ON c.member_id=t.member_id LIMIT 1') !== false;
    }

    private function review(): never
    {
        throw new NonprofitTaxAssessmentException('nonprofit_tax_review_required',
            'Na zdroj navazuje potvrzené čerpání v pozdějším období. Nejprve je nutné doloženě přehodnotit navazující posouzení.', 409);
    }
}
