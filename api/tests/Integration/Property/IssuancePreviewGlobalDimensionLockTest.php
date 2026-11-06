<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceContextService;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use PHPUnit\Framework\TestCase;

/**
 * Náhled předpisu jedné firmy nesmí výhradně zamknout sdílené dimenze (supplier_id IS NULL)
 * ostatním firmám a nesmí vidět dimenze cizí skupiny firem.
 */
final class IssuancePreviewGlobalDimensionLockTest extends TestCase
{
    use ChargeIssuanceFixture;

    private array $connections=[];
    private array $cleanup=[];

    protected function tearDown(): void
    {
        foreach($this->connections as $db) if($db->pdo()->inTransaction()) $db->pdo()->rollBack();
        if($this->cleanup!==[]) {
            $pdo=$this->connections[0]->pdo();
            foreach($this->cleanup['suppliers'] as $supplier) $pdo->prepare('UPDATE supplier SET supplier_group_id=NULL WHERE id=?')->execute([$supplier]);
            foreach($this->cleanup['types'] as $type) {
                $pdo->prepare('DELETE FROM dimension_values WHERE type_id=?')->execute([$type]);
                $pdo->prepare('DELETE FROM dimension_types WHERE id=?')->execute([$type]);
            }
            foreach($this->cleanup['groups'] as $group) $pdo->prepare('DELETE FROM supplier_groups WHERE id=?')->execute([$group]);
        }
        foreach($this->connections as $db) $db->close();
        $this->connections=[];
    }

    public function testCurrentPreviewsOfGroupFirmsShareGroupDimensions(): void
    {
        $a=$this->connection();
        $b=$this->connection();
        $a->pdo()->beginTransaction();
        $first=$this->issuanceFixture($a);
        $second=$this->issuanceFixture($a);
        $a->pdo()->commit();
        $group=$this->group($a,[$first['supplier'],$second['supplier']]);
        [$type,$value]=$this->groupDimension($a,$group);

        $a->pdo()->beginTransaction();
        (new IssuancePreviewService($a))->preview($first['supplier'],$first['issue_request'],true);

        // Druhá firma skupiny čte tytéž sdílené dimenze pod sdíleným zámkem (jako její vlastní náhled).
        // Výhradní zámek první firmy by ji zablokoval až do konce jejího vydání předpisů.
        $b->pdo()->exec('SET SESSION innodb_lock_wait_timeout=2');
        $b->pdo()->beginTransaction();
        $q=$b->pdo()->prepare('SELECT v.id FROM dimension_values v JOIN dimension_types t ON t.id=v.type_id WHERE v.id=? AND t.id=? LOCK IN SHARE MODE');
        $q->execute([$value,$type]);
        self::assertSame($value,(int)$q->fetchColumn());
    }

    public function testForeignGroupDimensionIsNotOfferedNorAccepted(): void
    {
        $a=$this->connection();
        $a->pdo()->beginTransaction();
        $f=$this->issuanceFixture($a);
        $a->pdo()->commit();
        $foreign=$this->group($a,[]);
        [$type,$value]=$this->groupDimension($a,$foreign);

        $context=(new IssuanceContextService($a))->context($f['supplier'],$f['issue_request']['version_id'],'2026-01-01T10:00:00+01:00');
        self::assertNotContains($type,array_column($context['dimensions'],'id'));

        $request=$f['issue_request'];
        $request['accounts'][0]['dimensions']=[(string)$type=>$value];
        try {
            (new IssuancePreviewService($a))->preview($f['supplier'],$request);
            self::fail('Dimension of a foreign supplier group was accepted on a charge.');
        } catch(\MyInvoice\Service\Property\Charges\Issuance\IssuanceException|\MyInvoice\Service\Property\PropertyException $e) {
            self::assertContains($e->errorCode,['property_charge_issue_dimension_invalid','property_invalid_input']);
        }
    }

    private function group(Connection $db,array $suppliers): int
    {
        $db->pdo()->exec("INSERT INTO supplier_groups (name) VALUES ('Synthetic dimension group')");
        $group=(int)$db->pdo()->lastInsertId();
        $this->cleanup['groups'][]=$group;
        $this->cleanup['suppliers']??=[];
        $this->cleanup['types']??=[];
        foreach($suppliers as $supplier) {
            $db->pdo()->prepare('UPDATE supplier SET supplier_group_id=? WHERE id=?')->execute([$group,$supplier]);
            $this->cleanup['suppliers'][]=$supplier;
        }
        return $group;
    }

    private function groupDimension(Connection $db,int $group): array
    {
        $db->pdo()->prepare("INSERT INTO dimension_types (supplier_id,supplier_group_id,code,name,kind,is_active) VALUES (NULL,?,'SHARED','Synthetic group type','custom',1)")->execute([$group]);
        $type=(int)$db->pdo()->lastInsertId();
        $this->cleanup['types'][]=$type;
        $db->pdo()->prepare("INSERT INTO dimension_values (type_id,supplier_id,supplier_group_id,code,name,is_active) VALUES (?,NULL,?,'SHARED','Synthetic group value',1)")->execute([$type,$group]);
        return [$type,(int)$db->pdo()->lastInsertId()];
    }

    private function connection(): Connection
    {
        $db=Connection::withoutSharedTestConnection(static fn()=>Bootstrap::buildContainer()->get(Connection::class));
        self::assertStringEndsWith('_test',(string)$db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->connections[]=$db;
        return $db;
    }
}
