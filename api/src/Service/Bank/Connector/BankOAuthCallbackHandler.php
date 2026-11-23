<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\OAuth\OAuthCallback;
use MyInvoice\Service\OAuth\OAuthCallbackHandler;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Návrat z banky na společný OAuth callback (doména bank). Připojení dokončí služba
 * poskytovatele; prohlížeč se vrátí na Banku stejně jako dřív z callbacku banky.
 * Návrat po podpisu dávky (poskytovatel {@see BankPaymentSignatureService::OAUTH_PROVIDER})
 * vrací na platební příkaz nebo mzdovou dávku.
 */
final class BankOAuthCallbackHandler implements OAuthCallbackHandler
{
    public const DOMAIN = 'bank';

    public function __construct(
        private readonly CsasOnboardingService $csas,
        private readonly KbPlusOnboardingService $kbPlus,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly BankPaymentSignatureService $signatures,
    ) {}

    public function domain(): string
    {
        return self::DOMAIN;
    }

    public function allows(Request $request): bool
    {
        return RequestAuthorization::isSessionAuth($request)
            && RequestAuthorization::allows($request, 'settings.bank_accounts', AccessLevel::WRITE);
    }

    public function complete(OAuthCallback $callback): string
    {
        $provider = (string) $callback->session['provider'];
        try {
            return match ($provider) {
                CsasOnboardingService::PROVIDER => self::csasLocation('connected', $this->csas->completeCallback($callback)),
                KbPlusOnboardingService::PROVIDER => self::kbPlusLocation($this->baseUrl(), 'connected', $this->kbPlus->completeCallback($callback)),
                BankPaymentSignatureService::OAUTH_PROVIDER => $this->signatures->completeCallback($callback),
                default => throw new BankConnectorOperationException('provider_not_supported'),
            };
        } catch (BankConnectorOperationException $e) {
            $this->logger->warning($provider === CsasOnboardingService::PROVIDER ? 'csas_oauth_callback_failed' : 'bank_oauth_callback_failed', [
                'provider' => $provider,
                'reason' => $e->errorCode,
                'authorization_error' => $callback->error ?? 'unspecified',
                'has_code' => $callback->code !== '',
            ]);
            return $this->failureLocation($callback->session, $e->errorCode);
        }
    }

    public function failureLocation(array $session, string $errorCode): string
    {
        return match ($session['provider'] ?? null) {
            CsasOnboardingService::PROVIDER => self::csasLocation('error', (int) ($session['subject_id'] ?? 0), $errorCode),
            KbPlusOnboardingService::PROVIDER => self::kbPlusLocation($this->baseUrl(), 'error', (int) ($session['subject_id'] ?? 0), $errorCode),
            BankPaymentSignatureService::OAUTH_PROVIDER => $this->signatures->failureLocation($session),
            default => '/bank?' . http_build_query(['tab' => 'accounts']),
        };
    }

    /**
     * Návrat na Banku po připojení ČSAS. Při chybě nese účet a kód, aby hláška u účtu
     * mohla říct proč (např. souhlas udělený za jinou firmu).
     */
    public static function csasLocation(string $status, ?int $currencyId = null, ?string $errorCode = null): string
    {
        $query = ['tab' => 'accounts', 'csas' => $status];
        if ($currencyId !== null && $currencyId > 0) {
            $query['currency_id'] = $currencyId;
        }
        if ($errorCode !== null && preg_match('/^[a-z0-9_]{1,80}$/D', $errorCode)) {
            $query['code'] = $errorCode;
        }
        return '/bank?' . http_build_query($query);
    }

    /** Návrat na Banku po připojení KB+ (stejný tvar jako před společnou vrstvou). */
    public static function kbPlusLocation(string $baseUrl, string $status, ?int $currencyId, ?string $errorCode = null): string
    {
        $query = ['tab' => 'accounts', 'kb_plus' => $status];
        if ($currencyId !== null && $currencyId > 0) {
            $query['currency_id'] = $currencyId;
        }
        if ($errorCode !== null && preg_match('/^[a-z0-9_]{1,80}$/D', $errorCode)) {
            $query['code'] = $errorCode;
        }
        return $baseUrl . '/bank?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** Veřejná adresa aplikace pro návrat z KB+; bez platné app.url relativní cesta. */
    public static function appBaseUrl(Config $config): string
    {
        $base = rtrim((string) $config->get('app.url', ''), '/');
        $parts = parse_url($base);
        if (($parts['scheme'] ?? null) !== 'https' || !isset($parts['host'])
            || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')
        ) {
            return '';
        }
        return $base;
    }

    private function baseUrl(): string
    {
        return self::appBaseUrl($this->config);
    }
}
