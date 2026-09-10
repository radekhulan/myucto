<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use PDO;

/**
 * Převod agendy Money S3 do firmy v MyÚčtu — orchestrátor kroků průvodce.
 *
 * Pořadí: osnova → období a deník → režim účetní jednotky → adresář a předkontace →
 * faktury → pokladna a banka → vazby dokladů na deník a úhrady → uzávěrka historických
 * let → rekonciliace. Automatika účtování je po celou dobu vypnutá
 * ({@see AccountingUnitSwitch}).
 *
 * **Zkouška nanečisto** běží stejným kódem v jedné transakci, která se na konci vrátí —
 * protokol ukáže přesně to, co by udělal ostrý převod, a v databázi nic nezůstane.
 *
 * **Ostrý převod** zapisuje po krocích a každý krok je idempotentní
 * ({@see MoneyS3ImportRepository}): opakovaný běh téže zálohy nic nezdvojí a běh
 * přerušený chybou pokračuje tam, kde skončil.
 */
final class MoneyS3Importer
{
    public const STEP_PREFLIGHT = 'preflight';
    public const STEP_ACCOUNTING_MODE = 'accounting_mode';

    /** Kroky, bez kterých nemá smysl pokračovat — další na nich stojí. */
    private const CRITICAL_STEPS = [ChartImporter::STEP, JournalImporter::STEP, self::STEP_ACCOUNTING_MODE];

    public function __construct(
        private readonly Connection $db,
        private readonly MoneyS3ImportRepository $map,
        private readonly AccountingPeriodRepository $periods,
        private readonly ChartImporter $chart,
        private readonly JournalImporter $journal,
        private readonly AccountingUnitSwitch $unit,
        private readonly CodebookImporter $codebooks,
        private readonly InvoiceImporter $invoices,
        private readonly CashBankImporter $cashBank,
        private readonly DocumentLinker $linker,
        private readonly HistoricalYearCloser $closer,
        private readonly MoneyS3Reconciler $reconciler,
    ) {}

    /** @return list<string> klíče kroků v pořadí, v jakém běží */
    public static function stepKeys(): array
    {
        return [
            ChartImporter::STEP,
            JournalImporter::STEP,
            self::STEP_ACCOUNTING_MODE,
            CodebookImporter::STEP_PARTNERS,
            CodebookImporter::STEP_POSTING_RULES,
            InvoiceImporter::STEP_PURCHASE,
            InvoiceImporter::STEP_ISSUED,
            CashBankImporter::STEP_CASH,
            CashBankImporter::STEP_BANK,
            DocumentLinker::STEP_LINK,
            DocumentLinker::STEP_PAYMENTS,
            HistoricalYearCloser::STEP,
            MoneyS3Reconciler::STEP,
        ];
    }

    /**
     * Kontrola před převodem — nic nezapisuje. Chyba převod zastaví, upozornění ne.
     *
     * @return list<array{level:string,code:string,message:string,context:array<string,mixed>}>
     */
    public function preflight(int $supplierId, Ms3Backup $backup, AgendaInfo $agenda, ImportOptions $options): array
    {
        $out = [];
        $add = static function (string $level, string $code, string $message, array $context = []) use (&$out): void {
            $out[] = ['level' => $level, 'code' => $code, 'message' => $message, 'context' => $context];
        };

        $stmt = $this->db->pdo()->prepare('SELECT id, ic, company_name, accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($supplier === false) {
            $add('error', 'supplier_missing', 'Cílová firma neexistuje.');
            return $out;
        }

        $supplierIco = CodebookImporter::ico((string) ($supplier['ic'] ?? ''));
        $agendaIco = CodebookImporter::ico($agenda->ico);
        if ($agendaIco === '') {
            $add('warning', 'agenda_ico_missing', 'Záloha neobsahuje IČO firmy — ověřte, že jde o agendu této firmy.');
        } elseif ($supplierIco === '') {
            $add('warning', 'supplier_ico_missing', "Firma v MyÚčtu nemá vyplněné IČO, záloha je agenda IČO {$agendaIco}.");
        } elseif ($supplierIco !== $agendaIco) {
            // Záloha cizí firmy by se jinak vmíchala do účetnictví téhle — tenantová
            // izolace na úrovni obsahu, ne jen oprávnění.
            $add('error', 'ico_mismatch', "Záloha je agenda IČO {$agendaIco}, firma v MyÚčtu má IČO {$supplierIco}.", ['agenda' => $agendaIco, 'supplier' => $supplierIco]);
        }
        if (($agendaIco === '' || $supplierIco === '') && !$options->isDryRun() && !$options->confirmedIco) {
            // Bez IČO na jedné ze stran nejde ověřit, že agenda patří téhle firmě. Ostrý
            // převod by cizí účetnictví vmíchal natrvalo — spustí se jen s potvrzením.
            $add('error', 'ico_unverified', 'Nelze ověřit, že záloha patří této firmě (chybí IČO). Ostrý převod vyžaduje výslovné potvrzení.');
        }

        foreach ($agenda->warnings as $w) {
            $add('warning', $w['code'], $w['message']);
        }

        $plan = $this->journal->plan($backup, $options);
        if ($plan === []) {
            $add('error', 'no_journal', 'Záloha neobsahuje účetní deník — není co převést.');
        }
        foreach ($plan as $item) {
            if (!$item['calendar']) {
                $add('error', 'fiscal_year_not_calendar', "Účetní rok {$item['year']} (adresář {$item['dir']}) nevede Money jako kalendářní — velká část deníku leží mimo rok {$item['year']}. Převod podporuje jen kalendářní účetní rok.", ['year' => $item['year']]);
            }
            $period = $this->periods->findByYear($supplierId, $item['year']);
            if ($period === null) {
                continue;
            }
            $mapped = $this->map->get($supplierId, MoneyS3ImportRepository::KIND_PERIOD, (string) $item['year']) !== null;
            $foreign = $this->journal->foreignEntryCount($supplierId, (int) $period['id']);
            if ($foreign > 0) {
                $add('error', 'journal_not_empty', "Účetní období {$item['year']} už obsahuje {$foreign} zápisů, které nevznikly převodem z Money. Deník z Money se do rozjetého účetnictví nepřimíchává.", ['year' => $item['year'], 'entries' => $foreign]);
            }
            if ((string) $period['status'] !== 'open' && !$mapped) {
                $add('error', 'period_not_open', "Účetní období {$item['year']} je v MyÚčtu uzavřené.", ['year' => $item['year']]);
            }
        }
        if (($supplier['accounting_mode'] ?? '') !== 'double_entry') {
            $add('info', 'switch_to_double_entry', 'Firma se převodem přepne do podvojného účetnictví.');
        }
        return $out;
    }

    /**
     * @param (callable(string,int,int):void)|null $progress
     * @param (callable():bool)|null $shouldCancel
     */
    public function run(
        int $supplierId,
        int $userId,
        Ms3Backup $backup,
        ImportOptions $options,
        ?int $runId = null,
        ?callable $progress = null,
        ?callable $shouldCancel = null,
    ): ImportProtocol {
        $protocol = new ImportProtocol($options->mode);
        $agenda = AgendaInfo::fromBackup($backup);
        $protocol->set('agenda', $agenda->toArray());
        $protocol->set('options', $options->toArray());

        $preflight = $this->preflight($supplierId, $backup, $agenda, $options);
        $protocol->set('preflight', $preflight);
        $protocol->begin(self::STEP_PREFLIGHT);
        foreach ($preflight as $m) {
            if ($m['level'] === 'error') {
                $protocol->error(self::STEP_PREFLIGHT, $m['code'], $m['message'], $m['context']);
            }
        }
        if ($protocol->hasErrors()) {
            $protocol->fail(self::STEP_PREFLIGHT);
            return $protocol;
        }
        $protocol->finish(self::STEP_PREFLIGHT);

        $ctx = new ImportContext($supplierId, $userId, $backup, $agenda, $options, $protocol);
        $ctx->runId = $runId;
        $ctx->progress = $progress;

        $pdo = $this->db->pdo();
        $dryRun = $options->isDryRun();
        // Zkouška nanečisto uvnitř cizí transakce (testy, vnořené volání) jede přes
        // savepoint — vlastní BEGIN by PDO odmítlo a vnější transakci by nesměla vrátit.
        $savepoint = $dryRun && $pdo->inTransaction();
        if ($savepoint) {
            $pdo->exec('SAVEPOINT money_s3_dry_run');
        } elseif ($dryRun) {
            $pdo->beginTransaction();
        }
        try {
            // Stav před PRVNÍM ostrým během, po kterém se automatika neobnovila (neúspěšný
            // nebo spadlý běh ji nechal vypnutou) — jinak by se „obnovilo" vypnuto. K běhu
            // se ukládá PŘED vypnutím: kdyby spadl i tenhle běh, další snímek najde.
            $snapshot = ($dryRun ? null : $this->map->pendingAutomationSnapshot($supplierId)) ?? $this->unit->snapshot($supplierId);
            if (!$dryRun && $runId !== null) {
                $this->map->saveAutomationSnapshot($runId, $supplierId, $snapshot);
            }
            // Zkouška nanečisto vypnutí automatiky jen ohlásí. Zápis do řádku firmy by v její
            // jediné transakci držel zámek až do konce a každý nový doklad firmy (cizí klíč
            // na firmu) by na něj čekal; převod sám automatiku ke své práci nepotřebuje.
            if (!$dryRun) {
                $this->unit->disableAutomation($supplierId, $userId > 0 ? $userId : null);
            }
            $automation = ['before' => $snapshot, 'during' => $dryRun ? 'off' : $this->unit->automationLevel($supplierId), 'restored' => false, 'after' => null];
            $protocol->set('automation', $automation);

            $steps = $this->steps($ctx);
            $total = count($steps);
            $index = 0;
            foreach ($steps as $key => $fn) {
                if ($shouldCancel !== null && $shouldCancel()) {
                    $protocol->fail('cancelled');
                    break;
                }
                $ctx->report($key, $index++, $total);
                $protocol->begin($key);
                try {
                    // Uzávěrka si transakce řídí sama (každý krok průvodce je atomický);
                    // obalit ji by znamenalo, že se chyba uprostřed kroku nevrátí.
                    if ($dryRun || $key === HistoricalYearCloser::STEP) {
                        $fn();
                    } else {
                        $this->transactional($fn);
                    }
                    $protocol->finish($key);
                } catch (\Throwable $e) {
                    $protocol->error($key, $e instanceof MoneyS3Exception ? $e->errorCode : 'unexpected', $e->getMessage());
                    $protocol->fail($key);
                    break;
                }
                if (in_array($key, self::CRITICAL_STEPS, true) && $this->stepFailed($protocol, $key)) {
                    $protocol->fail($key);
                    break;
                }
            }
            $ctx->report('done', $total, $total);
            if (!$dryRun) {
                $this->invoices->recomputeClientStats($ctx);
            }

            if (!$dryRun && !$protocol->hasErrors()) {
                $this->unit->restoreAutomation($supplierId, $snapshot, $userId > 0 ? $userId : null);
                $this->map->markAutomationRestored($supplierId);
                $automation['restored'] = true;
                $automation['after'] = $this->unit->automationLevel($supplierId);
            }
            $protocol->set('automation', $automation);
        } finally {
            if ($savepoint) {
                $pdo->exec('ROLLBACK TO SAVEPOINT money_s3_dry_run');
            } elseif ($dryRun && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        return $protocol;
    }

    /** @return array<string,callable():void> */
    private function steps(ImportContext $ctx): array
    {
        return [
            ChartImporter::STEP => fn () => $this->chart->run($ctx),
            JournalImporter::STEP => fn () => $this->journal->run($ctx),
            self::STEP_ACCOUNTING_MODE => fn () => $this->switchMode($ctx),
            CodebookImporter::STEP_PARTNERS => fn () => $this->codebooks->importPartners($ctx),
            CodebookImporter::STEP_POSTING_RULES => fn () => $this->codebooks->importPostingRules($ctx),
            InvoiceImporter::STEP_PURCHASE => fn () => $this->invoices->importPurchases($ctx),
            InvoiceImporter::STEP_ISSUED => fn () => $this->invoices->importIssued($ctx),
            CashBankImporter::STEP_CASH => fn () => $this->cashBank->importCash($ctx),
            CashBankImporter::STEP_BANK => fn () => $this->cashBank->importBank($ctx),
            DocumentLinker::STEP_LINK => fn () => $this->linker->link($ctx),
            DocumentLinker::STEP_PAYMENTS => fn () => $this->linker->matchPayments($ctx),
            HistoricalYearCloser::STEP => fn () => $this->closer->run($ctx),
            MoneyS3Reconciler::STEP => fn () => $this->reconciler->run($ctx),
        ];
    }

    private function switchMode(ImportContext $ctx): void
    {
        $starts = array_map(static fn (array $p): string => $p['starts_on'], $ctx->periods);
        if ($starts === []) {
            return;
        }
        sort($starts);
        $ends = array_map(static fn (array $p): string => $p['ends_on'], $ctx->periods);
        $this->unit->switchToDoubleEntry($ctx->supplierId, $starts[0], !$ctx->options->isDryRun(), max($ends));
        $ctx->protocol->info(self::STEP_ACCOUNTING_MODE, 'double_entry', 'Podvojné účetnictví od ' . $starts[0] . '.');
    }

    private function stepFailed(ImportProtocol $protocol, string $key): bool
    {
        foreach ($protocol->toArray()['steps'] as $s) {
            if ($s['key'] === $key) {
                return $s['status'] === 'error';
            }
        }
        return false;
    }

    private function transactional(callable $fn): void
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $fn();
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
