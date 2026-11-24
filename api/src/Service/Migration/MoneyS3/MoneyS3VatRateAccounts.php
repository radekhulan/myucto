<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\Vat\VatPostingAccounts;

final class MoneyS3VatRateAccounts
{
    public function __construct(
        private readonly Connection $db,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly PostingRuleRepository $rules,
    ) {}

    /**
     * Money odděluje předkontaci základu (UcPrKont) od předkontace daně (UcPrDPH).
     * Zkratka ZauctDPH spojuje obě tabulky. Sazbu ZD/SD určuje příslušný slot
     * na faktuře, nikoli rok převodu. Přednost má předkontace položky.
     * Pro každou sazbu použijeme poslední rok s průkazným účtováním.
     *
     * @param list<string>|null $onlyDirs názvy převáděných adresářů
     * @return array<string,array<int|string,string>>
     */
    public static function detect(Ms3Backup $backup, ?array $onlyDirs = null): array
    {
        $result = ['output' => [], 'input' => []];
        $seen = $result;
        $years = [];
        foreach (array_reverse($backup->yearDirs()) as $dir) {
            if ($onlyDirs === null || in_array(basename($dir), $onlyDirs, true)) {
                $years[$dir] = $backup->yearSettings($dir)['year'] ?? 0;
            }
        }
        arsort($years, SORT_NUMERIC);
        foreach (array_keys($years) as $dir) {
            $posting = [];
            foreach ($backup->table('UcPrKont', $dir)?->rows(['Zkrat', 'ZauctDPH']) ?? [] as $row) {
                $posting[trim((string) ($row['Zkrat'] ?? ''))] = trim((string) ($row['ZauctDPH'] ?? ''));
            }
            $vat = [];
            foreach ($backup->table('UcPrDPH', $dir)?->rows(['Zkratka', 'SDUcMD', 'SDUcD', 'ZDUcMD', 'ZDUcD']) ?? [] as $row) {
                $vat[trim((string) ($row['Zkratka'] ?? ''))] = $row;
            }
            if ($posting === [] || $vat === []) {
                continue;
            }
            foreach (['output' => 'V', 'input' => 'P'] as $direction => $prefix) {
                $candidates = self::yearCandidates($backup, $dir, $prefix, $posting, $vat);
                foreach ($candidates as $rate => $codes) {
                    if (isset($seen[$direction][$rate])) {
                        continue;
                    }
                    $seen[$direction][$rate] = true;
                    if (count($codes) === 1) {
                        $result[$direction][$rate] = (string) array_key_first($codes);
                    }
                }
            }
        }
        return $result;
    }

    private static function yearCandidates(Ms3Backup $backup, string $dir, string $prefix, array $posting, array $vat): array
    {
        $items = [];
        foreach ($backup->table($prefix . 'FaktPol', $dir)?->rows(['Cislo', 'SazbaDPH', 'PredKontac', 'Cena']) ?? [] as $item) {
            $key = trim((string) ($item['PredKontac'] ?? ''));
            $rate = (float) ($item['SazbaDPH'] ?? 0);
            if ($rate > 0 && (float) ($item['Cena'] ?? 0) !== 0.0) {
                $items[(int) $item['Cislo']][InvoiceImporter::rateKey($rate)][$key] = true;
            }
        }

        $candidates = [];
        $fields = ['Cislo', 'PredKontac', 'Druh', 'Storno', 'Neuctovat', 'SazbaDPH1', 'SazbaDPH2', 'DPH_1', 'DPH_2'];
        $side = $prefix === 'V' ? 'D' : 'MD';
        foreach ($backup->table($prefix . 'Faktury', $dir)?->rows($fields) ?? [] as $row) {
            if (!in_array(trim((string) ($row['Druh'] ?? '')), ['', 'N'], true)
                || !empty($row['Storno']) || !empty($row['Neuctovat'])) {
                continue;
            }
            $headerKey = trim((string) ($row['PredKontac'] ?? ''));
            foreach ([1 => 'SD', 2 => 'ZD'] as $slot => $fieldPrefix) {
                $rate = (float) ($row['SazbaDPH' . $slot] ?? 0);
                if ($rate <= 0 || abs((float) ($row['DPH_' . $slot] ?? 0)) < 0.005) {
                    continue;
                }
                $rateKey = InvoiceImporter::rateKey($rate);
                $itemKeys = $items[(int) ($row['Cislo'] ?? 0)][$rateKey] ?? ['' => true];
                foreach (array_keys($itemKeys) as $key) {
                    $vatKey = ($posting[$key] ?? '') ?: ($posting[$headerKey] ?? '');
                    $raw = (string) ($vat[$vatKey][$fieldPrefix . 'Uc' . $side] ?? '');
                    if (AccountCode::synthetic($raw) === '343') {
                        $candidates[$rateKey][AccountCode::fromMoney($raw)] = true;
                    }
                }
            }
        }
        return $candidates;
    }

    /**
     * Navrhne nebo doplní kontace v osnově cílové firmy. Už uložené firemní volby ponechá.
     * @param list<string>|null $onlyDirs
     * @return list<array{rule_key:string,debit_account_code:?string,credit_account_code:?string,description:string}>
     */
    public function run(Ms3Backup $backup, int $supplierId, bool $commit = false, ?array $onlyDirs = null): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ic, accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch(\PDO::FETCH_ASSOC);
        $ico = CodebookImporter::ico(($backup->agendaCompany()['ico'] ?? '') ?: ($backup->agendaInfoIni()['ico'] ?? ''));
        if ($supplier === false || $supplier['accounting_mode'] !== 'double_entry'
            || ($ico !== '' && $ico !== CodebookImporter::ico((string) $supplier['ic']))) {
            throw new MoneyS3Exception('vat_rate_accounts_supplier_mismatch', 'Záloha neodpovídá cílové firmě s podvojným účetnictvím.');
        }

        return $this->prefill($backup, $supplierId, $commit, $onlyDirs);
    }

    public function prefill(Ms3Backup $backup, int $supplierId, bool $commit, ?array $onlyDirs = null): array
    {
        $chart = $this->accounts->listForTenant($supplierId, true);
        $effective = $this->rules->effectiveMap($supplierId);
        $plan = self::plan(self::detect($backup, $onlyDirs), $chart, $effective);
        if ($commit) {
            $this->rules->upsertOverrides($supplierId, $plan, replace: false);
        }
        return $plan;
    }

    public static function plan(array $detected, array $chart, array $effective): array
    {
        $result = [];
        $active = array_column($chart, 'is_active', 'account_code');
        $settlement = VatPostingAccounts::settlement($effective);
        foreach ($detected as $direction => $rates) {
            $rates = array_filter($rates, static fn (string $code): bool =>
                !empty($active[$code]) && $code !== $settlement
                && VatPostingAccounts::rootCode($chart, $code) === '343');
            if ($rates === []) {
                continue;
            }
            krsort($rates, SORT_NUMERIC);
            $base = 'invoice.vat.' . $direction;
            $side = VatPostingAccounts::SIDES[$direction];
            $common = reset($rates);
            if (($effective[$base]['supplier_id'] ?? null) !== null) {
                $common = VatPostingAccounts::common($effective, $direction);
                $root = VatPostingAccounts::rootCode($chart, $common);
                if ($root !== null && $root !== '343') {
                    continue;
                }
            }
            $codes = [$base => $common];
            if (array_diff($rates, [$common]) !== []) {
                foreach ($rates as $rate => $code) {
                    $codes[$base . '.' . $rate] = $code;
                }
            }
            foreach ($codes as $key => $code) {
                if (($effective[$key]['supplier_id'] ?? null) !== null
                    || VatPostingAccounts::ruleCode($effective, $key, $side) === $code) {
                    continue;
                }
                $result[] = [
                    'rule_key' => $key,
                    'debit_account_code' => $side === 'debit' ? $code : ($effective[$key]['debit_account_code'] ?? null),
                    'credit_account_code' => $side === 'credit' ? $code : ($effective[$key]['credit_account_code'] ?? null),
                    'description' => ($direction === 'input' ? 'DPH na vstupu' : 'DPH na výstupu') . ' (Money S3)',
                ];
            }
        }
        return $result;
    }
}
