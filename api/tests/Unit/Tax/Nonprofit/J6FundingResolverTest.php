<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Nonprofit;

use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6FundingResolver;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6WorkingStore;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Tests\Support\J6CalculatorFactory;
use PHPUnit\Framework\TestCase;

final class J6FundingResolverTest extends TestCase
{
    private J6WorkingStore $store;

    protected function setUp(): void
    {
        $this->store = new J6WorkingStore(J6CalculatorFactory::connection()->pdo());
    }

    protected function tearDown(): void
    {
        if (isset($this->store)) $this->store->closeAndDelete();
    }

    public function testNetPoolAndIndexedLinkStayExactWithoutMemberArray(): void
    {
        $this->source('grant', 'revenue', 10000, 0, 'public_operating_grant', 'out_of_scope');
        $this->source('refund', 'revenue', -4000, 0, 'public_operating_grant', 'out_of_scope');
        $this->source('cost', 'expense', 6000, 6000, 'cost', 'subject');
        $hash = hash('sha256', 'synthetic');
        $this->store->appendPool('revenue', 'grant', ['evidence' => 'Synthetic refund pool'], $hash);
        $this->store->appendMember('revenue', 'grant', 'grant');
        $this->store->appendMember('revenue', 'grant', 'refund');
        $this->store->appendLink('grant', 'cost', ['amount_cents' => 6000, 'evidence' => 'Synthetic drawdown'], $hash);

        (new J6FundingResolver($this->store))->prepare(true);

        $pool = $this->store->prepare("SELECT amount_cents,prior_drawdown_cents FROM {resolved_pools} WHERE pool_kind='revenue' AND anchor='grant'");
        $pool->execute();
        self::assertSame(['amount_cents' => 6000, 'prior_drawdown_cents' => 0], array_map('intval', $pool->fetch(\PDO::FETCH_ASSOC)));
        $pool->closeCursor();
        self::assertSame(6000, (int) $this->store->scalar('SELECT amount_cents FROM {funding_link_eval}'));
    }

    public function testNetPoolCapacityRejectsOneCentOverdraw(): void
    {
        $this->source('grant', 'revenue', 10000, 0, 'public_operating_grant', 'out_of_scope');
        $this->source('refund', 'revenue', -4000, 0, 'public_operating_grant', 'out_of_scope');
        $this->source('cost', 'expense', 7000, 7000, 'cost', 'subject');
        $hash = hash('sha256', 'synthetic');
        $this->store->appendPool('revenue', 'grant', ['evidence' => 'Synthetic refund pool'], $hash);
        $this->store->appendMember('revenue', 'grant', 'grant');
        $this->store->appendMember('revenue', 'grant', 'refund');
        $this->store->appendLink('grant', 'cost', ['amount_cents' => 6001, 'evidence' => 'Synthetic overdraw'], $hash);

        $this->expectException(NonprofitTaxAssessmentException::class);
        (new J6FundingResolver($this->store))->prepare(true);
    }

    private function source(string $id, string $kind, int $amount, int $deductible, string $title, string $regime): void
    {
        $this->store->appendSource(['id' => $id, 'kind' => $kind, 'amount_cents' => $amount,
            'deductible_cents' => $deductible, 'activity_kind_id' => 1, 'group' => '1:',
            'classification' => ['regime' => $regime, 'method' => $kind === 'revenue' ? 'none' : 'cost'],
            'decision' => ['title' => $title]], true);
    }
}
