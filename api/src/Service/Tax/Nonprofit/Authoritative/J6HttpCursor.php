<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;

final readonly class J6HttpCursor
{
    private const PURPOSE = 'nonprofit-tax-http-cursor:v1';

    public function __construct(private SecretEncryption $encryption) {}

    public function encode(array $context, int|string|array $position): string
    {
        if ($context === []) throw new \InvalidArgumentException('J6 cursor context is required.');
        $plain = NonprofitTaxRules::canonicalJson(['version' => 1, 'context' => $context, 'position' => $position]);
        $encrypted = $this->encryption->encryptFor($plain, self::PURPOSE);
        return rtrim(strtr(base64_encode($encrypted), '+/', '-_'), '=');
    }

    public function decode(string $token, array $context): int|string|array
    {
        if ($token === '' || strlen($token) > 8192 || preg_match('/^[A-Za-z0-9_-]+$/D', $token) !== 1) self::invalid();
        $raw = base64_decode(strtr($token . str_repeat('=', (4 - strlen($token) % 4) % 4), '-_', '+/'), true);
        if ($raw === false || !str_starts_with($raw, 'enc:v2:')
            || !hash_equals($token, rtrim(strtr(base64_encode($raw), '+/', '-_'), '='))) self::invalid();
        try {
            $plain = $this->encryption->decryptFor($raw, self::PURPOSE);
            $payload = json_decode($plain, true, 16, JSON_THROW_ON_ERROR);
        } catch (\RuntimeException | \JsonException) {
            self::invalid();
        }
        if (!is_array($payload) || array_keys($payload) !== ['context', 'position', 'version']
            || $payload['version'] !== 1 || !is_array($payload['context'])
            || (!is_int($payload['position']) && !is_string($payload['position']) && !is_array($payload['position']))
            || !hash_equals($plain, NonprofitTaxRules::canonicalJson($payload))) self::invalid();
        if (!hash_equals(NonprofitTaxRules::canonicalJson($context), NonprofitTaxRules::canonicalJson($payload['context']))) {
            throw new NonprofitTaxAssessmentException('nonprofit_tax_cursor_stale', 'Kurzor neodpovídá aktuální firmě, konceptu a jeho revizi.', 409);
        }
        return $payload['position'];
    }

    private static function invalid(): never
    {
        throw new NonprofitTaxAssessmentException('invalid_input', 'Kurzor daňového posouzení není platný.', 400);
    }
}
