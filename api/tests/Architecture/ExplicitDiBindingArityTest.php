<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Bootstrap;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Explicitní DI binding v Bootstrapu nesmí zapomenout na konstruktorový parametr.
 *
 * PHP-DI **nevyplní optional class-typed parametr** (ten s `= null`) — použije default.
 * Proto mají některé služby v Bootstrapu ruční `new X(...)` binding. Jenže když se pak
 * ke konstruktoru přidá další závislost a binding se neupraví, zůstane null a feature
 * TIŠE zmizí: žádná chyba, jen prázdná karta na dashboardu.
 *
 * Přesně to se stalo s CrmAggregationService — přibyla JournalIntegrityService, binding
 * zůstal dvouargumentový a celá karta „Zkontroluj integritu deníku" se přestala zobrazovat.
 * Odhalila to až kontrola v prohlížeči, ne testy.
 *
 * Guard je záměrně úzký: kontroluje jen služby s ručním bindingem, kde je null-závislost
 * tichým vypnutím funkce. Nový záznam v seznamu = nový ruční binding, který má tuhle past.
 */
#[Group('integration')]
final class ExplicitDiBindingArityTest extends TestCase
{
    /**
     * Služby s ručním bindingem v Bootstrap::buildApp(), u kterých musí být VŠECHNY
     * konstruktorové závislosti skutečně vyplněné.
     *
     * @var list<class-string>
     */
    private const MUST_BE_FULLY_WIRED = [
        \MyInvoice\Service\Crm\CrmAggregationService::class,
        // Obě mají volitelný ?EntityCache. Bez vyplnění spadnou na průchozí
        // EntityCache::disabled() a cache v produkci tiše nedělá nic — přesně
        // to se stalo při prvním nasazení, než se doplnily bindy v Bootstrapu.
        \MyInvoice\Service\License\LicenseService::class,
        \MyInvoice\Service\Tenant\SupplierAccessResolver::class,
        \MyInvoice\Security\UserRoleProfile::class,
        \MyInvoice\Action\Auth\MeAction::class,
        // Obě mají volitelný ?SharedCertificateResolver. Bez vyplnění by
        // certifikát navázaný na sdílený trezor (migrace 1711) v produkci
        // nešel odemknout vůbec, přestože testy se závislostí předanou ručně
        // zůstanou zelené.
        \MyInvoice\Service\Submission\SubmissionCredentialService::class,
        \MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayRegistrationService::class,
    ];

    /**
     * Závislosti, které se ZÁMĚRNĚ staví líně až při prvním použití, ne v DI.
     *
     * Klíč je `Třída::$parametr`, hodnota důvod — bez důvodu se sem nic
     * nepřidává, jinak by se výjimka stala odpadkovým košem na cokoli, co
     * zrovna padá.
     *
     * @var array<string,string>
     */
    private const LAZY_BY_DESIGN = [
        'MyInvoice\Service\License\LicenseService::$telemetry' =>
            'Telemetrie se staví až při prvním odeslání (LicenseService::telemetry() → '
            . 'TelemetryPayloadBuilder::forRuntime). Vyplňovat ji v DI by znamenalo skládat '
            . 'sondu nad databází při každém sestavení kontejneru, i když se telemetrie '
            . 'vůbec neodešle — a její selhání nesmí ovlivnit obnovu licence.',
    ];

    public function testExplicitlyBoundServicesGetAllConstructorDependencies(): void
    {
        if (!is_file(dirname(__DIR__, 3) . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje — test vyžaduje DI kontejner.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
        } catch (\Throwable $e) {
            self::markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $missing = [];
        foreach (self::MUST_BE_FULLY_WIRED as $class) {
            $instance = $container->get($class);
            $ctor = (new \ReflectionClass($class))->getConstructor();
            self::assertNotNull($ctor, $class . ' nemá konstruktor — patří ještě do seznamu?');

            foreach ($ctor->getParameters() as $param) {
                $type = $param->getType();
                // Zajímají jen class-typed závislosti (skalární configy řešit nemá smysl).
                if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }
                $key = $class . '::$' . $param->getName();
                if (isset(self::LAZY_BY_DESIGN[$key])) {
                    continue;
                }

                // Parametr nemusí být promovaný na stejnojmennou vlastnost.
                // Bez téhle větve skončí kontrola ReflectionException a padne
                // jako chyba testu, ne jako srozumitelný nález.
                if (!property_exists($class, $param->getName())) {
                    $missing[] = sprintf(
                        '%s::$%s (%s) — parametr se nejmenuje stejně jako vlastnost, '
                            . 'takže se nedá ověřit; přejmenuj vlastnost, nebo parametr '
                            . 'zapiš do LAZY_BY_DESIGN i s důvodem',
                        $class,
                        $param->getName(),
                        $type->getName()
                    );
                    continue;
                }

                $prop = new \ReflectionProperty($class, $param->getName());
                if (!$prop->isInitialized($instance) || $prop->getValue($instance) === null) {
                    $missing[] = sprintf('%s::$%s (%s)', $class, $param->getName(), $type->getName());
                }
            }
        }

        self::assertSame([], $missing, sprintf(
            "Ruční DI binding v Bootstrapu nevyplnil závislost:\n  %s\n\n"
                . "PHP-DI optional class-param sám nedoplní — přidej argument do `new …(…)`\n"
                . "v Bootstrap::buildApp(). Jinak feature tiše zmizí bez jediné chyby.",
            implode("\n  ", $missing),
        ));
    }
}
