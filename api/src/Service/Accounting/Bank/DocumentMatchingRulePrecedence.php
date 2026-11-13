<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankPostingRuleRepository;
use MyInvoice\Repository\BankPostingSuggestionRepository;

/**
 * Přednost pravidla účtování před automatickým párováním s doklady
 * (`bank_posting_rules.skip_document_matching`).
 *
 * Faktoringová společnost, inkasní agentura nebo leasing platí s VS naší faktury,
 * peníze ale nejsou úhradou od odběratele. Pravidlo s předností proto vyřadí pohyb
 * z automatického párování s vystavenými i přijatými doklady (a tím i s ostatními
 * položkami a z návrhů párování) a pohyb jde nespárovanou cestou k pravidlu, které
 * ho zaúčtuje nebo navrhne podle svého režimu. Ruční spárování uživatelem zůstává
 * možné, výslovná volba člověka má přednost.
 *
 * Jediné místo rozhodnutí „vyřadí tenhle pohyb z párování nějaké pravidlo?". Shodu
 * posuzuje {@see BankRuleMatcher} se všemi podmínkami pravidla (směr, měna, vlastní
 * účet, protistrana, částka, karta). Pravidlo, jehož návrh uživatel u pohybu odmítl,
 * pohyb z párování nevyřadí: pro ten pohyb zjevně neplatí.
 */
final class DocumentMatchingRulePrecedence
{
    /** Důvod nespárování u pohybu, který si vzalo pravidlo s předností. */
    public const REASON = 'rule_skips_matching';

    /** @var array<int,list<array<string,mixed>>>|null pravidla firem po dobu dávky, null = mimo dávku */
    private ?array $batch = null;

    public function __construct(
        private readonly Connection $db,
        private readonly BankPostingRuleRepository $rules,
        private readonly BankRuleMatcher $matcher,
        private readonly BankPostingSuggestionRepository $suggestions,
    ) {}

    /**
     * Dávka párování: pravidla každé firmy se načtou jednou pro celou dávku. Mimo dávku
     * se čtou při každém dotazu, aby dlouho běžící proces neviděl zastaralá pravidla.
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function withinBatch(callable $work): mixed
    {
        $outer = $this->batch;
        $this->batch ??= [];
        try {
            return $work();
        } finally {
            $this->batch = $outer;
        }
    }

    /**
     * Pravidlo, které pohyb vyřazuje z automatického párování s doklady, nebo null.
     *
     * @param array<string,mixed> $tx řádek pohybu s `recipient_account`/`recipient_bank`
     *        a `statement_currency` ({@see \MyInvoice\Service\Bank\BankTransactionContext})
     * @return array<string,mixed>|null
     */
    public function ruleFor(int $supplierId, array $tx): ?array
    {
        $amount = (float) ($tx['amount'] ?? 0);
        if ($supplierId <= 0 || $amount === 0.0) {
            return null;
        }
        $direction = $amount > 0 ? 'incoming' : 'outgoing';
        $currency = strtoupper(self::currency($tx));
        $candidates = array_values(array_filter(
            $this->rulesOf($supplierId),
            static fn (array $rule): bool => (string) $rule['direction'] === $direction
                && strtoupper((string) ($rule['applies_currency'] ?? 'CZK')) === $currency,
        ));
        if ($candidates === []) {
            return null;
        }
        $match = (new StatementOwnAccount($this->db))->withOwnAccount($supplierId, BankRuleMatcher::txFields($tx), $tx);
        $txId = (int) ($tx['id'] ?? 0);
        foreach ($candidates as $rule) {
            if ($this->matcher->matching($rule, $match)
                && ($txId <= 0 || !$this->suggestions->hasRejected($supplierId, $txId, (int) $rule['id']))) {
                return $rule;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $tx */
    public function skipsDocumentMatching(int $supplierId, array $tx): bool
    {
        return $this->ruleFor($supplierId, $tx) !== null;
    }

    /** @return list<array<string,mixed>> */
    private function rulesOf(int $supplierId): array
    {
        if ($this->batch === null) {
            return $this->rules->findActiveSkippingDocumentMatching($supplierId);
        }
        return $this->batch[$supplierId] ??= $this->rules->findActiveSkippingDocumentMatching($supplierId);
    }

    /** Měna pohybu jako u účtování: měna pohybu, jinak měna výpisu, jinak CZK. */
    private static function currency(array $tx): string
    {
        $c = $tx['currency'] ?? $tx['statement_currency'] ?? null;
        return is_string($c) && $c !== '' ? $c : 'CZK';
    }
}
