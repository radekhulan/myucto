<?php

declare(strict_types=1);

namespace MyInvoice\Service;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Loguje akce do tabulky activity_log. Citlivá pole se redaktují
 * (LoggerSanitizer). Pro auth/security události samostatný kanál.
 *
 * Není `final` (EP-4): účetní služby zapisují audit ve STEJNÉ transakci jako
 * účetní mutaci, takže integrační testy potřebují injektovat testovacího dvojníka
 * loggeru, který zápis auditu záměrně shodí (ověření rollbacku mutace). log() je
 * proto přepsatelný; produkční chování zůstává beze změny.
 */
class ActivityLogger
{
    /**
     * Klíče, jejichž hodnota se do `activity_log` nezapíše.
     *
     * ── Proč tu NENÍ `full_name` ─────────────────────────────────────────────
     * Úplný výmaz osoby loguje jméno smazaného
     * ({@see \MyInvoice\Repository\Payroll\PayrollEmployeeDeletionRepository::delete()})
     * a redakce se na něj vědomě neuplatňuje. Není to přehlédnutí a neopravovat:
     *
     *  - úplný výmaz je NEVRATNÝ a řádek už neexistuje. Auditní zápis „smazán
     *    zaměstnanec #418" bez jména neříká nic — nedá se z něj poznat, koho se
     *    úkon týkal, ani rozlišit omylem založený duplicitní záznam od skutečného
     *    člověka. Auditní stopa, ze které nejde určit předmět úkonu, není stopa;
     *  - typickým důvodem ručního výmazu je právě omylem založená osoba, u které
     *    jméno v logu být MÁ. Řádné ukončení retence jménem neprochází: to je
     *    ANONYMIZACE, a ta do auditu jméno nezapisuje vůbec (hlídá to test
     *    `testAuditPayloadCarriesNoPersonalData`);
     *  - `full_name` je navíc klíč sdílený celou aplikací (kontakty, osoby,
     *    vyživované děti). Doplnit ho sem by ztichlo i tam, kde je jméno
     *    legitimním předmětem záznamu, a to bez ohledu na mzdovou agendu.
     *
     * Citlivější složky identity redaktované jsou i tady: rodné číslo, rodné
     * příjmení, adresa, kontakt i číslo účtu jsou v seznamu níž.
     */
    private const REDACT_KEYS = [
        'password', 'password_confirm', 'current_password', 'new_password',
        'token', 'csrf_token', 'cf_turnstile_response', 'secret_key',
        'private_key', 'pass',
        'flow_token', 'step_up_token', 'challenge', 'credential', 'rawid',
        'raw_id', 'signature', 'authenticator_data', 'client_data_json',
        'attestation_object', 'public_key',
        'birth_number', 'personal_identifier', 'national_id', 'foreign_tax_identifier',
        'birth_surname', 'street_line', 'postal_code', 'contact_value',
        'contact_value_ciphertext', 'contact_value_hash', 'contact_value_masked',
        'bank_account', 'account_number', 'iban', 'diagnosis', 'medical_code',
        'enforcement_case_number', 'insolvency_case_number', 'ciphertext',
        'personal_identifier_ciphertext', 'foreign_tax_id_ciphertext',
        'bank_account_ciphertext', 'monthly_gross', 'gross_minor', 'net_minor',
        // ─── Odesílací brána ISDS (SetConcept) ───
        // `timeLimitedId` je HESLO Basic autentizace vůči bráně (uživatel
        // `ExtWS`), `sessionId` se za něj jednorázově vyměňuje. Ani jedno
        // nesmí skončit v auditní stopě. `app_token` tajemství není, ale je to
        // ukazatel na rozpracované podání a v logu není k ničemu. Klíč se
        // porovnává přesně, proto tu jsou obě obvyklé podoby zápisu.
        'time_limited_id', 'timelimitedid', 'session_id', 'sessionid',
        'app_token', 'apptoken', 'certificate', 'certificate_bytes',
        'certificate_password', 'certificate_passphrase', 'passphrase',
        'certificate_ciphertext', 'certificate_passphrase_ciphertext',
        'pfx_ciphertext', 'pkey', 'client_certificate',
    ];

    public function __construct(
        private readonly Connection $db,
        // § 33a — zřetězení auditní stopy hashem. Volitelná, aby testovací dvojníci
        // loggeru (viz docblock třídy) nemuseli řetěz řešit.
        private readonly ?ActivityLogHashChain $hashChain = null,
    ) {}

    /** @param array<array-key,mixed>|null $payload */
    public function log(
        string $action,
        ?int $userId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $payload = null,
        ?string $ip = null,
        ?string $userAgent = null,
        ?int $supplierId = null,
    ): void {
        // Auto-resolve supplier_id z entity (invoice/client/project) když nebylo zadáno
        if ($supplierId === null && $entityId !== null && $entityType !== null) {
            $supplierId = $this->resolveSupplierId($entityType, $entityId);
        }

        $sql = 'INSERT INTO activity_log
                (supplier_id, user_id, action, entity_type, entity_id, payload, ip, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            $supplierId,
            $userId,
            $action,
            $entityType,
            $entityId,
            $payload === null ? null : json_encode($this->redact($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $ip !== null ? (@inet_pton($ip) ?: null) : null,
            $userAgent !== null ? substr($userAgent, 0, 255) : null,
        ]);

        // Zapečetění hned po vložení, uvnitř TÉŽE transakce jako zapisovaná mutace —
        // jinak by při rollbacku zůstal v řetězu článek bez odpovídající změny.
        $this->hashChain?->seal((int) $this->db->pdo()->lastInsertId());
    }

    /** Auto-resolve supplier_id z entity podle entity_type. NULL pro cross-cutting akce. */
    private function resolveSupplierId(string $entityType, int $entityId): ?int
    {
        $pdo = $this->db->pdo();
        $sql = match ($entityType) {
            'invoice'  => 'SELECT supplier_id FROM invoices WHERE id = ?',
            'client'   => 'SELECT supplier_id FROM clients  WHERE id = ?',
            'project'  => 'SELECT c.supplier_id FROM projects p JOIN clients c ON c.id = p.client_id WHERE p.id = ?',
            'supplier' => 'SELECT id FROM supplier WHERE id = ?',
            'bank_statement'   => 'SELECT supplier_id FROM bank_statements WHERE id = ?',
            'bank_transaction' => 'SELECT bs.supplier_id FROM bank_transactions bt
                                     JOIN bank_statements bs ON bs.id = bt.statement_id WHERE bt.id = ?',
            default    => null,
        };
        if ($sql === null) return null;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$entityId]);
        $sid = $stmt->fetchColumn();
        return $sid !== false && $sid !== null ? (int) $sid : null;
    }

    /**
     * @param array<array-key,mixed> $data
     * @return array<array-key,mixed>
     */
    public function redact(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACT_KEYS, true)) {
                $out[$key] = '[REDACTED]';
                continue;
            }
            if (is_array($value)) {
                $out[$key] = $this->redact($value);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }
}
