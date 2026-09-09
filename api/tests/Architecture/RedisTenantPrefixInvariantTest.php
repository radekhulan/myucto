<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Infrastructure\Cache\RedisKeyspace;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Tests\Support\SourceCorpus;
use PHPUnit\Framework\TestCase;

/**
 * H-08 — izolace instancí v Redisu stojí ČISTĚ na prefixu.
 *
 * Hosting dává všem instancím `db 0` a odlišuje je prefixem
 * (`myucto:i<id>:`). Druhá pojistka neexistuje: JEDEN klíč zapsaný mimo
 * jmenný prostor instance znamená, že si dva zákazníci vidí do cache —
 * v `EntityCache` je seznam firem, v `ApiTokenService` čerstvost tokenu,
 * v `BruteForceGuard` počítadlo pokusů o přihlášení.
 *
 * Takový klíč nevznikne úmyslně. Vznikne tak, že někdo potřebuje „jen si
 * ověřit spojení" a postaví si vedle druhého klienta bez prefixu — a příště
 * ho někdo rozšíří o `->get()`. Proto guard hlídá KONSTRUKCI klientů, ne
 * jednotlivá volání: jediné bezpečné pravidlo je „žádný klient bez prefixu".
 */
final class RedisTenantPrefixInvariantTest extends TestCase
{
    /**
     * Soubory, které smějí konstruovat Predis klienta. Rozšíření seznamu je
     * vědomé rozhodnutí — každý další klient je další místo, kde může vzniknout
     * klíč mimo jmenný prostor instance.
     *
     * @var list<string>
     */
    private const ALLOWED_CLIENT_BUILDERS = [
        'Infrastructure/Cache/RedisFactory.php',
        'Infrastructure/Cache/RedisProbe.php',
    ];

    private static function srcDir(): string
    {
        return dirname(__DIR__, 2) . '/src';
    }

    /** @return array<string,string> relativní cesta => obsah */
    private static function sources(): array
    {
        return SourceCorpus::sources(self::srcDir());
    }

    public function testEveryRedisClientIsBuiltWithTheInstancePrefix(): void
    {
        $offenders = [];
        foreach (self::sources() as $rel => $code) {
            if (!preg_match('/new\s+(\\\\?Predis\\\\Client|RedisClient)\s*\(/', $code)) {
                continue;
            }

            self::assertContains(
                $rel,
                self::ALLOWED_CLIENT_BUILDERS,
                "{$rel} staví Predis klienta mimo Infrastructure\\Cache. Izolace zákazníků "
                . 'v Redisu stojí jen na prefixu — další cesta ke klientovi je další cesta ke klíči bez něj.',
            );

            // Klient musí prefix brát z SSOT. Vlastní `(string) $config->get(...)`
            // by pustil prázdnou hodnotu, kterou Predis chápe jako „neprefixuj".
            if (!str_contains($code, 'RedisKeyspace::prefix(')) {
                $offenders[] = $rel;
            }
        }

        self::assertSame([], $offenders, sprintf(
            "Predis klient bez prefixu z RedisKeyspace:\n  %s\n\n"
            . "Hosting dává všem instancím db 0 — bez prefixu si zákazníci vidí do cache.",
            implode("\n  ", $offenders),
        ));
    }

    public function testNoHardcodedRedisDatabaseIndex(): void
    {
        $offenders = [];
        foreach (self::sources() as $rel => $code) {
            // Index databáze se smí brát JEN z konfigurace. Zadrátovaná hodnota
            // by na spravovaném hostingu buď spadla, nebo — hůř — trefila cizí db.
            if (preg_match("/'database'\s*=>\s*\d/", $code)) {
                $offenders[] = $rel;
            }
        }

        self::assertSame([], $offenders, sprintf(
            "Zadrátovaný index Redis databáze:\n  %s\n\nPoužij (int) \$config->get('redis.db', RedisKeyspace::MANAGED_DB).",
            implode("\n  ", $offenders),
        ));
    }

    public function testPrefixIsNeverEmptyEvenWhenConfigSaysSo(): void
    {
        // `redis.prefix => ''` v cfg by Predis pochopil jako „neprefixuj" a klíče
        // `bf:…`, `rl:…` by skončily holé ve sdíleném db 0. Prázdná hodnota se
        // proto nahradí výchozí — ignorovaný překlep je lepší než sdílená cache.
        foreach (['', '   ', null] as $configured) {
            $config = new Config(['redis' => ['prefix' => $configured]]);
            self::assertNotSame('', RedisKeyspace::prefix($config));
            self::assertSame(RedisKeyspace::DEFAULT_PREFIX, RedisKeyspace::prefix($config));
        }

        self::assertSame(
            'myucto:i42:',
            RedisKeyspace::prefix(new Config(['redis' => ['prefix' => ' myucto:i42: ']])),
        );
    }

    public function testManagedInstallationRefusesTheSharedDefaultPrefix(): void
    {
        // Na flotile má výchozí prefix každá instance stejný — to je totéž jako
        // žádný prefix. Redis se tam raději nepoužije vůbec: aplikace bez cache
        // jen zpomalí, kdežto sdílená cache je únik mezi zákazníky.
        $managedDefault = new Config([
            'app'   => ['managed' => true],
            'redis' => ['enabled' => true, 'prefix' => RedisKeyspace::DEFAULT_PREFIX],
        ]);
        self::assertNotNull(RedisKeyspace::unsafeReason($managedDefault));

        $managedUnique = new Config([
            'app'   => ['managed' => true],
            'redis' => ['enabled' => true, 'prefix' => 'myucto:i42:'],
        ]);
        self::assertNull(RedisKeyspace::unsafeReason($managedUnique));

        // Self-host má vlastní Redis a jediného tenanta — výchozí prefix tam
        // problém není a zpřísnění by mu jen vyplo cache.
        $selfHosted = new Config([
            'redis' => ['enabled' => true, 'prefix' => RedisKeyspace::DEFAULT_PREFIX],
        ]);
        self::assertNull(RedisKeyspace::unsafeReason($selfHosted));
    }

    public function testManagedInstallationWarnsAboutNonZeroDatabase(): void
    {
        // Hosting zřizuje jen db 0. Jiný index se nedozvíme jinak než z logu.
        self::assertNotNull(RedisKeyspace::databaseWarning(new Config([
            'app'   => ['managed' => true],
            'redis' => ['enabled' => true, 'prefix' => 'myucto:i42:', 'db' => 3],
        ])));
        self::assertNull(RedisKeyspace::databaseWarning(new Config([
            'app'   => ['managed' => true],
            'redis' => ['enabled' => true, 'prefix' => 'myucto:i42:', 'db' => 0],
        ])));
        self::assertNull(RedisKeyspace::databaseWarning(new Config([
            'redis' => ['enabled' => true, 'db' => 3],
        ])));
    }

    public function testRedisAccessGoesThroughTheFactory(): void
    {
        // Klíčové příkazy se smějí volat jen uvnitř `RedisFactory::run()`, kde
        // klient nese prefix. Přímý import Predisu jinde je předzvěst klíče
        // mimo jmenný prostor instance.
        $importers = [];
        foreach (self::sources() as $rel => $code) {
            if (preg_match('/^use\s+Predis\\\\/m', $code)) {
                $importers[] = $rel;
            }
        }

        sort($importers);
        self::assertSame(self::ALLOWED_CLIENT_BUILDERS, $importers);
    }
}
