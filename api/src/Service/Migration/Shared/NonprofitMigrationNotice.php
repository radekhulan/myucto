<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Service\Accounting\LegalFormProfileRecommendation;

/**
 * Upozornění průvodců převodem z cizího programu, že cílová firma je nezisková
 * organizace nebo SVJ (právní forma, nebo rámec vyhlášky 504 kdykoli v historii).
 *
 * Převody umí účetnictví jen v rámci vyhlášky 500: období v rámci 504 odmítne
 * {@see \MyInvoice\Service\Accounting\AccountingFrameworkImportGuard}, ale firma
 * s právní formou NNO a dosud rámcem 500 by převod prošla a převzala osnovu
 * a výkazy podnikatele bez rozlišení hlavní a hospodářské činnosti. Průvodce proto
 * u takové firmy výrazně upozorní, že převod pro neziskové organizace nebyl
 * ověřen. Běžné firmě se do odpovědi nic nepřidá.
 */
final class NonprofitMigrationNotice
{
    public const CODE = 'migration_nonprofit_untested';
    public const RESPONSE_KEY = 'nonprofit_notice';

    /** @return array{level:string,code:string,message:string,context:array{entity_profile:string,framework_504:bool}}|null */
    public static function forSupplier(Connection $db, int $supplierId): ?array
    {
        if ($supplierId <= 0) {
            return null;
        }
        $stmt = $db->pdo()->prepare('SELECT legal_form, accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $profile = LegalFormProfileRecommendation::forLegalForm(
            $row['legal_form'] !== null ? (string) $row['legal_form'] : null,
            $row['accounting_mode'] !== null ? (string) $row['accounting_mode'] : null,
        )['entity_profile'];
        $framework504 = in_array(AccountingFrameworkService::CZ_504,
            array_column((new AccountingFrameworkRepository($db))->listForTenant($supplierId), 'accounting_framework'), true);
        if ($profile === LegalFormProfileRecommendation::PROFILE_UNKNOWN && !$framework504) {
            return null;
        }
        return [
            'level' => 'warning',
            'code' => self::CODE,
            'message' => 'Převod dat pro neziskové organizace (spolky, nadace, ústavy, SVJ a další účetní jednotky podle vyhlášky 504/2002 Sb.) nebyl ověřen. '
                . 'Převod nezná osnovu a výkazy podle vyhlášky 504, hlavní a hospodářskou činnost ani fondy. Před převodem kontaktujte podporu.',
            'context' => ['entity_profile' => $profile, 'framework_504' => $framework504],
        ];
    }

    /**
     * Přidá upozornění do odpovědi průvodce; běžné firmě vrátí odpověď beze změny.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function attach(Connection $db, int $supplierId, array $payload): array
    {
        $notice = self::forSupplier($db, $supplierId);
        if ($notice !== null) {
            $payload[self::RESPONSE_KEY] = $notice;
        }
        return $payload;
    }
}
