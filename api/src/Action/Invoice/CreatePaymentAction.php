<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Eet\EetCommandException;
use MyInvoice\Service\Eet\EetInvoicePaymentGate;
use MyInvoice\Service\Eet\IdempotencyConflictException;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\PaymentThanksMailer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/invoices/{id}/payments — zaeviduje (částečnou) úhradu (#89).
 *
 * Body: amount (povinné, v měně faktury), paid_on (default dnes), variable_symbol,
 * bank_reference, note, send_payment_thanks (jen pokud platba doklad doplatí).
 *
 * Pokud suma plateb pokryje částku k úhradě (tolerance 0,05), faktura se označí
 * jako zaplacená (paid_at = datum poslední platby) — stejné chování jako mark-paid.
 *
 * Firma s aktivní evidencí tržeb (EET-05) posílá i okolnosti platby (channel,
 * contact_context, device_id, received_at, business_purpose nebo zkratku
 * eet_context = remote_transfer, u tržby evidované e-shopem eet_context = external s volitelným
 * external_eet) a u evidované tržby hlavičku Idempotency-Key;
 * rozhoduje {@see EetInvoicePaymentGate}. Ostatní firmy beze změny.
 */
final class CreatePaymentAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly InvoicePaymentService $payments,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly PaymentThanksMailer $paymentThanks,
        private readonly DocumentLockService $locks,
        private readonly EetInvoicePaymentGate $eetGate,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        if (!isset($body['amount']) || !is_numeric($body['amount'])) {
            return Json::error($response, 'invalid_amount', 'Částka platby je povinná.', 400);
        }
        $amount = (float) $body['amount'];
        $paidOn = (string) ($body['paid_on'] ?? date('Y-m-d'));

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);

        // Epic F6 (R8/M5): platba je fakt — na zamčeném dokladu povolena i klientovi,
        // ale POVINNĚ auditována (u proforem spouští vznik daňového dokladu k platbě).
        // Zámek se vyhodnotí teď, audit se zapíše až PO úspěšném provedení platby —
        // jinak by selhání recordPayment nechalo falešný audit záznam.
        $clientLock = RequestAuthorization::isClientType($request) ? $this->locks->forInvoice($invoice) : null;
        $auditLockedPayment = $clientLock !== null && $clientLock->lockedForClient();

        $eet = null;
        try {
            $route = $this->eetGate->route(
                (int) $invoice['supplier_id'],
                (int) ($user['id'] ?? 0),
                $id,
                (string) $body['amount'],
                $body,
                $request->getHeaderLine('Idempotency-Key'),
                'manual',
                EetInvoicePaymentGate::tokenDefaultsToExternal($request->getAttribute(AuthMiddleware::ATTR_API_TOKEN), RequestAuthorization::isBearerAuth($request)),
            );
            if ($route['mode'] === 'eet') {
                $eet = (array) $route['result'];
                $result = $this->eetOutcome($invoice, $eet);
                $paidOn = (string) ($eet['paid_on'] ?? $paidOn);
            } else {
                $result = $this->payments->recordPayment($id, $amount, $paidOn, [
                    'variable_symbol' => $body['variable_symbol'] ?? null,
                    'bank_reference'  => $body['bank_reference'] ?? null,
                    'note'            => $body['note'] ?? null,
                    'source'          => 'manual',
                    'created_by'      => (int) ($user['id'] ?? 0),
                ]);
            }
        } catch (IdempotencyConflictException $e) {
            return Json::error($response, 'idempotency_conflict', $e->getMessage(), 409);
        } catch (EetCommandException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->extra);
        } catch (\PDOException $e) {
            // Chyba databáze není odmítnutá platba: 409 invalid_payment by klient API
            // bral jako neplatná data, i kdyby šlo o souběh, po kterém má zkusit znovu.
            throw $e;
        } catch (\RuntimeException $e) {
            return Json::error($response, 'invalid_payment', $e->getMessage(), 409);
        }

        $replayed = $eet !== null && !empty($eet['idempotent_replay']);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        if ($auditLockedPayment && !$replayed) {
            $this->logger->log(
                'invoice.payment_on_locked_document',
                isset($user['id']) ? (int) $user['id'] : null,
                'invoice',
                $id,
                ['trigger' => 'payment', 'reasons' => $clientLock->reasons()],
                $ip,
                $request->getHeaderLine('User-Agent'),
            );
        }
        if (!$replayed) {
            $this->logger->log('invoice.payment_added', $user['id'] ?? null, 'invoice', $id, [
                'payment_id' => $result['payment_id'],
                'amount'     => round($amount, 2),
                'paid_on'    => $paidOn,
                'remaining'  => $result['remaining'],
            ] + ($eet !== null ? ['payment_event_id' => $eet['payment_event_id'] ?? null, 'eet_record_id' => $eet['eet']['record_id'] ?? null] : []),
                $ip, $request->getHeaderLine('User-Agent'));
        }

        // Děkovný e-mail dává smysl až po doplacení — u částečné úhrady se neposílá.
        $thanks = null;
        if ($result['became_paid'] && !empty($body['send_payment_thanks'])) {
            $thanks = $this->paymentThanks->sendForInvoice(
                $id,
                'manual',
                $user['id'] ?? null,
                $ip,
                $request->getHeaderLine('User-Agent'),
                requireUnsent: false,
            );
        }

        $fresh = $this->repo->find($id);
        $payload = [
            'invoice'        => $fresh,
            'payments'       => $this->payments->listFor($id),
            'payment'        => $result['payment_id'] !== null ? $this->payments->findPayment($result['payment_id']) : null,
            'became_paid'    => $result['became_paid'],
            'remaining'      => $result['remaining'],
            'payment_thanks' => $thanks,
        ];
        if ($eet !== null) {
            $payload['eet'] = $eet;
            // Uplatnění poukazu nad zůstatek prodaných poukazů: platba proběhla, jen se upozorní.
            if (!empty($eet['warnings'])) {
                $payload['warnings'] = $eet['warnings'];
            }
        } elseif ($route['decision'] !== null) {
            $payload['eet_decision'] = $route['decision'];
        }
        return Json::ok($response, $payload);
    }

    /**
     * Výsledek platebního příkazu EET v tvaru odpovědi ruční úhrady.
     *
     * @param array<string,mixed> $before faktura před platbou
     * @param array<string,mixed> $eet
     * @return array{payment_id:?int, became_paid:bool, remaining:float}
     */
    private function eetOutcome(array $before, array $eet): array
    {
        $paymentId = null;
        foreach ((array) ($eet['allocations'] ?? []) as $allocation) {
            if (($allocation['invoice_payment_id'] ?? null) !== null) {
                $paymentId = (int) $allocation['invoice_payment_id'];
                break;
            }
        }
        $after = $this->repo->find((int) $before['id']) ?? $before;
        return [
            'payment_id'  => $paymentId,
            'became_paid' => (string) $before['status'] !== 'paid' && (string) $after['status'] === 'paid',
            'remaining'   => round((float) ($after['amount_to_pay'] ?? 0) - (float) ($after['paid_total'] ?? 0), 2),
        ];
    }
}
