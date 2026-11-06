<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxSavingsService;
use MyInvoice\Service\Tax\Return\TaxReturnService;
use PHPUnit\Framework\TestCase;

final class NonprofitAnnualConstantsWorkflowTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\TestWith([false])]
    #[\PHPUnit\Framework\Attributes\TestWith([true])]
    public function testFinalSavingUsesCapturedConstantsAfterAnnualOverrideChanges(bool $legacy): void
    {
        $fixture = new NonprofitDppoNativeWorkflowTest('testNativeFinalizationStoresActual504XmlAndOnlyActualTaxSaving');
        (new \ReflectionMethod($fixture, 'setUp'))->invoke($fixture);
        try {
            $db = (new \ReflectionProperty($fixture, 'db'))->getValue($fixture);
            self::assertInstanceOf(Connection::class, $db);
            $container = (new \ReflectionProperty($fixture, 'container'))->getValue($fixture);
            $supplier = (new \ReflectionProperty($fixture, 'supplier'))->getValue($fixture);
            $actor = (new \ReflectionProperty($fixture, 'actor'))->getValue($fixture);
            $constants = new TaxConstantsRepository($db);
            $annual = $constants->forExactYear(2026);
            $annual['nonprofit_reduction_rate'] = 0.25;
            $annual['nonprofit_reduction_min'] = 400000;
            $annual['nonprofit_reduction_max'] = 500000;
            $constants->upsert(2026, $annual);

            $period = (new \ReflectionProperty($fixture, 'period'))->getValue($fixture);
            $claim = $legacy ? 300000 : 400000;
            $inputs = ['nonprofit' => ['reduction_kc' => $claim, 'reduction_evidence' => 'Synthetic annual election']];
            $data = $container->get(\MyInvoice\Service\Tax\Nonprofit\NonprofitDppoDataProvider::class)->gather($supplier, 2026, $inputs);
            $capturedConstants = $legacy ? array_replace(\MyInvoice\Service\Tax\TaxConstants::forYear(2026), ['nonprofit_reduction_rate' => 0.30, 'nonprofit_reduction_min' => 300000, 'nonprofit_reduction_max' => 1000000, 'nonprofit_reduction_university_min' => 1000000, 'nonprofit_reduction_university_max' => 3000000]) : $constants->forExactYear(2026);
            $computed = (new \MyInvoice\Service\Tax\Return\DppoReturnCalculator())->compute($data, $inputs, $capturedConstants);
            $data['loss_appendix']=\MyInvoice\Service\Tax\Return\DppoLossAppendix::build($supplier,'po',2026,$data['period'],[],0,0,5);$data['source_manifest']['loss_appendix_hash']=$data['loss_appendix']['proof_hash'];
            $entity = $container->get(TaxReturnService::class)->nonprofitEntityProof($supplier, $period);
            if ($legacy) {
                foreach (array_keys(\MyInvoice\Service\Tax\Nonprofit\NonprofitDppoReduction::configuration($capturedConstants)) as $key) unset($capturedConstants[$key]);
                $record = ['data' => $data, 'inputs' => $inputs, 'constants' => $capturedConstants, 'entity_proof' => $entity];
                $record['filing_proof_hash'] = \MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules::hash($record);
            } else {
                $record = \MyInvoice\Service\Tax\Nonprofit\NonprofitDppoStoredProof::capture($data, $inputs, $capturedConstants, $entity);
            }
            $manifest = $data['source_manifest'] + ['nonprofit_filing_proof_hash' => $record['filing_proof_hash'], 'nonprofit_data_manifest_hash' => \MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules::hash($data['source_manifest'])];
            $snapshot = ['computed' => $computed, 'podklady' => ['accounting_decree' => '504', 'period' => $data['period'], 'nonprofit_calculation' => $record]];
            $built = $container->get(\MyInvoice\Service\Tax\Nonprofit\NonprofitDppoArchiveBuilder::class)->build($record, $computed);
            self::assertSame('passed', (new \MyInvoice\Service\Validation\XmlSchemaValidator())->validate($built['xml'], 'dppdp9')['status']);
            $verified = \MyInvoice\Service\Tax\Nonprofit\NonprofitDppoStoredProof::verify($snapshot, $manifest, $built['xml']);
            self::assertSame($legacy ? 'legacy' : 'v3', $verified['comparison_profile']);
            $repo = new \MyInvoice\Repository\TaxReturnRepository($db);
            $row = $repo->create($supplier, 2026, 'po', $inputs, $actor);
            $snapshotId = $repo->createSnapshot($row['id'], $supplier, $snapshot, $manifest, $built['xml'], 'passed', [], $actor);
            $repo->finalize($supplier, 2026, 'po', $snapshot, $row['row_version'], snapshotId: $snapshotId, finalizedBy: $actor);
            $ledger = new \MyInvoice\Service\Tax\Nonprofit\NonprofitTaxSavingsLedger($db);
            $before = $ledger->registerFinal($supplier, $snapshotId, $actor);
            self::assertSame($claim, $before['reduction_kc']);
            self::assertSame($legacy ? '6300000' : '8400000', $before['saving_cents']);
            self::assertSame($legacy ? 105000 : 84000, (int) $computed['tax']);
            $storedBefore = $repo->snapshot($supplier, $snapshotId);

            $annual['nonprofit_reduction_min'] = 0;
            $annual['nonprofit_reduction_max'] = 50000;
            $constants->upsert(2026, $annual);
            self::assertSame(50000, $constants->forExactYear(2026)['nonprofit_reduction_max']);
            self::assertSame($before, $ledger->source($supplier, $before['id']));
            self::assertSame($storedBefore, $repo->snapshot($supplier, $snapshotId));
            self::assertSame($legacy ? null : 500000, $storedBefore['snapshot_json']['podklady']['nonprofit_calculation']['constants']['nonprofit_reduction_max'] ?? null);
            self::assertSame('final',$container->get(TaxReturnService::class)->getReturn($supplier,2026,'po',$actor)['return']['status']);
            self::assertTrue($db->pdo()->inTransaction());
        } finally {
            (new \ReflectionMethod($fixture, 'tearDown'))->invoke($fixture);
        }
    }
}
