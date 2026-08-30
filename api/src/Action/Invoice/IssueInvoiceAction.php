<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Repository\WorkReportRepository;
use MyInvoice\Service\Accounting\AssetSale\InvoiceAssetSaleService;
use MyInvoice\Service\Accounting\Cash\CashSettlementService;
use MyInvoice\Service\Accounting\DocumentAutoPoster;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\AdvanceCycleLock;
use MyInvoice\Service\Invoice\SnapshotBuilder;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Invoice\SimplifiedDocumentPolicy;
use MyInvoice\Service\Stats\StatsRecomputer;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockIssueService;
use MyInvoice\Service\Validation\InvoiceAmountPolicy;
use MyInvoice\Service\Vat\VatStatusService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Přechod draft → issued:
 *  1. Vygeneruje varsymbol (atomicky)
 *  2. Zapíše snapshots (client, supplier, bank)
 *  3. Status = issued
 *
 * Po issued už faktura nelze editovat — jen storno/dobropis/mark-paid.
 */
final class IssueInvoiceAction
{
    use HandlesVarsymbolDuplicate;
    use GuardsDocumentLock;

    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
        private readonly VarsymbolGenerator $varsymbol,
        private readonly SnapshotBuilder $snapshots,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly StatsRecomputer $stats,
        private readonly WorkReportRepository $workReports,
        // § 31/31a — rozpis plateb kalendáře; bez něj kalendář není daňovým dokladem.
        private readonly \MyInvoice\Repository\PaymentScheduleRepository $paymentSchedule,
        private readonly InvoicePdfRenderer $pdfRenderer,
        private readonly DocumentLockService $locks,
        private readonly StockIssueService $stockIssue,
        private readonly DocumentAutoPoster $autoPoster,
        private readonly AdvanceCycleLock $cycleLock,
        private readonly InvoiceAssetSaleService $assetSale,
        private readonly VatStatusService $vatStatus,
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
        if ($invoice['status'] !== 'draft') {
            return Json::error($response, 'not_draft', 'Lze vystavit jen draft fakturu.', 409);
        }

        // Zámek dokladu (Epic F6, H1): issue přiděluje varsymbol/číslo řady k issue/tax
        // date — datum v uzavřeném období nesmí projít (client 403, účetní 409, admin force).
        if ($deny = $this->denyIfLocked($request, $response, $this->locks->forInvoice($invoice), 'invoice', $id)) {
            return $deny;
        }
        if (count($invoice['items']) === 0) {
            return Json::error($response, 'no_items', 'Faktura musí obsahovat alespoň jednu položku.', 422);
        }
        if (
            InvoiceAmountPolicy::requiresPositiveDraftAmountToPay(
                (string) ($invoice['invoice_type'] ?? 'invoice'),
                $invoice['parent_invoice_id'] ?? null,
            )
            && !InvoiceAmountPolicy::hasPositiveAmountToPay($invoice)
        ) {
            return Json::error($response, 'invalid_amount', InvoiceAmountPolicy::NON_POSITIVE_DRAFT_MESSAGE, 409);
        }
        if ($invoice['invoice_type'] === 'cancellation') {
            return Json::error($response, 'invalid_type', 'Storno nedostává varsymbol.', 422);
        }
        // Daňový doklad k přijaté platbě nelze vystavit, když už k proformě existuje
        // (nestornovaný) finál — jeho § 37a odpočty jsou zafixované a stejná úplata
        // by se zdanila podruhé. Draft DD smaž, nebo nejdřív stornuj finál.
        if ($invoice['invoice_type'] === 'tax_document' && (int) ($invoice['parent_invoice_id'] ?? 0) > 0) {
            $fin = $this->db->pdo()->prepare(
                "SELECT 1 FROM invoices
                  WHERE parent_invoice_id = ? AND invoice_type = 'invoice' AND status <> 'cancelled'
                  LIMIT 1"
            );
            $fin->execute([(int) $invoice['parent_invoice_id']]);
            if ($fin->fetchColumn() !== false) {
                return Json::error(
                    $response,
                    'final_exists',
                    'K zálohové faktuře už existuje finální doklad — daňový doklad k platbě by úplatu zdanil podruhé. Smaž tento koncept.',
                    409,
                );
            }
        }

        // § 29 odst. 2 ZDPH — doklad v režimu přenesené daňové povinnosti musí nést DIČ
        // odběratele. Blokuje se až TADY, při vystavení: koncept smí být neúplný, vystavený
        // daňový doklad ne.
        //
        // Dosud se na to jen upozorňovalo ve výkazu, tedy AŽ PO odeslání dokladu. A v KH
        // takový řádek vůbec nevznikne (`cleanDic() === ''` ho vyřadí), takže plnění z KH
        // tiše vypadne a nesedí na přiznání — varování v tu chvíli přichází pozdě, doklad
        // je u odběratele.
        if (!empty($invoice['reverse_charge'])) {
            $dicStmt = $this->db->pdo()->prepare('SELECT dic FROM clients WHERE id = ?');
            $dicStmt->execute([(int) ($invoice['client_id'] ?? 0)]);
            if (KontrolniHlaseniBuilder::cleanDic((string) ($dicStmt->fetchColumn() ?: '')) === '') {
                return Json::error(
                    $response,
                    'reverse_charge_dic_missing',
                    'Doklad v režimu přenesené daňové povinnosti musí nést DIČ odběratele '
                        . '(§ 29 odst. 2 ZDPH). Bez něj plnění nelze uvést v kontrolním hlášení. '
                        . 'Doplňte DIČ u odběratele, nebo režim přenesené povinnosti vypněte.',
                    422,
                );
            }
        }

        // § 31 / § 31a ZDPH — kalendář je daňovým dokladem jen tehdy, obsahuje-li ROZPIS
        // PLATEB na předem stanovené období. Bez rozpisu nejde o daňový doklad a odběratel
        // z něj nemůže uplatnit odpočet — vystavit ho prázdný je horší než ho nevystavit,
        // protože obě strany se pak spoléhají na doklad, který jím není.
        //
        // Součet rozpisu musí sedět na celkovou částku: rozejde-li se, není z čeho určit,
        // kolik vlastně bylo sjednáno.
        if (($invoice['invoice_type'] ?? '') === 'payment_calendar') {
            $scheduleTotal = $this->paymentSchedule->totalForInvoice((int) $invoice['supplier_id'], $id);
            if ($this->paymentSchedule->forInvoice((int) $invoice['supplier_id'], $id) === []) {
                return Json::error(
                    $response,
                    'payment_schedule_missing',
                    'Splátkový ani platební kalendář nelze vystavit bez rozpisu plateb '
                        . '(§ 31 a § 31a ZDPH) — bez něj nejde o daňový doklad.',
                    422,
                );
            }
            $invoiceTotal = round((float) ($invoice['total_with_vat'] ?? 0), 2);
            if ((int) round($scheduleTotal * 100) !== (int) round($invoiceTotal * 100)) {
                return Json::error(
                    $response,
                    'payment_schedule_mismatch',
                    sprintf(
                        'Součet rozpisu plateb (%s Kč) nesedí na celkovou částku dokladu (%s Kč).',
                        number_format($scheduleTotal, 2, ',', ' '),
                        number_format($invoiceTotal, 2, ',', ' '),
                    ),
                    422,
                );
            }
        }

        // § 30 odst. 1 a 2 ZDPH — zjednodušený daňový doklad. Blokuje se stejně jako
        // § 29 až TADY: koncept smí být rozpracovaný, vystavený doklad ne.
        //
        // Výjimky nejsou formalita. U dodání do JČS a u přenesené daňové povinnosti
        // odběratel své identifikační údaje na dokladu POTŘEBUJE — bez nich se plnění
        // nedá vykázat v souhrnném ani kontrolním hlášení, takže špatně zvolený
        // zjednodušený doklad rozbije výkazy, ne jen náležitosti dokladu.
        if (!empty($invoice['is_simplified'])) {
            $lineStmt = $this->db->pdo()->prepare(
                'SELECT dphdp3_line FROM vat_classifications
                  WHERE code = ? AND (supplier_id = ? OR supplier_id IS NULL)
                  ORDER BY supplier_id IS NULL LIMIT 1'
            );
            $lineStmt->execute([
                (string) ($invoice['vat_classification_code'] ?? ''),
                (int) $invoice['supplier_id'],
            ]);
            $line = $lineStmt->fetchColumn();

            $docYear = (int) substr((string) (($invoice['tax_date'] ?? null) ?: $invoice['issue_date']), 0, 4);
            $reason = SimplifiedDocumentPolicy::rejectionReason(
                $invoice,
                $line === false || $line === null ? null : (string) $line,
                (float) ($this->taxConstants->forYear($docYear)['simplified_document_limit_with_vat']
                    ?? SimplifiedDocumentPolicy::LIMIT_WITH_VAT),
            );
            if ($reason !== null) {
                return Json::error($response, 'simplified_document_not_allowed', $reason, 422);
            }
        }

        // Pokud projekt vyžaduje schválení výkazu A faktura má výkaz, musí být approved.
        // Faktury bez výkazu (např. fixní paušál) lze vystavit i u projektu s requires_approval.
        if (!empty($invoice['project_requires_approval'])
            && ($invoice['approval_status'] ?? 'none') !== 'approved'
            && $this->workReports->findByInvoice($id) !== null
        ) {
            return Json::error(
                $response,
                'approval_required',
                'Tato zakázka vyžaduje schválení výkazu zákazníkem před vystavením faktury.',
                409,
            );
        }

        $issueDate = new \DateTimeImmutable($invoice['issue_date']);

        $supplierId = (int) $invoice['supplier_id'];

        // Rozhodné datum dokladu pro plátcovství DPH: DUZP (tax_date), jinak issue_date.
        // U dobropisu se tax_date při vystavení přepisuje datem doručení (§ 42 odst. 3,
        // UPDATE níže) — rozhodné datum ho musí zohlednit už teď.
        $vatStatusDate = $invoice['invoice_type'] === 'credit_note' && !empty($invoice['corrective_delivered_on'])
            ? (string) $invoice['corrective_delivered_on']
            : (string) (($invoice['tax_date'] ?? null) ?: $invoice['issue_date']);

        // Neplátce nesmí vystavit doklad s DPH (§ 108 odst. 4 ZDPH: uvedená daň by se
        // musela odvést). Rozhoduje stav K ROZHODNÉMU DATU dokladu, ne dnešní cache —
        // firma, která k 30. 6. přestala být plátcem, smí v srpnu doúčtovat červnové
        // plnění s DPH, ale červencové už ne. Reverse charge se nekontroluje: RC řádky
        // daň nenesou a identifikovaná osoba (§ 6g–6l) RC doklad vystavit smí.
        if (
            (float) ($invoice['total_vat'] ?? 0) > 0
            && empty($invoice['reverse_charge'])
            && !$this->vatStatus->isVatPayerAt($supplierId, $vatStatusDate)
        ) {
            return Json::error(
                $response,
                'not_vat_payer_at_date',
                sprintf(
                    'Firma není k rozhodnému datu dokladu (%s) plátcem DPH — doklad s DPH nelze vystavit. '
                        . 'Přepněte položky na sazbu 0 %%, nebo opravte datum zdanitelného plnění.',
                    date('j. n. Y', strtotime($vatStatusDate)),
                ),
                422,
            );
        }

        // Sklad (§5.1): předběžná kontrola dostupnosti PŘED přidělením varsymbolu —
        // deterministický nedostatek → 409 bez propáleného čísla řady FV (test #3).
        // Autoritativní kontrolu pod zámky dělá až transakce níže.
        if ($this->stockIssue->isStockEnabled($supplierId)) {
            try {
                $this->stockIssue->assertAvailableForInvoice($supplierId, $invoice);
            } catch (StockException $e) {
                return Json::error(
                    $response,
                    'stock.error.' . $e->errorCode,
                    $e->getMessage(),
                    $e->httpStatus,
                    ['items' => $e->details],
                );
            }
        }

        // Pokud byl draft ručně očíslován (varsymbol zadaný v editoru), respektuj override
        // a NEinkremenetuj counter. Jen ověříme unikátnost v rámci supplier scope.
        $manualVarsymbol = trim((string) ($invoice['varsymbol'] ?? ''));
        if ($manualVarsymbol !== '') {
            $dup = $this->db->pdo()->prepare(
                'SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ? AND id != ? LIMIT 1'
            );
            $dup->execute([$supplierId, $manualVarsymbol, $id]);
            if ($dup->fetchColumn()) {
                return Json::error(
                    $response,
                    'varsymbol_duplicate',
                    "Číslo '{$manualVarsymbol}' už existuje u jiné faktury tohoto dodavatele.",
                    409,
                );
            }
            $varsymbol = $manualVarsymbol;
        } else {
            try {
                $varsymbol = $this->varsymbol->next(
                    $supplierId,
                    $invoice['invoice_type'],
                    $issueDate,
                    (int) $invoice['client_id'],
                    (int) ($invoice['revenue_category_id'] ?? 0),
                );
            } catch (\InvalidArgumentException | \RuntimeException $e) {
                return Json::error($response, 'varsymbol_failed', $e->getMessage(), 500);
            }
        }

        try {
            $snapshots = $this->snapshots->build(
                (int) $invoice['client_id'],
                (int) $invoice['currency_id'],
                $supplierId,
                isset($invoice['branding_profile_id']) ? (int) $invoice['branding_profile_id'] : null,
                $vatStatusDate,
            );
        } catch (\RuntimeException $e) {
            return Json::error($response, 'snapshot_failed', $e->getMessage(), 500);
        }

        // Finální daňový doklad plně pokrytý zálohou (amount_to_pay <= 0) je fakticky
        // zaplacený už při vystavení — záloha dorazila dřív. Označíme ho rovnou jako
        // 'paid' (paid_at = issue_date dokladu, datum se váže na daňový doklad, ne na
        // proformu), jinak by zbytečně visel jako nezaplacený/po splatnosti a reálné
        // inkaso by chybělo v kasových reportech (cash-flow, limit paušální daně), které
        // sčítají daňové doklady, ne proformy. Detail podmínky viz InvoiceAmountPolicy.
        $autoPaid = InvoiceAmountPolicy::shouldAutoMarkPaidOnIssue($invoice);

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'UPDATE invoices SET
                varsymbol         = ?,
                client_snapshot   = ?,
                supplier_snapshot = ?,
                bank_snapshot     = ?,
                status            = ?,
                paid_at           = ?,
                -- § 42 odst. 3: u dobropisu určuje období opravy DEN DORUČENÍ opravného
                -- dokladu. `effective_tax_date` je generovaný z `tax_date`, takže se
                -- musí přepsat právě ten; datum doručení zůstává v samostatném sloupci,
                -- aby šlo doložit, proč doklad spadl do daného období.
                tax_date          = COALESCE(?, tax_date)
             WHERE id = ? AND status = "draft"'
        );
        $issueParams = [
            $varsymbol,
            json_encode($snapshots['client'],   JSON_UNESCAPED_UNICODE),
            json_encode($snapshots['supplier'], JSON_UNESCAPED_UNICODE),
            $snapshots['bank'] !== null ? json_encode($snapshots['bank'], JSON_UNESCAPED_UNICODE) : null,
            $autoPaid ? 'paid' : 'issued',
            // Daňový doklad k přijaté platbě: paid_at = den přijetí úplaty (tax_date/DUZP),
            // ne den vystavení dokladu — kasové reporty mají vidět skutečné inkaso.
            $autoPaid
                ? ($invoice['invoice_type'] === 'tax_document'
                    ? ($invoice['tax_date'] ?? $invoice['issue_date'])
                    : $invoice['issue_date'])
                : null,
            $invoice['invoice_type'] === 'credit_note'
                ? ($invoice['corrective_delivered_on'] ?? null)
                : null,
            $id,
        ];

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);

        if ($this->stockIssue->isStockEnabled($supplierId)) {
            // Sklad zapnutý (Epic SKLAD §5.2): flip statusu + auto-výdejka = JEDNA
            // transakce. READ COMMITTED je nutné pro FOR UPDATE zámky
            // StockDocumentService (stale RR snapshot by obešel lock-order B3);
            // SET bez SESSION platí jen pro NÁSLEDUJÍCÍ transakci.
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
            try {
                $stmt->execute($issueParams);
                if ($stmt->rowCount() === 0) {
                    $pdo->rollBack();
                    return Json::error($response, 'race_condition', 'Faktura byla mezitím změněna.', 409);
                }
                $this->stockIssue->issueForInvoice(
                    $supplierId,
                    array_merge($invoice, ['varsymbol' => $varsymbol]),
                    isset($user['id']) ? (int) $user['id'] : null,
                );
                $pdo->commit();
            } catch (StockException $e) {
                // Typicky insufficient_stock (409 + výčet chybějících položek) —
                // rollback vrátí fakturu do draftu, nic se nevystavilo.
                $pdo->rollBack();
                return Json::error(
                    $response,
                    'stock.error.' . $e->errorCode,
                    $e->getMessage(),
                    $e->httpStatus,
                    ['items' => $e->details],
                );
            } catch (\PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                // Zachovaná pojistka proti porušení unique indexu (supplier_id, varsymbol).
                if ($dupMsg = self::varsymbolDuplicateMessage($e, $varsymbol)) {
                    return Json::error($response, 'varsymbol_duplicate', $dupMsg, 409);
                }
                throw $e;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        } else {
            // Sklad vypnutý — PŮVODNÍ cesta beze změny (žádná transakce).
            try {
                $stmt->execute($issueParams);
            } catch (\PDOException $e) {
                // Poslední pojistka proti porušení unique indexu (supplier_id, varsymbol) — typicky
                // souběžné vystavení nebo číslo, které proklouzlo kontrolami. Generátor se sice
                // duplicitám aktivně vyhýbá, ale DB constraint je definitivní ochrana proti race.
                if ($dupMsg = self::varsymbolDuplicateMessage($e, $varsymbol)) {
                    return Json::error($response, 'varsymbol_duplicate', $dupMsg, 409);
                }
                throw $e;
            }

            if ($stmt->rowCount() === 0) {
                return Json::error($response, 'race_condition', 'Faktura byla mezitím změněna.', 409);
            }
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.issued', $user['id'] ?? null, 'invoice', $id, [
            'varsymbol' => $varsymbol,
            'type'      => $invoice['invoice_type'],
            'total'     => $invoice['total_with_vat'],
            'currency'  => $invoice['currency'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        if ($autoPaid) {
            $this->logger->log('invoice.paid', $user['id'] ?? null, 'invoice', $id, [
                'paid_at' => $invoice['issue_date'],
                'trigger' => 'advance_fully_covered',
            ], $ip, $request->getHeaderLine('User-Agent'));
        }

        $this->stats->recomputeForInvoiceId($id);
        // Smaž cached draft PDF (Faktura-draft-NN.pdf) — po vystavení má faktura nový
        // varsymbol a snapshoty, takže staré cached PDF už neodpovídá.
        $this->pdfRenderer->invalidate($id, 'invalidate_issue');

        // Auto-post hook (A2): má-li firma zapnutý auto_post_invoices a běží v podvojném
        // účetnictví, zaúčtuj vystavenou fakturu hned. Chyba zaúčtování NESMÍ zablokovat
        // vystavení — DocumentAutoPoster ji jen zaloguje (faktura zůstane vystavená
        // nezaúčtovaná, uživatel ji dožene ručně). Storno/proforma DocumentAutoPoster
        // odmítne (document_not_postable) — taky jen warning.
        $this->autoPoster->maybeAutoPost(
            $supplierId,
            'invoice',
            $id,
            isset($user['id']) ? (int) $user['id'] : null,
            $ip,
            $request->getHeaderLine('User-Agent'),
        );

        // Prodej majetku (1177): řádky navázané na kartu ji uzavřou — drobný majetek přejde na
        // 'sold', dlouhodobý se vyřadí (541/08x + 08x/02x). Až PO auto-postu, aby v deníku
        // seděla chronologie doklad → vyřazení. Chyby si service polyká do audit warningu ze
        // stejného důvodu jako auto-post: faktura je vystavená a zákazník ji má.
        $assetSaleWarnings = $this->assetSale->applyForIssuedInvoice($supplierId, $id, [
            'user_id'    => isset($user['id']) ? (int) $user['id'] : null,
            'ip'         => $ip,
            'user_agent' => $request->getHeaderLine('User-Agent'),
        ]);

        // Hotovostní vyrovnání (migrace 1327): draft se inkasovat nedá, takže volbu
        // „přijmout hotově do pokladny" uplatní až vystavení — vznikne příjmový pokladní
        // doklad a faktura je rovnou uhrazená. Až ZA auto-postem a prodejem majetku, ať
        // v deníku sedí chronologie doklad → úhrada. Měkká brána: chyba pokladny
        // NESMÍ shodit vystavení (zákazník už doklad má), jen se ohlásí.
        $settlement = $this->cashSettlement->maybeSettle(
            $supplierId,
            'invoice',
            $id,
            isset($user['id']) ? (int) $user['id'] : null,
            $ip,
            $request->getHeaderLine('User-Agent'),
        );

        $issued = $this->repo->find($id);
        if ($settlement['status'] !== CashSettlementService::NOOP) {
            $issued['_cash_settlement'] = $settlement;
        }
        if ($settlement['status'] === CashSettlementService::FAILED) {
            $issued['warnings'] = array_merge(
                is_array($issued['warnings'] ?? null) ? $issued['warnings'] : [],
                [CashSettlementService::WARNING],
            );
        }

        // Karta majetku, kterou se nepodařilo uzavřít (zavřené období, nedoúčtovaný odpis
        // minulého roku, daňová evidence). Faktura je platná a zaúčtovaná, ale uživatel to
        // musí vidět hned — jinak se o neuzavřené kartě dozví až při inventarizaci.
        if ($assetSaleWarnings !== []) {
            $issued['asset_sale_warnings'] = $assetSaleWarnings;
        }

        // § 42 odst. 3 — bez data doručení zůstává obdobím opravy `tax_date` z okamžiku
        // vytvoření dobropisu. Přes přelom měsíce to bývá JINÉ zdaňovací období, než do
        // kterého oprava patří, a systém by o tom mlčel.
        if (($invoice['invoice_type'] ?? '') === 'credit_note'
            && empty($invoice['corrective_delivered_on'])
        ) {
            $issued['warnings'] = array_merge(
                is_array($issued['warnings'] ?? null) ? $issued['warnings'] : [],
                ['credit_note_delivery_date_missing'],
            );
        }

        return Json::ok($response, $issued);
    }
}
