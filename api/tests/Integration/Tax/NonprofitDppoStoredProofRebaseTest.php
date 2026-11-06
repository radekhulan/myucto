<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoComputation;
use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoStoredProof;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Service\Tax\Return\DppoLossAppendix;
use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use MyInvoice\Service\Tax\Return\TaxReturnService;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Přenos zmrazeného důkazu 504 na pseudonymizované podklady anonymizované kopie:
 * verze záznamu se zachová a každá změna typové nebo finanční projekce se odmítne,
 * i když útočník všechny vnitřní otisky přepočítá.
 */
final class NonprofitDppoStoredProofRebaseTest extends TestCase
{
    private NonprofitDppoNativeWorkflowTest $workflow;

    private array $record;

    protected function setUp(): void
    {
        $this->workflow = new NonprofitDppoNativeWorkflowTest('testNativeFinalizationStoresActual504XmlAndOnlyActualTaxSaving');
        (new \ReflectionMethod($this->workflow, 'setUp'))->invoke($this->workflow);
        $container = $this->property('container');
        $supplier = $this->property('supplier');
        $period = $this->property('period');
        $inputs = ['nonprofit' => ['reduction_kc' => 300000, 'reduction_evidence' => 'Synthetic rebase election']];
        $data = $container->get(\MyInvoice\Service\Tax\Nonprofit\NonprofitDppoDataProvider::class)->gather($supplier, 2026, $inputs);
        $data['loss_appendix'] = DppoLossAppendix::build($supplier, 'po', 2026, $data['period'], [], 0, 0, 5);
        $data['source_manifest']['loss_appendix_hash'] = $data['loss_appendix']['proof_hash'];
        $record = NonprofitDppoStoredProof::capture($data, $inputs, TaxConstants::forYear(2026), $container->get(TaxReturnService::class)->nonprofitEntityProof($supplier, $period));
        $this->record = json_decode(json_encode($record, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function tearDown(): void
    {
        (new \ReflectionMethod($this->workflow, 'tearDown'))->invoke($this->workflow);
    }

    public static function versions(): array
    {
        return [['v3'], ['v2'], ['legacy']];
    }

    #[DataProvider('versions')]
    public function testPseudonymizedIdentityKeepsVersionShapeAndFinancialProjection(string $version): void
    {
        $record = $this->record($version);
        $data = $record['data'];
        $entity = $record['entity_proof'];
        $entity['supplier']['company_name'] = 'Pseudonym Spolek z.s.';
        $data['statements']['balance_sheet']['entity']['name'] = 'Pseudonym Spolek z.s.';
        $data['source_manifest']['statements_hash'] = NonprofitTaxRules::hash($data['statements']);

        $rebased = NonprofitDppoStoredProof::rebaseHistorical($record, $data, $record['inputs'], $entity);

        self::assertSame($version, NonprofitDppoStoredProof::comparisonProfile($rebased));
        $fields = array_keys($rebased);
        sort($fields);
        $expected = array_keys($record);
        sort($expected);
        self::assertSame($expected, $fields);
        self::assertSame(array_key_exists('loss_appendix', $record['data']), array_key_exists('loss_appendix', $rebased['data']));
        self::assertNotSame($record['filing_proof_hash'], $rebased['filing_proof_hash']);
        self::assertSame(NonprofitDppoStoredProof::semanticProjection($record), NonprofitDppoStoredProof::semanticProjection($rebased));
        $calculator = new DppoReturnCalculator();
        NonprofitDppoStoredProof::assertCalculation($calculator->compute($rebased['data'], $rebased['inputs'], NonprofitDppoStoredProof::constantsForRecord($rebased)), $rebased);
        if ($version === 'v3') {
            self::assertSame($record['form_manifest'], $rebased['form_manifest']);
        } else {
            self::assertArrayNotHasKey('form_manifest', $rebased);
            self::assertArrayNotHasKey('line_coverage', $rebased);
        }
    }

    public static function tampering(): array
    {
        return [
            'buňka rozvahy' => ['statement_cell'],
            'konec období' => ['period'],
            'výsledek hospodaření' => ['vh'],
            'zbytek ztráty přílohy E' => ['loss_remaining'],
            'běžná ztráta přílohy E' => ['loss_current'],
            'období ztráty přílohy E' => ['loss_period'],
            'částka úpravy můstku' => ['bridge'],
        ];
    }

    #[DataProvider('tampering')]
    public function testRecomputedHashesDoNotHideSemanticTampering(string $case): void
    {
        $record = $this->record('v3');
        $data = $record['data'];
        switch ($case) {
            case 'statement_cell':
                $data['statements']['balance_sheet'] = self::bumpFirstNumber($data['statements']['balance_sheet']);
                break;
            case 'period':
                $data['period']['ends_on'] = '2026-06-29';
                break;
            case 'vh':
                $data['vh'] = (float) $data['vh'] + 1;
                break;
            case 'loss_remaining':
            case 'loss_current':
            case 'loss_period':
                $card = $data['loss_appendix'];
                unset($card['proof_hash']);
                $card['rows'][] = ['loss_id' => null, 'starts_on' => $case === 'loss_period' ? '2025-07-02' : $data['period']['starts_on'], 'ends_on' => $data['period']['ends_on'],
                    'amount_cents' => '100000', 'applied_before_cents' => '0', 'applied_cents' => '0', 'remaining_cents' => $case === 'loss_remaining' ? '0' : '100000'];
                $card['remaining_cents'] = $case === 'loss_remaining' ? '0' : '100000';
                $data['loss_appendix'] = $card + ['proof_hash' => NonprofitTaxRules::hash($card)];
                $data['source_manifest']['loss_appendix_hash'] = $data['loss_appendix']['proof_hash'];
                break;
            case 'bridge':
                $data['nonprofit']['bridge']['line_cents'][40] += 100;
                $data['nonprofit'] = NonprofitDppoComputation::seal($data['nonprofit']);
                $data['source_manifest']['nonprofit_proof_hash'] = $data['nonprofit']['proof_hash'];
                break;
        }
        $data['source_manifest']['statements_hash'] = NonprofitTaxRules::hash($data['statements']);

        $this->expectException(TaxReturnException::class);
        NonprofitDppoStoredProof::rebaseHistorical($record, $data, $record['inputs'], $record['entity_proof']);
    }

    /**
     * Záznam ve tvaru, který ukládaly starší verze aplikace: v2 a neverzovaný bez polí v3.
     */
    private function record(string $version): array
    {
        $record = $this->record;
        if ($version === 'v3') {
            return $record;
        }
        unset($record['form_manifest'], $record['line_coverage'], $record['proof_hash_mode'], $record['filing_proof_hash'],
            $record['data']['j6_receipt'], $record['data']['loss_appendix'], $record['data']['nonprofit']['j6_receipt'],
            $record['data']['source_manifest']['j6_receipt_hash'], $record['data']['source_manifest']['loss_appendix_hash']);
        $record['data']['nonprofit'] = NonprofitDppoComputation::seal($record['data']['nonprofit']);
        $record['data']['source_manifest']['nonprofit_proof_hash'] = $record['data']['nonprofit']['proof_hash'];
        if ($version === 'v2') {
            $record['version'] = 2;
        } else {
            unset($record['version']);
        }
        $record['filing_proof_hash'] = NonprofitDppoStoredProof::filingProofHash($record);

        return $record;
    }

    private static function bumpFirstNumber(array $statement): array
    {
        $done = false;
        $walk = static function (array $node) use (&$walk, &$done): array {
            foreach ($node as $name => $value) {
                if ($done) {
                    break;
                }
                if (is_array($value)) {
                    $node[$name] = $walk($value);
                } elseif ((is_int($value) || is_float($value)) && !in_array($name, ['adis_row', 'row', 'order', 'level'], true)) {
                    $node[$name] = $value + 1000;
                    $done = true;
                }
            }

            return $node;
        };
        foreach ($statement as $key => $value) {
            if (!$done && is_array($value) && !in_array($key, ['entity', 'period'], true)) {
                $statement[$key] = $walk($value);
            }
        }
        self::assertTrue($done, 'Výkaz nemá číselnou buňku.');

        return $statement;
    }

    private function property(string $name): mixed
    {
        return (new \ReflectionProperty($this->workflow, $name))->getValue($this->workflow);
    }
}
