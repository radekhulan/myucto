<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property\Services\Settlement;
use MyInvoice\Service\Property\Services\Settlement\SettlementDocument;
use MyInvoice\Repository\Property\Services\Settlement\SettlementDocumentRepository;
use PHPUnit\Framework\TestCase;
final class SettlementNativeSegmentProofTest extends TestCase
{
 private SettlementCrossDocumentClosingGuardTest $owner;
 protected function setUp():void {$this->owner=new SettlementCrossDocumentClosingGuardTest('testCostCannotBeConsumedAcrossIndependentAdvanceAndDocument');(new \ReflectionMethod($this->owner,'setUp'))->invoke($this->owner);}
 protected function tearDown():void {(new \ReflectionMethod($this->owner,'tearDown'))->invoke($this->owner);}
 private function data():array {return(new \ReflectionMethod($this->owner,'nativeFixture'))->invoke($this->owner,'800000');}
 private function save(array $data,array $facts):array {$container=(new \ReflectionProperty($this->owner,'container'))->getValue($this->owner);return $container->get(SettlementDocumentRepository::class)->save($data['f']['supplier'],SettlementDocument::fromEvidence($facts),[$data['selector']],'2026-12-31',0,$facts['segment_key'],$data['f']['actor']);}
 public function testNativeSourceAndOriginalDocumentAreNonzero():void {$d=$this->data();self::assertSame('1200000',$d['native']['origins'][0]['prescribed_cents']);self::assertGreaterThan(0,$d['saved']['id']);self::assertSame('2026-01-01',$d['f']['issue_request']['period_from']);self::assertSame('2026-12-31',$d['f']['issue_request']['period_to']);}
 public function testNative2026SourceCannotProveDisjoint2030Segment():void {$d=$this->data();$facts=$d['doc']->snapshot();unset($facts['amounts']);$facts['segment_key']='SYNTHETIC-DISJOINT-2030';$facts['period_from']='2030-01-01';$facts['period_to']='2030-12-31';$this->expectException(\DomainException::class);$this->save($d,$facts);}
 public function testBoundaryOverlapRemainsAccepted():void {$d=$this->data();$facts=$d['doc']->snapshot();unset($facts['amounts']);$facts['segment_key']='SYNTHETIC-BOUNDARY';$facts['period_from']='2026-12-31';$facts['period_to']='2027-12-31';self::assertGreaterThan(0,$this->save($d,$facts)['id']);}
 public function testForgedSourcePeriodCannotReplaceNativeProof():void {$d=$this->data();$facts=$d['doc']->snapshot();unset($facts['amounts']);$facts['segment_key']='SYNTHETIC-FORGED-PERIOD';$facts['period_from']='2030-01-01';$facts['period_to']='2030-12-31';$facts['origins'][0]['source_period']=['from'=>'2030-01-01','to'=>'2030-12-31'];$this->expectException(\DomainException::class);$this->save($d,$facts);}
}
