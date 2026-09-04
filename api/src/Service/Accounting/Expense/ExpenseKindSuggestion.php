<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Expense;

/**
 * Návrh klasifikace řádku + PROČ. Důvod není kosmetika — bez něj uživatel nemá jak
 * poznat, jestli se návrhu dá věřit, a v editoru se pak klika naslepo (§DM/UX).
 */
final class ExpenseKindSuggestion
{
    public function __construct(
        public readonly ExpenseKind $kind,
        public readonly float $confidence,
        public readonly string $reason,
        public readonly string $source, // 'rule' | 'catalog' | 'keyword' | 'threshold' | 'ai'
        /**
         * Účet nákladu, když ho pravidlo určuje adresně; NULL = odvodí se z druhu.
         *
         * Druh výdaje a účet jsou dvě různé osy: pojistné je druhem SLUŽBA, ale vyhláška
         * 500/2002 ho řadí na 548 (F.5. Jiné provozní náklady), ne na 518 (A.3. Služby).
         */
        public readonly ?string $accountCode = null,
        /**
         * Řádek nese znaky OSOBNÍHO / daňově NEUZNATELNÉHO výdaje (§25 ZDP) — typicky optika,
         * dioptrické brýle. Jen SIGNÁL ke kontrole: účet se tím NEMĚNÍ (nedaňový účet 528/513 je
         * rozhodnutí účetní jednotky, ne pure klasifikátoru — viz seed pravidel), ale editor a
         * DPPO návrh (ř.40) ho podle něj zvýrazní. Nikdy neúčtuje sám (confidence zůstává WEAK).
         */
        public readonly bool $nonDeductible = false,
    ) {
    }

    /** Smí se použít bez potvrzení uživatele? Slabý důkaz nesmí sám zaúčtovat doklad. */
    public function isAutoApplicable(): bool
    {
        return $this->confidence >= ExpenseKindClassifier::AUTO_THRESHOLD;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'expense_kind' => $this->kind->value,
            'expense_account_code' => $this->accountCode,
            'confidence' => round($this->confidence, 2),
            'reason' => $this->reason,
            'source' => $this->source,
            'non_deductible' => $this->nonDeductible,
            'auto' => $this->isAutoApplicable(),
        ];
    }
}
