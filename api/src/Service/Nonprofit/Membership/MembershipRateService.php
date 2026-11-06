<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRateRepository;
use MyInvoice\Service\Accounting\JournalLineAmount;
use PDO;

final class MembershipRateService
{
    public function __construct(private readonly Connection $db) {}

    public function save(int $supplier, ?int $id, array $input): array
    {
        $fields = ['name', 'amount', 'receivable_account', 'revenue_account', 'title_key', 'period_months', 'title_reference', 'evidence', 'classification', 'is_active', 'row_version'];
        if (array_diff(array_keys($input), $fields) !== [] || array_diff($fields, array_keys($input)) !== []) self::invalid();
        foreach (['name' => 160, 'receivable_account' => 20, 'revenue_account' => 20, 'title_reference' => 2000, 'evidence' => 2000] as $field => $max) {
            $input[$field] = MembershipChargeRequest::text($input[$field], $field, $max);
        }
        $input['title_key'] = MembershipChargeRequest::key($input['title_key'], 'titulu');
        if (!is_int($input['period_months']) || !in_array($input['period_months'], [0, 1, 3, 6, 12], true)
            || !is_bool($input['is_active']) || !is_int($input['row_version']) || $input['row_version'] < 0
            || ($id === null ? $input['row_version'] !== 0 : $input['row_version'] < 1)) self::invalid();
        if (!is_string($input['amount']) && !is_int($input['amount']) && !is_float($input['amount'])) self::invalid();
        try {
            $input['amount'] = JournalLineAmount::decimal($input['amount']);
            $cents = JournalLineAmount::cents($input['amount']);
        }
        catch (\InvalidArgumentException) { self::invalid(); }
        if ($cents <= 0 || $cents > 999999999999999) self::invalid();
        // U šablony ověřujeme vlastnictví a typ účtů; platnost k účetnímu datu a roli znovu ověří náhled předpisu.
        $q = $this->db->pdo()->prepare('SELECT account_code,account_type FROM chart_of_accounts WHERE supplier_id=? AND account_code IN (?,?)');
        $q->execute([$supplier, $input['receivable_account'], $input['revenue_account']]);
        $accounts = $q->fetchAll(PDO::FETCH_KEY_PAIR);
        if (($accounts[$input['receivable_account']] ?? null) !== 'asset' || ($accounts[$input['revenue_account']] ?? null) !== 'revenue'
            || !str_starts_with($input['revenue_account'], '684')) self::invalid();
        if ($input['classification'] !== null) {
            if (!is_array($input['classification'])) self::invalid();
            (new MembershipClassification($this->db))->resolve($supplier, $input['classification']);
        }
        $input['is_active'] = (int) $input['is_active'];
        return (new MembershipRateRepository($this->db))->save($supplier, $id, $input, $input['row_version']);
    }

    public function assertUsable(int $supplier, int $id, bool $lock): void
    {
        $rate = (new MembershipRateRepository($this->db))->find($supplier, $id, $lock);
        if ($rate === null || !$rate['is_active']) throw new MembershipException('nonprofit_membership_rate_unavailable', 'Vybraná sazba není dostupná v této organizaci. Vyberte jinou nebo zadejte předpis ručně.', 409);
    }

    private static function invalid(): never
    {
        throw new MembershipException('nonprofit_membership_rate_invalid', 'Sazba vyžaduje název, kladnou částku, platné účty, titul, období a podklady.', 422);
    }
}
