<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use MyInvoice\Repository\BankPaymentOrderSubmissionRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\OAuth\OAuthCallback;
use MyInvoice\Service\OAuth\OAuthClientFlow;
use MyInvoice\Service\OAuth\OAuthException;

/**
 * Podpis dávky přesměrováním do banky a zjišťování výsledku dotazem (banka nevolá zpět).
 *
 * Návrat z banky jde na společný callback ({@see OAuthClientFlow::CALLBACK_PATH}) s
 * jednorázovým state; rozpracovaný podpis se eviduje jako přihlášení domény bank
 * s poskytovatelem {@see OAUTH_PROVIDER} a subjektem = id odeslání. Výsledek podpisu
 * se zjišťuje při návratu, na vyžádání z UI a cronem; nic z toho banku znovu nežádá
 * o založení plateb.
 *
 * Archivovaný příkaz (zrušení potvrzené uživatelem) a zahozená mzdová dávka se už
 * nepodepisují ani nedotazují: vedle náhradního příkazu by podpis znamenal druhou
 * platbu. API ČS zrušení založených plateb nenabízí, ruší je uživatel v George.
 */
final class BankPaymentSignatureService
{
    public const OAUTH_PROVIDER = 'csas_sign';
    public const WITHDRAWN = 'payment_order_no_longer_payable';
    private const SIGN_TTL_SECONDS = 1800;
    /** Nejkratší odstup dvou dotazů na stav téže dávky (opakované klikání v UI). */
    private const MIN_REFRESH_SECONDS = 5;
    /** Dotazy při probíhajícím podpisu: první hodinu po minutě, potom šetrněji. */
    private const POLL_SIGNING_SECONDS = 60;
    private const POLL_IDLE_SECONDS = 15 * 60;
    /** Dávka bez zahájeného podpisu v aplikaci: první dvě hodiny po 15 minutách, potom po 6 hodinách. */
    private const POLL_UNSIGNED_LATE_SECONDS = 6 * 3600;
    private const POLL_RETRY_SECONDS = 30 * 60;
    /** Podepsaná dávka: před splatností dvakrát denně, kolem splatnosti po 2 hodinách, potom denně. */
    private const POLL_BEFORE_EXECUTION_SECONDS = 12 * 3600;
    private const POLL_AROUND_EXECUTION_SECONDS = 2 * 3600;
    private const POLL_LATE_SECONDS = 24 * 3600;
    private const POLL_CONTINUE_SECONDS = 15 * 60;
    /** Nejvýš tolik volání banky na jedno ověření z UI; cron má vlastní rozpočet na běh. */
    private const REFRESH_CALL_BUDGET = 50;
    public const CRON_CALL_BUDGET = 200;
    /** Založená a nepodepsaná platba v bance propadá po 30 dnech. */
    private const UNSIGNED_LIFETIME_DAYS = 30;
    /** Stav platby banka drží 34 dní; potom se už nedotazujeme. */
    private const STATUS_RETENTION_DAYS = 34;

    private int $callBudget = 0;

    public function __construct(
        private readonly BankPaymentOrderSubmissionRepository $submissions,
        private readonly BankPaymentSubmissionService $engine,
        private readonly BankConnectorCallGuard $calls,
        private readonly OAuthClientFlow $flow,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Vydá adresu podpisu dávky v bance. Adresa má krátkou platnost a neukládá se;
     * nové vydání podepisuje stejnou dávku (stejné signId a hash), nikdy nové platby.
     *
     * @return array{signing_url:string,submission:array<string,mixed>}
     */
    public function startSignature(int $supplierId, int $orderId, string $source, int $userId): array
    {
        if ($userId < 1) throw new BankConnectorOperationException('forbidden');
        $state = $this->state($supplierId, $orderId, $source);
        $this->assertNotWithdrawn($supplierId, $state);
        if ($state['status'] !== 'awaiting_signature' || !is_string($state['provider_sign_hash'])
            || !is_string($state['provider_reference']) || $state['provider_reference'] === '') {
            throw new BankConnectorOperationException('payment_signature_unavailable');
        }
        if (self::olderThan($state['age_seconds'], self::UNSIGNED_LIFETIME_DAYS * 86400)) {
            $this->submissions->updateSigning($supplierId, (int) $state['id'], ['awaiting_signature'],
                ['status' => 'expired', 'error_code' => 'payment_signature_expired', 'next_poll_seconds' => null, 'polled' => true]);
            throw new BankConnectorOperationException('payment_signature_expired');
        }
        $access = $this->engine->signingAccess($supplierId, (int) $state['connection_id']);
        return $this->calls->withConnectionLock($supplierId, (int) $access['connection']['currency_id'],
            function () use ($supplierId, $orderId, $source, $userId, $access): array {
                // Pod zámkem znovu: archivace nebo zahození mohly proběhnout mezitím.
                $state = $this->state($supplierId, $orderId, $source);
                $this->assertNotWithdrawn($supplierId, $state);
                if ($state['status'] !== 'awaiting_signature') throw new BankConnectorOperationException('payment_signature_unavailable');
                try {
                    $authorization = $this->flow->authorize(false);
                    $callback = $this->flow->callbackUrl() . '?' . http_build_query(['state' => $authorization->state]);
                } catch (OAuthException) {
                    throw new BankConnectorOperationException('app_url_invalid');
                }
                try {
                    $signature = $access['connector']->startSignature($access['token'], (string) $state['provider_reference'],
                        (string) $state['provider_sign_hash'], $callback);
                } catch (BankConnectorException $e) {
                    throw new BankConnectorOperationException($e->errorCode === BankConnectorException::RATE_LIMITED
                        ? 'bank_rate_limited' : 'payment_signature_failed');
                }
                try {
                    $this->flow->store($authorization, $supplierId, $userId, BankOAuthCallbackHandler::DOMAIN, self::OAUTH_PROVIDER,
                        (int) $state['id'], OAuthClientFlow::FLOW_REDIRECT, ['source' => $source, 'order_id' => $orderId],
                        self::SIGN_TTL_SECONDS);
                } catch (OAuthException) {
                    throw new BankConnectorOperationException('encryption_key_unavailable');
                }
                $this->submissions->updateSigning($supplierId, (int) $state['id'], ['awaiting_signature'], [
                    'provider_poll_id' => $signature['poll_id'], 'signature_state' => 'AUTHORIZATION_NOT_STARTED',
                    'error_code' => null, 'signature_requested' => true, 'next_poll_seconds' => self::POLL_SIGNING_SECONDS,
                ]);
                $this->activity->log('bank.payment_signature_started', $userId,
                    $source === 'payroll' ? 'payroll_payment_batch' : 'payment_order', $orderId,
                    ['submission_id' => (int) $state['id'], 'provider' => (string) $state['provider'], 'connection_id' => (int) $state['connection_id']],
                    supplierId: $supplierId);
                return ['signing_url' => $signature['signing_url'], 'submission' => $this->publicState($supplierId, $orderId, $source)];
            });
    }

    /**
     * Zjistí stav podpisu a plateb u banky. Častější dotaz než jednou za pár sekund se
     * nepošle a vrátí se uložený stav.
     *
     * @return array<string,mixed> veřejný stav odeslání
     */
    public function refresh(int $supplierId, int $orderId, string $source): array
    {
        $state = $this->state($supplierId, $orderId, $source);
        $this->assertNotWithdrawn($supplierId, $state);
        if (in_array($state['status'], ['awaiting_signature', 'signed'], true)
            && !self::recent($state['polled_seconds_ago'], self::MIN_REFRESH_SECONDS)) {
            $this->callBudget = self::REFRESH_CALL_BUDGET;
            $this->poll($supplierId, (int) $state['id']);
        }
        return $this->publicState($supplierId, $orderId, $source);
    }

    /**
     * Položky odeslání ke kontrole uživatelem (nejasný nebo přerušený stav).
     *
     * @return list<array<string,mixed>>
     */
    public function items(int $supplierId, int $orderId, string $source): array
    {
        $public = $this->submissions->find($supplierId, $orderId, $source);
        if ($public === null) throw new BankConnectorOperationException('payment_order_not_found');
        return $this->submissions->publicItems($supplierId, (int) $public['id']);
    }

    /** Návrat z banky po podpisu. Vrací adresu, kam přesměrovat prohlížeč. */
    public function completeCallback(OAuthCallback $callback): string
    {
        $supplierId = $callback->supplierId();
        $session = $this->flow->claim($callback->state, $supplierId, $callback->userId(), BankOAuthCallbackHandler::DOMAIN, self::OAUTH_PROVIDER);
        if ($session === null) return $this->failureLocation($callback->session);
        $source = $session->secret['source'] ?? null;
        $orderId = $session->secret['order_id'] ?? null;
        if (!in_array($source, ['purchase_invoice', 'payroll'], true) || !is_int($orderId) || $orderId < 1) {
            $this->flow->finish($session, false, 'invalid_state');
            return $this->failureLocation($callback->session);
        }
        if (!RequestAuthorization::allows($callback->request, $source === 'payroll' ? 'payroll.payments' : 'purchase_invoices.payment_orders', AccessLevel::WRITE)) {
            $this->flow->finish($session, false, 'forbidden');
            return self::location($source, $orderId, 'error');
        }
        $this->flow->finish($session, true);
        try {
            $state = $this->state($supplierId, $orderId, $source);
            if ((int) $state['id'] !== $session->subjectId) return self::location($source, $orderId, 'error');
            $this->callBudget = self::REFRESH_CALL_BUDGET;
            $this->poll($supplierId, (int) $state['id']);
        } catch (BankConnectorOperationException) {
            // Stav se dozví další dotaz (UI nebo cron); návrat do aplikace tím nesmí selhat.
        }
        return self::location($source, $orderId, 'returned');
    }

    /**
     * Kam vrátit prohlížeč po nepovedeném návratu z podpisu: podle typu odeslání (mzdy,
     * platební příkazy), které se pozná z rozpracovaného podpisu (subjekt = id odeslání).
     *
     * @param array<string,mixed> $session údaje rozpracovaného podpisu bez tajemství
     */
    public function failureLocation(array $session): string
    {
        $supplierId = (int) ($session['supplier_id'] ?? 0);
        $submissionId = (int) ($session['subject_id'] ?? 0);
        $state = $supplierId > 0 && $submissionId > 0 ? $this->submissions->signingState($supplierId, $submissionId) : null;
        if ($state !== null && $state['payroll_batch_id'] !== null) return self::location('payroll', (int) $state['payroll_batch_id'], 'error');
        return self::location('purchase_invoice', $state !== null ? $state['payment_order_id'] : null, 'error');
    }

    /**
     * Cron: dotazy na dávky, kterým nastal čas. Chyba jedné dávky nezastaví ostatní a
     * další pokus se odloží. Počet volání banky za běh hlídá rozpočet; dávka, na kterou
     * nezbylo, přijde na řadu v dalším běhu.
     *
     * @return array{polled:int,errors:int,calls:int}
     */
    public function pollDue(array $providers, int $limit = 20, int $callBudget = self::CRON_CALL_BUDGET): array
    {
        $polled = 0;
        $errors = 0;
        $this->callBudget = $callBudget;
        foreach ($providers as $provider) {
            foreach ($this->submissions->dueForPolling($provider, $limit) as $row) {
                if ($this->callBudget <= 0) break 2;
                try {
                    $this->poll($row['supplier_id'], $row['id']);
                    $polled++;
                } catch (\Throwable) {
                    $errors++;
                    try {
                        $this->submissions->updateSigning($row['supplier_id'], $row['id'], ['awaiting_signature', 'signed'],
                            ['next_poll_seconds' => self::POLL_RETRY_SECONDS, 'polled' => true]);
                    } catch (\Throwable) {
                    }
                }
            }
        }
        return ['polled' => $polled, 'errors' => $errors, 'calls' => $callBudget - max(0, $this->callBudget)];
    }

    private function poll(int $supplierId, int $submissionId): void
    {
        $state = $this->submissions->signingState($supplierId, $submissionId);
        if ($state === null || !in_array($state['status'], ['awaiting_signature', 'signed'], true)) return;
        if ($state['withdrawn']) {
            $this->stopWithdrawn($supplierId, $submissionId);
            return;
        }
        if (self::olderThan($state['age_seconds'], self::STATUS_RETENTION_DAYS * 86400)) {
            $this->submissions->updateSigning($supplierId, $submissionId, ['awaiting_signature', 'signed'], $state['status'] === 'awaiting_signature'
                ? ['status' => 'expired', 'error_code' => 'payment_signature_expired', 'next_poll_seconds' => null, 'polled' => true]
                : ['next_poll_seconds' => null, 'polled' => true]);
            return;
        }
        $access = $this->engine->signingAccess($supplierId, (int) $state['connection_id']);
        try {
            $this->calls->withConnectionLock($supplierId, (int) $access['connection']['currency_id'],
                function () use ($supplierId, $submissionId, $access): void {
                    $state = $this->submissions->signingState($supplierId, $submissionId);
                    if ($state === null) return;
                    if ($state['withdrawn']) {
                        $this->stopWithdrawn($supplierId, $submissionId);
                        return;
                    }
                    if ($state['status'] === 'awaiting_signature') {
                        $this->pollSignature($supplierId, $submissionId, $state, $access);
                        $state = $this->submissions->signingState($supplierId, $submissionId);
                    }
                    if ($state !== null && $state['status'] === 'signed') {
                        $this->pollPayments($supplierId, $submissionId, $state, $access);
                    }
                });
        } catch (BankConnectorException $e) {
            // Dotaz na stav nic nezakládá; chyba banky jen odloží další dotaz.
            $this->submissions->updateSigning($supplierId, $submissionId, ['awaiting_signature', 'signed'],
                ['next_poll_seconds' => self::POLL_RETRY_SECONDS, 'polled' => true]);
            throw new BankConnectorOperationException($e->errorCode === BankConnectorException::RATE_LIMITED
                ? 'bank_rate_limited' : 'payment_status_unavailable');
        }
    }

    /** @param array{connector:SignableBankPaymentConnector,token:string} $access */
    private function pollSignature(int $supplierId, int $submissionId, array $state, array $access): void
    {
        if (self::olderThan($state['age_seconds'], self::UNSIGNED_LIFETIME_DAYS * 86400)) {
            $this->submissions->updateSigning($supplierId, $submissionId, ['awaiting_signature'],
                ['status' => 'expired', 'error_code' => 'payment_signature_expired', 'next_poll_seconds' => null, 'polled' => true]);
            return;
        }
        $pollId = $state['provider_poll_id'];
        if (!is_string($pollId) || $pollId === '') {
            // Podpis mimo aplikaci (přímo v bankovnictví) se pozná na stavu první platby.
            $this->detectSignatureFromPayments($supplierId, $submissionId, $state, $access);
            return;
        }
        if (!$this->spend()) {
            $this->submissions->updateSigning($supplierId, $submissionId, ['awaiting_signature'], ['next_poll_seconds' => self::POLL_SIGNING_SECONDS]);
            return;
        }
        $result = $access['connector']->signatureState($access['token'], $pollId);
        $requestedRecently = self::recent($state['signature_requested_seconds_ago'], 3600);
        $changes = match ($result['state']) {
            'DONE' => ['status' => 'signed', 'signature_state' => 'DONE', 'error_code' => null, 'next_poll_seconds' => self::POLL_SIGNING_SECONDS],
            'REJECTED', 'ORDER_PROCESSING_FAILED' => ['status' => 'rejected', 'signature_state' => $result['state'],
                'error_code' => 'payment_signature_rejected', 'next_poll_seconds' => null],
            'EXPIRED' => ['provider_poll_id' => null, 'signature_state' => 'EXPIRED', 'error_code' => 'payment_signature_expired',
                'next_poll_seconds' => self::POLL_IDLE_SECONDS],
            default => ['signature_state' => $result['state'],
                'next_poll_seconds' => $requestedRecently ? max(self::POLL_SIGNING_SECONDS, intdiv($result['poll_interval_ms'], 1000)) : self::POLL_IDLE_SECONDS],
        };
        $this->submissions->updateSigning($supplierId, $submissionId, ['awaiting_signature'], $changes + ['polled' => true]);
    }

    /** @param array{connector:SignableBankPaymentConnector,token:string} $access */
    private function detectSignatureFromPayments(int $supplierId, int $submissionId, array $state, array $access): void
    {
        $first = null;
        foreach ($this->submissions->items($supplierId, $submissionId) as $item) {
            if ($item['status'] === 'created' && is_string($item['provider_sign_id'])) {
                $first = $item;
                break;
            }
        }
        if ($first === null) {
            $this->submissions->updateSigning($supplierId, $submissionId, ['awaiting_signature'], ['next_poll_seconds' => null, 'polled' => true]);
            return;
        }
        if (!$this->spend()) return;
        $payment = $access['connector']->paymentState($access['token'], (string) $first['provider_sign_id']);
        $this->submissions->recordItemStatus($supplierId, $submissionId, $first['line_no'], $payment['instruction_status'], $payment['status_change_info']);
        $idle = self::recent($state['age_seconds'], 2 * 3600) ? self::POLL_IDLE_SECONDS : self::POLL_UNSIGNED_LATE_SECONDS;
        $this->submissions->updateSigning($supplierId, $submissionId, ['awaiting_signature'], $payment['instruction_status'] === 'ACTC'
            ? ['next_poll_seconds' => $idle, 'polled' => true]
            : ['status' => 'signed', 'signature_state' => 'DONE', 'error_code' => null, 'next_poll_seconds' => self::POLL_SIGNING_SECONDS, 'polled' => true]);
    }

    /**
     * Stav jednotlivých plateb podepsané dávky (API ČS nemá stav celé dávky). Dotazuje se
     * jen platba, která ještě nemá konečný stav (ACSC odepsáno, RJCT odmítnuto), a jen
     * v rámci rozpočtu volání. Frekvence se řídí datem splatnosti příkazu.
     *
     * @param array<string,mixed> $state
     * @param array{connector:SignableBankPaymentConnector,token:string} $access
     */
    private function pollPayments(int $supplierId, int $submissionId, array $state, array $access): void
    {
        $items = array_values(array_filter($this->submissions->items($supplierId, $submissionId),
            static fn (array $item): bool => $item['status'] === 'created'));
        $exhausted = false;
        foreach ($items as $index => $item) {
            if (in_array($item['instruction_status'], ['ACSC', 'RJCT'], true) || !is_string($item['provider_sign_id'])) continue;
            if (!$this->spend()) {
                $exhausted = true;
                break;
            }
            $payment = $access['connector']->paymentState($access['token'], $item['provider_sign_id']);
            $this->submissions->recordItemStatus($supplierId, $submissionId, $item['line_no'], $payment['instruction_status'], $payment['status_change_info']);
            $items[$index]['instruction_status'] = $payment['instruction_status'];
        }
        $statuses = array_column($items, 'instruction_status');
        $settled = count(array_filter($statuses, static fn (?string $status): bool => $status === 'ACSC'));
        $rejected = count(array_filter($statuses, static fn (?string $status): bool => $status === 'RJCT'));
        $changes = ['accepted_count' => $settled, 'rejected_count' => $rejected, 'polled' => true];
        if ($items !== [] && $settled + $rejected === count($items)) {
            $changes += match (true) {
                $rejected === 0 => ['status' => 'completed', 'error_code' => null],
                $settled === 0 => ['status' => 'rejected', 'error_code' => 'payment_rejected'],
                default => ['status' => 'partially_rejected', 'error_code' => 'payment_partially_rejected'],
            };
            $changes['next_poll_seconds'] = null;
        } else {
            $changes['next_poll_seconds'] = $exhausted ? self::POLL_CONTINUE_SECONDS : self::signedPollSeconds($state['days_to_execution'] ?? null);
        }
        $this->submissions->updateSigning($supplierId, $submissionId, ['signed'], $changes);
    }

    private static function signedPollSeconds(?int $daysToExecution): int
    {
        return match (true) {
            $daysToExecution !== null && $daysToExecution > 0 => self::POLL_BEFORE_EXECUTION_SECONDS,
            $daysToExecution === null || $daysToExecution >= -3 => self::POLL_AROUND_EXECUTION_SECONDS,
            default => self::POLL_LATE_SECONDS,
        };
    }

    /** Jedno volání banky z rozpočtu; false = rozpočet vyčerpán. */
    private function spend(): bool
    {
        if ($this->callBudget <= 0) return false;
        $this->callBudget--;
        return true;
    }

    /** @param array<string,mixed> $state */
    private function assertNotWithdrawn(int $supplierId, array $state): void
    {
        if (!$state['withdrawn']) return;
        $this->stopWithdrawn($supplierId, (int) $state['id']);
        throw new BankConnectorOperationException(self::WITHDRAWN);
    }

    private function stopWithdrawn(int $supplierId, int $submissionId): void
    {
        $this->submissions->updateSigning($supplierId, $submissionId, ['awaiting_signature', 'signed'],
            ['error_code' => self::WITHDRAWN, 'next_poll_seconds' => null, 'polled' => true]);
    }

    /** @return array<string,mixed> */
    private function state(int $supplierId, int $orderId, string $source): array
    {
        $public = $this->submissions->find($supplierId, $orderId, $source);
        if ($public === null) throw new BankConnectorOperationException('payment_order_not_found');
        $state = $this->submissions->signingState($supplierId, (int) $public['id']);
        if ($state === null) throw new BankConnectorOperationException('payment_order_not_found');
        return $state;
    }

    /** @return array<string,mixed> */
    private function publicState(int $supplierId, int $orderId, string $source): array
    {
        return $this->submissions->find($supplierId, $orderId, $source)
            ?? throw new BankConnectorOperationException('submission_state_unavailable');
    }

    private static function location(string $source, ?int $orderId, string $result): string
    {
        return $source === 'payroll'
            ? '/payroll/payments?' . http_build_query(array_filter(['bank_batch' => $orderId, 'bank_sign' => $result]))
            : '/purchase-invoices/payment-orders?' . http_build_query(array_filter(['bank_order' => $orderId, 'bank_sign' => $result]));
    }

    /** Stáří počítá databáze (TIMESTAMPDIFF), aby nezáleželo na časové zóně PHP. */
    private static function olderThan(?int $ageSeconds, int $seconds): bool
    {
        return $ageSeconds !== null && $ageSeconds > $seconds;
    }

    private static function recent(?int $ageSeconds, int $seconds): bool
    {
        return $ageSeconds !== null && $ageSeconds >= 0 && $ageSeconds < $seconds;
    }
}
