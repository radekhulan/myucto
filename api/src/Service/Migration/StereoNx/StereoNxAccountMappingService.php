<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Repository\SupplierBankAccountRepository;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Migration\Shared\ImportedAccountCode;

/** Návrh nastavení firmy je oddělený od jeho zápisu v transakci převodu. */
final class StereoNxAccountMappingService
{
    private const VAT_ACCOUNT_NAMES = ['input' => 'Daň z přidané hodnoty vstup',
        'output' => 'Daň z přidané hodnoty výstup', 'settlement' => 'Daň z přidané hodnoty zúčtování',
        'unresolved' => 'DPH – ručně přiřazené kontace'];
    public function __construct(
        private readonly Connection $db,
        private readonly StereoNxAccountingWriter $journal,
        private readonly StereoNxAccountingPayments $payments,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly SupplierBankAccountRepository $banks,
        private readonly PostingRuleRepository $rules,
        private readonly StereoNxImportMap $map,
    ) {}

    public function propose(StereoNxBackup $backup, int $supplierId): array
    {
        $calendar = $this->journal->calendar($supplierId);
        $journal = $this->journal->prepare($backup, $calendar);
        if (!$journal['ok']) {
            throw new StereoNxException('account_mapping_source_invalid', 'Účetní deník nebo účtový rozvrh obsahuje chyby; nejprve je opravte ve zdroji.');
        }
        $payments = $this->payments->prepare($backup, $calendar);
        $proposal = (new StereoNxAccountMappingPlan())->propose($journal, $payments);
        $available = [];
        foreach ($this->accounts->listForTenant($supplierId, true) as $account) {
            $available[(string) $account['account_code']] = ['code' => (string) $account['account_code'],
                'name' => (string) $account['name'], 'is_active' => (bool) $account['is_active']];
        }
        $existingBanks = $this->banks->listForSupplier($supplierId);
        $reserved = array_fill_keys(array_keys($available), true);
        foreach ($existingBanks as $bank) {
            if (($bank['analytic_suffix'] ?? '') !== '') $reserved['221.' . $bank['analytic_suffix']] = true;
        }
        $existingReserved = $reserved;
        foreach ($journal['accounting_plan']['chart'] as $sourceAccount) {
            $reserved[ImportedAccountCode::normalize((string) $sourceAccount['code'])] = true;
        }
        $sourceBanks = [];
        foreach ($payments['records']['bank_accounts'] ?? [] as $bank) $sourceBanks[$bank['source_key']] = $bank;
        $assignedByIdentity = [];
        foreach ($proposal['banks'] as &$row) {
            $source = $sourceBanks[$row['source_key']];
            $current = $this->matchingBank($source, $existingBanks);
            $identity = $row['identity_key'];
            if (isset($assignedByIdentity[$identity])) {
                $code = $assignedByIdentity[$identity];
            } elseif (($current['analytic_suffix'] ?? '') !== '') {
                $code = '221.' . $current['analytic_suffix'];
            } else {
                $code = $row['target_code'];
                if (isset($existingReserved[$code])) {
                    for ($suffix = 100; isset($reserved['221.' . $suffix]); $suffix++) {
                        if ($suffix > 999999) throw new StereoNxException('account_mapping_bank_exhausted', 'Chybí volná analytika banky.');
                    }
                    $code = '221.' . $suffix;
                }
            }
            $reserved[$code] = true;
            $existingReserved[$code] = true;
            $assignedByIdentity[$identity] = $code;
            $row['target_code'] = $code;
            $proposal['bank_accounts'][$row['source_key']] = $code;
        }
        unset($row);
        $proposal['available_accounts'] = array_values($available);
        $proposal['vat_account_names'] = self::VAT_ACCOUNT_NAMES;
        $names = self::targetNames($journal, $payments, $proposal);
        $proposal['suggested_accounts'] = [];
        foreach ($names as $code => $name) $proposal['suggested_accounts'][] = ['code' => (string) $code, 'name' => $name];
        return $proposal;
    }

    /** Parametry jobu obsahují jen ověřené volby, nikdy libovolný objekt z požadavku. */
    public static function configuration(mixed $value): ?array
    {
        if ($value === null) return null;
        if (!is_array($value) || array_diff(array_keys($value), ['accounts', 'bank_accounts', 'vat']) !== []
            || !is_array($value['accounts'] ?? null) || !is_array($value['bank_accounts'] ?? null)
            || count($value['accounts']) > 5000 || count($value['bank_accounts']) > 1000) {
            throw new StereoNxException('account_mapping_invalid', 'Mapování účtů nemá platný formát.');
        }
        $out = ['accounts' => [], 'bank_accounts' => [], 'vat' => null];
        foreach (['accounts', 'bank_accounts'] as $part) {
            foreach ($value[$part] as $source => $target) {
                if (strlen((string) $source) > 190 || (string) $source === '' || !is_string($target)
                    || strlen(ImportedAccountCode::normalize($target)) > 10 || trim($target) === '') {
                    throw new StereoNxException('account_mapping_invalid', 'Mapování musí obsahovat platné zdrojové a cílové účty.');
                }
                $code = ImportedAccountCode::normalize($target);
                if (preg_match('/^[0-9]{3}[0-9A-Za-z.\/-]*$/D', $code) !== 1
                    || ($part === 'bank_accounts' && preg_match('/^221\.[0-9]{1,6}$/D', $code) !== 1)
                    || ($part === 'accounts' && substr((string) $source, 0, 3) !== substr($code, 0, 3))) {
                    throw new StereoNxException('account_mapping_invalid', 'Cílový účet nemá platný kód.');
                }
                $out[$part][(string) $source] = $code;
            }
            ksort($out[$part], SORT_STRING);
        }
        if (($value['vat'] ?? null) !== null) {
            if (!is_array($value['vat']) || array_diff(array_keys($value['vat']), ['input', 'output', 'settlement', 'unresolved']) !== []) {
                throw new StereoNxException('account_mapping_invalid', 'Mapování DPH nemá platný formát.');
            }
            $out['vat'] = [];
            foreach (['input', 'output', 'settlement', 'unresolved'] as $role) {
                $code = $value['vat'][$role] ?? null;
                if ($code === null && $role === 'unresolved') { $out['vat'][$role] = null; continue; }
                if (!is_string($code) || strlen(ImportedAccountCode::normalize($code)) > 10
                    || preg_match('/^343[0-9A-Za-z.\/-]*$/D', ImportedAccountCode::normalize($code)) !== 1) {
                    throw new StereoNxException('account_mapping_invalid', 'Vyberte účty pro jednotlivé role DPH.');
                }
                $out['vat'][$role] = ImportedAccountCode::normalize($code);
            }
            if (count(array_unique(array_intersect_key($out['vat'], array_flip(['input', 'output', 'settlement'])))) !== 3) {
                throw new StereoNxException('account_mapping_invalid', 'Role DPH vyžadují tři odlišné účty.');
            }
        }
        return $out;
    }

    public static function fingerprint(?array $configuration): string
    {
        return hash('sha256', json_encode(self::configuration($configuration), JSON_THROW_ON_ERROR));
    }

    /** Názvy jsou stejné v náhledu i při zakládání účtů; existující název se nepřepisuje. */
    public static function targetNames(array $journal, array $payments, array $configuration): array
    {
        $names = [];
        foreach ($journal['accounting_plan']['chart'] ?? [] as $source) {
            $code = $configuration['accounts'][$source['code']] ?? ImportedAccountCode::normalize($source['code']);
            $names[$code] ??= trim((string) $source['name']) ?: 'Účet ' . $code;
        }
        foreach ($payments['records']['bank_accounts'] ?? [] as $bank) {
            $code = $configuration['bank_accounts'][$bank['source_key']] ?? null;
            if ($code !== null) $names[$code] ??= (string) ($bank['label'] ?: 'Bankovní účet ' . $bank['source_key']);
        }
        foreach (self::VAT_ACCOUNT_NAMES as $role => $name) {
            $code = $configuration['vat'][$role] ?? null;
            if ($code !== null) $names[$code] ??= $name;
        }
        return $names;
    }

    public function validateTargets(array $journal, int $supplierId): array
    {
        $selected = [];
        foreach ($journal['accounting_plan']['entries'] ?? [] as $entry) {
            foreach (['debit', 'credit'] as $side) $selected[$entry['target_' . $side]] = true;
        }
        foreach ($journal['account_mapping']['vat'] ?? [] as $code) if ($code !== null) $selected[$code] = true;
        foreach ($journal['account_mapping']['bank_accounts'] ?? [] as $code) $selected[$code] = true;
        foreach ($this->accounts->listForTenant($supplierId, true) as $account) {
            if (isset($selected[$account['account_code']]) && !$account['is_active']) {
                throw new StereoNxException('account_mapping_inactive', 'Cílový účet ' . $account['account_code'] . ' je neaktivní. Vyberte aktivní účet.');
            }
        }
        $definitions = [];
        foreach ($journal['accounting_plan']['chart'] as $source) {
            $code = $journal['account_mapping']['accounts'][$source['code']] ?? ImportedAccountCode::normalize($source['code']);
            $definition = [$source['source_type'], $source['tax_deductible']];
            if (isset($definitions[$code]) && $definitions[$code] !== $definition) {
                throw new StereoNxException('account_mapping_definition_conflict', 'Na cílový účet ' . $code . ' míří zdrojové účty s různými vlastnostmi.');
            }
            $definitions[$code] = $definition;
        }
        $warnings = [];
        foreach (self::controlAccounts($journal) as $synthetic => $targets) {
            if (count($targets) > 1) $warnings[] = ['level' => 'warning', 'code' => 'document_control_account_ambiguous',
                'message' => 'Syntetický účet ' . $synthetic . ' má více cílových analytik (' . implode(', ', $targets)
                    . '). Před další úpravou nebo zaúčtováním dokladu zkontrolujte jeho předkontaci pohledávky či závazku.'];
        }
        return $warnings;
    }

    /** Starší převody mají doložené cíle bez tečky; jejich identita má přednost před novým účtem. */
    public function resolveExistingTargets(array $journal, int $supplierId, string $ico, int $companyIndex): array
    {
        $existing = self::existingTargets($this->accounts->listForTenant($supplierId, true));
        $mapped = $this->map->accountCodes($supplierId, $ico, $companyIndex);
        $resolve = static function (string $code) use ($existing): string {
            // Analytiku banky musí umět sestavit i BankAnalyticResolver ze suffixu.
            return substr($code, 0, 3) === '221' ? $code : ($existing[$code] ?? $code);
        };
        foreach ($journal['account_mapping']['accounts'] as $source => &$target) {
            $previous = $mapped[$source] ?? null;
            if ($previous !== null && ImportedAccountCode::normalize($previous) === $target) {
                if ($previous !== $target && ($existing[$target] ?? null) === $target) {
                    throw new StereoNxException('account_mapping_existing_conflict',
                        'Dříve převedený účet ' . $previous . ' a nativní účet ' . $target
                            . ' existují současně. Nejprve sjednoťte jejich účetní vazby; mapování nesmí vybrat jiný účet, než ukazuje náhled.');
                }
                if (substr($target, 0, 3) === '221' && $previous !== $target) {
                    throw new StereoNxException('account_mapping_legacy_bank',
                        'Dříve převedený bankovní účet ' . $previous . ' nemá nativní tvar s tečkou. Nejprve sjednoťte jeho analytiku a vazbu na vlastní bankovní účet; opakovaný převod nesmí založit druhý účet.');
                }
                $target = $previous;
            } else {
                $target = $resolve($target);
            }
        }
        unset($target);
        $actualTargets = [];
        foreach ($journal['accounting_plan']['entries'] as &$entry) {
            foreach (['debit', 'credit'] as $side) {
                $target = $entry['target_' . $side];
                $source = (string) $entry[$side];
                $selected = $journal['account_mapping']['accounts'][$source] ?? null;
                $entry['target_' . $side] = $selected !== null && ImportedAccountCode::normalize($selected) === $target
                    ? $selected : $resolve($target);
                $actualTargets[$target][$entry['target_' . $side]] = true;
            }
        }
        unset($entry);
        foreach ($journal['account_mapping']['vat'] ?? [] as $role => $code) {
            if ($code === null) continue;
            $targets = array_keys($actualTargets[$code] ?? []);
            if (count($targets) > 1) {
                throw new StereoNxException('account_mapping_existing_conflict',
                    'Na účet DPH ' . $code . ' míří dříve převzaté i nativní účty s různými kódy. Nejprve sjednoťte jejich účetní vazby; převod je nesmí tiše sloučit ani změnit předkontace.');
            }
            $journal['account_mapping']['vat'][$role] = $targets === [] ? $resolve($code) : (string) $targets[0];
        }
        return $journal;
    }

    /** Přesný nativní kód vyhrává nad starším stejně normalizovaným kódem. */
    public static function existingTargets(array $accounts): array
    {
        $targets = [];
        foreach ($accounts as $account) {
            $code = (string) $account['account_code'];
            $normalized = ImportedAccountCode::normalize($code);
            $targets[$normalized] ??= $code;
            if ($code === $normalized) $targets[$normalized] = $code;
        }
        return $targets;
    }

    /** Jediný cíl saldokontní syntetiky dovoluje převzít i výchozí předkontace. */
    public static function controlAccounts(array $journal): array
    {
        $targets = [];
        foreach ($journal['accounting_plan']['entries'] ?? [] as $entry) {
            foreach (['debit', 'credit'] as $side) {
                $synthetic = substr((string) $entry[$side], 0, 3);
                if (in_array($synthetic, ['311', '321'], true)) $targets[$synthetic][$entry['target_' . $side]] = true;
            }
        }
        return array_map(static fn (array $codes): array => array_map('strval', array_keys($codes)), $targets);
    }

    /** Kontrola bankovních voleb před zápisem deníku i před změnou nastavení. */
    public function preparePayments(array $payments, array $configuration, int $supplierId): array
    {
        $existing = $this->banks->listForSupplier($supplierId);
        $payments['records']['bank_accounts'] ??= [];
        foreach ($payments['records']['bank_accounts'] as &$record) {
            $code = $configuration['bank_accounts'][$record['source_key']] ?? null;
            if ($code === null) continue;
            $suffix = substr($code, 4);
            $current = $this->matchingBank($record, $existing);
            if (($current['analytic_suffix'] ?? '') !== '' && $current['analytic_suffix'] !== $suffix) {
                throw new StereoNxException('account_mapping_bank_changed', 'Bankovní účet už má přiřazenou jinou analytiku. Zachovejte ji v mapování.');
            }
            foreach ($existing as $other) {
                if (($other['analytic_suffix'] ?? '') === $suffix && ($current['id'] ?? null) !== $other['id']) {
                    throw new StereoNxException('account_mapping_bank_collision', 'Vybranou analytiku už používá jiný bankovní účet firmy.');
                }
            }
            $record['target_analytic_suffix'] = $suffix;
        }
        unset($record);
        return $payments;
    }

    /** Nastavení používají i následné nativní doklady; zkouška ho spolu s deníkem vrátí zpět. */
    public function applySettings(array $configuration, int $supplierId, string $ico, int $companyIndex, array $journal): void
    {
        if (!$this->db->pdo()->inTransaction()) throw new StereoNxException('transaction_required', 'Nastavení mapování vyžaduje transakci převodu.');
        $hash = self::fingerprint($configuration);
        $previous = $this->map->get($supplierId, $ico, $companyIndex, 'accounting_settings', 'posting_rules');
        if ($previous !== null) {
            if (!hash_equals($previous['source_hash'], $hash)) {
                throw new StereoNxException('account_mapping_settings_changed', 'Nastavení tohoto převodu už bylo použito. Pro opakovaný import zachovejte stejné mapování.');
            }
            // Cílový kód může být současně jiným zdrojovým kódem. Pravidla už
            // převedená do cílové osnovy proto znovu nepřekládáme jako zdrojová.
            return;
        }
        $changes = [];
        $effective = $this->rules->effectiveMap($supplierId);
        $ruleMapping = $configuration['accounts'];
        foreach (self::controlAccounts($journal) as $synthetic => $targets) {
            // Zdrojová analytika nemusí obsahovat syntetiku v použitém rozvrhu.
            // Jediný doložený cíl přesto určuje i nativní předpis závazku/pohledávky.
            if (count($targets) === 1) $ruleMapping[$synthetic] = $targets[0];
        }
        foreach ($effective as $key => $rule) {
            $debit = $ruleMapping[$rule['debit_account_code'] ?? ''] ?? $rule['debit_account_code'];
            $credit = $ruleMapping[$rule['credit_account_code'] ?? ''] ?? $rule['credit_account_code'];
            if ($debit !== $rule['debit_account_code'] || $credit !== $rule['credit_account_code']) {
                $changes[$key] = ['rule_key' => $key, 'debit_account_code' => $debit,
                    'credit_account_code' => $credit, 'description' => (string) $rule['description']];
            }
        }
        $vat = $configuration['vat'];
        if ($vat === null) {
            $flat = [];
            foreach ($journal['accounting_plan']['entries'] ?? [] as $entry) {
                foreach (['debit', 'credit'] as $side) {
                    if (substr((string) $entry[$side], 0, 3) === '343') $flat[$entry['target_' . $side]] = true;
                }
            }
            // Výslovně ponechaný plochý účet používají i nové doklady. Jinak by
            // první editace znovu zavedla výchozí tři analytiky vedle převzatého účtu.
            if (count($flat) === 1) {
                $code = (string) array_key_first($flat);
                $vat = ['input' => $code, 'output' => $code, 'settlement' => $code];
            } elseif ($flat !== []) {
                $vat = array_replace([
                    'input' => $effective['invoice.vat.input']['debit_account_code'] ?? '343.100',
                    'output' => $effective['invoice.vat.output']['credit_account_code'] ?? '343.200',
                    'settlement' => $effective['vat.clearing.output']['credit_account_code'] ?? '343.900',
                ], (new StereoNxAccountMappingPlan())->nativeVatAccounts($journal));
                if (count(array_unique($vat)) !== 3 || array_diff(array_keys($flat), array_values($vat)) !== []) {
                    throw new StereoNxException('account_mapping_vat_roles_required',
                        'Ruční mapování DPH nelze jednoznačně přenést do předkontací dalších dokladů. Zapněte automatické rozdělení DPH a vyberte cílové účty pro vstup, výstup a zúčtování.');
                }
            }
        }
        if ($vat !== null) {
            foreach (['invoice.vat.input' => [$vat['input'], null], 'invoice.vat.output' => [null, $vat['output']],
                'vat.clearing.input' => [$vat['settlement'], $vat['input']],
                'vat.clearing.output' => [$vat['output'], $vat['settlement']]] as $key => [$debit, $credit]) {
                $changes[$key] = ['rule_key' => $key, 'debit_account_code' => $debit,
                    'credit_account_code' => $credit, 'description' => 'DPH podle potvrzeného převodu Stereo NX'];
            }
        }
        $this->rules->upsertOverrides($supplierId, array_values($changes));
        $this->map->put($supplierId, $ico, $companyIndex, 'accounting_settings', 'posting_rules', $hash, $supplierId);
    }

    private function matchingBank(array $source, array $existing): ?array
    {
        $canonical = AccountNumberNormalizer::canonical((string) ($source['account_number'] ?? ''), $source['iban'] ?? null);
        if ($canonical === null) return null;
        $bank = AccountNumberNormalizer::canonicalBankCode($source['bank_code'] ?? null);
        foreach ($existing as $row) {
            if ($row['account_canonical'] === $canonical && (AccountNumberNormalizer::canonicalBankCode($row['bank_code'] ?? null) ?? '') === ($bank ?? '')
                && ($row['currency'] ?? 'CZK') === ($source['currency'] ?? 'CZK')) return $row;
        }
        return null;
    }
}
