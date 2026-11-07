<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Auth;

use MyInvoice\Service\Auth\UserSessionCli;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserSessionCliTest extends TestCase
{
    #[DataProvider('invalidArguments')]
    public function testRejectsAmbiguousOrInvalidSelection(array $arguments): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UserSessionCli::parse($arguments);
    }

    public static function invalidArguments(): iterable
    {
        foreach ([[], ['unknown'], ['terminate'], ['terminate', '--all', '--email=x@example.test'],
            ['terminate', '--all', '--id=' . str_repeat('a', 64)], ['terminate', '--id=short'],
            ['terminate', '--email=invalid'], ['terminate', '--all', '--cursor=x'], ['list', '--all'],
            ['list', '--yes'], ['list', '--id=' . str_repeat('a', 64)], ['list', '--cursor=x'],
            ['list', '--unknown'], ['list', '--email'], ['list', '--email', '--json'],
            ['terminate', '--all=true'], ['terminate', '--all', '--all'], ['list', '--json', '--json'],
        ] as $arguments) yield [$arguments];
    }

    public function testDefaultsToPreviewAndSupportsBothValueSyntaxes(): void
    {
        $options = UserSessionCli::parse(['terminate', '--email', 'synthetic@example.test']);
        self::assertFalse($options['yes']);
        self::assertSame('synthetic@example.test', $options['email']);
        self::assertTrue(UserSessionCli::parse(['terminate', '--all', '--yes', '--json'])['yes']);
        self::assertSame(str_repeat('a', 64), UserSessionCli::parse(['terminate', '--id=' . str_repeat('A', 64)])['id']);
    }
}
