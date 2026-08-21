<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Compatibility;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;

/** Načte immutable revision z release artefaktu nebo explicitní dev konfigurace. */
final class BuildRevisionProvider
{
    private readonly string $revisionFile;

    public function __construct(
        private readonly Config $config,
        ?string $revisionFile = null,
    ) {
        $this->revisionFile = $revisionFile ?? Bootstrap::rootDir() . '/BUILD_REVISION';
    }

    public function current(): string
    {
        $configured = $this->normalize($this->config->get('app.build_revision'));
        $fromFile = is_file($this->revisionFile)
            ? $this->normalize(@file_get_contents($this->revisionFile))
            : null;
        if ($fromFile !== null && in_array(strtolower($fromFile), ['unknown', 'unavailable'], true)) {
            $fromFile = null;
        }

        if ($configured !== null && !$this->isValid($configured)) {
            throw new BuildRevisionUnavailable('invalid', 'Nakonfigurovaná build revision není platná.');
        }
        if ($fromFile !== null && !$this->isValid($fromFile)) {
            throw new BuildRevisionUnavailable('invalid', 'Release BUILD_REVISION není platná.');
        }
        if ($configured !== null && $fromFile !== null && !hash_equals($fromFile, $configured)) {
            throw new BuildRevisionUnavailable(
                'mismatch',
                'Nakonfigurovaná build revision se neshoduje s release artefaktem.',
            );
        }
        $revision = $fromFile ?? $configured;
        if ($revision === null) {
            throw new BuildRevisionUnavailable(
                'missing',
                'Immutable build revision není dostupná; transfer zůstává vypnutý.',
            );
        }
        return $revision;
    }

    private function normalize(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        return trim($value);
    }

    private function isValid(string $revision): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{6,127}$/D', $revision) === 1
            && !in_array(strtolower($revision), ['unknown', 'unavailable'], true);
    }
}
