<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Nonprofit;
use MyInvoice\Service\Anonymization\NonprofitFundingCloneScrubber;
use MyInvoice\Service\Anonymization\Pseudonymizer;
use MyInvoice\Service\Anonymization\TextScrubber;
use PHPUnit\Framework\TestCase;
final class FundingCloneIdentifiersTest extends TestCase
{
    public function testAnonymizationPreservesFinancialCodesDespiteDictionaryCollisions():void
    {
        $pseudo=new Pseudonymizer('synthetic-funding-identifiers');
        foreach(['gift','691','line:123']as$word)$pseudo->dictionary()->add($word,'changed123',1);
        $class=new \ReflectionClass(NonprofitFundingCloneScrubber::class);$owner=$class->newInstanceWithoutConstructor();
        $class->getProperty('scrubber')->setValue($owner,new TextScrubber($pseudo));
        $input=['kind'=>'gift','title'=>'Gift','source_anchor'=>'line:123','source_members'=>['line:123'],'account_prefixes'=>['691'],
            'decisions'=>['line:123'=>['title'=>'gift','evidence'=>'Gift donor@example.test','cohort'=>'donor@example.test']],
            'provider'=>['company_name'=>'Gift'],'activities'=>['4'=>['cohorts'=>['donor@example.test'=>'Gift donor@example.test']]]];
        $result=$class->getMethod('transform')->invoke($owner,$input);
        foreach(['kind','source_anchor','source_members','account_prefixes']as$key)self::assertSame($input[$key],$result[$key]);
        self::assertSame('gift',$result['decisions']['line:123']['title']);
        self::assertNotSame($input['title'],$result['title']);
        self::assertNotSame($input['provider'],$result['provider']);
        self::assertStringNotContainsString('donor@example.test',json_encode($result,JSON_THROW_ON_ERROR));
        self::assertArrayHasKey($result['decisions']['line:123']['cohort'],$result['activities'][4]['cohorts']);
    }
}
