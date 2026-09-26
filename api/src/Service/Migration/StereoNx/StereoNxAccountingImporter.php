<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\Pohoda\PartnerImporter;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationModuleSetup;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use PDO;
use Psr\Log\LoggerInterface;
use Throwable;

/** Účetní převod: všechny zapisované agendy sdílejí transakci i zkoušku nanečisto. */
final class StereoNxAccountingImporter
{
    public function __construct(
        private readonly Connection $db,
        private readonly StereoNxAccountingWriter $journal,
        private readonly StereoNxAssets $assets,
        private readonly StereoNxEmployees $employees,
        private readonly StereoNxPayrollWriter $payroll,
        private readonly StereoNxInventory $inventoryWriter,
        private readonly StereoNxAccountingDocuments $accountingDocuments,
        private readonly StereoNxAccountingPayments $accountingPayments,
        private readonly StereoNxJournalLinks $journalLinks,
        private readonly StereoNxImporter $documents,
        private readonly LoggerInterface $log,
        private readonly PayrollMigrationModuleSetup $payrollSetup,
        private readonly StereoNxReconciler $reconciler,
    ) {}

    public function run(StereoNxBackup $backup, int $supplierId, int $userId, bool $dryRun, bool $blankCountryIsCz = false): array
    {
        $report = ['ok' => false, 'dry_run' => $dryRun, 'database_writes' => false,
            'accounting_mode' => 'double_entry', 'preflight' => [], 'errors' => [], 'warnings' => [],
            'counts' => [], 'written' => [], 'review_documents' => [], 'review_reasons' => [],
            'review_movements' => [], 'movement_review_reasons' => []];
        try {
            StereoNxCompanyCompatibility::assertAccountingMode($backup->companyIdentity(), 'double_entry');
            $inventory = $backup->inventory();
            foreach ($inventory as $table) {
                if ($table['status'] !== 'ok') {
                    throw new StereoNxException('source_decode', 'Některou tabulku NX1 nelze úplně načíst.');
                }
            }
            $partners = StereoNxSourcePlan::partners(iterator_to_array($backup->rows('LAdresy'), false), $blankCountryIsCz);
            $report['counts']['clients'] = count($partners);
            foreach ($partners as $partner) {
                if ($partner['country_unresolved']) {
                    $report['warnings'][] = ['level' => 'warning', 'code' => 'partner_country_unresolved',
                        'message' => 'Země některé protistrany zůstává k ruční kontrole v adresáři.'];
                    break;
                }
            }
            $payrollMonths = iterator_to_array($backup->rows('MMzdy'), false);
            $plans = [
                'journal' => $this->journal->prepare($backup),
                'assets' => $this->assets->prepare($backup),
                'employees' => $this->employees->prepare($backup),
                'payroll' => StereoNxPayrollMonths::fromTables([
                    'MZAMEST' => iterator_to_array($backup->rows('MZAMEST'), false),
                    'MMzdy' => $payrollMonths,
                    'Gparrok' => $payrollMonths === [] ? [] : $backup->payrollRates(),
                    'MOdvPar' => in_array('MOdvPar', $backup->tableNames(), true)
                        ? iterator_to_array($backup->rows('MOdvPar'), false) : [],
                ], $backup->companyIdentity(), $backup->companyIndex()),
                'inventory' => $this->inventoryWriter->prepare($backup),
                'documents' => $this->accountingDocuments->prepare($backup, $blankCountryIsCz),
                'payments' => $this->accountingPayments->prepare($backup),
            ];
            // Samostatný převod osob stále hlásí mzdy jako nepřevzaté. V účetním
            // převodu už měsíce posoudí a spočítá modul historických mezd.
            $plans['employees']['warnings'] = array_values(array_filter(
                $plans['employees']['warnings'],
                static fn (array $warning): bool => $warning['code'] !== 'historical_payroll_skipped',
            ));
            $plans['payments'] = $this->accountingPayments->resolveNonVatCash($plans['payments'], $plans['journal']);
            $plans['payments']['documents'] = $plans['documents']['records'];
            $plans['payments']['journal_plan'] = $plans['journal'];
            $partners = $plans['documents']['records']['clients'];
            $report['counts']['clients'] = count($partners);
            foreach ($plans as $plan) {
                foreach ($plan['counts'] ?? [] as $key => $value) $report['counts'][$key] = $value;
                array_push($report['warnings'], ...($plan['warnings'] ?? []));
                array_push($report['errors'], ...($plan['errors'] ?? []));
            }
            $report['not_transferred'] = [];
            if (($plans['documents']['counts']['skipped_foreign_documents'] ?? 0) > 0) {
                $report['not_transferred'][] = ['table' => 'SfaktV/SfaktP',
                    'count' => $plans['documents']['counts']['skipped_foreign_documents'],
                    'reason' => 'foreign_document_unverified'];
            }
            foreach ([
                'ZAZPVDPH' => 'Kontrolní evidence DPH',
                'CIntdokl' => 'Interní doklady jako samostatné doklady',
                'CPSSaldo' => 'Počáteční saldo pohledávek a závazků',
                'SklOperH' => 'Skladové doklady',
                'SObjH' => 'Objednávky',
                'JLeasing' => 'Leasingové smlouvy',
                'JSplKal' => 'Splátkový kalendář',
            ] as $table => $label) {
                $count = (int) ($inventory[$table]['decoded_rows'] ?? 0);
                if ($count === 0) continue;
                $report['not_transferred'][] = ['table' => $table, 'count' => $count];
                $report['warnings'][] = ['level' => 'warning', 'code' => 'agenda_not_transferred',
                    'message' => $label . ': ' . $count . ' zdrojových záznamů není převáděno do samostatné evidence. '
                    . 'Účetní deník se převádí samostatně v rozsahu zdrojové tabulky Cdenik.'];
            }
            foreach ([
                ['CBanka', 'skipped_bank_statements', 'Bankovní výpisy bez ověřené měny'],
                ['CBanka', 'skipped_bank_account_statements', 'Bankovní výpisy bez jednoznačného vlastního účtu'],
                ['CBankap', 'skipped_bank_transactions', 'Bankovní pohyby bez bezpečného měnového zařazení'],
                ['CPokl', 'skipped_cash_transactions', 'Pokladní pohyby bez bezpečného měnového zařazení'],
                ['CPokl', 'skipped_zero_cash', 'Nulové počáteční záznamy pokladny bez peněžního dopadu'],
            ] as [$table, $counter, $label]) {
                $count = (int) ($plans['payments']['counts'][$counter] ?? 0);
                if ($count === 0) continue;
                $report['not_transferred'][] = ['table' => $table, 'count' => $count, 'reason' => $counter];
                $report['warnings'][] = ['level' => 'warning', 'code' => 'agenda_not_transferred',
                    'message' => $label . ': ' . $count . ' zdrojových záznamů nebylo převedeno.'];
            }
            $report['partial'] = $report['not_transferred'] !== [] || $report['warnings'] !== [];
            $report['date_bounds'] = $plans['journal']['date_bounds'] ?? null;
            $documentDates = [];
            foreach (['issued', 'purchases'] as $kind) {
                foreach ($plans['documents']['records'][$kind] as $document) {
                    foreach (['issue_date', 'tax_date', 'supply_date'] as $field) {
                        if (!empty($document[$field])) $documentDates[] = $document[$field];
                    }
                }
            }
            $movementDates = array_values(array_filter($plans['payments']['dates'] ?? [], 'is_string'));
            $targetDates = [...$documentDates, ...$movementDates];
            if ($targetDates !== []) {
                $dates = [...$targetDates, ...array_filter($report['date_bounds'] ?? [])];
                $report['date_bounds'] = ['from' => min($dates), 'to' => max($dates)];
            }

            $lastPayroll = $plans['payroll']['last_source_period'] ?? null;
            $lastData = isset($report['date_bounds']['to']) ? substr($report['date_bounds']['to'], 0, 7) : null;
            if ($lastPayroll !== null) {
                $report['payroll_setup'] = $this->payrollSetup->plan($supplierId, $lastPayroll, $lastData);
            }
            if ($report['errors'] !== []) return $report;
            $pdo = $this->db->pdo();
            $nested = $pdo->inTransaction();
            if ($nested) $pdo->exec('SAVEPOINT stereo_nx_accounting');
            else $pdo->beginTransaction();
            try {
                $lock = $pdo->prepare('SELECT ic, accounting_mode, is_vat_payer FROM supplier WHERE id = ? FOR UPDATE');
                $lock->execute([$supplierId]);
                $supplier = $lock->fetch(PDO::FETCH_ASSOC);
                $identity = $backup->companyIdentity();
                if ($supplier === false || PartnerImporter::ico((string) $supplier['ic']) !== $identity['ico']
                    || $supplier['accounting_mode'] !== 'double_entry'
                    || !(bool) $supplier['is_vat_payer'] || !$identity['vat_payer']) {
                    throw new StereoNxException('target_mismatch', 'Účetní převod vyžaduje shodné IČO a plátce DPH v režimu podvojného účetnictví.');
                }
                $this->assertDocumentPeriodsEmpty($supplierId, $report['date_bounds'], $identity['ico'], $backup->companyIndex());
                $this->assertDocumentDatesOpen($supplierId, $targetDates);
                if ($lastPayroll !== null) {
                    // Stejný zápis jako při převodu; zkouška vrátí i nastavení modulu.
                    $setup = $this->payrollSetup->ensure($supplierId, $userId, $lastPayroll, $lastData);
                    $protocol = new ImportProtocol($dryRun ? 'dry_run' : 'import');
                    PayrollMigrationModuleSetup::report($protocol, 'payroll', $setup, 'Stereo NX');
                    foreach ($protocol->toArray()['steps'] as $step) {
                        foreach ($step['messages'] as $message) {
                            $report['warnings'][] = ['level' => $message['level'], 'code' => $message['code'], 'message' => $message['text']];
                        }
                    }
                    $report['payroll_setup_result'] = $setup;
                }
                $report['written'] = $this->documents->writeAccountingPartners($partners, $identity, $backup->companyIndex(), $supplierId);
                foreach (['journal' => $this->journal, 'assets' => $this->assets, 'employees' => $this->employees,
                    'payroll' => $this->payroll,
                    'inventory' => $this->inventoryWriter, 'documents' => $this->accountingDocuments,
                    'payments' => $this->accountingPayments] as $key => $module) {
                    $result = $module->write($plans[$key], $supplierId, $userId);
                    foreach ($result['written'] ?? $result['counts'] ?? $result as $counter => $value) {
                        if (is_int($value)) $report['written'][$counter] = $value;
                    }
                    array_push($report['warnings'], ...($result['warnings'] ?? []));
                    array_push($report['review_documents'], ...($result['review_documents'] ?? []));
                    array_push($report['review_movements'], ...($result['review_movements'] ?? []));
                }
                $report['written'] += $this->journalLinks->write($plans['journal'], $plans['documents'], $supplierId, $userId);
                $proposal = $this->payroll->refreshPostingProposal($backup, $supplierId);
                if ($proposal !== null) {
                    $report['written']['payroll_posting_proposals'] = 1;
                    $report['warnings'][] = ['level' => 'info', 'code' => 'payroll_posting_proposal',
                        'message' => 'Z ověřených mzdových kontací vznikl návrh předkontací. Zkontrolujte jej v Mzdy → Importy; nastavení se automaticky nepřepsalo.'];
                }
                elseif ($lastPayroll !== null) {
                    $report['warnings'][] = ['level' => 'warning', 'code' => 'payroll_posting_unverified',
                        'message' => 'Ve zdroji nebyly doloženy jednoznačné dvojice mzdových účtů; návrh předkontací nevznikl.'];
                }
                $report += $this->reconciler->run($supplierId, $plans['journal']['accounting_plan']);
                foreach ($report['reconciliation'] as $year) {
                    if (!$year['ok']) throw new StereoNxException('reconciliation_failed',
                        'Předvaha po převodu nesouhlasí se zdrojovým deníkem. Převod byl vrácen; podrobnosti jsou v kontrole převodu.');
                }
                $report['counts']['requires_draft'] = count($report['review_documents']);
                foreach ($report['review_documents'] as &$review) {
                    if ($dryRun) $review['target_id'] = null;
                    foreach ($review['review_codes'] ?? [] as $code) {
                        $report['review_reasons'][$code] = ($report['review_reasons'][$code] ?? 0) + 1;
                    }
                }
                unset($review);
                $report['counts']['requires_movement_review'] = count($report['review_movements']);
                foreach ($report['review_movements'] as &$review) {
                    if ($dryRun) {
                        $review['target_id'] = null;
                        $review['statement_id'] = null;
                    }
                    foreach ($review['review_codes'] ?? [] as $code) {
                        $report['movement_review_reasons'][$code] = ($report['movement_review_reasons'][$code] ?? 0) + 1;
                    }
                }
                unset($review);
                $report['partial'] = $report['partial'] || $report['warnings'] !== [];
                if ($dryRun) {
                    $nested ? $pdo->exec('ROLLBACK TO SAVEPOINT stereo_nx_accounting') : $pdo->rollBack();
                    if ($nested) $pdo->exec('RELEASE SAVEPOINT stereo_nx_accounting');
                } else {
                    $nested ? $pdo->exec('RELEASE SAVEPOINT stereo_nx_accounting') : $pdo->commit();
                    $report['database_writes'] = true;
                }
                $report['ok'] = true;
            } catch (Throwable $e) {
                if ($nested) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT stereo_nx_accounting');
                    $pdo->exec('RELEASE SAVEPOINT stereo_nx_accounting');
                } elseif ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        } catch (StereoNxException $e) {
            $report['written'] = [];
            $report['errors'][] = ['level' => 'error', 'code' => $e->errorCode, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            $report['written'] = [];
            $this->log->error('Stereo NX accounting import failed', ['exception_class' => $e::class]);
            $report['errors'][] = ['level' => 'error', 'code' => 'import_failed',
                'message' => 'Účetní převod selhal; v databázi nebyly uloženy žádné nové záznamy.'];
        }
        if (!$report['ok']) {
            foreach ($report['review_documents'] as &$review) $review['target_id'] = null;
            unset($review);
            foreach ($report['review_movements'] as &$review) {
                $review['target_id'] = null;
                $review['statement_id'] = null;
            }
            unset($review);
        }
        return $report;
    }

    /** Doklady mohou mít jiné datum než jejich kontace v převzatém deníku. */
    private function assertDocumentDatesOpen(int $supplierId, array $dates): void
    {
        if (StereoNxTargetDates::findings($this->db, $supplierId, $dates) !== []) {
            throw new StereoNxException('document_date_locked', 'Datum převáděného dokladu spadá do uzavřeného nebo uzamčeného období.');
        }
    }

    private function assertDocumentPeriodsEmpty(int $supplierId, ?array $bounds, string $ico, int $companyIndex): void
    {
        if ($bounds === null || empty($bounds['from']) || empty($bounds['to'])) return;
        $from = substr($bounds['from'], 0, 4) . '-01-01';
        $to = ((int) substr($bounds['to'], 0, 4) + 1) . '-01-01';
        foreach ([['invoices', 'issue_date', 'issued'], ['purchase_invoices', 'issue_date', 'purchase'],
            ['bank_statements', 'statement_date', 'bank_statement'], ['cash_documents', 'issue_date', 'cash']] as [$table, $column, $kind]) {
            $stmt = $this->db->pdo()->prepare("SELECT 1 FROM {$table} t
                WHERE t.supplier_id = ? AND t.{$column} >= ? AND t.{$column} < ?
                AND NOT EXISTS (SELECT 1 FROM stereo_nx_import_map m
                    WHERE m.supplier_id = t.supplier_id AND m.source_ico = ?
                    AND m.source_company_index = ? AND m.kind = ? AND m.target_id = t.id) LIMIT 1");
            $stmt->execute([$supplierId, $from, $to, $ico, $companyIndex, $kind]);
            if ($stmt->fetchColumn() !== false) {
                throw new StereoNxException('target_period_not_empty', 'Cílové období už obsahuje účetní doklady mimo tento převod.');
            }
        }
    }
}
