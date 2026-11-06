<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\PremierImportRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\JournalDescriptionBuilder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Migration\MoneyS3\AccountCode;
use MyInvoice\Service\Migration\Shared\ChartAccountCreator;
use MyInvoice\Service\Migration\Shared\MigrationPeriods;
use MyInvoice\Service\Migration\Shared\ReconciliationTolerance;

/**
 * Účtová osnova, účetní období, počáteční stavy a účetní deník roku z PREMIER.
 *
 * Osnova: firma má osnovu ze šablony MyÚčta, z PREMIER se doplní účty, na které deník
 * roku účtuje (`518100` → `518.100` pod syntetikou `518`), s názvem z osnovy PREMIER.
 * **Daňovou uznatelnost účtu (`NEDANOVY`) přebírá převod vždy z PREMIER** - šablona MyÚčta
 * vede pod stejným číslem analytiky jiný obsah (`501.900` je v šabloně daňový materiál,
 * v PREMIER typicky nedaňový) a DPPO by jinak nesedělo. Stejně tak název analytiky ze
 * šablony, na kterou ještě nic není zaúčtované.
 *
 * Deník je autoritativní kopie PREMIER - zaúčtování se nepřepočítává, doklady se k němu
 * jen připojí ({@see DocumentLinker}). Počáteční stavy tvoří jeden otevírací zápis se
 * zdrojem `opening` (účty proti 701, výsledek minulých let na 431 - stejně jako otevírací
 * zápis uzávěrky MyÚčta). Když už ho období má (rok uzavřený v MyÚčtu), převod ho nemění
 * a jen ohlásí rozdíl proti PREMIER.
 */
final class ChartJournalImporter
{
    public const STEP_CHART = 'chart';
    public const STEP_JOURNAL = 'journal';

    private const RESULT_SYNTHETIC = '431';

    public function __construct(
        private readonly Connection $db,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly ChartOfAccountsSeeder $seeder,
        private readonly AccountingPeriodRepository $periods,
        private readonly JournalEntryRepository $journal,
        private readonly PremierImportRepository $map,
    ) {}

    public function chart(PremierContext $ctx): void
    {
        $p = $ctx->protocol;
        if ($this->accounts->count($ctx->supplierId) === 0) {
            $p->setCount(self::STEP_CHART, 'seeded', $this->seeder->seedForSupplier($ctx->supplierId, $this->periods->findByYear($ctx->supplierId, $ctx->year)['starts_on'] ?? $ctx->startsOn()));
        }
        $chart = $this->premierChart($ctx);
        $p->setCount(self::STEP_CHART, 'premier_accounts', count($chart));

        $ctx->accountIds = [];
        foreach ($this->accounts->codeToIdMap($ctx->supplierId) as $code => $row) {
            $ctx->accountIds[(string) $code] = (int) $row['id'];
        }

        $ctx->opening = $ctx->journal->openingRows($ctx->year) === []
            ? $ctx->journal->openingBalances($ctx->year, $this->resultAccount($ctx, $chart))
            : [];
        $used = $ctx->journal->accountsUsed($ctx->year);
        foreach (array_keys($ctx->opening) as $code) {
            $used[] = (string) $code;
        }
        $used = array_values(array_unique($used));
        sort($used, SORT_STRING);

        foreach ($used as $premierCode) {
            if (str_starts_with($premierCode, PremierJournal::OPENING_ACCOUNT)) {
                $this->ensureSynthetic($ctx, PremierJournal::OPENING_ACCOUNT, $chart);
                continue;
            }
            $target = AccountCode::fromMoney($premierCode);
            if ($target === null) {
                $p->error(self::STEP_CHART, 'invalid_account', "Deník PREMIER účtuje na účet „{$premierCode}“, který není číselný kód účtu.", ['account' => $premierCode]);
                continue;
            }
            $info = $chart[$premierCode] ?? $chart[substr($premierCode, 0, 3)] ?? null;
            $deductibility = ($info['non_deductible'] ?? false) ? 'non_deductible' : 'deductible';
            if (isset($ctx->accountIds[$target])) {
                $this->alignExisting($ctx, $target, $info, $deductibility);
                continue;
            }
            $parent = $this->ensureSynthetic($ctx, substr($target, 0, 3), $chart);
            if ($parent === null) {
                continue;
            }
            $id = (new ChartAccountCreator($this->accounts))->createAnalytic(
                $ctx->supplierId, $target, ($info['name'] ?? '') !== '' ? $info['name'] : ('Analytika ' . $premierCode), $parent,
            );
            $this->setDeductibility($ctx->supplierId, $id, $deductibility);
            $ctx->accountIds[$target] = $id;
            $this->map->put($ctx->supplierId, PremierImportRepository::KIND_ACCOUNT, $target, $id, $ctx->runId);
            $p->count(self::STEP_CHART, 'created');
            if ($deductibility === 'non_deductible') {
                $p->count(self::STEP_CHART, 'non_deductible');
            }
        }
        $p->finish(self::STEP_CHART);
    }

    public function journal(PremierContext $ctx): void
    {
        $p = $ctx->protocol;
        $year = $ctx->year;
        $existing = $this->map->all($ctx->supplierId, PremierImportRepository::KIND_JOURNAL_ENTRY, $year . '|');
        $legacy = self::legacyBankDocuments($ctx, $existing);
        if ($legacy > 0) {
            // Starší verze skládala bankovní výpis do jednoho zápisu za den. Nové zápisy po
            // pohybech by se k nim přidaly a banka by byla v deníku dvakrát.
            $p->error(self::STEP_JOURNAL, 'legacy_bank_entries', 'Firma byla převedena starší verzí převodu; pro rozdělení bankovních zápisů převeďte znovu do čisté firmy.',
                ['year' => $year, 'documents' => $legacy]);
            return;
        }
        $period = $this->ensurePeriod($ctx);
        $ctx->period = $period;
        if ($period['locked']) {
            $p->info(self::STEP_JOURNAL, 'year_locked', "Rok {$year} je v MyÚčtu už uzavřený, deník se do něj znovu nenahrává.", ['year' => $year]);
            $this->rememberEntries($ctx, $existing);
            $p->finish(self::STEP_JOURNAL);
            return;
        }
        $closing = $ctx->journal->closingRowCount($year);
        if ($closing > 0) {
            $p->info(self::STEP_JOURNAL, 'year_end_closing_skipped', "Uzávěrkové zápisy z PREMIER ({$closing} řádků na 702/710) se nepřebírají, rok uzavře průvodce uzávěrkou MyÚčta.");
        }

        $moneyCurrencies = self::moneyAccountCurrencies($ctx);
        $stats = ['year' => $year, 'entries' => 0, 'existing' => 0, 'lines' => 0, 'debit' => 0.0, 'credit' => 0.0, 'skipped_rows' => 0, 'swapped_rows' => 0];
        $now = date('Y-m-d H:i:s');

        $this->openingEntry($ctx, $period, $existing, $now);

        $documents = $ctx->journal->documents($year);
        $done = 0;
        $total = count($documents);
        foreach ($documents as $docKey => $rows) {
            if (++$done % 500 === 0) {
                $ctx->report(self::STEP_JOURNAL, $done, $total);
            }
            $key = $year . '|' . $docKey;
            if (isset($existing[$key])) {
                $ctx->entries[$docKey] = $existing[$key];
                $stats['existing']++;
                continue;
            }
            $first = $rows[0];
            $lines = [];
            $lineNo = 0;
            foreach ($rows as $r) {
                $effect = PremierJournal::effect($r);
                if ($effect === null) {
                    $stats['skipped_rows']++;
                    continue;
                }
                if ($r['amount'] < 0) {
                    $stats['swapped_rows']++;
                }
                $debitId = $ctx->accountIds[AccountCode::fromMoney($effect['debit']) ?? ''] ?? null;
                $creditId = $ctx->accountIds[AccountCode::fromMoney($effect['credit']) ?? ''] ?? null;
                if ($debitId === null || $creditId === null) {
                    throw new PremierException('unknown_account', sprintf(
                        'Doklad %s %s účtuje na účet %s/%s, který v osnově chybí.', $first['series'], $first['number'], $effect['debit'], $effect['credit']
                    ));
                }
                $amount = number_format($effect['amount'], 2, '.', '');
                $fx = self::foreign($r, $effect['amount']);
                foreach ([[$debitId, 'debit', $effect['debit']], [$creditId, 'credit', $effect['credit']]] as [$accountId, $side, $code]) {
                    $line = ['account_id' => $accountId, 'side' => $side, 'amount' => $amount, 'line_no' => ++$lineNo];
                    if ($fx !== null && self::carriesCurrency($code, $fx['currency_code'], $moneyCurrencies)) {
                        $line += $fx;
                    }
                    $lines[] = $line;
                }
            }
            if ($lines === []) {
                continue;
            }
            PostingService::assertBalanced($lines);
            $number = trim($first['series'] . ' ' . $first['number']);
            $description = JournalDescriptionBuilder::composeParts([$number, $first['text'] !== '' ? $first['text'] : $first['partner_name']]);
            $entryId = $this->journal->insert([
                'supplier_id' => $ctx->supplierId,
                'period_id' => $period['id'],
                'entry_date' => $first['date'],
                'document_date' => $first['tax_date'],
                'document_no' => mb_substr($number, 0, 50) ?: null,
                'description' => mb_substr($description !== '' ? $description : 'Účetní zápis z PREMIER', 0, 255),
                'source_type' => 'manual',
                'source_id' => null,
                'posted_at' => $now,
                'posted_by' => $ctx->userOrNull(),
            ], $lines);
            $this->map->put($ctx->supplierId, PremierImportRepository::KIND_JOURNAL_ENTRY, $key, $entryId, $ctx->runId);
            $ctx->entries[$docKey] = $entryId;
            $stats['entries']++;
            $stats['lines'] += count($lines);
            foreach ($lines as $l) {
                $stats[$l['side']] += (float) $l['amount'];
            }
        }
        $ctx->report(self::STEP_JOURNAL, $total, $total);

        $stats['debit'] = round($stats['debit'], 2);
        $stats['credit'] = round($stats['credit'], 2);
        foreach (['entries', 'existing', 'lines', 'skipped_rows'] as $k) {
            $p->setCount(self::STEP_JOURNAL, $k, $stats[$k]);
        }
        if ($stats['swapped_rows'] > 0) {
            $p->info(self::STEP_JOURNAL, 'negative_amounts', "{$stats['swapped_rows']} řádků se zápornou částkou přeneseno s prohozenými stranami (účetně totéž).");
        }
        if (!ReconciliationTolerance::sameCent($stats['debit'], $stats['credit'])) {
            $p->error(self::STEP_JOURNAL, 'journal_unbalanced', "Rok {$year}: Σ MD ≠ Σ D.");
        }
        $p->set('journal', [$stats]);
        $p->finish(self::STEP_JOURNAL);
    }

    /**
     * Zápisy období, které nevznikly převodem (ani uzávěrkou nad ním) - do rozjeté
     * účetní evidence se deník z PREMIER nepřimíchává.
     */
    public function foreignEntryCount(int $supplierId, int $periodId): int
    {
        return $this->map->foreignEntryCount($supplierId, $periodId);
    }

    /**
     * Otevírací zápis roku: z řádků 701 v deníku, jinak z dopočtených stavů minulých let.
     *
     * @param array{id:int,starts_on:string,ends_on:string,status:string,locked:bool} $period
     * @param array<string,int> $existing
     */
    private function openingEntry(PremierContext $ctx, array $period, array $existing, string $now): void
    {
        $p = $ctx->protocol;
        $key = $ctx->year . '|PS';
        if (isset($existing[$key])) {
            $p->count(self::STEP_JOURNAL, 'opening_existing');
            return;
        }
        $balances = $ctx->opening;
        foreach ($ctx->journal->openingRows($ctx->year) as $r) {
            foreach ([[$r['md'], 1], [$r['dal'], -1]] as [$code, $sign]) {
                if (!str_starts_with((string) $code, PremierJournal::OPENING_ACCOUNT)) {
                    $balances[$code] = round(($balances[$code] ?? 0.0) + $sign * $r['amount'], 2);
                }
            }
        }
        $balances = array_filter($balances, static fn (float $v): bool => abs($v) >= 0.005);
        if ($balances === []) {
            if ($ctx->journal->hasRowsBefore($ctx->year)) {
                $p->info(self::STEP_JOURNAL, 'opening_empty', "Rok {$ctx->year}: všechny účty mají na začátku roku nulový zůstatek.");
            }
            return;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM journal_entries WHERE supplier_id = ? AND period_id = ? AND source_type = 'opening' AND reversed_by IS NULL LIMIT 1"
        );
        $stmt->execute([$ctx->supplierId, $period['id']]);
        if ($stmt->fetchColumn() !== false) {
            // Otevírací zápis už založila uzávěrka minulého roku v MyÚčtu - platí ten;
            // rekonciliace ukáže, jestli sedí na PREMIER.
            $p->info(self::STEP_JOURNAL, 'opening_kept', "Rok {$ctx->year} už má otevírací zápis z uzávěrky MyÚčta, počáteční stavy z PREMIER se nepřebírají.");
            return;
        }
        $openingId = $ctx->accountIds[PremierJournal::OPENING_ACCOUNT] ?? null;
        if ($openingId === null) {
            throw new PremierException('unknown_account', 'V osnově chybí účet 701 pro počáteční stavy.');
        }
        $lines = [];
        $lineNo = 0;
        foreach ($balances as $code => $balance) {
            $accountId = $ctx->accountIds[AccountCode::fromMoney((string) $code) ?? ''] ?? null;
            if ($accountId === null) {
                throw new PremierException('unknown_account', "Počáteční stav účtu {$code} nejde zapsat, účet v osnově chybí.");
            }
            $amount = number_format(abs($balance), 2, '.', '');
            $lines[] = ['account_id' => $balance > 0 ? $accountId : $openingId, 'side' => 'debit', 'amount' => $amount, 'line_no' => ++$lineNo];
            $lines[] = ['account_id' => $balance > 0 ? $openingId : $accountId, 'side' => 'credit', 'amount' => $amount, 'line_no' => ++$lineNo];
        }
        PostingService::assertBalanced($lines);
        $net = round(array_sum($balances), 2);
        if (abs($net) >= 0.005) {
            $p->warn(self::STEP_JOURNAL, 'opening_701_not_zero', sprintf('Počáteční stavy roku %d nejsou vyrovnané (701 zůstává %s Kč) - deník minulých let obsahuje zápisy mimo třídy 0-6.', $ctx->year, number_format($net, 2, ',', ' ')));
        }
        $entryId = $this->journal->insert([
            'supplier_id' => $ctx->supplierId,
            'period_id' => $period['id'],
            'entry_date' => $period['starts_on'],
            'document_date' => null,
            'document_no' => null,
            'description' => 'Počáteční stavy ' . $ctx->year . ' (převzato z PREMIER)',
            'source_type' => 'opening',
            'source_id' => $period['id'],
            'posted_at' => $now,
            'posted_by' => $ctx->userOrNull(),
        ], $lines);
        $this->map->put($ctx->supplierId, PremierImportRepository::KIND_JOURNAL_ENTRY, $key, $entryId, $ctx->runId);
        $p->setCount(self::STEP_JOURNAL, 'opening_accounts', count($balances));
    }

    /**
     * Bankovní doklady roku, které má firma v mapě převodu jako jeden zápis za doklad
     * (převod starší verzí) - nové zápisy po pohybech by je zdvojily.
     *
     * @param array<string,int> $existing
     */
    private static function legacyBankDocuments(PremierContext $ctx, array $existing): int
    {
        $legacy = [];
        foreach (array_keys($ctx->journal->documents($ctx->year)) as $docKey) {
            $group = PremierJournal::groupKey((string) $docKey);
            if ($group !== $docKey && isset($existing[$ctx->year . '|' . $group])) {
                $legacy[$group] = true;
            }
        }
        return count($legacy);
    }

    /**
     * Při uzavřeném roce se aspoň naplní mapa zápisů z dřívějšího převodu (vazby dokladů).
     *
     * @param array<string,int> $existing mapa zápisů roku
     */
    private function rememberEntries(PremierContext $ctx, array $existing): void
    {
        $prefix = $ctx->year . '|';
        foreach ($existing as $key => $id) {
            if (str_starts_with((string) $key, $prefix) && $key !== $prefix . 'PS') {
                $ctx->entries[substr((string) $key, strlen($prefix))] = $id;
            }
        }
    }

    /**
     * Osnova PREMIER: rok převodu má přednost, chybějící účet se vezme z nejbližšího roku.
     *
     * @return array<string,array{name:string,non_deductible:bool}> kód PREMIER (`518100`, syntetika `518`) → údaje
     */
    private function premierChart(PremierContext $ctx): array
    {
        $byYear = [];
        foreach ($ctx->backup->rows('OSNOVA') as $r) {
            $synthetic = trim((string) ($r['UCET'] ?? ''));
            if (!ctype_digit($synthetic) || strlen($synthetic) !== 3) {
                continue;
            }
            $code = $synthetic . trim((string) ($r['ANALYT'] ?? ''));
            $byYear[(int) ($r['ROK'] ?? 0)][$code] = [
                'name' => trim((string) ($r['TEXT'] ?? '')),
                'non_deductible' => (bool) ($r['NEDANOVY'] ?? false),
            ];
        }
        uksort($byYear, static fn (int $a, int $b): int => abs($a - $ctx->year) <=> abs($b - $ctx->year) ?: $b <=> $a);
        $out = [];
        foreach ($byYear as $accounts) {
            $out += $accounts;
        }
        return $out;
    }

    /**
     * Účet výsledku minulých let v PREMIER (431 s analytikou, kterou osnova vede).
     *
     * @param array<string,array{name:string,non_deductible:bool}> $chart
     */
    private function resultAccount(PremierContext $ctx, array $chart): string
    {
        foreach ($ctx->journal->accountsUsed($ctx->year) as $code) {
            if (str_starts_with($code, self::RESULT_SYNTHETIC) && strlen($code) > 3) {
                return $code;
            }
        }
        $analytics = array_filter(array_keys($chart), static fn (int|string $c): bool => str_starts_with((string) $c, self::RESULT_SYNTHETIC) && strlen((string) $c) > 3);
        sort($analytics, SORT_STRING);
        return $analytics !== [] ? (string) $analytics[0] : self::RESULT_SYNTHETIC . '000';
    }

    /**
     * Existující účet (ze šablony nebo z dřívějšího převodu) - daňová uznatelnost podle
     * PREMIER vždy, název jen u analytiky bez zaúčtování.
     *
     * @param array{name:string,non_deductible:bool}|null $info
     */
    private function alignExisting(PremierContext $ctx, string $target, ?array $info, string $deductibility): void
    {
        $p = $ctx->protocol;
        $id = $ctx->accountIds[$target];
        $row = $this->accounts->findById($ctx->supplierId, $id);
        if ($row === null) {
            return;
        }
        $p->count(self::STEP_CHART, 'existing');
        if (($row['tax_deductibility'] ?? 'deductible') !== $deductibility) {
            $this->setDeductibility($ctx->supplierId, $id, $deductibility);
            $p->count(self::STEP_CHART, 'deductibility_aligned');
            $p->info(self::STEP_CHART, 'deductibility_aligned', sprintf('Účet %s: daňová uznatelnost převzata z PREMIER (%s).', $target,
                $deductibility === 'non_deductible' ? 'daňově neuznatelný' : 'daňově uznatelný'), ['account' => $target]);
        }
        $name = $info['name'] ?? '';
        if ($name !== '' && empty($row['is_synthetic']) && $name !== (string) $row['name'] && !$this->hasPostings($ctx->supplierId, $id)) {
            $this->accounts->update($ctx->supplierId, $id, ['name' => mb_substr($name, 0, 190)]);
            $p->count(self::STEP_CHART, 'renamed');
        }
    }

    private function hasPostings(int $supplierId, int $accountId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM journal_entry_lines WHERE supplier_id = ? AND account_id = ? LIMIT 1');
        $stmt->execute([$supplierId, $accountId]);
        return $stmt->fetchColumn() !== false;
    }

    private function setDeductibility(int $supplierId, int $accountId, string $value): void
    {
        $this->db->pdo()->prepare('UPDATE chart_of_accounts SET tax_deductibility = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$value, $accountId, $supplierId]);
    }

    /** @return array{id:int,starts_on:string,ends_on:string,status:string,locked:bool} */
    private function ensurePeriod(PremierContext $ctx): array
    {
        $year = $ctx->year;
        [$starts, $ends] = MigrationPeriods::bounds($ctx->calendar, $year);
        return (new MigrationPeriods($this->periods))->ensure(
            $ctx->supplierId, $year, $starts, $ends, $ctx->protocol, self::STEP_JOURNAL,
            fn (int $id) => $this->map->put($ctx->supplierId, PremierImportRepository::KIND_PERIOD, (string) $year, $id, $ctx->runId),
        );
    }

    /**
     * Syntetický účet osnovy; chybějící se založí s typem od sourozence ze skupiny
     * (jinak ze třídy) a názvem z osnovy PREMIER.
     *
     * @param array<string,array{name:string,non_deductible:bool}> $chart
     * @return array<string,mixed>|null
     */
    private function ensureSynthetic(PremierContext $ctx, string $synthetic, array $chart): ?array
    {
        $found = $this->accounts->findByCode($ctx->supplierId, $synthetic);
        if ($found !== null) {
            $ctx->accountIds[$synthetic] ??= (int) $found['id'];
            return $found;
        }
        $created = (new ChartAccountCreator($this->accounts))->createSynthetic(
            $ctx->supplierId, $synthetic, ($chart[$synthetic]['name'] ?? '') !== '' ? $chart[$synthetic]['name'] : ('Účet ' . $synthetic), $ctx->protocol, self::STEP_CHART,
        );
        if ($created === null) {
            return null;
        }
        $ctx->accountIds[$synthetic] = $created['id'];
        $this->map->put($ctx->supplierId, PremierImportRepository::KIND_ACCOUNT, $synthetic, $created['id'], $ctx->runId);
        return $this->accounts->findById($ctx->supplierId, $created['id']);
    }

    /**
     * Cizí měna řádku (bankovní účet v EUR): částka v měně a kurz na řádek deníku.
     *
     * @param array<string,mixed> $r
     * @return array{currency_code:string,amount_foreign:string,fx_rate:string}|null
     */
    private static function foreign(array $r, float $amount): ?array
    {
        if ($r['currency'] === '' || $r['currency'] === 'CZK' || abs($r['amount_foreign']) < 0.005) {
            return null;
        }
        $foreign = abs($r['amount_foreign']);
        return [
            'currency_code' => $r['currency'],
            'amount_foreign' => number_format($foreign, 2, '.', ''),
            'fx_rate' => number_format($amount / $foreign, 6, '.', ''),
        ];
    }

    /**
     * Cizoměnovou částku nese peněžní účet vedený v měně řádku (podle číselníku řad
     * pokladen a bank) a pohledávka nebo závazek; korunový bankovní účet u převodu do
     * měnového účtu ji nenese.
     *
     * @param array<string,string> $moneyCurrencies účet PREMIER → měna účtu
     */
    private static function carriesCurrency(string $code, string $currency, array $moneyCurrencies): bool
    {
        if (isset($moneyCurrencies[$code])) {
            return $moneyCurrencies[$code] === $currency;
        }
        return in_array(substr($code, 0, 2), ['31', '32', '37'], true);
    }

    /**
     * Měna pokladen a bankovních účtů z číselníku řad deníku (`DOKL_PU`, typ 1 a 2).
     *
     * @return array<string,string>
     */
    private static function moneyAccountCurrencies(PremierContext $ctx): array
    {
        $out = [];
        foreach ($ctx->backup->rows('DOKL_PU') as $r) {
            if (!in_array((int) ($r['TOK'] ?? 0), [1, 2], true)) {
                continue;
            }
            $currency = strtoupper(trim((string) ($r['MENA'] ?? '')));
            foreach ([['MD', 'MDA'], ['DAL', 'DALA']] as [$syn, $analytic]) {
                $account = trim((string) ($r[$syn] ?? '')) . trim((string) ($r[$analytic] ?? ''));
                if (ctype_digit($account) && strlen($account) > 3) {
                    // Řada bez měny v číselníku (termínovaný vklad v EUR…) - měna z deníku.
                    $out[$account] = preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : $ctx->journal->accountCurrency($account);
                }
            }
        }
        return $out;
    }
}
