<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Compatibility;

final readonly class CompatibilityResult
{
    /** @var list<CompatibilityIssue> */
    public array $issues;

    /** @param array<mixed> $issues */
    public function __construct(
        public ?string $profile,
        array $issues,
    ) {
        $validated = [];
        foreach ($issues as $issue) {
            if (!$issue instanceof CompatibilityIssue) {
                throw new \InvalidArgumentException('Výsledek kompatibility obsahuje neplatnou chybu.');
            }
            $validated[] = $issue;
        }
        $this->issues = $validated;
    }

    public function isCompatible(): bool
    {
        return $this->profile !== null && $this->issues === [];
    }

    /** @return array{compatible:bool,profile:?string,issues:list<array{code:string,field:string,message:string}>} */
    public function toArray(): array
    {
        return [
            'compatible' => $this->isCompatible(),
            'profile' => $this->profile,
            'issues' => array_map(
                static fn (CompatibilityIssue $issue): array => $issue->toArray(),
                $this->issues,
            ),
        ];
    }
}
