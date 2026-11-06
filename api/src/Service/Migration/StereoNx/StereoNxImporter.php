<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\MovementClassificationRepository;
use MyInvoice\Repository\SupplierBankAccountRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\OssMigrationPolicy;
use MyInvoice\Service\Migration\Shared\BankAccountRegistrar;
use MyInvoice\Service\Migration\Shared\ForeignCurrencyTakeover;
use MyInvoice\Service\Migration\Shared\MigrationHomeCurrency;
use MyInvoice\Service\Migration\Shared\MigratedPaymentWriter;
use MyInvoice\Service\Migration\Shared\MigratedPurchasePaymentWriter;
use MyInvoice\Service\Migration\Shared\MigratedCashNumber;
use MyInvoice\Service\Migration\Shared\MigratedDocumentItem;
use MyInvoice\Service\Migration\Shared\MigratedDocumentWriter;
use MyInvoice\Service\Migration\Shared\MigratedIssuedDocument;
use MyInvoice\Service\Migration\Shared\MigratedPurchaseDocument;
use MyInvoice\Service\Migration\Shared\MigrationVatRateLookup;
use MyInvoice\Service\Migration\Shared\BankStatementImportWriter;
use MyInvoice\Service\Migration\Shared\PartnerIdentityMatcher;
use MyInvoice\Service\Migration\Shared\VatCoefficientSeeder;
use MyInvoice\Service\Stats\StatsRecomputer;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Throwable;

/** Převod daňové evidence Stereo NX do existující firmy; jeden běh = jedna transakce. */
final class StereoNxImporter
{
    private const REVIEW_LABELS = [
        'vat_participation_unassigned' => 'není určeno zpracování DPH',
        'vat_participation_disabled' => 'zpracování DPH je ve zdroji vypnuté',
        'price_mode_unassigned' => 'není určeno, zda jsou ceny včetně DPH',
        'self_assessment_amount_mismatch' => 'samovyměřená DPH neodpovídá základu a sazbě',
        'issued_lines_missing' => 'chybí původní položky vydaného dokladu',
        'missing_items' => 'doklad nemá položky',
        'partner_identity_missing' => 'chybí identita protistrany',
        'partner_country_unresolved' => 'není ověřena země protistrany',
        'partner_country_eu_unspecified' => 'země protistrany je uvedena pouze jako EU',
        'partner_country_changed' => 'země na dokladu se liší od karty protistrany',
        'tax_date_supply_mismatch' => 'datum DPH a den uskutečnění plnění se liší',
        'vat_register_mismatch' => 'údaje dokladu nesouhlasí se zdrojovou evidencí DPH',
        'mixed_vat_deduction' => 'doklad kombinuje položky s různým nárokem na odpočet DPH',
        'purchase_lines_aggregated' => 'položky přijatého dokladu byly sloučeny podle rekapitulace DPH',
        'advance_application_unlinked' => 'čerpání zálohy není navázáno na původní zálohový doklad',
        'issued_lines_aggregated' => 'položky vydaného dokladu byly sloučeny podle rekapitulace DPH',
        'foreign_currency_rate_mismatch' => 'korunová částka dokladu neodpovídá částce v cizí měně a uloženému kurzu',
        'foreign_currency_vat_base_mismatch' => 'korunový základ DPH nesouhlasí s přepočtenou částkou dokladu',
        'foreign_currency_vat_unverified' => 'daňové zařazení cizoměnového dokladu vyžaduje kontrolu',
        'document_tax_mapping_unverified' => 'daňové rozdělení dokladu se nepodařilo ověřit',
        'document_tax_date_missing' => 'zdroj neobsahuje úplné datum plnění nebo DPH',
        'cancelled_document_review' => 'stornovaný doklad vyžaduje kontrolu vazby na původní doklad',
        'document_kind_unverified' => 'zdrojový typ dokladu nemá přímý ekvivalent',
    ];

    public static function reviewLabel(string $code): string
    {
        return self::REVIEW_LABELS[$code] ?? 'neověřený údaj ze zdrojové zálohy';
    }

    private readonly MigrationVatRateLookup $rates;
    private readonly MigrationHomeCurrency $homeCurrency;
    private readonly MigratedPaymentWriter $payments;
    private readonly MigratedPurchasePaymentWriter $purchasePayments;
    private readonly MigratedCashNumber $cashNumbers;

    public function __construct(
        private readonly Connection $db,
        private readonly StereoNxSourcePlan $sourcePlan,
        private readonly StereoNxImportMap $map,
        private readonly SupplierBankAccountRepository $bankAccounts,
        private readonly MovementClassificationRepository $classifications,
        private readonly StatsRecomputer $stats,
        private readonly LoggerInterface $log,
        private readonly MigratedDocumentWriter $writer,
        private readonly VatCoefficientSeeder $coefficients,
        private readonly PartnerIdentityMatcher $identity,
    ) {
        $this->rates = new MigrationVatRateLookup($db);
        $this->homeCurrency = new MigrationHomeCurrency($db);
        $this->payments = new MigratedPaymentWriter($db);
        $this->purchasePayments = new MigratedPurchasePaymentWriter($db);
        $this->cashNumbers = new MigratedCashNumber($db);
    }

    /** Sdílený zápis adresáře pro účetní převod, bez dokladů a bez vlastní transakce.
     * @param array<string,array<string,mixed>> $partners
     * @param array{ico:string} $identity
     */
    public function writeAccountingPartners(array $partners, array $identity, int $companyIndex, int $supplierId): array
    {
        if (!$this->db->pdo()->inTransaction()) {
            throw new StereoNxException('transaction_required', 'Zápis adresáře vyžaduje transakci převodu.');
        }
        $context = ['supplier_id' => $supplierId, 'ico' => $identity['ico'], 'company_index' => $companyIndex,
            'currency_id' => $this->currencyId($supplierId), 'ids' => ['client' => []], 'written' => ['clients' => 0]];
        foreach ($partners as $record) $this->importClient($context, $record);
        return $context['written'];
    }

    /** Zápis účetních dokladů po samostatně převedeném adresáři, bez vlastní transakce. */
    public function writeAccountingDocuments(array $plan, int $supplierId, int $userId): array
    {
        if (!$this->db->pdo()->inTransaction()) {
            throw new StereoNxException('transaction_required', 'Zápis účetních dokladů vyžaduje transakci převodu.');
        }
        $ico = trim((string) ($plan['identity']['ico'] ?? ''));
        $companyIndex = (int) ($plan['source_company_index'] ?? -1);
        $context = [
            'supplier_id' => $supplierId, 'user_id' => $userId, 'ico' => $ico, 'company_index' => $companyIndex,
            'currency_id' => $this->currencyId($supplierId), 'ids' => ['client' => [], 'issued' => [], 'purchase' => []],
            'new_documents' => ['issued' => [], 'purchase' => []], 'actual_review_documents' => [],
            'written' => ['issued' => 0, 'purchases' => 0],
        ];
        foreach ($plan['clients'] ?? [] as $client) {
            $key = (string) ($client['source_key'] ?? '');
            $mapped = $this->map->get($supplierId, $ico, $companyIndex, 'client', $key);
            if ($mapped === null) throw new StereoNxException('document_partner_missing', 'Adresář musí být převeden před doklady.');
            $context['ids']['client'][$key] = $mapped['target_id'];
        }
        foreach ($plan['issued'] ?? [] as $record) $this->importDocument($context, 'issued', $record);
        foreach ($plan['purchases'] ?? [] as $record) $this->importDocument($context, 'purchase', $record);
        foreach (['issued' => 'issued', 'purchases' => 'purchase'] as $section => $kind) {
            foreach ($plan[$section] ?? [] as $record) {
                if (($record['requires_draft'] ?? false) !== true) continue;
                $key = (string) ($record['source_key'] ?? '');
                $index = $kind . "\0" . $key;
                $planned = array_values(array_unique(array_map('strval', $record['review_codes'] ?? [])));
                if (!isset($context['actual_review_documents'][$index])) {
                    $context['actual_review_documents'][$index] = [
                        'kind' => $kind, 'source_key' => $key,
                        'document_no' => (string) ($record['document_no'] ?? ''),
                        'review_codes' => $planned,
                        'target_id' => $context['ids'][$kind][$key] ?? null,
                    ];
                } else {
                    $context['actual_review_documents'][$index]['review_codes'] = array_values(array_unique([
                        ...$context['actual_review_documents'][$index]['review_codes'], ...$planned,
                    ]));
                    $context['actual_review_documents'][$index]['target_id'] = $context['ids'][$kind][$key] ?? null;
                }
            }
        }
        return ['counts' => $context['written'], 'warnings' => [],
            'review_documents' => array_values($context['actual_review_documents'])];
    }

    /** Zápis fyzické banky a pokladny bez projekce účetního deníku a bez vlastní transakce. */
    public function writeAccountingPayments(array $plan, int $supplierId, int $userId): array
    {
        if (!$this->db->pdo()->inTransaction()) {
            throw new StereoNxException('transaction_required', 'Zápis banky a pokladny vyžaduje transakci převodu.');
        }
        $ico = trim((string) ($plan['identity']['ico'] ?? ''));
        $companyIndex = (int) ($plan['source_company_index'] ?? -1);
        $context = [
            'supplier_id' => $supplierId, 'user_id' => $userId, 'ico' => $ico, 'company_index' => $companyIndex,
            'ids' => ['issued' => [], 'purchase' => [], 'bank_account' => [], 'bank_statement' => [], 'bank' => [], 'cash' => []],
            'new_documents' => ['issued' => [], 'purchase' => []], 'new_statements' => [],
            'touched_documents' => ['issued' => [], 'purchase' => []], 'touched_statements' => [], 'zero_cash' => [],
            'bank_writer' => new BankStatementImportWriter($this->db, 'stereo-nx'),
            'bank_statement_records' => array_column($plan['bank_statements'] ?? [], null, 'source_key'),
            'cash_numbers' => [],
            'written' => ['bank_accounts' => 0, 'bank_statements' => 0, 'bank_transactions' => 0,
                'cash_transactions' => 0, 'payments' => 0, 'skipped_zero_cash' => 0],
        ];
        foreach ($plan['payments'] ?? [] as $payment) {
            $kind = (string) ($payment['document_kind'] ?? '');
            $key = (string) ($payment['document_key'] ?? '');
            if (!in_array($kind, ['issued', 'purchase'], true) || $key === '') {
                throw new StereoNxException('payment_link_invalid', 'Vazba úhrady má nepodporovaný druh.');
            }
            if (isset($context['ids'][$kind][$key])) continue;
            $section = $kind === 'issued' ? 'issued' : 'purchases';
            $sources = array_values(array_filter($plan['documents'][$section] ?? [],
                static fn (array $document): bool => ($document['source_key'] ?? null) === $key));
            if (count($sources) !== 1) {
                throw new StereoNxException('payment_document_missing', 'Úhrada nemá jednoznačný zdrojový doklad.');
            }
            $target = $this->mappedDocument($context, $kind, $sources[0]);
            if ($target === null) throw new StereoNxException('payment_document_missing', 'Doklady musí být převedeny před úhradami.');
            $context['ids'][$kind][$key] = $target;
        }
        foreach ($plan['bank_accounts'] ?? [] as $record) {
            $wasMapped = $this->map->get($supplierId, $ico, $companyIndex, 'bank_account', (string) ($record['source_key'] ?? '')) !== null;
            $this->importBankAccount($context, $record);
            if (!$wasMapped) $context['written']['bank_accounts']++;
        }
        foreach ($plan['bank_statements'] ?? [] as $record) $this->importBankStatement($context, $record);
        foreach ($plan['bank_transactions'] ?? [] as $record) $this->importBankTransaction($context, $record);
        foreach ($plan['cash_transactions'] ?? [] as $record) $this->importCashTransaction($context, $record);
        foreach ($plan['payments'] ?? [] as $record) $this->importPayment($context, $record);
        $linkPlan = $plan;
        if (($plan['cash_openings'] ?? []) !== []) {
            $context['written']['cash_openings'] = (new StereoNxCashOpeningWriter($this->db, $this->map))
                ->write($context, $plan['cash_openings'], $plan['journal_plan'] ?? []);
            // Počáteční stav má informační vazbu na opening, běžné pohyby vlastní kontaci.
            $openingKeys = array_fill_keys(array_column(array_column($plan['cash_openings'], 'movement'), 'source_key'), true);
            $linkPlan['cash_transactions'] = array_values(array_filter($plan['cash_transactions'] ?? [],
                static fn (array $movement): bool => !isset($openingKeys[$movement['source_key']])));
        }
        $linkReviews = (new StereoNxMovementJournalLinks($this->db, $this->map))->write($context, $linkPlan);
        $allReviews = [];
        foreach ([...($plan['reviews'] ?? []), ...$linkReviews] as $review) {
            $reviewKey = $review['kind'] . ':' . $review['source_key'];
            if (isset($allReviews[$reviewKey])) {
                $allReviews[$reviewKey]['review_codes'] = array_values(array_unique([
                    ...$allReviews[$reviewKey]['review_codes'], ...$review['review_codes'],
                ]));
            } else $allReviews[$reviewKey] = $review;
        }
        $plan['reviews'] = array_values($allReviews);
        $this->refreshBalances($context);
        if (($plan['bank_openings'] ?? []) !== []) {
            $context['written']['bank_openings'] = (new StereoNxBankOpeningWriter($this->db, $this->map))
                ->write($context, $plan['bank_openings'], $plan['journal_plan'] ?? []);
        }
        $reviews = [];
        foreach ($plan['reviews'] ?? [] as $review) {
            $kind = (string) ($review['kind'] ?? ''); $key = (string) ($review['source_key'] ?? '');
            $review['target_id'] = $key !== '' ? ($context['ids'][$kind][$key] ?? null) : null;
            if ($kind === 'bank') {
                $review['statement_id'] = $context['ids']['bank_statement'][(string) ($review['statement_key'] ?? '')] ?? null;
            }
            unset($review['statement_key']);
            $reviews[] = $review;
        }
        unset($context['written']['skipped_zero_cash']);
        return ['counts' => $context['written'], 'warnings' => $linkReviews === [] ? [] : [[
            'level' => 'warning', 'code' => 'movement_journal_unverified',
            'message' => 'Pohyby bez ověřené kontace zůstaly mimo automatické účtování: banka jako ignorovaná, pokladna jako koncept. Před obnovením ověřte vazbu na převzatý deník.',
        ]], 'review_movements' => $reviews];
    }

    /**
     * Stejná cesta pro ostrý převod i zkoušku nanečisto. Suchý běh provede veškeré
     * SQL v transakci/savepointu a výsledek vrátí; nic nesmí zůstat v databázi.
     *
     * @return array<string,mixed>
     */
    public function run(StereoNxBackup $backup, int $supplierId, int $userId, bool $dryRun, bool $blankCountryIsCz = false, array $partnerCountries = []): array
    {
        $statsClientIds = [];
        $report = [
            'ok' => false, 'dry_run' => $dryRun, 'database_writes' => false,
            'preflight' => [], 'errors' => [], 'warnings' => [], 'counts' => [],
            'review_documents' => [],
        ];
        try {
            StereoNxCompanyCompatibility::assertAccountingMode($backup->companyIdentity(), 'tax_evidence');
            $backup->assertBankAccountSeriesReady();
            array_push($report['warnings'], ...$backup->bankAccountSeriesWarnings());
            $plan = $this->sourcePlan->build($backup, $blankCountryIsCz, $partnerCountries);
            $report['partner_country_candidates'] = $plan['partner_country_candidates'];
            array_push($report['warnings'], ...$plan['partner_country_warnings']);
            $report['counts'] = $plan['counts'] ?? [];
            $reviewReasons = [];
            foreach (['issued' => 'issued', 'purchases' => 'purchase'] as $section => $kind) {
                foreach ($plan[$section] ?? [] as $document) {
                    $codes = array_values(array_unique(array_map('strval', $document['review_codes'] ?? [])));
                    foreach ($codes as $code) $reviewReasons[$code] = ($reviewReasons[$code] ?? 0) + 1;
                    if (($document['requires_draft'] ?? false) === true) {
                        $report['review_documents'][] = [
                            'kind' => $kind,
                            'source_key' => (string) ($document['source_key'] ?? ''),
                            'document_no' => (string) ($document['document_no'] ?? ''),
                            'review_codes' => $codes,
                            'target_id' => null,
                        ];
                    }
                }
            }
            $report['review_reasons'] = $reviewReasons;
            $sourceDates = [];
            foreach (['issued', 'purchases', 'bank_statements', 'bank_transactions', 'cash_transactions'] as $part) {
                foreach ($plan[$part] ?? [] as $row) {
                    $date = $row['issue_date'] ?? $row['date'] ?? null;
                    if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1) $sourceDates[] = $date;
                    if (in_array($part, ['issued', 'purchases'], true)) {
                        foreach (['tax_date', 'supply_date'] as $field) {
                            $date = $row[$field] ?? null;
                            if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1) $sourceDates[] = $date;
                        }
                    }
                }
            }
            if ($sourceDates !== []) $report['date_bounds'] = ['from' => min($sourceDates), 'to' => max($sourceDates)];
            $zeroCashCount = count(array_filter($plan['cash_transactions'] ?? [],
                static fn (array $movement): bool => abs((float) ($movement['amount'] ?? 0)) < 0.005));
            if ($zeroCashCount > 0) {
                $report['warnings'][] = ['level' => 'warning', 'code' => 'zero_cash_opening',
                    'message' => 'Nulový počáteční záznam pokladny nevyvolal peněžní pohyb.',
                    'count' => $zeroCashCount];
            }
            $pdo = $this->db->pdo();
            $nested = $pdo->inTransaction();
            if ($nested) $pdo->exec('SAVEPOINT stereo_nx_import');
            else $pdo->beginTransaction();
            try {
                $lock = $pdo->prepare('SELECT id FROM supplier WHERE id = ? FOR UPDATE');
                $lock->execute([$supplierId]);
                if ($lock->fetchColumn() === false) throw new StereoNxException('supplier_missing', 'Cílová firma neexistuje.');
                $preflight = $this->preflight($supplierId, $backup, $plan);
                $report['preflight'] = $preflight;
                foreach ($preflight as $finding) {
                    if (($finding['level'] ?? '') === 'error') $report['errors'][] = $finding;
                    else $report['warnings'][] = $finding;
                }
                if ($report['errors'] !== []) {
                    $nested ? $pdo->exec('ROLLBACK TO SAVEPOINT stereo_nx_import') : $pdo->rollBack();
                    return $report;
                }
                $context = [
                    'supplier_id' => $supplierId,
                    'user_id' => $userId,
                    'ico' => (string) $plan['identity']['ico'],
                    'company_index' => $backup->companyIndex(),
                    'currency_id' => $this->currencyId($supplierId),
                    'country_id' => $this->countryId('CZ'),
                    'ids' => ['client' => [], 'issued' => [], 'purchase' => [], 'bank_account' => [],
                        'bank_statement' => [], 'bank' => [], 'cash' => []],
                    'new_documents' => ['issued' => [], 'purchase' => []], 'new_statements' => [],
                    'touched_documents' => ['issued' => [], 'purchase' => []], 'touched_statements' => [],
                    'zero_cash' => [],
                    'protocol' => new ImportProtocol($dryRun ? 'dry_run' : 'import'),
                    'actual_review_documents' => [],
                    'written' => ['clients' => 0, 'issued' => 0, 'purchases' => 0, 'bank_statements' => 0,
                        'bank_transactions' => 0, 'cash_transactions' => 0, 'payments' => 0,
                        'classifications' => 0, 'skipped_zero_cash' => 0],
                ];
                if ($context['country_id'] <= 0) throw new StereoNxException('country_missing', 'Chybí země CZ v cílové databázi.');
                foreach ($plan['clients'] ?? [] as $record) $this->importClient($context, $record);
                foreach ($plan['issued'] ?? [] as $record) $this->importDocument($context, 'issued', $record);
                foreach ($plan['purchases'] ?? [] as $record) $this->importDocument($context, 'purchase', $record);
                foreach ($plan['bank_accounts'] ?? [] as $record) $this->importBankAccount($context, $record);
                foreach ($plan['bank_statements'] ?? [] as $record) $this->importBankStatement($context, $record);
                foreach ($plan['bank_transactions'] ?? [] as $record) $this->importBankTransaction($context, $record);
                foreach ($plan['cash_transactions'] ?? [] as $record) $this->importCashTransaction($context, $record);
                self::appendProtocolWarnings($report, $context['protocol']);
                foreach ($plan['payments'] ?? [] as $record) $this->importPayment($context, $record);
                foreach ($plan['movement_classifications'] ?? [] as $record) $this->importClassification($context, $record);
                $this->refreshBalances($context);
                $this->seedVatCoefficients($report, $supplierId, $userId, $dryRun, $sourceDates);
                $reviewDocuments = [];
                foreach ($report['review_documents'] as $document) {
                    $reviewDocuments[$document['kind'] . "\0" . $document['source_key']] = $document;
                }
                foreach ($context['actual_review_documents'] as $key => $document) {
                    $reviewDocuments[$key] = $document;
                }
                $report['review_documents'] = array_values($reviewDocuments);
                $report['counts']['requires_draft'] = count($report['review_documents']);
                $report['review_reasons'] = [];
                foreach ($report['review_documents'] as $document) {
                    foreach ($document['review_codes'] as $code) {
                        $report['review_reasons'][$code] = ($report['review_reasons'][$code] ?? 0) + 1;
                    }
                }
                $statsClientIds = $this->issuedStatsClientIds($context);
                $report['written'] = $context['written'];
                $report['ok'] = true;
                if ($dryRun) {
                    $nested ? $pdo->exec('ROLLBACK TO SAVEPOINT stereo_nx_import') : $pdo->rollBack();
                } elseif ($nested) {
                    $pdo->exec('RELEASE SAVEPOINT stereo_nx_import');
                    $report['database_writes'] = true;
                } else {
                    $pdo->commit();
                    $report['database_writes'] = true;
                }
                if ($report['database_writes']) {
                    foreach ($report['review_documents'] as &$reviewDocument) {
                        $reviewDocument['target_id'] = $context['ids'][$reviewDocument['kind']][$reviewDocument['source_key']] ?? null;
                    }
                    unset($reviewDocument);
                }
            } catch (Throwable $e) {
                if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                    throw $e;
                }
                if ($nested) $pdo->exec('ROLLBACK TO SAVEPOINT stereo_nx_import');
                elseif ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        } catch (StereoNxException $e) {
            $report['ok'] = false;
            $report['database_writes'] = false;
            $report['errors'][] = ['level' => 'error', 'code' => $e->errorCode, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            $report['ok'] = false;
            $report['database_writes'] = false;
            // PDO/ZIP/reader výjimka může obsahovat hodnoty z klientských dat.
            $diagnostic = ['exception_class' => $e::class];
            if ($e instanceof PDOException) {
                $sqlState = (string) $e->getCode();
                if (preg_match('/^[A-Z0-9]{5}$/D', $sqlState)) $diagnostic['sqlstate'] = $sqlState;
                $driverCode = $e->errorInfo[1] ?? null;
                if (is_int($driverCode) || (is_string($driverCode) && ctype_digit($driverCode))) {
                    $diagnostic['driver_code'] = (int) $driverCode;
                }
            }
            $this->log->error('Stereo NX import failed', $diagnostic);
            $report['errors'][] = ['level' => 'error', 'code' => 'import_failed', 'message' => 'Převod selhal; v databázi nebyly uloženy žádné nové záznamy.'];
        }
        if ($report['ok'] && !$dryRun && !$this->db->pdo()->inTransaction() && $statsClientIds !== []) {
            try {
                $this->stats->recomputeMany($statsClientIds);
            } catch (Throwable) {
                // Převod už byl potvrzen; chybu vedlejší cache nelze vydávat za rollback.
                $report['warnings'][] = ['level' => 'warning', 'code' => 'stats_recompute_failed',
                    'message' => 'Převod proběhl, ale statistiky klientů je třeba přepočítat.'];
            }
        }
        return $report;
    }

    /**
     * Koeficient § 76 převedených let stejnou cestou jako ostatní převody: bez něj přiznání
     * s kráceným odpočtem (ř. 52) nejde sestavit.
     *
     * @param array<string,mixed> $report
     * @param list<string> $sourceDates
     */
    private function seedVatCoefficients(array &$report, int $supplierId, int $userId, bool $dryRun, array $sourceDates): void
    {
        $protocol = new ImportProtocol($dryRun ? 'dry_run' : 'import');
        // Koeficient § 76 se vypořádává za kalendářní rok i u hospodářského roku firmy.
        $years = array_map(static fn (string $d): int => (int) substr($d, 0, 4), $sourceDates);
        $this->coefficients->seedConverted($supplierId, [], $years, $userId, $protocol);
        self::appendProtocolWarnings($report, $protocol);
        foreach ($protocol->toArray()['steps'] as $step) {
            if (($step['counts']['settled'] ?? 0) > 0) {
                $report['counts']['vat_coefficients_settled'] = $step['counts']['settled'];
            }
        }
    }

    /** Upozornění ze sdílených služeb převodu (protokol Money) do zprávy Stereo NX. @param array<string,mixed> $report */
    private static function appendProtocolWarnings(array &$report, ImportProtocol $protocol): void
    {
        foreach ($protocol->toArray()['steps'] as $step) {
            foreach ($step['messages'] as $m) {
                if ($m['level'] === 'warning') {
                    $report['warnings'][] = ['level' => 'warning', 'code' => $m['code'], 'message' => $m['text']] + $m['context'];
                }
            }
        }
    }

    /** @param array<string,mixed> $ctx @return list<int> */
    private function issuedStatsClientIds(array $ctx): array
    {
        $ids = array_values(array_unique([...array_values($ctx['new_documents']['issued']),
            ...array_values($ctx['touched_documents']['issued'])]));
        if ($ids === []) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT DISTINCT client_id FROM invoices
            WHERE supplier_id = ? AND id IN ({$placeholders}) AND client_id IS NOT NULL");
        $stmt->execute([$ctx['supplier_id'], ...$ids]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param array<string,mixed> $plan
     * @return list<array<string,mixed>> */
    private function preflight(int $supplierId, StereoNxBackup $backup, array $plan): array
    {
        $out = [];
        $error = static function (string $code, string $message, array $details = []) use (&$out): void {
            $out[] = ['level' => 'error', 'code' => $code, 'message' => $message, ...$details];
        };
        $stmt = $this->db->pdo()->prepare('SELECT ic, is_vat_payer, accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($supplier === false) {
            $error('supplier_missing', 'Cílová firma neexistuje.');
            return $out;
        }
        $identity = $plan['identity'] ?? [];
        if (PartnerIdentityMatcher::ico((string) ($supplier['ic'] ?? '')) !== ($identity['ico'] ?? null)) {
            $error('ico_mismatch', 'IČO firmy v záloze a vybrané firmy se neshoduje.');
        }
        if (($identity['vat_payer'] ?? null) !== true || (int) $supplier['is_vat_payer'] !== 1) {
            $error('vat_mode_mismatch', 'Převod je nyní určený pouze pro plátce DPH v obou systémech.');
        }
        if ($supplier['accounting_mode'] !== 'tax_evidence') {
            $error('accounting_mode_mismatch', 'Cílová firma musí být v režimu daňové evidence.');
        }
        foreach ($plan['blockers'] ?? [] as $blocker) {
            $code = is_array($blocker) ? (string) ($blocker['code'] ?? 'source_blocker') : (string) $blocker;
            $error('source_' . preg_replace('/[^a-z0-9_]/', '', $code), 'Záloha obsahuje nepodporované nebo nejednoznačné zdrojové údaje.');
        }
        $dates = [];
        foreach (['issued', 'purchases', 'bank_statements', 'bank_transactions', 'cash_transactions'] as $kind) {
            foreach ($plan[$kind] ?? [] as $record) {
                $date = $record['issue_date'] ?? $record['date'] ?? null;
                if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1) $dates[] = $date;
                else $error('source_date_missing', 'Zdrojový doklad nebo pohyb nemá platné datum.');
                if (in_array($kind, ['issued', 'purchases'], true)) {
                    foreach (['tax_date', 'supply_date'] as $field) {
                        if (($record[$field] ?? null) !== null) $dates[] = $this->date($record[$field]);
                    }
                }
            }
        }
        // Daňová evidence (DPFO) vede vždy kalendářní rok; hospodářský rok firmy tady
        // záměrně nehraje roli (na rozdíl od účetního převodu StereoNxAccountingImporter).
        $years = array_values(array_unique(array_map(static fn (string $d): int => (int) substr($d, 0, 4), $dates)));
        array_push($out, ...StereoNxTargetDates::findings($this->db, $supplierId, $dates));
        foreach ($years as $year) {
            foreach ([['invoices', 'issued', 'issue_date', 'vydané faktury'],
                ['purchase_invoices', 'purchase', 'issue_date', 'přijaté faktury'],
                ['bank_statements', 'bank_statement', 'statement_date', 'bankovní výpisy'],
                ['cash_documents', 'cash', 'issue_date', 'pokladní doklady']] as [$table, $kind, $dateColumn, $label]) {
                $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} t
                    WHERE t.supplier_id = ? AND t.{$dateColumn} >= ? AND t.{$dateColumn} < ?
                      AND NOT EXISTS (SELECT 1 FROM stereo_nx_import_map m
                        WHERE m.supplier_id = ? AND m.source_ico = ? AND m.source_company_index = ?
                          AND m.kind = ? AND m.target_id = t.id)");
                $start = sprintf('%04d-01-01', $year);
                $end = sprintf('%04d-01-01', $year + 1);
                $stmt->execute([$supplierId, $start, $end, $supplierId, $identity['ico'], $backup->companyIndex(), $kind]);
                $count = (int) $stmt->fetchColumn();
                if ($count > 0) {
                    $error('target_period_not_empty', sprintf(
                        'Počet nesouvisejících záznamů v agendě „%s“ za rok %d: %d.', $label, $year, $count,
                    ), ['year' => $year, 'agenda' => $table, 'count' => $count]);
                }
            }
            $bank = $this->db->pdo()->prepare('SELECT COUNT(*) FROM bank_transactions bt
                JOIN bank_statements bs ON bs.id = bt.statement_id
                WHERE bs.supplier_id = ? AND bt.posted_at >= ? AND bt.posted_at < ?
                  AND NOT EXISTS (SELECT 1 FROM stereo_nx_import_map m
                    WHERE m.supplier_id = ? AND m.source_ico = ? AND m.source_company_index = ?
                      AND m.kind = "bank" AND m.target_id = bt.id)');
            $bank->execute([$supplierId, $start, $end, $supplierId, $identity['ico'], $backup->companyIndex()]);
            $count = (int) $bank->fetchColumn();
            if ($count > 0) {
                $error('target_period_not_empty', sprintf(
                    'Počet nesouvisejících záznamů v agendě „bankovní pohyby“ za rok %d: %d.', $year, $count,
                ), ['year' => $year, 'agenda' => 'bank_transactions', 'count' => $count]);
            }
        }
        $seen = [];
        return array_values(array_filter($out, static function (array $finding) use (&$seen): bool {
            $key = json_encode($finding, JSON_THROW_ON_ERROR);
            if (isset($seen[$key])) return false;
            $seen[$key] = true;
            return true;
        }));
    }

    /** @param array<string,mixed> $ctx @param array<string,mixed> $record */
    private function importClient(array &$ctx, array $record): void
    {
        $key = $this->key($record);
        $hash = self::sourceHash($record);
        $existing = $this->mapped($ctx, 'client', $key, $hash);
        if ($existing !== null) { $ctx['ids']['client'][$key] = $existing; return; }
        $ico = PartnerIdentityMatcher::ico((string) ($record['ico'] ?? ''));
        if ($ico !== '') {
            $matched = $this->identity->clientByIco($ctx['supplier_id'], $ico);
            if ($matched !== null) {
                $sourceDic = PartnerIdentityMatcher::vatId((string) ($record['dic'] ?? ''));
                $targetDic = PartnerIdentityMatcher::vatId((string) ($matched['dic'] ?? ''));
                if ($sourceDic !== '' && $targetDic !== '' && $sourceDic !== $targetDic) {
                    throw new StereoNxException('client_identity_conflict', 'Existující partner má při stejném IČO jiné DIČ.');
                }
                $id = (int) $matched['id'];
                $this->put($ctx, 'client', $key, $hash, $id);
                $ctx['ids']['client'][$key] = $id;
                return;
            }
        }
        $name = trim((string) ($record['name'] ?? ''));
        if ($name === '') throw new StereoNxException('client_name_missing', 'Adresář obsahuje partnera bez názvu.');
        $country = strtoupper(trim((string) ($record['country_code'] ?? '')));
        if ($country === '' || ($record['country_unresolved'] ?? false) === true) {
            // Zdrojový plán takového partnera připouští jen s doklady v konceptu.
            $country = 'CZ';
        }
        $countryId = $this->countryId($country);
        if ($countryId <= 0) throw new StereoNxException('country_unsupported', 'Země partnera nemá jednoznačné zařazení.');
        $id = $this->identity->insertImportedClient($ctx['supplier_id'], [
            'name' => $name, 'ico' => $ico, 'dic' => (string) ($record['dic'] ?? ''),
            'street' => (string) ($record['street'] ?? ''), 'city' => (string) ($record['city'] ?? ''),
            'zip' => (string) ($record['zip'] ?? ''), 'email' => (string) ($record['email'] ?? ''),
            'phone' => (string) ($record['phone'] ?? ''), 'country_id' => $countryId,
            'currency_id' => $ctx['currency_id'],
            'is_vat_payer' => ($record['row']['PlatDPH'] ?? null) === true,
            'note' => ($record['country_unresolved'] ?? false)
                ? 'Převzato ze Stereo NX; zemi nutno ověřit.' : 'Převzato ze Stereo NX',
        ]);
        $this->put($ctx, 'client', $key, $hash, $id);
        $ctx['ids']['client'][$key] = $id;
        $ctx['written']['clients']++;
    }

    /** @param array<string,mixed> $ctx @param array<string,mixed> $record */
    private function importDocument(array &$ctx, string $kind, array $record): void
    {
        $key = $this->key($record);
        $hash = self::sourceHash($record);
        $existing = $this->mappedDocument($ctx, $kind, $record);
        if ($existing !== null) { $ctx['ids'][$kind][$key] = $existing; return; }
        $partnerId = $ctx['ids']['client'][(string) ($record['partner_key'] ?? '')] ?? null;
        if ($partnerId === null) throw new StereoNxException('document_partner_missing', 'Doklad odkazuje na neznámého partnera.');
        $number = trim((string) ($record['document_no'] ?? ''));
        $issue = $this->date($record['issue_date'] ?? null);
        $tax = $this->date($record['supply_date'] ?? $record['tax_date'] ?? $issue);
        $sourceVatDate = $this->date($record['tax_date'] ?? $tax);
        $due = $this->date($record['due_date'] ?? $issue);
        if ($number === '' || mb_strlen($number) > 20) throw new StereoNxException('document_number_invalid', 'Doklad nemá platné číslo.');
        $table = $kind === 'issued' ? 'invoices' : 'purchase_invoices';
        $stmt = $this->db->pdo()->prepare("SELECT id FROM {$table} WHERE supplier_id = ? AND varsymbol = ? LIMIT 1");
        $stmt->execute([$ctx['supplier_id'], $number]);
        if ($stmt->fetchColumn() !== false) throw new StereoNxException('document_number_taken', 'Číslo zdrojového dokladu už ve firmě používá jiný doklad.');
        $items = $record['target_items'] ?? $record['items'] ?? [];
        if (!is_array($items)) throw new StereoNxException('document_items_invalid', 'Položky dokladu nejsou platné.');
        $base = $this->money($record['total_without_vat'] ?? null);
        $vat = $this->money($record['total_vat'] ?? null);
        $total = $this->money($record['source_total_with_vat'] ?? $record['total_with_vat'] ?? null);
        $rounding = $this->money($record['rounding'] ?? 0);
        if (abs($base + $vat + $rounding - $total) > 0.011) {
            throw new StereoNxException('document_total_mismatch', 'Součet dokladu nesouhlasí s položkami a zaokrouhlením.');
        }
        $review = (bool) ($record['requires_draft'] ?? false) || $items === [];
        $reviewCodes = array_values(array_unique(array_map('strval', $record['review_codes'] ?? [])));
        if ($items === []) $reviewCodes[] = 'missing_items';
        $liveSnapshot = $this->clientSnapshot($ctx['supplier_id'], $partnerId);
        $historical = $record['partner_snapshot'] ?? [];
        if (is_array($historical) && ($historical['country_unresolved'] ?? true) === false
            && ($historical['country_code'] ?? '') !== ''
            && $historical['country_code'] !== $liveSnapshot['country_code']) {
            $review = true;
            $reviewCodes[] = 'partner_country_changed';
        }
        if ($review) {
            $ctx['actual_review_documents'][$kind . "\0" . $key] = [
                'kind' => $kind, 'source_key' => $key, 'document_no' => $number,
                'review_codes' => array_values(array_unique($reviewCodes)), 'target_id' => null,
            ];
        }
        $note = 'Převzato ze Stereo NX.';
        if ($reviewCodes !== []) {
            $labels = array_map(static fn (string $code): string => self::reviewLabel($code), $reviewCodes);
            $note .= ' K ruční kontrole: ' . implode('; ', array_unique($labels)) . '.';
        }
        if ($sourceVatDate !== $tax) $note .= ' Zdrojové datum pro DPH: ' . $sourceVatDate . '; DUZP: ' . $tax . '.';
        $sourceNote = trim((string) ($record['note'] ?? ''));
        $snapshot = $liveSnapshot;
        if (is_array($historical)) {
            foreach (['name', 'ico', 'dic', 'street', 'city', 'zip'] as $field) {
                $value = trim((string) ($historical[$field] ?? ''));
                if ($value !== '') $snapshot[$field] = $value;
            }
        }
        $snapshotJson = (string) json_encode([
            'company_name' => $snapshot['name'], 'street' => $snapshot['street'], 'city' => $snapshot['city'],
            'zip' => $snapshot['zip'], 'ic' => $snapshot['ico'], 'dic' => $snapshot['dic'],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $vs = preg_replace('/\D/', '', (string) ($record['variable_symbol'] ?? '')) ?? '';
        $vs = strlen($vs) <= 10 ? ($vs !== '' ? $vs : null) : null;
        // Zdrojový plán převádí jen doklady v Kč s kurzem 1 (StereoNxIssuedDocuments,
        // StereoNxPurchaseRecap), režim cen a přenesenou povinnost nese záznam plánu.
        $pricesIncludeVat = (bool) ($record['prices_include_vat'] ?? false);
        $reverseCharge = (bool) ($record['reverse_charge'] ?? false);
        $currencyCode = strtoupper(trim((string) ($record['currency_code'] ?? 'CZK')));
        $currencyId = $this->currencyIdForCode($ctx['supplier_id'], 'CZK');
        $exchangeRate = null;
        if ($currencyCode !== 'CZK') {
            $decision = (new ForeignCurrencyTakeover($this->db))->decide(
                $ctx['supplier_id'], $currencyCode, (float) ($record['exchange_rate'] ?? 0), 1.0,
                (array) ($record['foreign_takeover_items'] ?? []),
                (float) ($record['foreign_home_total'] ?? 0),
                array_key_exists('foreign_takeover_blocked', $record)
                    ? $record['foreign_takeover_blocked'] : 'zdroj nemá ověřené korunové částky',
            );
            if (!$decision->inForeignCurrency()) {
                if (str_starts_with((string) $decision->reason, 'měna ')) {
                    throw new StereoNxException('currency_missing',
                        'Cílová firma nemá měnu ' . $currencyCode . '; založte ji v Nastavení měn a převod zopakujte.');
                }
                throw new StereoNxException('document_foreign_unverified',
                    'Cizoměnový doklad nelze bezpečně převzít: ' . $decision->reason . '.');
            }
            $currencyId = $decision->currencyId;
            $exchangeRate = $decision->rate;
        }
        if ($kind === 'issued') {
            $id = $this->writer->insertIssued(new MigratedIssuedDocument(
                supplierId: $ctx['supplier_id'],
                invoiceType: (string) ($record['target_document_kind'] ?? 'invoice'),
                clientId: $partnerId,
                varsymbol: $number,
                issueDate: $issue,
                taxDate: $tax,
                dueDate: $due,
                currencyId: $currencyId,
                exchangeRate: $exchangeRate,
                pricesIncludeVat: $pricesIncludeVat,
                reverseCharge: $reverseCharge,
                noteAboveItems: $sourceNote !== '' ? mb_substr($sourceNote, 0, 1000) : null,
                noteBelowItems: $note,
                clientSnapshot: $snapshotJson,
                totalWithoutVat: $base,
                totalVat: $vat,
                totalWithVat: $total,
                rounding: $rounding,
                status: $review ? 'draft' : 'sent',
                createdBy: $ctx['user_id'],
                paymentVariableSymbol: $vs,
            ));
        } else {
            $vendorNumber = trim((string) ($record['vendor_number'] ?? ''));
            if ($vendorNumber === '') $vendorNumber = $number;
            [$deduction, $mixedDeduction] = self::purchaseDeduction($items);
            if ($mixedDeduction) {
                throw new StereoNxException('mixed_deduction_unsupported', 'Zdaněné položky s různým nárokem na odpočet vyžadují samostatné mapování.');
            }
            $id = $this->writer->insertPurchase(new MigratedPurchaseDocument(
                supplierId: $ctx['supplier_id'],
                vendorId: $partnerId,
                vendorIsVatPayer: $liveSnapshot['is_vat_payer'],
                varsymbol: $number,
                vendorInvoiceNumber: mb_substr($vendorNumber, 0, 50),
                documentKind: (string) ($record['target_document_kind'] ?? 'invoice'),
                issueDate: $issue,
                taxDate: $tax,
                dueDate: $due,
                receivedAt: max($issue, $tax),
                receivedAtSource: 'import',
                currencyId: $currencyId,
                exchangeRate: $exchangeRate,
                pricesIncludeVat: $pricesIncludeVat,
                reverseCharge: $reverseCharge,
                vendorSnapshot: $snapshotJson,
                totalWithoutVat: $base,
                totalVat: $vat,
                totalWithVat: $total,
                rounding: $rounding,
                status: $review ? 'draft' : 'received',
                vatDeduction: $deduction,
                noteAboveItems: $sourceNote !== '' ? mb_substr($sourceNote, 0, 1000) : null,
                noteBelowItems: $note,
                createdBy: $ctx['user_id'],
            ));
        }
        $this->insertItems($kind, $id, $items, $tax);
        $this->put($ctx, $kind, $key, $hash, $id);
        $ctx['ids'][$kind][$key] = $id;
        $ctx['new_documents'][$kind][$key] = $id;
        $ctx['written'][$kind === 'issued' ? 'issued' : 'purchases']++;
    }

    /** @param list<array<string,mixed>> $items */
    /**
     * Sazba se páruje až po zápisu hlavičky a položku po položce; neplatná nebo chybějící
     * sazba shodí celý běh výjimkou (převod je jedna transakce).
     *
     * @param list<array<string,mixed>> $items
     */
    private function insertItems(string $kind, int $documentId, array $items, string $taxDate): void
    {
        foreach ($items as $i => $item) {
            $rate = (float) ($item['vat_rate_snapshot'] ?? -1);
            if ($rate < 0 || $rate > 100) throw new StereoNxException('vat_rate_invalid', 'Neplatná sazba DPH položky.');
            $rateId = $this->rateId($rate, $taxDate);
            if ($rateId === null) throw new StereoNxException('vat_rate_missing', 'Sazba DPH položky není v cílovém číselníku.');
            $description = (string) ($item['description'] ?? '');
            $quantity = (float) ($item['quantity'] ?? 1);
            $unit = (string) ($item['unit'] ?? 'ks');
            $unitPrice = (float) ($item['unit_price_without_vat'] ?? $item['unit_price'] ?? 0);
            $base = $this->money($item['total_without_vat'] ?? null);
            $vat = $this->money($item['total_vat'] ?? null);
            $gross = $this->money($item['total_with_vat'] ?? null);
            $code = $item['vat_classification_code'] ?? null;
            if ($kind === 'issued') {
                // Stereo NX převádí jen tuzemská plnění; režim OSS zdroj nezná.
                $this->writer->insertIssuedItem($documentId, MigratedDocumentItem::issued(
                    $description, $quantity, $unit, $unitPrice, $rateId, $rate, $base, $vat, $gross, $code,
                    OssMigrationPolicy::DOMESTIC_COLUMNS,
                    revenueAccountCode: $item['target_revenue_account_code'] ?? $item['revenue_account_code'] ?? null,
                ), $i);
            } else {
                $this->writer->insertPurchaseItem($documentId, MigratedDocumentItem::purchase(
                    $description, $quantity, $unit, $unitPrice, $rateId, $rate, $base, $vat, $gross, $code,
                    expenseAccountCode: $item['target_expense_account_code'] ?? $item['expense_account_code'] ?? null,
                ), $i);
            }
        }
    }

    /**
     * Nulová sazba bez DPH nepředstavuje nárok na odpočet a nesmí přebít režim
     * zdaněných položek. Smíšený nárok u dvou skutečně zdaněných položek se drží
     * v konceptu: cílový VAT ledger umí deduction jen na hlavičce dokladu.
     *
     * @param list<array<string,mixed>> $items
     * @return array{0:string,1:bool} deduction hlavičky, vyžaduje kontrolu
     */
    public static function purchaseDeduction(array $items): array
    {
        $taxableModes = [];
        foreach ($items as $item) {
            $mode = (string) ($item['vat_deduction'] ?? 'full');
            if (!in_array($mode, ['full', 'none', 'proportional', 'reduced'], true)) {
                throw new StereoNxException('vat_deduction_invalid', 'Položka má neplatný rozsah odpočtu DPH.');
            }
            $hasVat = abs((float) ($item['total_vat'] ?? 0)) >= 0.005
                || abs((float) ($item['source_self_assessed_vat'] ?? 0)) >= 0.005;
            if ($hasVat) $taxableModes[$mode] = true;
        }
        if ($taxableModes === []) return ['none', false];
        if (count($taxableModes) > 1) return ['none', true];
        return [(string) array_key_first($taxableModes), false];
    }

    /** @param array<string,mixed> $ctx @param array<string,mixed> $record */
    private function importBankAccount(array &$ctx, array $record): void
    {
        $key = $this->key($record);
        $hash = self::sourceHash($record);
        $existing = $this->mapped($ctx, 'bank_account', $key, $hash);
        if ($existing !== null) { $ctx['ids']['bank_account'][$key] = $existing; return; }
        $number = trim((string) ($record['account_number'] ?? ''));
        if ($number === '') throw new StereoNxException('bank_account_missing', 'Záloha neobsahuje číslo vlastního účtu.');
        $registered = (new BankAccountRegistrar($this->db, $this->bankAccounts))->register($ctx['supplier_id'], [
            $key => ['number' => $number, 'bank' => (string) ($record['bank_code'] ?? ''),
                'iban' => (string) ($record['iban'] ?? ''), 'currency' => (string) ($record['currency'] ?? 'CZK'),
                'label' => (string) ($record['label'] ?? 'Stereo NX'), 'suffix' => $record['target_analytic_suffix'] ?? null],
        ]);
        $id = $registered[$key] ?? null;
        if ($id === null) throw new StereoNxException('bank_account_conflict', 'Vlastní bankovní účet nelze bezpečně přiřadit vybrané firmě.');
        if (isset($record['target_analytic_suffix'])
            && ($this->bankAccounts->find($ctx['supplier_id'], $id)['analytic_suffix'] ?? null) !== $record['target_analytic_suffix']) {
            throw new StereoNxException('account_mapping_bank_collision', 'Bankovní účet nemohl získat potvrzenou analytiku.');
        }
        $this->put($ctx, 'bank_account', $key, $hash, $id);
        $ctx['ids']['bank_account'][$key] = $id;
    }

    /** @param array<string,mixed> $ctx @param array<string,mixed> $record */
    private function importBankStatement(array &$ctx, array $record): void
    {
        $key = $this->key($record);
        $hash = self::sourceHash($record);
        $existing = $this->mapped($ctx, 'bank_statement', $key, $hash);
        if ($existing !== null) { $ctx['ids']['bank_statement'][$key] = $existing; return; }
        $accountKey = (string) ($record['account_key'] ?? '');
        $accountId = $ctx['ids']['bank_account'][$accountKey] ?? null;
        if ($accountId === null) throw new StereoNxException('statement_account_missing', 'Výpis odkazuje na neznámý vlastní účet.');
        $stmt = $this->db->pdo()->prepare('SELECT account_number, bank_code, currency FROM supplier_bank_accounts WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$accountId, $ctx['supplier_id']]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($account === false) throw new StereoNxException('statement_account_missing', 'Vlastní účet nepatří vybrané firmě.');
        $date = $this->date($record['date'] ?? null);
        $number = mb_substr((string) ($record['document_no'] ?? $key), 0, 20);
        $writer = $ctx['bank_writer'] ?? new BankStatementImportWriter($this->db, 'stereo-nx');
        $id = $writer->createStatement($ctx['supplier_id'], $ctx['ico'] . '|' . $ctx['company_index'] . '|' . $key,
            $number, (string) $account['account_number'], (string) ($account['bank_code'] ?? ''),
            (string) ($account['currency'] ?: 'CZK'), $date, $ctx['user_id']);
        $this->put($ctx, 'bank_statement', $key, $hash, $id);
        $ctx['ids']['bank_statement'][$key] = $id;
        $ctx['new_statements'][$key] = $id;
        $ctx['written']['bank_statements']++;
    }

    /** @param array<string,mixed> $ctx @param array<string,mixed> $record */
    private function importBankTransaction(array &$ctx, array $record): void
    {
        $key = $this->key($record);
        $hash = self::sourceHash($record);
        $existing = $this->mapped($ctx, 'bank', $key, $hash);
        if ($existing !== null) { $ctx['ids']['bank'][$key] = $existing; return; }
        $statementId = $ctx['ids']['bank_statement'][(string) ($record['statement_key'] ?? '')] ?? null;
        if ($statementId === null) throw new StereoNxException('transaction_statement_missing', 'Bankovní pohyb nemá výpis.');
        $amount = $this->money($record['amount'] ?? null);
        $date = $this->date($record['date'] ?? null);
        $writer = $ctx['bank_writer'] ?? new BankStatementImportWriter($this->db, 'stereo-nx');
        $id = $writer->insertTransaction($ctx['supplier_id'], $statementId,
            $ctx['ico'] . '|' . $ctx['company_index'] . '|' . $key, [
                'source_ref' => mb_substr($key, 0, 190), 'posted_at' => $date,
                'amount' => number_format($amount, 2, '.', ''), 'currency' => (string) ($record['currency'] ?? 'CZK'),
                'variable_symbol' => mb_substr((string) ($record['variable_symbol'] ?? ''), 0, 10) ?: null,
                'constant_symbol' => mb_substr((string) ($record['constant_symbol'] ?? ''), 0, 10) ?: null,
                'specific_symbol' => mb_substr((string) ($record['specific_symbol'] ?? ''), 0, 10) ?: null,
                'counterparty_account' => mb_substr((string) ($record['counterparty_account'] ?? ''), 0, 40) ?: null,
                'counterparty_bank' => mb_substr((string) ($record['counterparty_bank'] ?? ''), 0, 4) ?: null,
                'counterparty_name' => mb_substr((string) ($record['counterparty_name'] ?? ''), 0, 190) ?: null,
                'description' => mb_substr((string) ($record['description'] ?? ''), 0, 255) ?: null,
                'bank_ref' => null,
            ]);
        $this->put($ctx, 'bank', $key, $hash, $id);
        $ctx['ids']['bank'][$key] = $id;
        $ctx['touched_statements'][$statementId] = $statementId;
        $ctx['written']['bank_transactions']++;
    }

    /** @param array<string,mixed> $ctx @param array<string,mixed> $record */
    private function importCashTransaction(array &$ctx, array $record): void
    {
        $key = $this->key($record);
        $hash = self::sourceHash($record);
        $amount = $this->money($record['amount'] ?? null);
        if (abs($amount) < 0.005) {
            if (($record['document_key'] ?? null) !== null) {
                throw new StereoNxException('zero_payment_invalid', 'Nulový pokladní pohyb nemůže být úhradou dokladu.');
            }
            $registerId = $this->payments->cashRegister($ctx['supplier_id'], 'Stereo NX');
            if ($this->mapped($ctx, 'cash_zero', $key, $hash) === null) {
                // Zdrojové nulové otevření pokladny nemá peněžní dopad a cílová
                // cash_documents nedovoluje posted doklad s nulovou částkou.
                $this->put($ctx, 'cash_zero', $key, $hash, $registerId);
                $ctx['written']['skipped_zero_cash']++;
            }
            $ctx['zero_cash'][$key] = true;
            return;
        }
        $existing = $this->mapped($ctx, 'cash', $key, $hash);
        if ($existing !== null) { $ctx['ids']['cash'][$key] = $existing; return; }
        $registerId = $this->payments->cashRegister($ctx['supplier_id'], 'Stereo NX');
        $date = $this->date($record['date'] ?? null);
        $draft = ($record['requires_draft'] ?? false) === true;
        $number = (string) ($record['document_no'] ?? $key);
        $number = $this->cashNumbers->allocate($ctx['supplier_id'], [$number],
            $ctx['protocol'] ?? new ImportProtocol('import'), 'cash', $number);
        $id = $this->payments->insertCash($ctx['supplier_id'], $registerId, $number, $date,
            mb_substr((string) ($record['description'] ?? 'Stereo NX'), 0, 255), $amount, $draft, $ctx['user_id']);
        $this->put($ctx, 'cash', $key, $hash, $id);
        $ctx['ids']['cash'][$key] = $id;
        $ctx['cash_numbers'][$key] = $number;
        $ctx['written']['cash_transactions']++;
    }

    /** @param array<string,mixed> $ctx @param array<string,mixed> $record */
    private function importPayment(array &$ctx, array $record): void
    {
        $key = $this->paymentKey($record);
        $hash = self::sourceHash($record);
        $kind = (string) ($record['document_kind'] ?? '');
        $movementType = (string) ($record['movement_type'] ?? '');
        if (!in_array($kind, ['issued', 'purchase'], true) || !in_array($movementType, ['bank', 'cash'], true)) {
            throw new StereoNxException('payment_link_invalid', 'Vazba úhrady má nepodporovaný druh.');
        }
        $mapKind = 'payment_' . $movementType;
        $docId = $ctx['ids'][$kind][(string) ($record['document_key'] ?? '')] ?? null;
        $movementId = $ctx['ids'][$movementType][(string) ($record['movement_key'] ?? '')] ?? null;
        if ($docId === null || $movementId === null) throw new StereoNxException('payment_target_missing', 'Úhrada odkazuje na chybějící doklad nebo pohyb.');
        $amount = $this->money($record['amount'] ?? null);
        if (abs($amount) < 0.005 || ($amount < 0 && ($movementType !== 'bank' || $kind !== 'purchase'))) {
            throw new StereoNxException('payment_amount_invalid', 'Úhrada má nulovou nebo nepodporovanou zápornou částku.');
        }
        $existing = $this->mapped($ctx, $mapKind, $key, $hash);
        if ($existing !== null) {
            $this->validateExistingPayment($ctx['supplier_id'], $kind, $movementType, $docId, $movementId, $amount, $existing);
            // Koncept mohl být mezitím vystaven; ověřená úhrada musí znovu obnovit jeho stav i datum.
            $ctx['touched_documents'][$kind][$docId] = $docId;
            return;
        }
        try {
            $linkId = $kind === 'purchase' && $movementType === 'bank'
                ? $this->purchasePayments->attachBank($ctx['supplier_id'], $ctx['user_id'], $docId, $movementId, abs($amount))
                : $this->payments->attach($ctx['supplier_id'], $ctx['user_id'], $kind, $movementType,
                    $docId, $movementId, $amount);
        } catch (\InvalidArgumentException $e) {
            if ($e->getMessage() === 'cash_payment_ambiguous') {
                throw new StereoNxException('cash_payment_ambiguous', 'Pokladní pohyb nelze jednoznačně spárovat s dokladem.');
            }
            if ($e->getMessage() === 'payment_currency_unverified') {
                throw new StereoNxException('payment_currency_unverified',
                    'Úhradu dokladu v cizí měně nelze z korunového pohybu převést bez ověřené částky v měně dokladu.');
            }
            throw new StereoNxException('payment_target_missing', 'Úhrada odkazuje na doklad nebo pohyb mimo vybranou firmu.');
        }
        $this->put($ctx, $mapKind, $key, $hash, $linkId);
        $ctx['touched_documents'][$kind][$docId] = $docId;
        if ($movementType === 'bank') {
            $statementId = $this->payments->statementId($ctx['supplier_id'], $movementId);
            if ($statementId !== null) $ctx['touched_statements'][$statementId] = $statementId;
        }
        $ctx['written']['payments']++;
    }

    private function validateExistingPayment(int $supplierId, string $kind, string $movementType,
        int $docId, int $movementId, float $amount, int $mappedId): void
    {
        if ($movementType === 'bank') {
            $column = $kind === 'issued' ? 'invoice_id' : 'purchase_invoice_id';
            $stmt = $this->db->pdo()->prepare("SELECT 1 FROM payment_matches WHERE id = ? AND supplier_id = ?
                AND bank_transaction_id = ? AND {$column} = ? AND ABS(amount - ?) < 0.01");
            $stmt->execute([$mappedId, $supplierId, $movementId, $docId, $kind === 'purchase' ? abs($amount) : $amount]);
            if ($stmt->fetchColumn() === false) throw new StereoNxException('mapped_payment_changed', 'Dříve převedená úhrada byla změněna nebo odstraněna.');
            if ($kind === 'issued') {
                $stmt = $this->db->pdo()->prepare('SELECT 1 FROM invoice_payments WHERE supplier_id = ?
                    AND invoice_id = ? AND bank_transaction_id = ? AND ABS(amount - ?) < 0.01 LIMIT 1');
                $stmt->execute([$supplierId, $docId, $movementId, $amount]);
                if ($stmt->fetchColumn() === false) throw new StereoNxException('mapped_payment_changed', 'Dříve převedená příjmová úhrada chybí.');
            }
            return;
        }
        $column = $kind === 'issued' ? 'invoice_id' : 'purchase_invoice_id';
        $stmt = $this->db->pdo()->prepare("SELECT invoice_payment_id FROM cash_documents WHERE id = ? AND supplier_id = ?
            AND id = ? AND {$column} = ? AND ABS(total_amount - ?) < 0.01 AND status = 'posted'");
        $stmt->execute([$mappedId, $supplierId, $movementId, $docId, $amount]);
        $paymentId = $stmt->fetchColumn();
        if ($paymentId === false) throw new StereoNxException('mapped_payment_changed', 'Dříve převedená hotovostní úhrada byla změněna.');
        if ($kind === 'issued') {
            $stmt = $this->db->pdo()->prepare('SELECT 1 FROM invoice_payments WHERE id = ? AND supplier_id = ?
                AND invoice_id = ? AND source = "cash" AND ABS(amount - ?) < 0.01');
            $stmt->execute([$paymentId, $supplierId, $docId, $amount]);
            if ($stmt->fetchColumn() === false) throw new StereoNxException('mapped_payment_changed', 'Dříve převedená hotovostní příjmová úhrada chybí.');
        }
    }

    /** @param array<string,mixed> $ctx @param array<string,mixed> $record */
    private function importClassification(array &$ctx, array $record): void
    {
        $kind = (string) ($record['movement_type'] ?? '');
        $sourceKey = (string) ($record['movement_key'] ?? '');
        if ($kind === 'cash' && isset($ctx['zero_cash'][$sourceKey])) return;
        $id = $ctx['ids'][$kind][$sourceKey] ?? null;
        if (!in_array($kind, ['bank', 'cash'], true) || $id === null) {
            throw new StereoNxException('classification_movement_missing', 'Zařazení peněžního deníku nemá pohyb.');
        }
        // U spárovaného dokladu přebírá daňový základ z položek kanonický peněžní deník;
        // override celého brutto by zde zdanil i DPH. Číselník Stereo zůstává jen pro
        // pohyby bez dokladu, jejichž částku nelze rozložit podle faktury.
        if (($record['document_key'] ?? null) !== null) return;
        $mapKind = 'classification_' . $kind;
        $mapKey = $sourceKey;
        $hash = self::sourceHash($record);
        if ($this->mapped($ctx, $mapKind, $mapKey, $hash) !== null) return;
        $bucket = (string) ($record['bucket'] ?? '');
        if (!in_array($bucket, MovementClassificationRepository::TAX_BUCKETS, true)) {
            throw new StereoNxException('classification_bucket_invalid', 'Zdrojový sloupec peněžního deníku nemá podporované zařazení.');
        }
        if (!$this->classifications->belongsToSupplier($ctx['supplier_id'], $kind, $id)) {
            throw new StereoNxException('classification_owner_invalid', 'Pohyb peněžního deníku nepatří vybrané firmě.');
        }
        $this->classifications->upsert($ctx['supplier_id'], $kind, $id, $bucket, 'Stereo NX', $ctx['user_id']);
        $this->put($ctx, $mapKind, $mapKey, $hash, $id);
        $ctx['written']['classifications']++;
    }

    /** @param array<string,mixed> $ctx */
    private function refreshBalances(array $ctx): void
    {
        $this->payments->refreshBalances($ctx['supplier_id'],
            [...array_values($ctx['new_documents']['issued']), ...array_values($ctx['touched_documents']['issued'])],
            [], [...array_values($ctx['new_statements']), ...array_values($ctx['touched_statements'])]);
        $purchases = array_values(array_unique([
            ...array_values($ctx['new_documents']['purchase']), ...array_values($ctx['touched_documents']['purchase']),
        ]));
        try {
            $this->purchasePayments->refreshBalances($ctx['supplier_id'], $purchases);
        } catch (\InvalidArgumentException $e) {
            throw match ($e->getMessage()) {
                'payment_target_missing' => new StereoNxException('payment_target_missing', 'Převáděný závazek nepatří vybrané firmě.'),
                'payment_currency_unverified' => new StereoNxException('payment_currency_unverified', 'Úhrada závazku nemá ověřenou měnu.'),
                default => $e,
            };
        }
    }

    /** Stejná kontrola otisku a vlastnictví pro doklad i jeho následné úhrady. */
    private function mappedDocument(array $ctx, string $kind, array $record): ?int
    {
        return $this->mapped($ctx, $kind, $this->key($record), self::sourceHash($record), [
            self::sourceHash($record, false), self::sourceHash($record, true, true),
            self::sourceHash($record, false, true),
        ]);
    }

    /** @param array<string,mixed> $ctx */
    private function mapped(array $ctx, string $kind, string $key, string $hash, string|array|null $alternateHash = null): ?int
    {
        $row = $this->map->get($ctx['supplier_id'], $ctx['ico'], $ctx['company_index'], $kind, $key);
        if ($row === null) return null;
        $alternates = is_array($alternateHash) ? $alternateHash : ($alternateHash === null ? [] : [$alternateHash]);
        if (!hash_equals($row['source_hash'], $hash)
            && !array_any($alternates, static fn (string $candidate): bool => hash_equals($row['source_hash'], $candidate))) {
            throw new StereoNxException('source_changed', 'Zdrojový záznam se od předchozího převodu změnil; proveďte ruční kontrolu.');
        }
        $table = match ($kind) {
            'client' => 'clients', 'issued' => 'invoices', 'purchase' => 'purchase_invoices',
            'bank_account' => 'supplier_bank_accounts',
            'bank_statement' => 'bank_statements', 'bank' => 'bank_transactions', 'cash' => 'cash_documents',
            'classification_bank' => 'bank_transactions', 'classification_cash' => 'cash_documents',
            'cash_zero' => 'cash_registers',
            'payment_bank' => 'payment_matches', 'payment_cash' => 'cash_documents', default => null,
        };
        if ($table === null) return $row['target_id'];
        if ($kind === 'bank' || $kind === 'classification_bank') {
            $stmt = $this->db->pdo()->prepare('SELECT 1 FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id
                WHERE bt.id = ? AND bs.supplier_id = ?');
        } elseif ($kind === 'payment_bank') {
            $stmt = $this->db->pdo()->prepare('SELECT 1 FROM payment_matches WHERE id = ? AND supplier_id = ?');
        } else {
            $stmt = $this->db->pdo()->prepare("SELECT 1 FROM {$table} WHERE id = ? AND supplier_id = ?");
        }
        $stmt->execute([$row['target_id'], $ctx['supplier_id']]);
        if ($stmt->fetchColumn() === false) throw new StereoNxException('mapped_target_missing', 'Dříve převedený objekt v cílové firmě chybí.');
        return $row['target_id'];
    }

    /** @param array<string,mixed> $ctx */
    private function put(array $ctx, string $kind, string $key, string $hash, int $targetId): void
    {
        $this->map->put($ctx['supplier_id'], $ctx['ico'], $ctx['company_index'], $kind, $key, $hash, $targetId);
    }

    /** @param array<string,mixed> $record */
    private function key(array $record): string
    {
        $key = $record['source_key'] ?? null;
        if (!is_string($key) || $key === '') throw new StereoNxException('source_key_missing', 'Zdrojový záznam nemá složený klíč.');
        return $key;
    }

    /** @param array<string,mixed> $record */
    private function paymentKey(array $record): string
    {
        return json_encode([
            (string) ($record['movement_type'] ?? ''), (string) ($record['movement_key'] ?? ''),
            (string) ($record['document_kind'] ?? ''), (string) ($record['document_key'] ?? ''),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Zdrojové NX hlavičky obsahují mutující souhrny úhrad a čas změny. Novější
     * záloha smí přidat platbu ke stejnému dokladu a řádky ke stejnému výpisu;
     * hash porovnává jejich kanonický obsah, ne raw hlavičku programu.
     * @param array<string,mixed> $record
     */
    public static function sourceHash(array $record, bool $legacyEuReview = true, bool $omitDefaultDocumentFields = false): string
    {
        // Ověřené korunové částky cizoměnového dokladu jsou součástí identity zdroje.
        // Změna těchto důkazů musí zastavit opakovaný převod před vrácením starého mapování.
        unset($record['row'], $record['header']);
        // Potvrzené cílové účty jsou výsledek převodu, nikoli změna zdrojové zálohy.
        unset($record['target_analytic_suffix'], $record['target_items']);
        foreach ($record['items'] ?? [] as $index => $item) {
            unset($record['items'][$index]['target_expense_account_code'],
                $record['items'][$index]['target_revenue_account_code']);
        }
        if ($omitDefaultDocumentFields) {
            // Kompatibilní otisk první verze plánu, která tato odvozená pole neměla.
            // Odlišná měna, kurz nebo dobropis v otisku naopak zůstávají.
            if (($record['currency_code'] ?? null) === 'CZK') unset($record['currency_code']);
            if (array_key_exists('exchange_rate', $record) && $record['exchange_rate'] === null) unset($record['exchange_rate']);
            if (($record['target_document_kind'] ?? null) === 'invoice') unset($record['target_document_kind']);
        }
        if ($legacyEuReview && isset($record['review_codes']) && is_array($record['review_codes'])) {
            // Pouhé zpřesnění textu „EU“ nesmí změnit identitu již převedeného
            // dokladu. Všechny ostatní zdrojové hodnoty zůstávají součástí otisku.
            $record['review_codes'] = array_map(
                static fn (mixed $code): mixed => $code === 'partner_country_eu_unspecified'
                    ? 'partner_country_unresolved' : $code,
                $record['review_codes'],
            );
        }
        return StereoNxImportMap::fingerprint($record);
    }

    private function date(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new StereoNxException('date_invalid', 'Zdrojový záznam má neplatné datum.');
        }
        return $value;
    }

    private function money(mixed $value): float
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || abs($value) > 9999999999) {
            throw new StereoNxException('money_invalid', 'Zdrojový záznam má neplatnou částku.');
        }
        return round((float) $value, 2);
    }

    private function currencyId(int $supplierId): int
    {
        $id = $this->homeCurrency->id($supplierId, true);
        if ($id === 0) throw new StereoNxException('currency_missing',
            'Cílová firma nemá měnu CZK; založte ji v Nastavení měn a převod zopakujte.');
        return $id;
    }

    private function currencyIdForCode(int $supplierId, string $code): int
    {
        if (preg_match('/^[A-Z]{3}$/D', $code) !== 1) {
            throw new StereoNxException('currency_invalid', 'Doklad má neplatnou měnu.');
        }
        $id = $this->homeCurrency->idForCode($supplierId, $code);
        if ($id === 0) throw new StereoNxException('currency_missing',
            'Cílová firma nemá měnu ' . $code . '; založte ji v Nastavení měn a převod zopakujte.');
        return $id;
    }

    private function countryId(string $code): int
    {
        if (preg_match('/^[A-Z]{2}$/D', $code) !== 1) return 0;
        $stmt = $this->db->pdo()->prepare('SELECT id FROM countries WHERE iso2 = ? LIMIT 1');
        $stmt->execute([$code]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function rateId(float $rate, string $date): ?int
    {
        // Historický NX doklad může předcházet valid_from cílového seed řádku.
        // Stejně jako Premier/Pohoda hledáme nejprve časově platnou sazbu a potom
        // stejnou nominální sazbu; rozhodující historické procento drží položka
        // ve vat_rate_snapshot, nikoli dnešní validita číselníku.
        return $this->rates->find($rate, $date);
    }

    /** @return array{name:string,ico:string,dic:string,street:string,city:string,zip:string,country_code:string,is_vat_payer:bool} */
    private function clientSnapshot(int $supplierId, int $clientId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT c.company_name, c.ic, c.dic, c.street, c.city, c.zip,
            c.is_vat_payer, co.iso2 AS country_code FROM clients c JOIN countries co ON co.id = c.country_id
            WHERE c.id = ? AND c.supplier_id = ?');
        $stmt->execute([$clientId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) throw new StereoNxException('client_missing', 'Partner dokladu nepatří vybrané firmě.');
        return ['name' => (string) $row['company_name'], 'ico' => (string) ($row['ic'] ?? ''),
            'dic' => (string) ($row['dic'] ?? ''), 'street' => (string) $row['street'],
            'city' => (string) $row['city'], 'zip' => (string) $row['zip'],
            'country_code' => (string) $row['country_code'],
            'is_vat_payer' => (int) $row['is_vat_payer'] === 1];
    }

}
