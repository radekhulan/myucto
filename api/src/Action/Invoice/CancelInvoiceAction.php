<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\AssetSale\InvoiceAssetSaleService;
use MyInvoice\Service\Accounting\Cash\CashException;
use MyInvoice\Service\Accounting\Cash\CashSettlementService;
use MyInvoice\Service\Accounting\DocumentJournalSync;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\AdvanceCycleLock;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Oss\OssPeriod;
use MyInvoice\Service\Stats\StatsRecomputer;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockIssueService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Storno vystavené faktury. Dvě varianty:
 *
 *  - mode=internal      → vytvoří `cancellation` záznam s parent. Původní invoice
 *                         dostane `cancelled_at` a status='cancelled'. Žádný doklad
 *                         pro klienta.
 *
 *  - mode=credit_note   → vytvoří DRAFT typu `credit_note` se zápornými položkami.
 *                         User musí v editoru zkontrolovat a zavolat /issue.
 *                         Původní status zůstává až do vystavení dobropisu.
 */
final class CancelInvoiceAction
{
    use GuardsDocumentLock;

    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
        private readonly InvoiceCalculator $calc,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly StatsRecomputer $stats,
        private readonly DocumentLockService $locks,
        private readonly StockIssueService $stockIssue,
        private readonly DocumentJournalSync $journalSync,
        private readonly AdvanceCycleLock $cycleLock,
        private readonly InvoiceAssetSaleService $assetSale,
        private readonly CashSettlementService $cashSettlement,
        private readonly TaxConstantsRepository $taxConstants,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (SupplierGuard::owns($request, $invoice) && ($invoice['invoice_type'] ?? '') === 'tax_document') {
            $proformaId = !empty($invoice['parent_invoice_id']) ? (int) $invoice['parent_invoice_id'] : 0;
            if ($proformaId === 0) {
                $payment = $this->db->pdo()->prepare(
                    'SELECT invoice_id FROM invoice_payments WHERE tax_document_invoice_id = ? LIMIT 1'
                );
                $payment->execute([$id]);
                $proformaId = (int) ($payment->fetchColumn() ?: 0);
            }
            if ($proformaId > 0) {
                return $this->cycleLock->synchronized(
                    $proformaId,
                    fn (): Response => $this->invokeUnlocked($request, $response, $args),
                );
            }
        }

        return $this->invokeUnlocked($request, $response, $args);
    }

    private function invokeUnlocked(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        if (!in_array($invoice['status'], ['issued', 'sent', 'reminded', 'paid'], true)) {
            return Json::error($response, 'invalid_state', 'Lze zrušit jen vystavenou/odeslanou/zaplacenou fakturu.', 409);
        }
        if ($invoice['invoice_type'] === 'cancellation') {
            return Json::error($response, 'invalid_type', 'Stornovací doklad nelze stornovat.', 409);
        }
        if ($invoice['invoice_type'] === 'tax_document') {
            $proformaId = !empty($invoice['parent_invoice_id']) ? (int) $invoice['parent_invoice_id'] : 0;
            if ($proformaId === 0) {
                $payment = $this->db->pdo()->prepare(
                    'SELECT invoice_id FROM invoice_payments WHERE tax_document_invoice_id = ? LIMIT 1'
                );
                $payment->execute([$id]);
                $proformaId = (int) ($payment->fetchColumn() ?: 0);
            }
            $final = $this->db->pdo()->prepare(
                "SELECT 1 FROM invoices
                  WHERE supplier_id = ? AND parent_invoice_id = ?
                    AND invoice_type = 'invoice' AND status <> 'cancelled'
                  LIMIT 1"
            );
            $final->execute([(int) $invoice['supplier_id'], $proformaId]);
            if ($proformaId > 0 && $final->fetchColumn() !== false) {
                return Json::error(
                    $response,
                    'advance_tax_document_settled',
                    'Daňový doklad k přijaté platbě nelze stornovat, dokud existuje navazující finální faktura. Nejdřív stornujte finální fakturu.',
                    409,
                );
            }
        }

        // Zámek dokladu (Epic F6): storno = účetní akt — zaúčtovaný/uzavřený doklad
        // klient nestornuje (403), účetní v zavřeném období 409, admin ?force=1.
        if ($deny = $this->denyIfLocked($request, $response, $this->locks->forInvoice($invoice), 'invoice', $id)) {
            return $deny;
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $mode = (string) ($body['mode'] ?? '');
        $reason = (string) ($body['reason'] ?? '');

        // Dobropisu nelze vystavit další dobropis — povolen jen interní storno.
        if ($invoice['invoice_type'] === 'credit_note' && $mode !== 'internal') {
            return Json::error($response, 'invalid_mode', 'Dobropis lze stornovat pouze interně.', 409);
        }

        if ($mode === 'internal') {
            return $this->internalCancel($request, $response, $invoice, $reason);
        }
        if ($mode === 'credit_note') {
            return $this->createCreditNote($request, $response, $invoice, $reason);
        }

        return Json::error($response, 'invalid_mode', 'mode musí být "internal" nebo "credit_note".', 400);
    }

    private function internalCancel(Request $request, Response $response, array $invoice, string $reason): Response
    {
        $pdo = $this->db->pdo();
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $requireUnbooked = RequestAuthorization::isClientType($request);

        $supplierId = (int) $invoice['supplier_id'];
        $stockEnabled = $this->stockIssue->isStockEnabled($supplierId);
        $ownTx = !$pdo->inTransaction();
        if ($stockEnabled && $ownTx) {
            // Storno auto-výdejky poběží ve stejné transakci — READ COMMITTED je
            // nutné pro FOR UPDATE zámky StockDocumentService (SET bez SESSION
            // platí jen pro následující transakci).
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            // A3 (audit H5): interní storno zaúčtované faktury musí stornovat i aktivní
            // zápis v deníku — jinak deník drží výnos + 311 stornované faktury, kterou
            // DPH evidence už nevykazuje (trvalá divergence). PRVNÍ krok (fail-fast): při
            // uzavřeném období vyhodí PostingException → rollback + 409 dřív, než doklad
            // dostane cancellation záznam / cancelled status (nic se nezmění).
            $this->journalSync->onCancel($supplierId, 'invoice', (int) $invoice['id'], [
                'user_id'    => $userId,
                'posted_by'  => $userId,
                'ip'         => $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                'user_agent' => $request->getHeaderLine('User-Agent'),
            ]);

            // Hotovostní vyrovnání (migrace 1327): stornovaná faktura nesmí zůstat
            // inkasovaná zaúčtovaným PPD — pokladní doklad i jeho zápis padnou s ní,
            // ve stejné transakci. Ručně pořízených pokladních dokladů (auto_settlement = 0)
            // se to nedotýká; ty ať uživatel vyřídí v modulu Pokladna vědomě.
            $this->cashSettlement->detach($supplierId, 'invoice', (int) $invoice['id']);

            // 1. Vytvoř cancellation záznam (interní, bez varsymbolu)
            $stmt = $pdo->prepare(
                'INSERT INTO invoices
                   (invoice_type, parent_invoice_id, client_id, project_id, supplier_id, branding_profile_id,
                    issue_date, tax_date, due_date, currency_id, language,
                    note_above_items, revenue_category_id, status, created_by)
                 VALUES ("cancellation", ?, ?, ?, ?, ?, CURDATE(), NULL, CURDATE(), ?, ?, ?, ?, "issued", ?)'
            );
            $stmt->execute([
                $invoice['id'],
                $invoice['client_id'],
                $invoice['project_id'],
                (int) $invoice['supplier_id'],
                $invoice['branding_profile_id'] ?? null,
                (int) $invoice['currency_id'],
                $invoice['language'],
                $reason !== '' ? "Storno faktury {$invoice['varsymbol']}: $reason" : null,
                // Storno mirror dědí kategorii tržby původní faktury.
                $invoice['revenue_category_id'] ?? null,
                $userId,
            ]);
            $cancellationId = (int) $pdo->lastInsertId();

            // 2. Označ původní fakturu jako cancelled. Optimistický zámek L1 (Epic F6):
            // pro klienta UPDATE podmíněný booked_at IS NULL — účetní mohla zaúčtovat
            // mezi guard-checkem a zápisem; 0 řádek → rollback + 409 document_locked.
            $upd = $pdo->prepare(
                'UPDATE invoices SET status = "cancelled", cancelled_at = NOW() WHERE id = ?'
                . ($requireUnbooked ? ' AND booked_at IS NULL' : '')
            );
            $upd->execute([$invoice['id']]);
            if ($requireUnbooked && $upd->rowCount() === 0) {
                if ($ownTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return Json::error(
                    $response,
                    'document_locked',
                    'Doklad byl mezitím zaúčtován — změny vyřídí vaše účetní.',
                    409,
                );
            }

            // Epic SKLAD §5.3: interní storno stornuje i posted auto-doklady
            // k faktuře (protidoklad v původní ceně, hodnotově neutrální) —
            // atomicky se změnou statusu. No-op, pokud žádné nejsou.
            if ($stockEnabled) {
                $this->stockIssue->reverseForInvoice($supplierId, (int) $invoice['id'], $userId);
            }

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (CashException $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return Json::error(
                $response,
                'cash_' . $e->errorCode,
                'Fakturu nelze stornovat — nejdřív vyřešte pokladní doklad, kterým byla hotově '
                    . 'inkasována (' . $e->getMessage() . ').',
                409,
            );
        } catch (PostingException $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return Json::error(
                $response,
                'journal_' . $e->errorCode,
                'Fakturu nelze stornovat — má zaúčtovaný zápis, který nelze stornovat ('
                    . $e->getMessage() . '). Nejdřív vyřešte zaúčtování v deníku.',
                409,
            );
        } catch (StockException $e) {
            // Např. storno vratky dobropisu při nedostatku zásob, zamčené období,
            // otevřená inventura — srozumitelný kód/status místo holé 500.
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return Json::error(
                $response,
                'stock.error.' . $e->errorCode,
                $e->getMessage(),
                $e->httpStatus,
                ['items' => $e->details],
            );
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return Json::error($response, 'cancel_failed', $e->getMessage(), 500);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.cancelled', $userId, 'invoice', $invoice['id'], [
            'mode' => 'internal',
            'cancellation_id' => $cancellationId,
            'reason' => $reason,
        ], $ip, $request->getHeaderLine('User-Agent'));

        // Prodej majetku (1177): stornovaná faktura prodej neuskutečnila → karty navázané na
        // její řádky se vrací do užívání. Mimo transakci storna a s polykáním chyb (AssetService
        // si drží vlastní transakci a revert vyřazení jde jen v otevřeném období) — nedokončený
        // revert se zaloguje a účetní ho dořeší z karty, storno faktury kvůli tomu nespadne.
        $this->assetSale->revertForInvoice($supplierId, (int) $invoice['id'], [
            'user_id'    => $userId,
            'ip'         => $ip,
            'user_agent' => $request->getHeaderLine('User-Agent'),
        ]);

        // Stornovaná faktura odejde z revenue cache
        $this->stats->recomputeForInvoiceId((int) $invoice['id']);

        return Json::ok($response, [
            'cancellation_id' => $cancellationId,
            'invoice'         => $this->repo->find($invoice['id']),
        ]);
    }

    private function createCreditNote(Request $request, Response $response, array $invoice, string $reason): Response
    {
        $pdo = $this->db->pdo();
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $supportsOss = $this->db->hasColumn('invoice_items', 'oss_applicable');
        // Vlastní guard, ne společný se zbytkem OSS sloupců: mezi migracemi 0137 a 1293
        // je řada verzí (shodně s InvoiceRepository::supportsOssManualReview()).
        $supportsManualReview = $supportsOss
            && $this->db->hasColumn('invoice_items', 'oss_needs_manual_review');
        $sourcePeriod = OssPeriod::quarterCode((string) ($invoice['tax_date'] ?? $invoice['issue_date'] ?? ''));
        $creditPeriod = OssPeriod::quarterCode(date('Y-m-d'));
        $defaultOriginalPeriod = $sourcePeriod !== null && $creditPeriod !== null && $sourcePeriod < $creditPeriod
            ? $sourcePeriod
            : null;

        // Auto-klasifikace legacy položek bez kódu potřebuje kontext dokladu: základní sazbu
        // § 47 ZDPH pro ROK DOBROPISU (vystavuje se k CURDATE(), viz INSERT níž) a zemi
        // odběratele. Natvrdo 21 by po změně sazby přeřadilo plnění na špatný řádek přiznání
        // a chybějící země by zahraničnímu klientovi dosadila tuzemský kód.
        $standardRate = $this->taxConstants->vatRateStandard((int) date('Y'));
        $clientCountryIso2 = null;
        if (!empty($invoice['client_id'])) {
            $countryStmt = $pdo->prepare(
                'SELECT co.iso2 FROM clients c
              LEFT JOIN countries co ON co.id = c.country_id
                  WHERE c.id = ?'
            );
            $countryStmt->execute([(int) $invoice['client_id']]);
            $iso = strtoupper(trim((string) ($countryStmt->fetchColumn() ?: '')));
            $clientCountryIso2 = $iso !== '' ? $iso : null;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO invoices
                   (invoice_type, parent_invoice_id, client_id, project_id, supplier_id, branding_profile_id,
                    issue_date, tax_date, due_date, currency_id, reverse_charge, prices_include_vat, language,
                    note_above_items, revenue_category_id, status, created_by)
                 VALUES ("credit_note", ?, ?, ?, ?, ?, CURDATE(), CURDATE(), CURDATE(), ?, ?, ?, ?, ?, ?, "draft", ?)'
            );
            $stmt->execute([
                $invoice['id'],
                $invoice['client_id'],
                $invoice['project_id'],
                (int) $invoice['supplier_id'],
                $invoice['branding_profile_id'] ?? null,
                (int) $invoice['currency_id'],
                $invoice['reverse_charge'] ? 1 : 0,
                // Dobropis musí dědit režim „ceny s DPH" — jinak by se zkopírované brutto
                // jednotkové ceny přepočítaly jako netto (nafouknuté totály dobropisu).
                !empty($invoice['prices_include_vat']) ? 1 : 0,
                $invoice['language'],
                $reason !== '' ? "Dobropis k faktuře {$invoice['varsymbol']}: $reason" : "Dobropis k faktuře {$invoice['varsymbol']}",
                // Dobropis dědí kategorii tržby původní faktury (záporná tržba ve stejné kategorii).
                $invoice['revenue_category_id'] ?? null,
                $userId,
            ]);
            $creditNoteId = (int) $pdo->lastInsertId();

            // Zkopíruj položky se zápornými quantities — zachovává vat_classification_code
            // (dobropis má jít na stejné DPH řádky jako originální faktura, ale se zápornými hodnotami)
            // Sloupce se skládají místo ručně psaných variant SQL — guard na příznak
            // „k ručnímu posouzení" je samostatný, takže variant by jinak byly tři.
            $ossColumns = $supportsOss
                ? [
                    'oss_applicable', 'oss_consumer_country', 'oss_rate_type', 'oss_supply_type',
                    'oss_exchange_rate', 'oss_exchange_rate_date', 'oss_taxable_amount_return',
                    'oss_vat_amount_return', 'oss_original_period',
                ]
                : [];
            if ($supportsManualReview) {
                $ossColumns[] = 'oss_needs_manual_review';
            }
            $itemStmt = $pdo->prepare(
                'INSERT INTO invoice_items
                   (invoice_id, description, quantity, unit, unit_price_without_vat,
                    vat_rate_id, vat_rate_snapshot,
                    total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code,
                    stock_item_id, warehouse_id'
                . ($ossColumns !== [] ? ', ' . implode(', ', $ossColumns) : '')
                . ') VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?, ?'
                . str_repeat(', ?', count($ossColumns))
                . ')'
            );
            foreach ($invoice['items'] as $item) {
                $code = $item['vat_classification_code']
                    ?? \MyInvoice\Repository\InvoiceRepository::defaultSaleClassificationCode(
                        (float) $item['vat_rate_snapshot'],
                        (bool) ($invoice['reverse_charge'] ?? false),
                        $clientCountryIso2,
                        (string) ($item['unit'] ?? '') ?: null,
                        $standardRate,
                    );
                $params = [
                    $creditNoteId,
                    $item['description'],
                    -1 * (float) $item['quantity'],   // záporné množství
                    $item['unit'],
                    $item['unit_price_without_vat'],
                    $item['vat_rate_id'],
                    $item['vat_rate_snapshot'],
                    $item['order_index'],
                    $code !== null ? (string) $code : null,
                    // Vazba na skladovou kartu z parent faktury (Epic SKLAD §5.3) —
                    // bez ní by vratka při vystavení dobropisu neuměla napárovat výdej.
                    $item['stock_item_id'] ?? null,
                    $item['warehouse_id'] ?? null,
                ];
                if ($supportsOss) {
                    $ossApplicable = !empty($item['oss_applicable']);
                    $originalPeriod = trim((string) ($item['oss_original_period'] ?? '')) ?: $defaultOriginalPeriod;
                    if ($originalPeriod !== null && $creditPeriod !== null && $originalPeriod >= $creditPeriod) {
                        $originalPeriod = $defaultOriginalPeriod;
                    }
                    array_push(
                        $params,
                        $ossApplicable ? 1 : 0,
                        $ossApplicable ? ($item['oss_consumer_country'] ?? null) : null,
                        $ossApplicable ? ($item['oss_rate_type'] ?? null) : null,
                        $ossApplicable ? ($item['oss_supply_type'] ?? null) : null,
                        $ossApplicable ? ($item['oss_exchange_rate'] ?? null) : null,
                        $ossApplicable ? ($item['oss_exchange_rate_date'] ?? null) : null,
                        $ossApplicable && ($item['oss_taxable_amount_return'] ?? null) !== null
                            ? -1 * (float) $item['oss_taxable_amount_return']
                            : null,
                        $ossApplicable && ($item['oss_vat_amount_return'] ?? null) !== null
                            ? -1 * (float) $item['oss_vat_amount_return']
                            : null,
                        $ossApplicable ? $originalPeriod : null,
                    );
                }
                if ($supportsManualReview) {
                    // Opravný doklad dědí nejistotu původního: dobropis jde do TÉHOŽ
                    // přiznání jako opravovaná faktura, takže je-li sporné místo plnění
                    // u ní, je sporné i u něj — a se záporným znaménkem je chyba stejně
                    // velká. Bez přenosu by příznak na opravném dokladu zhasl a v náhledu
                    // podání by zůstala označená jen kladná polovina opravy.
                    // Nese ho i tuzemský řádek smíšeného dokladu (proto bez vazby na
                    // `$ossApplicable`) — viz InvoiceRepository::ossItemParams().
                    $params[] = !empty($item['oss_needs_manual_review']) ? 1 : 0;
                }
                $itemStmt->execute($params);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return Json::error($response, 'credit_note_failed', $e->getMessage(), 500);
        }

        // Recompute sumy nového dobropisu
        $this->calc->recompute($creditNoteId);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.credit_note_created', $userId, 'invoice', $invoice['id'], [
            'credit_note_id' => $creditNoteId,
            'reason'         => $reason,
        ], $ip, $request->getHeaderLine('User-Agent'));

        // Credit note je draft (revenue se nepočítá), ale parent dostal cancelled stav až po issue dobropisu — nepřepočítáváme tady.
        return Json::ok($response, [
            'credit_note_id' => $creditNoteId,
            'edit_url'       => "/invoices/$creditNoteId/edit",
        ], 201);
    }
}
