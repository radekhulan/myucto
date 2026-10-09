<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Action\Invoice\HandlesVarsymbolDuplicate;
use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SessionOnlyAccountingAct;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\Cash\CashException;
use MyInvoice\Service\Accounting\Cash\CashSettlementService;
use MyInvoice\Service\Accounting\DocumentJournalSync;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\PurchaseInvoice\Approval\PurchaseApprovalException;
use MyInvoice\Service\PurchaseInvoice\Approval\PurchaseInvoiceApprovalService;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceReceiver;
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
        private readonly CashSettlementService $cashSettlement,
        private readonly \MyInvoice\Service\Accounting\Card\CardPaymentAutomation $cardAutomation,
        private readonly PurchaseInvoiceReceiver $receiver,
        private readonly PurchaseInvoiceApprovalService $approvals,
        private readonly \MyInvoice\Service\Invoice\CreditNoteOffsetService $creditNoteOffsets,
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

        // Ruční zaúčtování přes API token ne. Automatické zaúčtování při přijetí (hook
        // u 'received' níž) je volba firmy a token ho spouští stejně jako uživatel.
        if ($target === 'booked'
            && ($deny = SessionOnlyAccountingAct::deny($request, $response, 'Ruční zaúčtování přijaté faktury'))) {
            return $deny;
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

        // Schvalování manažerem střediska (F6): doklad, který schválení vyžaduje a ještě
        // ho nemá, se nepřijme — místo toho vznikne kolo schvalování a doklad zůstane
        // konceptem. Bez typu dimenze se schvalováním brána nic nedělá.
        if ($currentStatus === 'draft' && $target === 'received') {
            try {
                $gate = $this->approvals->gateReceive(
                    $supplierId,
                    $id,
                    isset($user['id']) ? (int) $user['id'] : null,
                    $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                    $request->getHeaderLine('User-Agent'),
                );
            } catch (PurchaseApprovalException $e) {
                return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
            }
            if ($gate !== null) {
                return Json::ok($response, (array) $this->repo->find($id, $supplierId) + $gate);
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

        // Dobropis vyrovnaný zápočtem (issue #140): „Zrušit úhradu" zruší zápočet a stav
        // vrátí ten sám. Háčky přijetí se pak nespouští, jinak by zápočet hned vznikl znovu.
        $offsetReverted = $currentStatus === 'paid' && $target === 'received'
            && (string) ($existing['document_kind'] ?? '') === 'credit_note'
            && $this->creditNoteOffsets->revertForDocument($supplierId, 'purchase_invoice', $id) > 0;

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
                // Zápočet dobropisu (issue #140) padá se stornem kterékoli strany.
                $this->creditNoteOffsets->revertForDocument($supplierId, 'purchase_invoice', $id);
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
        } elseif (!$offsetReverted && !$this->repo->setStatus($id, $target, $supplierId, $paidDate, $bookedBy, $requireUnbooked)) {
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

        // Háčky přijetí (auto-zaúčtování, karta, drobný majetek, hotovost) žijí v jednom
        // místě, které sdílí i přijetí po schválení manažerem střediska — viz
        // PurchaseInvoiceReceiver. Opakované dosažení stavu received (un-cancel) je
        // idempotentní.
        $userId = isset($user['id']) ? (int) $user['id'] : null;
        $settlement = null;
        if ($target === 'received' && !$offsetReverted) {
            $settlement = $this->receiver->afterReceived($supplierId, $id, $userId, $ip, $request->getHeaderLine('User-Agent'));
        } else {
            // Platba kartou: zaúčtovaný / uhrazený doklad se spáruje s pohybem karty
            // (idempotentní, bez režimu karet no-op).
            if (in_array($target, ['booked', 'paid'], true)) {
                $this->cardAutomation->afterPurchaseReady($supplierId, $id, $userId);
            }
            // Hotovostní vyrovnání (migrace 1327) uplatní i zaúčtování dokladu.
            if ($target === 'booked') {
                $settlement = $this->cashSettlement->maybeSettle(
                    $supplierId,
                    'purchase_invoice',
                    $id,
                    $userId,
                    $ip,
                    $request->getHeaderLine('User-Agent'),
                );
            }
        }

        // Stornovaný koncept už schválení nepotřebuje (F6).
        if ($currentStatus === 'draft' && $target === 'cancelled') {
            $this->approvals->onInvoiceLeftDraft($supplierId, $id, $userId, 'cancelled');
        }

        $invoice = $this->repo->find($id, $supplierId);
        if ($invoice !== null && $settlement !== null && $settlement['status'] !== CashSettlementService::NOOP) {
            $invoice['_cash_settlement'] = $settlement;
        }

        return Json::ok($response, $invoice);
    }
}
