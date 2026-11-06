<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

final class PasskeyService
{
    private const CEREMONY_TIMEOUT_MS = 120_000;
    private const MAX_CREDENTIAL_ID_BYTES = 1024;

    private ?SerializerInterface $serializer = null;
    private ?AuthenticatorAttestationResponseValidator $attestationValidator = null;
    private ?AuthenticatorAssertionResponseValidator $assertionValidator = null;
    private readonly ?WebAuthnConfig $staticConfig;
    private readonly ?WebAuthnConfigProvider $configProvider;

    public function __construct(WebAuthnConfig|WebAuthnConfigProvider $config)
    {
        $this->staticConfig = $config instanceof WebAuthnConfig ? $config : null;
        $this->configProvider = $config instanceof WebAuthnConfigProvider ? $config : null;
    }

    public function isAvailable(): bool
    {
        return $this->staticConfig !== null || $this->configProvider?->isAvailable() === true;
    }

    public function configurationError(): ?string
    {
        return $this->configProvider?->validationError();
    }

    private function initialize(): void
    {
        if ($this->serializer !== null) {
            return;
        }
        $config = $this->config();
        $attestationManager = AttestationStatementSupportManager::create();
        $attestationManager->add(NoneAttestationStatementSupport::create());
        $this->serializer = (new WebauthnSerializerFactory($attestationManager))->create();

        $ceremonyFactory = new CeremonyStepManagerFactory();
        $ceremonyFactory->setAttestationStatementSupportManager($attestationManager);
        $ceremonyFactory->setAllowedOrigins([$config->origin()]);

        $this->attestationValidator = AuthenticatorAttestationResponseValidator::create(
            $ceremonyFactory->creationCeremony(),
        );
        $this->assertionValidator = AuthenticatorAssertionResponseValidator::create(
            $ceremonyFactory->requestCeremony(),
        );
    }

    /**
     * @param list<CredentialRecord> $existingCredentials
     * @return array<string,mixed>
     */
    public function registrationOptions(
        string $userEmail,
        string $displayName,
        string $userHandle,
        array $existingCredentials,
        string $challenge,
    ): array {
        $this->initialize();
        $config = $this->config();
        if ($userEmail === '' || $displayName === '' || strlen($userHandle) !== 32) {
            throw new \InvalidArgumentException('Neplatná identita pro registraci passkey.');
        }
        self::validateChallenge($challenge);

        $excludeCredentials = array_map(
            static fn (CredentialRecord $record): PublicKeyCredentialDescriptor => $record->getPublicKeyCredentialDescriptor(),
            $existingCredentials,
        );

        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create('MyInvoice.cz', $config->rpId()),
            PublicKeyCredentialUserEntity::create($userEmail, $userHandle, $displayName),
            $challenge,
            [
                PublicKeyCredentialParameters::createPk(-7),
                PublicKeyCredentialParameters::createPk(-257),
            ],
            AuthenticatorSelectionCriteria::create(
                null,
                AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            $excludeCredentials,
            self::CEREMONY_TIMEOUT_MS,
        );

        return $this->serializeOptions($options);
    }

    /**
     * @param list<CredentialRecord> $credentials
     * @return array<string,mixed>
     */
    public function assertionOptions(array $credentials, string $challenge): array
    {
        $this->initialize();
        $config = $this->config();
        if ($credentials === []) {
            throw new \InvalidArgumentException('Pro WebAuthn assertion není dostupná žádná passkey.');
        }
        self::validateChallenge($challenge);

        $allowCredentials = array_map(
            static fn (CredentialRecord $record): PublicKeyCredentialDescriptor => $record->getPublicKeyCredentialDescriptor(),
            $credentials,
        );
        $options = PublicKeyCredentialRequestOptions::create(
            $challenge,
            $config->rpId(),
            $allowCredentials,
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            self::CEREMONY_TIMEOUT_MS,
        );

        return $this->serializeOptions($options);
    }

    /**
     * @return array<string,mixed>
     */
    public function discoverableAssertionOptions(string $challenge): array
    {
        $this->initialize();
        $config = $this->config();
        self::validateChallenge($challenge);

        $options = PublicKeyCredentialRequestOptions::create(
            $challenge,
            $config->rpId(),
            [],
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            self::CEREMONY_TIMEOUT_MS,
        );

        return $this->serializeOptions($options);
    }

    /**
     * @param array<string,mixed> $credentialPayload
     * @param array<string,mixed> $storedOptions
     */
    public function verifyRegistration(array $credentialPayload, array $storedOptions): CredentialRecord
    {
        try {
            $this->initialize();
            $config = $this->config();
            $credential = $this->deserializeCredential($credentialPayload);
            if (!$credential->response instanceof AuthenticatorAttestationResponse) {
                throw new \UnexpectedValueException('Expected an attestation response.');
            }
            self::validateCredentialShape($credential);
            self::rejectCrossOrigin($credential);

            $options = $this->deserializeOptions($storedOptions, PublicKeyCredentialCreationOptions::class);
            return $this->attestationValidator->check(
                $credential->response,
                $options,
                $config->rpId(),
            );
        } catch (\Throwable) {
            throw new PasskeyVerificationException('Registraci passkey se nepodařilo ověřit.');
        }
    }

    /**
     * @param array<string,mixed> $credentialPayload
     * @param array<string,mixed> $storedOptions
     */
    public function verifyAssertion(
        array $credentialPayload,
        array $storedOptions,
        CredentialRecord $storedCredential,
    ): CredentialRecord {
        return $this->verifyAssertionMode(
            $credentialPayload,
            $storedOptions,
            $storedCredential,
            false,
        );
    }

    /**
     * @param array<string,mixed> $credentialPayload
     * @param array<string,mixed> $storedOptions
     */
    public function verifyDiscoverableAssertion(
        array $credentialPayload,
        array $storedOptions,
        CredentialRecord $storedCredential,
    ): CredentialRecord {
        return $this->verifyAssertionMode(
            $credentialPayload,
            $storedOptions,
            $storedCredential,
            true,
        );
    }

    /**
     * @param array<string,mixed> $credentialPayload
     * @param array<string,mixed> $storedOptions
     */
    private function verifyAssertionMode(
        array $credentialPayload,
        array $storedOptions,
        CredentialRecord $storedCredential,
        bool $discoverable,
    ): CredentialRecord {
        $stage = PasskeyVerificationException::REASON_MALFORMED;
        try {
            $this->initialize();
            $config = $this->config();
            $credential = $this->deserializeCredential($credentialPayload);
            if (!$credential->response instanceof AuthenticatorAssertionResponse) {
                throw new \UnexpectedValueException('Expected an assertion response.');
            }
            self::validateCredentialShape($credential);
            self::rejectCrossOrigin($credential);
            if (!hash_equals($storedCredential->publicKeyCredentialId, $credential->rawId)) {
                throw new PasskeyVerificationException(
                    reason: PasskeyVerificationException::REASON_CREDENTIAL_MISMATCH,
                );
            }
            $responseUserHandle = $credential->response->userHandle;
            if ($discoverable
                && ($responseUserHandle === null
                    || $responseUserHandle === ''
                    || !hash_equals($storedCredential->userHandle, $responseUserHandle))
            ) {
                throw new PasskeyVerificationException(
                    reason: PasskeyVerificationException::REASON_USER_HANDLE_MISMATCH,
                    detail: 'discoverable',
                );
            }
            if (!$discoverable
                && $responseUserHandle !== null
                && !hash_equals($storedCredential->userHandle, $responseUserHandle)
            ) {
                throw new PasskeyVerificationException(
                    reason: PasskeyVerificationException::REASON_USER_HANDLE_MISMATCH,
                );
            }

            $options = $this->deserializeOptions($storedOptions, PublicKeyCredentialRequestOptions::class);
            // Knihovna ověřovaný CredentialRecord MUTUJE na místě — přepíše mu
            // counter, backup bity i uvInitialized. Kdyby dostala přímo uložený
            // záznam, měl by volající po návratu už NOVÝ counter i tam, kde drží
            // obraz DB řádku, a optimistický zámek `WHERE sign_count = ?` by
            // porovnával nový counter sám se sebou. UPDATE pak nesedne na žádný
            // řádek a přihlášení skončí jako counter anomálie u každého
            // autentikátoru, který counter reálně zvyšuje (#4).
            //
            // Kontrolu proti klonované credential to nijak neoslabuje: tu dělá
            // CheckCounter nad hodnotou uloženou v záznamu, a ta je tady pořád
            // ta z databáze. Podle specifikace (ověření assertion, krok se
            // signature counterem) se kontrola přeskakuje jen tehdy, když je
            // přijatý i uložený counter nula — autentikátor ho pak nepodporuje.
            $verified = clone $storedCredential;
            $stage = PasskeyVerificationException::REASON_ASSERTION_INVALID;
            return $this->assertionValidator->check(
                $verified,
                $credential->response,
                $options,
                $config->rpId(),
                $discoverable ? null : $verified->userHandle,
            );
        } catch (PasskeyVerificationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Hláška validátoru (např. „Invalid origin", „User authentication required")
            // je jediné vodítko, proč konkrétní správce klíčů neprošel. Jde jen do
            // auditního logu, klient dostane obecnou chybu. U nerozparsovaného
            // payloadu jen třída výjimky: chyba deserializace může citovat vstup.
            // Předchozí výjimku schválně nepřikládáme, aby se materiál credential
            // nedostal do trasování.
            throw new PasskeyVerificationException(
                reason: $stage,
                detail: $stage === PasskeyVerificationException::REASON_MALFORMED
                    ? (new \ReflectionClass($e))->getShortName()
                    : $e->getMessage(),
            );
        }
    }

    /**
     * @param array<string,mixed> $credentialPayload
     */
    public function credentialId(array $credentialPayload): string
    {
        try {
            $encoded = $credentialPayload['rawId'] ?? null;
            if (!is_string($encoded)
                || $encoded === ''
                || preg_match('/^[A-Za-z0-9_-]+$/D', $encoded) !== 1
            ) {
                throw new \UnexpectedValueException('Missing rawId.');
            }
            $padding = (4 - strlen($encoded) % 4) % 4;
            $decoded = base64_decode(
                strtr($encoded . str_repeat('=', $padding), '-_', '+/'),
                true,
            );
            if ($decoded === false || strlen($decoded) < 1 || strlen($decoded) > self::MAX_CREDENTIAL_ID_BYTES) {
                throw new \UnexpectedValueException('Invalid rawId.');
            }
            return $decoded;
        } catch (\Throwable) {
            throw new PasskeyVerificationException(reason: PasskeyVerificationException::REASON_MALFORMED, detail: 'rawId');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function serializeOptions(
        PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options,
    ): array {
        $data = json_decode(
            $this->serializer->serialize($options, 'json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        if (!is_array($data)) {
            throw new \UnexpectedValueException('WebAuthn knihovna vrátila neplatné options.');
        }
        return $data;
    }

    private function config(): WebAuthnConfig
    {
        return $this->staticConfig ?? $this->configProvider?->get()
            ?? throw new \LogicException('WebAuthn konfigurace není dostupná.');
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function deserializeCredential(array $payload): PublicKeyCredential
    {
        $credential = $this->serializer->deserialize(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            PublicKeyCredential::class,
            'json',
        );
        return $credential;
    }

    /**
     * @template T of PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions
     * @param array<string,mixed> $options
     * @param class-string<T> $type
     * @return T
     */
    private function deserializeOptions(array $options, string $type): PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions
    {
        $result = $this->serializer->deserialize(
            json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $type,
            'json',
        );
        if (!$result instanceof $type) {
            throw new \UnexpectedValueException('Neplatné uložené WebAuthn options.');
        }
        return $result;
    }

    private static function validateChallenge(string $challenge): void
    {
        if (strlen($challenge) < 16 || strlen($challenge) > 64) {
            throw new \InvalidArgumentException('WebAuthn challenge musí mít 16 až 64 bajtů.');
        }
    }

    private static function validateCredentialShape(PublicKeyCredential $credential): void
    {
        $length = strlen($credential->rawId);
        if ($credential->type !== PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY
            || $length < 1
            || $length > self::MAX_CREDENTIAL_ID_BYTES
        ) {
            throw new \UnexpectedValueException('Neplatný tvar WebAuthn credential.');
        }
    }

    private static function rejectCrossOrigin(PublicKeyCredential $credential): void
    {
        if ($credential->response->clientDataJSON->crossOrigin === true
            || $credential->response->clientDataJSON->topOrigin !== null
        ) {
            throw new PasskeyVerificationException(
                'Cross-origin WebAuthn ceremony není povolená.',
                PasskeyVerificationException::REASON_CROSS_ORIGIN,
            );
        }
    }
}
