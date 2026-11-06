<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Infrastructure\Database\Connection;
use PHPUnit\Event\Facade as EventFacade;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Pojistka ke sdílenému testovacímu DB spojení ({@see Connection::resetSharedTestSessions()}).
 *
 * Dokud měl každý test vlastní PDO, nedokončená transakce zmizela sama — socket se
 * zavřel a MariaDB ji implicitně rollbackla. Tuhle „úklidovou službu zdarma" sdílené
 * spojení ruší: rozdělaná transakce by protekla do dalšího testu a tvářila se jako
 * jeho vlastní data (přesně vzorec „cash test pollution", který tenhle projekt
 * jednou zažil).
 *
 * Proto se po KAŽDÉM testu rozdělaná transakce rollbackne a NAHLÁSÍ jako chyba testu,
 * který ji nechal viset. Tiché uklizení by z toho udělalo neviditelný dluh — a přesně
 * ten je horší než pomalá sada.
 */
final class SharedTestConnectionGuard implements Extension
{
    /** @internal volá jen odběratel začátku testu */
    public static function setRunningClass(?string $class): void
    {
        self::$runningClass = $class;
    }

    /** Třída právě spuštěného testu; pomocná instance jiné třídy (fixtura přes reflexi) sdílený základ nepoužije. */
    private static ?string $runningClass = null;

    public static function runningClass(): ?string
    {
        return self::$runningClass;
    }

    /** Třída, jejíž transakce se sdíleným základem ({@see SharedFixtureSavepointTrait}) má přežít konec testu. */
    private static ?string $fixtureHolder = null;

    public static function fixtureHolder(): ?string
    {
        return self::$fixtureHolder;
    }

    public static function setFixtureHolder(?string $class): void
    {
        self::$fixtureHolder = $class;
    }

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class implements \PHPUnit\Event\Test\PreparationStartedSubscriber {
            public function notify(\PHPUnit\Event\Test\PreparationStarted $event): void
            {
                $test = $event->test();
                SharedTestConnectionGuard::setRunningClass($test instanceof \PHPUnit\Event\Code\TestMethod ? $test->className() : null);
            }
        });
        $facade->registerSubscriber(new class((int) ini_get('max_execution_time')) implements FinishedSubscriber {
            public function __construct(private readonly int $executionTimeLimit) {}

            /** Třída se sdíleným základem drží transakci až do tearDownAfterClass(). */
            private static function keepsClassTransaction(\PHPUnit\Event\Code\Test $test): bool
            {
                return $test instanceof \PHPUnit\Event\Code\TestMethod && SharedTestConnectionGuard::fixtureHolder() === $test->className();
            }

            public function notify(Finished $event): void
            {
                if ((int) ini_get('max_execution_time') !== $this->executionTimeLimit) {
                    set_time_limit($this->executionTimeLimit);
                }
                ParallelRuntime::restore();
                foreach (Connection::resetSharedTestSessions(self::keepsClassTransaction($event->test())) as $dsn) {
                    EventFacade::emitter()->testTriggeredPhpunitError(
                        $event->test(),
                        sprintf(
                            "Test skončil s ROZDĚLANOU TRANSAKCÍ na sdíleném testovacím spojení (%s).\n"
                                . "Guard ji rollbacknul, aby neprotekla do dalších testů, ale opravit se to musí\n"
                                . "v testu: každý beginTransaction() musí mít v tearDown() (nebo ve finally)\n"
                                . "protějšek commit()/rollBack().",
                            $dsn,
                        ),
                    );
                }
            }
        });
    }
}
