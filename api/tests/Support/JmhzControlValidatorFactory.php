<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use DI\Container;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCodebookCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1ControlEvaluator;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1ControlValidator;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecVersion;
use Psr\Container\ContainerInterface;

use function DI\factory;

final class JmhzControlValidatorFactory
{
    /** @var array<string,JmhzControlSourceCatalog> */
    private static array $controlCatalogs = [];
    private static ?JmhzExternalCodebookCatalog $externalCodebooks = null;
    /** @var array<string,JmhzCodebookCatalog> */
    private static array $codebooks = [];

    public static function create(
        ?PayrollRulesetProvider $rulesets = null,
        ?string $resourceRoot = null,
        JmhzSpecVersion $version = JmhzSpecVersion::V2026,
    ): JmhzScenario1ControlValidator {
        $rulesets ??= CzechPayrollRulesets2026::provider();
        if ($resourceRoot !== null) {
            return JmhzScenario1ControlValidator::create($rulesets, $resourceRoot, $version);
        }
        $catalog = self::$controlCatalogs[$version->value] ??= JmhzControlSourceCatalog::loadFor($version);
        $specPackages = new JmhzSpecPackageCatalog();
        $pin = $specPackages->requirePin($version);
        $codebooks = self::$codebooks[$version->value] ??= new JmhzCodebookCatalog($specPackages->load(
            $pin['package_key'],
            $pin['manifest_sha256'],
        ));

        return new JmhzScenario1ControlValidator(
            $catalog,
            new JmhzScenario1ControlEvaluator(
                $catalog->parameters(),
                new JmhzDeadlinePolicy($rulesets),
                self::externalCodebooks(),
                $codebooks,
                $version,
            ),
        );
    }

    public static function externalCodebooks(): JmhzExternalCodebookCatalog
    {
        return self::$externalCodebooks ??= new JmhzExternalCodebookCatalog(new JmhzSpecPackageCatalog());
    }

    public static function configure(ContainerInterface $container): void
    {
        if (!$container instanceof Container) {
            throw new \LogicException('Mzdová testovací fixture vyžaduje PHP-DI kontejner.');
        }
        $container->set(JmhzExternalCodebookCatalog::class, self::externalCodebooks());
        $container->set(JmhzScenario1ControlValidator::class, factory(
            static fn (ContainerInterface $current): JmhzScenario1ControlValidator => self::create(
                $current->get(PayrollRulesetProvider::class),
            ),
        ));
    }
}
