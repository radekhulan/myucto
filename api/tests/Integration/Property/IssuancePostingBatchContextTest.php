<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePostingBatchContext;
use MyInvoice\Service\Property\PropertyInput;
use PHPUnit\Framework\TestCase;

final class IssuancePostingBatchContextTest extends TestCase
{
    private Connection $db;

    protected function setUp():void
    {
        $this->db=Connection::withoutSharedTestConnection(static fn()=>Bootstrap::buildContainer()->get(Connection::class));
        self::assertStringEndsWith('_test',(string)$this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->beginTransaction();
    }

    protected function tearDown():void
    {
        if(isset($this->db)&&$this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();
        $this->db->close();
    }

    public function testCapabilityRejectsStaleReusedAndForeignClaims():void
    {
        $lines=[['account_code'=>'315','side'=>'debit','amount'=>'1.00'],['account_code'=>'324','side'=>'credit','amount'=>'1.00']];
        $meta=['entry_date'=>'2026-01-01','posted_by'=>1,'user_id'=>1];
        $context=$this->context([[1,$lines,$meta]]);
        try{$context->claim($this->db,1,1,$lines,$meta+['description'=>'changed']);self::fail('Stale capability accepted changed metadata.');}
        catch(\LogicException $e){self::assertStringContainsString('Stale',$e->getMessage());}
        $foreign=Connection::withoutSharedTestConnection(static fn()=>Bootstrap::buildContainer()->get(Connection::class));
        try{$context->claim($foreign,1,1,$lines,$meta);self::fail('Capability accepted a foreign connection.');}
        catch(\LogicException $e){self::assertStringContainsString('outside',$e->getMessage());}
        finally{$foreign->close();}
        self::assertSame('2026-01-01',$context->claim($this->db,1,1,$lines,$meta)['entry_date']);
        try{$context->claim($this->db,1,1,$lines,$meta);self::fail('Capability ticket was reused.');}
        catch(\LogicException $e){self::assertStringContainsString('reused',$e->getMessage());}
        $context->close();
    }

    public function testCommitBeginAndOuterSavepointRollbackInvalidateGeneration():void
    {
        $lines=[['account_code'=>'315','side'=>'debit','amount'=>'1.00'],['account_code'=>'324','side'=>'credit','amount'=>'1.00']];
        $meta=['entry_date'=>'2026-01-01'];
        $context=$this->context([[1,$lines,$meta]]);
        $this->db->pdo()->commit();$this->db->pdo()->beginTransaction();
        try{$context->claim($this->db,1,1,$lines,$meta);self::fail('Capability survived commit and a new transaction on the same PDO.');}
        catch(\LogicException $e){self::assertStringContainsString('generation changed',$e->getMessage());}
        $context->close();

        $this->db->pdo()->exec('SAVEPOINT outer_owner');
        $context=$this->context([[2,$lines,$meta],[3,$lines,$meta]]);
        self::assertSame(2,$context->claim($this->db,1,2,$lines,$meta)['source_id']);
        $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT outer_owner');
        try{$context->claim($this->db,1,3,$lines,$meta);self::fail('Capability survived rollback past its generation savepoint.');}
        catch(\LogicException $e){self::assertStringContainsString('generation changed',$e->getMessage());}
        $context->close();
    }

    private function context(array $definitions):IssuancePostingBatchContext
    {
        $documents=[];
        foreach($definitions as[$id,$lines,$meta])$documents[]=['source_id'=>$id,'entry_date'=>$meta['entry_date'],'period'=>['id'=>1,'status'=>'open'],
            'request_hash'=>IssuancePostingBatchContext::requestHash(1,$id,$lines,$meta),'resolved_hash'=>PropertyInput::hash($lines),'resolved_lines'=>$lines];
        return IssuancePostingBatchContext::begin($this->db,1,[1=>'open'],null,$documents);
    }
}
