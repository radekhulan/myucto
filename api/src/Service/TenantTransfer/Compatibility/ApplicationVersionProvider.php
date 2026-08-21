<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Compatibility;

use MyInvoice\Bootstrap;

/** Načte přesnou aplikační verzi z release souboru VERSION. */
final class ApplicationVersionProvider
{
    private readonly string $versionFile;

    public function __construct(?string $versionFile = null)
    {
        $this->versionFile = $versionFile ?? Bootstrap::rootDir() . '/VERSION';
    }

    public function current(): string
    {
        $value = is_file($this->versionFile)
            ? @file_get_contents($this->versionFile)
            : false;
        $version = is_string($value) ? trim($value) : '';
        if ($version === '') {
            throw new ApplicationVersionUnavailable(
                'missing',
                'Release VERSION není dostupná.',
            );
        }
        if (strlen($version) > 128
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9.+_-]{0,127}$/D', $version) !== 1
        ) {
            throw new ApplicationVersionUnavailable(
                'invalid',
                'Release VERSION není platná.',
            );
        }
        return $version;
    }
}
