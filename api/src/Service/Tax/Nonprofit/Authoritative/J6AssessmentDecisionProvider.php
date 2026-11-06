<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

interface J6AssessmentDecisionProvider
{
    public function context(): J6DecisionContext;

    public function header(): array;

    /** @return iterable<list<array<string,mixed>>> */
    public function decisionPages(int $limit = 500): iterable;

    /** @return iterable<list<array<string,mixed>>> */
    public function activityPages(int $limit = 500): iterable;

    /** @return iterable<list<array<string,mixed>>> */
    public function fundingPoolPages(int $limit = 500): iterable;

    /** @return iterable<list<array{pool_kind:string,anchor:string,member_id:string}>> */
    public function fundingMemberPages(int $limit = 500): iterable;

    /** @return iterable<list<array<string,mixed>>> */
    public function fundingLinkPages(int $limit = 500): iterable;
}
