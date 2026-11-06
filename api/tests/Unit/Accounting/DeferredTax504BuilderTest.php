<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Service\Accounting\Closing\{ClosingException, DeferredTax504Builder};
use PHPUnit\Framework\TestCase;

final class DeferredTax504BuilderTest extends TestCase
{
    public function testOwnMethodIsOptInAndRecognizesGrossRecoverableAssetWithoutOffsettingLiability(): void
    {
        self::assertFalse(DeferredTax504Builder::assessment([])['enabled']);
        $body = $this->decision();
        $body['titles'] = [$this->title('taxable', '1000.00'), $this->title('deductible', '800.00', '500.00')];
        $out = DeferredTax504Builder::assessment($body);
        self::assertSame(21000, $out['closing']['liability_cents']);
        self::assertSame(10500, $out['closing']['asset_cents']);
        self::assertSame(6300, $out['closing']['unrecognized_asset_cents']);
        self::assertSame(2, count($out['closing']['cells']));
        $lines = DeferredTax504Builder::movements($out, [], 'closing');
        self::assertSame(['592', '959.100', '349', '592'], array_column($lines, 'account_code'));
        self::assertSame([210.0, 210.0, 105.0, 105.0], array_column($lines, 'amount'));
        self::assertSame(['debit', 'credit', 'debit', 'credit'], array_column($lines, 'side'));
    }

    public function testReductionReleasesOnlyExistingLiabilityAndAssetAsSeparateJointCells(): void
    {
        $body = $this->decision();
        $body['titles'] = [$this->title('taxable', '200.00')];
        $out = DeferredTax504Builder::assessment($body);
        $existing = [
            ['kind' => 'liability', 'balance_cents' => 21000, 'dimensions' => [1 => 11, 2 => 21]],
            ['kind' => 'asset', 'balance_cents' => 10500, 'dimensions' => [1 => 11, 2 => 22]],
        ];
        $lines = DeferredTax504Builder::movements($out, $existing, 'closing');
        self::assertSame(['959.100', '592', '592', '349'], array_column($lines, 'account_code'));
        self::assertSame([168.0, 168.0, 105.0, 105.0], array_column($lines, 'amount'));
        self::assertSame([1 => 11, 2 => 22], $lines[3]['dimensions']);
    }

    public function testFirstAdoptionRequiresSeparateDecisionAndUsesEquityOnlyForOpeningBatch(): void
    {
        $body = $this->decision();
        $body['method']['first_adoption'] = true;
        $body['opening_titles'] = [$this->title('taxable', '1000.00')];
        try { DeferredTax504Builder::assessment($body); self::fail('No implicit first-adoption equity decision.'); }
        catch (ClosingException $e) { self::assertSame('deferred_tax_method_required', $e->errorCode); }
        $body['method']['first_adoption_reason'] = 'Synthetic documented retrospective method decision';
        $body['titles'] = [$this->title('taxable', '1200.00')];
        $out = DeferredTax504Builder::assessment($body);
        self::assertSame(['932', '959.100'], array_column(DeferredTax504Builder::movements($out, [], 'opening'), 'account_code'));
        $lines = DeferredTax504Builder::movements($out, [['kind' => 'liability', 'balance_cents' => 21000, 'dimensions' => [1 => 11, 2 => 21]]], 'closing');
        self::assertSame([42.0, 42.0], array_column($lines, 'amount'));
    }

    public function testMissingRecoverabilityAndExcessRecoverableAmountAreRejected(): void
    {
        foreach (['missing', 'excess'] as $case) {
            $body = $this->decision();
            $title = $this->title('deductible', '100.00', $case === 'excess' ? '100.01' : '100.00');
            if ($case === 'missing') unset($title['recoverability_evidence']);
            $body['titles'] = [$title];
            try { DeferredTax504Builder::assessment($body); self::fail('Unproven recoverability must not be recognized.'); }
            catch (ClosingException $e) { self::assertSame('deferred_tax_recoverability_required', $e->errorCode); }
        }
    }

    public function testExactCentInputsAndPerTitleRoundingAreExplicit(): void
    {
        $body = $this->decision();
        $body['titles'] = [$this->title('taxable', '0.03')];
        self::assertSame(1, DeferredTax504Builder::assessment($body)['closing']['liability_cents']);
        foreach (['0.005', '100.001', '1e2'] as $invalid) {
            $body['titles'] = [$this->title('taxable', $invalid)];
            try { DeferredTax504Builder::assessment($body); self::fail('Exact amount required.'); }
            catch (ClosingException $e) { self::assertNotSame('', $e->errorCode); }
        }
    }

    private function decision(): array
    {
        return ['method' => ['enabled' => true, 'version' => '504-deferred-own-v1', 'reference' => 'SYN-METHOD',
            'faithful_image_reason' => 'Synthetic future tax effect', 'standard_departure_reason' => 'Synthetic recognition of recoverable asset',
            'presentation_reason' => 'Synthetic separate current and deferred tax presentation', 'first_adoption' => false], 'titles' => []];
    }

    private function title(string $kind, string $difference, ?string $recognized = null): array
    {
        return ['reference' => 'SYN-' . $kind, 'evidence' => 'Synthetic temporary difference evidence', 'temporary_confirmed' => true,
            'kind' => $kind, 'difference' => $difference, 'rate_percent' => '21.00', 'rate_basis' => 'Synthetic applicable enacted tax rate',
            'recognized_difference' => $recognized, 'recoverability_evidence' => 'Synthetic probable future taxable base',
            'dimensions' => [1 => 11, 2 => $kind === 'taxable' ? 21 : 22]];
    }
}
