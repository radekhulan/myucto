<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Service\OAuth\OAuthClientFlow;
use MyInvoice\Service\OAuth\OAuthException;
use PHPUnit\Framework\TestCase;

/**
 * Atrapa {@see OAuthClientFlow::rotate()} pro unit testy bez databáze: stejný postup
 * (načíst znovu, obnovit jen propadlé, uložit, neuložené nepoužít), jen bez zámku.
 * Zámek a souběh ověřují integrační testy proti skutečné vrstvě.
 *
 * @mixin TestCase
 */
trait InlineOAuthRotation
{
    private function inlineRotationFlow(): OAuthClientFlow
    {
        $flow = $this->createStub(OAuthClientFlow::class);
        $flow->method('rotate')->willReturnCallback(
            static function (string $scope, string|int $key, callable $load, callable $isFresh, callable $refresh, callable $persist): array {
                $current = $load();
                if ($isFresh($current)) return $current;
                $next = $refresh($current);
                if (!$persist($next)) throw new OAuthException(OAuthException::ROTATION_FAILED);
                return $next;
            },
        );
        return $flow;
    }
}
