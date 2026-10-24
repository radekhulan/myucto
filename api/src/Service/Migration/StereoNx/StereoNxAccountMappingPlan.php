<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Migration\Shared\ImportedAccountCode;

/** Cílové kontace bez zásahu do identity, částek nebo období zdrojového deníku. */
final class StereoNxAccountMappingPlan
{
    /** Návrh zahrnuje pouze účty použité na některé straně převáděné kontace. */
    public function propose(array $journalPrepared, array $paymentPrepared): array
    {
        $entries = $journalPrepared['accounting_plan']['entries'] ?? [];
        $used = $this->usedAccounts($entries);
        $chart = [];
        $reserved = [];
        foreach ($journalPrepared['accounting_plan']['chart'] ?? [] as $account) {
            $chart[(string) $account['code']] = $account;
            $reserved[ImportedAccountCode::normalize((string) $account['code'])] = true;
        }
        $accounts = [];
        $rows = [];
        $hasVat = false;
        foreach ($used as $source => $count) {
            $source = (string) $source;
            $target = ImportedAccountCode::normalize($source);
            $accounts[$source] = $target;
            $rows[] = ['source_code' => $source, 'name' => (string) ($chart[$source]['name'] ?? ''),
                'target_code' => $target, 'count' => $count];
            $hasVat = $hasVat || substr($source, 0, 3) === '343';
        }
        $bankRecords = $this->bankRecords($paymentPrepared);
        $bankIdentities = array_map(self::bankIdentity(...), $bankRecords);
        $physicalBanks = array_unique($bankIdentities);
        $bankSources = [];
        $owners = [];
        foreach ($entries as $entry) {
            $parts = $this->parts($entry);
            foreach (['debit', 'credit'] as $side) {
                $source = (string) $entry[$side];
                if (substr($source, 0, 3) !== '221') continue;
                $series = ($parts[0] ?? '') === 'B' ? (string) ($parts[1] ?? '') : '';
                if ($series === '' && count($physicalBanks) === 1) $series = (string) array_key_first($bankRecords);
                $target = ImportedAccountCode::normalize($source);
                $identity = $bankIdentities[$series] ?? ('unknown:' . $series);
                $owners[$target][$identity] = true;
                if (isset($bankRecords[$series])) $bankSources[$identity][$target] = true;
            }
        }
        $bankAccounts = [];
        $banks = [];
        $allocated = [];
        $physicalTargets = [];
        $next = 100;
        foreach ($bankRecords as $series => $record) {
            $identity = $bankIdentities[$series];
            $candidates = array_map('strval', array_keys($bankSources[$identity] ?? []));
            $target = $physicalTargets[$identity] ?? (count($candidates) === 1 && preg_match('/^221\.[0-9]{1,6}$/D', $candidates[0]) === 1
                && count($owners[$candidates[0]]) === 1 ? $candidates[0] : null);
            if ($target === null) {
                do {
                    $target = '221.' . $next++;
                } while (isset($reserved[$target]) || isset($allocated[$target]));
            }
            $allocated[$target] = true;
            $physicalTargets[$identity] = $target;
            $bankAccounts[$series] = $target;
            $banks[] = ['source_key' => (string) $series, 'label' => (string) ($record['label'] ?? $series),
                'target_code' => $target, 'identity_key' => $identity];
        }
        $vat = $hasVat ? ['input' => PostingService::INPUT_VAT_ACCOUNT,
            'output' => PostingService::OUTPUT_VAT_ACCOUNT,
            'settlement' => PostingService::VAT_SETTLEMENT_ACCOUNT, 'unresolved' => null] : null;
        $groups = $this->groups($entries);
        $counts = ['input' => 0, 'output' => 0, 'settlement' => 0, 'unresolved' => 0, 'reverse_charge_unresolved' => 0];
        $unresolved = [];
        foreach ($entries as $entry) {
            foreach (['debit', 'credit'] as $side) {
                if (substr((string) $entry[$side], 0, 3) !== '343') continue;
                $role = $this->vatRole($entry, $side, $groups);
                $counts[$role]++;
                $isReverseChargeUnknown = $role === 'unresolved' && $this->isVatPair($entry);
                if ($isReverseChargeUnknown) $counts['reverse_charge_unresolved']++;
                if ($role === 'unresolved' && count($unresolved) < 100) {
                    $isOpening = ($entry['is_opening'] ?? false) === true;
                    $unresolved[] = ['source_key' => $entry['source_key'],
                        'side' => $side, 'source_code' => (string) $entry[$side],
                        'reason' => $isReverseChargeUnknown ? 'vat_reverse_charge_ambiguous'
                            : ($isOpening ? 'opening_balance' : 'vat_role_ambiguous'),
                        'date' => (string) ($entry['date'] ?? ''),
                        'document_no' => (string) ($entry['document_no'] ?? ''),
                        'description' => (string) ($entry['description'] ?? ''),
                        'amount_cents' => (int) ($entry['amount_cents'] ?? 0),
                        'is_opening' => $isOpening, 'debit' => (string) $entry['debit'],
                        'credit' => (string) $entry['credit'],
                        'source_name' => (string) ($chart[$entry[$side]]['name'] ?? '')];
                }
            }
        }
        return ['accounts' => $accounts, 'bank_accounts' => $bankAccounts, 'vat' => $vat,
            'used_accounts' => $rows, 'banks' => $banks, 'unresolved' => $unresolved, 'vat_counts' => $counts];
    }

    /** Původní rozvrh i source_hash zůstávají přesně zachované pro opakovaný převod. */
    public function apply(array $journalPrepared, array $configuration, array $paymentPrepared): array
    {
        $entries = $journalPrepared['accounting_plan']['entries'] ?? [];
        $used = $this->usedAccounts($entries);
        $bankRecords = $this->bankRecords($paymentPrepared);
        $bankIdentities = array_map(self::bankIdentity(...), $bankRecords);
        $physicalBankCount = count(array_unique($bankIdentities));
        $accounts = $this->mapping($configuration['accounts'] ?? [], $used, null);
        $banks = $this->mapping($configuration['bank_accounts'] ?? [], $bankRecords, '221');
        foreach ($banks as $target) {
            if (preg_match('/^221\.[0-9]{1,6}$/D', $target) !== 1) {
                throw new StereoNxException('account_mapping_bank_invalid', 'Analytický účet banky musí mít číselný tvar 221.xxx.');
            }
        }
        $physicalTargets = [];
        $targetOwners = [];
        foreach ($banks as $series => $target) {
            $identity = $bankIdentities[$series];
            if (isset($targetOwners[$target]) && $targetOwners[$target] !== $identity) {
                throw new StereoNxException('account_mapping_bank_duplicate', 'Každý bankovní účet musí mít vlastní analytický účet.');
            }
            if (isset($physicalTargets[$identity]) && $physicalTargets[$identity] !== $target) {
                throw new StereoNxException('account_mapping_bank_alias_conflict', 'Řady stejného bankovního účtu musí používat stejnou analytiku.');
            }
            $targetOwners[$target] = $identity;
            $physicalTargets[$identity] = $target;
        }
        foreach ($bankIdentities as $series => $identity) {
            if (isset($physicalTargets[$identity])) $banks[$series] = $physicalTargets[$identity];
        }
        $vat = $configuration['vat'] ?? null;
        if ($vat !== null) {
            if (!is_array($vat)) throw new StereoNxException('account_mapping_invalid', 'Mapování DPH není platné.');
            $normalized = [];
            foreach (['input', 'output', 'settlement', 'unresolved'] as $role) {
                $normalized[$role] = $role === 'unresolved' && ($vat[$role] ?? null) === null
                    ? null : $this->target($vat[$role] ?? null, '343');
            }
            $vat = $normalized;
            if (count(array_unique([$vat['input'], $vat['output'], $vat['settlement']])) !== 3) {
                throw new StereoNxException('account_mapping_vat_duplicate', 'Účty DPH na vstupu, výstupu a zúčtování musí být odlišné.');
            }
        }
        $groups = $this->groups($entries);
        foreach ($entries as &$entry) {
            $parts = $this->parts($entry);
            foreach (['debit', 'credit'] as $side) {
                $source = (string) $entry[$side];
                $synthetic = substr($source, 0, 3);
                $target = $accounts[$source] ?? ImportedAccountCode::normalize($source);
                if ($synthetic === '221') {
                    $series = ($parts[0] ?? '') === 'B' ? (string) ($parts[1] ?? '') : '';
                    if (isset($banks[$series])) {
                        $target = $banks[$series];
                    } elseif ($physicalBankCount === 1 && $banks !== [] && $series === '') {
                        $target = reset($banks);
                    } elseif (!array_key_exists($source, $accounts)) {
                        throw new StereoNxException('account_mapping_bank_unresolved', 'Účet banky bez jednoznačné řady vyžaduje výslovné mapování.');
                    }
                }
                if ($synthetic === '343' && $vat !== null) {
                    $role = $this->vatRole($entry, $side, $groups);
                    if ($role === 'unresolved' && $this->isVatPair($entry)) {
                        throw new StereoNxException('account_mapping_vat_reverse_charge_unresolved',
                            'Dvojice účtů DPH nemá doložený směr samovyměření. Vypněte automatické rozdělení DPH a přiřaďte zdrojové účty ručně.');
                    }
                    $target = $vat[$role];
                    if ($target === null) {
                        throw new StereoNxException('account_mapping_vat_unresolved', 'Nerozpoznané kontace DPH vyžadují výslovnou volbu cílového účtu.');
                    }
                }
                $entry['target_' . $side] = $this->target($target, $synthetic);
            }
        }
        unset($entry);
        $journalPrepared['accounting_plan']['entries'] = $entries;
        $journalPrepared['account_mapping'] = ['accounts' => $accounts, 'bank_accounts' => $banks, 'vat' => $vat];
        return $journalPrepared;
    }

    /** Nativní role vyvozujeme jen z doložených kontací, nikdy z počátečního zůstatku. */
    public function nativeVatAccounts(array $prepared): array
    {
        $entries = $prepared['accounting_plan']['entries'] ?? [];
        $groups = $this->groups($entries);
        $roles = [];
        foreach ($entries as $entry) {
            foreach (['debit', 'credit'] as $side) {
                if (substr((string) $entry[$side], 0, 3) !== '343') continue;
                $role = $this->vatRole($entry, $side, $groups);
                if ($role === 'unresolved') continue;
                if (!isset($entry['target_' . $side])) {
                    throw new StereoNxException('account_mapping_invalid', 'Nejprve potvrďte cílové účty převáděných kontací.');
                }
                $target = (string) $entry['target_' . $side];
                if (isset($roles[$role]) && $roles[$role] !== $target) {
                    $label = match ($role) {
                        'input' => 'vstupní DPH', 'output' => 'výstupní DPH', default => 'zúčtování DPH',
                    };
                    throw new StereoNxException('account_mapping_vat_role_conflict',
                        "Pro {$label} je vybráno více cílových účtů. Zapněte automatické rozdělení DPH a vyberte jeden účet pro každou roli, aby následné úpravy dokladů zachovaly stejné účty.");
                }
                $roles[$role] = $target;
            }
        }
        return $roles;
    }

    private function mapping(mixed $mapping, array $known, ?string $synthetic): array
    {
        if (!is_array($mapping)) throw new StereoNxException('account_mapping_invalid', 'Mapování účtů není platné.');
        $result = [];
        foreach ($mapping as $source => $target) {
            if (!array_key_exists($source, $known)) {
                throw new StereoNxException('account_mapping_source_unknown', 'Mapování obsahuje nepoužitý zdrojový účet nebo neznámou bankovní řadu.');
            }
            $result[$source] = $this->target($target, $synthetic ?? substr((string) $source, 0, 3));
        }
        return $result;
    }

    private function target(mixed $value, string $synthetic): string
    {
        if (!is_string($value)) throw new StereoNxException('account_mapping_target_invalid', 'Cílový účet musí být neprázdný text.');
        $target = ImportedAccountCode::normalize($value);
        if (strlen($target) > 10 || preg_match('/^[0-9]{3}[0-9A-Za-z.\/-]*$/D', $target) !== 1) {
            throw new StereoNxException('account_mapping_target_invalid', 'Cílový účet má neplatný formát nebo přesahuje 10 znaků.');
        }
        if (substr($target, 0, 3) !== $synthetic) {
            throw new StereoNxException('account_mapping_synthetic_changed', 'Mapování musí zachovat zdrojový syntetický účet.');
        }
        return $target;
    }

    private function usedAccounts(array $entries): array
    {
        $used = [];
        foreach ($entries as $entry) {
            foreach (['debit', 'credit'] as $side) {
                $source = (string) $entry[$side];
                $used[$source] = ($used[$source] ?? 0) + 1;
            }
        }
        ksort($used, SORT_STRING);
        return $used;
    }

    private function bankRecords(array $paymentPrepared): array
    {
        $records = [];
        foreach ($paymentPrepared['records']['bank_accounts'] ?? [] as $record) {
            $records[(string) $record['source_key']] = $record;
        }
        ksort($records, SORT_STRING);
        return $records;
    }

    /** Více dokladových řad téhož fyzického účtu sdílí jednu cílovou analytiku. */
    public static function bankIdentity(array $record): string
    {
        $canonical = AccountNumberNormalizer::canonical((string) ($record['account_number'] ?? ''), $record['iban'] ?? null);
        $identity = $canonical === null ? ['series', (string) $record['source_key']]
            : ['account', $canonical, AccountNumberNormalizer::canonicalBankCode($record['bank_code'] ?? null),
                strtoupper(trim((string) ($record['currency'] ?? 'CZK')))];
        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }

    private function parts(array $entry): array
    {
        $parts = json_decode((string) ($entry['source_key'] ?? ''), true);
        return is_array($parts) ? $parts : [];
    }

    private function groupKey(array $entry): string
    {
        // Klic odděluje fyzické pohyby stejného výpisu; rok brání spojení opakovaných čísel.
        return json_encode([$entry['posting_year'] ?? substr((string) ($entry['date'] ?? ''), 0, 4),
            array_slice($this->parts($entry), 0, 4)], JSON_THROW_ON_ERROR);
    }

    private function groups(array $entries): array
    {
        $groups = [];
        foreach ($entries as $entry) {
            if (($entry['is_opening'] ?? false) === true) continue;
            $key = $this->groupKey($entry);
            foreach (['debit', 'credit'] as $side) {
                $synthetic = substr((string) $entry[$side], 0, 3);
                $groups[$key]['accounts'][$synthetic] = true;
                $storno = ($entry['is_red_storno'] ?? false) ? 'red' : 'normal';
                $groups[$key]['sides'][$storno][$synthetic][$side] = true;
            }
        }
        return $groups;
    }

    private function vatRole(array $entry, string $side, array $groups): string
    {
        if (($entry['is_opening'] ?? false) === true) return 'unresolved';
        $agenda = $this->parts($entry)[0] ?? '';
        $opposite = substr((string) $entry[$side === 'debit' ? 'credit' : 'debit'], 0, 3);
        $context = $groups[$this->groupKey($entry)] ?? [];
        $group = $context['accounts'] ?? [];
        $input = $agenda === 'PF' || isset($group['321']);
        $output = $agenda === 'VF' || isset($group['311']);
        if ($input && $output) return 'unresolved';
        if ($opposite === '343') {
            // Agenda nerozliší běžnou fakturu od dobropisu. Směr musí doložit
            // související závazek/pohledávka; červené storno zachovává strany.
            $storno = ($entry['is_red_storno'] ?? false) ? 'red' : 'normal';
            $control = $input ? '321' : ($output ? '311' : null);
            $sides = $control !== null ? ($context['sides'][$storno][$control] ?? []) : [];
            if (count($sides) !== 1) return 'unresolved';
            $normalSide = $input ? 'credit' : 'debit';
            $reversed = !isset($sides[$normalSide]);
            return ($side === 'debit') !== $reversed ? 'input' : 'output';
        }
        if ($input) return 'input';
        if ($output) return 'output';
        // Bankovní odvod/vratka bez nákladové či výnosové kontace. U hotovosti by
        // stejná dvojice 343/211 nerozlišila daň na vstupu od zúčtování s FÚ.
        if ($agenda === 'B' && $opposite === '221' && array_diff(array_keys($group), ['343', '221']) === []) {
            return 'settlement';
        }
        return 'unresolved';
    }

    private function isVatPair(array $entry): bool
    {
        return !($entry['is_opening'] ?? false) && substr((string) $entry['debit'], 0, 3) === '343'
            && substr((string) $entry['credit'], 0, 3) === '343';
    }
}
