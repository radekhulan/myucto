<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountingPeriodRepository,ChartOfAccountsRepository};
use MyInvoice\Repository\Property\Opening\OpeningRepository;
use MyInvoice\Repository\Property\Payments\DebtRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Anonymization\{AnonymizationOptions,AnonymizationService,ChargesCloneScrubber,DatabaseCloner,IssuanceCloneScrubber,PaymentsCloneScrubber,PropertyCloneScrubber,Pseudonymizer,SettlementCloneScrubber,TextScrubber};
use MyInvoice\Service\Property\Opening\{OpeningException,OpeningReadProof};
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Tests\Support\{OwnedTestDatabaseCleanup,PropertyChargeFixture};
use PDO;
use PDOStatement;
use MyInvoice\Tests\Support\PropertyCloneBatchFixtureTestCase;
final class OpeningCloneBatchProofTest extends PropertyCloneBatchFixtureTestCase
{
    public function testOpeningCloneChecksWholeItemClosureOncePerTenant():void
    {
        $before=$this->financial(self::$source);$source=$this->fingerprint(self::$source);$db=$this->copy();$args=$this->prefix($db);$original=$db->pdo()->getAttribute(PDO::ATTR_STATEMENT_CLASS);OpeningCloneBatchStatement::$batches=0;$db->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS,[OpeningCloneBatchStatement::class]);
        try{$hashes=(new PaymentsCloneScrubber($db,$args[0]))->run($args[1],$args[2],$args[3]);$batches=OpeningCloneBatchStatement::$batches;}finally{$db->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS,$original);}
        self::assertSame($before,$this->financial($db));self::assertSame($source,$this->fingerprint(self::$source));
        foreach(self::$fixtures as $f){$old=$f['batch']['snapshot_hash'];$q=$db->pdo()->prepare('SELECT snapshot_hash,snapshot_json FROM property_opening_batches WHERE supplier_id=? AND id=?');$q->execute([$f['supplier'],$f['batch']['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);self::assertNotSame($old,$row['snapshot_hash']);self::assertSame($hashes[$old],$row['snapshot_hash']);self::assertStringNotContainsString('person@example.test',$row['snapshot_json']);}
        self::assertLessThanOrEqual(4*count(self::$fixtures)+2,$batches,'Opening and debt proof decoding must be bounded per tenant, including both native credit-origin checks. Observed '.$batches);
    }
    public function testOpeningBatchRejectsRehashedPhysicalCorruptionAndRollsBack():void
    {
        $source=$this->fingerprint(self::$source);$db=$this->copy();$args=$this->prefix($db);$q=$db->pdo()->query('SELECT id,supplier_id,fact_json FROM property_opening_components ORDER BY id LIMIT 1');$row=$q->fetch(PDO::FETCH_ASSOC);$fact=json_decode($row['fact_json'],true,flags:JSON_THROW_ON_ERROR);$fact['ledger_line']['amount']=bcadd($fact['ledger_line']['amount'],'1',2);$db->pdo()->prepare('UPDATE property_opening_components SET fact_json=?,fact_hash=? WHERE supplier_id=? AND id=?')->execute([json_encode($fact,JSON_THROW_ON_ERROR),PropertyInput::hash($fact),$row['supplier_id'],$row['id']]);$before=$this->fingerprint($db);
        try{(new PaymentsCloneScrubber($db,$args[0]))->run($args[1],$args[2],$args[3]);self::fail('A self-consistent fact hash must not replace the native physical ledger proof.');}catch(OpeningException $e){self::assertSame('property_opening_ledger_changed',$e->errorCode);}
        self::assertFalse($db->pdo()->inTransaction());self::assertSame($before,$this->fingerprint($db));self::assertSame($source,$this->fingerprint(self::$source));
    }
}
final class OpeningCloneBatchStatement extends PDOStatement
{
    public static int $batches=0;
    protected function __construct(){}
    public function execute(?array $params=null):bool{if(str_starts_with($this->queryString,'SELECT * FROM property_opening_batches WHERE supplier_id=? AND id=?'))self::$batches++;return parent::execute($params);}
}
