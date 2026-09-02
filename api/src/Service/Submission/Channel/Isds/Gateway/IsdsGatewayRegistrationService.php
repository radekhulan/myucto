<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel\Isds\Gateway;

use MyInvoice\Repository\Submission\IsdsGatewayRegistrationRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Submission\Channel\SensitiveValue;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\SharedCertificateResolver;

/**
 * Trezor registrace odesílací brány.
 *
 * Drží se přesně té cesty, kterou projekt používá pro podpisové certifikáty
 * ({@see \MyInvoice\Service\Epo\EpoSigningCredentialService}) a pro systémový
 * certifikát k datové schránce ({@see \MyInvoice\Service\Submission\SubmissionCredentialService}):
 *   1. brána na klíč — bez `cfg.app.secret_encryption_key` se nic neuloží (503),
 *   2. {@see SecretEncryption::encryptFor()} s vlastním kontextem na každé pole,
 *   3. API čte z projekce, která ciphertext vůbec nevybírá.
 *
 * ── Co tu ZÁMĚRNĚ není ──────────────────────────────────────────────────────
 * Žádná cesta, jak certifikát nebo jeho heslo přečíst zpátky v čitelné podobě.
 * `load()` vrací {@see SensitiveValue}, tedy handle do trezoru; kdo chce
 * plaintext, musí zavolat `reveal()` a ten se používá jedině uvnitř
 * {@see SoapIsdsGatewayClient}, těsně před cURL voláním.
 *
 * ── Fail-closed ─────────────────────────────────────────────────────────────
 * Chybějící registrace, vypnutá registrace i nerozšifrovatelný certifikát
 * končí pojmenovanou výjimkou. Nikdy se nepokračuje „naprázdno" — odeslání
 * podání není operace, u které by mělo smysl doufat.
 */
final readonly class IsdsGatewayRegistrationService implements IsdsGatewayRegistrationSource
{
    private const CONTEXT_CERTIFICATE = 'isds:gateway-certificate';
    private const CONTEXT_PASSPHRASE = 'isds:gateway-passphrase';

    private const ENVIRONMENTS = ['production', 'test'];

    /**
     * Výchozí hostitelé podle `odesilaci_brana_ISDS.pdf` v. 1.11, kap. 1.2
     * („[url-adresa-prostředí-isds]"). Jsou to jen předvolby formuláře —
     * závazná je hodnota v registraci, protože staré domény `mojedatovaschranka.cz`
     * podle Provozního řádu poběží souběžně minimálně do 31. 12. 2027.
     */
    public const DEFAULT_HOSTS = [
        'production' => ['portal' => 'datovka.gov.cz', 'service' => 'cert.datovka.gov.cz'],
        'test' => ['portal' => 'datovka-test.gov.cz', 'service' => 'cert.datovka-test.gov.cz'],
    ];

    /**
     * Povolené dvojice z oficiálních specifikací. Hostitelé nejsou obecná
     * konfigurace: na service host posíláme klientský certifikát a jednorázové
     * přihlašovací údaje, proto libovolný hostname znamená credential relay.
     */
    private const ALLOWED_HOST_PAIRS = [
        'production' => [
            ['portal' => 'datovka.gov.cz', 'service' => 'cert.datovka.gov.cz'],
            ['portal' => 'mojedatovaschranka.cz', 'service' => 'cert.mojedatovaschranka.cz'],
        ],
        'test' => [
            ['portal' => 'datovka-test.gov.cz', 'service' => 'cert.datovka-test.gov.cz'],
        ],
    ];

    /**
     * `$sharedCertificates` je volitelný jen kvůli testům, které si službu
     * skládají ručně (PHP-DI nullable parametr s defaultem neautowiruje —
     * binding je v `Bootstrap`). Když chybí a registrace přesto na sdílený
     * trezor odkazuje, {@see load()} skončí pojmenovanou chybou.
     */
    public function __construct(
        private IsdsGatewayRegistrationRepository $repository,
        private SecretEncryption $crypto,
        private ?SharedCertificateResolver $sharedCertificates = null,
    ) {}

    /** @return list<array<string,mixed>> Bez tajných hodnot — bezpečné pro API. */
    public function listPublic(): array
    {
        return $this->repository->listPublic();
    }

    /**
     * Uloží registraci. Certifikát se ihned zašifruje a dál nikam nepokračuje.
     *
     * `is_active` je po uložení VŽDY `false`: registrace se zapíná zvlášť, až
     * když ji provozovatel ověří ve veřejném testu. Kdyby se zapínala uložením,
     * stačil by překlep v `atsId` k tomu, aby uživatelé mířili na cizí bránu.
     *
     * @return array<string,mixed>
     */
    public function save(
        string $environment,
        string $atsId,
        string $label,
        string $returnUrl,
        ?string $errorUrl,
        int $conceptTtlSeconds,
        ?string $portalHost,
        ?string $serviceHost,
        string $loginPolicy,
        string $certificateBytes,
        ?string $certificatePassphrase,
        ?int $userId,
    ): array {
        $this->assertEncryptionReady();
        $this->assertEnvironment($environment);

        $atsId = trim($atsId);
        if (preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $atsId) !== 1) {
            throw new SubmissionChannelException(
                'invalid_ats_id',
                'ID odesílací brány (atsId) nemá platný tvar. Najdete ho v Portálu datových schránek '
                . 'v Nastavení → Externí aplikace → Odesílací brána.',
                400,
            );
        }
        $label = trim($label);
        if ($label === '') {
            throw new SubmissionChannelException('label_required', 'Vyplňte název registrace.', 400);
        }

        $returnUrl = trim($returnUrl);
        if (!str_starts_with($returnUrl, 'https://')) {
            // Přes návratové URL chodí `sessionId`. Po nešifrovaném spojení
            // by ho mohl odposlechnout kdokoliv na cestě a vyměnit ho za
            // `timeLimitedId` dřív než my.
            throw new SubmissionChannelException(
                'invalid_return_url',
                'Návratová adresa musí být na HTTPS — chodí přes ni identifikátor relace.',
                400,
            );
        }
        $errorUrl = ($errorUrl !== null && trim($errorUrl) !== '') ? trim($errorUrl) : null;
        if ($errorUrl !== null && !str_starts_with($errorUrl, 'https://')) {
            throw new SubmissionChannelException(
                'invalid_error_url',
                'Chybová adresa musí být na HTTPS.',
                400,
            );
        }

        if ($conceptTtlSeconds < 60 || $conceptTtlSeconds > 7200) {
            throw new SubmissionChannelException(
                'invalid_concept_ttl',
                'Doba platnosti konceptu musí být mezi 60 a 7200 sekundami — stejnou hodnotu '
                . 'nastavte i v registraci brány v Portálu datových schránek.',
                400,
            );
        }

        $portalHost = $this->normalizeHost($portalHost ?? self::DEFAULT_HOSTS[$environment]['portal']);
        $serviceHost = $this->normalizeHost($serviceHost ?? self::DEFAULT_HOSTS[$environment]['service']);
        $this->assertOfficialHosts($environment, $portalHost, $serviceHost);

        if ($certificateBytes === '') {
            throw new SubmissionChannelException(
                'certificate_required',
                'Nahrajte komerční certifikát odesílací brány (soubor PFX nebo P12). '
                . 'Musí to být týž certifikát, který je vložený v registraci brány v ISDS.',
                400,
            );
        }
        [$fingerprint, $validTo] = $this->inspectCertificate($certificateBytes, $certificatePassphrase);

        $this->repository->save($environment, [
            'ats_id' => $atsId,
            'label' => mb_substr($label, 0, 120),
            'return_url' => mb_substr($returnUrl, 0, 500),
            'error_url' => $errorUrl !== null ? mb_substr($errorUrl, 0, 500) : null,
            'concept_ttl_seconds' => $conceptTtlSeconds,
            'portal_host' => $portalHost,
            'service_host' => $serviceHost,
            'user_login_policy' => IsdsGatewayLoginPolicy::fromDatabase($loginPolicy)->value,
            'certificate_ciphertext' => $this->crypto->encryptFor(
                base64_encode($certificateBytes),
                self::CONTEXT_CERTIFICATE,
            ),
            'certificate_passphrase_ciphertext' => ($certificatePassphrase ?? '') !== ''
                ? $this->crypto->encryptFor((string) $certificatePassphrase, self::CONTEXT_PASSPHRASE)
                : null,
            'certificate_fingerprint' => $fingerprint,
            'certificate_valid_to' => $validTo,
            // Vždy vypnuto — viz docblock.
            'is_active' => false,
        ], $userId);

        $saved = $this->repository->findPublic($environment);
        if ($saved === null) {
            throw new SubmissionChannelException(
                'gateway_store_failed',
                'Registrace se uložila, ale nelze ji znovu načíst.',
                500,
            );
        }

        return $saved;
    }

    public function setActive(string $environment, bool $active): bool
    {
        $this->assertEnvironment($environment);
        if ($active) {
            // Zapnout nejde něco, co neexistuje nebo čemu prošel certifikát.
            $row = $this->repository->findPublic($environment);
            if ($row === null) {
                throw new SubmissionChannelException(
                    'gateway_not_configured',
                    'Registrace odesílací brány pro tohle prostředí není uložená.',
                    409,
                );
            }
            $validTo = $row['certificate_valid_to'];
            if (is_string($validTo) && $validTo !== '' && strtotime($validTo) < time()) {
                throw new SubmissionChannelException(
                    'gateway_certificate_expired',
                    'Certifikátu odesílací brány vypršela platnost ' . $validTo . '. Nahrajte nový.',
                    409,
                );
            }
        }

        return $this->repository->setActive($environment, $active);
    }

    public function delete(string $environment): bool
    {
        $this->assertEnvironment($environment);

        return $this->repository->delete($environment);
    }

    /**
     * Načte registraci k použití. **Fail-closed:** jakýkoliv chybějící nebo
     * nepoužitelný předpoklad je pojmenovaná výjimka, ne tichý průchod.
     *
     * @throws SubmissionChannelException
     */
    public function load(string $environment): IsdsGatewayRegistration
    {
        $this->assertEncryptionReady();
        $this->assertEnvironment($environment);

        $row = $this->repository->findWithSecrets($environment);
        if ($row === null) {
            throw new SubmissionChannelException(
                'isds_gateway_not_configured',
                'Odesílací brána datové schránky není nastavená. '
                . 'Podání je připravené — stáhněte si přílohu a odešlete ji ze své datové schránky ručně.',
                503,
            );
        }
        if ($row['is_active'] !== true) {
            throw new SubmissionChannelException(
                'isds_gateway_disabled',
                'Odesílací brána datové schránky je vypnutá. '
                . 'Podání je připravené — stáhněte si přílohu a odešlete ji ze své datové schránky ručně.',
                503,
            );
        }
        $portalHost = (string) $row['portal_host'];
        $serviceHost = (string) $row['service_host'];
        $this->assertOfficialHosts($environment, $portalHost, $serviceHost);
        $validTo = $row['certificate_valid_to'];
        if (is_string($validTo) && $validTo !== '' && strtotime($validTo) < time()) {
            throw new SubmissionChannelException(
                'gateway_certificate_expired',
                'Certifikátu odesílací brány vypršela platnost ' . $validTo . '. Nahrajte nový.',
                503,
            );
        }

        // Jedna větev navíc: registrace s odkazem si certifikát vezme ze
        // sdíleného trezoru, registrace s vlastní kopií jede přesně jako dosud.
        //
        // `supplierId` je tu `null` schválně: registrace brány je instalačně
        // globální (jedna na prostředí, tabulka nemá `supplier_id`), takže
        // rozsah na firmu není čím ověřit. Autorizaci nese oprávnění, kterým
        // se registrace nastavuje.
        $credentialId = ($row['credential_id'] ?? null) !== null ? (int) $row['credential_id'] : 0;
        if ($credentialId > 0) {
            $shared = $this->sharedCertificates()->resolve($credentialId, null);
            $certificate = $shared->certificate;
            $passphrase = $shared->passphrase;
        } else {
            try {
                $certificate = $this->reveal($row['certificate_ciphertext'] ?? null, self::CONTEXT_CERTIFICATE);
                $passphrase = $this->reveal($row['certificate_passphrase_ciphertext'] ?? null, self::CONTEXT_PASSPHRASE);
            } catch (\RuntimeException) {
                // `previous` se ZÁMĚRNĚ nepředává: nesl by v trace ciphertext
                // i šifrovací kontext.
                throw new SubmissionChannelException(
                    'isds_gateway_certificate_decryption_failed',
                    'Certifikát odesílací brány se nepodařilo rozšifrovat. '
                    . 'Nejspíš se změnil šifrovací klíč — nahrajte certifikát znovu.',
                    500,
                );
            }
        }

        if ($certificate === null) {
            throw new SubmissionChannelException(
                'isds_gateway_certificate_missing',
                'Registrace odesílací brány nemá uložený certifikát.',
                503,
            );
        }

        return new IsdsGatewayRegistration(
            environment: (string) $row['environment'],
            atsId: (string) $row['ats_id'],
            label: (string) $row['label'],
            returnUrl: (string) $row['return_url'],
            errorUrl: $row['error_url'] !== null ? (string) $row['error_url'] : null,
            conceptTtlSeconds: (int) $row['concept_ttl_seconds'],
            portalHost: $portalHost,
            serviceHost: $serviceHost,
            loginPolicy: IsdsGatewayLoginPolicy::fromDatabase(
                isset($row['user_login_policy']) ? (string) $row['user_login_policy'] : null,
            ),
            certificate: $certificate,
            certificatePassphrase: $passphrase,
            certificateFingerprint: $row['certificate_fingerprint'] !== null
                ? (string) $row['certificate_fingerprint']
                : null,
            certificateValidTo: $row['certificate_valid_to'] !== null
                ? (string) $row['certificate_valid_to']
                : null,
        );
    }

    /** Je brána v tomhle prostředí použitelná? Pro UI, ne pro rozhodování v kódu. */
    public function isUsable(string $environment): bool
    {
        $row = $this->repository->findPublic($environment);

        return $row !== null
            && $row['is_active'] === true
            && $this->publicRowIsUsable($environment, $row);
    }

    /**
     * Je brána nastavená natolik, že má smysl na ni v kontejneru nabindovat
     * {@see GatewayIsdsTransport} místo `UnavailableIsdsTransport`?
     *
     * Oproti {@see isUsable()} trvá navíc na certifikátu: bez něj se ke službám
     * brány nedovoláme vůbec (kap. 3.1 bod 4), takže by adaptér uživateli
     * sliboval cestu, která neexistuje. Otisk je v `PUBLIC_COLUMNS`, takže se
     * kvůli téhle otázce nesahá na ciphertext.
     *
     * **Není to povolení k odeslání.** Tím zůstává {@see load()}, který se
     * volá až v okamžiku odesílání a hází pojmenované chyby. Tohle rozhoduje
     * jen o tom, kterou překážku uživatel uvidí.
     */
    public function isDispatchReady(string $environment): bool
    {
        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            return false;
        }
        $row = $this->repository->findPublic($environment);

        return $row !== null
            && $row['is_active'] === true
            && is_string($row['certificate_fingerprint'] ?? null)
            && $row['certificate_fingerprint'] !== ''
            && $this->publicRowIsUsable($environment, $row);
    }

    /** Čistá kontrola pro capability UI a bezpečnostní regresní testy. */
    public static function isOfficialHostPair(
        string $environment,
        string $portalHost,
        string $serviceHost,
    ): bool {
        foreach (self::ALLOWED_HOST_PAIRS[$environment] ?? [] as $pair) {
            if (hash_equals($pair['portal'], $portalHost)
                && hash_equals($pair['service'], $serviceHost)
            ) {
                return true;
            }
        }

        return false;
    }

    // ───────────────────────── interní ─────────────────────────

    /**
     * Bez resolveru nejde odkaz do trezoru odemknout. Fail-closed
     * s pojmenovaným kódem — prázdný certifikát by se projevil až
     * nesrozumitelným selháním TLS proti bráně.
     */
    private function sharedCertificates(): SharedCertificateResolver
    {
        if ($this->sharedCertificates === null) {
            throw new SubmissionChannelException(
                'shared_certificate_unavailable',
                'Sdílený trezor certifikátů není k dispozici.',
                500,
            );
        }

        return $this->sharedCertificates;
    }

    private function reveal(mixed $ciphertext, string $context): ?SensitiveValue
    {
        if (!is_string($ciphertext) || $ciphertext === '') {
            return null;
        }
        $crypto = $this->crypto;

        return SensitiveValue::fromProducer(static fn (): string => $crypto->decryptFor($ciphertext, $context));
    }

    /** @return array{0:?string,1:?string} fingerprint, valid_to */
    private function inspectCertificate(string $bytes, ?string $passphrase): array
    {
        $bundle = [];
        if (!@openssl_pkcs12_read($bytes, $bundle, (string) $passphrase)) {
            throw new SubmissionChannelException(
                'invalid_certificate',
                'Nahraný soubor se nepodařilo otevřít jako PKCS#12 (PFX/P12). '
                . 'Odesílací brána vyžaduje certifikát VČETNĚ soukromého klíče — používá se jako '
                . 'klientský certifikát TLS. Zkontrolujte soubor a jeho heslo.',
                400,
            );
        }

        $certificate = (string) ($bundle['cert'] ?? '');
        $parsed = @openssl_x509_parse($certificate, false);
        $fingerprint = @openssl_x509_fingerprint($certificate, 'sha256');
        if (!is_array($parsed) || !is_string($fingerprint) || $fingerprint === '') {
            throw new SubmissionChannelException(
                'invalid_certificate',
                'Nahraný soubor se nepodařilo přečíst jako certifikát.',
                400,
            );
        }
        if (($bundle['pkey'] ?? '') === '') {
            throw new SubmissionChannelException(
                'certificate_without_key',
                'Nahraný certifikát neobsahuje soukromý klíč, takže ho nelze použít jako klientský '
                . 'certifikát TLS. Vyexportujte ho znovu i s klíčem.',
                400,
            );
        }

        $validTo = (int) ($parsed['validTo_time_t'] ?? 0);

        return [
            strtolower(str_replace(':', '', $fingerprint)),
            $validTo > 0 ? date('Y-m-d H:i:s', $validTo) : null,
        ];
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('~^https?://~', '', $host) ?? $host;
        $host = rtrim($host, '/');
        if (preg_match('/^[a-z0-9.-]{4,190}$/', $host) !== 1) {
            throw new SubmissionChannelException(
                'invalid_host',
                'Adresa prostředí datové schránky nemá platný tvar.',
                400,
            );
        }

        return $host;
    }

    private function assertOfficialHosts(
        string $environment,
        string $portalHost,
        string $serviceHost,
    ): void {
        if (self::isOfficialHostPair($environment, $portalHost, $serviceHost)) return;

        throw new SubmissionChannelException(
            'untrusted_isds_host',
            'Odesílací brána smí komunikovat jen s oficiální dvojicí hostitelů ISDS pro zvolené prostředí.',
            400,
        );
    }

    /** @param array<string,mixed> $row */
    private function publicRowIsUsable(string $environment, array $row): bool
    {
        try {
            $this->assertOfficialHosts(
                $environment,
                (string) ($row['portal_host'] ?? ''),
                (string) ($row['service_host'] ?? ''),
            );
        } catch (SubmissionChannelException) {
            return false;
        }
        $validTo = $row['certificate_valid_to'] ?? null;

        return !is_string($validTo) || $validTo === '' || strtotime($validTo) >= time();
    }

    private function assertEncryptionReady(): void
    {
        if ($this->crypto->validateKey() !== null) {
            throw new SubmissionChannelException(
                'encryption_key_required',
                'Pro uložení certifikátu odesílací brány nastavte cfg.app.secret_encryption_key.',
                503,
            );
        }
    }

    private function assertEnvironment(string $environment): void
    {
        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            throw new SubmissionChannelException('invalid_environment', 'Neznámé prostředí.', 400);
        }
    }
}
