<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Eet\EetHistoryExistsException;
use MyInvoice\Service\Eet\EetHistoryGuard;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\Invoice\TakenOverFillerGuard;
use MyInvoice\Service\Invoice\TakenOverPayment;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Stats\StatsRecomputer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/invoices/{id}/unmark-paid (admin only)
 *
 * Vrátí fakturu ze stavu 'paid' zpět do 'sent' (pokud byla odeslaná) nebo 'issued',
 * vyčistí paid_at, přepočítá statistiky.
 *
 * Bezpečnostní guard: pokud je faktura spárovaná s aktivní bankovní transakcí,
 * vrátí 409 — uživatel má použít „Zrušit spárování" v detailu výpisu, který
 * cascades správně (vrátí jak invoice, tak bank tx).
 */
final class UnmarkPaidAction
{
    use GuardsDocumentLock;

    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly StatsRecomputer $stats,
        private readonly InvoicePdfRenderer $pdf,
        private readonly InvoicePaymentService $payments,
        private readonly DocumentLockService $locks,
        private readonly EetHistoryGuard $eetHistory,
        private readonly TakenOverFillerGuard $fillerGuard,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::isCompanyAdmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        if ($invoice['status'] !== 'paid') {
            return Json::error($response, 'invalid_state', 'Lze vrátit zpět jen zaplacenou fakturu.', 409);
        }
        if (!empty($invoice['other_item_id'])) {
            return Json::error($response, 'other_item_linked',
                'Faktura je uhrazená úhradami navázané ostatní pohledávky. Úhradu odpojte v detailu pohledávky, nebo fakturu od pohledávky odpojte.', 409);
        }

        // Zámek dokladu (Epic F6): unmark-paid mění účetní fakt — v zavřeném období
        // jen admin s ?force=1 (klient sem přes admin-only check výše nedojde).
        if ($deny = $this->denyIfLocked($request, $response, $this->locks->forInvoice($invoice), 'invoice', $id)) {
            return $deny;
        }

        // Guard: nesmí být spárovaná aktivní bankovní transakce — uživatel má
        // použít bank unmatch flow, který odpáruje tx i invoice atomicky.
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM bank_transactions
              WHERE matched_invoice_id = ?
                AND match_status IN ('auto_exact', 'auto_partial', 'manual')"
        );
        $stmt->execute([$id]);
        $matchedCount = (int) $stmt->fetchColumn();
        if ($matchedCount > 0) {
            return Json::error(
                $response,
                'has_matched_tx',
                'Faktura je spárovaná s bankovní transakcí. Nejdřív zruš spárování v detailu výpisu.',
                409,
            );
        }

        // Guard #2: k některé z plateb existuje (nestornovaný) daňový doklad k přijaté
        // platbě — smazání platby by rozbilo daňovou stopu. Nejdřív doklad smazat/stornovat.
        $tdStmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*)
               FROM invoice_payments p
               JOIN invoices td ON td.id = p.tax_document_invoice_id
              WHERE p.invoice_id = ? AND td.status <> 'cancelled'"
        );
        $tdStmt->execute([$id]);
        if ((int) $tdStmt->fetchColumn() > 0) {
            return Json::error(
                $response,
                'has_tax_document',
                'K platbám faktury existuje daňový doklad k přijaté platbě. Nejdřív ho smaž (koncept) nebo stornuj.',
                409,
            );
        }

        // Guard #3: úhrada převzatá z POHODY bez protějšku v MyÚčtu se nemaže
        // (deleteAllForInvoice ji ponechá). Výplň Money S3 smaže oprávněný uživatel, když
        // žádná z výplní dokladu neleží v zamčeném ani podaném období (TakenOverFillerGuard).
        // Pokrývá-li doklad sama ponechaná úhrada, vrátit ho na neuhrazený nejde; jinak se
        // smažou jen platby, které smazat jde.
        $fillers = $this->db->pdo()->prepare('SELECT p.id, p.paid_on, p.amount, p.note FROM invoice_payments p WHERE p.invoice_id = ? AND ' . TakenOverPayment::moneyS3FillerSql('p'));
        $fillers->execute([$id]);
        $fillerRows = $fillers->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $allowFiller = $fillerRows !== [] && RequestAuthorization::canDeleteTakenOverFiller($request);
        foreach ($allowFiller ? $fillerRows : [] as $f) {
            $allowFiller = $allowFiller && $this->fillerGuard->blockedReason((int) $invoice['supplier_id'], $f) === null;
        }
        $keptStmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(p.amount), 0) AS kept, COALESCE(SUM(CASE WHEN ' . TakenOverPayment::moneyS3FillerSql('p') . ' THEN 0 ELSE p.amount END), 0) AS pohoda
               FROM invoice_payments p WHERE p.invoice_id = ? AND ' . TakenOverPayment::isProtectedSql('p', $allowFiller)
        );
        $keptStmt->execute([$id]);
        $keptRow = $keptStmt->fetch(\PDO::FETCH_ASSOC) ?: ['kept' => 0, 'pohoda' => 0];
        $kept = (float) $keptRow['kept'];
        if ($kept > 0 && $kept >= (float) $invoice['amount_to_pay'] - InvoicePaymentService::TOLERANCE) {
            return Json::error($response, TakenOverPayment::ERROR_CODE, (float) $keptRow['pohoda'] >= 0.01
                ? 'Doklad je uhrazený úhradou převzatou z POHODY, která se nemaže. Vrátit ho na neuhrazený nejde; opravte převod opakovaným převodem.'
                : 'Doklad je uhrazený úhradou převzatou z Money S3 bez dokladu úhrady. Smazat ji smí správce nebo role s právem upravit vystavenou fakturu, jen v nezamčeném a nepodaném období.', 409);
        }

        if ((string) ($invoice['invoice_type'] ?? '') === 'credit_note'
            && ($eetConflict = $this->eetHistory->creditNoteRefundConflict((int) $invoice['supplier_id'], $id)) !== null) {
            return Json::error($response, EetHistoryGuard::ERROR_CODE, $eetConflict, 409);
        }

        $previousPaidAt = (string) ($invoice['paid_at'] ?? '');

        // Smaže evidované platby + přepočítá paid_total; u dokladu s kladnou částkou
        // k úhradě tím service rovnou revertuje status (sent/issued) a vyčistí paid_at.
        try {
            $this->payments->deleteAllForInvoice($id, $allowFiller);
        } catch (EetHistoryExistsException $e) {
            return Json::error($response, EetHistoryGuard::ERROR_CODE, $e->getMessage(), 409);
        } catch (\RuntimeException $e) {
            return Json::error($response, 'payment_locked', $e->getMessage(), 409);
        }

        // Finální doklad krytý zálohou (amount_to_pay <= 0, žádné platby) service
        // nerevertuje — bookkeeping flip vrátíme přímo (původní chování).
        // Reminder stav (reminded) se nezachovává — uživatel může upomínku poslat znovu.
        $this->db->pdo()->prepare(
            "UPDATE invoices
                SET status  = IF(sent_at IS NOT NULL, 'sent', 'issued'),
                    paid_at = NULL
              WHERE id = ? AND status = 'paid'"
        )->execute([$id]);

        // Cached PDF nese „UHRAZENO" stamp — bez invalidace by se po unmark vracel.
        $this->pdf->invalidate($id, 'invalidate_unmark_paid');

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.unmark_paid', $user['id'] ?? null, 'invoice', $id, [
            'previous_paid_at' => $previousPaidAt ?: null,
        ], $ip, $request->getHeaderLine('User-Agent'));
        foreach ($allowFiller ? $fillerRows : [] as $f) {
            $this->logger->log(DeletePaymentAction::FILLER_AUDIT_ACTION, $user['id'] ?? null, 'invoice', $id, [
                'payment_id' => (int) $f['id'], 'amount' => $f['amount'], 'paid_on' => $f['paid_on'], 'note' => $f['note'], 'trigger' => 'unmark_paid',
            ], $ip, $request->getHeaderLine('User-Agent'));
        }

        $this->stats->recomputeForInvoiceId($id);

        return Json::ok($response, $this->repo->find($id));
    }
}
