<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Migration\Shared\ImportedAccountCode;

/** Účetní faktury Stereo NX; deník zůstává autoritativním zdrojem zaúčtování. */
final class StereoNxAccountingDocuments
{
    public function __construct(
        private readonly StereoNxSourcePlan $sourcePlan,
        private readonly StereoNxImporter $importer,
    ) {}

    /** @return array<string,mixed> */
    public function prepare(StereoNxBackup $backup, bool $blankCountryIsCz = false, ?FiscalCalendar $calendar = null, array $partnerCountries = []): array
    {
        $tables = [];
        $available = array_flip($backup->tableNames());
        foreach (['LAdresy', 'LFirmaUc', 'Lsdph', 'LSloupce', 'Svfh', 'Svfp', 'SPFH',
            'Spfp', 'Cpz', 'CPZZ', 'ZAZPVDPH', 'CBanka', 'CBankap', 'CPokl', 'Cdenik'] as $name) {
            $tables[$name] = isset($available[$name]) ? iterator_to_array($backup->rows($name), false) : [];
        }
        $source = $this->sourcePlan->fromTables($tables, $backup->companyIdentity(), $blankCountryIsCz, true, $calendar, $partnerCountries);
        $source['source_company_index'] = $backup->companyIndex();
        $warnings = [];
        $foreignDocuments = 0;
        $skippedForeignDocuments = 0;
        foreach (['issued', 'purchases'] as $part) {
            foreach ($source[$part] ?? [] as $key => $document) {
                if (($document['currency_code'] ?? 'CZK') !== 'CZK') $foreignDocuments++;
                foreach (array_unique(array_map('strval', $document['review_codes'] ?? [])) as $code) {
                    $warnings[] = ['level' => 'warning', 'code' => $code,
                        'source_key' => (string) ($document['source_key'] ?? ''),
                        'partner_source_key' => (string) ($document['partner_key'] ?? ''),
                        'document_no' => (string) ($document['document_no'] ?? ''),
                        'message' => 'Doklad ' . (string) ($document['document_no'] ?? '') . ' vyžaduje kontrolu: '
                            . StereoNxImporter::reviewLabel($code) . '.'];
                }
                if (($document['currency_code'] ?? 'CZK') !== 'CZK'
                    && ($document['foreign_takeover_blocked'] ?? null) !== null) {
                    $skippedForeignDocuments++;
                    $warnings[] = ['level' => 'warning', 'code' => 'foreign_document_unverified',
                        'document_no' => (string) ($document['document_no'] ?? ''),
                        'message' => 'Doklad ' . (string) ($document['document_no'] ?? '')
                            . ' nebyl převzat: ' . $document['foreign_takeover_blocked'] . '.'];
                    unset($source[$part][$key]);
                }
            }
        }
        if ($foreignDocuments > 0) {
            $warnings[] = ['level' => 'warning', 'code' => 'foreign_document_ledger_currency_unavailable',
                'message' => 'Účetní deník Stereo NX je převzat v Kč; cizoměnové zůstatky pro kurzové přecenění je nutné zkontrolovat a případně doplnit.',
                'count' => $foreignDocuments];
        }
        $countryCandidates = StereoNxPartnerCountries::candidates($source['clients'] ?? [], $source['issued'] ?? [],
            $source['purchases'] ?? [], StereoNxPartnerCountries::configuration($partnerCountries));
        array_push($warnings, ...StereoNxPartnerCountries::directoryWarnings($source['clients'] ?? [], $countryCandidates));
        return [
            'partner_country_candidates' => $countryCandidates,
            'identity' => $source['identity'],
            'source_company_index' => $backup->companyIndex(),
            'counts' => ['issued' => count($source['issued'] ?? []), 'purchases' => count($source['purchases'] ?? []),
                'skipped_foreign_documents' => $skippedForeignDocuments,
                'requires_draft' => count(array_filter([...(array) ($source['issued'] ?? []), ...(array) ($source['purchases'] ?? [])],
                    static fn (array $d): bool => ($d['requires_draft'] ?? false) === true))],
            'warnings' => $warnings, 'errors' => [],
            'records' => ['clients' => $source['clients'] ?? [], 'issued' => $source['issued'] ?? [],
                'purchases' => $source['purchases'] ?? []],
        ];
    }

    /**
     * Potvrzené účty přenese na položky; více účtů rozdělí jen při jediném řešení
     * doloženém částkami. Nejednoznačný nebo příliš složitý rozpad zůstane ke kontrole.
     * Zdrojová pole se nemění, odvozená pole `target_*` importér neotiskuje do zdroje.
     *
     * @param array<string,mixed> $documentPlan
     * @param array<string,mixed> $journalPrepared
     * @return array<string,mixed>
     */
    public static function withAccountMapping(array $documentPlan, array $journalPrepared): array
    {
        $accountsByDocument = [];
        $amountsByDocument = [];
        $postingsByDocument = [];
        foreach ($journalPrepared['accounting_plan']['entries'] ?? [] as $entry) {
            if (($entry['is_opening'] ?? false) || (int) ($entry['amount_cents'] ?? 0) === 0) continue;
            $parts = json_decode((string) $entry['source_key'], true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($parts) || count($parts) < 5) {
                throw new StereoNxException('import_identity_invalid', 'Účetní zápis nemá platnou zdrojovou identitu.');
            }
            $agenda = $parts[0];
            if (!in_array($agenda, ['VF', 'PF'], true)) continue;
            $key = json_encode(array_slice($parts, 0, 3), JSON_THROW_ON_ERROR);
            foreach (['target_debit', 'target_credit'] as $field) {
                $account = trim((string) ($entry[$field] ?? ''));
                // Stejná položková pole čte PostingService; pořizovací/majetkové účty
                // patří na vstupu vedle nákladů. Závazky, pohledávky a daň nejsou položka.
                if (preg_match($agenda === 'PF' ? '/^[015]/D' : '/^6/D', $account) !== 1) continue;
                $accountsByDocument[$key]['#' . $account] = $account;
                $normalSide = $agenda === 'PF' ? 'target_debit' : 'target_credit';
                $signedAmount = (int) $entry['amount_cents'] * ($field === $normalSide ? 1 : -1)
                    * (($entry['is_red_storno'] ?? false) ? -1 : 1);
                $amountsByDocument[$key][$account] = ($amountsByDocument[$key][$account] ?? 0) + $signedAmount;
                $postingsByDocument[$key][] = ['account' => $account, 'amount_cents' => $signedAmount,
                    'control' => substr((string) ($entry[$field === 'target_debit' ? 'credit' : 'debit'] ?? ''), 0, 3)];
            }
        }

        $accountMapping = $journalPrepared['account_mapping']['accounts'] ?? [];
        $allocationBudget = 250000;
        foreach (['issued' => ['VF', 'revenue_account_code'], 'purchases' => ['PF', 'expense_account_code']] as $part => [$agenda, $field]) {
            foreach ($documentPlan['records'][$part] ?? [] as $index => $document) {
                $parts = json_decode((string) $document['source_key'], true, flags: JSON_THROW_ON_ERROR);
                if (!is_array($parts) || count($parts) < 3 || !isset($parts[1], $parts[2])) {
                    throw new StereoNxException('import_identity_invalid', 'Doklad nemá platnou zdrojovou identitu.');
                }
                $key = json_encode([$agenda, $parts[1], $parts[2]], JSON_THROW_ON_ERROR);
                $accounts = array_values($accountsByDocument[$key] ?? []);
                $amounts = $amountsByDocument[$key] ?? [];
                $rounding = self::separateRounding($document, $field, $amounts, $postingsByDocument[$key] ?? [], $accountMapping, $allocationBudget);
                if ($rounding !== null) {
                    $amounts = $rounding['amounts'];
                    $accounts = array_map('strval', array_keys($amounts));
                    $documentPlan['warnings'][] = ['level' => 'warning', 'code' => 'document_rounding_account_review',
                        'source_key' => $document['source_key'], 'document_no' => (string) ($document['document_no'] ?? ''),
                        'source_rounding_account' => $rounding['account'], 'rounding_amount' => $rounding['amount_cents'] / 100,
                        'message' => 'Doklad ' . (string) ($document['document_no'] ?? '') . ': zaokrouhlení '
                            . number_format($rounding['amount_cents'] / 100, 2, ',', ' ') . ' Kč je účtováno na '
                            . $rounding['account'] . ' a není další položkou. Před přeúčtováním zkontrolujte účet zaokrouhlení.'];
                }
                sort($accounts, SORT_STRING);
                $needsAllocation = false;
                foreach ($document['items'] ?? [] as $itemIndex => $item) {
                    $explicit = trim((string) ($item[$field] ?? ''));
                    if ($explicit !== '') {
                        // Původní účet položky má přednost; případné přesměrování je
                        // jen cílové pole, aby původní účet dál chránil otisk zdroje.
                        if (isset($accountMapping[$explicit])) {
                            $documentPlan['records'][$part][$index]['items'][$itemIndex]['target_' . $field] = $accountMapping[$explicit];
                        }
                        continue;
                    }
                    if (count($accounts) === 1) {
                        $documentPlan['records'][$part][$index]['items'][$itemIndex]['target_' . $field] = $accounts[0];
                    } elseif (count($accounts) > 1) {
                        $needsAllocation = true;
                    }
                }
                if ($needsAllocation) {
                    $allocation = self::allocateItemAccounts($document, $field, $amounts,
                        $accountMapping, $allocationBudget);
                    if ($allocation !== null) {
                        foreach ($allocation as $itemIndex => $account) {
                            $documentPlan['records'][$part][$index]['items'][$itemIndex]['target_' . $field] = $account;
                        }
                        $needsAllocation = false;
                    } else {
                        $reconstructed = StereoNxRecapAccountItems::reconstruct($document, $amounts, $field);
                        if ($reconstructed !== null) {
                            $documentPlan['records'][$part][$index]['target_items'] = $reconstructed;
                            $documentPlan['warnings'][] = ['level' => 'info', 'code' => 'document_items_reconstructed',
                                'source_key' => $document['source_key'], 'document_no' => (string) ($document['document_no'] ?? ''),
                                'message' => 'Doklad ' . (string) ($document['document_no'] ?? '')
                                    . ' má náhradní účetní položky vytvořené podle kontací deníku (' . implode(', ', $accounts) . ').'];
                            $needsAllocation = false;
                        }
                    }
                }
                if ($needsAllocation) {
                    $documentPlan['warnings'][] = ['level' => 'warning', 'code' => 'document_item_account_ambiguous',
                        'source_key' => $document['source_key'], 'document_no' => (string) ($document['document_no'] ?? ''),
                        'accounts' => $accounts,
                        'message' => 'Doklad ' . (string) ($document['document_no'] ?? '')
                            . ' používá více účtů (' . implode(', ', $accounts)
                            . '); před další úpravou nebo zaúčtováním přiřaďte účty jednotlivým položkám.'];
                }
            }
        }
        return $documentPlan;
    }

    /**
     * Zaokrouhlení hlavičky není zdanitelná položka. Samotná shoda malé částky
     * nestačí: zbytek musí jednoznačně odpovídat položkám a kandidát musí být jediný.
     * Kontace deníku i zaokrouhlení hlavičky zůstávají beze změny. Kontrola účtu
     * při nativním přeúčtování zůstává nutná; core používá vlastní 548/648.
     */
    public static function separateRounding(array $document, string $field, array $amounts, array $postings, array $mapping, int &$budget): ?array
    {
        if (($document['currency_code'] ?? 'CZK') !== 'CZK') return null;
        $rounding = StereoNxRecapAmounts::moneyCents($document['rounding'] ?? 0);
        $base = StereoNxRecapAmounts::moneyCents($document['total_without_vat'] ?? null);
        if ($rounding === null || $rounding === 0 || $base === null || $base === 0) return null;
        $signedRounding = ($document['target_document_kind'] ?? '') === 'credit_note' && $base > 0 ? -$rounding : $rounding;
        $control = $field === 'revenue_account_code' ? '311' : '321';
        $candidates = [];
        foreach ($postings as $posting) {
            if ($posting['control'] === $control && $posting['amount_cents'] === $signedRounding) $candidates[$posting['account']] = true;
        }
        if (count($candidates) > 12) return null;
        $proof = null;
        foreach ($candidates as $account => $_) {
            $remaining = $amounts;
            $remaining[$account] -= $signedRounding;
            $remaining = array_filter($remaining, static fn (int $amount): bool => $amount !== 0);
            if ($remaining === []) continue;
            $uncertain = false;
            $allocation = self::allocateItemAccounts($document, $field, $remaining, $mapping, $budget, $uncertain);
            // Více možných rozpisů ani nedokončené hledání jiného kandidáta
            // nejsou důkazem jeho neplatnosti.
            if ($uncertain) return null;
            if ($allocation === null && StereoNxRecapAccountItems::reconstruct($document, $remaining, $field) === null) continue;
            if ($proof !== null) return null;
            $proof = ['account' => (string) $account, 'amount_cents' => $rounding, 'amounts' => $remaining];
        }
        return $proof;
    }

    /**
     * Explicitní účty mají přednost. Zbytek řešíme v haléřích jako rozdělení částek
     * do souhrnů účtů; nalezení jedné možnosti nestačí, musí být jediná.
     * @return array<int,string>|null
     */
    private static function allocateItemAccounts(array $document, string $field, array $remaining, array $mapping, int &$budget, bool &$uncertain = false): ?array
    {
        $uncertain = false;
        if (count($remaining) > 12 || ($document['currency_code'] ?? 'CZK') !== 'CZK') {
            $uncertain = true;
            return null;
        }
        $amounts = [];
        foreach ($document['items'] ?? [] as $index => $item) {
            $base = StereoNxRecapAmounts::moneyCents($item['total_without_vat'] ?? null);
            if ($base === null) return null;
            if ($field === 'expense_account_code') {
                $deduction = $item['vat_deduction'] ?? 'full';
                // Poměrný odpočet bez úplných podkladů ani cizoměnové zaokrouhlení
                // zde znovu nepočítáme. Patří autoritativní účetní/daňové vrstvě.
                if (!in_array($deduction, ['full', 'reduced', 'none'], true)) return null;
                if ($deduction === 'none') {
                    if (abs((float) ($item['source_self_assessed_vat'] ?? 0)) > 0.004) return null;
                    $base = StereoNxRecapAmounts::moneyCents($item['total_with_vat'] ?? null);
                    if ($base === null) return null;
                }
            }
            // Připravené řádkové totály už zohledňují sazbu i prices_include_vat.
            // Opětovné dělení brutto jednotkové ceny by změnilo zdrojové zaokrouhlení.
            $amounts[$index] = $base;
        }
        if (($document['target_document_kind'] ?? '') === 'credit_note' && array_sum($amounts) > 0) {
            $amounts = array_map(static fn (int $amount): int => -$amount, $amounts);
        }
        $unknown = [];
        foreach ($document['items'] ?? [] as $index => $item) {
            $explicit = trim((string) ($item[$field] ?? ''));
            if ($explicit !== '') {
                $target = ImportedAccountCode::target($explicit, $mapping);
                if (!array_key_exists($target, $remaining)) return null;
                $remaining[$target] -= $amounts[$index];
            } else {
                $unknown[] = ['index' => $index, 'amount' => $amounts[$index]];
            }
        }
        if (count($unknown) > 24) {
            $uncertain = true;
            return null;
        }
        if (array_sum($remaining) !== array_sum(array_column($unknown, 'amount'))) return null;
        usort($unknown, static fn (array $a, array $b): int => abs($b['amount']) <=> abs($a['amount']) ?: $a['index'] <=> $b['index']);
        $positive = $negative = [count($unknown) => 0];
        for ($i = count($unknown) - 1; $i >= 0; $i--) {
            $positive[$i] = $positive[$i + 1] + max(0, $unknown[$i]['amount']);
            $negative[$i] = $negative[$i + 1] + min(0, $unknown[$i]['amount']);
        }
        $solutions = 0;
        $solution = null;
        $states = 0;
        $exhausted = false;
        $search = static function (int $offset, array $left, array $assignment) use (
            &$search, &$solutions, &$solution, &$states, &$exhausted, &$budget, $unknown, $positive, $negative,
        ): void {
            if ($exhausted || $solutions > 1) return;
            if (++$states > 50000 || --$budget < 0) { $exhausted = true; return; }
            foreach ($left as $amount) {
                if ($amount < $negative[$offset] || $amount > $positive[$offset]) return;
            }
            if ($offset === count($unknown)) {
                $solutions++;
                $solution ??= $assignment;
                return;
            }
            $item = $unknown[$offset];
            $accounts = array_map('strval', array_keys($left));
            // Přesná shoda má přednost při hledání; další větve se přesto prověří,
            // protože shodnou částku může dát i jiná skupina položek.
            usort($accounts, static fn (string $a, string $b): int => ($left[$b] === $item['amount']) <=> ($left[$a] === $item['amount']) ?: strcmp($a, $b));
            foreach ($accounts as $account) {
                $next = $left;
                $next[$account] -= $item['amount'];
                $search($offset + 1, $next, $assignment + [$item['index'] => $account]);
                if ($exhausted || $solutions > 1) return;
            }
        };
        $search(0, $remaining, []);
        $uncertain = $exhausted || $solutions > 1;
        return !$exhausted && $solutions === 1 ? $solution : null;
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    public function write(array $plan, int $supplierId, int $userId): array
    {
        $source = ['identity' => $plan['identity'] ?? [],
            'source_company_index' => $plan['source_company_index'] ?? null,
            'clients' => $plan['records']['clients'] ?? [], 'issued' => $plan['records']['issued'] ?? [],
            'purchases' => $plan['records']['purchases'] ?? []];
        return $this->importer->writeAccountingDocuments($source, $supplierId, $userId);
    }
}
