<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\PaymentThanksMailer;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Stats\StatsRecomputer;
use MyInvoice\Service\Validation\InvoiceAmountPolicy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MarkPaidAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly StatsRecomputer $stats,
        private readonly InvoicePdfRenderer $pdf,
        private readonly PaymentThanksMailer $paymentThanks,
        private readonly InvoicePaymentService $payments,
        private readonly DocumentLockService $locks,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        if (!in_array($invoice['status'], ['issued', 'sent', 'reminded'], true)) {
            return Json::error($response, 'invalid_state', 'Lze označit jako zaplacené jen vystavenou nebo odeslanou fakturu.', 409);
        }
        if (!InvoiceAmountPolicy::canBeMarkedPaid($invoice)) {
            return Json::error($response, 'invalid_amount', InvoiceAmountPolicy::NON_POSITIVE_MARK_PAID_MESSAGE, 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $paidAt = (string) ($body['paid_at'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidAt)) {
            return Json::error($response, 'invalid_date', 'Neplatné datum.', 400);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);

        // Epic F6 (R8/M5): platba je fakt — mark-paid je na zamčeném dokladu povolený
        // i klientovi, ale POVINNĚ se audituje (u proforem spouští vznik daňového dokladu).
        // Zámek se vyhodnotí teď, audit se zapíše až PO úspěšném provedení platby —
        // jinak by selhání zápisu nechalo falešný audit záznam.
        $clientLock = RequestAuthorization::isClientType($request) ? $this->locks->forInvoice($invoice) : null;
        $auditLockedPayment = $clientLock !== null && $clientLock->lockedForClient();

        // Mark-paid = zkratka „platba na zbývající částku" (#89) — evidence plateb
        // zůstává konzistentní (paid_total = amount_to_pay) a označení lze vrátit
        // smazáním platby. Status flip + PDF invalidace + stats řeší service.
        $remaining = round((float) ($invoice['amount_to_pay'] ?? 0) - (float) ($invoice['paid_total'] ?? 0), 2);
        if ((string) ($invoice['invoice_type'] ?? '') === 'credit_note') {
            try {
                $this->payments->markCreditNoteRefunded(
                    $id,
                    (int) $invoice['supplier_id'],
                    $paidAt,
                );
            } catch (\RuntimeException $e) {
                return Json::error($response, 'invalid_payment', $e->getMessage(), 409);
            }
        } elseif ($remaining > 0) {
            try {
                $this->payments->recordPayment($id, $remaining, $paidAt, [
                    'source'     => 'mark_paid',
                    'created_by' => (int) ($user['id'] ?? 0),
                ]);
            } catch (\RuntimeException $e) {
                return Json::error($response, 'invalid_payment', $e->getMessage(), 409);
            }
        } else {
            // Finální doklad plně krytý zálohou (amount_to_pay <= 0) — žádná platba
            // neproběhla, jen bookkeeping flip (původní chování).
            $this->db->pdo()->prepare(
                'UPDATE invoices SET status = "paid", paid_at = ? WHERE id = ?'
            )->execute([$paidAt, $id]);

            // Cached PDF má embedded status (UHRAZENO stamp, QR skip) — bez invalidace by
            // se servíroval starý soubor s výzvou k platbě i po označení za zaplacené.
            $this->pdf->invalidate($id, 'invalidate_mark_paid');
            $this->stats->recomputeForInvoiceId($id);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        if ($auditLockedPayment) {
            $this->logger->log(
                'invoice.payment_on_locked_document',
                isset($user['id']) ? (int) $user['id'] : null,
                'invoice',
                $id,
                ['trigger' => 'mark_paid', 'reasons' => $clientLock->reasons()],
                $ip,
                $request->getHeaderLine('User-Agent'),
            );
        }
        $this->logger->log('invoice.paid', $user['id'] ?? null, 'invoice', $id, [
            'paid_at' => $paidAt,
        ], $ip, $request->getHeaderLine('User-Agent'));

        // Volitelný děkovný e-mail za úhradu (issue #57) — jen pokud uživatel zaškrtl.
        // Selhání odeslání nesmí shodit označení jako zaplacené; service vrací výsledek.
        $thanks = null;
        if (!empty($body['send_payment_thanks'])) {
            $trigger = ($body['thanks_trigger'] ?? '') === 'bulk' ? 'bulk' : 'manual';
            $thanks = $this->paymentThanks->sendForInvoice(
                $id,
                $trigger,
                $user['id'] ?? null,
                $ip,
                $request->getHeaderLine('User-Agent'),
                requireUnsent: false,
            );
        }

        $result = $this->repo->find($id);
        $result['payment_thanks'] = $thanks; // null = neodesíláno
        return Json::ok($response, $result);
    }
}
