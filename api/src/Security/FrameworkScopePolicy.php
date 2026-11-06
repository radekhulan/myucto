<?php

declare(strict_types=1);

namespace MyInvoice\Security;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Service\Accounting\LegalFormProfileRecommendation;

/**
 * Agendy NNO/504 a SVJ jen firmám, kterých se týkají. Serverový protějšek
 * `web/src/security/frameworkScope.ts`: menu a router je běžné firmě nenabídnou,
 * tady se totéž vynucuje i pro přímé volání API (např. API tokenem).
 *
 * - `nonprofit504`: účinný rámec vyhlášky 504 nebo právní forma nevýdělečné organizace.
 * - `conversion504`: navíc i SVJ (převod rámce).
 * - `svj`: právní forma společenství vlastníků jednotek nebo výslovně zapnutý profil SVJ.
 *
 * Kroky uzávěrky podle rámce 504 (`periods/{id}/closing/…`) sem nepatří: řídí je rámec
 * konkrétního období, ne dnešní rámec firmy.
 */
final class FrameworkScopePolicy
{
    public const NONPROFIT_504 = 'nonprofit504';
    public const CONVERSION_504 = 'conversion504';
    public const SVJ = 'svj';

    private const PATHS = [
        '#^/api/property(/|$)#' => self::SVJ,
        '#^/api/accounting/framework-conversions(/|$)#' => self::CONVERSION_504,
        '#^/api/accounting/(joint-allocations|nonprofit-[a-z-]+|securities/debt|catalog/nonprofit-[a-z-]+)(/|$)#' => self::NONPROFIT_504,
        '#^/api/accounting/periods/[0-9]+/nonprofit-[a-z-]+(/|$)#' => self::NONPROFIT_504,
    ];

    public function __construct(private readonly Connection $db) {}

    public static function scopeForPath(string $path): ?string
    {
        foreach (self::PATHS as $pattern => $scope) {
            if (preg_match($pattern, $path) === 1) return $scope;
        }
        return null;
    }

    public function allows(int $supplierId, string $scope): bool
    {
        if ($supplierId <= 0) return false;
        $stmt = $this->db->pdo()->prepare('SELECT legal_form, accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($supplier === false) return false;
        $profile = LegalFormProfileRecommendation::forLegalForm(
            isset($supplier['legal_form']) ? (string) $supplier['legal_form'] : null,
            isset($supplier['accounting_mode']) ? (string) $supplier['accounting_mode'] : null,
        )['entity_profile'];
        if ($scope === self::SVJ) {
            if ($profile === LegalFormProfileRecommendation::PROFILE_SVJ) return true;
            // Firma s výslovně zapnutým profilem SVJ (modul Nemovitosti) agendu vést smí i bez právní formy 145.
            $stmt = $this->db->pdo()->prepare('SELECT 1 FROM property_module_profiles WHERE supplier_id = ? AND enabled = 1 LIMIT 1');
            $stmt->execute([$supplierId]);
            return $stmt->fetchColumn() !== false;
        }

        $framework = (new AccountingFrameworkService($this->db, new AccountingFrameworkRepository($this->db)))
            ->forDateForSuppliers([$supplierId], date('Y-m-d'))[$supplierId] ?? AccountingFrameworkService::CZ_500;
        $nonprofit = $framework === AccountingFrameworkService::CZ_504
            || $profile === LegalFormProfileRecommendation::PROFILE_NONPROFIT;
        return $scope === self::CONVERSION_504
            ? $nonprofit || $profile === LegalFormProfileRecommendation::PROFILE_SVJ
            : $nonprofit;
    }
}
