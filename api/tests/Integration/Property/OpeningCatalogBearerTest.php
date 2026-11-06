<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;
use MyInvoice\Tests\Support\CuratedReadBearerFixture;
use PHPUnit\Framework\TestCase;

final class OpeningCatalogBearerTest extends TestCase
{
    use CuratedReadBearerFixture;
    public function testActualTokenReadsExistingOpeningCatalogThroughPublicVersionedRoute():void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET legal_form='145' WHERE id IN (?,?)")->execute([$this->supplier,$this->otherSupplier]);
        $token=$this->token();[$status,$body]=$this->http('GET','/api/v1/property/catalog/openings',$token);self::assertSame(200,$status,json_encode($body));self::assertSame([],$body['items']);
        [$otherStatus,$other]=$this->http('GET','/api/v1/property/catalog/openings',$token,['supplier_id'=>$this->otherSupplier],['X-Supplier-Id'=>(string)$this->otherSupplier]);self::assertSame(200,$otherStatus,json_encode($other));self::assertSame($body,$other);
    }
    public function testActualTokenWithoutReadGrantAndEveryMutationRemainForbidden():void
    {
        [$status]=$this->http('GET','/api/v1/property/catalog/openings',$this->token([]));self::assertSame(403,$status);
        $token=$this->token();foreach(['POST','PUT','PATCH','DELETE']as$method){[$status]=$this->http($method,'/api/v1/property/catalog/openings',$token);self::assertSame(403,$status,$method);}
        foreach(['/api/v1/property/catalog/openings/1','/api/v1/property/catalog/openings-extra','/api/v1/property/openings']as$path){[$status]=$this->http('GET',$path,$token);self::assertSame(403,$status,$path);}
    }
}
