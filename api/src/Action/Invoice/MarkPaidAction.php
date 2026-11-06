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
use MyInvoice\Service\Eet\EetCommandException;
use MyInvoice\Service\Eet\EetCreditNoteRefundGate;
use MyInvoice\Service\Eet\EetInvoicePaymentGate;
use MyInvoice\Service\Eet\IdempotencyConflictException;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\Invoice\RefundDocument;
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
        private readonly EetInvoicePaymentGate $eetGate,
        private readonly EetCreditNoteRefundGate $eetRefunds,
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
        $eet = null;
        $eetDecision = null;
        // Doklad k vyplacení = vrácení peněz zákazníkovi. Faktura se zálohou (přeplatek
        // zálohy) zůstává na bookkeeping flipu níž, jak ji zná vystavení.
        if ((string) ($invoice['invoice_type'] ?? '') === 'credit_note'
            || (RefundDocument::isRefundDocument($invoice) && (int) ($invoice['parent_invoice_id'] ?? 0) <= 0)
        ) {
            try {
                // EET-08: kontaktní vrácení k dobropisu s evidovanou tržbou = záporná tržba.
                $route = $this->eetRefunds->route(
                    (int) $invoice['supplier_id'],
                    (int) ($user['id'] ?? 0),
                    $id,
                    $body,
                    $paidAt,
                    fn (string $paidOn): bool => $this->payments->markRefunded($id, (int) $invoice['supplier_id'], $paidOn),
                );
                $paidAt = $route['paid_on'];
                if ($route['mode'] === 'eet') {
                    $eet = $route['eet'] ?? ['corrections' => [], 'warning' => null];
                    $this->stats->recomputeAfterCommit($id);
                } elseif ($route['decision'] !== null) {
                    $eetDecision = $route['decision'];
                }
            } catch (EetCommandException $e) {
                return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->extra);
            } catch (\PDOException $e) {
                throw $e;
            } catch (\RuntimeException $e) {
                return Json::error($response, 'invalid_payment', $e->getMessage(), 409);
            }
        } elseif ($remaining > 0) {
            try {
                // EET-05: u firmy s aktivní evidencí rozhoduje o cestě společný příkaz.
                $route = $this->eetGate->route(
                    (int) $invoice['supplier_id'],
                    (int) ($user['id'] ?? 0),
                    $id,
                    number_format($remaining, 2, '.', ''),
                    $body,
                    $request->getHeaderLine('Idempotency-Key'),
                    'mark_paid',
                    EetInvoicePaymentGate::tokenDefaultsToExternal($request->getAttribute(AuthMiddleware::ATTR_API_TOKEN), RequestAuthorization::isBearerAuth($request)),
                );
                if ($route['mode'] === 'eet') {
                    $eet = $route['result'];
                    $paidAt = (string) ($eet['paid_on'] ?? $paidAt);
                } else {
                    $eetDecision = $route['decision'];
                    $this->payments->recordPayment($id, $remaining, $paidAt, [
                        'source'     => 'mark_paid',
                        'created_by' => (int) ($user['id'] ?? 0),
                    ]);
                }
            } catch (IdempotencyConflictException $e) {
                return Json::error($response, 'idempotency_conflict', $e->getMessage(), 409);
            } catch (EetCommandException $e) {
                return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->extra);
            } catch (\PDOException $e) {
                // Chyba databáze není neplatná platba, viz CreatePaymentAction.
                throw $e;
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
            $this->stats->recomputeAfterCommit($id);
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
        if ($eet !== null) {
            $result['eet'] = $eet;
        } elseif ($eetDecision !== null) {
            $result['eet_decision'] = $eetDecision;
        }
        return Json::ok($response, $result);
    }
}
