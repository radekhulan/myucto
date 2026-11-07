<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Service\Accounting\Product\ProductPostingDefaults;
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
use MyInvoice\Service\Invoice\InvoiceCalendar;
use MyInvoice\Service\Invoice\InvoiceIssuer;
use MyInvoice\Service\Invoice\InvoiceOtherItemLinkService;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\LineDiscount;
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
        private readonly \MyInvoice\Service\Invoice\CreditNoteOffsetService $creditNoteOffsets,
        private readonly \MyInvoice\Service\Eet\EetHistoryGuard $eetHistory,
        private readonly \MyInvoice\Repository\StockTrackingRepository $stockTracking,
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

        if (InvoiceCalendar::isCalendar($invoice['invoice_type'])) {
            return $this->cancelCalendar($request, $response, $invoice, $mode, $reason);
        }
        // Interní storno splátky by rozpis na vytištěném kalendáři popřelo.
        if (!empty($invoice['calendar_id']) && $invoice['invoice_type'] !== 'tax_document' && $mode === 'internal') {
            return Json::error($response, 'calendar_instalment_cancel', 'Splátku opravte dobropisem, nebo stornujte celý kalendář.', 409);
        }

        if ($mode === 'internal') {
            return $this->internalCancel($request, $response, $invoice, $reason);
        }
        if ($mode === 'credit_note') {
            return $this->createCreditNote($request, $response, $invoice, $reason);
        }

        return Json::error($response, 'invalid_mode', 'mode musí být "internal" nebo "credit_note".', 400);
    }

    /**
     * Storno kalendáře zruší i jeho splátky. Zaplacenou nebo dobropisovanou splátku nejde
     * vrátit zpět jedním krokem: úhrada a dobropis mají vlastní daňový a účetní dopad.
     *
     * @param array<string,mixed> $calendar
     */
    private function cancelCalendar(Request $request, Response $response, array $calendar, string $mode, string $reason): Response
    {
        if ($mode !== 'internal') {
            return Json::error($response, 'invalid_mode', 'Kalendář lze stornovat jen interně. Jednotlivou splátku opravte dobropisem.', 409);
        }
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT i.id, i.paid_total,
                    (SELECT COUNT(*) FROM invoices cn
                      WHERE cn.parent_invoice_id = i.id AND cn.invoice_type = 'credit_note' AND cn.status <> 'cancelled') AS credit_notes
               FROM invoices i
              WHERE i.supplier_id = ? AND i.calendar_id = ?
                AND i.invoice_type IN ('invoice', 'proforma') AND i.status <> 'cancelled'
           ORDER BY i.id"
        );
        $stmt->execute([(int) $calendar['supplier_id'], (int) $calendar['id']]);
        $instalments = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $loaded = [];
        foreach ($instalments as $row) {
            if ((float) $row['paid_total'] > 0 || (int) $row['credit_notes'] > 0) {
                return Json::error($response, 'calendar_instalment_settled', 'Kalendář má uhrazené nebo dobropisované splátky. Nejdřív odeberte úhrady a stornujte dobropisy.', 409);
            }
            $instalment = $this->repo->find((int) $row['id']);
            if ($deny = $this->denyIfLocked($request, $response, $this->locks->forInvoice($instalment), 'invoice', (int) $row['id'])) {
                return $deny;
            }
            $loaded[] = $instalment;
        }
        foreach ($loaded as $instalment) {
            $result = $this->internalCancel($request, $response->withBody((new \Slim\Psr7\Factory\StreamFactory())->createStream()), $instalment, $reason);
            if ($result->getStatusCode() >= 300) {
                return $result;
            }
        }

        return $this->internalCancel($request, $response, $calendar, $reason);
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

            // Zápočet dobropisu (issue #140) padá se stornem kterékoli strany: stornovaný
            // dobropis nic nesnižuje a stornovaná faktura nemá co započíst.
            $this->creditNoteOffsets->revertForDocument($supplierId, 'invoice', (int) $invoice['id']);

            // Vazba na ostatní pohledávku (migrace 2246) padá se stornem i se zrcadlenými
            // úhradami; pohledávka a její úhrady zůstávají. Obnovení storna ji nevrací.
            $detachedOtherItem = InvoiceOtherItemLinkService::detachForCancel($pdo, $supplierId, (int) $invoice['id']);

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
            InvoiceIssuer::record($pdo, $cancellationId, $userId);

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
            return Json::internalError($response, 'cancel_failed', $e->getMessage(), 500);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.cancelled', $userId, 'invoice', $invoice['id'], [
            'mode' => 'internal',
            'cancellation_id' => $cancellationId,
            'reason' => $reason,
        ], $ip, $request->getHeaderLine('User-Agent'));
        if ($detachedOtherItem !== null) {
            $this->logger->log('invoice.other_item_unlinked', $userId, 'invoice', $invoice['id'], [
                'other_item_id' => $detachedOtherItem,
                'trigger' => 'cancel',
            ], $ip, $request->getHeaderLine('User-Agent'));
        }

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

        return Json::ok($response, $this->withEetWarning($supplierId, (int) $invoice['id'], [
            'cancellation_id' => $cancellationId,
            'invoice'         => $this->repo->find($invoice['id']),
        ]));
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
        $supportsTracking = $this->db->hasColumn('invoice_items', 'tracking_json');
        $supportsLineDiscount = $this->db->hasColumn('invoice_items', LineDiscount::LIST_COLUMN);
        // Dobropis vrací kusy, které výdejka původní faktury skutečně vydala (s aktuálním
        // textem šarže, i když se kód šarže po vydání opravil); bez výdejky zůstává kopie výběru.
        $issuedUnits = $supportsTracking
            ? $this->stockTracking->issuedUnitsByInvoiceItem((int) $invoice['supplier_id'], (int) $invoice['id'])
            : [];
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
                     supplier_order_number, note_above_items, revenue_category_id, payment_method, rounding_mode, status, created_by)
                 VALUES ("credit_note", ?, ?, ?, ?, ?, CURDATE(), CURDATE(), CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", ?)'
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
                $invoice['supplier_order_number'] ?? null,
                $reason !== '' ? "Dobropis k faktuře {$invoice['varsymbol']}: $reason" : "Dobropis k faktuře {$invoice['varsymbol']}",
                // Dobropis dědí kategorii tržby původní faktury (záporná tržba ve stejné kategorii).
                $invoice['revenue_category_id'] ?? null,
                $invoice['payment_method'] ?? 'bank_transfer',
                $invoice['rounding_mode'] ?? 'none',
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
            // Dobropis vrací TYTÉŽ kusy (sériová čísla / šarže, 2016), vratka z nich vychází.
            if ($supportsTracking) {
                $ossColumns[] = 'tracking_json';
            }
            // Ceníková cena řádku (2035): dobropis vrací totéž plnění za tutéž cenu.
            if ($supportsLineDiscount) {
                $ossColumns[] = LineDiscount::LIST_COLUMN;
                $ossColumns[] = LineDiscount::PERCENT_COLUMN;
            }
            $itemStmt = $pdo->prepare(
                'INSERT INTO invoice_items
                   (invoice_id, description, quantity, duration_minutes, unit, unit_price_without_vat,
                    vat_rate_id, vat_rate_snapshot,
                    total_without_vat, total_vat, total_with_vat, order_index, item_kind, vat_classification_code,
                    stock_item_id, warehouse_id, revenue_account_code'
                . ($ossColumns !== [] ? ', ' . implode(', ', $ossColumns) : '')
                . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?, ?, ?, ?'
                . str_repeat(', ?', count($ossColumns))
                . ')'
            );
            $productAccounts = (new ProductPostingDefaults($this->db))->accountsFor(
                (int) $invoice['supplier_id'],
                array_map(static fn (array $it): int => (int) ($it['stock_item_id'] ?? 0), $invoice['items']),
            );
            $voucherCards = \MyInvoice\Service\Invoice\MultiPurposeVoucher::stockItemIds(
                $pdo,
                (int) $invoice['supplier_id'],
                array_map(static fn (array $it): int => (int) ($it['stock_item_id'] ?? 0), $invoice['items']),
            );
            foreach ($invoice['items'] as $item) {
                // Textový řádek jde na dobropis jako textový, bez částky a bez klasifikace.
                // Ostatní řádky (i slevový) jdou jako běžné, jako dřív. Vrácený víceúčelový
                // poukaz klasifikaci nenese stejně jako jeho prodej (MultiPurposeVoucher).
                $isText = \MyInvoice\Service\Invoice\InvoiceItemKind::isText($item);
                $code = $isText || isset($voucherCards[(int) ($item['stock_item_id'] ?? 0)]) ? null : $item['vat_classification_code']
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
                    $item['duration_minutes'] !== null ? -1 * (int) $item['duration_minutes'] : null,
                    $item['unit'],
                    $item['unit_price_without_vat'],
                    $item['vat_rate_id'],
                    $item['vat_rate_snapshot'],
                    $item['order_index'],
                    $isText ? \MyInvoice\Service\Invoice\InvoiceItemKind::TEXT : \MyInvoice\Service\Invoice\InvoiceItemKind::STANDARD,
                    $code !== null ? (string) $code : null,
                    // Vazba na skladovou kartu z parent faktury (Epic SKLAD §5.3) —
                    // bez ní by vratka při vystavení dobropisu neuměla napárovat výdej.
                    $item['stock_item_id'] ?? null,
                    $item['warehouse_id'] ?? null,
                    // Dobropis vrací výnos z TÉHOŽ účtu, na který šla původní položka (F1):
                    // účet z produktu / kategorie se zapíše na položku dobropisu, aby pozdější
                    // změna karty nepřesměrovala vratku jinam než původní výnos.
                    ProductPostingDefaults::code($item['revenue_account_code'] ?? null)
                        ?? ($productAccounts[(int) ($item['stock_item_id'] ?? 0)]['revenue']['code'] ?? null),
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
                if ($supportsTracking) {
                    $stored = \MyInvoice\Service\Stock\InvoiceItemTracking::storedJson($item);
                    $issued = $issuedUnits[(int) ($item['id'] ?? 0)] ?? [];
                    $params[] = $stored !== null && $issued !== []
                        ? \MyInvoice\Service\Stock\InvoiceItemTracking::fromIssuedUnits($issued)
                        : $stored;
                }
                if ($supportsLineDiscount) {
                    array_push($params, ...LineDiscount::columns($item));
                }
                $itemStmt->execute($params);
            }
            // Dobropis nese dimenze původní faktury, jinak by vratka výnosu skončila mimo
            // středisko, ze kterého výnos odešel. Položky leží ve stejném pořadí.
            (new \MyInvoice\Repository\DimensionAssignmentRepository($this->db))
                ->copyDocument((int) $invoice['supplier_id'], 'invoice', (int) $invoice['id'], $creditNoteId);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return Json::internalError($response, 'credit_note_failed', $e->getMessage(), 500);
        }

        // Recompute sumy nového dobropisu
        $this->calc->recompute($creditNoteId);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.credit_note_created', $userId, 'invoice', $invoice['id'], [
            'credit_note_id' => $creditNoteId,
            'reason'         => $reason,
        ], $ip, $request->getHeaderLine('User-Agent'));

        // Credit note je draft (revenue se nepočítá), ale parent dostal cancelled stav až po issue dobropisu — nepřepočítáváme tady.
        return Json::ok($response, $this->withEetWarning((int) $invoice['supplier_id'], (int) $invoice['id'], [
            'credit_note_id' => $creditNoteId,
            'edit_url'       => "/invoices/$creditNoteId/edit",
        ]), 201);
    }

    /**
     * EET-07: storno ani dobropis evidovanou tržbu automaticky neopravují. Odpověď
     * jen upozorní (`eet_warning`); u faktury bez tržeb zůstává beze změny.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function withEetWarning(int $supplierId, int $invoiceId, array $payload): array
    {
        $warning = $this->eetHistory->cancellationWarning($supplierId, $invoiceId);
        if ($warning !== null) {
            $payload['eet_warning'] = $warning;
        }
        return $payload;
    }
}
