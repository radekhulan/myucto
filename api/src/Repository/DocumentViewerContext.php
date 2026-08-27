<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

/**
 * Kontext prohlížejícího uživatele pro per-user scope guard nad DMS dokumenty (Epic F7).
 *
 * Vnější tenant guard (supplier_id) zůstává beze změny; tohle nese vnitřní osu:
 *  - `isAdmin=true`  → vidí VŠECHNY doklady tenanta (i scope='user' cizích uživatelů),
 *  - `userId != null`→ vidí company doklady + vlastní user doklady (owner_user_id = userId),
 *  - `userId = null` → fail-closed: jen company doklady (žádné user-scoped).
 * Citlivé mzdové důkazy mají samostatný explicitní příznak; samotný admin
 * ani obecné oprávnění k Dokumentům je nezpřístupňuje.
 *
 * Staví se v Action vrstvě z AuthMiddleware::ATTR_USER (role + id) a protéká do
 * DocumentRepository read metod. Background joby ho rekonstruují z uloženého kontextu.
 */
final readonly class DocumentViewerContext
{
    private function __construct(
        public ?int $userId,
        public bool $isAdmin,
        public bool $canViewPayrollEnforcementEvidence,
        public bool $canViewPayrollInsolvencyEvidence,
        public bool $canViewPayrollSubmissionEvidence,
    ) {}

    /** Admin tenanta — vidí vše (i user-scoped cizích uživatelů). */
    public static function admin(
        ?int $userId = null,
        bool $canViewPayrollEnforcementEvidence = false,
        bool $canViewPayrollInsolvencyEvidence = false,
        bool $canViewPayrollSubmissionEvidence = false,
    ): self
    {
        return new self(
            $userId,
            true,
            $canViewPayrollEnforcementEvidence,
            $canViewPayrollInsolvencyEvidence,
            $canViewPayrollSubmissionEvidence,
        );
    }

    /** Non-admin uživatel — company + vlastní user doklady. NULL userId = fail-closed (jen company). */
    public static function forUser(
        ?int $userId,
        bool $canViewPayrollEnforcementEvidence = false,
        bool $canViewPayrollInsolvencyEvidence = false,
        bool $canViewPayrollSubmissionEvidence = false,
    ): self
    {
        return new self(
            $userId,
            false,
            $canViewPayrollEnforcementEvidence,
            $canViewPayrollInsolvencyEvidence,
            $canViewPayrollSubmissionEvidence,
        );
    }

    /** Non-admin bez identity — fail-closed (jen company doklady). */
    public static function companyOnly(): self
    {
        return new self(null, false, false, false, false);
    }

    /** Důvěryhodný serverový tok ověřující firemní referenci, nikdy HTTP viewer. */
    public static function internalCompany(): self
    {
        return new self(null, false, true, true, true);
    }

    public static function fromAuthorization(
        bool $isSuperadmin,
        ?int $userId,
        bool $canViewPayrollEnforcementEvidence = false,
        bool $canViewPayrollInsolvencyEvidence = false,
        bool $canViewPayrollSubmissionEvidence = false,
    ): self
    {
        return $isSuperadmin
            ? self::admin(
                $userId,
                $canViewPayrollEnforcementEvidence,
                $canViewPayrollInsolvencyEvidence,
                $canViewPayrollSubmissionEvidence,
            )
            : self::forUser(
                $userId,
                $canViewPayrollEnforcementEvidence,
                $canViewPayrollInsolvencyEvidence,
                $canViewPayrollSubmissionEvidence,
            );
    }

    /** Kompatibilní konstruktor pro starší middleware-less volající. */
    public static function fromRole(string $role, ?int $userId): self
    {
        return self::fromAuthorization($role === 'admin', $userId);
    }

    /** @return array{viewer_is_admin:bool,viewer_can_view_payroll_enforcement_evidence:bool,viewer_can_view_payroll_insolvency_evidence:bool,viewer_can_view_payroll_submission_evidence:bool} */
    public function toJobParams(): array
    {
        return [
            'viewer_is_admin' => $this->isAdmin,
            'viewer_can_view_payroll_enforcement_evidence' => $this->canViewPayrollEnforcementEvidence,
            'viewer_can_view_payroll_insolvency_evidence' => $this->canViewPayrollInsolvencyEvidence,
            'viewer_can_view_payroll_submission_evidence' => $this->canViewPayrollSubmissionEvidence,
        ];
    }

    /** @param array<string,mixed> $params */
    public static function fromJobParams(array $params, ?int $userId): self
    {
        return self::fromAuthorization(
            (bool) ($params['viewer_is_admin'] ?? false),
            $userId,
            (bool) ($params['viewer_can_view_payroll_enforcement_evidence'] ?? false),
            (bool) ($params['viewer_can_view_payroll_insolvency_evidence'] ?? false),
            (bool) ($params['viewer_can_view_payroll_submission_evidence'] ?? false),
        );
    }
}
