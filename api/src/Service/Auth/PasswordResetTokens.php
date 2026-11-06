<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use PDO;

final class PasswordResetTokens
{
    public static function invalidateForUserInTransaction(PDO $pdo, int $userId): void
    {
        if (!$pdo->inTransaction() || $userId < 1) {
            throw new \LogicException('Zneplatnění odkazů vyžaduje transakci a platného uživatele.');
        }
        $pdo->prepare('UPDATE password_resets SET used_at = CURRENT_TIMESTAMP WHERE user_id = ? AND used_at IS NULL')
            ->execute([$userId]);
    }
}
