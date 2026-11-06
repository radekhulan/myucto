<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Admin;

use MyInvoice\Action\Admin\RunCronJobAction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Audit ručního spuštění cronu nesmí nést absolutní cesty serveru: čte ho
 * každý admin a ve spravovaném provozu jde o rozložení serveru provozovatele.
 */
final class RunCronJobActionPathsTest extends TestCase
{
    #[DataProvider('texts')]
    public function testAbsolutePathsAreReducedToFileNames(string $input, string $expected): void
    {
        self::assertSame($expected, RunCronJobAction::withoutPaths($input));
    }

    /** @return list<array{string,string}> */
    public static function texts(): array
    {
        return [
            ['exec rc=0', 'exec rc=0'],
            ['popen ok', 'popen ok'],
            ['php cli not found (PHP_BINARY=C:\\inetpub\\php\\php-cgi.exe)', 'php cli not found (PHP_BINARY=php-cgi.exe)'],
            ['/var/www/clients/client1/web28/web/../private/myucto/log/cron-run-cron-maintenance-notice.log', 'cron-run-cron-maintenance-notice.log'],
            ['spawn /usr/bin/php8.5 C:/inetpub/wwwroot/app/api/bin/cron-x.php', 'spawn php8.5 cron-x.php'],
        ];
    }
}
