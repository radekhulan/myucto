<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OwnedCloneCleanupTest extends TestCase
{
    public static function callers(): array
    {
        $out=[];
        foreach ([
            ['Property\\PaymentWorkflowCloneTest','ownedSchema','myucto_workflow_clone_0123456789_test','_copy_test'],
            ['Property\\UnitPaymentsCloneTest','ownedSchema','myucto_payment_clone_0123456789_test','_copy_test'],
            ['Property\\ChargeIssuanceCloneTest','schema','myucto_issue_clone_0123456789_test','_copy_test'],
            ['Property\\Charges\\ChargesCloneScrubberTest','ownedSchema','myucto_charge_clone_0123456789_test','_clone_test'],
        ] as [$class,$field,$owned,$suffix]) {
            $out[$class]=['MyInvoice\\Tests\\Integration\\'.$class,[$field=>$owned,'targetSchema'=>$owned.$suffix],[$owned.$suffix,$owned]];
        }
        $schemas=['myucto_property_clone_0123456789_source_test','myucto_property_clone_0123456789_target_test','myucto_property_clone_0123456789_partial_test'];
        $out['property clone']=['MyInvoice\\Tests\\Integration\\Property\\PropertyCloneScrubberTest',['schemas'=>$schemas],array_reverse($schemas)];
        $schemas=['myucto_joint_scrub_src_0123456789_test','myucto_joint_scrub_dst_0123456789_test'];
        $out['joint allocation clone']=['MyInvoice\\Tests\\Integration\\Anonymization\\JointAllocationCloneScrubberTest',['schemas'=>$schemas],$schemas];
        return $out;
    }

    #[DataProvider('callers')]
    public function testActualTeardownRetriesEachOwnedDirectoryFailureWithoutChangingOrder(string $class,array $fields,array $schemas): void
    {
        $outcomes=[];$expected=[];
        foreach ($schemas as $schema) {
            $outcomes[]=self::dropError(1010,'Directory not empty');$outcomes[]=null;
            $sql='DROP DATABASE IF EXISTS `'.$schema.'`';$expected[]=$sql;$expected[]=$sql;
        }
        $server=new OwnedCloneDropPdo($outcomes);$failure=null;
        try {self::cleanup($class,$fields,$server);} catch (PDOException $error) {$failure=$error;}
        self::assertNull($failure,'Transient1010 escaped actual '.$class.'::tearDown');
        self::assertSame($expected,$server->statements);
        self::assertSame([],$server->remaining());
    }

    #[DataProvider('callers')]
    public function testActualTeardownPreservesPermissionFailureWithoutRetry(string $class,array $fields,array $schemas): void
    {
        $failure=self::dropError(1044,'Access denied');$server=new OwnedCloneDropPdo([$failure]);
        try {self::cleanup($class,$fields,$server);self::fail('Permission failure was swallowed.');}
        catch (PDOException $error) {self::assertSame($failure,$error);}
        self::assertCount(1,$server->statements);
        self::assertStringContainsString('`'.$schemas[0].'`',$server->statements[0]);
    }

    #[DataProvider('callers')]
    public function testActualTeardownBoundsPersistentDirectoryFailure(string $class,array $fields,array $schemas): void
    {
        $failure=self::dropError(1010,'Directory not empty');$server=new OwnedCloneDropPdo(array_fill(0,5,$failure));
        try {self::cleanup($class,$fields,$server);self::fail('Persistent failure was swallowed.');}
        catch (PDOException $error) {self::assertSame($failure,$error);}
        self::assertSame(array_fill(0,5,'DROP DATABASE IF EXISTS `'.$schemas[0].'`'),$server->statements);
        self::assertSame([],$server->remaining());
    }

    private static function cleanup(string $class,array $fields,PDO $server): void
    {
        $methods=array_values(array_filter((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC),static fn(\ReflectionMethod $method):bool=>str_starts_with($method->name,'test')));
        $test=new $class($methods[0]->name);
        foreach ($fields+['server'=>$server] as $name=>$value) (new \ReflectionProperty($test,$name))->setValue($test,$value);
        (new \ReflectionMethod($test,'tearDown'))->invoke($test);
    }

    private static function dropError(int $vendor,string $message): PDOException
    {
        $error=new PDOException($message);$error->errorInfo=['HY000',$vendor,$message];return $error;
    }
}

final class OwnedCloneDropPdo extends PDO
{
    public array $statements=[];
    public function __construct(private array $outcomes) {}
    public function exec(string $statement): int|false
    {
        $this->statements[]=$statement;
        if ($this->outcomes===[]) throw new \LogicException('Unexpected cleanup SQL.');
        $outcome=array_shift($this->outcomes);
        if ($outcome instanceof PDOException) throw $outcome;
        return 0;
    }
    public function remaining(): array {return $this->outcomes;}
}