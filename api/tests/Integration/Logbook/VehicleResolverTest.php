<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Logbook;

use MyInvoice\Repository\CarRepository;
use MyInvoice\Service\Logbook\CardHolderVehicleResolver;
use MyInvoice\Service\Logbook\VehicleResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Přiřazení vozidla k tankování: explicitně, SPZ, text, karta (bod rozšíření), výchozí vůz. */
#[Group('integration')]
final class VehicleResolverTest extends TestCase
{
    use LogbookFixtures;

    private int $carA1 = 0;
    private int $carA2 = 0;
    private int $carB = 0;

    protected function setUp(): void
    {
        $this->bootLogbook();
        $this->carA1 = $this->car($this->supplierA, '1AB 2345');
        $this->carA2 = $this->car($this->supplierA, '2CD 6789', true);
        $this->carB = $this->car($this->supplierB, '3EF 1111');
    }

    protected function tearDown(): void
    {
        $this->shutdownLogbook();
    }

    private function resolver(?CardHolderVehicleResolver $byCard = null): VehicleResolver
    {
        return new VehicleResolver($this->db, $this->container->get(CarRepository::class), $byCard);
    }

    public function testPlateIsComparedWithoutSpacesAndCase(): void
    {
        self::assertSame(
            ['car_id' => $this->carA1, 'method' => 'plate'],
            $this->resolver()->resolve($this->supplierA, ['plate' => '1ab-2345']),
        );
    }

    public function testPlateFoundInFreeText(): void
    {
        self::assertSame(
            ['car_id' => $this->carA1, 'method' => 'text'],
            $this->resolver()->resolve($this->supplierA, ['text' => 'Tankování 1AB2345 dálnice']),
        );
    }

    public function testFallsBackToDefaultCarOnlyWhenAllowed(): void
    {
        self::assertSame(['car_id' => $this->carA2, 'method' => 'default'], $this->resolver()->resolve($this->supplierA, []));
        self::assertSame(['car_id' => null, 'method' => 'none'], $this->resolver()->resolve($this->supplierA, [], false));
    }

    public function testExplicitAndPlateOfAnotherSupplierAreIgnored(): void
    {
        $r = $this->resolver()->resolve($this->supplierA, ['car_id' => $this->carB, 'plate' => '3EF 1111'], false);

        self::assertSame(['car_id' => null, 'method' => 'none'], $r);
    }

    public function testCardHolderExtensionPointAssignsVehicle(): void
    {
        $carA1 = $this->carA1;
        $byCard = new class ($carA1) implements CardHolderVehicleResolver {
            public function __construct(private readonly int $carId) {}
            public function vehicleForCard(int $supplierId, string $cardLast4, string $date): ?int
            {
                return $cardLast4 === '4242' ? $this->carId : null;
            }
        };

        self::assertSame(
            ['car_id' => $this->carA1, 'method' => 'card'],
            $this->resolver($byCard)->resolve($this->supplierA, ['card_last4' => '************4242', 'date' => '2099-03-05'], false),
        );
    }

    public function testCardHolderVehicleOfAnotherSupplierIsRejected(): void
    {
        $carB = $this->carB;
        $byCard = new class ($carB) implements CardHolderVehicleResolver {
            public function __construct(private readonly int $carId) {}
            public function vehicleForCard(int $supplierId, string $cardLast4, string $date): ?int
            {
                return $this->carId;
            }
        };

        self::assertSame(
            ['car_id' => null, 'method' => 'none'],
            $this->resolver($byCard)->resolve($this->supplierA, ['card_last4' => '4242'], false),
        );
    }
}
