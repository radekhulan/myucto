<?php

declare(strict_types=1);

// Composer autoloader (stejně jako vendor/autoload.php — sjednocuje vstupní bod)
require __DIR__ . '/../vendor/autoload.php';

// Bypass `final class` u tříd, které potřebujeme mockovat v unit testech
// (PurchaseInvoiceRepository, Connection a další). PHPUnit 13 nepodporuje
// mockování final tříd nativně; dg/bypass-finals to runtime přepíše.
//
// ── Proč allowPaths ─────────────────────────────────────────────────────────────
// `enable()` registruje userland stream wrapper na protokol `file://`, takže KAŽDÉ
// čtení souboru v celém procesu jde přes PHP-level wrapper a pouští se na něm
// `token_get_all` odstraňující `final`. Změřeno: přečtení `api/src` (971 souborů)
// trvá 27 ms bez toho a 900 ms s tím — 40× pomaleji. Nejvíc to bilo Architecture
// guardy, které si strom procházejí nezávisle.
//
// Zúžením na soubory, které se v testech reálně mockují (40 z 971), klesla celá sada
// ze 112 s na 98 s a Architecture suita ze 17,9 s na 3,6 s, při BITOVĚ SHODNÉM
// výsledku (7 018 testů, 28 192 asercí, 19 skipů).
//
// Seznam nemůže zestárnout v nebezpečném směru: pokus o mock `final` třídy mimo něj
// skončí hlasitým `ClassIsFinalException`, ne tichým průchodem. Zbytečná položka
// naopak nevadí. Regenerace: průnik `createMock|createStub|createPartialMock|
// createConfiguredMock|getMockBuilder` v `tests/` s `final class` v `src/`.
\DG\BypassFinals::allowPaths([
    '*/api/src/Infrastructure/Cache/RedisProbe.php',
    '*/api/src/Infrastructure/Config/Config.php',
    '*/api/src/Infrastructure/Database/Connection.php',
    '*/api/src/Middleware/FirstRunLockMiddleware.php',
    '*/api/src/Repository/ChartOfAccountsRepository.php',
    '*/api/src/Repository/AccountingModeRepository.php',
    '*/api/src/Repository/ClosingRepository.php',
    '*/api/src/Repository/EmailProfileRepository.php',
    '*/api/src/Repository/EmailTemplateRepository.php',
    '*/api/src/Repository/FuelingRepository.php',
    '*/api/src/Repository/InvoiceRepository.php',
    '*/api/src/Repository/PasskeyCredentialRepository.php',
    '*/api/src/Repository/PayrollEmployeeRepository.php',
    '*/api/src/Repository/PayrollMonthlyRecordRepository.php',
    '*/api/src/Repository/PostingRuleRepository.php',
    '*/api/src/Repository/Payroll/PayrollDocumentAccessLinkRepository.php',
    '*/api/src/Repository/Payroll/PayrollDocumentRepository.php',
    '*/api/src/Repository/Payroll/PayrollEmployerPolicyRepository.php',
    '*/api/src/Repository/Payroll/PayrollEmployerSettingsRepository.php',
    '*/api/src/Repository/Payroll/PayrollModuleStateRepository.php',
    '*/api/src/Repository/Payroll/PayrollPostingBatchRepository.php',
    '*/api/src/Repository/Payroll/PayrollStatutoryResultRepository.php',
    '*/api/src/Repository/PurchaseInvoiceRepository.php',
    '*/api/src/Repository/SigningProfileRepository.php',
    '*/api/src/Repository/SupplierDomainRepository.php',
    '*/api/src/Repository/TaxSubmissionEpoRepository.php',
    '*/api/src/Repository/EpoDirectSubmissionRepository.php',
    '*/api/src/Repository/TaxConstantsRepository.php',
    '*/api/src/Repository/TripRepository.php',
    '*/api/src/Repository/UserSupplierRepository.php',
    '*/api/src/Security/PermissionResolver.php',
    '*/api/src/Security/UserRoleProfile.php',
    '*/api/src/Service/Accounting/DocumentAutoPoster.php',
    '*/api/src/Service/Accounting/DocumentLockService.php',
    '*/api/src/Service/Accounting/PostingService.php',
    '*/api/src/Service/Auth/ApiTokenService.php',
    '*/api/src/Service/Auth/BruteForceGuard.php',
    '*/api/src/Service/Auth/EmailOtpService.php',
    '*/api/src/Service/Auth/LoginSessionIssuer.php',
    '*/api/src/Service/Auth/MfaOfferService.php',
    '*/api/src/Service/Auth/MfaPolicyService.php',
    '*/api/src/Service/Auth/MfaRecoveryCodeService.php',
    '*/api/src/Service/Auth/MfaStepUpProofStore.php',
    '*/api/src/Service/Auth/MfaProtectedOperationService.php',
    '*/api/src/Service/Auth/MfaStepUpService.php',
    '*/api/src/Service/Auth/PasskeyService.php',
    '*/api/src/Service/Auth/PasskeySessionTransitionService.php',
    '*/api/src/Service/Auth/PasswordHasher.php',
    '*/api/src/Service/Auth/SecretEncryption.php',
    '*/api/src/Service/Auth/SessionCookieFactory.php',
    '*/api/src/Service/Auth/SessionLockPreferenceService.php',
    '*/api/src/Service/Auth/SessionLockService.php',
    '*/api/src/Service/Auth/SessionManager.php',
    '*/api/src/Service/Auth/TotpService.php',
    '*/api/src/Service/Auth/TrustedDeviceService.php',
    '*/api/src/Service/Auth/WebAuthnCeremonyStore.php',
    '*/api/src/Service/Auth/MfaStepUpProof.php',
    '*/api/src/Service/Bank/Match/CounterpartyMapService.php',
    '*/api/src/Service/Captcha/TurnstileVerifier.php',
    '*/api/src/Service/Currency/CnbExchangeRateClient.php',
    '*/api/src/Service/Epo/EpoConfirmationPartsArchiver.php',
    '*/api/src/Service/Epo/TaxSubmissionDocumentService.php',
    '*/api/src/Service/Currency/ExchangeRateApplier.php',
    '*/api/src/Service/Document/DocumentStorage.php',
    '*/api/src/Service/Currency/FixedExchangeRateService.php',
    '*/api/src/Service/Export/MergedInvoicePdfExporter.php',
    '*/api/src/Service/Export/PohodaXmlExporter.php',
    '*/api/src/Service/Import/AiPdfExtractor.php',
    '*/api/src/Service/Import/ClientResolver.php',
    '*/api/src/Service/Import/InvoiceExtractionRouter.php',
    '*/api/src/Service/Import/InvoiceImportService.php',
    '*/api/src/Service/Import/IsdocParser.php',
    '*/api/src/Service/Import/IsdocToPurchaseInvoiceMapper.php',
    '*/api/src/Service/Import/PdfIsdocExtractor.php',
    '*/api/src/Service/Import/PurchaseInvoiceCnbApplier.php',
    '*/api/src/Service/Import/PurchaseInvoicePdfArchiver.php',
    '*/api/src/Service/Invoice/AutoIssueAndSendService.php',
    '*/api/src/Service/Invoice/FinalFromProformaCreator.php',
    '*/api/src/Service/Invoice/PurchaseInvoiceCalculator.php',
    '*/api/src/Service/IpMatcher.php',
    '*/api/src/Service/License/LicenseClient.php',
    '*/api/src/Service/License/LicenseState.php',
    '*/api/src/Service/License/LicenseService.php',
    '*/api/src/Service/Mail/Mailer.php',
    '*/api/src/Service/Payroll/Document/Delivery/PayrollDeliveryRecipientResolver.php',
    '*/api/src/Service/Payroll/Document/PayrollDocumentDeliveryLedgerService.php',
    '*/api/src/Service/Payroll/Document/PayrollDocumentStorage.php',
    '*/api/src/Service/Oss/OssLedgerService.php',
    '*/api/src/Service/Payroll/PayrollPeriodOwnershipService.php',
    '*/api/src/Service/Payroll/Document/ApprovedRevisionPayslipBatchService.php',
    '*/api/src/Service/Payroll/Document/AnnualTaxCertificatePdfRenderer.php',
    '*/api/src/Service/Payroll/Document/AnnualTaxCertificateDocumentData.php',
    '*/api/src/Service/Payroll/Document/AnnualTaxCertificateSnapshotBuilder.php',
    '*/api/src/Service/Payroll/Document/PayrollDocumentService.php',
    '*/api/src/Service/Payroll/Payment/PayrollEnforcementLiabilityMaterializer.php',
    '*/api/src/Service/Payroll/Payment/PayrollHealthInsuranceLiabilityMaterializer.php',
    '*/api/src/Service/Payroll/Payment/PayrollIncomeTaxLiabilityMaterializer.php',
    '*/api/src/Service/Payroll/Payment/PayrollInsolvencyLiabilityMaterializer.php',
    '*/api/src/Service/Payroll/Payment/PayrollSocialInsuranceLiabilityMaterializer.php',
    '*/api/src/Service/Payroll/Settings/PayrollSetupCheckService.php',
    '*/api/src/Service/Payroll/Settings/PayrollSetupFeaturesResolver.php',
    '*/api/src/Service/Payroll/Posting/PayrollApprovedRevisionPostingService.php',
    '*/api/src/Service/Payroll/Posting/PayrollPostingAdapter.php',
    '*/api/src/Service/Payroll/Posting/PayrollPostingLineBuilder.php',
    '*/api/src/Service/Payroll/Submission/Jmhz/JmhzScenario1DocumentService.php',
    '*/api/src/Service/Payroll/Submission/Jmhz/JmhzScenario1XmlValidator.php',
    '*/api/src/Service/Payroll/Submission/Jmhz/JmhzSubmissionGuidFactory.php',
    '*/api/src/Service/Pdf/InvoicePdfRenderer.php',
    '*/api/src/Service/Signing/PersonalCertificateVaultService.php',
    '*/api/src/Service/Auth/SecretEncryption.php',
    '*/api/src/Service/Signing/Pdf/PdfSigningService.php',
    '*/api/src/Service/System/EnvironmentCheckService.php',
    '*/api/src/Service/Tenant/DomainVerificationService.php',
    '*/api/src/Service/Tenant/SupplierAccessResolver.php',
    '*/api/src/Service/Update/VersionService.php',
    '*/api/src/Repository/Payroll/PayrollDeadlineOverviewRepository.php',
    '*/api/src/Repository/Payroll/PayrollRegistrationChangeProposalRepository.php',
    '*/api/src/Repository/Payroll/PayrollSicknessCaseRepository.php',
    '*/api/src/Repository/Payroll/PayrollSigningProfileRepository.php',
    '*/api/src/Repository/Payroll/PayrollSubmissionRepository.php',
    '*/api/src/Repository/Payroll/PayrollSubmissionTransportAttemptRepository.php',
    '*/api/src/Repository/Submission/SubmissionChannelCredentialRepository.php',
    '*/api/src/Service/ActivityLogger.php',
    '*/api/src/Service/Payroll/PayrollModuleAccess.php',
    '*/api/src/Service/Payroll/Submission/HealthInsurance/HealthInsuranceIsdsSubmissionService.php',
    '*/api/src/Service/Payroll/Submission/Jmhz/JmhzFrozenPayloadReader.php',
    '*/api/src/Service/Payroll/Submission/Jmhz/Transport/JmhzDispatchService.php',
    '*/api/src/Service/Payroll/Submission/Jmhz/Transport/JmhzProtocolExplainer.php',
    '*/api/src/Service/Payroll/Submission/PayrollDeadlineAssessmentService.php',
    '*/api/src/Service/Payroll/Submission/PayrollSubmissionService.php',
    '*/api/src/Service/Payroll/Submission/Registration/Change/PayrollRegistrationChangeDetectionService.php',
    '*/api/src/Service/Payroll/Submission/Registration/PayrollRegistrationTransportService.php',
    '*/api/src/Service/Submission/Channel/Isds/Gateway/IsdsGatewayRegistrationService.php',
    '*/api/src/Service/Submission/Channel/Isds/MobileKeyIsdsAuthenticator.php',
    '*/api/src/Service/Submission/IsdsMobileCredentialService.php',
    '*/api/src/Service/Submission/SubmissionCredentialService.php',
    '*/api/src/Service/Submission/SubmissionOutboxService.php',
]);
\DG\BypassFinals::enable();

// Lokální cfg.local.php může zapínat veřejný demo režim. Testy ale ověřují běžné
// mutační chování aplikace, proto jej pro celý testovací proces explicitně vypnou.
// --- Časová zóna procesu ---
// Aplikace si ji nastavuje sama v Config::load() z `app.timezone`. Tady se nastavuje
// znovu proto, že testy sahají na `date()` i v místech, kam se žádná konfigurace
// nenačítá (data fixtur, pomocné asserty), a php.ini na CI runneru žádnou zónu nemá.
//
// Rozdíl není kosmetický: Connection z ní odvozuje zónu session a PDO se mezi testy
// SDÍLÍ, takže by `CURDATE()` mezi půlnocí a 2:00 pražského času ukazovalo o den zpět
// proti PHP a rozešly by se všechny dotazy porovnávající data (splatné šablony, denní
// zámek obnovy licence, platnost OTP kódů).
$__tzCfgPath = \MyInvoice\Bootstrap::rootDir() . DIRECTORY_SEPARATOR . 'cfg.php';
$__tzCfg = is_file($__tzCfgPath) ? require $__tzCfgPath : [];
date_default_timezone_set(
    is_array($__tzCfg) ? (string) ($__tzCfg['app']['timezone'] ?? 'Europe/Prague') : 'Europe/Prague'
);
unset($__tzCfgPath, $__tzCfg);

putenv('MYINVOICE_DEMO_ENABLED=false');
$_ENV['MYINVOICE_DEMO_ENABLED'] = 'false';
$_SERVER['MYINVOICE_DEMO_ENABLED'] = 'false';

// --- Izolace test databáze (BEZPEČNOSTNÍ POJISTKA) ---
// Integrační testy zapisují do DB (klonují supplier, zakládají faktury/období).
// Ostrá dev DB z cfg.php obsahuje reálná účetní data → testy proti ní běžet NESMÍ.
// Proto: pokud není MYINVOICE_DB_NAME explicitně nastaveno, vynutíme `<cfg-db>_test`
// (např. `myucto` → `myucto_test`). A tvrdě odmítneme běh proti jakékoli DB, jejíž
// jméno nekončí na `_test` — tím je nemožné testy omylem pustit proti ostrým datům.
$__rootDir = \MyInvoice\Bootstrap::rootDir();

// cfg.php je gitignorovaná (lokální/produkční config) → v CI neexistuje. Bez ní
// nemáme reálnou DB a integrační testy se stejně soft-skipnou (markTestSkipped
// 'cfg.php neexistuje'). Bezpečnostní pojistka proti ostré DB má smysl jen tam,
// kde cfg.php reálně je. Proto require nesmí být fatální.
//
$__cfgPath = $__rootDir . DIRECTORY_SEPARATOR . 'cfg.php';
$__cfg     = is_file($__cfgPath) ? require $__cfgPath : [];
$__realDb  = is_array($__cfg) ? (string) ($__cfg['db']['name'] ?? '') : '';

// ZÁMĚRNĚ čteme jen cfg.php, i když aplikace přes Config::load() mergne ještě
// cfg.local.php (pořadí defaults → cfg.php → cfg.local.php → DATA_DIR → ENV).
// Testovací DB musí být ŘÍZENÝ fixture odvozený od kanonického jména projektu,
// ne kopie toho, na co má vývojář zrovna přepnutou aplikaci. Vyzkoušeno opačně:
// odvození z mergnuté konfigurace přesměrovalo sadu na klon ostrých dat a rozsvítilo
// 47 testů, které mlčky počítají s výchozím stavem (uložený licenční klíč, existující
// bankovní import, jiné role uživatelů). To nejsou vady těch testů — sada prostě
// potřebuje známý výchozí bod.
//
// Rozejití obou souborů ale mate: člověk klikne v aplikaci nad jednou databází a testy
// mu běží nad úplně jinou. Proto to aspoň nahlas oznámíme.
$__localPath = $__rootDir . DIRECTORY_SEPARATOR . 'cfg.local.php';
if (is_file($__localPath)) {
    $__localCfg = require $__localPath;
    $__localDb  = is_array($__localCfg) ? (string) ($__localCfg['db']['name'] ?? '') : '';
    if ($__localDb !== '' && $__localDb !== $__realDb) {
        // Hláška MUSÍ jmenovat databázi, nad kterou se poběží doopravdy. Dřív tvrdila
        // natvrdo `<cfg-db>_test` i tehdy, když MYINVOICE_DB_NAME mířilo jinam —
        // souběžní agenti pak hledali kontaminaci sdílené DB, kterou nikdo nepoužil.
        $__envDb = (string) (getenv('MYINVOICE_DB_NAME') ?: '');
        $__announcedDb = $__envDb !== ''
            ? "'{$__envDb}' (MYINVOICE_DB_NAME)"
            : "'{$__realDb}_test' (odvozeno z cfg.php)";
        fwrite(STDERR, "[TEST DB] Pozor: aplikace jede nad '{$__localDb}' (cfg.local.php),"
            . " ale testy běží nad {$__announcedDb}.\n");
        unset($__envDb, $__announcedDb);
    }
    unset($__localCfg, $__localDb);
}
unset($__localPath);

$__chosenDb = getenv('MYINVOICE_DB_NAME');
if (!is_string($__chosenDb)) {
    $__chosenDb = '';
}

// ParaTest dává každému workeru unikátní TEST_TOKEN. Paralelní runner mu přes
// prefix přiřadí vlastní klon testovací DB, takže testy s pevnými ID i sdílené
// Connection::pdo() nikdy nemíchají data mezi procesy. Prefix nastavuje pouze
// api/bin/test-parallel.php; běžné PHPUnit spuštění se chová beze změny.
$__parallelPrefix = getenv('MYINVOICE_PARALLEL_DB_PREFIX');
$__testToken = getenv('TEST_TOKEN');
if (is_string($__parallelPrefix) && $__parallelPrefix !== '') {
    // ParaTest nejdřív načte konfiguraci v řídicím procesu bez tokenu; ten testy
    // nespouští. Worker má TEST_TOKEN vždy, jinak by volba --no-test-tokens byla
    // nebezpečná a runner ji nepoužívá.
    if (is_string($__testToken) && $__testToken !== '' && preg_match('/^[A-Za-z0-9_]+$/D', $__testToken) !== 1) {
        fwrite(STDERR, "[TEST SAFETY] ParaTest worker nemá bezpečný TEST_TOKEN.\n");
        exit(1);
    }
    if (is_string($__testToken) && $__testToken !== '') {
        $__chosenDb = $__parallelPrefix . '_' . $__testToken . '_test';
        if (strlen($__chosenDb) > 64 || preg_match('/^[A-Za-z0-9_]+$/D', $__chosenDb) !== 1) {
            fwrite(STDERR, "[TEST SAFETY] Neplatné jméno paralelní testovací DB.\n");
            exit(1);
        }
        putenv('MYINVOICE_DB_NAME=' . $__chosenDb);
        $_ENV['MYINVOICE_DB_NAME'] = $__chosenDb;
        $_SERVER['MYINVOICE_DB_NAME'] = $__chosenDb;
    }
}
unset($__parallelPrefix, $__testToken);

// Bez cfg.php a bez explicitní MYINVOICE_DB_NAME (typicky CI) přeskoč celou
// izolační logiku i kontrolu migrací — není žádná DB, integrační testy se
// soft-skipnou samy. Pojistka i migrace se aktivují jen když je co chránit.
if ($__realDb !== '' || $__chosenDb !== '') {
    if ($__chosenDb === '') {
        $__chosenDb = $__realDb . '_test';
        putenv('MYINVOICE_DB_NAME=' . $__chosenDb);
        $_ENV['MYINVOICE_DB_NAME']    = $__chosenDb;
        $_SERVER['MYINVOICE_DB_NAME'] = $__chosenDb;
    }

    if ($__chosenDb === $__realDb || !str_ends_with($__chosenDb, '_test')) {
        fwrite(STDERR, "\n[TEST SAFETY] Integrační testy odmítnuty proti DB '{$__chosenDb}'.\n"
            . "Testy smí běžet jen proti DB s příponou _test (nikdy proti ostré '{$__realDb}').\n"
            . "Vytvoř/naklonuj test DB a spusť s MYINVOICE_DB_NAME=<neco>_test.\n\n");
        exit(1);
    }

    __applyPendingTestMigrations($__rootDir, $__cfg, $__chosenDb);
    __normalizeTestTenant($__cfg, $__chosenDb);
}

unset($__rootDir, $__cfgPath, $__cfg, $__realDb, $__chosenDb);

/**
 * Automatická aktualizace schématu test DB.
 *
 * Test DB je klon ostré DB (nese `migrations` tabulku na HEADu). Když v db/migrations/
 * přibudou nové soubory, aplikují se sem SAMY při dalším běhu testů — jen ty nové
 * (idempotentně, supplier 1 z klonu existuje, takže i datové migrace projdou).
 * Běží jen když nějaká migrace skutečně chybí → v běžném běhu je to jedna COUNT query.
 */
/**
 * Sjednotí konfiguraci ambientního tenanta (supplier s nejnižším id) v TEST DB.
 *
 * Integrační testy účetnictví/DPH si suppliera neberou vlastního — sahají na
 * `SELECT id FROM supplier ORDER BY id LIMIT 1` a mlčky předpokládají firmu, jakou
 * měl původní klon ostré DB: podvojné účetnictví, plátce DPH, zapnutý sklad.
 * Když se test DB postaví ze setup.php + sample.php, vyjde z toho neplátce v daňové
 * evidenci → requireDoubleEntry() vrací 403 a padne ~250 testů na místech, která
 * s příčinou nesouvisí.
 *
 * Řešíme to tady, ne v každém testu: je to vlastnost PROSTŘEDÍ, ne jednotlivého
 * případu. Testy, které potřebují jiný režim, si suppliera klonují explicitně
 * (cloneSupplier('tax_evidence') / IsolatedSupplierTrait) a tohle je neovlivní.
 *
 * Bezpečnost: voláno až ZA izolační pojistkou výše, takže `$chosenDb` vždy končí
 * na `_test`. Na ostrou DB se tenhle UPDATE nemůže dostat.
 */
function __normalizeTestTenant(array $cfg, string $chosenDb): void
{
    if (!str_ends_with($chosenDb, '_test')) {
        return; // paranoia: nikdy nesahej na ne-test DB
    }
    try {
        $db  = $cfg['db'];
        $pdo = new \PDO(
            "mysql:host={$db['host']};port=" . (int) ($db['port'] ?? 3306) . ";dbname={$chosenDb};charset=utf8mb4",
            (string) $db['user'],
            (string) ($db['pass'] ?? ''),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
        $supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($supplierId === 0) {
            return; // prázdná DB — integrační testy se soft-skipnou samy
        }
        // COALESCE: klon ostré DB si nechá vlastní identitu, doplňujeme jen co CHYBÍ.
        // Bez IČ/DIČ/FÚ/typu datovky neprojde XML výkazů (DPH, KH, SHV) XSD validací
        // EPO — `dic` je ve VetaP povinné vždy. Hodnoty jsou stejné jako v ostatních
        // testech výkazů (financial_office_code 451), ať je fixture konzistentní.
        $pdo->prepare(
            "UPDATE supplier
                SET accounting_mode       = 'double_entry',
                    is_vat_payer          = 1,
                    taxpayer_type         = 'po',
                    stock_enabled         = 1,
                    ic                    = COALESCE(ic, '12345678'),
                    dic                   = COALESCE(dic, 'CZ1234567890'),
                    financial_office_code = COALESCE(financial_office_code, '451'),
                    data_box_type         = COALESCE(data_box_type, 'P'),
                    vat_period            = COALESCE(vat_period, 'monthly')
              WHERE id = ?"
        )->execute([$supplierId]);

        // Bankovní testy resolvují tenanta přes `currencies.account_number` a skipují se,
        // když ho CZK měna nemá — v klonu ostré DB tam byl, ve vygenerovaném seedu ne.
        // Bez tohohle se TICHO přeskočí ~290 testů (BankPostingTestCase::ACCOUNT a spol.).
        // `bank_code` tamtéž: rekonciliace e-mailových avíz porovnává kód banky kandidáta
        // proti kódu z výpisu a při prázdném kódu guard VYPÍNÁ (považuje ho za neznámý).
        // Bez něj by testTakeOverWhenBankCodeDiffers přestal testovat to, co má —
        // převzetí by proběhlo i pro cizí banku. Hodnota je konzistentní se
        // `supplier_bank_accounts` řádkem zakládaným o pár řádků níž.
        $pdo->prepare(
            "UPDATE currencies
                SET account_number = COALESCE(account_number, '1700000006'),
                    bank_code      = COALESCE(bank_code, '2250')
              WHERE supplier_id = ? AND code = 'CZK'"
        )->execute([$supplierId]);

        // EUR měna tenanta — cizoměnové scénáře (DPH/KH, klasifikace výdajů, kurzy) ji
        // berou jako danost. setup.php ji podle dokumentace zakládá spolu s CZK, ale
        // vygenerovaný testovací seed má jen CZK.
        // POZOR: `currencies` NEMÁ unique klíč na (supplier_id, code) — INSERT IGNORE by
        // zakládal další EUR při každém běhu testů. Proto explicitní NOT EXISTS.
        $pdo->prepare(
            "INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active)
             SELECT ?, 'EUR', 'EUR', '€', 'euro', 'euro', 2, 1
              WHERE NOT EXISTS (SELECT 1 FROM currencies WHERE supplier_id = ? AND code = 'EUR')"
        )->execute([$supplierId, $supplierId]);

        // Detekce vlastních převodů porovnává protistranu proti supplier_bank_accounts.
        // Testy si registrují jen DRUHÝ účet a u prvního spoléhají, že tenant svůj hlavní
        // účet má (v klonu ostré DB měl). Bez něj vrací detektor no_rule místo own_transfer.
        //
        // Nejdřív UPDATE, teprve pak INSERT IGNORE: unique klíč je
        // (supplier_id, account_canonical, bank_code_norm), takže samotný INSERT by na
        // starším klonu jen přidal DRUHÝ řádek a ten původní (s jiným číslem) by tam
        // zůstal. Řádek se proto adresuje přes `label`, ale pouze pokud cílový účet
        // ještě neexistuje; jinak by UPDATE narazil na uq_sba_account. Následný
        // upsert v obou případech aktivuje právě cílový syntetický účet.
        $pdo->prepare(
            "UPDATE supplier_bank_accounts target
         LEFT JOIN supplier_bank_accounts desired
                ON desired.supplier_id = target.supplier_id
               AND desired.account_canonical = '1700000006'
               AND desired.bank_code_norm = '2250'
               AND desired.id <> target.id
                SET target.account_number    = '1700000006',
                    target.account_canonical = '1700000006',
                    target.bank_code         = '2250',
                    target.bank_code_norm    = '2250',
                    target.currency          = 'CZK',
                    target.is_active         = 1
              WHERE target.supplier_id = ?
                AND target.label = 'Hlavní testovací účet'
                AND desired.id IS NULL"
        )->execute([$supplierId]);
        $pdo->prepare(
            "INSERT INTO supplier_bank_accounts
                (supplier_id, label, account_number, bank_code, bank_code_norm, currency,
                 account_canonical, kind, source, is_active)
             VALUES (?, 'Hlavní testovací účet', '1700000006', '2250', '2250', 'CZK',
                     '1700000006', 'current', 'manual', 1)
             ON DUPLICATE KEY UPDATE
                account_number = VALUES(account_number),
                bank_code = VALUES(bank_code),
                bank_code_norm = VALUES(bank_code_norm),
                currency = VALUES(currency),
                is_active = 1"
        )->execute([$supplierId]);

        // Od tohoto bodu izolovaně po krocích: jedna chybějící tabulka/sloupec nesmí
        // shodit zbytek normalizace. Přesně to se stalo při zavádění protistran —
        // překlep ve sloupci (`email` místo `main_email`) tiše zabil i seed období
        // a projevilo se to jen tím, že skipů neubylo.
        $step = static function (string $label, callable $fn): void {
            try {
                $fn();
            } catch (\Throwable $e) {
                fwrite(STDERR, "[TEST DB] Krok normalizace '{$label}' selhal: " . $e->getMessage() . "\n");
            }
        };

        // Protistrany. Bez nich se TICHO přeskočí ~109 integračních testů
        // („Supplier #1 nemá žádné klienty", „Chybí client/user pro supplier.",
        // „Chybí vendor/CZK currency/user." a varianty). Skipy jsou nebezpečnější
        // než červená sada — maskují reálná selhání: §74b test se za jedním z nich
        // schovával a padal na produkčně závislém fixture (viz N-022).
        //
        // Data jsou ZÁMĚRNĚ syntetická (AGENTS.md: repo je veřejné). Idempotentní přes
        // NOT EXISTS — `clients` nemá unique klíč na (supplier_id, company_name),
        // takže INSERT IGNORE by zakládal duplicity při každém běhu.
        $step('protistrany', static function () use ($pdo, $supplierId): void {
            $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
            $czkStmt = $pdo->prepare("SELECT id FROM currencies WHERE supplier_id = ? AND code = 'CZK' LIMIT 1");
            $czkStmt->execute([$supplierId]);
            $czkId = (int) ($czkStmt->fetchColumn() ?: 0);
            if ($czId === 0 || $czkId === 0) {
                return;
            }
            $insertParty = $pdo->prepare(
                "INSERT INTO clients
                    (supplier_id, company_name, street, city, zip, country_id, currency_default_id,
                     ic, dic, main_email, is_customer, is_vendor)
                 SELECT ?, ?, 'Testovací 1', 'Praha', '11000', ?, ?, ?, ?, ?, ?, ?
                  WHERE NOT EXISTS (
                      SELECT 1 FROM clients WHERE supplier_id = ? AND company_name = ?
                  )"
            );
            // Odběratel i dodavatel zvlášť — část testů hledá výslovně is_vendor = 1.
            $insertParty->execute([
                $supplierId, 'Testovací odběratel s.r.o.', $czId, $czkId,
                '25596641', 'CZ25596641', 'odberatel@example.test', 1, 0,
                $supplierId, 'Testovací odběratel s.r.o.',
            ]);
            $insertParty->execute([
                $supplierId, 'Testovací dodavatel s.r.o.', $czId, $czkId,
                '27082440', 'CZ27082440', 'dodavatel@example.test', 0, 1,
                $supplierId, 'Testovací dodavatel s.r.o.',
            ]);
        });

        // DRUHÁ FIRMA. Bez ní se tiše přeskočí 9 tenantových testů — a to jsou zrovna
        // ty, které ověřují, že se data jedné firmy nedostanou do druhé (cross-tenant
        // FK, orphan check, převzetí bankovního avíza, backfill pravidel). Skip u
        // izolačního testu je nejhorší druh skipu: tvrdí, že izolace je otestovaná.
        //
        // Firma je prázdná skořápka — testy si do ní zakládají vlastní data a ověřují,
        // že je supplier #1 nevidí. Idempotentní přes NOT EXISTS na company_name.
        $step('druha-firma', static function () use ($pdo, $supplierId): void {
            $src = $pdo->prepare(
                'SELECT country_id, default_currency_id, default_vat_rate_id FROM supplier WHERE id = ?'
            );
            $src->execute([$supplierId]);
            $ref = $src->fetch(\PDO::FETCH_ASSOC);
            if (!$ref) {
                return;
            }
            $pdo->prepare(
                "INSERT INTO supplier
                    (company_name, street, city, zip, country_id, email,
                     default_currency_id, default_vat_rate_id)
                 SELECT 'Druhá testovací firma s.r.o.', 'Testovací 2', 'Brno', '60200', ?,
                        'druha@example.test', ?, ?
                  WHERE NOT EXISTS (
                      SELECT 1 FROM supplier WHERE company_name = 'Druhá testovací firma s.r.o.'
                  )"
            )->execute([$ref['country_id'], $ref['default_currency_id'], $ref['default_vat_rate_id']]);

            // Měny jsou per-firma. Bez vlastní CZK spadne cokoliv, co pod druhou firmou
            // zakládá doklad nebo klienta, na „Currency not found" (HTTP 400) — a to
            // vypadá jako chyba v testu, ne jako chybějící fixture. `default_currency_id`
            // schválně zůstává na měně první firmy, aby úklid nenarazil na cyklický FK.
            $second = $pdo->query(
                "SELECT id FROM supplier WHERE company_name = 'Druhá testovací firma s.r.o.' LIMIT 1"
            )->fetchColumn();
            if ($second === false) {
                return;
            }
            $pdo->prepare(
                "INSERT INTO currencies
                    (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
                 SELECT ?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1
                  WHERE NOT EXISTS (
                      SELECT 1 FROM currencies WHERE supplier_id = ? AND code = 'CZK'
                  )"
            )->execute([(int) $second, (int) $second]);

            // Druhá firma je záměrně FYZICKÁ OSOBA. Kromě tenantové izolace tím vzniká
            // jediný FO tenant v testovací DB, bez kterého skipoval XSD test přiznání
            // k dani z příjmů FO (DPFDP7) — tedy zrovna kontrola, že se generuje
            // schématicky platné podání. IČ/DIČ musí být jiné než u první firmy.
            $pdo->prepare(
                "UPDATE supplier
                    SET taxpayer_type         = 'fo',
                        is_vat_payer          = 0,
                        accounting_mode       = 'tax_evidence',
                        ic                    = COALESCE(NULLIF(ic, ''), '87654321'),
                        dic                   = COALESCE(NULLIF(dic, ''), 'CZ8765432109'),
                        financial_office_code = COALESCE(NULLIF(financial_office_code, ''), '451'),
                        data_box_type         = COALESCE(NULLIF(data_box_type, ''), 'F'),
                        sest_jmeno            = COALESCE(NULLIF(sest_jmeno, ''), 'Jana'),
                        sest_prijmeni         = COALESCE(NULLIF(sest_prijmeni, ''), 'Testovací')
                  WHERE id = ?"
            )->execute([(int) $second]);
        });

        // Každý supplier musí mít baseline řádek historie plátcovství (jako u ostrých
        // firem migrace 1180) — reporty čtou stav k datu z historie a firma bez řádku
        // by potichu jela přes živý fallback, což je přesně stav, který testy nemají
        // reprodukovat.
        $step('vat-status-baseline', static function () use ($pdo): void {
            $pdo->exec(
                "INSERT IGNORE INTO supplier_vat_status_history (supplier_id, effective_from, is_vat_payer, is_identified)
                 SELECT s.id, '1900-01-01', s.is_vat_payer, s.is_identified FROM supplier s
                  WHERE NOT EXISTS (
                      SELECT 1 FROM supplier_vat_status_history h WHERE h.supplier_id = s.id
                  )"
            );
        });

        // Ukázkové doklady. Bez nich skipují testy, které potřebují JAKÝKOLI reálný
        // doklad — rozlišení syntetického a skutečného ID v deníku, kolizní scénář
        // cross-tenant, klonování faktury, vazba PF ↔ DMS.
        //
        // Rok 2095 je zvolený schválně: mimo všechna reportovací okna (12 měsíců zpět,
        // aktuální účetní období) A ZÁROVEŇ mimo fixture roky ostatních testů. 2098 a
        // 2099 už obsazené jsou — první pokus se seedem v roce 2098 rozbil
        // `BackfillAccountingTest`, který si tam zakládá vlastní doklady a počítá,
        // kolik jich backfill pokryje.
        //
        // `booked_at` je vyplněné: nezaúčtovaný doklad by se objevil ve frontě
        // „k zaúčtování" a rozbil testy, které v ní počítají položky podle typu.
        $step('ukazkove-doklady', static function () use ($pdo, $supplierId): void {
            $czk = $pdo->prepare("SELECT id FROM currencies WHERE supplier_id = ? AND code = 'CZK' LIMIT 1");
            $czk->execute([$supplierId]);
            $czkId = (int) ($czk->fetchColumn() ?: 0);
            $userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
            $vatRateId = (int) ($pdo->query('SELECT default_vat_rate_id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
            if ($czkId === 0 || $userId === 0) {
                return;
            }

            $partyFor = static function (int $sid, string $column) use ($pdo): int {
                $q = $pdo->prepare("SELECT id FROM clients WHERE supplier_id = ? AND {$column} = 1 ORDER BY id LIMIT 1");
                $q->execute([$sid]);
                return (int) ($q->fetchColumn() ?: 0);
            };

            // Vydaná faktura s položkou — pro supplier #1 i pro druhou firmu.
            foreach ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 2')->fetchAll(\PDO::FETCH_COLUMN) as $sid) {
                $sid = (int) $sid;
                $clientId = $partyFor($sid, 'is_customer') ?: $partyFor($supplierId, 'is_customer');
                if ($clientId === 0) {
                    continue;
                }
                $vs = "2095" . str_pad((string) $sid, 4, '0', STR_PAD_LEFT);
                $exists = $pdo->prepare('SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ? LIMIT 1');
                $exists->execute([$sid, $vs]);
                if ($exists->fetchColumn() !== false) {
                    continue;
                }
                $pdo->prepare(
                    "INSERT INTO invoices
                        (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                         currency_id, status, total_without_vat, total_vat, total_with_vat, booked_at, created_by)
                     VALUES ('invoice', ?, ?, ?, '2095-06-15', '2095-06-15', '2095-06-30', ?, 'issued', 1000, 210, 1210, '2095-06-15 10:00:00', ?)"
                )->execute([$vs, $clientId, $sid, $czkId, $userId]);
                $invoiceId = (int) $pdo->lastInsertId();

                if ($vatRateId > 0) {
                    $pdo->prepare(
                        "INSERT INTO invoice_items
                            (invoice_id, description, quantity, unit_price_without_vat, vat_rate_id,
                             vat_rate_snapshot, total_without_vat, total_vat, total_with_vat)
                         VALUES (?, 'Testovací položka', 1, 1000, ?, 21, 1000, 210, 1210)"
                    )->execute([$invoiceId, $vatRateId]);
                }
            }

            // Cizoměnová vydaná faktura — ISDOC XSD test bez ní skipuje a nekontroluje
            // se tedy zrovna ta větev exportu, kde se pracuje s kurzem.
            $eur = $pdo->prepare("SELECT id FROM currencies WHERE supplier_id = ? AND code = 'EUR' LIMIT 1");
            $eur->execute([$supplierId]);
            $eurId = (int) ($eur->fetchColumn() ?: 0);
            $customerId = $partyFor($supplierId, 'is_customer');
            if ($eurId > 0 && $customerId > 0 && $vatRateId > 0) {
                $exists = $pdo->prepare('SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ? LIMIT 1');
                $exists->execute([$supplierId, '2095EUR1']);
                if ($exists->fetchColumn() === false) {
                    $pdo->prepare(
                        "INSERT INTO invoices
                            (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                             currency_id, exchange_rate, status, total_without_vat, total_vat, total_with_vat,
                             booked_at, created_by)
                         VALUES ('invoice', '2095EUR1', ?, ?, '2095-06-15', '2095-06-15', '2095-06-30',
                                 ?, 25.00, 'issued', 100, 21, 121, '2095-06-15 10:00:00', ?)"
                    )->execute([$customerId, $supplierId, $eurId, $userId]);
                    $pdo->prepare(
                        "INSERT INTO invoice_items
                            (invoice_id, description, quantity, unit_price_without_vat, vat_rate_id,
                             vat_rate_snapshot, total_without_vat, total_vat, total_with_vat)
                         VALUES (?, 'Testovací položka EUR', 1, 100, ?, 21, 100, 21, 121)"
                    )->execute([(int) $pdo->lastInsertId(), $vatRateId]);
                }
            }

            // Přijatá faktura pro supplier #1 — vazba PF ↔ DMS.
            $vendorId = $partyFor($supplierId, 'is_vendor');
            if ($vendorId > 0) {
                $exists = $pdo->prepare('SELECT id FROM purchase_invoices WHERE supplier_id = ? AND vendor_invoice_number = ? LIMIT 1');
                $exists->execute([$supplierId, 'SEED-2095-001']);
                if ($exists->fetchColumn() === false) {
                    $pdo->prepare(
                        "INSERT INTO purchase_invoices
                            (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                             due_date, received_at, currency_id, vendor_snapshot, total_without_vat, total_vat,
                             total_with_vat, status, booked_at, created_by)
                         VALUES (?, ?, 'SEED-2095-001', 'invoice', '2095-06-15', '2095-06-15', '2095-06-30',
                                 '2095-06-15', ?, '{}', 1000, 210, 1210, 'received', '2095-06-15 10:00:00', ?)"
                    )->execute([$supplierId, $vendorId, $czkId, $userId]);
                    $purchaseId = (int) $pdo->lastInsertId();

                    // Položka je POVINNÁ, ne kosmetika: doklad bez řádků neprojde ISDOC
                    // XSD validací (`InvoiceLines` nesmí být prázdné) a shodí export test,
                    // který kontroluje VŠECHNY přijaté faktury v DB.
                    if ($vatRateId > 0) {
                        $pdo->prepare(
                            "INSERT INTO purchase_invoice_items
                                (purchase_invoice_id, description, quantity, unit_price_without_vat,
                                 vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat)
                             VALUES (?, 'Testovací položka', 1, 1000, ?, 21, 1000, 210, 1210)"
                        )->execute([$purchaseId, $vatRateId]);
                    }
                }
            }
        });

        // Účetní období pro AKTUÁLNÍ rok — několik testů skipuje na
        // „Žádné účetní období pro dnešní datum u supplier_id=1."
        $step('ucetni-obdobi', static function () use ($pdo, $supplierId): void {
            $year = (int) date('Y');
            $pdo->prepare(
                "INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status)
                 SELECT ?, ?, ?, ?, 'open'
                  WHERE NOT EXISTS (
                      SELECT 1 FROM accounting_periods WHERE supplier_id = ? AND fiscal_year = ?
                  )"
            )->execute([$supplierId, $year, "{$year}-01-01", "{$year}-12-31", $supplierId, $year]);
        });
    } catch (\Throwable $e) {
        fwrite(STDERR, '[TEST DB] Normalizace tenanta přeskočena: ' . $e->getMessage() . "\n");
    }
}

function __applyPendingTestMigrations(string $rootDir, array $cfg, string $chosenDb): void
{
    try {
        $db  = $cfg['db'];
        $pdo = new \PDO(
            "mysql:host={$db['host']};port=" . (int) ($db['port'] ?? 3306) . ";dbname={$chosenDb};charset=utf8mb4",
            (string) $db['user'],
            (string) ($db['pass'] ?? ''),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
        $applied = $pdo->query('SELECT filename FROM migrations')->fetchAll(\PDO::FETCH_COLUMN);
        $files   = array_map('basename', glob($rootDir . '/db/migrations/*.sql') ?: []);
        $pending = array_values(array_diff($files, $applied));
        if ($pending !== []) {
            fwrite(STDERR, '[TEST DB] Aplikuji ' . count($pending) . " nových migrací do {$chosenDb}...\n");
            // proc_open s EXPLICITNÍM env → child migrate.php garantovaně míří na test DB
            // (nespoléháme na dědění putenv; child NIKDY nesmí sáhnout na ostrou DB).
            $env = getenv();
            $env['MYINVOICE_DB_NAME'] = $chosenDb;
            $proc = proc_open(
                [PHP_BINARY, $rootDir . '/api/bin/migrate.php', '--no-backfills'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $rootDir,
                $env
            );
            $migOut = '';
            if (is_resource($proc)) {
                $migOut = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $code = proc_close($proc);
            } else {
                $code = 1;
            }
            if ($code !== 0) {
                fwrite(STDERR, "[TEST DB] Migrace SELHALY:\n{$migOut}\n");
                exit(1);
            }
        }
    } catch (\Throwable $e) {
        // Test DB nedostupná / bez `migrations` tabulky — integrační testy se stejně
        // soft-skipnou (markTestSkipped 'DB unavailable'); nebráníme unit/architecture testům.
        fwrite(STDERR, '[TEST DB] Přeskočena kontrola migrací: ' . $e->getMessage() . "\n");
    }
}
