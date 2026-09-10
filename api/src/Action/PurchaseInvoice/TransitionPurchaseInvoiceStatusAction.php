<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Action\Invoice\HandlesVarsymbolDuplicate;
use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\Cash\CashException;
use MyInvoice\Service\Accounting\Cash\CashSettlementService;
use MyInvoice\Service\Accounting\DocumentAutoPoster;
use MyInvoice\Service\Accounting\DocumentJournalSync;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\SmallAsset\SmallAssetService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/purchase-invoices/{id}/transition
 *
 * Přechod stavu přijaté faktury podle state machine:
 *   draft     → received | cancelled
 *   received  → booked | paid | cancelled
 *   booked    → paid | cancelled
 *   paid      → (terminal — jen unmark přes samostatný endpoint, není v fázi 1)
 *   cancelled → (terminal)
 *
 * Body: { target: "received|booked|paid|cancelled", paid_date?: "YYYY-MM-DD" (jen pro paid) }
 *
 * Při přechodu draft→received se automaticky vygeneruje varsymbol, pokud chybí.
 */
final class TransitionPurchaseInvoiceStatusAction
{
    use HandlesVarsymbolDuplicate;
    use GuardsDocumentLock;

    private const TRANSITIONS = [
        // Forward flow (typical lifecycle): draft → received → booked → paid
        'draft'    => ['received', 'cancelled'],
        'received' => ['booked', 'paid', 'cancelled'],
        'booked'   => ['paid', 'cancelled'],
        // Reverse / corrective flows — user občas potřebuje opravit:
        //   paid → received   = unmark paid (omylem označeno)
        //   paid → cancelled  = storno už uhrazené faktury
        //   cancelled → received = un-cancel (vrátit do hry)
        'paid'      => ['received', 'cancelled'],
        'cancelled' => ['received'],
    ];

    /** Cílové stavy povolené roli client (M2): booked/cancelled = účetní akt → 403 VŽDY. */
    private const CLIENT_TARGETS = ['received', 'paid'];

    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly DocumentLockService $locks,
        private readonly Connection $db,
        private readonly DocumentJournalSync $journalSync,
        private readonly DocumentAutoPoster $autoPoster,
        private readonly SmallAssetService $smallAssets,
        private readonly CashSettlementService $cashSettlement,
        private readonly \MyInvoice\Service\Accounting\Card\CardPaymentAutomation $cardAutomation,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'invalid_id', 'Neplatné ID', 400);
        }

        $supplierId = SupplierGuard::currentId($request);
        $existing = $this->repo->find($id, $supplierId);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $target = (string) ($body['target'] ?? '');

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);

        // Epic F6: klient smí jen received ⇄ paid; booked/cancelled jsou účetní akt —
        // 403 forbidden_transition VŽDY, bez ohledu na zámek i stav dokladu.
        if (RequestAuthorization::isClientType($request) && !in_array($target, self::CLIENT_TARGETS, true)) {
            return Json::error($response, 'forbidden_transition', 'Tento přechod stavu provádí účetní.', 403);
        }

        // Zámek dokladu (Epic F6): received ⇄ paid jen na nezamčených dokladech (jen client;
        // účetní workflow stavy mění bez omezení — datum účetního případu se neposouvá).
        $lock = $this->locks->forPurchaseInvoice($existing);
        if ($deny = $this->denyIfLocked($request, $response, $lock, 'purchase_invoice', $id, clientOnly: true)) {
            return $deny;
        }

        // Matice §4.3: booked/cancelled je účetní akt k datu dokladu — staff v zavřeném
        // období 409 period_closed, admin ?force=1 s auditem. Client sem s těmito targety
        // nedojde (403 forbidden_transition výš), takže druhé volání gatuje jen staff.
        if (in_array($target, ['booked', 'cancelled'], true)) {
            if ($deny = $this->denyIfLocked($request, $response, $lock, 'purchase_invoice', $id)) {
                return $deny;
            }
        }

        $currentStatus = (string) $existing['status'];
        $allowed = self::TRANSITIONS[$currentStatus] ?? [];

        if (!in_array($target, $allowed, true)) {
            return Json::error(
                $response,
                'invalid_transition',
                "Z {$currentStatus} nelze přejít na {$target}.",
                409,
                ['allowed' => $allowed],
            );
        }

        // FR1 (vendor audit 2026-08): DUZP je legislativně nosný údaj daňového
        // dokladu (§21 vznik povinnosti přiznat daň, §73/1/a nárok na odpočet) —
        // PurchaseInvoiceValidation dosud kontrolovala jen FORMÁT, když byl vyplněný, takže
        // `tax_date IS NULL` klidně protekl až do podkladů DPH (VatLedgerService spadá na
        // COALESCE(tax_date, issue_date), což u dokladu s jiným skutečným DUZP než datem
        // vystavení dá špatné zdaňovací období). TVRDÝ blok právě tady, na přechodu do
        // `booked` — to je okamžik, kdy se doklad stává účetním případem ("zaúčtování").
        // Záměrně NE na `received` (pořizovací/pracovní stav, migrace historie tam musí
        // projít volně) a záměrně NE retroaktivně — kontrola platí jen na NOVÝ přechod, už
        // dřív zaúčtované doklady (typicky z migrace historie) zůstávají beze změny, takže
        // upgrade nikomu nezablokuje uzávěrku existujícího období.
        if ($target === 'booked' && empty($existing['tax_date'])) {
            return Json::error(
                $response,
                'missing_tax_date',
                'Doklad nemá vyplněné DUZP (datum uskutečnění zdanitelného plnění) — bez něj nelze fakturu zaúčtovat.',
                422,
            );
        }

        $paidDate = null;
        if ($target === 'paid') {
            $paidDate = !empty($body['paid_date']) ? (string) $body['paid_date'] : date('Y-m-d');
            $d = \DateTimeImmutable::createFromFormat('Y-m-d', $paidDate);
            if ($d === false || $d->format('Y-m-d') !== $paidDate) {
                return Json::error($response, 'validation_failed', 'Neplatné paid_date', 400);
            }
        }

        // Při přechodu draft→received vygenerujeme varsymbol pokud chybí
        if ($currentStatus === 'draft' && $target === 'received' && empty($existing['varsymbol'])) {
            try {
                $this->repo->ensureVarsymbol($id, $supplierId);
            } catch (\PDOException $e) {
                // Race na unique indexu (uq_pi_supplier_varsymbol) — generátor se kolizím
                // vyhýbá, tohle je poslední pojistka proti souběžnému přijetí.
                if ($dupMsg = self::varsymbolDuplicateMessage($e, null)) {
                    return Json::error($response, 'varsymbol_duplicate', $dupMsg, 409);
                }
                throw $e;
            } catch (\RuntimeException $e) {
                return Json::error($response, 'internal_error', 'Nepodařilo se vygenerovat varsymbol', 500);
            }
        }

        // Ruční zaúčtování PF = transition na booked (Epic F6, §4.7): doplň booked_by.
        // Pro klienta optimistický zámek L1 — UPDATE podmíněný booked_at IS NULL
        // (účetní mohla zaúčtovat mezi guard-checkem výš a tímto zápisem).
        $bookedBy = $target === 'booked' && !empty($user['id']) ? (int) $user['id'] : null;
        $requireUnbooked = RequestAuthorization::isClientType($request);

        if ($target === 'cancelled') {
            // A3 (audit H5): storno PF (přechod na cancelled) musí stornovat i aktivní
            // zápis v deníku — jinak deník drží náklad + 321 stornované PF, kterou DPH
            // evidence už nevykazuje. Reverze + setStatus v JEDNÉ transakci; uzavřené
            // období → PostingException → rollback + 409 (doklad i zápis beze změny).
            $pdo = $this->db->pdo();
            $ownTx = !$pdo->inTransaction();
            if ($ownTx) {
                $pdo->beginTransaction();
            }
            try {
                // Hotovostní vyrovnání (migrace 1327): stornovaná faktura nesmí zůstat
                // „uhrazená" zaúčtovaným VPD — pokladní doklad i jeho zápis padnou s ní,
                // ve stejné transakci. Ruční pokladní doklady (auto_settlement = 0) se
                // nedotýká; ty ať uživatel vyřídí v modulu Pokladna vědomě.
                $this->cashSettlement->detach($supplierId, 'purchase_invoice', $id);
                $this->journalSync->onCancel($supplierId, 'purchase_invoice', $id, [
                    'user_id'    => $user['id'] ?? null,
                    'posted_by'  => $user['id'] ?? null,
                    'ip'         => $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                    'user_agent' => $request->getHeaderLine('User-Agent'),
                ]);
                if (!$this->repo->setStatus($id, $target, $supplierId, $paidDate, $bookedBy, $requireUnbooked)) {
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
                    'Přijatou fakturu nelze stornovat — nejdřív vyřešte pokladní doklad, kterým byla '
                        . 'hotově uhrazena (' . $e->getMessage() . ').',
                    409,
                );
            } catch (PostingException $e) {
                if ($ownTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return Json::error(
                    $response,
                    'journal_' . $e->errorCode,
                    'Přijatou fakturu nelze stornovat — má zaúčtovaný zápis, který nelze stornovat ('
                        . $e->getMessage() . '). Nejdřív vyřešte zaúčtování v deníku.',
                    409,
                );
            } catch (\Throwable $e) {
                if ($ownTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        } elseif (!$this->repo->setStatus($id, $target, $supplierId, $paidDate, $bookedBy, $requireUnbooked)) {
            return Json::error(
                $response,
                'document_locked',
                'Doklad byl mezitím zaúčtován — změny vyřídí vaše účetní.',
                409,
            );
        }

        // Při přechodu z draftu (typicky po manuální kontrole AI-importované faktury)
        // automaticky vyčistit extraction_warning — uživatel data ověřil tím, že
        // posunul stav z konceptu dál. Pokud warning není set, je to no-op.
        if ($currentStatus === 'draft' && $target !== 'cancelled' && !empty($existing['extraction_warning'])) {
            try {
                $this->repo->setExtractionWarning($id, $supplierId, null);
            } catch (\Throwable) {
                // Silent — transition už proběhl, warning clear je jen nice-to-have.
            }
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log("purchase_invoice.transitioned", $user['id'] ?? null, 'purchase_invoice', $id, [
            'from' => $currentStatus,
            'to'   => $target,
        ], $ip, $request->getHeaderLine('User-Agent'));

        // Auto-post hook (A2): přijetí přijaté faktury (přechod na 'received') je analog
        // vystavení FV — má-li firma zapnutý auto_post_purchases a běží v podvojném
        // účetnictví, zaúčtuj PF hned. Chyba zaúčtování NESMÍ zablokovat přechod stavu —
        // DocumentAutoPoster ji jen zaloguje (PF zůstane nezaúčtovaná). Idempotentní, takže
        // opakované dosažení stavu received (un-cancel apod.) zápis neduplikuje.
        if ($target === 'received') {
            $this->autoPoster->maybeAutoPost(
                $supplierId,
                'purchase_invoice',
                $id,
                isset($user['id']) ? (int) $user['id'] : null,
                $ip,
                $request->getHeaderLine('User-Agent'),
            );
        }

        // Platba kartou: přijatý doklad s koncovkou karty se spáruje s pohybem karty
        // a zaúčtuje se vypořádání 321/378.x (idempotentní, bez režimu karet no-op).
        if (in_array($target, ['received', 'booked', 'paid'], true)) {
            $this->cardAutomation->afterPurchaseReady($supplierId, $id, isset($user['id']) ? (int) $user['id'] : null);
        }

        // Evidence drobného majetku (§DM): protějšek hooku v UpdatePurchaseInvoiceAction,
        // který na draftu záměrně nic nedělá — rozpracovaný doklad ještě není pořízení.
        // Bez tohoto volání ale klasifikace udělaná V DRAFTU nikam nedojde: ISDOC import
        // zakládá fakturu vždy jako draft, uživatel v ní označí položky za majetek, uloží
        // (hook nad draftem mlčí) a finalizuje — a evidence zůstane prázdná. Kartu pak
        // vyrobilo teprve druhé uložení už přijaté faktury, které navíc chce ?force=1 a
        // roli admin, takže klientovi nevznikla nikdy. Přijetí dokladu je právě ten
        // okamžik, kdy se z rozpracovaného stává pořízení.
        //
        // Idempotentní přes přirozený klíč (název + cena), takže opakované dosažení stavu
        // received (un-cancel) kartu neduplikuje. Chyba evidence NESMÍ shodit přechod
        // stavu — stejně jako u auto-postu výš; jen ji zalogujeme, ať nezmizí potichu.
        if ($target === 'received') {
            try {
                $this->smallAssets->syncFromPurchaseInvoice(
                    $supplierId,
                    $id,
                    isset($user['id']) ? (int) $user['id'] : null,
                );
            } catch (\Throwable $e) {
                $this->logger->log('purchase_invoice.small_asset_sync_failed', $user['id'] ?? null,
                    'purchase_invoice', $id, ['error' => $e->getMessage()],
                    $ip, $request->getHeaderLine('User-Agent'));
            }
        }

        // Hotovostní vyrovnání (migrace 1327): koncept ještě není závazek, takže volbu
        // „uhradit hotově z pokladny" uplatní až přijetí/zaúčtování dokladu. Měkká brána —
        // chyba pokladny nesmí zablokovat přechod stavu (stejně jako auto-post výš);
        // doklad pak jen zůstane neuhrazený a warning je v auditní stopě.
        $settlement = null;
        if (in_array($target, ['received', 'booked'], true)) {
            $settlement = $this->cashSettlement->maybeSettle(
                $supplierId,
                'purchase_invoice',
                $id,
                isset($user['id']) ? (int) $user['id'] : null,
                $ip,
                $request->getHeaderLine('User-Agent'),
            );
        }

        $invoice = $this->repo->find($id, $supplierId);
        if ($invoice !== null && $settlement !== null && $settlement['status'] !== CashSettlementService::NOOP) {
            $invoice['_cash_settlement'] = $settlement;
        }

        return Json::ok($response, $invoice);
    }
}
