<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Nonprofit;
use MyInvoice\Tests\Support\{ResourceReadBearerFixture,NonprofitResourceCaseFixture};
use PHPUnit\Framework\TestCase;

final class NonprofitInternalBearerBoundaryTest extends TestCase
{
    use ResourceReadBearerFixture,NonprofitResourceCaseFixture;
    public function testRawInternalWorkspaceAndChoicesAreSessionOnlyWhile500ReadContractsRemainAvailable():void
    {
        $this->fundingSource();$token=$this->token();
        foreach(['/api/v1/accounting/nonprofit-funding','/api/v1/accounting/nonprofit-capital','/api/v1/accounting/periods/'.$this->period.'/nonprofit-funding/choices','/api/v1/accounting/periods/'.$this->period.'/nonprofit-capital/choices']as$path){[$status,$body]=$this->http('GET',$path,$token);if($status===200&&$path==='/api/v1/accounting/nonprofit-funding')self::assertStringContainsString(self::PRIVATE_SENTINEL,json_encode($body,JSON_THROW_ON_ERROR));self::assertSame(403,$status,$path);self::assertSame('token_endpoint_forbidden',$body['error']['code']);}
        $own=$this->supplier;$this->supplier=$this->otherSupplier;self::assertSame('cz_500',$this->container->get(\MyInvoice\Service\Accounting\AccountingFrameworkService::class)->forDate($this->supplier,'2026-01-01'));$legacyToken=$this->token();$this->supplier=$own;
        [$status,$body]=$this->http('GET','/api/v1/accounting/accounts',$legacyToken);self::assertSame(200,$status,json_encode($body));
    }
}
