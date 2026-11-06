<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use DI\Container;
use MyInvoice\Bootstrap;

final class PayrollTestContainer
{
    public static function build(): Container
    {
        $container = Bootstrap::buildContainer();
        if (!$container instanceof Container) {
            throw new \LogicException('Mzdová testovací fixture vyžaduje PHP-DI kontejner.');
        }
        JmhzControlValidatorFactory::configure($container);

        return $container;
    }
}
