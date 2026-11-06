<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Revenue;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\RevenueClassificationRuleRepository;
use MyInvoice\Service\Accounting\Bank\BankMessageNormalizer;

/**
 * Pravidla výnosů (migrace 2217): účet položky vydané faktury podle kódu položky,
 * textu nebo sazby DPH.
 *
 * Jediné místo vyhodnocení pro import ({@see \MyInvoice\Service\Import\InvoiceImportService})
 * i zaúčtování ({@see \MyInvoice\Service\Accounting\PostingService}), aby import a
 * Doúčtovat doklady rozhodly o téže položce stejně. Kontrakt kritérií je shodný
 * s pravidly nákladů: AND přes vyplněná kritéria, priorita ASC, první shoda vyhrává,
 * text se porovnává po normalizaci {@see BankMessageNormalizer::normalizeKeepDigits()}.
 *
 * Pravidla tenanta se načtou jednou na instanci, vyhodnocení dávky položek je pak čistě
 * v paměti: Doúčtovat tisíce faktur neudělá dotaz na pravidla za každou položku.
 */
final class RevenueRuleMatcher
{
    /** @var array<int,list<array<string,mixed>>> */
    private array $rulesCache = [];

    public function __construct(private readonly Connection $db) {}

    /**
     * Účet pro každou položku, na kterou sedí některé pravidlo.
     *
     * @param array<int|string,array{seller_item_code?:?string, description?:?string, vat_rate_snapshot?:mixed}> $items
     * @return array<int|string,array{account:string, rule_id:int}> klíč položky => účet a pravidlo
     */
    public function match(int $supplierId, array $items): array
    {
        if ($items === []) {
            return [];
        }
        $rules = $this->rules($supplierId);
        if ($rules === []) {
            return [];
        }
        $out = [];
        foreach ($items as $key => $item) {
            $rule = self::firstMatch(
                $rules,
                isset($item['seller_item_code']) ? (string) $item['seller_item_code'] : null,
                (string) ($item['description'] ?? ''),
                isset($item['vat_rate_snapshot']) ? (float) $item['vat_rate_snapshot'] : null,
            );
            if ($rule !== null) {
                $out[$key] = ['account' => (string) $rule['target_account_code'], 'rule_id' => (int) $rule['id']];
            }
        }
        return $out;
    }

    /**
     * Čistá funkce nad seřazenými pravidly, bez DB.
     *
     * @param list<array<string,mixed>> $rules aktivní pravidla v pořadí priority
     * @return array<string,mixed>|null
     */
    public static function firstMatch(array $rules, ?string $itemCode, string $description, ?float $vatRate): ?array
    {
        $code = mb_strtolower(trim((string) $itemCode));
        $text = null;
        foreach ($rules as $rule) {
            if (!($rule['is_active'] ?? true)) {
                continue;
            }
            $matched = 0;

            $ruleCode = trim((string) ($rule['item_code'] ?? ''));
            if ($ruleCode !== '') {
                if ($code === '' || mb_strtolower($ruleCode) !== $code) {
                    continue;
                }
                $matched++;
            }

            $fragment = trim((string) ($rule['description_contains'] ?? ''));
            if ($fragment !== '') {
                $text ??= BankMessageNormalizer::normalizeKeepDigits($description);
                if (!str_contains($text, BankMessageNormalizer::normalizeKeepDigits($fragment))) {
                    continue;
                }
                $matched++;
            }

            if (($rule['vat_rate'] ?? null) !== null) {
                if ($vatRate === null || abs($vatRate - (float) $rule['vat_rate']) > 0.005) {
                    continue;
                }
                $matched++;
            }

            if ($matched > 0) {
                return $rule;
            }
        }
        return null;
    }

    /** @return list<array<string,mixed>> */
    private function rules(int $supplierId): array
    {
        return $this->rulesCache[$supplierId] ??= (new RevenueClassificationRuleRepository($this->db))->activeFor($supplierId);
    }
}
