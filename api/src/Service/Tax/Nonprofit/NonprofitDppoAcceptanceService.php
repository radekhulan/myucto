<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\Return\Dppo504CapabilityService;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use PDO;

/**
 * Akceptace nativního výpočtu DPPO 504 firmou.
 *
 * Bez platné akceptace backend odmítne finalizaci, XML i podání DPPO 504. Akceptace se
 * váže na verzi právních pravidel a připnutou verzi formuláře; změna kterékoli z nich
 * akceptaci zneplatní a vyžaduje nové ověření. Záznamy jsou neměnné, odvolání je nová revize.
 */
final class NonprofitDppoAcceptanceService
{
    public const ACCEPT = 'accept';
    public const REVOKE = 'revoke';

    public function __construct(private readonly Connection $db) {}

    /** Aktuální stav akceptace firmy bez zámků. */
    public function state(int $supplierId): array
    {
        $head = $this->head($supplierId, false);
        $expected = $this->expectedBinding();
        $accepted = $head !== null && $head['status'] === 'accepted' && $this->matches($head, $expected);
        return [
            'accepted' => $accepted,
            'stale' => $head !== null && $head['status'] === 'accepted' && !$accepted,
            'head' => $head,
            'binding' => $expected,
        ];
    }

    public function isAccepted(int $supplierId): bool
    {
        return $this->state($supplierId)['accepted'];
    }

    public function assertAccepted(int $supplierId): void
    {
        if (!$this->isAccepted($supplierId)) {
            throw new TaxReturnException('nonprofit_dppo_calculation_not_accepted',
                'Finalizace, XML a podání DPPO 504 vyžadují výslovnou akceptaci výpočtu správcem firmy pro aktuální verzi pravidel a formuláře.', 409);
        }
    }

    public function history(int $supplierId, int $limit = 50): array
    {
        if ($limit < 1 || $limit > 100) throw new \InvalidArgumentException('Acceptance history limit is invalid.');
        $stmt = $this->db->pdo()->prepare('SELECT * FROM nonprofit_dppo_acceptances
            WHERE supplier_id=? ORDER BY revision_no DESC LIMIT ' . $limit);
        $stmt->execute([$supplierId]);
        return array_map($this->decode(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Zapíše akceptaci nebo její odvolání. Volající ověřuje roli (správce firmy) v
     * RequestAuthorization; služba vynucuje výslovné potvrzení, odkaz na ověření a CAS hlavy.
     */
    public function change(int $supplierId, string $operation, bool $confirmed, string $verificationReference,
        string $reason, string $idempotencyKey, ?int $expectedHeadId, int $actor): array
    {
        if (!in_array($operation, [self::ACCEPT, self::REVOKE], true)) throw new \InvalidArgumentException('Unknown acceptance operation.');
        $verificationReference = trim($verificationReference);
        $reason = trim($reason);
        if ($supplierId <= 0 || $actor <= 0 || $idempotencyKey === '' || strlen($idempotencyKey) > 80
            || preg_match('/^[A-Za-z0-9_.:-]+$/D', $idempotencyKey) !== 1
            || $reason === '' || mb_strlen($reason) > 2000 || mb_strlen($verificationReference) > 500) {
            $this->invalid();
        }
        if ($operation === self::ACCEPT && (!$confirmed || $verificationReference === '')) {
            throw new TaxReturnException('nonprofit_dppo_acceptance_confirmation_required',
                'Akceptace výpočtu vyžaduje výslovné potvrzení a odkaz na provedené ověření.', 422);
        }
        $binding = $this->expectedBinding();
        $requestHash = NonprofitTaxRules::hash(['operation' => $operation, 'supplier_id' => $supplierId,
            'binding' => $binding, 'verification_reference' => $verificationReference, 'reason' => $reason,
            'expected_head_id' => $expectedHeadId, 'actor' => $actor, 'idempotency_key' => $idempotencyKey]);
        $pdo = $this->db->pdo();
        $owned = !$pdo->inTransaction();
        if ($owned) $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT id FROM supplier WHERE id=? FOR UPDATE');
            $lock->execute([$supplierId]);
            if ($lock->fetchColumn() === false) throw new TaxReturnException('nonprofit_dppo_acceptance_supplier_missing', 'Firma nebyla nalezena.', 404);
            $existing = $pdo->prepare('SELECT * FROM nonprofit_dppo_acceptances WHERE supplier_id=? AND idempotency_key=? FOR UPDATE');
            $existing->execute([$supplierId, $idempotencyKey]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                if (!hash_equals($row['request_hash'], $requestHash)) {
                    throw new TaxReturnException('nonprofit_dppo_acceptance_idempotency_conflict', 'Klíč požadavku patří jiné změně akceptace.', 409);
                }
                $result = $this->decode($row);
            } else {
                $head = $this->head($supplierId, true);
                if (($head['id'] ?? null) !== $expectedHeadId) {
                    throw new TaxReturnException('nonprofit_dppo_acceptance_changed', 'Stav akceptace se mezitím změnil. Načtěte jej znovu.', 409);
                }
                if ($operation === self::REVOKE && ($head === null || $head['status'] !== 'accepted')) {
                    throw new TaxReturnException('nonprofit_dppo_acceptance_not_active', 'Výpočet není akceptovaný, není co odvolat.', 409);
                }
                $insert = $pdo->prepare('INSERT INTO nonprofit_dppo_acceptances
                    (supplier_id,revision_no,previous_id,status,ruleset,form_code,form_version,xsd_sha256,
                     verification_reference,reason,idempotency_key,request_hash,created_by)
                    VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $insert->execute([$supplierId, ($head['revision_no'] ?? 0) + 1, $head['id'] ?? null,
                    $operation === self::ACCEPT ? 'accepted' : 'revoked', $binding['ruleset'], $binding['form_code'],
                    $binding['form_version'], $binding['xsd_sha256'], $verificationReference, $reason,
                    $idempotencyKey, $requestHash, $actor]);
                $result = $this->head($supplierId, true);
            }
            if ($owned) $pdo->commit();
            return $result;
        } catch (\Throwable $failure) {
            if ($owned && $pdo->inTransaction()) $pdo->rollBack();
            throw $failure;
        }
    }

    /** Verze, ke které se akceptace váže: právní pravidla DPPO 504 a připnutý formulář. */
    public function expectedBinding(): array
    {
        $manifest = (new Dppo504CapabilityService())->manifest();
        return ['ruleset' => NonprofitDppoLegalRules::VERSION, 'form_code' => $manifest['form_code'],
            'form_version' => $manifest['form_version'], 'xsd_sha256' => $manifest['xsd_sha256']];
    }

    private function matches(array $head, array $binding): bool
    {
        foreach ($binding as $key => $value) {
            if (($head[$key] ?? null) !== $value) return false;
        }
        return true;
    }

    private function head(int $supplierId, bool $currentRead): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM nonprofit_dppo_acceptances
            WHERE supplier_id=? ORDER BY revision_no DESC LIMIT 1' . ($currentRead ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->decode($row);
    }

    private function decode(array $row): array
    {
        return ['id' => (int) $row['id'], 'supplier_id' => (int) $row['supplier_id'],
            'revision_no' => (int) $row['revision_no'],
            'previous_id' => $row['previous_id'] === null ? null : (int) $row['previous_id'],
            'status' => $row['status'], 'ruleset' => $row['ruleset'], 'form_code' => $row['form_code'],
            'form_version' => $row['form_version'], 'xsd_sha256' => $row['xsd_sha256'],
            'verification_reference' => $row['verification_reference'], 'reason' => $row['reason'],
            'created_by' => (int) $row['created_by'], 'created_at' => $row['created_at']];
    }

    private function invalid(): never
    {
        throw new TaxReturnException('nonprofit_dppo_acceptance_invalid', 'Požadavek na akceptaci výpočtu DPPO 504 není platný.', 422);
    }
}
