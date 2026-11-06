<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Shared\ChartAccountCreator;
use MyInvoice\Service\Migration\Shared\FiscalYearMismatchException;
use MyInvoice\Service\Migration\Shared\ImportedAccountCode;
use MyInvoice\Service\Migration\Shared\MigrationPeriods;
use PDO;

/**
 * Autoritativní kopie účtové osnovy a účetního deníku Stereo NX.
 *
 * Jeden řádek Cdenik je jedna vyrovnaná kontace. Doklady importované jinými
 * částmi převodu se znovu automaticky nezaúčtují: zdrojem účetní pravdy je zde
 * Cdenik. Zápisy DruhPS se přenesou jako opening a zůstatky Lrozvrh se proto
 * samostatně neseedují.
 */
final class StereoNxAccountingWriter
{
    private const MAP_ACCOUNT = 'accounting_account';
    private const MAP_ENTRY = 'accounting_journal';

    public function __construct(
        private readonly Connection $db,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly ChartOfAccountsSeeder $seeder,
        private readonly AccountingPeriodRepository $periods,
        private readonly JournalEntryRepository $journal,
        private readonly StereoNxImportMap $map,
    ) {}

    /** Kalendář účetního roku cílové firmy; podle něj se deník řadí do období. */
    public function calendar(int $supplierId): FiscalCalendar
    {
        return (new MigrationPeriods($this->periods))->calendar($supplierId);
    }

    /**
     * @return array{
     *   ok:bool,identity:array{ico:string,dic:string,name:string,vat_payer:bool},company_index:int,
     *   errors:list<array<string,mixed>>,warnings:list<array<string,mixed>>,
     *   counts:array{accounts:int,journal_entries:int,opening_entries:int},
     *   date_bounds:array{from:?string,to:?string},
     *   accounting_plan:array{chart:list<array<string,mixed>>,entries:list<array<string,mixed>>}
     * }
     */
    public function prepare(StereoNxBackup $backup, ?FiscalCalendar $calendar = null): array
    {
        $identity = $backup->companyIdentity();
        $chartRows = iterator_to_array($backup->rows('Lrozvrh'), false);
        $journalRows = iterator_to_array($backup->rows('Cdenik'), false);
        $companyRows = iterator_to_array($backup->rows('LFirma'), false);
        $errors = [];

        if (count($companyRows) !== 1) {
            $errors[] = ['code' => 'accounting_company_settings_invalid'];
            $settings = [];
        } else {
            $settings = $companyRows[0];
            if (($settings['NeniUcetniJednotkou'] ?? null) !== false) {
                $errors[] = ['code' => 'source_not_accounting_entity'];
            }
        }

        $openingKind = trim((string) ($settings['DruhPS'] ?? ''));
        $openingAccount = trim((string) ($settings['UcetPocatecniRozvazny'] ?? ''));
        if ($openingKind === '' || $openingAccount === '') {
            $errors[] = ['code' => 'opening_settings_missing'];
        }

        $journalPlan = StereoNxAccountingJournalPlan::build($journalRows, $chartRows, $calendar);
        array_push($errors, ...$journalPlan['blockers']);

        $chart = [];
        foreach ($chartRows as $index => $row) {
            $code = trim((string) ($row['Ucet'] ?? ''));
            $sourceType = trim((string) ($row['TypUctu'] ?? ''));
            if (!in_array($sourceType, ['A', 'P', 'R', 'N', 'V', 'Z', 'O'], true)) {
                $errors[] = ['code' => 'chart_account_type_unknown', 'account' => $code, 'row' => $index];
            }
            if (!is_bool($row['Danovy'] ?? null)) {
                $errors[] = ['code' => 'chart_tax_flag_invalid', 'account' => $code, 'row' => $index];
            }
            $chart[] = [
                'code' => $code,
                'name' => trim((string) ($row['Nazev'] ?? '')),
                'source_type' => $sourceType,
                'tax_deductible' => ($row['Danovy'] ?? null) === true,
                'source_hash' => StereoNxImportMap::fingerprint([
                    'Ucet' => $code,
                    'Nazev' => trim((string) ($row['Nazev'] ?? '')),
                    'TypUctu' => $sourceType,
                    'Danovy' => $row['Danovy'] ?? null,
                ]),
            ];
        }

        $rawByKey = [];
        foreach ($journalRows as $row) {
            $key = self::entryKey($row);
            if ($key !== null) {
                $rawByKey[$key] = $row;
            }
        }
        $entries = [];
        $openingCount = 0;
        foreach ($journalPlan['entries'] as $entry) {
            $raw = $rawByKey[$entry['source_key']] ?? [];
            $isOpening = trim((string) ($raw['Druh'] ?? '')) === $openingKind;
            if ($isOpening) {
                $openingCount++;
                if ($entry['debit'] !== $openingAccount && $entry['credit'] !== $openingAccount) {
                    $errors[] = ['code' => 'opening_account_mismatch', 'key' => $entry['source_key']];
                }
            }
            if ($entry['amount_cents'] === 0) {
                $errors[] = ['code' => 'journal_amount_zero', 'key' => $entry['source_key']];
            }
            foreach (['Stredisko', 'Vykon', 'Zakazka'] as $dimension) {
                if (mb_strlen(trim((string) ($raw[$dimension] ?? ''))) > 50) {
                    $errors[] = ['code' => 'journal_dimension_too_long', 'key' => $entry['source_key'], 'dimension' => $dimension];
                }
            }
            $record = [
                'source_key' => $entry['source_key'],
                'date' => $entry['date'],
                'source_year' => $entry['source_year'],
                'posting_year' => $entry['posting_year'],
                'debit' => $entry['debit'],
                'credit' => $entry['credit'],
                'amount_cents' => abs((int) $entry['amount_cents']),
                'is_red_storno' => (int) $entry['amount_cents'] < 0,
                'document_no' => trim((string) ($raw['Doklad'] ?? '')),
                'description' => trim((string) ($raw['Text'] ?? '')),
                'cost_center' => trim((string) ($raw['Stredisko'] ?? '')),
                'performance' => trim((string) ($raw['Vykon'] ?? '')),
                'job' => trim((string) ($raw['Zakazka'] ?? '')),
                'is_opening' => $isOpening,
            ];
            $record['source_hash'] = StereoNxImportMap::fingerprint($record);
            $entries[] = $record;
        }

        if ($calendar !== null) {
            try {
                self::assertSourceYears($calendar, $entries);
            } catch (FiscalYearMismatchException $e) {
                $errors[] = ['code' => 'fiscal_year_mismatch', 'message' => $e->getMessage()];
            }
        }

        $reportedErrors = self::summarizeIssues($errors, 'error');
        $reportedWarnings = self::summarizeIssues($journalPlan['warnings'], 'warning');

        return [
            'ok' => $reportedErrors === [],
            'identity' => $identity,
            'company_index' => $backup->companyIndex(),
            'errors' => $reportedErrors,
            'warnings' => $reportedWarnings,
            'counts' => [
                'accounts' => count($chart),
                'journal_entries' => count($entries),
                'opening_entries' => $openingCount,
            ],
            'date_bounds' => [
                'from' => $journalPlan['summary']['date_from'],
                'to' => $journalPlan['summary']['date_to'],
            ],
            'accounting_plan' => ['chart' => $chart, 'entries' => $entries],
        ];
    }

    /**
     * Volající musí držet vnější transakci; metoda ji nikdy necommitne.
     *
     * @param array<string,mixed> $prepared výsledek prepare()
     * @return array{accounts_created:int,accounts_existing:int,periods_created:int,journal_entries_created:int,journal_entries_existing:int,journal_lines:int}
     */
    public function write(array $prepared, int $supplierId, int $userId): array
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new StereoNxException('transaction_required', 'Převod účetnictví musí proběhnout v transakci.');
        }
        if (($prepared['ok'] ?? false) !== true) {
            throw new StereoNxException('accounting_source_invalid', 'Zdrojové účetnictví obsahuje chyby, které brání převodu.');
        }
        $identity = $prepared['identity'] ?? null;
        $companyIndex = $prepared['company_index'] ?? null;
        $plan = $prepared['accounting_plan'] ?? null;
        if (!is_array($identity) || !is_int($companyIndex) || !is_array($plan)) {
            throw new StereoNxException('accounting_plan_invalid', 'Plán převodu účetnictví není platný.');
        }

        $supplier = $pdo->prepare('SELECT ic, accounting_mode FROM supplier WHERE id = ? FOR UPDATE');
        $supplier->execute([$supplierId]);
        $target = $supplier->fetch(PDO::FETCH_ASSOC);
        if ($target === false) {
            throw new StereoNxException('supplier_missing', 'Cílová firma neexistuje.');
        }
        if ((string) $target['ic'] !== (string) ($identity['ico'] ?? '')) {
            throw new StereoNxException('ico_mismatch', 'IČO cílové firmy se neshoduje se zálohou.');
        }
        if ((string) $target['accounting_mode'] !== 'double_entry') {
            throw new StereoNxException('accounting_mode_mismatch', 'Cílová firma nevede podvojné účetnictví.');
        }

        $stats = [
            'accounts_created' => 0,
            'accounts_existing' => 0,
            'periods_created' => 0,
            'journal_entries_created' => 0,
            'journal_entries_existing' => 0,
            'journal_lines' => 0,
        ];
        if ($this->accounts->count($supplierId) === 0) {
            $firstDate = $prepared['date_bounds']['from'] ?? null;
            $seedDate = null;
            // Bez datovaných záznamů se osnova zakládá k dnešku jako před historií rámců.
            if ($firstDate !== null) {
                $calendar = $this->calendar($supplierId);
                $year = $calendar->fiscalYearOfDate($firstDate);
                $seedDate = $this->periods->findByYear($supplierId, $year)['starts_on'] ?? $calendar->periodStart($year);
            }
            $this->seeder->seedForSupplier($supplierId, $seedDate);
        }

        $accountIds = $this->writeChart(
            $plan['chart'] ?? [],
            $supplierId,
            (string) $identity['ico'],
            $companyIndex,
            $stats,
            $prepared['account_mapping']['accounts'] ?? [],
            $prepared['account_mapping_names'] ?? [],
        );
        $targetIds = $this->targetAccountIds($plan, $prepared['account_mapping_names'] ?? [], $supplierId, $stats, $prepared['account_mapping'] ?? []);
        $periods = $this->preparePeriods(
            $plan['entries'] ?? [],
            $supplierId,
            (string) $identity['ico'],
            $companyIndex,
            $stats,
        );

        foreach ($plan['entries'] ?? [] as $entry) {
            $this->writeEntry(
                $entry,
                $supplierId,
                $userId,
                (string) $identity['ico'],
                $companyIndex,
                $accountIds,
                $periods,
                $stats,
                $targetIds,
            );
        }
        return $stats;
    }

    /** @param list<array<string,mixed>> $chart @param array<string,int> $stats @return array<string,int> */
    private function writeChart(array $chart, int $supplierId, string $ico, int $companyIndex, array &$stats, array $mapping = [], array $names = []): array
    {
        usort($chart, static fn (array $a, array $b): int => strlen((string) $a['code']) <=> strlen((string) $b['code']) ?: strcmp((string) $a['code'], (string) $b['code']));
        $ids = [];
        foreach ($this->accounts->codeToIdMap($supplierId) as $code => $row) {
            $ids[$code] = (int) $row['id'];
        }
        $parents = $ids;
        foreach ($chart as $source) {
            $code = (string) ($source['code'] ?? '');
            $targetCode = $mapping[$code] ?? ImportedAccountCode::normalize($code);
            $hash = (string) ($source['source_hash'] ?? '');
            $mapped = $this->mapped($supplierId, $ico, $companyIndex, self::MAP_ACCOUNT, $code, $hash);
            if ($mapped !== null) {
                $target = $this->accounts->findById($supplierId, $mapped);
                // Starší převody uchovaly kód bez tečky. Doložená vazba je nadále
                // platná; opakovaný import nesmí přejmenovat účet ani vytvořit nový.
                $allowed = array_key_exists($code, $mapping) ? [$targetCode] : [$code, $targetCode];
                if ($target === null || !in_array((string) $target['account_code'], $allowed, true)) {
                    throw new StereoNxException('mapped_target_changed', 'Dříve převedený účet byl změněn nebo odstraněn.');
                }
                $ids[$code] = $mapped;
                $stats['accounts_existing']++;
                continue;
            }

            if (!array_key_exists($code, $mapping) && $code !== $targetCode && isset($ids[$code])) {
                throw new StereoNxException('account_code_normalization_conflict',
                    "Účet {$code} už v cílové osnově existuje bez importní vazby. Před převodem na {$targetCode} vyřešte jeho přiřazení.");
            }
            $existing = $this->accounts->findByCode($supplierId, $targetCode);
            if ($existing === null) {
                $existing = $this->createAccount(array_replace($source,
                    ['code' => $targetCode, 'name' => $names[$targetCode] ?? $source['name']]), $supplierId, $parents);
                $stats['accounts_created']++;
            } else {
                $stats['accounts_existing']++;
            }
            $id = (int) $existing['id'];
            $parents[$targetCode] = $id;
            $ids[$code] = $id;
            $this->alignTaxDeductibility($supplierId, $id, $source, (string) $existing['account_type']);
            $this->map->put($supplierId, $ico, $companyIndex, self::MAP_ACCOUNT, $code, $hash, $id);
        }
        return $ids;
    }

    /** Cíle rozdělených bankovních a DPH kontací nemusí mít vlastní zdrojový účet. */
    private function targetAccountIds(array $plan, array $names, int $supplierId, array &$stats, array $configuration): array
    {
        $chart = [];
        foreach ($plan['chart'] ?? [] as $source) $chart[$source['code']] = $source;
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $this->accounts->codeToIdMap($supplierId));
        foreach ($plan['entries'] ?? [] as $entry) {
            foreach (['debit', 'credit'] as $side) {
                $code = $entry['target_' . $side] ?? null;
                if ($code === null || isset($ids[$code])) continue;
                $source = $chart[$entry[$side]] ?? null;
                if ($source === null) throw new StereoNxException('journal_account_missing', 'Chybí zdrojový účet pro cílovou kontaci.');
                $account = $this->createAccount(array_replace($source,
                    ['code' => $code, 'name' => $names[$code] ?? $source['name']]), $supplierId, $ids);
                $ids[$code] = (int) $account['id'];
                $stats['accounts_created']++;
            }
        }
        foreach ([...array_values($configuration['bank_accounts'] ?? []), ...array_values($configuration['vat'] ?? [])] as $code) {
            if ($code === null || isset($ids[$code])) continue;
            $account = $this->createAccount(['code' => $code, 'name' => $names[$code] ?? 'Účet ' . $code,
                'source_type' => 'R'], $supplierId, $ids);
            $ids[$code] = (int) $account['id'];
            $stats['accounts_created']++;
        }
        return $ids;
    }

    /** @param array<string,mixed> $source @param array<string,int> $ids @return array<string,mixed> */
    private function createAccount(array $source, int $supplierId, array $ids): array
    {
        $code = (string) $source['code'];
        $name = (string) ($source['name'] !== '' ? $source['name'] : 'Účet ' . $code);
        $normalSide = self::normalSide((string) $source['source_type']);
        $creator = new ChartAccountCreator($this->accounts);
        if (strlen($code) > 3) {
            $parentCode = substr($code, 0, 3);
            $parentId = $ids[$parentCode] ?? null;
            $parent = $parentId !== null ? $this->accounts->findById($supplierId, $parentId) : null;
            if ($parent === null) {
                throw new StereoNxException('chart_parent_missing', "Účet {$code} nemá v cílové osnově syntetický účet {$parentCode}.");
            }
            $id = $creator->createAnalytic($supplierId, $code, $name, $parent, $normalSide, true);
        } else {
            $explicitType = match ($source['source_type']) {
                'N' => 'expense', 'V' => 'revenue', 'O' => 'offbalance', 'Z' => 'closing', default => null,
            };
            $created = $creator->createSynthetic($supplierId, $code, $name,
                new ImportProtocol('stereo_nx'), 'chart', false, $explicitType, $normalSide, true);
            if ($created === null) {
                throw new StereoNxException('chart_account_type_unresolved', "Typ účtu {$code} nelze bezpečně určit.");
            }
            $id = $created['id'];
        }
        $account = $this->accounts->findById($supplierId, $id);
        if ($account === null) {
            throw new StereoNxException('chart_account_create_failed', "Účet {$code} se nepodařilo vytvořit.");
        }
        return $account;
    }

    private static function normalSide(string $sourceType): ?string
    {
        return match ($sourceType) {
            'A', 'N' => 'debit',
            'P', 'V' => 'credit',
            'R', 'Z', 'O' => null,
            default => null,
        };
    }

    /**
     * Protokol nesmí zveřejnit složené klíče kontací ani vytvořit desítky stejných
     * hlášení. Stejné nálezy proto shrne do jednoho řádku s počtem.
     *
     * @param list<array<string,mixed>> $issues
     * @return list<array{level:string,code:string,message:string,count:int}>
     */
    private static function summarizeIssues(array $issues, string $level): array
    {
        $counts = array_count_values(array_map(static fn (array $issue): string => (string) ($issue['code'] ?? 'accounting_source_invalid'), $issues));
        $firstMessage = [];
        foreach ($issues as $issue) {
            if (isset($issue['message']) && !isset($firstMessage[(string) ($issue['code'] ?? '')])) {
                $firstMessage[(string) $issue['code']] = (string) $issue['message'];
            }
        }
        $out = [];
        foreach ($counts as $code => $count) {
            $message = match ($code) {
                'fiscal_year_mismatch' => $firstMessage[$code] ?? 'Účetní rok zdroje neodpovídá účetnímu roku firmy.',
                'journal_year_mismatch' => "Účetní období určeno podle data účetního případu; pomocný rok se u {$count} řádků liší.",
                'source_not_accounting_entity' => 'Zdrojová firma není ve Stereo označena jako účetní jednotka.',
                'accounting_company_settings_invalid' => 'Nastavení zdrojové účetní jednotky není jednoznačné.',
                'opening_settings_missing' => 'Ve zdroji chybí druh počátečních stavů nebo počáteční účet rozvažný.',
                'opening_account_mismatch' => "{$count} počátečních kontací nepoužívá nastavený počáteční účet rozvažný.",
                'chart_account_missing' => "{$count} řádků účtové osnovy nemá kód účtu.",
                'chart_account_duplicate' => "Účtová osnova obsahuje {$count} duplicitních kódů.",
                'chart_account_normalization_collision' => "U {$count} účtů vzniká po doplnění tečky shodný kód. Vyřešte kolizi účtů ve zdroji; převod je nesloučí.",
                'chart_account_type_unknown' => "U {$count} účtů nelze určit zdrojový typ.",
                'chart_tax_flag_invalid' => "U {$count} účtů není určen daňový příznak.",
                'target_account_code_too_long' => "{$count} kódů účtů překračuje délku podporovanou MyÚčtem.",
                'journal_identity_missing' => "{$count} kontací nemá úplnou zdrojovou identitu.",
                'journal_identity_duplicate' => "Účetní deník obsahuje {$count} duplicitních kontací.",
                'journal_year_invalid' => "{$count} kontací nemá platný pomocný rok.",
                'journal_month_invalid', 'journal_month_mismatch' => "U {$count} kontací nesouhlasí účetní měsíc s datem případu.",
                'journal_date_invalid' => "{$count} kontací nemá platné datum účetního případu.",
                'journal_account_missing', 'journal_account_not_in_chart' => "{$count} stran kontací odkazuje na chybějící účet.",
                'journal_amount_invalid', 'journal_amount_out_of_range', 'journal_total_out_of_range', 'journal_amount_zero' => "{$count} kontací nemá podporovanou částku.",
                'journal_tax_amount_invalid', 'journal_tax_amount_unverified' => "U {$count} kontací nelze ověřit samostatnou částku DPH.",
                'journal_dimension_too_long' => "U {$count} kontací je analytické členění příliš dlouhé.",
                default => "Zdrojové účetnictví obsahuje {$count} problémů typu {$code}.",
            };
            $out[] = ['level' => $level, 'code' => $code, 'message' => $message, 'count' => $count];
        }
        return $out;
    }

    /** @param array<string,mixed> $source */
    private function alignTaxDeductibility(int $supplierId, int $accountId, array $source, string $accountType): void
    {
        if (!in_array($accountType, ['expense', 'revenue'], true)) {
            return;
        }
        $value = !empty($source['tax_deductible']) ? 'deductible' : 'non_deductible';
        $this->accounts->setTaxDeductibility($supplierId, $accountId, $value);
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @param array<string,int> $stats
     * @return array<int,array{id:int,starts_on:string,ends_on:string,status:string}>
     */
    private function preparePeriods(array $entries, int $supplierId, string $ico, int $companyIndex, array &$stats): array
    {
        $calendar = $this->calendar($supplierId);
        try {
            self::assertSourceYears($calendar, $entries);
        } catch (FiscalYearMismatchException $e) {
            throw new StereoNxException('fiscal_year_mismatch', $e->getMessage());
        }
        $years = [];
        foreach ($entries as $entry) {
            $label = $calendar->fiscalYearOfDate((string) $entry['date']);
            if ((int) $entry['posting_year'] !== $label) {
                throw new StereoNxException('accounting_period_shape',
                    'Plán převodu počítal s jiným začátkem účetního roku, než má cílová firma. Spusťte kontrolu převodu znovu.');
            }
            $years[$label] = true;
        }
        ksort($years);
        $out = [];
        foreach (array_keys($years) as $year) {
            $period = $this->periods->findByYear($supplierId, $year);
            if ($period === null) {
                [$starts, $ends] = MigrationPeriods::bounds($calendar, $year);
                if ($this->periods->overlapping($supplierId, $starts, $ends) !== null) {
                    throw new StereoNxException('accounting_period_overlap', "Rok {$year} se překrývá s existujícím účetním obdobím.");
                }
                $ensured = (new MigrationPeriods($this->periods))->ensure(
                    $supplierId, $year, $starts, $ends, new ImportProtocol('stereo_nx'), 'journal',
                    static function (int $id) use (&$stats): void { $stats['periods_created']++; },
                );
                $period = $this->periods->findById($supplierId, $ensured['id']);
            }
            if ($period === null || (string) $period['status'] !== 'open') {
                throw new StereoNxException('accounting_period_locked', "Účetní období {$year} není otevřené.");
            }
            foreach ($entries as $entry) {
                if ((int) $entry['posting_year'] === $year
                    && ((string) $entry['date'] < (string) $period['starts_on'] || (string) $entry['date'] > (string) $period['ends_on'])) {
                    throw new StereoNxException('accounting_period_shape', "Datum zápisu neleží v účetním období {$year}.");
                }
            }

            $foreign = $this->db->pdo()->prepare(
                'SELECT COUNT(*) FROM journal_entries e
                  WHERE e.supplier_id = ? AND e.period_id = ?
                    AND NOT EXISTS (
                        SELECT 1 FROM stereo_nx_import_map m
                         WHERE m.supplier_id = e.supplier_id AND m.source_ico = ?
                           AND m.source_company_index = ? AND m.kind = ? AND m.target_id = e.id
                    )'
            );
            $foreign->execute([$supplierId, (int) $period['id'], $ico, $companyIndex, self::MAP_ENTRY]);
            if ((int) $foreign->fetchColumn() > 0) {
                throw new StereoNxException('accounting_period_not_empty', "Účetní období {$year} už obsahuje jiné zápisy.");
            }
            $out[$year] = $period;
        }

        $lock = $this->db->pdo()->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ?');
        $lock->execute([$supplierId]);
        $lockedUntil = $lock->fetchColumn();
        if (is_string($lockedUntil) && $lockedUntil !== '') {
            foreach ($entries as $entry) {
                if ((string) $entry['date'] <= $lockedUntil) {
                    throw new StereoNxException('accounting_date_locked', 'Některé zápisy spadají do uzamčeného období.');
                }
            }
        }
        return $out;
    }

    /**
     * Účetní rok zdroje začíná počátečními stavy (DruhPS) a trvá do posledního zápisu
     * před dalšími počátečními stavy; každý musí padnout do jednoho období firmy.
     * Pomocný Rok k tomu spolehlivý není (liší se od data i v kalendářních agendách).
     * Zápisy před prvními počátečními stavy se nekontrolují.
     *
     * @param list<array<string,mixed>> $entries
     * @throws FiscalYearMismatchException
     */
    private static function assertSourceYears(FiscalCalendar $calendar, array $entries): void
    {
        $openings = [];
        foreach ($entries as $entry) {
            if (!empty($entry['is_opening'])) {
                $date = (string) $entry['date'];
                $openings[$date] ??= is_int($entry['source_year'] ?? null) ? (string) $entry['source_year'] : substr($date, 0, 4);
            }
        }
        if ($openings === []) {
            return;
        }
        ksort($openings, SORT_STRING);
        $starts = array_map('strval', array_keys($openings));
        $years = [];
        foreach ($entries as $entry) {
            $date = (string) $entry['date'];
            $start = null;
            foreach ($starts as $candidate) {
                if ($candidate > $date) {
                    break;
                }
                $start = $candidate;
            }
            if ($start !== null) {
                $years[$start][] = $date;
            }
        }
        foreach ($years as $start => $dates) {
            MigrationPeriods::assertSourceYear($calendar, $dates, $openings[(string) $start]);
        }
    }

    /**
     * @param array<string,mixed> $entry
     * @param array<string,int> $accountIds
     * @param array<int,array<string,mixed>> $periods
     * @param array<string,int> $stats
     */
    private function writeEntry(array $entry, int $supplierId, int $userId, string $ico, int $companyIndex, array $accountIds, array $periods, array &$stats, array $targetIds = []): void
    {
        $key = (string) $entry['source_key'];
        $hash = (string) $entry['source_hash'];
        $mapped = $this->mapped($supplierId, $ico, $companyIndex, self::MAP_ENTRY, $key, $hash);
        $amountCents = (int) $entry['amount_cents'];
        $isRedStorno = !empty($entry['is_red_storno']);
        $debit = (string) $entry['debit'];
        $credit = (string) $entry['credit'];
        if ($amountCents <= 0) {
            throw new StereoNxException('journal_amount_unsupported', 'Kontace nemá kladnou podporovanou částku.');
        }
        $debitId = isset($entry['target_debit']) ? ($targetIds[$entry['target_debit']] ?? null) : ($accountIds[$debit] ?? null);
        $creditId = isset($entry['target_credit']) ? ($targetIds[$entry['target_credit']] ?? null) : ($accountIds[$credit] ?? null);
        if ($debitId === null || $creditId === null) {
            throw new StereoNxException('journal_account_missing', 'Kontace odkazuje na účet, který nebyl převeden.');
        }
        $sourceType = !empty($entry['is_opening']) ? 'opening' : 'manual';
        if ($mapped !== null) {
            $this->verifyMappedEntry($mapped, $supplierId, $entry, $sourceType, $debitId, $creditId, $amountCents);
            $stats['journal_entries_existing']++;
            return;
        }

        $amount = number_format($amountCents / 100, 2, '.', '');
        $lines = [
            ['account_id' => $debitId, 'side' => 'debit', 'amount' => $amount, 'line_no' => 1,
                'is_red_storno' => $isRedStorno,
                'cost_center' => $entry['cost_center'] !== '' ? $entry['cost_center'] : null],
            ['account_id' => $creditId, 'side' => 'credit', 'amount' => $amount, 'line_no' => 2,
                'is_red_storno' => $isRedStorno,
                'cost_center' => $entry['cost_center'] !== '' ? $entry['cost_center'] : null],
        ];
        PostingService::assertBalanced($lines);
        $descriptionParts = array_values(array_filter([
            (string) $entry['description'],
            $entry['performance'] !== '' ? 'Výkon ' . $entry['performance'] : '',
            $entry['job'] !== '' ? 'Zakázka ' . $entry['job'] : '',
        ], static fn (string $part): bool => $part !== ''));
        $description = $descriptionParts !== [] ? implode(' · ', $descriptionParts) : 'Účetní zápis ze Stereo NX';
        $entryId = $this->journal->insert([
            'supplier_id' => $supplierId,
            'period_id' => (int) $periods[(int) $entry['posting_year']]['id'],
            'entry_date' => (string) $entry['date'],
            'document_date' => null,
            'document_no' => !empty($entry['is_opening']) ? null : (mb_substr((string) $entry['document_no'], 0, 50) ?: null),
            'description' => mb_substr($description, 0, 255),
            'source_type' => $sourceType,
            'source_id' => null,
            'posted_at' => date('Y-m-d H:i:s'),
            'posted_by' => $userId > 0 ? $userId : null,
        ], $lines);
        $this->map->put($supplierId, $ico, $companyIndex, self::MAP_ENTRY, $key, $hash, $entryId);
        $stats['journal_entries_created']++;
        $stats['journal_lines'] += 2;
    }

    private function verifyMappedEntry(int $entryId, int $supplierId, array $source, string $sourceType, int $debitId, int $creditId, int $amountCents): void
    {
        $header = $this->db->pdo()->prepare(
            'SELECT entry_date, source_type, source_id, reversed_by FROM journal_entries WHERE id = ? AND supplier_id = ?'
        );
        $header->execute([$entryId, $supplierId]);
        $row = $header->fetch(PDO::FETCH_ASSOC);
        if ($row === false || (string) $row['entry_date'] !== (string) $source['date']
            || ((string) $row['source_type'] !== $sourceType
                && !($sourceType === 'manual' && StereoNxJournalLinks::acceptsLinkedSource($this->db, $supplierId, $entryId,
                    $source['source_key'], (string) $row['source_type'], (int) $row['source_id'])))
            || $row['reversed_by'] !== null) {
            throw new StereoNxException('mapped_target_changed', 'Dříve převedený účetní zápis byl změněn nebo odstraněn.');
        }
        $lines = $this->db->pdo()->prepare(
            'SELECT account_id, side, amount, is_red_storno
               FROM journal_entry_lines
              WHERE entry_id = ? AND supplier_id = ?
              ORDER BY line_no, id'
        );
        $lines->execute([$entryId, $supplierId]);
        $actual = array_map(static fn (array $line): array => [
            (int) $line['account_id'], (string) $line['side'], (int) round((float) $line['amount'] * 100),
            (bool) $line['is_red_storno'],
        ], $lines->fetchAll(PDO::FETCH_ASSOC));
        $isRedStorno = !empty($source['is_red_storno']);
        $expected = [
            [$debitId, 'debit', $amountCents, $isRedStorno],
            [$creditId, 'credit', $amountCents, $isRedStorno],
        ];
        if ($actual !== $expected) {
            throw new StereoNxException('mapped_target_changed', 'Dříve převedená kontace byla změněna.');
        }
    }

    private function mapped(int $supplierId, string $ico, int $companyIndex, string $kind, string $key, string $hash): ?int
    {
        $mapped = $this->map->get($supplierId, $ico, $companyIndex, $kind, $key);
        if ($mapped === null) {
            return null;
        }
        if (!hash_equals($mapped['source_hash'], $hash)) {
            throw new StereoNxException('source_changed', 'Zdrojový účetní záznam se od předchozího převodu změnil.');
        }
        return $mapped['target_id'];
    }

    /** @param array<string,mixed> $row */
    private static function entryKey(array $row): ?string
    {
        $parts = array_map(static fn (string $field): string => trim((string) ($row[$field] ?? '')), ['Agenda', 'DoklRada', 'DoklCislo', 'Klic', 'Poradi']);
        if (in_array('', $parts, true)) {
            return null;
        }
        return json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
