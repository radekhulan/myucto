<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Migration\Shared\BankSymbols;

/**
 * Fyzická banka a pokladna účetní firmy; účetní deník se převádí samostatně.
 *
 * Stereo čísluje výpisy a pokladní doklady v rámci účetního roku, proto rok v identitě
 * výpisu a pohybu je účetní období firmy (kalendářní, nebo hospodářský rok). Stejný rok
 * použije {@see StereoNxMovementJournalLinks} pro kontace deníku, jinak by se v hospodářském
 * roce stejná čísla dokladů ze dvou období slila do jednoho pohybu.
 */
final class StereoNxAccountingPayments
{
    public function __construct(private readonly StereoNxImporter $importer) {}

    /** @return array<string,mixed> */
    public function prepare(StereoNxBackup $backup, ?FiscalCalendar $calendar = null): array
    {
        $backup->assertBankAccountSeriesReady();
        $calendar ??= FiscalCalendar::calendar();
        $available = array_flip($backup->tableNames());
        $read = static function (string $name) use ($backup, $available): array {
            return isset($available[$name]) ? iterator_to_array($backup->rows($name), false) : [];
        };
        $accountsBySeries = [];
        foreach ($read('LFirmaUc') as $row) {
            if (trim((string) ($row['DoklRada'] ?? '')) === '') continue;
            $series = self::required($row, 'DoklRada');
            if (isset($accountsBySeries[$series])) throw new StereoNxException('bank_account_duplicate', 'Bankovní řada nemá jednoznačný účet.');
            $accountsBySeries[$series] = [
                'source_key' => $series, 'account_number' => trim((string) ($row['BaUcet'] ?? '')),
                'bank_code' => trim((string) ($row['KodBanky'] ?? '')),
                'iban' => trim((string) ($row['IBAN'] ?? '')),
                'label' => trim((string) ($row['NazevUctu'] ?? '')) ?: 'Stereo NX', 'currency' => 'CZK',
            ];
        }

        $warnings = [];
        $statements = [];
        $statementByPhysical = [];
        $sourceDates = [];
        $usedAccounts = [];
        $skippedStatements = 0;
        $skippedAccountStatements = 0;
        foreach ($read('CBanka') as $row) {
            $series = trim((string) ($row['DoklRada'] ?? ''));
            $statementDate = self::date($row, 'KdyVystaveno');
            $sourceDates[] = $statementDate;
            if (!isset($accountsBySeries[$series])) {
                $skippedAccountStatements++;
                continue;
            }
            $year = (string) $calendar->fiscalYearOfDate($statementDate);
            $physical = self::key([$year, $series, self::required($row, 'DoklCislo')]);
            $key = self::key([$year, $series, self::required($row, 'DoklCislo')]);
            if (isset($statementByPhysical[$physical])) {
                throw new StereoNxException('bank_statement_identity', 'Bankovní výpis nemá jednoznačnou identitu.');
            }
            if (!self::domesticRate($row)) {
                $statementByPhysical[$physical] = null;
                $skippedStatements++;
                continue;
            }
            $statements[$key] = ['source_key' => $key, 'document_no' => self::required($row, 'Doklad'),
                'date' => self::date($row, 'KdyVystaveno'), 'account_key' => $series, 'currency' => 'CZK'];
            $statementByPhysical[$physical] = $key;
            $usedAccounts[$series] = $accountsBySeries[$series];
        }

        $receivables = [];
        foreach ($read('Cpz') as $row) {
            $bare = self::documentKey($row);
            if (isset($receivables[$bare])) throw new StereoNxException('receivable_identity', 'Saldokonto má duplicitní identitu.');
            $receivables[$bare] = $row;
        }
        $controls = [];
        foreach ($read('CPZZ') as $row) {
            $bare = self::documentKey($row);
            if (isset($controls[$bare])) throw new StereoNxException('receivable_control', 'Kontrolní evidence pohledávek a závazků je duplicitní.');
            $controls[$bare] = $row;
        }

        $bank = [];
        $cash = [];
        $linked = [];
        $excludedPaymentGroups = [];
        $reviews = [];
        $skippedBank = 0;
        $skippedBankCurrency = 0;
        $skippedBankAccount = 0;
        foreach ($read('CBankap') as $row) {
            $movementDate = self::date($row, 'KdyUcPripad');
            $sourceDates[] = $movementDate;
            $series = trim((string) ($row['DoklRada'] ?? ''));
            if (!isset($accountsBySeries[$series])) {
                if (($bare = self::optionalDocumentKey($row)) !== null) $excludedPaymentGroups[$bare] = true;
                $skippedBankAccount++;
                $skippedBank++;
                continue;
            }
            $physical = self::key([(string) $calendar->fiscalYearOfDate($movementDate),
                self::required($row, 'DoklRada'), self::required($row, 'DoklCislo')]);
            $statementKey = $statementByPhysical[$physical] ?? null;
            if ($statementKey === null) {
                if (($bare = self::optionalDocumentKey($row)) !== null) $excludedPaymentGroups[$bare] = true;
                $skippedBankCurrency++;
                $skippedBank++;
                continue;
            }
            if (!self::domesticMovement($row)) {
                if (($bare = self::optionalDocumentKey($row)) !== null) $excludedPaymentGroups[$bare] = true;
                $skippedBankCurrency++;
                $skippedBank++;
                continue;
            }
            $key = self::movementKey($row, true, $calendar);
            $movement = self::movement($row, $key);
            [$movement['variable_symbol'], $movement['description']] = BankSymbols::variableSymbolAndDescription(
                $movement['variable_symbol'], $movement['description'], false,
            );
            $movement += [
                'statement_key' => $statementKey,
                'counterparty_account' => trim((string) ($row['BaUcet'] ?? '')),
                'counterparty_bank' => trim((string) ($row['KodBanky'] ?? '')),
                'counterparty_name' => trim((string) ($row['Firma'] ?? '')),
                'constant_symbol' => trim((string) ($row['KonSym'] ?? '')),
                'specific_symbol' => trim((string) ($row['SpecSym'] ?? '')),
                'document_no' => self::first($row, ['DokladS', 'Doklad']),
            ];
            $bank[$key] = $movement;
            self::collectLink($linked, 'bank', $key, $row);
            if (self::vatCents($row) !== 0 && self::optionalDocumentKey($row) === null) {
                $reviews['bank:' . $key] = ['kind' => 'bank', 'source_key' => $key,
                    'statement_key' => $statementKey, 'document_no' => $movement['document_no'],
                    'review_codes' => ['movement_vat_unverified'], 'target_id' => null, 'statement_id' => null];
            }
        }
        $skippedCash = 0;
        $skippedZeroCash = 0;
        $nonVatCashCandidates = [];
        $cashOpeningCandidates = [];
        $companyRows = $read('LFirma');
        $openingKind = count($companyRows) === 1 ? trim((string) ($companyRows[0]['DruhPS'] ?? '')) : '';
        foreach ($read('CPokl') as $row) {
            $sourceDates[] = self::date($row, 'KdyUcPripad');
            if (self::cents($row['Castka'] ?? null) === 0) {
                $key = self::movementKey($row, false, $calendar);
                $reviews['cash-zero:' . $key] = ['kind' => 'cash', 'source_key' => $key,
                    'document_no' => self::first($row, ['Doklad', 'DokladS']),
                    'review_codes' => ['zero_cash_opening'], 'target_id' => null];
                $skippedZeroCash++;
                continue;
            }
            if (!self::domesticMovement($row)) {
                if (($bare = self::optionalDocumentKey($row)) !== null) $excludedPaymentGroups[$bare] = true;
                $key = self::movementKey($row, false, $calendar);
                $reviews['cash-skipped:' . $key] = ['kind' => 'cash', 'source_key' => $key,
                    'document_no' => self::first($row, ['Doklad', 'DokladS']),
                    'review_codes' => ['cash_currency_unresolved'], 'target_id' => null];
                $skippedCash++;
                continue;
            }
            $key = self::movementKey($row, false, $calendar);
            $reviewCode = self::hasCashTaxAmounts($row) && self::optionalDocumentKey($row) === null
                ? 'movement_vat_unverified' : 'pending_reconciliation';
            $cash[$key] = self::movement($row, $key) + [
                'document_no' => self::required($row, 'Doklad'), 'requires_draft' => true,
                'review_codes' => [$reviewCode],
            ];
            if (self::explicitNonVatCash($row)) $nonVatCashCandidates[$key] = true;
            if (self::explicitOpeningCash($row, $openingKind)) $cashOpeningCandidates[$key] = true;
            self::collectLink($linked, 'cash', $key, $row);
        }

        $payments = [];
        $headers = [];
        foreach (['VF' => 'Svfh', 'PF' => 'SPFH'] as $agenda => $table) {
            foreach ($read($table) as $header) {
                $bare = self::documentKey($header);
                $headers[$agenda][$bare] = array_key_exists($bare, $headers[$agenda] ?? []) ? null : $header;
            }
        }
        $groups = [];
        foreach ($linked as $candidate) $groups[$candidate['bare']][] = $candidate;
        $verifiedGroups = [];
        foreach ($groups as $bare => $candidates) {
            if (isset($excludedPaymentGroups[$bare])) continue;
            $source = $receivables[$bare] ?? null;
            $control = $controls[$bare] ?? null;
            $agenda = strtoupper(trim((string) ($source['Agenda'] ?? '')));
            $header = $headers[$agenda][$bare] ?? null;
            $direction = $source['SmerPlatby'] ?? null;
            if (!is_array($source) || !is_array($control) || !is_array($header)
                || !in_array($agenda, ['VF', 'PF'], true)
                || !in_array($direction, ['P', 'V'], true)
                || ($control['SmerPlatby'] ?? null) !== $direction
                || ($header['SmerPlatby'] ?? ($agenda === 'VF' ? 'P' : 'V')) !== $direction
                || !in_array(trim((string) ($source['Mena'] ?? '')), ['Kč', 'CZK'], true)
                || !in_array(trim((string) ($control['Mena'] ?? '')), ['Kč', 'CZK'], true)
                || !in_array(trim((string) ($header['Mena'] ?? '')), ['Kč', 'CZK'], true)) continue;
            try {
                $documentTotal = self::cents($header['Celkem'] ?? null);
                $paid = self::cents($source['Uhrazeno'] ?? null);
                if ($documentTotal === 0 || self::cents($source['Celkem'] ?? null) !== $documentTotal
                    || $calendar->fiscalYearOfDate(self::date($source, 'KdyVystaveno'))
                        !== $calendar->fiscalYearOfDate(self::date($header, 'KdyVyhotUD'))) continue;
            } catch (StereoNxException) {
                continue;
            }
            // Cílové zůstatky měří úhradu vůči absolutní hodnotě dokladu: záporný
            // dobropis se splácí kladně, vratka úhrady běžné faktury záporně.
            $sign = $documentTotal < 0 ? -1 : 1;
            $total = 0;
            foreach ($candidates as $candidate) {
                // Stejně jako StereoNxPaymentReconciliation ověřujeme směr celé skupiny
                // a čistý součet. Cash a invoice_payments neumí záporné úhrady.
                if ($candidate['direction'] !== $direction || ($candidate['amount_cents'] * $sign < 0
                    && ($candidate['type'] === 'cash' || $agenda !== 'PF'))) continue 2;
                $total += $candidate['amount_cents'];
            }
            if ($total === $paid) $verifiedGroups[$bare] = $sign;
        }
        foreach ($linked as $candidate) {
            $bare = $candidate['bare'];
            $source = $receivables[$bare] ?? null;
            $agenda = strtoupper(trim((string) ($source['Agenda'] ?? '')));
            if (!isset($verifiedGroups[$bare])) {
                $reason = is_array($source) && $agenda !== '' && !in_array($agenda, ['VF', 'PF'], true)
                    ? 'payment_agenda_not_imported' : 'pending_reconciliation';
                $reviews[$candidate['type'] . ':' . $candidate['key']] = ['kind' => $candidate['type'],
                    'source_key' => $candidate['key'],
                    'statement_key' => $candidate['type'] === 'bank' ? ($bank[$candidate['key']]['statement_key'] ?? null) : null,
                    'document_no' => $candidate['type'] === 'bank'
                        ? ($bank[$candidate['key']]['document_no'] ?? '') : ($cash[$candidate['key']]['document_no'] ?? ''),
                    'review_codes' => [$reason], 'target_id' => null, 'statement_id' => null];
                if ($candidate['type'] === 'cash') $cash[$candidate['key']]['review_codes'] = [$reason];
                continue;
            }
            // Musí se shodovat s identitou dokladu ze StereoNxSourcePlan se stejným kalendářem.
            $docKey = self::key([(string) $calendar->fiscalYearOfDate(self::date($source, 'KdyVystaveno')),
                self::required($source, 'DoklSRada'), self::required($source, 'DoklSCislo')]);
            $kind = $agenda === 'VF' ? 'issued' : 'purchase';
            $payments[] = ['movement_key' => $candidate['key'], 'movement_type' => $candidate['type'],
                'document_key' => $docKey, 'document_kind' => $kind,
                'amount' => $candidate['amount_cents'] * $verifiedGroups[$bare] / 100];
            if ($candidate['type'] === 'cash') {
                $cash[$candidate['key']]['requires_draft'] = false;
                $cash[$candidate['key']]['review_codes'] = [];
            }
        }
        foreach ($cash as $key => $record) {
            if (($record['requires_draft'] ?? false) === true) {
                $reviews['cash:' . $key] = ['kind' => 'cash', 'source_key' => $key,
                    'document_no' => (string) ($record['document_no'] ?? ''),
                    'review_codes' => array_values(array_unique($record['review_codes'])), 'target_id' => null];
            }
        }
        if ($skippedStatements > 0 || $skippedBankCurrency > 0) {
            $warnings[] = ['level' => 'warning', 'code' => 'bank_currency_unresolved',
                'message' => 'Cizoměnové bankovní záznamy bez kódu měny nebyly převedeny.',
                'count' => $skippedStatements + $skippedBankCurrency];
        }
        if ($skippedAccountStatements > 0 || $skippedBankAccount > 0) {
            $warnings[] = ['level' => 'warning', 'code' => 'bank_account_unresolved',
                'message' => 'Bankovní výpisy a pohyby bez jednoznačné vazby na vlastní účet nebyly převedeny.',
                'count' => $skippedAccountStatements + $skippedBankAccount];
        }
        if ($skippedCash > 0) {
            $warnings[] = ['level' => 'warning', 'code' => 'cash_currency_unresolved',
                'message' => 'Pokladní záznamy bez ověřené měny nebyly převedeny.', 'count' => $skippedCash];
        }
        if ($skippedZeroCash > 0) {
            $warnings[] = ['level' => 'warning', 'code' => 'zero_cash_opening',
                'message' => 'Nulový počáteční záznam pokladny nevyvolal peněžní pohyb.', 'count' => $skippedZeroCash];
        }
        if ($reviews !== []) {
            $warnings[] = ['level' => 'warning', 'code' => 'pending_reconciliation',
                'message' => 'Neověřené pohyby zůstávají ke kontrole a nejsou spárovány s dokladem.',
                'count' => count($reviews)];
        }
        return ['identity' => $backup->companyIdentity(), 'source_company_index' => $backup->companyIndex(),
            'dates' => array_values(array_unique($sourceDates)),
            'counts' => ['bank_accounts' => count($usedAccounts), 'bank_statements' => count($statements),
                'bank_transactions' => count($bank), 'cash_transactions' => count($cash), 'payments' => count($payments),
                'skipped_bank_statements' => $skippedStatements, 'skipped_bank_transactions' => $skippedBank,
                'skipped_bank_account_statements' => $skippedAccountStatements,
                'skipped_cash_transactions' => $skippedCash,
                'skipped_zero_cash' => $skippedZeroCash,
                'requires_review' => count($reviews)],
            'warnings' => $warnings, 'errors' => [],
            'non_vat_cash_candidates' => $nonVatCashCandidates,
            'cash_opening_candidates' => $cashOpeningCandidates,
            'records' => ['bank_accounts' => array_values($usedAccounts), 'bank_statements' => array_values($statements),
                'bank_transactions' => array_values($bank), 'cash_transactions' => array_values($cash),
                'payments' => $payments, 'reviews' => array_values($reviews)]];
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $journalPlan @return array<string,mixed> */
    public function resolveNonVatCash(array $plan, array $journalPlan): array
    {
        $candidates = (array) ($plan['non_vat_cash_candidates'] ?? []);
        if ($candidates === [] || !isset($plan['records']['cash_transactions'])) return $plan;
        $entries = StereoNxMovementJournalLinks::indexMovementEntries($journalPlan);
        $resolved = [];
        foreach ($plan['records']['cash_transactions'] as &$movement) {
            $key = (string) ($movement['source_key'] ?? '');
            if (!isset($candidates[$key]) || ($movement['requires_draft'] ?? null) !== true
                || ($movement['review_codes'] ?? null) !== ['pending_reconciliation']) continue;
            if (!StereoNxMovementJournalLinks::verifiesNonVatCash($movement, $entries['cash'][$key] ?? [])) continue;
            $movement['requires_draft'] = false;
            $movement['review_codes'] = [];
            $movement['verified_non_vat_cash'] = true;
            $resolved[$key] = true;
        }
        unset($movement);
        if ($resolved === []) return $plan;
        $plan['records']['reviews'] = array_values(array_filter($plan['records']['reviews'] ?? [],
            static fn (array $review): bool => ($review['kind'] ?? '') !== 'cash'
                || !isset($resolved[(string) ($review['source_key'] ?? '')])));
        $plan['counts']['requires_review'] = count($plan['records']['reviews']);
        $plan['warnings'] ??= [];
        foreach ($plan['warnings'] as $index => &$warning) {
            if (($warning['code'] ?? '') !== 'pending_reconciliation') continue;
            if ($plan['counts']['requires_review'] === 0) unset($plan['warnings'][$index]);
            else $warning['count'] = $plan['counts']['requires_review'];
        }
        unset($warning);
        $plan['warnings'] = array_values($plan['warnings']);
        return $plan;
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    public function write(array $plan, int $supplierId, int $userId): array
    {
        return $this->importer->writeAccountingPayments([
            'identity' => $plan['identity'] ?? [], 'source_company_index' => $plan['source_company_index'] ?? null,
            ...(array) ($plan['records'] ?? []),
            'documents' => $plan['documents'] ?? [], 'journal_plan' => $plan['journal_plan'] ?? [],
        ], $supplierId, $userId);
    }

    /** @param array<string,mixed> $row */
    private static function movement(array $row, string $key): array
    {
        $direction = $row['SmerPlatby'] ?? null;
        if (!in_array($direction, ['P', 'V'], true)) throw new StereoNxException('movement_direction', 'Neznámý směr peněžního pohybu.');
        $amount = self::cents($row['Castka'] ?? null) / 100;
        if ($amount == 0.0) throw new StereoNxException('movement_amount', 'Peněžní pohyb má nulovou zdrojovou částku.');
        // Záporný výdaj je vratka výdaje; znaménko zdroje se nesmí zahodit.
        return ['source_key' => $key, 'amount' => $direction === 'P' ? $amount : -$amount,
            'date' => self::date($row, 'KdyUcPripad'), 'description' => trim((string) ($row['Text'] ?? '')),
            'variable_symbol' => trim((string) ($row['VarSym'] ?? '')), 'currency' => 'CZK'];
    }

    /** @param array<string,list<array<string,mixed>>> $linked @param array<string,mixed> $row */
    private static function collectLink(array &$linked, string $type, string $key, array $row): void
    {
        $bare = self::optionalDocumentKey($row);
        if ($bare === null) return;
        $linked[] = ['type' => $type, 'key' => $key, 'bare' => $bare,
            'amount_cents' => self::cents($row['Castka'] ?? null), 'direction' => $row['SmerPlatby'] ?? null];
    }

    /** @param array<string,mixed> $row */
    private static function domesticRate(array $row): bool
    {
        $rate = (float) ($row['Kurz'] ?? 0); $units = (float) ($row['KurzMn'] ?? 0);
        return ($rate === 0.0 || ($rate === 1.0 && in_array($units, [0.0, 1.0], true)));
    }

    /** @param array<string,mixed> $row */
    private static function domesticMovement(array $row): bool
    {
        if (!self::domesticRate($row) || trim((string) ($row['MenaCizi'] ?? '')) !== '') return false;
        if ((!is_int($row['Castka'] ?? null) && !is_float($row['Castka'] ?? null))
            || (!is_int($row['CastkaVlastni'] ?? null) && !is_float($row['CastkaVlastni'] ?? null))) return false;
        $amount = self::cents($row['Castka'] ?? null);
        $own = self::cents($row['CastkaVlastni'] ?? null);
        return $amount === $own;
    }

    /** @param array<string,mixed> $row */
    private static function movementKey(array $row, bool $line, FiscalCalendar $calendar): string
    {
        $parts = [(string) $calendar->fiscalYearOfDate(self::date($row, 'KdyUcPripad')),
            self::required($row, 'DoklRada'), self::required($row, 'DoklCislo')];
        if ($line) $parts[] = self::required($row, 'Klic');
        return self::key($parts);
    }

    /** @param array<string,mixed> $row */
    private static function documentKey(array $row): string
    {
        return self::key([self::required($row, 'DoklSRada'), self::required($row, 'DoklSCislo')]);
    }

    /** @param array<string,mixed> $row */
    private static function optionalDocumentKey(array $row): ?string
    {
        $series = trim((string) ($row['DoklSRada'] ?? '')); $number = trim((string) ($row['DoklSCislo'] ?? ''));
        if ($series === '' && ($number === '' || $number === '0')) return null;
        if ($series === '' || $number === '' || $number === '0') throw new StereoNxException('payment_link_incomplete', 'Neúplná vazba úhrady.');
        return self::key([$series, $number]);
    }

    /** @param array<string,mixed> $row */
    private static function required(array $row, string $field): string
    {
        $value = trim((string) ($row[$field] ?? ''));
        if ($value === '' || strlen($value) > 180) throw new StereoNxException('source_identity_invalid', 'Chybí zdrojová identita peněžního záznamu.');
        return $value;
    }

    /** @param array<string,mixed> $row @param list<string> $fields */
    private static function first(array $row, array $fields): string
    {
        foreach ($fields as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') return $value;
        }
        return '';
    }

    /** @param list<string> $parts */
    private static function key(array $parts): string
    {
        $key = json_encode($parts, JSON_THROW_ON_ERROR);
        if (strlen($key) > 190) throw new StereoNxException('source_key_too_long', 'Zdrojová identita je příliš dlouhá.');
        return $key;
    }

    /** @param array<string,mixed> $row */
    private static function date(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) throw new StereoNxException('source_date_invalid', 'Peněžní záznam má neplatné datum.');
        return $value;
    }

    private static function cents(mixed $value): int
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || abs((float) $value) > 1.0e12) {
            throw new StereoNxException('source_amount_invalid', 'Peněžní záznam má neplatnou částku.');
        }
        return (int) round((float) $value * 100, 0, PHP_ROUND_HALF_UP);
    }

    /** @param array<string,mixed> $row */
    private static function vatCents(array $row): int
    {
        return self::cents($row['DPHz'] ?? 0.0) + self::cents($row['DPHs'] ?? 0.0) + self::cents($row['DPHt'] ?? 0.0);
    }

    /** @param array<string,mixed> $row */
    private static function explicitNonVatCash(array $row): bool
    {
        if (($row['ZpracovatDPH'] ?? null) !== false
            || !is_string($row['TypDPH'] ?? null) || trim($row['TypDPH']) !== ''
            || !is_string($row['DoklSRada'] ?? null) || trim($row['DoklSRada']) !== ''
            || !is_string($row['DoklSCislo'] ?? null) || trim($row['DoklSCislo']) !== '') return false;
        foreach (['ZaklDPHz', 'DPHz', 'ZaklDPHs', 'DPHs', 'ZaklDPHt', 'DPHt', 'BezDane'] as $field) {
            $value = $row[$field] ?? null;
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)
                || self::cents($value) !== 0) return false;
        }
        return true;
    }

    /**
     * Počáteční stav může mít nepoužitá pole NULL. Výjimka platí jen pro výslovný DruhPS;
     * planner navíc vyžaduje přesnou otevírací kontaci. Obecné explicitNonVatCash zůstává přísné.
     */
    private static function explicitOpeningCash(array $row, string $openingKind): bool
    {
        if ($openingKind === '' || trim((string) ($row['Druh'] ?? '')) !== $openingKind
            || ($row['ZpracovatDPH'] ?? null) !== false) return false;
        foreach (['TypDPH', 'DoklSRada', 'DoklSCislo'] as $field) {
            if (!array_key_exists($field, $row)) return false;
            $value = $row[$field];
            if ($value !== null && (!is_string($value) || trim($value) !== '')) return false;
        }
        foreach (['ZaklDPHz', 'DPHz', 'ZaklDPHs', 'DPHs', 'ZaklDPHt', 'DPHt', 'BezDane'] as $field) {
            if (!array_key_exists($field, $row)) return false;
            $value = $row[$field];
            if ($value !== null && ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)
                || self::cents($value) !== 0)) return false;
        }
        return true;
    }

    /** @param array<string,mixed> $row */
    private static function hasCashTaxAmounts(array $row): bool
    {
        foreach (['ZaklDPHz', 'DPHz', 'ZaklDPHs', 'DPHs', 'ZaklDPHt', 'DPHt', 'BezDane'] as $field) {
            $value = $row[$field] ?? null;
            if ((is_int($value) || is_float($value)) && is_finite((float) $value)
                && self::cents($value) !== 0) return true;
        }
        return false;
    }
}
