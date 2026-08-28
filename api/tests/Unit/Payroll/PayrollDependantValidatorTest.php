<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\PayrollDependantValidator;
use PHPUnit\Framework\TestCase;

final class PayrollDependantValidatorTest extends TestCase
{
    private PayrollDependantValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PayrollDependantValidator();
    }

    public function testNormalizesChildWithBirthNumber(): void
    {
        $result = $this->validator->validateDependant($this->dependant());

        self::assertSame('child_own', $result['relation']);
        self::assertSame('010101/0008', $result['birth_number']);
        self::assertTrue($result['birth_number_present']);
        self::assertSame('2001-01-01', $result['birth_date']);
        self::assertSame('Syntetické', $result['given_name']);
        self::assertSame('Dítě', $result['family_name']);
        self::assertNull($result['existence_to']);
    }

    public function testBirthNumberMustMatchBirthDate(): void
    {
        $input = $this->dependant(['birth_date' => '2002-01-01', 'existence_from' => '2002-01-01']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rodné číslo neodpovídá');
        $this->validator->validateDependant($input);
    }

    public function testMaskedBirthNumberIsRejected(): void
    {
        $input = $this->dependant(['birth_number' => '••••0008']);

        $this->expectException(InvalidArgumentException::class);
        $this->validator->validateDependant($input);
    }

    public function testExistenceCannotStartBeforeBirth(): void
    {
        $input = $this->dependant(['existence_from' => '2000-12-31']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('před datem narození');
        $this->validator->validateDependant($input);
    }

    public function testUnknownRelationIsRejected(): void
    {
        $input = $this->dependant(['relation' => 'cousin']);

        $this->expectException(InvalidArgumentException::class);
        $this->validator->validateDependant($input);
    }

    public function testDependantWithoutBirthNumberIsAllowed(): void
    {
        $input = $this->dependant();
        unset($input['birth_number']);

        $result = $this->validator->validateDependant($input);

        self::assertFalse($result['birth_number_present']);
        self::assertNull($result['birth_number']);
    }

    public function testVerifiedClaimAllowsMissingEvidenceReference(): void
    {
        $input = $this->claim(['evidence_reference' => null]);

        $result = $this->validator->validateClaim($input);

        self::assertSame('verified', $result['evidence_status']);
        self::assertNull($result['evidence_reference']);
    }

    public function testUnverifiedClaimMustNotCarryEvidence(): void
    {
        $input = $this->claim(['evidence_status' => 'unverified']);

        $this->expectException(InvalidArgumentException::class);
        $this->validator->validateClaim($input);
    }

    public function testClaimMustStartOnFirstDayOfMonth(): void
    {
        $input = $this->claim(['effective_from' => '2026-03-15']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('prvním dnem měsíce');
        $this->validator->validateClaim($input);
    }

    public function testClaimMustEndOnLastDayOfMonth(): void
    {
        $input = $this->claim(['effective_to' => '2026-06-15']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('posledním dnem měsíce');
        $this->validator->validateClaim($input);
    }

    public function testClaimOrderMustBePositive(): void
    {
        $input = $this->claim(['child_order' => 0]);

        $this->expectException(InvalidArgumentException::class);
        $this->validator->validateClaim($input);
    }

    public function testNonCanonicalEvidenceReferenceIsRejected(): void
    {
        $input = $this->claim(['evidence_reference' => 'doklad s mezerou']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('kanonická reference');
        $this->validator->validateClaim($input);
    }

    public function testNormalizesClaim(): void
    {
        $result = $this->validator->validateClaim($this->claim());

        self::assertSame(1, $result['child_order']);
        self::assertSame('verified', $result['evidence_status']);
        self::assertSame('document:child-claim', $result['evidence_reference']);
        self::assertTrue($result['shared_household_confirmed']);
        self::assertTrue($result['other_claimant_excluded']);
        self::assertSame('own_household', $result['claim_reason']);
        self::assertSame('2026-01-01', $result['effective_from']);
        self::assertNull($result['effective_to']);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function dependant(array $overrides = []): array
    {
        return $overrides + [
            'relation' => 'child_own',
            'full_name' => 'Syntetické Dítě',
            'given_name' => 'Syntetické',
            'family_name' => 'Dítě',
            'birth_date' => '2001-01-01',
            'birth_number' => '010101/0008',
            'ztp_p' => false,
            'student' => false,
            'existence_from' => '2001-01-01',
            'existence_to' => null,
            'note' => null,
        ];
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function claim(array $overrides = []): array
    {
        return $overrides + [
            'child_order' => 1,
            'claim_reason' => 'own_household',
            'evidence_status' => 'verified',
            'evidence_reference' => 'document:child-claim',
            'shared_household_confirmed' => true,
            'other_claimant_excluded' => true,
            'ztp_p' => false,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ];
    }
}
