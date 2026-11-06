<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Service\Anonymization\Streaming\CanonicalJsonProfile;
use MyInvoice\Service\Anonymization\Streaming\CanonicalJsonSpool;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;

final readonly class J6SourceEvidencePageHasher
{
    public const string SCHEMA = 'nonprofit-j6-source-evidence.v1';

    public function __construct(private CanonicalJsonSpool $canonical) {}

    public function page(string $kind, int $ordinal, array $rows): array
    {
        self::kind($kind);
        if ($ordinal < 0 || $rows === [] || count($rows) > 500 || !array_is_list($rows)) {
            throw new \InvalidArgumentException('J6 source evidence page is invalid.');
        }
        $payload = ['schema' => self::SCHEMA, 'kind' => $kind, 'ordinal' => $ordinal, 'rows' => $rows];
        $canonical = $this->canonical->canonicalize([NonprofitTaxRules::canonicalJson($payload)], CanonicalJsonProfile::NONPROFIT);
        return ['payload' => $payload, 'descriptor' => ['ordinal' => $ordinal, 'chunk_hash' => $canonical->sha256,
            'row_count' => count($rows), 'canonical_bytes' => $canonical->bytes]];
    }

    public function verify(array $payload, string $expectedHash): array
    {
        $chunk = $this->page((string) ($payload['kind'] ?? ''), (int) ($payload['ordinal'] ?? -1), $payload['rows'] ?? []);
        if (!hash_equals($expectedHash, $chunk['descriptor']['chunk_hash'])) {
            throw new \UnexpectedValueException('J6 source evidence page hash is invalid.');
        }
        return $chunk['descriptor'];
    }

    public function startRoot(J6CalculationHeader $header, string $kind): \HashContext
    {
        self::kind($kind);
        $context = hash_init('sha256');
        hash_update($context, "J6SOURCEEVIDENCEROOT1\0" . self::SCHEMA . "\0{$kind}\0"
            . NonprofitTaxRules::canonicalJson($header->toArray()) . "\0");
        return $context;
    }

    public function updateRoot(\HashContext $context, array $descriptor): void
    {
        $json = NonprofitTaxRules::canonicalJson($descriptor);
        hash_update($context, pack('N', strlen($json)) . $json);
    }

    public function finishRoot(\HashContext $context, int $chunks, int $rows): string
    {
        if ($chunks < 0 || $rows < 0) throw new \InvalidArgumentException('J6 source evidence totals are invalid.');
        hash_update($context, "\0TOTALS\0" . pack('J', $chunks) . pack('J', $rows));
        return hash_final($context);
    }

    public static function kind(string $kind): void
    {
        if (!in_array($kind, ['funding_reference', 'funding_history'], true)) {
            throw new \InvalidArgumentException('J6 source evidence page kind is invalid.');
        }
    }
}
