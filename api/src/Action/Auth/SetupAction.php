<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Bootstrap;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\SessionAuthContext;
use MyInvoice\Service\Auth\SessionCookieFactory;
use MyInvoice\Service\Auth\WebAuthnConfig;
use MyInvoice\Service\Ares\SupplierRegistryEnricher;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\Config\CfgLocalWriter;
use MyInvoice\Service\System\ManagedModeGuard;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Setup\PasswordSetupLinkIssuer;
use MyInvoice\Service\Setup\ProvisionTokenGuard;
use MyInvoice\Service\Setup\SetupPasswordMode;
use MyInvoice\Service\Setup\TermsOrigin;
use MyInvoice\Service\System\AppUrlConfiguration;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * First-run setup. Funguje **jen pokud users je prázdná** (race-safe přes UNIQUE constraint).
 */
final class SetupAction
{
    /** Dokumenty, jejichž přijetí musí uživatel v prvotním setupu potvrdit. */
    private const TERMS_DOCUMENTS = [
        'https://myucto.cz/licence',
        'https://myucto.cz/obchodni-podminky',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly PasswordHasher $hasher,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly SessionManager $sessions,
        private readonly Config $config,
        private readonly AppUrlConfiguration $appUrl,
        private readonly SupplierRegistryEnricher $enricher,
        // SEC-01: brání „nárokování" cizího bankovního účtu už při initial setupu.
        private readonly \MyInvoice\Repository\BankStatementOwnershipResolver $bankOwnership,
        private readonly SessionCookieFactory $sessionCookies,
        // H-01 / H-33 — spravovaný (SaaS) provoz; pro self-hosted instalace no-op.
        private readonly ProvisionTokenGuard $provisionTokens,
        private readonly PasswordSetupLinkIssuer $passwordSetupLinks,
        // H-02 — ve spravované instalaci drží konfiguraci provozovatel, ne setup.
        private readonly ManagedModeGuard $managed,
        // Právnická osoba se zakládá rovnou v podvojném účetnictví, a to bez
        // směrné osnovy nefunguje — viz insertSupplier().
        private readonly \MyInvoice\Service\Accounting\ChartOfAccountsSeeder $coaSeeder,
        // Spravovaná instalace dostává licenční klíč rovnou ve zřizovacím
        // požadavku — viz aktivaci na konci setupu.
        private readonly \MyInvoice\Service\License\LicenseService $license,
        // Zveřejněné bankovní účty z registru plátců DPH (doplnění podle DIČ).
        private readonly \MyInvoice\Service\Ares\CrpDphClient $crpdph,
        private readonly \Psr\Log\LoggerInterface $log,
    ) {}

    /**
     * SEC-01 (2. kolo): setup sice běží jen nad prázdnou tabulkou users, ale
     * `currencies` a `bank_statements` prázdné být nemusí (obnova dat, znovu-setup
     * po smazání uživatelů). insertSupplier() zapisuje account_number/bank_code/iban
     * stejně jako updateCurrency, takže musí projít stejným guardem — jinak je
     * 409 z SettingsAction obejitelný přes /api/setup.
     *
     * @param array<string,mixed> $supplier
     */
    /**
     * Doplní bankovní účet z registru plátců DPH (zveřejněné účty podle DIČ).
     *
     * Zřizovací požadavek účet neobsahuje — objednávka se na něj neptá a my ho
     * neznáme. Spravovaná instalace tak vznikla s prázdnou CZK i EUR měnou
     * a zákazník nemohl vystavit fakturu, dokud si účet nedoplnil ručně.
     *
     * ⚠️ Bere se JEN zveřejněný účet z registru. Je to účet, který u správce
     * daně ohlásil sám plátce — nic se nehádá a nic neopisuje z jiného zdroje.
     *
     * ⚠️ Best-effort a jen do PRÁZDNÉ měny. Výpadek registru ani chybějící
     * zveřejněný účet nesmí shodit dokončený setup; co se nedoplní, doplní si
     * zákazník v Nastavení.
     *
     * @param array<string,mixed> $supplier
     */
    private function fillPublishedBankAccount(int $supplierId, array $supplier): void
    {
        // Účet, který přišel ve zřizovacím požadavku, má přednost — registr
        // by ho přepsal jiným, který si zákazník nevybral.
        if (isset($supplier['bank_account']) && is_array($supplier['bank_account'])
            && trim((string) ($supplier['bank_account']['account_number'] ?? '')) !== '') {
            return;
        }
        $dic = trim((string) ($supplier['dic'] ?? ''));
        if ($dic === '') {
            return;
        }

        try {
            $res = $this->crpdph->lookup($dic);
            $accounts = is_array($res['accounts'] ?? null) ? $res['accounts'] : [];
            if (!$accounts) {
                return;
            }
            // První zveřejněný účet je ten, který plátce uvádí jako hlavní.
            $first = $accounts[0];
            $number = trim((string) ($first['prefix'] ?? '')) !== ''
                ? trim((string) $first['prefix']) . '-' . trim((string) ($first['number'] ?? ''))
                : trim((string) ($first['number'] ?? ''));
            $bankCode = trim((string) ($first['bank_code'] ?? ''));
            $iban = trim((string) ($first['iban'] ?? '')) ?: null;
            if ($number === '' && $iban === null) {
                return;
            }

            // SEC-01: ani doplnění z registru si nesmí nárokovat účet, který už
            // patří jinému dodavateli nebo na který chodí cizí výpisy.
            if ($this->bankOwnership->accountClaimedByOtherSupplier($supplierId, $number ?: null, $iban)
                || $this->bankOwnership->accountBlockedByForeignStatements($supplierId, $number ?: null, $iban)) {
                $this->log->warning('setup: zveřejněný účet se nedoplnil — patří jinému dodavateli');
                return;
            }

            $stmt = $this->db->pdo()->prepare(
                "UPDATE currencies SET account_number = ?, bank_code = ?, iban = ?
                   WHERE supplier_id = ? AND code = 'CZK'
                     AND (account_number IS NULL OR account_number = '')
                     AND (iban IS NULL OR iban = '')"
            );
            $stmt->execute([$number ?: null, $bankCode ?: null, $iban, $supplierId]);
        } catch (\Throwable $e) {
            $this->log->warning('setup: zveřejněné účty se nepodařilo načíst', ['error' => $e->getMessage()]);
        }
    }

    private function foreignBankAccountError(array $supplier): ?string
    {
        $bank = isset($supplier['bank_account']) && is_array($supplier['bank_account']) ? $supplier['bank_account'] : null;
        if ($bank === null) {
            return null;
        }
        $account = trim((string) ($bank['account_number'] ?? '')) ?: null;
        $iban    = trim((string) ($bank['iban'] ?? '')) ?: null;

        // supplier ještě nemá id → porovnává se proti všem firmám.
        if ($this->bankOwnership->accountClaimedByOtherSupplier(0, $account, $iban)) {
            return 'Tento bankovní účet už je evidovaný u jiné firmy.';
        }
        if ($this->bankOwnership->accountBlockedByForeignStatements(0, $account, $iban)) {
            return 'K tomuto účtu jsou v systému bankovní výpisy jiné firmy.';
        }

        return null;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        // H-01: zřizovací token se ověřuje jako ÚPLNĚ PRVNÍ věc — dřív, než se
        // vůbec podíváme na tělo požadavku. Ve spravovaném režimu je okno mezi
        // zřízením instance a naším setupem jediné, co brání cizímu zabrání účtu.
        $rejection = $this->provisionTokens->verify($request);
        if ($rejection !== null) {
            $this->logger->log(
                ProvisionTokenGuard::LOG_EVENT,
                null,
                null,
                null,
                ['reason' => $rejection['reason']],
                $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                $request->getHeaderLine('User-Agent'),
            );

            return Json::error($response, $rejection['code'], ProvisionTokenGuard::MESSAGE, 403);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $admin = (array) ($body['admin'] ?? []);
        $passwordMode = SetupPasswordMode::fromAdminBlock($admin);
        $termsOrigin = TermsOrigin::normalize($body[TermsOrigin::REQUEST_FIELD] ?? null);
        $supplier = isset($body['supplier']) && is_array($body['supplier']) ? $body['supplier'] : null;
        // ⚠️ Identifikátor instalace PŘIDĚLENÝ provozovatelem (spravovaný provoz).
        //
        // Aplikace si jinak generuje vlastní UUID a licenční server u spravované
        // instalace ověřuje, že `instance_id` odpovídá řádku v jeho evidenci
        // instancí — což lokálně vymyšlené UUID nikdy nesplní. Bez tohohle pole
        // by spravovaná instalace licenci nikdy neaktivovala a nedokoupila by
        // si ani místo. Volitelné: self-hosted setup ho neposílá a nic se
        // nemění.
        $assignedInstanceId = isset($body['instance_id']) && is_string($body['instance_id'])
            ? trim($body['instance_id'])
            : '';
        if ($assignedInstanceId !== '' && !preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $assignedInstanceId)) {
            return Json::error($response, 'validation_failed', 'instance_id má nepovolený tvar.', 400);
        }
        // ⚠️ Licenční klíč spravované instalace. Přichází ze zřizovacího
        // požadavku provozovatele, protože zákazník ho nemá kam opsat —
        // instalaci dostává hotovou. Bez něj (self-hosted) se nic nemění.
        $licenseKey = isset($body['license_key']) && is_string($body['license_key'])
            ? trim($body['license_key'])
            : '';
        if ($licenseKey !== '' && !preg_match('/^[A-Za-z0-9-]{8,64}$/', $licenseKey)) {
            return Json::error($response, 'validation_failed', 'license_key má nepovolený tvar.', 400);
        }
        $requireTotp = !empty($body['require_totp']);
        // Přijetí licence a obchodních podmínek je podmínkou dokončení setupu;
        // wizard bez zaškrtnutí dál nepustí, tady se to ověřuje znovu server-side.
        $termsAccepted = ($body['terms_accepted'] ?? null) === true;
        if (array_key_exists('require_mfa', $body) && !is_bool($body['require_mfa'])) {
            return Json::error($response, 'validation_failed', 'require_mfa musí být boolean.', 400);
        }
        if (array_key_exists(SetupPasswordMode::REQUEST_FIELD, $admin) && !is_bool($admin[SetupPasswordMode::REQUEST_FIELD])) {
            return Json::error($response, 'validation_failed', 'admin.password_setup_link musí být boolean.', 400);
        }
        $usesLegacyRequest = !array_key_exists('require_mfa', $body);
        $requireMfa = $usesLegacyRequest ? $requireTotp : (bool) $body['require_mfa'];
        $methodsProvided = array_key_exists('allowed_mfa_methods', $body);
        // Když volající seznam neposlal, platí to, co je v configu — ne domněnka
        // wizardu. Odpověď pak nese reálnou politiku, kterou vzápětí potvrdí /me.
        $methods = $methodsProvided
            ? $body['allowed_mfa_methods']
            : ($usesLegacyRequest
                ? ['totp']
                : $this->config->get('auth.allowed_mfa_methods', ['passkey', 'totp']));
        try {
            // Striktně — vstup z wizardu musí chybu vidět, runtime politika je
            // naopak fail-soft, aby překlep v cfg neshodil celou aplikaci.
            $allowedMfaMethods = MfaPolicyService::validateMethods($methods);
        } catch (\InvalidArgumentException $e) {
            if ($methodsProvided || $usesLegacyRequest) {
                return Json::error($response, 'validation_failed', $e->getMessage(), 400);
            }
            // Překlep v cfg.php nesmí zablokovat první spuštění; stejný fail-soft
            // fallback jako v MfaPolicyService.
            $allowedMfaMethods = ['passkey', 'totp'];
        }

        $detectedUrl = $this->detectAppUrl($request);
        $willWriteDetectedUrl = $detectedUrl !== null && $this->appUrl->shouldSetupUseDetectedOrigin();
        if ($requireMfa && $allowedMfaMethods === ['passkey']) {
            $canonicalUrl = $willWriteDetectedUrl
                ? $detectedUrl
                : (string) $this->config->get('app.url', '');
            try {
                new WebAuthnConfig(new Config(['app' => ['url' => $canonicalUrl]]));
            } catch (\InvalidArgumentException $e) {
                return Json::error(
                    $response,
                    'webauthn_configuration_invalid',
                    $e->getMessage(),
                    400,
                );
            }
        }

        $errors = $this->validate($admin, $supplier, $termsAccepted, $passwordMode);
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        if ($supplier !== null && ($bankErr = $this->foreignBankAccountError($supplier)) !== null) {
            return Json::error($response, 'validation_failed', $bankErr, 409, [
                'fields' => ['supplier.bank_account.account_number' => [$bankErr]],
            ]);
        }

        $pdo = $this->db->pdo();

        // H-33: v režimu odkazu se hashuje náhodné heslo, které nikdo nikdy nepoužije —
        // cizí heslo tak u nás neleží ani minutu.
        $plainPassword = $passwordMode->requiresPlainPassword()
            ? (string) $admin['password']
            : $this->passwordSetupLinks->randomPassword();

        try {
            $passwordHash = $this->hasher->hash($plainPassword);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400, [
                'fields' => ['admin.password' => [$e->getMessage()]],
            ]);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());

        /** @var array{token:string,expires_at:\DateTimeImmutable}|null $passwordSetup */
        $passwordSetup = null;

        // Race-safe: jedna transakce s SELECT FOR UPDATE — dva souběžné setup requesty
        // se serializují, druhý vidí prvního usera a odmítne setup.
        $pdo->beginTransaction();
        try {
            $count = (int) self::queryScalar($pdo, 'SELECT COUNT(*) FROM users FOR UPDATE');
            if ($count > 0) {
                $pdo->rollBack();
                return Json::error($response, 'setup_already_done', 'Setup již proběhl.', 409);
            }
            $superadminRoleId = (int) $pdo->query(
                "SELECT id FROM roles WHERE system_key = 'superadmin' AND role_type = 'superadmin' AND is_active = 1 LIMIT 1"
            )->fetchColumn();
            if ($superadminRoleId <= 0) {
                throw new \RuntimeException('Systémová role superadmin není dostupná. Spusť nejprve migrace.');
            }
            $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, name, role, role_id, locale, is_active)
                                   VALUES (?, ?, ?, "admin", ?, "cs", 1)');
            $stmt->execute([
                trim((string) $admin['email']),
                $passwordHash,
                trim((string) $admin['name']),
                $superadminRoleId,
            ]);
            $userId = (int) $pdo->lastInsertId();

            // H-33: jednorázový odkaz na NASTAVENÍ hesla (zákazník žádné neměl).
            // Vzniká ve stejné transakci jako admin — buď obojí, nebo nic.
            if ($passwordMode->returnsSetupToken()) {
                $passwordSetup = $this->passwordSetupLinks->issue($pdo, $userId, $ip);
            }

            // Přidělený identifikátor instalace. Řádek `license` zakládá migrace
            // se svým UUID, takže se přepisuje — a jen ve stejné transakci jako
            // admin, ať instance nikdy neběží s identitou, kterou licenční
            // server nezná.
            if ($assignedInstanceId !== '') {
                $pdo->prepare('UPDATE license SET instance_id = ? WHERE id = 1')
                    ->execute([$assignedInstanceId]);
            }

            // Volitelně dodavatel
            $createdSupplierId = null;
            if ($supplier !== null) {
                $createdSupplierId = $this->insertSupplier($pdo, $supplier);
            }

            $this->logger->log('setup.completed', $userId, 'user', $userId, array_filter([
                'email' => $admin['email'],
                'has_supplier' => $supplier !== null,
                'require_totp' => $requireTotp,
                'require_mfa' => $requireMfa,
                'allowed_mfa_methods' => $allowedMfaMethods,
                'terms_accepted' => true,
                'terms_documents' => self::TERMS_DOCUMENTS,
                'password_setup_link' => $passwordMode->usesSetupLink(),
                // Souhlas mohl přijít z objednávky — ať je dohledatelné, že ho
                // neodklikl uživatel, který u toho nebyl.
                'terms_origin' => $termsOrigin,
                'assigned_instance_id' => $assignedInstanceId !== '' ? $assignedInstanceId : null,
            ], static fn (mixed $v): bool => $v !== null), $ip, $request->getHeaderLine('User-Agent'));

            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            return Json::error($response, 'setup_failed', $e->getMessage(), 500);
        }

        // Po commitu (mimo DB transakci — dělá síťové volání): doplň z veřejných
        // registrů, co jde (čísla domu, NACE, spisová značka, typ poplatníka, kód FÚ).
        if ($createdSupplierId !== null) {
            $this->enricher->enrich($createdSupplierId, $supplier['ic'] ?? null, $supplier['dic'] ?? null);
            $this->fillPublishedBankAccount($createdSupplierId, $supplier ?? []);
        }

        // Spravovaná instalace aktivuje licenci sama, hned při zřízení.
        // Zákazník ji dostává hotovou a klíč nemá kam opsat; dokud se tohle
        // nedělo, běžela zaplacená instalace na zkušebním období.
        //
        // ⚠️ Best-effort. Nedostupný licenční server je provozní výpadek, ne
        // důvod zahodit dokončený setup — klíč se dá zadat i ručně a licence
        // se ověřuje znovu při každém startu.
        $licenseActivated = null;
        if ($licenseKey !== '') {
            try {
                // ⚠️ Přiřazené `instance_id` jsme zapsali přímo přes PDO uvnitř
                // transakce, takže licenční služba má v paměti pořád původní,
                // lokálně vygenerované UUID. Bez tohohle zahození odešla aktivace
                // s ním a server ji odmítl `instance_not_managed`.
                $this->license->forgetCachedRow();
                $res = $this->license->activate($licenseKey);
                $licenseActivated = ($res['ok'] ?? false) === true;
                if (!$licenseActivated) {
                    $this->log->warning('setup: aktivace licence selhala', ['error' => (string) ($res['error'] ?? '?')]);
                }
            } catch (\Throwable $e) {
                $licenseActivated = false;
                $this->log->warning('setup: aktivace licence spadla', ['error' => $e->getMessage()]);
            }
        }

        // Zapiš obecnou MFA politiku, legacy TOTP flag a případně detekované app.url.
        // `app.url` přepisujeme JEN pokud je v configu prázdné nebo některý ze známých
        // placeholderů (Docker `http://localhost:8080`, sample `https://dev.example.com`, `https://example.com`).
        // To umožní dokončit Docker setup z LAN IP a zároveň ušetří uživateli krok ruční konfigurace
        // (důležité pro reset hesla / schvalovací odkazy v emailech).
        // Pokud uživatel app.url už nastavil přes MYINVOICE_APP_URL env nebo cfg.php, neperepíšeme.
        // `auth.allowed_mfa_methods` zapisujeme jen když ho volající vážně poslal
        // (nebo jde o legacy tvar požadavku, kde je seznam odvozený z require_totp).
        // Wizard ho záměrně neposílá — ať zůstane platná hodnota z cfg.php a per-instance
        // override nevznikne omylem.
        $keysToWrite = [
            'auth.require_mfa' => $requireMfa,
            'auth.require_totp' => $requireTotp,
        ];
        if ($methodsProvided || $usesLegacyRequest) {
            $keysToWrite['auth.allowed_mfa_methods'] = $allowedMfaMethods;
        }
        // ⚠️ Ve spravované instalaci `app.url` NEZAPISUJEME, i kdyby v konfiguraci
        // chybělo. Vlastní ho provisioning šablona a musí být správně dřív, než na
        // instanci dorazí první požadavek — visí na něm tenantový host gate.
        // Kdybychom sem dopsali hodnotu odvozenou z požadavku (například když nám
        // setup projde přes IP nebo přes interní jméno), gate bychom instanci
        // zamkli na adresu, na kterou zákazník nikdy nepřijde. Chybějící `app.url`
        // je v tomhle režimu chyba zřízení a má se řešit tam, ne přepsat naslepo.
        if ($willWriteDetectedUrl && !$this->managed->isLocked(ManagedModeGuard::KEY_APP_URL)) {
            $keysToWrite['app.url'] = $detectedUrl;
        }
        $cfgLocalWritten = false;
        try {
            // V single-volume Docker layoutu (MYINVOICE_DATA_DIR=/data) zapisujeme
            // do volumu, ne do image — jinak by per-instance overrides nepřežily image update.
            CfgLocalWriter::setKeys(CfgLocalWriter::resolveTargetDir(Bootstrap::rootDir()), $keysToWrite);
            $cfgLocalWritten = true;
        } catch (\Throwable $e) {
            $this->logger->log('setup.cfg_local_write_failed', $userId, 'user', $userId, [
                'error' => $e->getMessage(),
            ], $ip, $request->getHeaderLine('User-Agent'));
        }

        // H-01: token je jednorázový. Vlastní zápis (ne součást $keysToWrite výše),
        // aby se o zneplatnění pokusil i tehdy, když zápis MFA politiky selhal.
        if ($this->provisionTokens->isEnforced()) {
            try {
                $this->provisionTokens->consume(CfgLocalWriter::resolveTargetDir(Bootstrap::rootDir()));
            } catch (\Throwable $e) {
                $this->logger->log('setup.provision_token_consume_failed', $userId, 'user', $userId, [
                    'error' => $e->getMessage(),
                ], $ip, $request->getHeaderLine('User-Agent'));
            }
        }

        // Auto-login: vytvoř session pro nově vzniknklého admina (eliminuje public window pro setup-sample).
        // ⚠️ H-33: v režimu odkazu na nastavení hesla se session ZÁMĚRNĚ nezakládá —
        // setup voláme my ze serveru, takže by patřila nám, ne zákazníkovi.
        $userAgent = $request->getHeaderLine('User-Agent');
        $session = null;
        if ($passwordMode->issuesSession()) {
            $session = $this->sessions->create(
                $userId,
                $ip,
                $userAgent,
                $requireMfa ? SessionAuthContext::setup('password') : SessionAuthContext::basic('password'),
            );

            $response = $response->withHeader(
                'Set-Cookie',
                $this->sessionCookies->create($session['token'], $session['expires_at']),
            );
        }

        $payload = [
            'user' => [
                'id'    => $userId,
                'email' => $admin['email'],
                'name'  => $admin['name'],
                'role'  => [
                    'id'         => $superadminRoleId,
                    'name'       => 'Superadmin',
                    'type'       => 'superadmin',
                    'is_active'  => true,
                    'system_key' => 'superadmin',
                ],
                'is_superadmin' => true,
                'totp_enabled' => false,
                'must_setup_totp' => $requireTotp,
                'mfa_enabled' => false,
                'mfa_methods' => [],
                'passkey_count' => 0,
                'must_setup_mfa' => $requireMfa,
            ],
            'csrf_token' => $session['csrf_token'] ?? null,
            'next' => $requireMfa ? '/setup-mfa' : '/',
            'require_totp' => $requireTotp,
            'require_mfa' => $requireMfa,
            'allowed_mfa_methods' => $allowedMfaMethods,
            'cfg_local_written' => $cfgLocalWritten,
        ];

        if ($passwordSetup !== null) {
            // „Nastavení hesla", ne „obnova" — zákazník žádné heslo neměl.
            $payload['password_setup_token'] = $passwordSetup['token'];
            $payload['password_setup_expires_at'] = $passwordSetup['expires_at']->format(\DateTimeInterface::ATOM);
        }

        return Json::ok($response, $payload, 201);
    }

    /**
     * @param array<string,mixed> $supplier
     */
    private function insertSupplier(\PDO $pdo, array $supplier): int
    {
        // Najdi country_id z iso2
        $iso2 = strtoupper((string) ($supplier['country_iso2'] ?? 'CZ'));
        $stmtCountry = $pdo->prepare('SELECT id FROM countries WHERE iso2 = ?');
        $stmtCountry->execute([$iso2]);
        $countryId = (int) ($stmtCountry->fetchColumn() ?: 0);
        if ($countryId === 0) {
            $countryId = (int) self::queryScalar($pdo, "SELECT id FROM countries WHERE iso2 = 'CZ'");
        }

        $defaultCurrencyCode = strtoupper((string) ($supplier['default_currency'] ?? 'CZK'));
        $vatRateId = (int) self::queryScalar(
            $pdo,
            'SELECT id FROM vat_rates WHERE is_default = 1 ORDER BY id LIMIT 1',
        ) ?: (int) self::queryScalar($pdo, 'SELECT id FROM vat_rates ORDER BY id LIMIT 1');
        if ($vatRateId === 0) {
            throw new \RuntimeException('Tabulka vat_rates je prázdná.');
        }

        // Multi-supplier bootstrap — supplier nemá ještě default_currency_id a currencies vyžadují supplier_id (cyklický FK).
        // Trick: SET FOREIGN_KEY_CHECKS=0, INSERT supplier s placeholder default_currency_id=0,
        // INSERT currencies (CZK + EUR) pro nový supplier, UPDATE supplier.default_currency_id, FK_CHECKS=1.
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        // ⚠️ Režim účetnictví se ODVOZUJE z právní formy, nedědí se DB default.
        //
        // Sloupec `supplier.accounting_mode` má default `tax_evidence` (migrace
        // 1001) a setup ho dosud nenastavoval vůbec. Jenže daňová evidence je
        // režim pro fyzické osoby — právnická osoba je ze zákona účetní jednotka
        // a vede podvojné účetnictví. Firma, která si přes ARES natáhla `pravniForma`
        // s.r.o., tedy dostala rovnou špatný režim a musela ho hledat v Nastavení.
        //
        // Přepnout to zpětně přitom NENÍ zadarmo: doklady vzniklé v daňové
        // evidenci se zapnutím podvojného účetnictví nedoúčtují a sestavy tiše
        // nezahrnou minulost (viz text u přepínače). Špatný výchozí stav je proto
        // dražší, než vypadá — správně se musí trefit hned na začátku.
        //
        // `fo` i prázdná hodnota zůstávají na daňové evidenci: u OSVČ je to
        // správně a u neznámé právní formy je to ta zvratitelnější volba.
        $taxpayerType = in_array($supplier['taxpayer_type'] ?? null, ['fo', 'po'], true)
            ? (string) $supplier['taxpayer_type']
            : null;
        $accountingMode = $taxpayerType === 'po' ? 'double_entry' : 'tax_evidence';

        $stmt = $pdo->prepare(
            'INSERT INTO supplier
            (company_name, display_name, street, city, zip, country_id, ic, dic, is_vat_payer,
             email, phone, web, commercial_register, taxpayer_type, accounting_mode,
             default_currency_id, default_vat_rate_id,
             default_payment_due_days, default_payment_due_unit, default_hourly_rate)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (string) ($supplier['company_name'] ?? ''),
            (string) ($supplier['display_name'] ?? '') ?: null,
            (string) ($supplier['street'] ?? ''),
            (string) ($supplier['city'] ?? ''),
            (string) ($supplier['zip'] ?? ''),
            $countryId,
            (string) ($supplier['ic'] ?? '') ?: null,
            (string) ($supplier['dic'] ?? '') ?: null,
            !empty($supplier['is_vat_payer']) ? 1 : 0,
            (string) ($supplier['email'] ?? ''),
            (string) ($supplier['phone'] ?? '') ?: null,
            (string) ($supplier['web'] ?? '') ?: null,
            (string) ($supplier['commercial_register'] ?? '') ?: null,
            $taxpayerType,
            $accountingMode,
            $vatRateId,
            (int) ($supplier['default_payment_due_days'] ?? 7),
            in_array($supplier['default_payment_due_unit'] ?? null, ['days', 'month'], true)
                ? (string) $supplier['default_payment_due_unit']
                : 'days',
            (string) ($supplier['default_hourly_rate'] ?? '1500.00'),
        ]);
        $supplierId = (int) $pdo->lastInsertId();
        \MyInvoice\Service\Vat\VatStatusService::seedInitialStatus($pdo, $supplierId, !empty($supplier['is_vat_payer']));

        // ⚠️ Podvojné účetnictví BEZ směrné osnovy je rozbitý stav — `PostingService`
        // nemá na co mapovat `account_code`. `SettingsAction` proto osnovu seeduje
        // při každém přepnutí na `double_entry` a totéž musí udělat setup, jinak by
        // firma vznikla v režimu, který neumí zaúčtovat první doklad.
        //
        // Doúčtování minulosti, které přepínač v Nastavení řeší, tady odpadá:
        // v okamžiku setupu firma žádné doklady nemá, takže není co backfillovat.
        // Právě proto je tohle nejlevnější místo, kde režim určit.
        if ($accountingMode === 'double_entry') {
            $this->coaSeeder->seedForSupplier($supplierId);
        }

        // Seed default currencies (CZK + EUR) pro tohoto supplier
        $bank = isset($supplier['bank_account']) && is_array($supplier['bank_account']) ? $supplier['bank_account'] : null;
        $bankCurrency = $bank !== null ? strtoupper((string) ($bank['currency'] ?? $defaultCurrencyCode)) : null;

        $insertCur = $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default,
                                     account_number, bank_code, bank_name, iban, bic)
             VALUES (?, ?, ?, ?, ?, ?, 2, 1, 1, ?, ?, ?, ?, ?)'
        );

        $seedCurrencies = [
            ['CZK', 'CZK — výchozí', 'Kč', 'Česká koruna', 'Czech Koruna'],
            ['EUR', 'EUR — výchozí', '€',  'Euro',          'Euro'],
        ];
        $defaultCurrencyId = 0;
        foreach ($seedCurrencies as [$code, $label, $symbol, $nameCs, $nameEn]) {
            $isThisBank = $bank !== null && $bankCurrency === $code;
            $insertCur->execute([
                $supplierId, $code, $label, $symbol, $nameCs, $nameEn,
                $isThisBank ? ((string) ($bank['account_number'] ?? '') ?: null) : null,
                $isThisBank ? ((string) ($bank['bank_code'] ?? '') ?: null) : null,
                $isThisBank ? ((string) ($bank['bank_name'] ?? '') ?: null) : null,
                $isThisBank ? ((string) ($bank['iban'] ?? '') ?: null) : null,
                $isThisBank ? ((string) ($bank['bic'] ?? '') ?: null) : null,
            ]);
            $newCurId = (int) $pdo->lastInsertId();
            if ($code === $defaultCurrencyCode) $defaultCurrencyId = $newCurId;
        }

        if ($defaultCurrencyId === 0) {
            // Fallback: prvni currency
            $stmtCur = $pdo->prepare('SELECT id FROM currencies WHERE supplier_id = ? LIMIT 1');
            $stmtCur->execute([$supplierId]);
            $defaultCurrencyId = (int) $stmtCur->fetchColumn();
        }

        // Doplň supplier.default_currency_id, obnov FK
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')
            ->execute([$defaultCurrencyId, $supplierId]);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        return $supplierId;
    }

    /**
     * Detekuje veřejnou URL aplikace z hostiteleho requestu. Respektuje X-Forwarded-Proto/Host
     * (PSR-7 Uri už typicky tyto headery zohledňuje, ale Slim default ne — proto manual fallback).
     * Vrací null pokud Host header chybí (degeneruje na nedělání nic).
     */
    private function detectAppUrl(Request $request): ?string
    {
        $uri = $request->getUri();
        $host = $uri->getHost();
        if ($host === '') {
            return null;
        }

        $fwdProto = trim(strtolower($request->getHeaderLine('X-Forwarded-Proto')));
        $scheme = $fwdProto !== '' ? $fwdProto : $uri->getScheme();
        if ($scheme !== 'http' && $scheme !== 'https') {
            $scheme = 'http';
        }

        $port = $uri->getPort();
        $isStandard = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);

        return $scheme . '://' . $host . ($port !== null && !$isStandard ? ':' . $port : '');
    }

    /**
     * @param array<string,mixed> $admin
     * @param array<string,mixed>|null $supplier
     * @return array<string,list<string>>
     */
    private function validate(array $admin, ?array $supplier, bool $termsAccepted, SetupPasswordMode $passwordMode): array
    {
        $errors = [];

        if (!$termsAccepted) {
            $errors['terms_accepted'][] = 'Bez přijetí licenčního ujednání a obchodních podmínek nelze setup dokončit.';
        }

        if (empty($admin['name']) || !is_string($admin['name'])) {
            $errors['admin.name'][] = 'Jméno je povinné';
        }
        if (empty($admin['email']) || !filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['admin.email'][] = 'Platný email je povinný';
        }
        // S `admin.password_setup_link` si heslo nastaví zákazník sám přes
        // jednorázový odkaz, takže ho v požadavku nechceme ani mít.
        if ($passwordMode->requiresPlainPassword() && (empty($admin['password']) || !is_string($admin['password']))) {
            $errors['admin.password'][] = 'Heslo je povinné';
        }

        if ($supplier !== null) {
            $required = ['company_name', 'street', 'city', 'zip', 'email'];
            foreach ($required as $field) {
                if (empty($supplier[$field]) || !is_string($supplier[$field])) {
                    $errors["supplier.$field"][] = 'Povinné pole';
                }
            }
            if (!empty($supplier['email']) && !filter_var($supplier['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['supplier.email'][] = 'Neplatný email';
            }
        }

        return $errors;
    }

    private static function queryScalar(\PDO $pdo, string $sql): mixed
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw new \RuntimeException('Setup dotaz se nepodařilo provést.');
        }
        return $statement->fetchColumn();
    }
}
