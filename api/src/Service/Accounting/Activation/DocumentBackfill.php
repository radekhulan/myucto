<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Activation;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\TakenOverRecord;
use PDO;

final class DocumentBackfill
{
    public function __construct(
        private readonly Connection $db,
        private readonly PostingService $posting,
        private readonly ChartOfAccountsSeeder $seeder,
        private readonly AccountingPeriodRepository $periods,
    ) {}

    /**
     * @param (callable(int,int,array<string,array<string,int>>):void)|null $onProgress
     *        fn(zpracováno, celkem, čítače) — hlášení průběhu pro běh na pozadí
     *        ({@see \MyInvoice\Service\Accounting\PostingBackfillJobService}). Průvodce
     *        aktivací ho nepotřebuje: má vlastní fáze a hlásí je po nich.
     * @param bool $onlyUnposted jen doklady BEZ aktivního zápisu (doúčtování). Bez něj se
     *        existující zápis přepíše (`postDocument` → `rewriteExisting`) — to potřebuje
     *        průvodce aktivací pro zúčtování záloh, ne doúčtování: přepis vrací hlavičku
     *        (popis, posted_at/by) na generické hodnoty a smaže ruční úpravy zápisu.
     *
     * Doklad převzatý z jiného účetního programu ({@see TakenOverRecord}) se nezaúčtuje ani
     * nepřepíše v žádném režimu: jeho předpis je v převzatém deníku (i když na doklad není
     * navázaný) a nový zápis by výnos, náklad i DPH zapsal podruhé. Počet vynechaných vrací
     * `taken_over`; počítadlo čekajících ({@see PendingBackfillCounter}) je nehlásí.
     */
    public function run(
        int $supplierId,
        ?string $from,
        ?int $year,
        bool $dryRun,
        bool $asDrafts = false,
        ?callable $onLog = null,
        ?callable $isCancelled = null,
        bool $settlementsOnly = false,
        ?callable $onProgress = null,
        bool $onlyUnposted = false,
    ): array {
        $pdo = $this->db->pdo();
        $emit = static function (string $line) use ($onLog): void {
            if ($onLog !== null) $onLog($line);
        };

        if (!$dryRun) {
            $seeded = $this->seeder->seedForSupplier($supplierId);
            $emit("Osnova: naseedováno {$seeded} nových účtů (idempotentně).\n");
        }

        $dateWhere = '';
        $bind = ['sid' => $supplierId];
        if ($year !== null) {
            $dateWhere .= ' AND YEAR(COALESCE(tax_date, issue_date)) = :yr';
            $bind['yr'] = $year;
        }
        if ($from !== null) {
            $dateWhere .= ' AND COALESCE(tax_date, issue_date) >= :from_date';
            $bind['from_date'] = $from;
        }

        $invStmt = $pdo->prepare(
            "SELECT i.id, i.varsymbol AS doc_no, i.issue_date, i.tax_date,
                    COALESCE(i.tax_date, i.issue_date) AS entry_date
               FROM invoices i
              WHERE i.supplier_id = :sid{$dateWhere}
                AND " . self::scopeSql('invoice', 'i')
                . ($settlementsOnly
                    ? " AND EXISTS (
                            SELECT 1 FROM invoices parent
                             WHERE parent.id = i.parent_invoice_id
                               AND parent.supplier_id = i.supplier_id
                               AND parent.invoice_type = 'proforma'
                        )"
                    : '')
                . ($onlyUnposted
                    ? ' AND NOT ' . DocumentPostingGroup::activePostingExistsSql("'invoice'", 'i.id', 'i.supplier_id', false)
                    : '') . "
           ORDER BY entry_date, i.id"
        );
        $invStmt->execute($bind);
        $invoices = $invStmt->fetchAll(PDO::FETCH_ASSOC);

        $piStmt = $pdo->prepare(
            "SELECT pi.id, pi.vendor_invoice_number AS doc_no, pi.issue_date, pi.tax_date,
                    COALESCE(pi.tax_date, pi.issue_date) AS entry_date
               FROM purchase_invoices pi
              WHERE pi.supplier_id = :sid{$dateWhere}
                AND " . self::scopeSql('purchase_invoice', 'pi')
                . ($settlementsOnly
                    ? " AND EXISTS (
                            SELECT 1 FROM purchase_invoices adv
                             WHERE adv.id = pi.advance_purchase_invoice_id
                               AND adv.supplier_id = pi.supplier_id
                               AND adv.document_kind = 'advance'
                        )"
                    : '')
                . ($onlyUnposted
                    ? ' AND NOT ' . DocumentPostingGroup::activePostingExistsSql("'purchase_invoice'", 'pi.id', 'pi.supplier_id', false)
                    : '') . "
           ORDER BY entry_date, pi.id"
        );
        $piStmt->execute($bind);
        $purchases = $piStmt->fetchAll(PDO::FETCH_ASSOC);

        $takenOver = $this->takenOverCount($bind, $dateWhere, $onlyUnposted);
        $emit('Ke zpracování: ' . count($invoices) . ' vydaných + ' . count($purchases) . " přijatých faktur.\n");
        if ($takenOver['invoice'] + $takenOver['purchase_invoice'] > 0) {
            $emit('Převzato z jiného účetního programu, nezaúčtuje se ani nepřepíše: ' . $takenOver['invoice']
                . ' vydaných + ' . $takenOver['purchase_invoice'] . " přijatých faktur.\n");
        }
        $emit("\n");

        $stats = [
            'invoice' => ['posted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0],
            'purchase_invoice' => ['posted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0],
        ];
        // Pokrytí pro kontrolu úplnosti: `pending` = zpracované doklady, které na začátku
        // neměly aktivní zápis (tutéž množinu počítá {@see PendingBackfillCounter}),
        // `already_posted` = doklady se zápisem, které průvodce jen přepisuje.
        $coverage = [
            'invoice' => ['pending' => 0, 'already_posted' => 0],
            'purchase_invoice' => ['pending' => 0, 'already_posted' => 0],
        ];
        $skipReasons = [];
        $documentIssues = [];
        $cancelled = false;
        $processed = 0;
        $existsStmt = $pdo->prepare(
            'SELECT ' . DocumentPostingGroup::activePostingExistsSql('?', '?', '?', false, false)
        );
        $ensuredYears = [];
        // Kontrola nanečisto nic nezapisuje, takže na zámek k datu by jinak nenarazila
        // a ostrý běh by teprve přeskočil, co kontrola hlásila jako připravené.
        $dryRunLockedUntil = $dryRun ? $this->posting->currentLockedUntil($supplierId) : null;

        $total = count($invoices) + count($purchases);
        $report = static function () use (&$processed, &$stats, $total, $onProgress): void {
            if ($onProgress !== null) $onProgress($processed, $total, $stats);
        };
        $report();

        $process = function (string $sourceType, array $doc) use (
            &$stats, &$coverage, &$skipReasons, &$documentIssues, &$processed, &$ensuredYears, &$cancelled,
            $supplierId, $dryRun, $asDrafts, $isCancelled, $existsStmt, $pdo, $emit, $report, $onlyUnposted,
            $dryRunLockedUntil
        ): void {
            if ($isCancelled !== null && $isCancelled()) {
                $cancelled = true;
                return;
            }
            $processed++;
            $id = (int) $doc['id'];
            $entryDate = (string) $doc['entry_date'];
            $label = ($sourceType === 'invoice' ? 'FV' : 'PF') . " #{$id} ({$doc['doc_no']}, {$entryDate})";
            // Zjišťuje se před sestavením předpisu: i doklad, u kterého sestavení selže,
            // se musí započítat do správné části kontroly úplnosti.
            $existsStmt->execute([$supplierId, $sourceType, $id, $supplierId, $sourceType, $id]);
            $wasThere = (bool) $existsStmt->fetchColumn();
            $coverage[$sourceType][$wasThere ? 'already_posted' : 'pending']++;

            try {
                $lines = $sourceType === 'invoice'
                    ? $this->posting->buildFromInvoice($supplierId, $id, ['entry_date' => $entryDate])
                    : $this->posting->buildFromPurchaseInvoice($supplierId, $id, ['entry_date' => $entryDate]);
                PostingService::assertBalanced($lines);

                if ($dryRun) {
                    if ($dryRunLockedUntil !== null && $entryDate <= $dryRunLockedUntil) {
                        throw PostingService::dateLockedException($entryDate, $dryRunLockedUntil);
                    }
                    $balance = PostingService::balanceCents($lines);
                    $stats[$sourceType][$wasThere ? 'updated' : 'posted']++;
                    $emit(sprintf("  [OK]   %-38s řádků=%d  MD=D=%s Kč\n", $label, count($lines), number_format($balance['debit'] / 100, 2, ',', ' ')));
                    return;
                }

                $yearKey = (int) substr($entryDate, 0, 4);
                if (!isset($ensuredYears[$yearKey])) {
                    $this->periods->ensureOpenPeriodFor($supplierId, $entryDate);
                    $ensuredYears[$yearKey] = true;
                }
                // Popis ani číslo dokladu záměrně nepředáváme: postDocument je složí stejně
                // jako u automatiky (defaultDescription, DocumentEntryNumber).
                $entryId = $this->posting->postDocument($supplierId, $sourceType, $id, $lines, [
                    'entry_date' => $entryDate,
                    'document_date' => $doc['issue_date'] ?? null,
                    'posted' => !$asDrafts,
                    // Doúčtování převzatý předpis ve skupině nepřepisuje. Průvodce aktivací
                    // přepisuje jako dřív; rozdělenou skupinu nahlásí jako chybu dokladu.
                    'posting_group_idempotent' => $onlyUnposted,
                ]);

                if (!$asDrafts) {
                    $table = $sourceType === 'invoice' ? 'invoices' : 'purchase_invoices';
                    $pdo->prepare(
                        "UPDATE {$table} d
                            JOIN journal_entries je
                              ON je.id = ? AND je.supplier_id = d.supplier_id
                             AND je.source_type = ? AND je.source_id = d.id
                             SET d.booked_at = COALESCE(d.booked_at, je.posted_at),
                                 d.booked_by = COALESCE(d.booked_by, je.posted_by)
                           WHERE d.id = ? AND d.supplier_id = ?"
                    )->execute([$entryId, $sourceType, $id, $supplierId]);
                }

                $stats[$sourceType][$wasThere ? 'updated' : 'posted']++;
                $emit(sprintf($wasThere ? "  [UPD]  %-38s → zápis #%d\n" : "  [NEW]  %-38s → zápis #%d\n", $label, $entryId));
            } catch (PostingException $e) {
                $reason = match ($e->errorCode) {
                    'period_not_open' => 'period_closed',
                    default => $e->errorCode,
                };
                if (in_array($e->errorCode, ['document_not_postable', 'advance_payment_only', 'date_locked', 'period_not_open'], true)) {
                    $stats[$sourceType]['skipped']++;
                    $skipReasons[$reason] = ($skipReasons[$reason] ?? 0) + 1;
                    $documentIssues[] = $this->documentIssue($sourceType, $doc, 'skipped', $reason, $e->getMessage(), $wasThere);
                    $emit(sprintf("  [SKIP] %-38s %s\n", $label, $e->getMessage()));
                    return;
                }
                $stats[$sourceType]['failed']++;
                $documentIssues[] = $this->documentIssue($sourceType, $doc, 'failed', $e->errorCode, $e->getMessage(), $wasThere);
                $emit(sprintf("  [FAIL] %-38s %s: %s\n", $label, $e->errorCode, $e->getMessage()));
            } catch (\Throwable $e) {
                $stats[$sourceType]['failed']++;
                // Text databázové chyby do reportu nepatří (nese SQL a názvy tabulek);
                // ostatní výjimky mají srozumitelnou větu, kterou uživatel potřebuje vidět.
                $documentIssues[] = $this->documentIssue(
                    $sourceType,
                    $doc,
                    'failed',
                    'processing_error',
                    $e instanceof \PDOException
                        ? 'Doklad se nepodařilo zpracovat kvůli chybě databáze.'
                        : 'Doklad se nepodařilo zpracovat: ' . mb_substr($e->getMessage(), 0, 300),
                    $wasThere,
                );
                $emit(sprintf("  [FAIL] %-38s %s\n", $label, $e->getMessage()));
            }
        };

        if ($invoices !== []) {
            $emit("Vydané faktury:\n");
            foreach ($invoices as $doc) {
                $process('invoice', $doc);
                $report();
                if ($cancelled) break;
            }
            $emit("\n");
        }
        if (!$cancelled && $purchases !== []) {
            $emit("Přijaté faktury:\n");
            foreach ($purchases as $doc) {
                $process('purchase_invoice', $doc);
                $report();
                if ($cancelled) break;
            }
            $emit("\n");
        }

        $emit("═══ KONTROLNÍ REPORT ═══════════════════════════════════════════\n");
        $verb = $dryRun ? 'postovatelné' : 'nové';
        foreach (['invoice' => 'Vydané faktury', 'purchase_invoice' => 'Přijaté faktury'] as $type => $label) {
            $s = $stats[$type];
            $emit(sprintf("  %-16s  %s=%d  updated=%d  skipped=%d  failed=%d\n", $label, $verb, $s['posted'], $s['updated'], $s['skipped'], $s['failed']));
        }

        $balStmt = $pdo->prepare(
            "SELECT CAST(ROUND(COALESCE(SUM(CASE WHEN side = 'debit' THEN signed_amount END), 0) * 100) AS SIGNED) AS debit_cents,
                    CAST(ROUND(COALESCE(SUM(CASE WHEN side = 'credit' THEN signed_amount END), 0) * 100) AS SIGNED) AS credit_cents
               FROM journal_entry_lines WHERE supplier_id = ?"
        );
        $balStmt->execute([$supplierId]);
        $balance = $balStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $debitCents = (int) ($balance['debit_cents'] ?? 0);
        $creditCents = (int) ($balance['credit_cents'] ?? 0);
        $balanced = $debitCents === $creditCents;
        $emit("───────────────────────────────────────────────────────────────\n");
        $emit(sprintf("  BALANCE CHECK (celý deník firmy #%d):\n", $supplierId));
        $emit(sprintf("    Σ MD = %s Kč\n", number_format($debitCents / 100, 2, ',', ' ')));
        $emit(sprintf("    Σ D  = %s Kč\n", number_format($creditCents / 100, 2, ',', ' ')));
        $emit(sprintf("    → %s\n", $balanced ? 'PASS (podvojnost sedí)' : 'FAIL (deník NENÍ vyrovnaný — rozdíl ' . number_format(($debitCents - $creditCents) / 100, 2, ',', ' ') . ' Kč!)'));
        $emit("═══════════════════════════════════════════════════════════════\n");
        if ($dryRun) $emit("\n(dry-run — nic nebylo zapsáno; pro ostrý běh spusť bez --dry-run)\n");

        return $stats + [
            'coverage' => $coverage,
            'taken_over' => $takenOver,
            'skip_reasons' => $skipReasons,
            'document_issues' => $documentIssues,
            'balance' => ['debit_cents' => $debitCents, 'credit_cents' => $creditCents, 'balanced' => $balanced],
            'processed' => $processed,
            'cancelled' => $cancelled,
        ];
    }

    /**
     * Převzaté doklady, které guard z dávky vynechal (týž rozsah dat a typů).
     *
     * @param array<string,mixed> $bind
     * @return array{invoice:int, purchase_invoice:int}
     */
    private function takenOverCount(array $bind, string $dateWhere, bool $onlyUnposted): array
    {
        $unposted = static fn (string $type, string $alias): string => $onlyUnposted
            ? ' AND NOT ' . DocumentPostingGroup::activePostingExistsSql("'{$type}'", "{$alias}.id", "{$alias}.supplier_id", false)
            : '';
        $pdo = $this->db->pdo();
        $invoices = $pdo->prepare(
            "SELECT COUNT(*) FROM invoices i
              WHERE i.supplier_id = :sid{$dateWhere}
                AND " . self::baseScopeSql('invoice', 'i') . '
                AND ' . TakenOverRecord::documentSql('invoice', 'i') . $unposted('invoice', 'i')
        );
        $invoices->execute($bind);
        $purchases = $pdo->prepare(
            "SELECT COUNT(*) FROM purchase_invoices pi
              WHERE pi.supplier_id = :sid{$dateWhere}
                AND " . self::baseScopeSql('purchase_invoice', 'pi') . '
                AND ' . TakenOverRecord::documentSql('purchase_invoice', 'pi') . $unposted('purchase_invoice', 'pi')
        );
        $purchases->execute($bind);
        return ['invoice' => (int) $invoices->fetchColumn(), 'purchase_invoice' => (int) $purchases->fetchColumn()];
    }

    /**
     * Doklady, které doúčtování zpracuje (bez omezení datem a bez ohledu na existující
     * zápis). Jediná definice rozsahu pro běh i pro {@see PendingBackfillCounter}: kontrola
     * úplnosti aktivace porovnává právě tyto dvě strany, takže odlišný filtr na jedné
     * z nich se projeví jako chybějící doklady.
     */
    public static function scopeSql(string $sourceType, string $alias): string
    {
        return self::baseScopeSql($sourceType, $alias) . ' AND NOT ' . TakenOverRecord::documentSql($sourceType, $alias);
    }

    /** Rozsah dokladů včetně převzatých z jiného programu ({@see TakenOverRecord}). */
    private static function baseScopeSql(string $sourceType, string $alias): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $alias) !== 1) {
            throw new \InvalidArgumentException('Neplatný alias.');
        }
        return match ($sourceType) {
            // Fakturu krytou ostatní pohledávkou (migrace 2246) účtuje zápis pohledávky.
            'invoice' => "{$alias}.status NOT IN ('draft','cancelled')
                AND {$alias}.other_item_id IS NULL
                AND {$alias}.invoice_type IN ('" . implode("','", PostingService::POSTABLE_ISSUED_INVOICE_TYPES) . "')
                AND " . OpeningBalanceDocuments::notInOpeningSql('invoice', $alias),
            'purchase_invoice' => "{$alias}.status IN ('received','booked','paid')
                AND {$alias}.document_kind <> 'advance'
                AND " . OpeningBalanceDocuments::notInOpeningSql('purchase_invoice', $alias),
            default => throw new \InvalidArgumentException('Neplatný typ dokladu.'),
        };
    }

    private function documentIssue(string $sourceType, array $doc, string $severity, string $errorCode, string $message, bool $hadPosting): array
    {
        return [
            'source_type' => $sourceType,
            'source_id' => (int) $doc['id'],
            'document_no' => trim((string) ($doc['doc_no'] ?? '')),
            'entry_date' => (string) ($doc['entry_date'] ?? ''),
            'severity' => $severity,
            'error_code' => $errorCode,
            'message' => $message,
            // Doklad už měl platný zápis: přeskočení znamená jen, že se nepřepsal,
            // ne že v deníku chybí.
            'had_posting' => $hadPosting,
        ];
    }
}
