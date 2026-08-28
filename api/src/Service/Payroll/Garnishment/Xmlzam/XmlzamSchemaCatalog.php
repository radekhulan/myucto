<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment\Xmlzam;

use DomainException;

final class XmlzamSchemaCatalog
{
    public const SHA256 = '7e6f8dfc27118f9788da1a067fd55526cc5373f3b2395c81d3af822423fb7657';

    public function schemaPath(): string
    {
        $path = dirname(__DIR__, 5) . '/xsd/xmlzam/EPXSD-1.0.xsd';
        if (!is_file($path)) {
            throw new DomainException('Oficiální schéma XMLZAM není dostupné.');
        }
        $hash = hash_file('sha256', $path);
        if (!is_string($hash) || !hash_equals(self::SHA256, strtolower($hash))) {
            throw new DomainException('Otisk oficiálního schématu XMLZAM nesouhlasí.');
        }

        return $path;
    }
}
