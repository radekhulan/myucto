<?php

declare(strict_types=1);

namespace MyInvoice;

use DI\ContainerBuilder;
use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Cache\RedisProbe;
use MyInvoice\Infrastructure\Clock\UtcClock;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\ApiRequestLogMiddleware;
use MyInvoice\Middleware\ApiScopeMiddleware;
use MyInvoice\Middleware\ApiVersionRewriteMiddleware;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\CsrfMiddleware;
use MyInvoice\Middleware\DemoReadOnlyMiddleware;
use MyInvoice\Middleware\FirstRunLockMiddleware;
use MyInvoice\Middleware\IpAllowlistMiddleware;
use MyInvoice\Middleware\LicenseMiddleware;
use MyInvoice\Middleware\MaintenanceModeMiddleware;
use MyInvoice\Middleware\RateLimitMiddleware;
use MyInvoice\Middleware\PermissionMiddleware;
use MyInvoice\Middleware\RequireMfaMiddleware;
use MyInvoice\Middleware\SessionLockMiddleware;
use MyInvoice\Middleware\StorageQuotaReadOnlyMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Middleware\TenantDomainMiddleware;
use MyInvoice\Middleware\WebAuthnBodyLimitMiddleware;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Auth\PasskeyService;
use MyInvoice\Service\Auth\DatabaseSecurityClock;
use MyInvoice\Service\Auth\SecurityClock;
use MyInvoice\Service\Auth\WebAuthnConfigProvider;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;

final class Bootstrap
{
    public static function rootDir(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * Postaví JEN DI kontejner — bez rout a bez middleware.
     *
     * Tohle je vstupní bod pro CLI (api/bin/cron-*.php, workery, backfilly).
     * `buildApp()` registruje 586 rout a instancuje 14 middleware, což je pro
     * skript spouštěný z cronu čistá režie: rozdíl je ~220 vs ~100 načtených
     * souborů na jeden běh. Při frekvenci „každou minutu" × počet tenantů to
     * přestává být kosmetika.
     *
     * Definice služeb jsou tady, ne v buildApp(), aby obě cesty (web i CLI)
     * dostaly bit po bitu stejný kontejner — jinak by se konfigurace časem
     * rozešla a chyba by se projevila jen v jedné z nich.
     */
    public static function buildContainer(): ContainerInterface
    {
        $rootDir = self::rootDir();
        $config  = Config::load($rootDir);

        // Bezpečnostní guard: v produkci pepper musí být nastavený (jinak hesla nemají druhotnou ochranu)
        $env    = (string) $config->get('app.env', 'production');
        $pepper = (string) $config->get('app.pepper', '');
        if ($env === 'production' && $pepper === '') {
            throw new \RuntimeException('cfg.app.pepper není nastaven (vygeneruj: openssl rand -base64 32). V produkci je povinný.');
        }

        date_default_timezone_set((string) $config->get('app.timezone', 'Europe/Prague'));

        // Schvalovatel dodaných mzdových legislativních sad = provozovatel TÉHLE
        // instalace. Sada se sestavuje statickou tovární metodou volanou i mimo
        // kontejner (CLI, fixtury), takže se hodnota předává jednou tady a dál se
        // čte staticky; bez tohohle volání platí ENV a pak výchozí hodnota.
        \MyInvoice\Service\Payroll\Ruleset\VendorRulesetApprover::configure(
            self::stringOrNull($config->get(
                \MyInvoice\Service\Payroll\Ruleset\VendorRulesetApprover::CONFIG_KEY,
            )),
        );

        // PHP error log → log/php-errors.log (jinak by warnings/notices padaly do
        // system php_errors.log, který je mimo repo). Display_errors v dev=on, prod=off.
        // Pokud je nastaven MYINVOICE_DATA_DIR, ukládáme i tento log do data_dir
        // (drží všechen state pod jediným perzistentním volume).
        $logDir = ($config->dataDir() ?? $rootDir) . '/log';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        ini_set('log_errors', '1');
        ini_set('error_log', $logDir . '/php-errors.log');
        // NIKDY display_errors=on pro API endpoints — JSON response by byla kontaminována
        // deprecation/notice warningy (typicky vendor 3rd-party kód). Logujeme do souboru.
        // Dev env: warnings se objeví v log/php-errors.log + log/app-YYYY-MM-DD.log.
        ini_set('display_errors', '0');
        // Reporting: E_ALL minus E_DEPRECATED (PHP 8.5 deprecates older patterns ve vendoru,
        // které nemůžeme fixnout — nechceme je v error log spamovat).
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

        $builder = new ContainerBuilder();
        $builder->useAttributes(false);
        $builder->addDefinitions([
            Config::class => $config,
            // Aplikační hodiny zůstávají v `app.timezone` (Europe/Prague) — účetní kód
            // (odpisy, období) porovnává kalendářní datum, a UTC by ho mezi půlnocí
            // a 2:00 posunul o den zpět. Autentizace na tom nestojí: bezpečnostní časy
            // bere SecurityClock z databáze a zbytek se normalizuje na UTC explicitně.
            ClockInterface::class => fn () => new \Symfony\Component\Clock\NativeClock(),
            SecurityClock::class => fn () => new DatabaseSecurityClock(),

            LoggerInterface::class => function (ContainerInterface $c) use ($config): LoggerInterface {
                $logger = new Logger('myinvoice');
                $path   = (string) $config->get('logging.path');
                $level  = self::resolveLogLevel((string) $config->get('logging.level', 'info'));
                $maxFiles = (int) $config->get('logging.max_files', 90);
                if (!is_dir(dirname($path))) {
                    @mkdir(dirname($path), 0755, true);
                }
                $logger->pushHandler(new RotatingFileHandler($path, $maxFiles, $level));
                return $logger;
            },

            ResponseFactory::class => fn () => new ResponseFactory(),
            Connection::class      => fn (ContainerInterface $c) => new Connection($c->get(Config::class), $c->get(LoggerInterface::class)),
            \MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolSignatureVerifierInterface::class =>
                fn (ContainerInterface $c) => $c->get(
                    \MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolSignatureVerifier::class,
                ),
            \MyInvoice\Service\Submission\SubmissionInboxMessageProcessor::class =>
                fn (ContainerInterface $c) => $c->get(
                    \MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzIsdsInboxProcessor::class,
                ),
            \MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthPaymentOverviewPdfTemplateProvider::class =>
                fn (ContainerInterface $c) => $c->get(
                    \MyInvoice\Service\Payroll\Submission\HealthInsurance\CachedHealthPaymentOverviewPdfTemplateProvider::class,
                ),
            \MyInvoice\Service\Payroll\Garnishment\EnforcementCaseSource::class =>
                fn (ContainerInterface $c) => $c->get(
                    \MyInvoice\Repository\Payroll\PayrollEnforcementRepository::class,
                ),
            \MyInvoice\Service\Payroll\Garnishment\PayrollGarnishmentSnapshotWriter::class =>
                fn (ContainerInterface $c) => $c->get(
                    \MyInvoice\Repository\Payroll\PayrollEnforcementRepository::class,
                ),
            \MyInvoice\Service\Payroll\Garnishment\PayrollGarnishmentPort::class =>
                fn (ContainerInterface $c) => $c->get(
                    \MyInvoice\Service\Payroll\Garnishment\RepositoryPayrollGarnishmentPort::class,
                ),
            // Runtime registry = ověřený default z kódu (CzechPayrollRulesets2026)
            // sloučený s DB overridem z administrace (migrace 1306), stejně jako
            // u ročních daňových konstant. Bez tohohle bindu by „aktivace" rulesetu
            // znamenala nasazení nové verze aplikace. Registry při nekonzistentním
            // overridu degraduje zpět na default, ne na výjimku.
            \MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider::class =>
                fn (ContainerInterface $c) => $c
                    ->get(\MyInvoice\Service\Payroll\Ruleset\PayrollRulesetRegistry::class)
                    ->provider(),
            \MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository::class =>
                fn (ContainerInterface $c) =>
                    new \MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository(
                        $c->get(Connection::class),
                        $c->get(
                            \MyInvoice\Repository\Payroll\PayrollEmployerPolicyDeletionRepository::class,
                        ),
                    ),
            \MyInvoice\Service\Payroll\Settings\PayrollEmployerPolicyService::class =>
                fn (ContainerInterface $c) =>
                    new \MyInvoice\Service\Payroll\Settings\PayrollEmployerPolicyService(
                        $c->get(
                            \MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository::class,
                        ),
                    ),
            \MyInvoice\Service\Payroll\Settings\PayrollSetupFeaturesResolver::class =>
                fn (ContainerInterface $c) =>
                    new \MyInvoice\Service\Payroll\Settings\PayrollSetupFeaturesResolver(
                        $c->get(Connection::class),
                        $c->get(
                            \MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository::class,
                        ),
                        $c->get(\MyInvoice\Service\Payroll\SupportMatrix::class),
                    ),
            \MyInvoice\Service\Payroll\Settings\PayrollSetupCheckService::class =>
                fn (ContainerInterface $c) =>
                    new \MyInvoice\Service\Payroll\Settings\PayrollSetupCheckService(
                        $c->get(Connection::class),
                        $c->get(
                            \MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository::class,
                        ),
                    ),
            \MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder::class =>
                fn (ContainerInterface $c) => new \MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder(
                    $c->get(Connection::class),
                    $c->get(\MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider::class),
                    $c->get(
                        \MyInvoice\Service\Payroll\Garnishment\EnforcementCaseSource::class,
                    ),
                    $c->get(
                        \MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository::class,
                    ),
                    $c->get(
                        \MyInvoice\Service\Payroll\Run\PayrollStatutoryPeriodResolver::class,
                    ),
                    $c->get(
                        \MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository::class,
                    ),
                    $c->get(
                        \MyInvoice\Repository\Payroll\PayrollEmployerSettingsRepository::class,
                    ),
                    $c->get(
                        \MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository::class,
                    ),
                    $c->get(
                        \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog::class,
                    ),
                    // Volitelné class-parametry PHP-DI neautowiruje — bez tohohle
                    // bindu by se limity § 93 tiše nehlídaly vůbec.
                    $c->get(
                        \MyInvoice\Service\Payroll\Time\Overtime\PayrollOvertimeLimitService::class,
                    ),
                ),
            \MyInvoice\Service\Payroll\Time\Overtime\OvertimeLimitRules::class =>
                fn (ContainerInterface $c) =>
                    new \MyInvoice\Service\Payroll\Time\Overtime\OvertimeLimitRules(
                        $c->get(\MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider::class),
                    ),
            \MyInvoice\Service\Payroll\Time\Overtime\PayrollOvertimeLimitService::class =>
                fn (ContainerInterface $c) =>
                    new \MyInvoice\Service\Payroll\Time\Overtime\PayrollOvertimeLimitService(
                        $c->get(\MyInvoice\Repository\Payroll\PayrollOvertimeRepository::class),
                        $c->get(
                            \MyInvoice\Service\Payroll\Time\Overtime\OvertimeLimitRules::class,
                        ),
                    ),
            // Katalog kontrol se načítá z připnutého manifestu a ověřuje otisk
            // zdrojového XLSX, což je na každý požadavek zbytečně drahé —
            // kontejner ho proto drží jako singleton.
            \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1ControlValidator::class =>
                static fn (): \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1ControlValidator
                    => \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1ControlValidator::create(),
            // Volitelný class-parametr PHP-DI neautowiruje — bez tohohle bindu
            // by se doplatek z ročního zúčtování do mzdového běhu nikdy
            // nedostal a přeplatek by se zaměstnanci nevrátil.
            \MyInvoice\Service\Payroll\Run\PayrollRunStatutoryCalculationService::class =>
                fn (ContainerInterface $c) =>
                    new \MyInvoice\Service\Payroll\Run\PayrollRunStatutoryCalculationService(
                        $c->get(\MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider::class),
                        $c->get(
                            \MyInvoice\Service\Payroll\Run\PayrollRunStatutoryInputAssembler::class,
                        ),
                        $c->get(
                            \MyInvoice\Service\Payroll\Run\PayrollRunStatutoryResultPersister::class,
                        ),
                        $c->get(
                            \MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementPayoutService::class,
                        ),
                    ),
            \MyInvoice\Service\Payroll\Run\PayrollRunCalculationPipeline::class =>
                fn (ContainerInterface $c) => new \MyInvoice\Service\Payroll\Run\PayrollRunCalculationPipeline(
                    $c->get(\MyInvoice\Service\Payroll\Run\PayrollRunCalculator::class),
                    $c->get(\MyInvoice\Service\Payroll\Run\PayrollRunGarnishmentProcessor::class),
                    $c->get(
                        \MyInvoice\Service\Payroll\Run\PayrollRunStatutoryCalculationService::class,
                    ),
                    $c->get(
                        \MyInvoice\Service\Payroll\Run\PayrollRunStatutoryAccumulatorApprover::class,
                    ),
                    $c->get(
                        \MyInvoice\Service\Payroll\Run\PayrollRunDeductionLedgerApprover::class,
                    ),
                    $c->get(
                        \MyInvoice\Service\Payroll\RiskySavings\PayrollRiskySavingsApprover::class,
                    ),
                ),
            \MyInvoice\Service\Payroll\Run\PayrollRunCommandService::class =>
                fn (ContainerInterface $c) => new \MyInvoice\Service\Payroll\Run\PayrollRunCommandService(
                    $c->get(Connection::class),
                    $c->get(\MyInvoice\Repository\Payroll\PayrollRunRepository::class),
                    $c->get(\MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder::class),
                    $c->get(\MyInvoice\Service\Payroll\Run\PayrollRunCalculationPipeline::class),
                    $c->get(\MyInvoice\Service\Payroll\Run\PayrollRunWorkflow::class),
                    $c->get(\MyInvoice\Service\Payroll\PayrollPeriodOwnershipService::class),
                    $c->get(
                        \MyInvoice\Service\Payroll\Posting\PayrollApprovedRevisionPostingService::class,
                    ),
                    $c->get(
                        \MyInvoice\Service\Payroll\Document\ApprovedRevisionPayslipBatchService::class,
                    ),
                    $c->get(
                        \MyInvoice\Service\Payroll\ControlTotals\PayrollControlTotalsService::class,
                    ),
                    // Volitelné class-parametry PHP-DI neautowiruje — bez
                    // explicitního bindu by mzdový běh neuměl `prepare_payments`,
                    // `mark_paid` ani překlopení modulu do `active`.
                    $c->get(
                        \MyInvoice\Service\Payroll\Run\PayrollRunPaymentPreparationService::class,
                    ),
                    $c->get(
                        \MyInvoice\Service\Payroll\Run\PayrollRunPaymentSettlementService::class,
                    ),
                    $c->get(
                        \MyInvoice\Service\Payroll\PayrollModuleActivationService::class,
                    ),
                    $c->get(
                        \MyInvoice\Service\Payroll\Document\PayrollDocumentBatchQueueService::class,
                    ),
                ),
            // Identifikace software jde do datové věty JMHZ a ČSSZ ji porovnává
            // s obálkou. Verze se čte ze souboru VERSION, aby v protokolu
            // seděla se skutečně nasazeným buildem — natvrdo zapsaná verze
            // znemožní dohledat, která zpráva odkud pochází.
            \MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSoftwareIdentification::class
                => function () use ($rootDir): \MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSoftwareIdentification {
                    $versionFile = $rootDir . DIRECTORY_SEPARATOR . 'VERSION';
                    $version = is_file($versionFile)
                        ? trim((string) @file_get_contents($versionFile))
                        : '';

                    return new \MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSoftwareIdentification(
                        'MyÚčto.cz',
                        $version === '' ? '0.0.0' : $version,
                    );
                },
            \MyInvoice\Service\Payroll\Submission\PayrollSubmissionService::class
                => fn (ContainerInterface $c) => new \MyInvoice\Service\Payroll\Submission\PayrollSubmissionService(
                    $c->get(\MyInvoice\Repository\Payroll\PayrollSubmissionRepository::class),
                    $c->get(\MyInvoice\Service\Payroll\Submission\PayrollSubmissionStateMachine::class),
                    $c->get(\MyInvoice\Service\Auth\SecretEncryption::class),
                    $c->get(ClockInterface::class),
                    $c->get(\MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationReceiptIdentityService::class),
                ),
            \MyInvoice\Service\Epo\EpoDirectResponseParser::class => function () use ($config, $rootDir): \MyInvoice\Service\Epo\EpoDirectResponseParser {
                $caBundle = trim((string) $config->get('epo.ca_bundle_path', ''));
                if (
                    $caBundle !== ''
                    && !preg_match('/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\|\/)/', $caBundle)
                ) {
                    $caBundle = $rootDir . DIRECTORY_SEPARATOR . $caBundle;
                }
                $fingerprints = $config->get('epo.receipt_signer_fingerprints_sha256', []);
                if (is_string($fingerprints)) {
                    $fingerprints = array_filter(array_map('trim', explode(',', $fingerprints)));
                }
                $testFingerprints = $config->get('epo.test_receipt_signer_fingerprints_sha256', []);
                if (is_string($testFingerprints)) {
                    $testFingerprints = array_filter(array_map('trim', explode(',', $testFingerprints)));
                }
                return new \MyInvoice\Service\Epo\EpoDirectResponseParser(
                    $caBundle !== '' ? $caBundle : null,
                    is_array($fingerprints) ? array_values($fingerprints) : [],
                    is_array($testFingerprints) ? array_values($testFingerprints) : [],
                );
            },
            // DPPO podklady: ClosingService (4. arg) je konstrukčně volitelný (unit testy nad SQLite
            // ho nepředávají), ale PHP-DI autowire optional class-param nevyplní → explicitní bind,
            // aby náhled DPPO měl projekci závěrkových operací (Feature 1) i v produkci.
            \MyInvoice\Service\Tax\Return\DppoReturnDataProvider::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Tax\Return\DppoReturnDataProvider(
                $c->get(Connection::class),
                $c->get(\MyInvoice\Repository\AccountingPeriodRepository::class),
                $c->get(\MyInvoice\Service\Tax\Return\NonDeductibleCostsService::class),
                $c->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class),
                // § 23/3/a/12 — rovněž volitelná, takže bez tohohle bindu by návrh
                // připočtení neuhrazených dluhů v produkci tiše nevznikl.
                $c->get(\MyInvoice\Service\Tax\Return\UnpaidLiabilityService::class),
                // § 23/7 — ze stejného důvodu: volitelný parametr PHP-DI neautowiruje,
                // takže bez tohohle bindu by podklad ke spojeným osobám zůstal v produkci
                // navždy prázdný a nikdo by se to nedozvěděl.
                $c->get(\MyInvoice\Service\Tax\RelatedPartyService::class),
            ),
            // JMHZ transport — poslední dva argumenty jsou volitelné kvůli
            // testovacím dvojníkům (falešný VREP, mockovaný ledger), ale
            // PHP-DI optional class-param neautowiruje. Bez tohohle bindu by
            // v produkci odeslané podání zůstalo navždy ve stavu `ready`
            // a nešlo by odeslat storno ani opravu bez ručně předaného XML.
            \MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchService::class
                => fn (ContainerInterface $c) => new \MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchService(
                    $c->get(\MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository::class),
                    $c->get(\MyInvoice\Repository\Payroll\PayrollSigningProfileRepository::class),
                    $c->get(\MyInvoice\Service\Signing\PersonalCertificateVaultService::class),
                    $c->get(\MyInvoice\Service\Auth\SecretEncryption::class),
                    $c->get(\MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSoftwareIdentification::class),
                    null,
                    new \MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzAcknowledgementParser(),
                    new \MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolParser(),
                    $c->get(\MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenPayloadReader::class),
                    $c->get(\MyInvoice\Service\Payroll\Submission\PayrollSubmissionService::class),
                    // Ověření podpisu protokolu proti připnutému certifikátu ČSSZ.
                    // Bez něj by dotažený protokol skončil jako nedůvěryhodná
                    // příloha a podání by navždy zůstalo ve stavu `submitted`.
                    new \MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolSignatureVerifier(),
                ),
            // § 33a — zřetězení auditní stopy hashem. Druhý argument loggeru je volitelný
            // kvůli testovacím dvojníkům; bez explicitního bindu by se v produkci nic
            // nepečetilo a řetěz by neexistoval, aniž by to cokoli ohlásilo.
            \MyInvoice\Service\ActivityLogger::class => fn (ContainerInterface $c) => new \MyInvoice\Service\ActivityLogger(
                $c->get(Connection::class),
                $c->get(\MyInvoice\Service\ActivityLogHashChain::class),
            ),
            // Epic #48 — dashboard „Akce pro tebe" potřebuje živý náhled doplatku DPPO
            // (TaxReturnService::balanceDuePreview). Konstrukčně volitelný (2. arg) kvůli
            // ReceivablesPayablesServiceTest, který CrmAggregationService staví ručně jen
            // s Connection — PHP-DI autowire optional class-param nevyplní, proto explicitní
            // bind, aby produkce/CrmDashboardAction reálnou instanci vždy dostaly.
            \MyInvoice\Service\Crm\CrmAggregationService::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Crm\CrmAggregationService(
                $c->get(Connection::class),
                $c->get(\MyInvoice\Service\Tax\Return\TaxReturnService::class),
                $c->get(\MyInvoice\Service\Accounting\JournalIntegrityService::class),
                $c->get(\MyInvoice\Service\License\LicenseService::class),
                $c->get(\MyInvoice\Service\Report\VatRegistrationService::class),
                // Bez tohohle argumentu spadne služba na průchozí EntityCache::disabled()
                // a náhled doplatku daně (~450 ms) se počítá při každém načtení znovu.
                $c->get(\MyInvoice\Infrastructure\Cache\EntityCache::class),
            ),
            // Epic F0 — seam pro budoucí shard-routing per supplier; nový účetní kód (F1+)
            // si PDO bere přes forSupplier(), dnes vrací sdílené spojení.
            \MyInvoice\Infrastructure\Database\ConnectionResolver::class => fn (ContainerInterface $c) => new \MyInvoice\Infrastructure\Database\ConnectionResolver($c->get(Connection::class)),
            // Nativní updater si cesty (root / data dir) rozřeší sám; explicitní bind,
            // ať PHP-DI nemusí hádat volitelné string parametry konstruktoru.
            \MyInvoice\Service\Update\NativeUpdateService::class => fn () => new \MyInvoice\Service\Update\NativeUpdateService(),
            RedisProbe::class      => fn (ContainerInterface $c) => new RedisProbe($c->get(Config::class)),
            RedisFactory::class    => fn (ContainerInterface $c) => new RedisFactory($c->get(Config::class)),
            // Entity cache se při vytvoření zaregistruje do WriteWatcheru, aby
            // invalidaci odchytávala PDO vrstva. Bez toho by cache držela
            // zastaralá data po jakémkoli zápisu, který nejde přes její API —
            // a takových je u `users` většina.
            \MyInvoice\Infrastructure\Cache\EntityCache::class => function (ContainerInterface $c) {
                $cache = new \MyInvoice\Infrastructure\Cache\EntityCache(
                    $c->get(RedisFactory::class),
                    $c->get(Config::class),
                );
                \MyInvoice\Infrastructure\Database\WriteWatcher::attach($cache);

                return $cache;
            },
            PasskeyService::class  => fn (ContainerInterface $c) => new PasskeyService(
                $c->get(WebAuthnConfigProvider::class),
            ),
            \MyInvoice\Service\Signing\SigningPassphraseProviderInterface::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Signing\SigningPassphraseProvider(
                $c->get(Config::class),
                $c->get(\MyInvoice\Service\Auth\SecretEncryption::class),
            ),
            \MyInvoice\Service\Signing\Pdf\PdfSigningService::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Signing\Pdf\PdfSigningService(
                $c->get(Config::class),
                $c->get(\MyInvoice\Service\ActivityLogger::class),
                $c->get(\MyInvoice\Service\Signing\Pdf\NativePdfSignatureBackend::class),
                $c->get(\MyInvoice\Repository\SigningProfileRepository::class),
                $c->get(\MyInvoice\Service\Signing\SigningPassphraseProviderInterface::class),
                $c->get(\MyInvoice\Service\Signing\PersonalCertificateVaultService::class),
            ),
            \MyInvoice\Service\Signing\Email\EmailSigningService::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Signing\Email\EmailSigningService(
                $c->get(Config::class),
                $c->get(\MyInvoice\Service\ActivityLogger::class),
                $c->get(\MyInvoice\Repository\SigningProfileRepository::class),
                $c->get(\MyInvoice\Service\Signing\SigningPassphraseProviderInterface::class),
                $c->get(\MyInvoice\Service\Auth\SecretEncryption::class),
                $c->get(\MyInvoice\Service\Signing\PersonalCertificateVaultService::class),
            ),
            \MyInvoice\Service\Mail\Mailer::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Mail\Mailer(
                $c->get(Config::class),
                $c->get(LoggerInterface::class),
                $c->get(Connection::class),
                $c->get(\MyInvoice\Repository\EmailTemplateRepository::class),
                $c->get(\MyInvoice\Service\Signing\Email\EmailSigningService::class),
                $c->get(\MyInvoice\Repository\EmailProfileRepository::class),
                $c->get(\MyInvoice\Service\Mail\SentMailImapAppender::class),
            ),
            \MyInvoice\Service\Bank\EmailNotice\ImapMailboxClientInterface::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Bank\EmailNotice\WebklexImapMailboxClient(
                $c->get(\MyInvoice\Service\Bank\EmailNotice\EmailNoticeTextNormalizer::class),
            ),
            \MyInvoice\Service\Bank\EmailNotice\Parser\BankEmailNoticeParserRepository::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Bank\EmailNotice\Parser\BankEmailNoticeParserRepository(
                $c->get(Connection::class),
                self::bankEmailNoticeParsers($c, $config),
            ),
            \MyInvoice\Service\Bank\StatementMatcher::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Bank\StatementMatcher(
                $c->get(Connection::class),
                $c->get(\MyInvoice\Service\Invoice\FinalFromProformaCreator::class),
                // #127 — automatické párování (GPC import, e-mailové avízo, cron) musí
                // poslat děkovný e-mail za úhradu stejně jako ruční mark-paid/manualMatch.
                $c->get(\MyInvoice\Service\Mail\PaymentThanksMailer::class),
                // #89 — evidence plateb (exact i částečné úhrady přes invoice_payments)
                // + auto DRAFT daňového dokladu k přijaté platbě u částečně uhrazené proformy.
                $c->get(\MyInvoice\Service\Invoice\InvoicePaymentService::class),
                $c->get(\MyInvoice\Service\Invoice\PaymentTaxDocumentCreator::class),
                // Aktivita dokladu — „payment_matched" záznam u auto-spárování platby
                // (vidět v aktivitě vystavené i přijaté faktury).
                $c->get(\MyInvoice\Service\ActivityLogger::class),
                $c->get(\MyInvoice\Repository\ClientBankAccountRepository::class),
                $c->get(\MyInvoice\Service\Bank\Match\MatchSuggestionService::class),
            ),

            \MyInvoice\Service\Accounting\Bank\TransferAutoPolicyInterface::class => fn (ContainerInterface $c) =>
                $c->get(\MyInvoice\Service\Accounting\AutoPostingPolicyService::class),
            // TransferPairService je jinak autowired, ale ?BankAnalyticResolver je optional
            // class-param (PHP-DI autowire ho nevyplní) — explicitní bind, ať #35 analytika
            // vlastního účtu funguje i na noze převodu mezi vlastními účty (221/261).
            \MyInvoice\Service\Accounting\Bank\TransferPairService::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Accounting\Bank\TransferPairService(
                $c->get(Connection::class),
                $c->get(\MyInvoice\Service\Accounting\PostingService::class),
                $c->get(\MyInvoice\Repository\PostingRuleRepository::class),
                $c->get(\MyInvoice\Repository\JournalEntryRepository::class),
                $c->get(\MyInvoice\Repository\BankPostingSuggestionRepository::class),
                $c->get(\MyInvoice\Service\Accounting\Bank\OwnTransferDetector::class),
                $c->get(\MyInvoice\Service\Accounting\Bank\TransferAutoPolicyInterface::class),
                $c->get(\MyInvoice\Service\ActivityLogger::class),
                $c->get(\MyInvoice\Service\Currency\CnbExchangeRateClient::class),
                $c->get(\MyInvoice\Service\Accounting\Bank\BankAnalyticResolver::class),
            ),
            \MyInvoice\Service\Accounting\Bank\BankPostingService::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Accounting\Bank\BankPostingService(
                $c->get(Connection::class),
                $c->get(\MyInvoice\Service\Accounting\PostingService::class),
                $c->get(\MyInvoice\Repository\PostingRuleRepository::class),
                $c->get(\MyInvoice\Repository\AccountingPeriodRepository::class),
                $c->get(\MyInvoice\Repository\JournalEntryRepository::class),
                $c->get(\MyInvoice\Repository\BankPostingRuleRepository::class),
                $c->get(\MyInvoice\Repository\BankPostingSuggestionRepository::class),
                $c->get(\MyInvoice\Service\Accounting\Bank\BankRuleMatcher::class),
                $c->get(\MyInvoice\Service\ActivityLogger::class),
                $c->get(\MyInvoice\Service\Currency\CnbExchangeRateClient::class),
                $c->get(\MyInvoice\Service\Currency\FixedExchangeRateService::class),
                $c->get(\MyInvoice\Service\Accounting\Bank\Detect\BankDetectorChain::class),
                $c->get(\MyInvoice\Service\Accounting\Bank\TransferPairService::class),
                $c->get(\MyInvoice\Service\Accounting\AutoPostingPolicyService::class),
                $c->get(\MyInvoice\Repository\TaxAdvanceScheduleRepository::class),
                $c->get(\MyInvoice\Service\Accounting\Learning\CorrectionRecorder::class),
                $c->get(\MyInvoice\Service\Accounting\Learning\RulePromotionService::class),
                $c->get(\MyInvoice\Service\Ai\AnomalyDetector::class),
                $c->get(\MyInvoice\Service\Ai\AiSuggestionService::class),
                $c->get(\MyInvoice\Service\Ai\AiKillSwitchService::class),
                $c->get(\MyInvoice\Service\Ai\EmbeddingWriter::class),
                $c->get(\MyInvoice\Service\Accounting\Bank\LegacyBankPaymentReconciler::class),
                $c->get(\MyInvoice\Service\Accounting\Bank\BankAnalyticResolver::class),
                $c->get(\MyInvoice\Service\Accounting\Bank\BankPostingPreview::class),
            ),
            \MyInvoice\Service\Bank\StatementImporter::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Bank\StatementImporter(
                $c->get(Connection::class),
                $c->get(\MyInvoice\Service\Bank\GpcParser::class),
                $c->get(\MyInvoice\Service\Bank\StatementMatcher::class),
                $c->get(\MyInvoice\Service\Bank\EmailNoticeReconciler::class),
                $c->get(\MyInvoice\Service\Accounting\Bank\BankPostingService::class),
                $c->get(\MyInvoice\Repository\SupplierBankAccountRepository::class),
            ),

            // EntityCache je v obou službách volitelný class-param (kvůli testovacím
            // dvojníkům) a PHP-DI s useAttributes(false) takový parametr NEVYPLNÍ —
            // dosadí null. Bez těchhle bindů by obě spadly na EntityCache::disabled()
            // a cache by v produkci tiše nedělala vůbec nic. Přesně to se stalo při
            // prvním nasazení: dotazy zůstaly na osmi, dokud se bindy nedoplnily.
            \MyInvoice\Service\License\LicenseService::class => fn (ContainerInterface $c) => new \MyInvoice\Service\License\LicenseService(
                $c->get(Connection::class),
                $c->get(Config::class),
                $c->get(\MyInvoice\Service\License\LicenseTokenVerifier::class),
                $c->get(\MyInvoice\Service\License\LicenseClient::class),
                $c->get(LoggerInterface::class),
                $c->get(\MyInvoice\Infrastructure\Cache\EntityCache::class),
                // Telemetrie se dopočítá lazily (viz docblock služby), rozsah
                // zaplacené služby ale musí být TÁŽ instance jako všude jinde —
                // jinak by se po zápisu nového rozsahu nezahodila jeho cache.
                entitlement: $c->get(\MyInvoice\Service\System\InstanceEntitlement::class),
            ),
            \MyInvoice\Service\Tenant\SupplierAccessResolver::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Tenant\SupplierAccessResolver(
                $c->get(Connection::class),
                $c->get(\MyInvoice\Repository\UserSupplierRepository::class),
                $c->get(\MyInvoice\Infrastructure\Cache\EntityCache::class),
            ),
            \MyInvoice\Service\Invoice\InvoicePublicLinkService::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Invoice\InvoicePublicLinkService(
                $c->get(Config::class),
                $c->get(\MyInvoice\Repository\InvoiceRepository::class),
                $c->get(\MyInvoice\Service\Tenant\TenantUrlResolver::class),
            ),
            \MyInvoice\Service\Mail\ApprovalEmailVarsBuilder::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Mail\ApprovalEmailVarsBuilder(
                $c->get(Connection::class),
                $c->get(Config::class),
                $c->get(\MyInvoice\Repository\WorkReportRepository::class),
                $c->get(\MyInvoice\Service\Tenant\TenantUrlResolver::class),
            ),
            \MyInvoice\Service\WorkReport\WorkReportLinkService::class => fn (ContainerInterface $c) => new \MyInvoice\Service\WorkReport\WorkReportLinkService(
                $c->get(Connection::class),
                $c->get(Config::class),
                $c->get(\MyInvoice\Service\Mail\Mailer::class),
                $c->get(\MyInvoice\Repository\WorkReportRepository::class),
                $c->get(\MyInvoice\Repository\WorkReportLinkRepository::class),
                $c->get(LoggerInterface::class),
                $c->get(\MyInvoice\Service\Vat\VatStatusService::class),
                $c->get(\MyInvoice\Service\Tenant\TenantUrlResolver::class),
            ),
            \MyInvoice\Security\UserRoleProfile::class => fn (ContainerInterface $c) => new \MyInvoice\Security\UserRoleProfile(
                $c->get(Connection::class),
                $c->get(\MyInvoice\Infrastructure\Cache\EntityCache::class),
            ),
            \MyInvoice\Action\Auth\MeAction::class => fn (ContainerInterface $c) => new \MyInvoice\Action\Auth\MeAction(
                $c->get(Connection::class),
                $c->get(Config::class),
                $c->get(\MyInvoice\Repository\UserSupplierRepository::class),
                $c->get(\MyInvoice\Security\PermissionResolver::class),
                $c->get(\MyInvoice\Service\License\LicenseService::class),
                $c->get(\MyInvoice\Repository\PasskeyCredentialRepository::class),
                $c->get(\MyInvoice\Service\Auth\MfaPolicyService::class),
                $c->get(\MyInvoice\Service\Auth\SessionLockPolicy::class),
                $c->get(ClockInterface::class),
                $c->get(\MyInvoice\Infrastructure\Cache\EntityCache::class),
            ),

            // ⚠️ `?InstanceHealthProbe $probe = null` v HealthAction je test seam
            // (health musí odpovědět kompletním tvarem i bez DB). PHP-DI ale
            // VOLITELNÉ parametry autowiringem přeskakuje — bez tohohle bindu by
            // probe zůstal null a `/api/health` by v provozu vracel samé null
            // u údržby, běžících úloh, cronu, zálohy i migrací. Tiché selhání
            // přesně toho, kvůli čemu endpoint vznikl (H-09).
            \MyInvoice\Action\System\HealthAction::class => \DI\autowire()
                ->constructorParameter(
                    'probe',
                    \DI\get(\MyInvoice\Service\System\InstanceHealthProbe::class),
                ),

            // Licenční klient (E4) má volitelný `?GuzzleHttp\Client $http = null` (test
            // seam). Autowire by ho vyplnil bare Guzzle (bez base_uri/verify z cfg) →
            // definujeme explicitně s $http = null, ať si klient postaví vlastní klienta.
            \MyInvoice\Service\License\LicenseClient::class => fn (ContainerInterface $c) => new \MyInvoice\Service\License\LicenseClient(
                $c->get(Config::class),
                $c->get(LoggerInterface::class),
            ),
            // EPO klient má volitelný Guzzle pouze jako test seam. Produkce musí
            // použít jeho vlastní timeout/TLS/no-redirect konfiguraci.
            \MyInvoice\Service\Epo\EpoClient::class => fn () => new \MyInvoice\Service\Epo\EpoClient(
                null,
            ),
            // ⚠️ `epo_test` prochází zámkem spravovaného režimu, a to PRÁVĚ TADY,
            // protože tohle je jediné místo, které rozhoduje o skutečném prostředí.
            // Zkušební prostředí daňové správy v ostré zákaznické instanci znamená
            // tiše nepodaná hlášení — a poznat se to dá až po termínu.
            \MyInvoice\Service\Epo\EpoDirectClient::class => fn () => new \MyInvoice\Service\Epo\EpoDirectClient(
                null,
                (new \MyInvoice\Service\System\ManagedModeGuard($config))->effectiveFlag(
                    \MyInvoice\Service\System\ManagedModeGuard::KEY_EPO_TEST,
                    (bool) $config->get('epo_test', false),
                ) ? 'test' : 'production',
            ),

            // IpMatcher má v konstruktoru volitelný `?Config $config = null`. Autowiring
            // takový parametr neresolvuje (dosadí default null), takže clientIpFromRequest()
            // by ignorovalo cfg.ip_allowlist.trusted_proxies a vždy vracelo REMOTE_ADDR.
            // Za reverse proxy → audit log a brute-force lockout vidí IP proxy místo
            // reálného klienta. Explicitní injekce Configu to opravuje.
            IpMatcher::class       => fn (ContainerInterface $c) => new IpMatcher($c->get(Config::class)),

            // Repo sazba ČNB pro úrok z prodlení (penalizace) — interface → repository.
            \MyInvoice\Service\Penalty\RepoRateProvider::class => fn (ContainerInterface $c) => $c->get(\MyInvoice\Repository\CnbRepoRateRepository::class),

            // Kniha jízd — registry parserů detailních výpisů tankování. Pořadí = priorita:
            // konkrétní vendor parsery → AI fallback → univerzální summary (vždy uspěje).
            // PŘIDÁNÍ NOVÉ TANKOVACÍ SPOLEČNOSTI: vytvoř třídu implements FuelStatementParser
            // a vlož ji do tohoto pole PŘED AiFuelStatementParser.
            \MyInvoice\Service\Logbook\Fuel\FuelStatementParserRegistry::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Logbook\Fuel\FuelStatementParserRegistry([
                $c->get(\MyInvoice\Service\Logbook\Fuel\AxigonStatementParser::class),
                $c->get(\MyInvoice\Service\Logbook\Fuel\AiFuelStatementParser::class),
                $c->get(\MyInvoice\Service\Logbook\Fuel\SummaryFuelParser::class),
            ]),

            // Kanál datové schránky. PHP-DI neumí autowire interface → explicitní
            // bind, a je to jediné místo, kde se volba dopravy rozhoduje. Zbytek
            // modulu — fronta, ledger, číselník, trezor, inbox, cron i UI —
            // o žádné knihovně ani bráně neví a vědět nesmí.
            //
            // ── Fail-closed rozcestník ──────────────────────────────────────
            // Je-li zaregistrovaná a zapnutá odesílací brána VČETNĚ certifikátu,
            // bindne se `GatewayIsdsTransport`. Ten neodesílá (nemůže — mezi
            // přípravou a odesláním stojí člověk, viz jeho docblock), ale říká
            // pravdu o tom, kudy cesta ven vede a že po bráně NEVEDE čtení
            // schránky. Jinak zůstává `UnavailableIsdsTransport`: nenastavená
            // instalace nesmí spadnout, ale musí hlásit srozumitelnou překážku.
            //
            // Rozhodnutí ovlivňuje jen TEXT překážky. Povolení cokoliv odeslat
            // dává až `IsdsGatewayRegistrationService::load()` v okamžiku
            // odesílání — a ten hází pojmenované chyby.
            \MyInvoice\Service\Submission\Channel\Isds\IsdsTransport::class => function (ContainerInterface $c) {
                $registrations = $c->get(
                    \MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayRegistrationService::class,
                );

                return \MyInvoice\Service\Submission\Channel\Isds\Gateway\GatewayIsdsTransport::isConfigured($registrations)
                    ? $c->get(\MyInvoice\Service\Submission\Channel\Isds\Gateway\GatewayIsdsTransport::class)
                    : $c->get(\MyInvoice\Service\Submission\Channel\Isds\UnavailableIsdsTransport::class);
            },

            // Testovací šev registrace brány — bez něj by se `GatewayIsdsTransport`
            // nedal otestovat bez databázového schématu.
            \MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayRegistrationSource::class => fn (ContainerInterface $c)
                => $c->get(\MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayRegistrationService::class),

            \MyInvoice\Service\Submission\Channel\Isds\IsdsAuthFlowStore::class => fn (ContainerInterface $c)
                => $c->get(\MyInvoice\Repository\Submission\IsdsAuthFlowRepository::class),

            // Odesílací brána ISDS (`SetConcept`). Vědomě NENÍ implementací
            // `IsdsTransport` výš: brána umí JEN odesílat, a to s člověkem
            // uprostřed (dvě přesměrování prohlížeče), kdežto `IsdsTransport`
            // je synchronní port včetně čtení schránky a doručenek. Čtecí
            // operace proto dál fail-closed odmítají — brána je neumí a
            // předstírat to by byla lež. Viz `odesilaci_brana_ISDS.pdf` v. 1.11.
            //
            // Klient má poslední parametr jako testovací šev (`$httpDouble`).
            // Produkce ho musí dostat jako `null`, aby si postavil vlastní cURL
            // volání s timeouty, TLS 1.2 a klientským certifikátem.
            \MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayClient::class => fn (ContainerInterface $c)
                => new \MyInvoice\Service\Submission\Channel\Isds\Gateway\SoapIsdsGatewayClient(
                    new \MyInvoice\Service\Submission\Channel\Isds\Gateway\SetConceptRequestWriter(),
                    $c->get(LoggerInterface::class),
                    null,
                ),
            \MyInvoice\Service\Submission\Channel\Epo\EpoAttemptStatusReader::class => fn (ContainerInterface $c)
                => $c->get(\MyInvoice\Service\Submission\Channel\Epo\EpoAttemptStatusRepository::class),
            \MyInvoice\Service\Submission\SubmissionArtifactResolver::class => fn (ContainerInterface $c)
                => $c->get(\MyInvoice\Service\Submission\DefaultSubmissionArtifactResolver::class),

            // F7 — AI extrakční brána (LlmGateway). PHP-DI s useAttributes(false)
            // neumí autowire interface → explicitní bind na router. Konkrétní klienti
            // (AnthropicClient), LlmProviderRegistry i ResidencyPolicy zůstávají autowired.
            \MyInvoice\Service\Import\LlmGatewayInterface::class => fn (ContainerInterface $c) => $c->get(\MyInvoice\Service\Import\LlmGatewayRouter::class),
            \MyInvoice\Service\Ai\EmbeddingGatewayInterface::class => fn (ContainerInterface $c) => $c->get(\MyInvoice\Service\Ai\EmbeddingGatewayRouter::class),
            \MyInvoice\Service\Ai\LlmClassifierInterface::class => fn (ContainerInterface $c) => $c->get(\MyInvoice\Service\Ai\LlmClassifierRouter::class),
            \MyInvoice\Service\Ai\AiProviderHttpClient::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Ai\AiProviderHttpClient(
                $c->get(Connection::class),
                $c->get(\MyInvoice\Service\Import\LlmProviderRegistry::class),
                $c->get(\MyInvoice\Service\Import\ResidencyPolicy::class),
                $c->get(\MyInvoice\Service\Ai\AiDpaGate::class),
                $c->get(LoggerInterface::class),
            ),

            // Non-Anthropic klienti mají volitelný `?GuzzleHttp\Client $http = null`
            // (test seam). Autowire by ho vyplnil bare Guzzle (bez http_errors=false →
            // non-2xx by házelo výjimky) → definujeme explicitně s $http = null,
            // aby si klient postavil vlastní nakonfigurovaný Guzzle interně.
            \MyInvoice\Service\Import\AzureOpenAiClient::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Import\AzureOpenAiClient(
                $c->get(Connection::class),
                $c->get(\MyInvoice\Service\Auth\SecretEncryption::class),
                $c->get(LoggerInterface::class),
            ),
            \MyInvoice\Service\Import\OpenAiClient::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Import\OpenAiClient(
                $c->get(Connection::class),
                $c->get(\MyInvoice\Service\Auth\SecretEncryption::class),
                $c->get(LoggerInterface::class),
            ),
            \MyInvoice\Service\Import\GeminiClient::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Import\GeminiClient(
                $c->get(Connection::class),
                $c->get(\MyInvoice\Service\Auth\SecretEncryption::class),
                $c->get(LoggerInterface::class),
            ),

            // "Upload PDF" bankovních výpisů — registry bank-specifických PDF parserů
            // (banky bez GPC/ABO exportu). PŘIDÁNÍ NOVÉ BANKY: nová třída implements
            // BankStatementPdfParserInterface a vlož ji do tohoto pole.
            \MyInvoice\Service\Bank\Pdf\BankStatementPdfParserRegistry::class => fn (ContainerInterface $c) => new \MyInvoice\Service\Bank\Pdf\BankStatementPdfParserRegistry([
                $c->get(\MyInvoice\Service\Bank\Pdf\CreditasStatementPdfParser::class),
                $c->get(\MyInvoice\Service\Bank\Pdf\CsobStatementPdfParser::class),
                $c->get(\MyInvoice\Service\Bank\Pdf\KbStatementPdfParser::class),
                $c->get(\MyInvoice\Service\Bank\Pdf\RaiffeisenbankStatementPdfParser::class),
            ]),
        ]);

        $container = $builder->build();

        // Entity cache se resolvuje EAGERNĚ, na rozdíl od middleware. Důvod je
        // korektnost, ne výkon: registrace do WriteWatcheru musí proběhnout dřív,
        // než stihne poběžet první zápis. Kdyby se cache vytvořila až u prvního
        // čtení, zápis provedený před tím by neinvalidoval generaci v Redisu a
        // NÁSLEDUJÍCÍ request by dostal zastaralá data.
        //
        // Cena je jen konstrukce objektu — spojení do Redisu si RedisFactory
        // otevírá až při prvním použití.
        $container->get(\MyInvoice\Infrastructure\Cache\EntityCache::class);

        return $container;
    }

    /** @return App<ContainerInterface|null> */
    public static function buildApp(): App
    {
        $container = self::buildContainer();
        $config    = $container->get(Config::class);

        AppFactory::setContainer($container);

        $app = AppFactory::create();

        Routes::register($app);

        // AŽ ZA registrací: otisk cache se počítá z rout, které jsou reálně
        // v paměti tohohle procesu (viz enableRouteCache()).
        self::enableRouteCache($app, $config);

        // Slim 4 LIFO: poslední `add()` = NEJVĚTŠÍ vrstva = běží JAKO PRVNÍ.
        // Cílový order běhu (outside → inside):
        //   ApiVersionRewrite → MaintenanceMode → IpAllowlist → FirstRunLock → TenantDomain → Auth → ApiRequestLog → SessionLock → RequireMfa → License → StorageQuotaReadOnly → DemoReadOnly → SupplierScope → Permission → ApiScope → RateLimit → CSRF → WebAuthnBodyLimit → Routing → BodyParsing → Action
        // → add() v opačném pořadí (innermost první):
        //
        // ⚠️ Middleware se předávají jako CLASS-STRING, ne jako instance. Slim je pak
        // přidá přes addDeferred() a z kontejneru je vytáhne až ve chvíli, kdy k nim
        // request skutečně sestoupí. Předávat `$container->get(...)` znamenalo postavit
        // všech 14 (i s jejich stromy závislostí) na KAŽDÝ request — naměřeno +7,2 ms
        // a +79 načtených tříd, i když request skončil na 401 hned v první vrstvě.
        // Pořadí zůstává beze změny; líné je jen vytvoření instance.
        $app->addBodyParsingMiddleware();                            // innermost
        $app->addRoutingMiddleware();
        $app->add(WebAuthnBodyLimitMiddleware::class);               // limit raw WebAuthn credential před JSON parsingem
        $app->add(CsrfMiddleware::class);                            // potřebuje session z Auth (bearer skip)
        $app->add(RateLimitMiddleware::class);                       // chrání forgot/setup/login/ARES + per-user/per-token limity
        $app->add(ApiScopeMiddleware::class);                        // bearer-only: enforce read / read_write scope
        $app->add(PermissionMiddleware::class);                      // jemnozrnná route permission kontrola
        $app->add(SupplierScopeMiddleware::class);                   // multi-supplier scope (X-Supplier-Id / token's supplier_id)
        $app->add(DemoReadOnlyMiddleware::class);                    // demo: globální zákaz business mutací
        // H-10: vyčerpaná disková kvóta → 507 na zápisy, čtení projde. Sedí ZA
        // autentizací schválně: údaje o zaplnění se tak nedostanou anonymnímu
        // volajícímu a přihlášení (výjimka) stihne proběhnout dřív, než se
        // pravidlo uplatní. Bez `app.managed` a bez nastavené kvóty je to
        // konfigurační no-op — self-hosted instalace se nikdy nezamkne sama.
        $app->add(StorageQuotaReadOnlyMiddleware::class);            // 507 + X-Storage-Quota-* hlavičky
        $app->add(LicenseMiddleware::class);                         // E4: denní obnova tokenu + blokace komerčních modulů po expiraci
        $app->add(RequireMfaMiddleware::class);                      // assurance + povinný MFA setup (bearer skip)
        $app->add(SessionLockMiddleware::class);                     // autoritativní idle/manual lock browser session
        $app->add(ApiRequestLogMiddleware::class);                   // bearer-only: per-request log do api_request_log (nad scope/právy, ať jsou vidět i zamítnutá volání)
        $app->add(AuthMiddleware::class);                            // načte session nebo bearer token
        $app->add(TenantDomainMiddleware::class);                   // Host autoritativně určí tenant před autentizací
        $app->add(FirstRunLockMiddleware::class);                    // 423 pokud users prázdná
        $app->add(IpAllowlistMiddleware::class);                     // outermost user mw
        // H-03: zámek údržby musí být PŘED autentizací (503 dostane i nepřihlášený)
        // a zároveň UVNITŘ ApiVersionRewrite, aby se výjimka pro /api/health testovala
        // na už přepsané cestě (jinak by /api/v1/health v údržbě spadlo na 503).
        $app->add(MaintenanceModeMiddleware::class);                 // 503 + Retry-After na vše kromě /api/health
        $app->add(new ApiVersionRewriteMiddleware());                // /api/v1/* → /api/* před vším ostatním

        // Stack trace v odpovědích API na cizí infrastruktuře nemá co dělat,
        // takže `app.debug` ve spravované instalaci neplatí, i kdyby se do
        // konfigurace dostal.
        $displayErrors = (new \MyInvoice\Service\System\ManagedModeGuard($config))->effectiveFlag(
            \MyInvoice\Service\System\ManagedModeGuard::KEY_APP_DEBUG,
            (bool) $config->get('app.debug', false),
        );
        $app->addErrorMiddleware($displayErrors, true, true, $container->get(LoggerInterface::class));

        return $app;
    }

    /**
     * Zapne FastRoute cache — předpočítané regexy pro routy.
     *
     * Bez ní se vzory parsují při KAŽDÉM requestu: naměřeno 10,2 ms dispatch
     * proti 1,5 ms s cache. Registrace rout se tím neušetří (Route objekty
     * vznikají vždy), ale kompilace regexů ano — a ta je tou drahou částí.
     *
     * INVALIDACE JMÉNEM SOUBORU: v názvu je otisk rout, které TENHLE proces
     * právě zaregistroval — identifikátor, metody a vzor každé z nich. Volá se
     * proto až ZA {@see Routes::register()}; Slim si cacheFile přečte líně, až
     * při prvním dispatchi, takže pozdější nastavení nic nerozbíjí.
     *
     * Otisk se dřív počítal z mtime + velikosti `Routes.php` a z `VERSION`,
     * tedy z metadat na disku. To je proti procesu, který má v opcache ještě
     * starý bytekód (nebo běží nad rozpracovaným swapem), slepé: disk už hlásí
     * novou verzi, ale proces registruje staré routy — a zapíše je pod jméno
     * nové. Ostatní procesy pak čtou mapping, kde `route757` znamená něco
     * jiného, než co mají v paměti, a aplikace tiše routuje na cizí handlery
     * (25. 8. 2026: `GET /api/dashboard/summary` končilo na `undoBatch`
     * s `invalid_batch`). Otisk z živých rout tuhle skulinu zavírá: proces se
     * starým kódem si sáhne na jiný soubor a ten správný nezamoří.
     *
     * Selhání je vždy tiché a bezpečné: bez cache aplikace jen běží pomaleji.
     *
     * @param App<ContainerInterface|null> $app
     */
    private static function enableRouteCache(App $app, Config $config): void
    {
        try {
            if ((bool) $config->get('cache.routes_enabled', true) === false) {
                return;
            }
            // Pod PHPUnitem ne: testy staví aplikaci tisíckrát a sdílený soubor
            // by mezi nimi přenášel stav.
            if (defined('PHPUNIT_COMPOSER_INSTALL')) {
                return;
            }

            $collector = $app->getRouteCollector();
            $signature = '';
            foreach ($collector->getRoutes() as $identifier => $route) {
                $signature .= $identifier . ' ' . implode(',', $route->getMethods())
                    . ' ' . $route->getPattern() . "\n";
            }
            if ($signature === '') {
                return; // routy ještě nejsou zaregistrované — cache by byla prázdná
            }

            $fingerprint = substr(hash('xxh128', $signature), 0, 16);
            $dir = ($config->dataDir() ?? self::rootDir())
                . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
            if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
                return;
            }
            if (!is_writable($dir)) {
                return;
            }

            $cacheFile = $dir . DIRECTORY_SEPARATOR . 'routes-' . $fingerprint . '.php';

            // Nový otisk → ukliď cache předchozích verzí, ať se v data adresáři
            // nehromadí po každém deployi jeden soubor navíc.
            if (!is_file($cacheFile)) {
                foreach (glob($dir . DIRECTORY_SEPARATOR . 'routes-*.php') ?: [] as $stale) {
                    @unlink($stale);
                }
            }

            $collector->setCacheFile($cacheFile);
        } catch (\Throwable) {
            // Cache je optimalizace — nikdy nesmí bránit startu aplikace.
        }
    }

    /**
     * Resolve class names ze slotů cfg.bank_email.notice_parsers na instance.
     * Validaci (interface, prázdný/duplicitní key) dělá konstruktor
     * BankEmailNoticeParserRepository — tady se jen vypínají sloty (null/false/'').
     *
     * @return list<object>
     */
    private static function bankEmailNoticeParsers(ContainerInterface $container, Config $config): array
    {
        $classes = $config->get('bank_email.notice_parsers', []);
        if (!is_array($classes) || $classes === []) {
            throw new \RuntimeException('cfg.bank_email.notice_parsers musí být neprázdná mapa parser slot => class.');
        }

        $parsers = [];
        foreach ($classes as $class) {
            if ($class === null || $class === false || trim((string) $class) === '') {
                continue; // slot vypnutý přes cfg.php
            }
            $parsers[] = $container->get(trim((string) $class));
        }

        return $parsers;
    }

    private static function resolveLogLevel(string $level): \Monolog\Level
    {
        return match (strtolower($level)) {
            'debug'   => \Monolog\Level::Debug,
            'info'    => \Monolog\Level::Info,
            'notice'  => \Monolog\Level::Notice,
            'warning' => \Monolog\Level::Warning,
            'error'   => \Monolog\Level::Error,
            default   => \Monolog\Level::Info,
        };
    }
}
