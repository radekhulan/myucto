<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use DOMDocument;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlSerializer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Nula v bloku `unemplcomp`: co se smí poslat a co ne.
 *
 * Pět atributů (`avgmonear` 10377, `replacement` 10530, `goldenhandshake`
 * 10531, `severancepay` 10532, `disposal` 10533) stojí v připnutém XSD
 * (`api/xsd/jmhz/regzec-1.4.0.4/REGZEC25.xsd`) na typu
 * `t:simpleNType_string`, jehož pattern je `[1-9][0-9]*`
 * (`baseTypes2.xsd` :91-101). Číselný `t:simpleNType` má u téhož patternu
 * výslovně přidanou alternativu `|0`, tenhle řetězcový ji nemá — nula tedy
 * schématem NEPROJDE, přestože je věcně smysluplná („odstupné se
 * nevyplácelo"). Všechny jsou `use="optional"`, takže správné chování je
 * atribut vynechat.
 *
 * `earlyterm` je tu jako PROTIPŘÍKLAD: stojí na `t:simpleNType`, kde nula
 * projde, a vynechávat ho by znamenalo ztratit údaj. Kdyby někdo pravidlo
 * „nula se nikdy neposílá" zobecnil na celý blok, spadne právě na něm.
 */
final class PayrollRegistrationUnemploymentZeroAttributesTest extends TestCase
{
    /** @return iterable<string,array{string,string}> */
    public static function zeroForbiddenAttributes(): iterable
    {
        yield 'průměrný čistý výdělek' => ['average_net_earnings', 'avgmonear'];
        yield 'jednorázová náhrada § 271ca' => ['replacement', 'replacement'];
        yield 'odstupné § 67' => ['golden_handshake', 'goldenhandshake'];
        yield 'odchodné' => ['severance_pay', 'severancepay'];
        yield 'odbytné' => ['disposal', 'disposal'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('zeroForbiddenAttributes')]
    public function testZeroIsOmittedInsteadOfBreakingSchema(
        string $key,
        string $attribute,
    ): void {
        $xml = $this->serializeUnemployment([$key => 0]);

        self::assertStringNotContainsString($attribute . '=', $xml);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('zeroForbiddenAttributes')]
    public function testZeroAsStringIsOmittedToo(
        string $key,
        string $attribute,
    ): void {
        $xml = $this->serializeUnemployment([$key => '0']);

        self::assertStringNotContainsString($attribute . '=', $xml);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('zeroForbiddenAttributes')]
    public function testNonZeroValueIsStillSent(
        string $key,
        string $attribute,
    ): void {
        $xml = $this->serializeUnemployment([$key => 25_000]);

        self::assertStringContainsString($attribute . '="25000"', $xml);
    }

    /**
     * Protipříklad: `earlyterm` stojí na typu, který nulu povoluje, takže
     * se posílá i s nulou. Kdyby ho někdo přidal mezi vynechávané, přijdeme
     * o údaj, který ČSSZ přijímá.
     */
    public function testAttributeOnZeroTolerantTypeKeepsItsZero(): void
    {
        $xml = $this->serializeUnemployment(['early_termination_reason' => 0]);

        self::assertStringContainsString('earlyterm="0"', $xml);
    }

    /** @param array<string,mixed> $data */
    private function serializeUnemployment(array $data): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $namespace = 'http://schemas.cssz.cz/REGZEC/2025';
        $employee = $document->createElementNS($namespace, 'employee');
        $document->appendChild($employee);

        $serializer = new PayrollRegistrationXmlSerializer();
        $method = new ReflectionMethod(
            PayrollRegistrationXmlSerializer::class,
            'appendUnemployment',
        );
        $method->invoke($serializer, $document, $namespace, $employee, $data);

        $xml = $document->saveXML();
        self::assertIsString($xml);

        return $xml;
    }
}
