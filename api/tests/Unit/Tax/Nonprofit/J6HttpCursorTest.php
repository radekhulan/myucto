<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Nonprofit;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6HttpCursor;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class J6HttpCursorTest extends TestCase
{
    private function encryption(string $key = 'a'): SecretEncryption
    {
        return new SecretEncryption(new Config(['app' => ['secret_encryption_key' => base64_encode(str_repeat($key, 32))]]));
    }

    private function context(): array
    {
        return ['actor_id' => 1, 'supplier_id' => 2, 'period_id' => 3, 'object_kind' => 'draft', 'object_id' => 4,
            'revision' => 5, 'kind' => 'funding-members', 'source_input_hash' => str_repeat('a', 64),
            'pool_kind' => 'revenue', 'anchor' => 'synthetic-source', 'limit' => 100];
    }

    public function testRoundTripKeepsInternalPositionOpaqueAndAcceptsEquivalentContextOrder(): void
    {
        $cursor = new J6HttpCursor($this->encryption());
        $position = ['after_ordinal' => -1, 'row_offset' => 100, 'finding_offset' => 0];
        $token = $cursor->encode($this->context(), $position);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/D', $token);
        self::assertStringNotContainsString('synthetic-source', $token);
        self::assertEquals($position, $cursor->decode($token, array_reverse($this->context(), true)));
        self::assertNotSame($token, $cursor->encode($this->context(), $position));
    }

    public static function boundaries(): array
    {
        return array_map(static fn(string $key): array => [$key], [
            'actor_id', 'supplier_id', 'period_id', 'object_kind', 'object_id', 'revision', 'kind',
            'source_input_hash', 'pool_kind', 'anchor', 'limit',
        ]);
    }

    #[DataProvider('boundaries')]
    public function testCursorCannotCrossAnyOwnerRevisionOrPoolBoundary(string $key): void
    {
        $cursor = new J6HttpCursor($this->encryption());
        $context = $this->context();
        $token = $cursor->encode($context, 'synthetic-member');
        $context[$key] = is_int($context[$key]) ? $context[$key] + 1 : $context[$key] . '-changed';
        try {
            $cursor->decode($token, $context);
            self::fail('Cursor crossed its owner boundary');
        } catch (NonprofitTaxAssessmentException $error) {
            self::assertSame('nonprofit_tax_cursor_stale', $error->errorCode);
            self::assertSame(409, $error->httpStatus);
        }
    }

    public function testPlaintextLegacyCiphertextWrongPurposeAndTamperedTokenAreRejected(): void
    {
        $encryption = $this->encryption();
        $cursor = new J6HttpCursor($encryption);
        $plain = NonprofitTaxRules::canonicalJson(['version' => 1, 'context' => $this->context(), 'position' => 1]);
        $wire = static fn(string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $valid = $cursor->encode($this->context(), 1);
        foreach ([$wire($plain), $wire($encryption->encrypt($plain)),
            $wire($encryption->encryptFor($plain, 'another-purpose')), $valid . '=', str_repeat('x', 8193),
            substr($valid, 0, -2) . 'zz'] as $invalid) {
            try {
                $cursor->decode($invalid, $this->context());
                self::fail('Unauthenticated cursor was accepted');
            } catch (NonprofitTaxAssessmentException $error) {
                self::assertSame('invalid_input', $error->errorCode);
                self::assertSame(400, $error->httpStatus);
            }
        }
    }

    public function testAnotherInstanceCannotDecryptThePosition(): void
    {
        $token = (new J6HttpCursor($this->encryption()))->encode($this->context(), ['source_id' => 'source', 'cost_id' => 'cost']);
        $this->expectException(NonprofitTaxAssessmentException::class);
        $this->expectExceptionMessage('Kurzor daňového posouzení není platný.');
        (new J6HttpCursor($this->encryption('b')))->decode($token, $this->context());
    }
}
