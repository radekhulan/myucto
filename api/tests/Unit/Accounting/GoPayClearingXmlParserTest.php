<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Service\Accounting\GoPay\GoPayClearingXmlParser;
use MyInvoice\Service\Accounting\GoPay\GoPayException;
use PHPUnit\Framework\TestCase;

final class GoPayClearingXmlParserTest extends TestCase
{
    public function testParsesCompleteClearingWithoutDoubleCountingExternalFee(): void
    {
        $result = (new GoPayClearingXmlParser())->parse($this->xml());

        self::assertSame('TEST-CLEARING-1', $result['clearing_id']);
        self::assertSame('CZK', $result['currency']);
        self::assertSame('875.00', $result['amount_transfer']);
        self::assertCount(5, $result['movements']);
        self::assertSame(['credit', 'storno', 'storno_fee', 'clearing_fee', 'payout'],
            array_column($result['movements'], 'movement_type'));
        self::assertSame('-20.00', $result['movements'][3]['amount']);
    }

    public function testRejectsSummaryThatDoesNotMatchMovements(): void
    {
        $this->expectException(GoPayException::class);
        $this->expectExceptionMessage('Souhrn GoPay XML neodpovídá');
        (new GoPayClearingXmlParser())->parse(str_replace('amountTransfer="875.00"', 'amountTransfer="874.00"', $this->xml()));
    }

    public function testRejectsDoctypeBeforeParsing(): void
    {
        $this->expectException(GoPayException::class);
        $this->expectExceptionMessage('zakázanou DTD');
        (new GoPayClearingXmlParser())->parse(str_replace('<?xml version="1.0"?>',
            '<?xml version="1.0"?><!DOCTYPE clearing [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>', $this->xml()));
    }

    public function testRejectsWrongNamespace(): void
    {
        $this->expectException(GoPayException::class);
        $this->expectExceptionMessage('není GoPay clearing XML');
        (new GoPayClearingXmlParser())->parse(str_replace('https://www.gopay.cz/clearing', 'https://example.test/clearing', $this->xml()));
    }

    public function testRejectsTruncatedOrMalformedExternalReference(): void
    {
        $this->expectException(GoPayException::class);
        $this->expectExceptionMessage('orderId má neplatný formát');
        (new GoPayClearingXmlParser())->parse(str_replace('orderId="TEST000001"', 'orderId="TEST 000001"', $this->xml()));
    }

    private function xml(): string
    {
        return <<<'XML'
<?xml version="1.0"?>
<clearing xmlns="https://www.gopay.cz/clearing" accountName="Test CZK"
 amount="1000.00" amountCreditNote="0.00" amountFee="20.00" amountFeeExternal="10.00"
 amountSent="875.00" amountStorno="100.00" amountStornoFee="5.00" amountTransfer="875.00"
 clearingId="TEST-CLEARING-1" dateClearedFrom="01.01.2099" dateClearedTo="31.01.2099"
 datePerformed="01.02.2099" variableSymbol="20990001">
  <paymentChannel fee="10.00" transactionFee="10.00" type="test" volumeFee="0.00">
    <movements>
      <movement accountMovementId="TEST-MOVE-1" amount="1000.00" counterpartyName="test"
       datePerformed="15.01.2099" orderId="TEST000001" paymentSessionId="1000000001" type="credit"/>
    </movements>
  </paymentChannel>
  <storno>
    <stornoMovement accountMovementId="TEST-MOVE-2" amount="-100.00" counterpartyName="GOPAY"
     datePerformed="20.01.2099" orderId="TEST000001" paymentSessionId="1000000001" type="storno"/>
    <stornoMovement accountMovementId="TEST-MOVE-3" amount="-5.00" counterpartyName="GOPAY"
     datePerformed="20.01.2099" orderId="TEST000001" paymentSessionId="1000000001" type="stornoFee"/>
  </storno>
</clearing>
XML;
    }
}
