<?php

declare(strict_types=1);

namespace MyInvoice\Service\Sample;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Service\Document\DocumentStorage;
use PDO;

/**
 * Práce s evidencí ukázkových (sample) dat — zjištění existence, souhrn a přesné odebrání.
 *
     * Sample data (obchodní doklady, deník, banka včetně nového registru účtu, sklad,
     * dlouhodobý i drobný majetek, pravidelné fakturace a kniha jízd) zapisuje {@see SampleDataGenerator} a každou kořenovou
 * entitu eviduje v tabulce `sample_data_entries`. Díky tomu je lze později smazat na milimetr
 * přesně (issue #162) a tatáž evidence řídí zobrazení tlačítka „Odebrat ukázková data" v UI.
 *
 * Mazání respektuje FK: entity s RESTRICT vazbou na clients (invoices, projects,
 * purchase_invoices, recurring) se mažou PŘED klienty; fuelings PŘED autem (auto je jinak
 * jen SET NULL, tankování by osiřela). Zbytek (items, PDF, cache, dobropisy přes
 * parent_invoice_id) padá kaskádou. Celé v jedné transakci — když je některá sample entita
 * navázaná na uživatelova reálná data (RESTRICT), purge se vrátí celý a nahlásí to.
 */
final class SampleDataService
{
    public function __construct(
        private readonly Connection $db,
        private readonly DocumentRepository $documents,
        private readonly DocumentStorage $documentStorage,
        private readonly SamplePayrollPurger $payrollPurger,
        private readonly SampleDimensionPurger $dimensionPurger,
    ) {}

    /** Existují pro daného dodavatele evidovaná sample data? (řídí zobrazení tlačítka v UI) */
    public function hasSampleData(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT EXISTS(SELECT 1 FROM sample_data_entries WHERE supplier_id = ?)'
        );
        $stmt->execute([$supplierId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Souhrn evidovaných sample entit dle typu (co bylo vygenerováno).
     *
     * @return array{has:bool, total:int, counts:array<string,int>}
     */
    public function summary(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT entity_type, COUNT(*) AS cnt
               FROM sample_data_entries
              WHERE supplier_id = ?
           GROUP BY entity_type'
        );
        $stmt->execute([$supplierId]);

        $counts = [];
        $total = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $c = (int) $row['cnt'];
            $counts[(string) $row['entity_type']] = $c;
            $total += $c;
        }

        return ['has' => $total > 0, 'total' => $total, 'counts' => $counts];
    }

    /**
     * Smaže všechna evidovaná sample data daného dodavatele. Vrací počty skutečně smazaných
     * řádků po hlavních tabulkách. Atomické — při FK konfliktu (sample entita navázaná na
     * reálná data) se transakce vrátí a vyhodí výjimku.
     *
     * @return array<string,int>
     */
    public function purge(int $supplierId, ?callable $preflight = null): array
    {
        $pdo = $this->db->pdo();

        $idsOf = function (array $types) use ($pdo, $supplierId): array {
            $place = implode(',', array_fill(0, count($types), '?'));
            $stmt = $pdo->prepare(
                "SELECT entity_id FROM sample_data_entries
                  WHERE supplier_id = ? AND entity_type IN ($place)"
            );
            $stmt->execute([$supplierId, ...$types]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        };

        $invoiceIds  = $idsOf(['invoice', 'credit_note']);
        $projectIds  = $idsOf(['project']);
        $clientIds   = $idsOf(['client', 'vendor']);
        $purchaseIds = $idsOf(['purchase_invoice']);
        $recurringIds = $idsOf(['recurring_template']);
        $carIds      = $idsOf(['car']);
        $journalIds  = $idsOf(['journal_entry']);
        $statementIds = $idsOf(['bank_statement']);
        $bankAccountIds = $idsOf(['supplier_bank_account']);
        $assetIds    = $idsOf(['asset']);
        $smallAssetIds = $idsOf(['small_asset']);
        $stockDocumentIds = $idsOf(['stock_document']);
        $stockItemIds = $idsOf(['stock_item']);
        $warehouseIds = $idsOf(['warehouse']);
        $cashDocumentIds = $idsOf(['cash_document']);
        $cashRegisterIds = $idsOf(['cash_register']);
        $manufacturerIds = $idsOf(['manufacturer']);
        $stockCategoryIds = $idsOf(['stock_category']);
        $documentIds = $idsOf(['document']);
        $offsetIds = $idsOf(['credit_note_offset']);
        $expenseRuleIds = $idsOf(['expense_rule']);
        $bankRuleIds = $idsOf(['bank_posting_rule']);
        $stmt = $pdo->prepare(
            "SELECT DISTINCT je.id FROM journal_entries je JOIN sample_data_entries sde
               ON sde.supplier_id=je.supplier_id AND sde.entity_id=je.source_id
              AND (sde.entity_type=je.source_type OR (sde.entity_type='credit_note' AND je.source_type='invoice'))
              WHERE je.supplier_id=?"
        );
        $stmt->execute([$supplierId]);
        $journalIds = array_merge($journalIds, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        if ($statementIds !== []) {
            $place = implode(',', array_fill(0, count($statementIds), '?'));
            $stmt = $pdo->prepare(
                "SELECT je.id FROM journal_entries je JOIN bank_transactions bt ON bt.id=je.source_id
                   JOIN bank_statements bs ON bs.id=bt.statement_id
                  WHERE je.supplier_id=? AND je.source_type='bank' AND bs.supplier_id=? AND bs.id IN ($place)"
            );
            $stmt->execute([$supplierId, $supplierId, ...$statementIds]);
            $journalIds = array_merge($journalIds, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        }
        $journalIds = array_values(array_unique($journalIds));
        $documentFiles = [];
        if ($documentIds !== []) {
            $place = implode(',', array_fill(0, count($documentIds), '?'));
            $stmt = $pdo->prepare("SELECT sha256,filename,thumb_path FROM documents WHERE supplier_id=? AND id IN ($place)");
            $stmt->execute([$supplierId, ...$documentIds]);
            $documentFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $pdo->prepare("SELECT sha256,filename,NULL AS thumb_path FROM document_files WHERE supplier_id=? AND document_id IN ($place)");
            $stmt->execute([$supplierId, ...$documentIds]);
            $documentFiles = array_merge($documentFiles, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        // Smaže rodičovské řádky podle id seznamu; děti padají kaskádou (viz třídní docblock).
        // $hasSupplierCol=false pro tabulky bez supplier_id (projects se váže přes client_id);
        // id pocházejí z sample_data_entries, takže jsou už scoped na tohoto dodavatele.
        $deleteByIds = function (string $table, array $ids, bool $hasSupplierCol = true) use ($pdo, $supplierId): int {
            if ($ids === []) return 0;
            $place = implode(',', array_fill(0, count($ids), '?'));
            if ($hasSupplierCol) {
                $stmt = $pdo->prepare("DELETE FROM `$table` WHERE supplier_id = ? AND id IN ($place)");
                $stmt->execute([$supplierId, ...$ids]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM `$table` WHERE id IN ($place)");
                $stmt->execute($ids);
            }
            return $stmt->rowCount();
        };

        $result = [
            'clients' => 0, 'projects' => 0, 'invoices' => 0, 'purchase_invoices' => 0,
            'recurring' => 0, 'cars' => 0, 'fuelings' => 0, 'trips' => 0,
            'journal_entries' => 0, 'bank_statements' => 0, 'assets' => 0,
            'small_assets' => 0,
            'bank_accounts' => 0, 'activity_logs' => 0, 'stock_documents' => 0,
            'stock_items' => 0, 'warehouses' => 0, 'cash_documents' => 0,
            'cash_registers' => 0, 'manufacturers' => 0, 'eshop_categories' => 0,
            'ai_jobs' => 0, 'accounting_corrections' => 0,
            'invoice_counters' => 0, 'purchase_invoice_counters' => 0,
            'documents' => 0, 'document_links' => 0,
        ];

        $preflightPassed = false;
        $pdo->beginTransaction();
        try {
            if ($preflight !== null) $preflight();
            $preflightPassed = true;
            $stmt = $pdo->prepare(
                "DELETE al FROM activity_log al
                  WHERE al.supplier_id=?
                    AND (EXISTS (SELECT 1 FROM sample_data_entries sde WHERE sde.supplier_id=al.supplier_id AND sde.entity_type=al.entity_type AND sde.entity_id=al.entity_id)
                      OR (al.entity_type='payroll_employee' AND EXISTS (SELECT 1 FROM sample_data_entries sde WHERE sde.supplier_id=al.supplier_id AND sde.entity_type='payroll_person' AND sde.entity_id=al.entity_id)))"
            );
            $stmt->execute([$supplierId]);
            $result['activity_logs'] += $stmt->rowCount();
            $result = array_merge($result, $this->dimensionPurger->purge($supplierId, $journalIds));
            $result = array_merge($result, $this->payrollPurger->purge($supplierId));
            $result['credit_note_offsets'] = $deleteByIds('credit_note_offsets', $offsetIds);
            $result['expense_rules'] = $deleteByIds('expense_classification_rules', $expenseRuleIds);
            $result['bank_posting_rules'] = $deleteByIds('bank_posting_rules', $bankRuleIds);
            $result['documents'] = $deleteByIds('documents', $documentIds);
            $stmt = $pdo->prepare(
                "DELETE dl FROM document_links dl JOIN sample_data_entries sde
                   ON sde.supplier_id=dl.supplier_id AND sde.entity_type=dl.entity_type AND sde.entity_id=dl.entity_id
                  WHERE dl.supplier_id=?"
            );
            $stmt->execute([$supplierId]);
            $result['document_links'] = $stmt->rowCount();
            if ($statementIds !== []) {
                $place = implode(',', array_fill(0, count($statementIds), '?'));
                $stmt = $pdo->prepare(
                    "DELETE dl FROM document_links dl JOIN bank_transactions bt ON bt.id=dl.entity_id
                       JOIN bank_statements bs ON bs.id=bt.statement_id
                      WHERE dl.supplier_id=? AND dl.entity_type='bank_transaction' AND bs.supplier_id=? AND bs.id IN ($place)"
                );
                $stmt->execute([$supplierId, $supplierId, ...$statementIds]);
                $result['document_links'] += $stmt->rowCount();
            }
            // Auditní log nemá FK na polymorfní entity. Odstraň jen záznamy, které
            // prokazatelně vznikly nad sledovanými sample zápisy a bankovními pohyby.
            if ($journalIds !== []) {
                $place = implode(',', array_fill(0, count($journalIds), '?'));
                $delActivity = $pdo->prepare(
                    "DELETE FROM activity_log
                      WHERE supplier_id = ? AND entity_type = 'journal_entry' AND entity_id IN ($place)"
                );
                $delActivity->execute([$supplierId, ...$journalIds]);
                $result['activity_logs'] += $delActivity->rowCount();
            }
            if ($statementIds !== []) {
                $place = implode(',', array_fill(0, count($statementIds), '?'));
                $delActivity = $pdo->prepare(
                    "DELETE al FROM activity_log al
                       JOIN bank_transactions bt ON bt.id = al.entity_id
                       JOIN bank_statements bs ON bs.id = bt.statement_id
                      WHERE al.supplier_id = ? AND al.entity_type = 'bank_transaction'
                        AND bs.supplier_id = ? AND bs.id IN ($place)"
                );
                $delActivity->execute([$supplierId, $supplierId, ...$statementIds]);
                $result['activity_logs'] += $delActivity->rowCount();
            }

            // AI joby a účetní korekce se váží polymorfně (entity_type/entity_id) a nemají
            // FK, takže smazáním dokladů nezmizí. Mažeme je PODLE EVIDENCE, dokud existuje —
            // po purge by už nešlo poznat, které patřily ukázkovým datům.
            foreach (['ai_jobs', 'accounting_corrections'] as $polymorphic) {
                // a) entity evidované přímo (purchase_invoice, invoice, journal_entry…)
                $del = $pdo->prepare(
                    "DELETE t FROM `$polymorphic` t
                       JOIN sample_data_entries sde
                         ON sde.supplier_id = t.supplier_id
                        AND sde.entity_id   = t.entity_id
                        AND sde.entity_type = t.entity_type
                      WHERE t.supplier_id = ?"
                );
                $del->execute([$supplierId]);
                $result[$polymorphic] = $del->rowCount();

                // b) bankovní pohyby se needvidují jednotlivě (patří pod výpis), takže
                //    se na evidenci dojde až přes bank_transactions → bank_statements.
                //    Stejná cesta, jakou výš používá úklid activity_log.
                $delBt = $pdo->prepare(
                    "DELETE t FROM `$polymorphic` t
                       JOIN bank_transactions bt ON bt.id = t.entity_id
                       JOIN bank_statements bs   ON bs.id = bt.statement_id
                       JOIN sample_data_entries sde
                         ON sde.supplier_id = bs.supplier_id
                        AND sde.entity_type = 'bank_statement'
                        AND sde.entity_id   = bs.id
                      WHERE t.supplier_id = ? AND t.entity_type = 'bank_transaction'"
                );
                $delBt->execute([$supplierId]);
                $result[$polymorphic] += $delBt->rowCount();
            }

            // Účetní zápisy nemají FK na polymorfní zdroj. Musí zmizet před doklady,
            // jinak by po purge zůstaly aktivní sirotky v hlavní knize.
            $result['journal_entries'] = $deleteByIds('journal_entries', $journalIds);

            // Majetek před zdrojovými přijatými fakturami; odpisové řádky kaskádují.
            $result['small_assets'] = $deleteByIds('small_assets', $smallAssetIds);
            $result['assets'] = $deleteByIds('assets', $assetIds);

            // Výpisy kaskádují pohyby a payment_matches; bankovní vazby plateb se nulují.
            $result['bank_statements'] = $deleteByIds('bank_statements', $statementIds);
            $result['bank_accounts'] = $deleteByIds('supplier_bank_accounts', $bankAccountIds);

            // Pokladní doklady před pokladnou (FK register_id je RESTRICT).
            $result['cash_documents'] = $deleteByIds('cash_documents', $cashDocumentIds);
            $result['cash_registers'] = $deleteByIds('cash_registers', $cashRegisterIds);

            // Skladové doklady před kartami. Materializované stavy nejsou odvozené FK,
            // proto je odstraňujeme explicitně podle evidovaných sample karet.
            $result['stock_documents'] = $deleteByIds('stock_documents', $stockDocumentIds);
            if ($stockItemIds !== []) {
                $place = implode(',', array_fill(0, count($stockItemIds), '?'));
                $pdo->prepare("DELETE FROM stock_levels WHERE supplier_id = ? AND stock_item_id IN ($place)")
                    ->execute([$supplierId, ...$stockItemIds]);
            }

            // Kniha jízd: tankování PŘED autem (fk_fuelings_car je SET NULL → jinak osiří),
            //    trips padají kaskádou s autem.
            if ($carIds !== []) {
                $place = implode(',', array_fill(0, count($carIds), '?'));
                $delFuel = $pdo->prepare(
                    "DELETE FROM fuelings WHERE supplier_id = ? AND car_id IN ($place)"
                );
                $delFuel->execute([$supplierId, ...$carIds]);
                $result['fuelings'] = $delFuel->rowCount();

                $cntTrips = $pdo->prepare(
                    "SELECT COUNT(*) FROM trips WHERE supplier_id = ? AND car_id IN ($place)"
                );
                $cntTrips->execute([$supplierId, ...$carIds]);
                $result['trips'] = (int) $cntTrips->fetchColumn();

                $result['cars'] = $deleteByIds('cars', $carIds); // kaskáduje trips
            }

            // Faktury + dobropisy (dobropis padá kaskádou přes parent_invoice_id, ale máme
            //    je evidované zvlášť → smažou se i tak). Kaskáduje items/PDF/přílohy/platby.
            $result['invoices'] = $deleteByIds('invoices', $invoiceIds);

            // Pravidelné fakturace (kaskáduje items) — před klienty (RESTRICT).
            $result['recurring'] = $deleteByIds('recurring_invoice_templates', $recurringIds);

            // Přijaté faktury (kaskáduje items/scans/matches) — před dodavateli (RESTRICT).
            $result['purchase_invoices'] = $deleteByIds('purchase_invoices', $purchaseIds);

            $result['stock_items'] = $deleteByIds('stock_items', $stockItemIds);
            $result['manufacturers'] = $deleteByIds('manufacturers', $manufacturerIds);
            $result['eshop_categories'] = $deleteByIds('stock_categories', $stockCategoryIds);
            $result['warehouses'] = $deleteByIds('warehouses', $warehouseIds);

            // Zakázky — po fakturách (RESTRICT), kaskáduje billing_emails/revenue_cache.
            //    projects nemá supplier_id (váže se přes client_id) → maž jen podle id.
            $result['projects'] = $deleteByIds('projects', $projectIds, false);

            // Klienti + dodavatelé — naposled, kaskáduje email_contacts/revenue_cache.
            $result['clients'] = $deleteByIds('clients', $clientIds);

            // Čítače číselných řad. Ukázkové faktury je vyhnaly nahoru a bez tohohle by
            // první REÁLNÁ faktura navázala číslováním až za smazanými ukázkami.
            // Čítač nemá `id` (PK je složený), nejde tedy evidovat jako entita — mažeme
            // proto řady, po kterých nezbyl ŽÁDNÝ doklad. Když má firma v téže řadě
            // vlastní doklady, čítač zůstane a číslování se nerozbije.
            $delCounters = $pdo->prepare(
                'DELETE c FROM invoice_counters c
                  WHERE c.supplier_id = ?
                    AND NOT EXISTS (
                        SELECT 1 FROM invoices i
                         WHERE i.supplier_id = c.supplier_id
                           AND i.invoice_type = c.invoice_type
                           AND (c.client_id IS NULL OR i.client_id = c.client_id)
                    )'
            );
            $delCounters->execute([$supplierId]);
            $result['invoice_counters'] = $delCounters->rowCount();

            $delPurchaseCounters = $pdo->prepare(
                'DELETE c FROM purchase_invoice_counters c
                  WHERE c.supplier_id = ?
                    AND NOT EXISTS (
                        SELECT 1 FROM purchase_invoices p WHERE p.supplier_id = c.supplier_id
                    )'
            );
            $delPurchaseCounters->execute([$supplierId]);
            $result['purchase_invoice_counters'] = $delPurchaseCounters->rowCount();

            // Vyčisti evidenci.
            $pdo->prepare('DELETE FROM sample_data_entries WHERE supplier_id = ?')
                ->execute([$supplierId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (!$preflightPassed) throw $e;
            throw new \RuntimeException(
                'Ukázková data se nepodařilo odebrat — některý sample záznam je navázaný na '
                . 'vaše vlastní data. Odeberte nejdřív tu vazbu, nebo použijte úplný reset '
                . '(`php api/bin/reset.php --keep-users-supplier`). Detail: ' . $e->getMessage(),
                0,
                $e
            );
        }

        foreach ($documentFiles as $file) {
            $this->documentStorage->deleteIfOrphan(
                $supplierId, $file['sha256'], $file['filename'], $file['thumb_path'], $this->documents, [],
            );
        }

        return $result;
    }
}
