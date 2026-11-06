<?php

declare(strict_types=1);
namespace MyInvoice\Service\Accounting\Catalog;

final readonly class AccountingContext implements \JsonSerializable
{
    public function __construct(
        public string $date,
        public array $framework,
        public array $method,
        public array $accounts,
        public array $accountRoles,
        public array $systemDimensions,
        public array $findings,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'date'=>$this->date,
            'temporal_basis'=>'current_knowledge_effective_date',
            'consistency'=>'transaction_snapshot',
            'complete'=>$this->findings===[],
            'findings'=>$this->findings,
            'framework'=>$this->framework,
            'method'=>$this->method,
            'accounts'=>$this->accounts,
            'role_profile'=>[
                'basis'=>'dated_framework_current_role_catalog',
                'historical_rule_versions_available'=>false,
                'legal_profile_used'=>false,
                'property_flags_used'=>false,
                'legal_basis_assessed'=>false,
            ],
            'account_roles'=>$this->accountRoles,
            'system_dimensions'=>$this->systemDimensions,
        ];
    }
}
