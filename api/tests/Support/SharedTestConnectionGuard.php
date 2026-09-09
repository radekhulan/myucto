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
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class implements FinishedSubscriber {
            public function notify(Finished $event): void
            {
                ParallelRuntime::restore();
                foreach (Connection::resetSharedTestSessions() as $dsn) {
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
