<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Nonprofit\Membership;

use MyInvoice\Service\Nonprofit\Membership\MembershipChargeRequest;
use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MembershipChargeRequestTest extends TestCase
{
    #[DataProvider('invalidAmounts')]
    public function testInvalidAmountShapeAndRangeUseMembershipValidation(mixed $amount, bool $include): void
    {
        $request = $this->validRequest();
        if ($include) $request['amount'] = $amount;
        else unset($request['amount']);

        try {
            MembershipChargeRequest::issue($request);
            self::fail('Invalid amount was accepted.');
        } catch (MembershipException $error) {
            self::assertSame('nonprofit_membership_invalid', $error->errorCode);
            self::assertSame(422, $error->httpStatus);
        }
    }

    public static function invalidAmounts(): iterable
    {
        yield 'omitted' => [null, false];
        yield 'null' => [null, true];
        yield 'array' => [['100.00'], true];
        yield 'boolean' => [true, true];
        yield 'empty string' => ['', true];
        yield 'nonnumeric' => ['one hundred', true];
        yield 'infinite float' => [INF, true];
        yield 'not-a-number float' => [NAN, true];
        yield 'zero' => ['0.00', true];
        yield 'negative' => ['-0.01', true];
        yield 'over maximum' => ['10000000000000.00', true];
    }

    public function testNumericScalarsAreCanonicalized(): void
    {
        $integer = $this->validRequest();
        $integer['amount'] = 100;
        $float = $this->validRequest();
        $float['amount'] = 100.5;

        self::assertSame('100.00', MembershipChargeRequest::issue($integer)['amount']);
        self::assertSame('100.50', MembershipChargeRequest::issue($float)['amount']);
    }

    #[DataProvider('malformedFields')]
    public function testOtherMalformedRequestFieldsUseMembershipValidation(string $field, mixed $value): void
    {
        $request = $this->validRequest();
        $request[$field] = $value;

        try {
            MembershipChargeRequest::issue($request);
            self::fail('Malformed request field was accepted.');
        } catch (MembershipException $error) {
            self::assertSame('nonprofit_membership_invalid', $error->errorCode);
            self::assertSame(422, $error->httpStatus);
        }
    }

    public static function malformedFields(): iterable
    {
        yield 'member id' => ['member_id', '1'];
        yield 'title key' => ['title_key', []];
        yield 'period start' => ['period_from', []];
        yield 'period end' => ['period_to', false];
        yield 'accounting date' => ['accounting_date', 20260101];
        yield 'due date' => ['due_date', null];
        yield 'receivable account' => ['receivable_account', []];
        yield 'revenue account' => ['revenue_account', true];
        yield 'empty receivable account' => ['receivable_account', '   '];
        yield 'empty revenue account' => ['revenue_account', ''];
        yield 'title reference' => ['title_reference', []];
        yield 'evidence' => ['evidence', false];
        yield 'reason' => ['reason', 1];
        yield 'nonreciprocal gate' => ['nonreciprocal', 1];
        yield 'classification missing axis' => ['classification', ['activity_value_id' => 1, 'activity_kind_value_id' => 2]];
        yield 'classification scalar' => ['classification', 1];
        yield 'classification invalid id' => ['classification', ['activity_value_id' => 1, 'activity_kind_value_id' => 2, 'tax_regime_value_id' => '3']];
    }

    #[DataProvider('malformedReversalFields')]
    public function testMalformedReversalFieldsUseMembershipValidation(string $field, mixed $value): void
    {
        $request = ['case_id' => 1, 'accounting_date' => '2026-12-31', 'reason' => 'Synthetic approved reversal'];
        $request[$field] = $value;

        try {
            MembershipChargeRequest::reversal($request);
            self::fail('Malformed reversal field was accepted.');
        } catch (MembershipException $error) {
            self::assertSame('nonprofit_membership_invalid', $error->errorCode);
            self::assertSame(422, $error->httpStatus);
        }
    }

    public static function malformedReversalFields(): iterable
    {
        yield 'case id' => ['case_id', []];
        yield 'accounting date' => ['accounting_date', []];
        yield 'reason' => ['reason', []];
    }

    public function testCanonicalStringAmountIsAcceptedAtMaximumBoundary(): void
    {
        $request = $this->validRequest();
        $request['amount'] = '9999999999999.99';

        $parsed = MembershipChargeRequest::issue($request);

        self::assertSame('9999999999999.99', $parsed['amount']);
        self::assertSame(999999999999999, $parsed['amount_cents']);
    }

    private function validRequest(): array
    {
        return ['member_id' => 1, 'title_key' => 'annual-2026', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31',
            'accounting_date' => '2026-01-01', 'due_date' => '2026-01-31', 'amount' => '100.00',
            'receivable_account' => '315.100', 'revenue_account' => '684.100', 'title_reference' => 'Synthetic statutes article 5',
            'evidence' => 'Synthetic approved annual resolution', 'nonreciprocal' => true, 'reason' => 'Synthetic annual membership charge',
            'classification' => ['activity_value_id' => 1, 'activity_kind_value_id' => 2, 'tax_regime_value_id' => 3]];
    }
}
