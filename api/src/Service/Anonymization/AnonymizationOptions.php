<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

/**
 * Parametry jednoho běhu anonymizované kopie.
 */
final readonly class AnonymizationOptions
{
    public function __construct(
        public string $source,
        public string $target,
        public bool $replace = false,
        #[\SensitiveParameter] public ?string $seed = null,
        #[\SensitiveParameter] public ?string $password = null,
        public ?string $filesFrom = null,
        public ?string $filesOut = null,
        public ?string $dumpPath = null,
        public ?string $dumpBinary = null,
        public ?string $sourceKeysPath = null,
        public ?string $targetKeysPath = null,
        public bool $olderSource = false,
    ) {}

    public static function isSafeDatabaseName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9_]{1,64}$/D', $name) === 1;
    }
}
