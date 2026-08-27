<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Dobrovolná nabídka dvoufázového ověření (migrace 1572).
 *
 * Vynucené MFA (`auth.require_mfa = true`) řeší `must_setup_mfa` + RequireMfaMiddleware
 * a tahle služba do něj nesmí sáhnout. Řeší přesně opačný případ: politika MFA
 * NEvynucuje, takže `must_setup_mfa` je vždycky false, router na `/setup-mfa`
 * nikoho nepošle a uživatel se o dvoufázovém ověření nikdy nedozví. Nabídka to
 * spraví — jednou, s možností odmítnout.
 *
 * Rozhodnutí „teď ne" je stav účtu (`users.mfa_offer_dismissed_at`), ne stav
 * stránky: kdyby bylo v paměti nebo v session, vrátilo by se při každém
 * přihlášení a z nabídky by se stalo vynucení oklikou.
 */
final class MfaOfferService
{
    public function __construct(
        private readonly Connection $db,
        private readonly MfaPolicyService $policy,
    ) {}

    /**
     * Nabídnout uživateli zapnutí MFA?
     *
     * Pořadí podmínek je záměrné: obě levné se ptají první, takže na hot path
     * `/api/auth/me` se účet s faktorem ani instalace s vynuceným MFA na
     * databázi vůbec nezeptají.
     */
    public function shouldOffer(int $userId, bool $hasAnyFactor): bool
    {
        // Při vynuceném MFA nabídka nesmí existovat — nese tlačítko „pokračovat
        // bez ověření", což by byla cesta kolem politiky.
        if ($userId < 1 || $this->policy->isRequired() || $hasAnyFactor) {
            return false;
        }

        return !$this->isDismissed($userId);
    }

    public function isDismissed(int $userId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT mfa_offer_dismissed_at FROM users WHERE id = ?'
        );
        $stmt->execute([$userId]);
        $dismissedAt = $stmt->fetchColumn();

        // `false` = uživatel neexistuje. Fail-closed (nenabízet) je tu správně:
        // komu nabídku ukázat nemáme koho se zeptat, tomu ji neukazujeme.
        return $dismissedAt !== null;
    }

    /**
     * Zaznamená „pokračovat bez MFA". Idempotentní — opakované volání první čas
     * nepřepíše, aby audit držel okamžik skutečného rozhodnutí.
     *
     * @return bool true = rozhodnutí je zapsané (i když už bylo zapsané dřív)
     */
    public function dismiss(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }
        // Vynucené MFA odmítnout nejde ani přímým voláním endpointu — jinak by
        // stačilo poslat jeden POST a politika by přestala platit.
        if ($this->policy->isRequired()) {
            return false;
        }

        $stmt = $this->db->pdo()->prepare(
            'UPDATE users
                SET mfa_offer_dismissed_at = UTC_TIMESTAMP(6)
              WHERE id = ? AND mfa_offer_dismissed_at IS NULL'
        );
        $stmt->execute([$userId]);

        return true;
    }
}
