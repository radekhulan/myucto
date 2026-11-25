<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\MyeetCustomerProvisioner;
use MyInvoice\Service\Auth\Oidc\ExternalIdentity;
use MyInvoice\Service\Auth\Oidc\OidcProviderRegistry;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MyeetCheckoutActivationAction
{
    public function __construct(
        private readonly Config $config,
        private readonly MyeetCustomerProvisioner $provisioner,
        private readonly OidcProviderRegistry $providers,
    ) {}

    public function preflight(Request $request, Response $response): Response
    {
        if (!$this->authorized($request)) {
            return $this->unavailable($response);
        }
        $body = (array) $request->getParsedBody();
        if (!$this->modeAvailable($body)) {
            return $this->unavailable($response);
        }
        try {
            [$email, $ico, $identity] = $this->customer($body, $body['ico'] ?? null);
        } catch (\InvalidArgumentException) {
            return $this->invalid($response);
        }

        if (!$this->provisioner->canRegister($email, $ico, $identity, ($body['sale_managed'] ?? false) === true)) {
            return $this->conflict($response);
        }
        return Json::ok($response, ['data' => ['ready' => true]]);
    }

    public function activate(Request $request, Response $response): Response
    {
        if (!$this->authorized($request)) {
            return $this->unavailable($response);
        }
        $body = (array) $request->getParsedBody();
        if (!$this->modeAvailable($body)) {
            return $this->unavailable($response);
        }
        $company = is_array($body['company'] ?? null) ? $body['company'] : [];
        try {
            [$email, , $identity] = $this->customer($body, $company['ico'] ?? null);
            foreach (['name' => 190, 'street' => 190, 'city' => 100, 'zip' => 5] as $key => $limit) {
                if (!is_string($company[$key] ?? null) || trim($company[$key]) === '' || mb_strlen($company[$key]) > $limit) {
                    throw new \InvalidArgumentException();
                }
            }
            if (!is_string($company['dic'] ?? null) || strlen($company['dic']) > 40
                || preg_match('/^[0-9]{5}$/D', $company['zip']) !== 1 || ($company['country'] ?? null) !== 'CZ'
                || !is_string($body['order_id'] ?? null) || preg_match('/^ME[0-9a-f]{24}$/D', $body['order_id']) !== 1
                || $request->getHeaderLine('Idempotency-Key') !== $body['order_id']
                || !is_string($body['payment_id'] ?? null) || preg_match('/^[1-9][0-9]{0,19}$/D', $body['payment_id']) !== 1
                || !is_int($body['amount_minor'] ?? null) || $body['amount_minor'] <= 0 || ($body['currency'] ?? null) !== 'CZK'
                || !is_string($body['paid_at'] ?? null) || !is_string($body['product'] ?? null)
                || trim($body['product']) === '' || mb_strlen($body['product']) > 160) {
                throw new \InvalidArgumentException();
            }
            $date = \DateTimeImmutable::createFromFormat(DATE_ATOM, $body['paid_at']);
            if ($date === false || $date->format(DATE_ATOM) !== $body['paid_at'] || $date->getTimestamp() > time() + 60) {
                throw new \InvalidArgumentException();
            }

            $order = array_intersect_key($body, array_flip(['order_id', 'payment_id', 'amount_minor', 'currency', 'sandbox', 'paid_at', 'product', 'identity', 'company']));
            $order['email'] = $email;
            $saleManaged = ($body['sale_managed'] ?? false) === true;
            $activation = $this->provisioner->provision($order, $identity, $saleManaged);
        } catch (\InvalidArgumentException) {
            return $this->invalid($response);
        }
        if ($activation === null) {
            return $this->conflict($response);
        }
        $invoiceId = (int) $activation['invoice_id'];
        if ($invoiceId <= 0 && !$saleManaged) {
            $invoiceId = $this->bill($order);
            if ($invoiceId <= 0) {
                return Json::error($response, 'myeet_billing_pending', 'Fakturace zatím objednávku nedokončila.', 503);
            }
            $this->provisioner->recordInvoice($order['order_id'], $invoiceId);
        }
        return Json::ok($response, ['data' => [
            'user_id' => (int) $activation['user_id'],
            'supplier_id' => (int) $activation['supplier_id'],
            'invoice_id' => $invoiceId ?: null,
            'sale_managed' => (bool) $activation['sale_managed'],
        ]]);
    }

    public function license(Request $request, Response $response): Response
    {
        if (!$this->authorized($request, licenseSync: true) || $this->config->get('myeet.sale_managed', false) !== true) {
            return $this->unavailable($response);
        }
        $body = (array) $request->getParsedBody();
        if (($body['sandbox'] ?? null) !== $this->config->get('myeet.sandbox', true)
            || !is_string($body['order_id'] ?? null) || preg_match('/^ME[0-9a-f]{24}$/D', $body['order_id']) !== 1
            || !is_int($body['user_id'] ?? null) || $body['user_id'] <= 0
            || !is_int($body['supplier_id'] ?? null) || $body['supplier_id'] <= 0
            || !is_int($body['valid_until'] ?? null) || $body['valid_until'] < 0
            || !is_int($body['version'] ?? null) || $body['version'] <= 0) {
            return $this->invalid($response);
        }
        if (!$this->provisioner->recordLicense($body['order_id'], $body['user_id'], $body['supplier_id'], $body['valid_until'], $body['version'])) {
            return $this->conflict($response);
        }
        return Json::ok($response, ['data' => ['saved' => true]]);
    }

    private function customer(array $body, mixed $ico): array
    {
        $email = is_string($body['email'] ?? null) ? strtolower(trim($body['email'])) : '';
        if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || !is_string($ico) || preg_match('/^[0-9]{8}$/D', $ico) !== 1
            || ($body['sandbox'] ?? null) !== $this->config->get('myeet.sandbox', true)) {
            throw new \InvalidArgumentException();
        }

        $claims = $body['identity'] ?? null;
        if ($claims === null) {
            return [$email, $ico, null];
        }
        if (!is_array($claims) || !in_array($claims['provider'] ?? null, ['google', 'apple'], true)) {
            throw new \InvalidArgumentException();
        }

        $provider = $this->providers->get($claims['provider']);
        if ($provider === null || ($claims['issuer'] ?? null) !== $provider->issuer
            || !is_string($claims['subject'] ?? null) || preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $claims['subject']) !== 1
            || !is_string($claims['email'] ?? null) || strtolower($claims['email']) !== $email) {
            throw new \InvalidArgumentException();
        }
        $name = is_string($claims['name'] ?? null) ? mb_substr(trim($claims['name']), 0, 190) : null;
        return [$email, $ico, new ExternalIdentity($provider->id, $provider->issuer, null, $claims['subject'], $email, $name)];
    }

    private function authorized(Request $request, bool $licenseSync = false): bool
    {
        $token = (string) $this->config->get($licenseSync ? 'myeet.license_sync_token' : 'myeet.checkout_token', '');
        $sandbox = $this->config->get('myeet.sandbox', true);
        $host = strtolower((string) parse_url((string) $this->config->get('app.url', ''), PHP_URL_HOST));
        return is_bool($sandbox) && $host !== '' && $request->getUri()->getHost() === $host
            && ($sandbox ? $host !== 'eet.myucto.online' : $host === 'eet.myucto.online')
            && strlen($token) >= 32
            && hash_equals($token, $request->getHeaderLine($licenseSync ? 'X-Myeet-License-Token' : 'X-Myeet-Checkout-Token'));
    }

    private function modeAvailable(array $body): bool
    {
        return match ($body['sale_managed'] ?? false) {
            true => $this->config->get('myeet.sale_managed', false) === true
                && strlen((string) $this->config->get('myeet.license_sync_token', '')) >= 32,
            false => strlen((string) $this->config->get('myeet.billing_token', '')) >= 32
                && preg_match('~^https://[a-z0-9][a-z0-9.-]+(?::[0-9]{1,5})?/ajax/myeet-invoice\.php$~Di',
                    (string) $this->config->get('myeet.billing_url', '')) === 1,
            default => false,
        };
    }

    private function bill(array $order): int
    {
        unset($order['identity']);
        $curl = curl_init((string) $this->config->get('myeet.billing_url'));
        curl_setopt_array($curl, [
            CURLOPT_POSTFIELDS => json_encode($order, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json', 'Content-Type: application/json',
                'Authorization: Bearer ' . $this->config->get('myeet.billing_token'),
            ],
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_PROTOCOLS_STR => 'https',
            CURLOPT_RETURNTRANSFER => true,
        ]);
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            curl_setopt($curl, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }
        $json = curl_exec($curl);
        if (!is_string($json) || (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) {
            return 0;
        }
        $body = json_decode($json, true);
        return (int) ($body['invoice_id'] ?? 0);
    }

    private function unavailable(Response $response): Response
    {
        return Json::error($response, 'myeet_unavailable', 'Aktivace MyEET není dostupná.', 404);
    }

    private function invalid(Response $response): Response
    {
        return Json::error($response, 'myeet_invalid', 'Údaje objednávky nejsou platné.', 422);
    }

    private function conflict(Response $response): Response
    {
        return Json::error($response, 'myeet_ownership_conflict', 'Oprávnění k účtu nebo firmě vyžaduje ověření.', 409);
    }
}
