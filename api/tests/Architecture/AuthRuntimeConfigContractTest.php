<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthRuntimeConfigContractTest extends TestCase
{
    /**
     * @return array<string,array{string}>
     */
    public static function composeProvider(): array
    {
        return [
            'development compose' => ['docker-compose.yml'],
            'production compose' => ['docker-compose.production.yml'],
            'Portainer compose' => ['docker-compose.portainer.yml'],
        ];
    }

    #[DataProvider('composeProvider')]
    public function testComposePassesOptionalAuthPolicyEnvironment(string $filename): void
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/' . $filename);
        self::assertIsString($contents);

        foreach ([
            'MYINVOICE_AUTH_REQUIRE_MFA',
            'MYINVOICE_AUTH_MFA_METHODS',
            'MYINVOICE_OIDC_ENTRA_SKIP_MFA',
            'MYINVOICE_AUTH_PASSWORDLESS_LOGIN',
            'MYINVOICE_SESSION_LOCK_AFTER_MINUTES',
        ] as $name) {
            self::assertMatchesRegularExpression(
                '/^\s+' . preg_quote($name, '/') . ':\s+\$\{'
                    . preg_quote($name, '/') . ':-\}\s*$/m',
                $contents,
                "{$filename} musí předat {$name} s prázdným výchozím override.",
            );
        }
    }

    public function testSessionCleanupKeepsUnrevokedReplacementTombstonesUntilExpiry(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/api/bin/cron-cleanup.php');
        self::assertIsString($contents);

        self::assertStringContainsString(
            'DELETE FROM sessions WHERE expires_at < CURRENT_TIMESTAMP(6)',
            $contents,
        );
        self::assertStringContainsString(
            'WHERE revoked_at < UTC_TIMESTAMP(6) - INTERVAL 7 DAY',
            $contents,
        );
        self::assertStringNotContainsString(
            'DELETE FROM sessions WHERE expires_at < UTC_TIMESTAMP',
            $contents,
        );
        self::assertDoesNotMatchRegularExpression(
            '/DELETE FROM sessions[\s\S]*?WHERE replaced_at\s*</',
            $contents,
            'Samotné replaced_at nesmí zkrátit životnost logout tombstone.',
        );
    }
}
