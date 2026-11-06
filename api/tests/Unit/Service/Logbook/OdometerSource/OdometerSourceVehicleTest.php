<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Logbook\OdometerSource;

use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceException;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceVehicle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Identifikátor auta přichází od služby: nesmí skončit chybou databáze, ale chybou zdroje. */
final class OdometerSourceVehicleTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function invalidRefs(): iterable
    {
        yield 'prázdný' => [''];
        yield 'delší než sloupec' => [str_repeat('A', OdometerSourceVehicle::MAX_REF_LENGTH + 1)];
        yield 'řídicí znak' => ["WBA0000\n0000000001"];
    }

    #[DataProvider('invalidRefs')]
    public function testInvalidRefFromServiceIsRejected(string $ref): void
    {
        try {
            new OdometerSourceVehicle($ref);
            self::fail('Neplatný identifikátor auta musí být odmítnut.');
        } catch (OdometerSourceException $e) {
            self::assertSame(OdometerSourceException::INVALID_RESPONSE, $e->errorCode);
        }
    }

    public function testRefOfColumnWidthIsAcceptedAndLabelIsTrimmed(): void
    {
        $vehicle = new OdometerSourceVehicle(str_repeat('A', OdometerSourceVehicle::MAX_REF_LENGTH), null, '  ' . str_repeat('x', 150) . ' ');

        self::assertSame(100, mb_strlen((string) $vehicle->label));
        self::assertNull((new OdometerSourceVehicle('REF-1', null, '   '))->label);
    }
}
