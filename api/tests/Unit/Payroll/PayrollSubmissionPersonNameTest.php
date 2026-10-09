<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\PayrollSubmissionPersonName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Znaky jména pro podání ČSSZ musí doslova odpovídat typu `simpleA_ZX_SP_Type`
 * v připnutých XSD. Kdyby se kontrola při zápisu a XSD rozešly, buď by zápis
 * odmítal jména, která podání přijme, nebo by jméno prošlo a podání spadlo.
 */
final class PayrollSubmissionPersonNameTest extends TestCase
{
    /** @return iterable<string,array{string}> */
    public static function schemas(): iterable
    {
        yield 'JMHZ' => ['jmhz/jmhz-1.4.3.6/baseTypes2.xsd'];
        yield 'REGZEC' => ['jmhz/regzec-1.4.0.4/baseTypes2.xsd'];
        yield 'PREZEC' => ['jmhz/prezec-1.2/baseTypes2.xsd'];
        yield 'NEMPRI' => ['cssz/nempri25-1.0/baseTypes2.xsd'];
        yield 'OZUSPOJ' => ['cssz/ozuspoj-1.2/baseTypes2.xsd'];
    }

    #[DataProvider('schemas')]
    public function testAllowedCharactersMatchTheSchemaPattern(string $schema): void
    {
        $xsd = (string) file_get_contents(dirname(__DIR__, 3) . '/xsd/' . $schema);
        $type = strstr($xsd, 'name="simpleA_ZX_SP_Type"');
        self::assertIsString($type, $schema);
        self::assertSame(1, preg_match('/<pattern value="([^"]+)"/', $type, $match), $schema);

        $characters = '[' . PayrollSubmissionPersonName::ALLOWED_CHARACTERS . ']';
        self::assertSame('(' . $characters . '+( +' . $characters . '+)*)|', $match[1], $schema);
    }

    public function testReportsEachDisallowedCharacterOnce(): void
    {
        self::assertSame(['1', '2'], PayrollSubmissionPersonName::disallowedCharacters('Dítě1 Dítě2 Dítě1'));
        self::assertSame([], PayrollSubmissionPersonName::disallowedCharacters("Ľudmila O'Neill-Řeháková, st."));
    }
}
