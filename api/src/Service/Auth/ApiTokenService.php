<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\IpMatcher;
use PDO;

/**
 * Personal Access Tokens pro veřejné REST API.
 *
 * Plaintext token: "mi_pat_" + 43 znaků base64url(random_bytes(32)).
 *   - V DB jen SHA-256 hash (`token_hash`).
 *   - Plaintext se vrací uživateli pouze jednou při `generate()`.
 *
 * Validace: SHA-256 lookup přes unique index. Konstantní-time srovnání není
 * potřeba — útočník nemůže iterovat 2^256 hashů.
 *
 * `touch()` aktualizuje `last_used_at` max 1× za 5 min (Redis throttle),
 * aby běžný traffic netloukl DB na každý request.
 */
final class ApiTokenService
{
    private const PLAINTEXT_PREFIX = 'mi_pat_';
    private const RANDOM_BYTES = 32;
    private const TOUCH_INTERVAL_SEC = 300;

    public function __construct(
        private readonly Connection $db,
        private readonly RedisFactory $redis,
    ) {}

    /**
     * Vygeneruje nový token. Vrací plaintext (zobrazit uživateli jednou).
     *
     * @return array{plaintext: string, prefix: string, id: int}
     */
    public function generate(
        int $userId,
        ?int $supplierId,
        string $name,
        string $scope,
        ?\DateTimeImmutable $expiresAt = null,
        bool $allowPayrollSubmissionDocs = false,
    ): array {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $token = $this->generateInTransaction(
                $pdo,
                $userId,
                $supplierId,
                $name,
                $scope,
                $expiresAt,
                $allowPayrollSubmissionDocs,
            );
            $pdo->commit();
            return $token;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array{plaintext: string, prefix: string, id: int}
     */
    public function generateInTransaction(
        PDO $pdo,
        int $userId,
        ?int $supplierId,
        string $name,
        string $scope,
        ?\DateTimeImmutable $expiresAt = null,
        bool $allowPayrollSubmissionDocs = false,
    ): array {
        if (!$pdo->inTransaction() || $userId < 1) {
            throw new \LogicException('Vytvoření API tokenu vyžaduje aktivní transakci a uživatele.');
        }
        if (!in_array($scope, ['read', 'read_write'], true)) {
            throw new \InvalidArgumentException('Invalid scope: ' . $scope);
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('Token name must be 1–100 chars');
        }

        $raw       = random_bytes(self::RANDOM_BYTES);
        $body      = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $plaintext = self::PLAINTEXT_PREFIX . $body;
        $hash      = hash('sha256', $plaintext);
        $prefix    = substr($plaintext, 0, 12);

        $expiresSql = $expiresAt !== null ? 'FROM_UNIXTIME(?)' : 'NULL';
        $stmt = $pdo->prepare(
            'INSERT INTO api_tokens (user_id, supplier_id, name, token_hash, prefix, scope,
                                     allow_payroll_submission_docs, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ' . $expiresSql . ')'
        );
        $params = [
            $userId,
            $supplierId,
            $name,
            $hash,
            $prefix,
            $scope,
            $allowPayrollSubmissionDocs ? 1 : 0,
        ];
        if ($expiresAt !== null) {
            $params[] = $expiresAt->getTimestamp();
        }
        $stmt->execute($params);

        return [
            'plaintext' => $plaintext,
            'prefix'    => $prefix,
            'id'        => (int) $pdo->lastInsertId(),
        ];
    }

    /**
     * Ověří plaintext. Vrací řádek `api_tokens` rozšířený o `user_*` a `supplier_*`
     * nebo null, pokud token neexistuje, byl revokován nebo expiroval.
     */
    public function validate(string $plaintext): ?array
    {
        if (!str_starts_with($plaintext, self::PLAINTEXT_PREFIX)) {
            return null;
        }
        // Sanity check délky — odhadneme očekávanou délku, ať netáhneme nesmysly do DB.
        if (strlen($plaintext) < 20 || strlen($plaintext) > 80) {
            return null;
        }

        $hash = hash('sha256', $plaintext);

        $stmt = $this->db->pdo()->prepare(
            'SELECT t.id, t.user_id, t.supplier_id, t.name, t.prefix, t.scope,
                    t.allow_payroll_submission_docs,
                    t.expires_at, t.revoked_at,
                    u.email AS user_email, u.name AS user_name, u.role_id AS user_role_id,
                    r.name AS user_role_name, r.role_type AS user_role_type,
                    r.is_active AS user_role_active, r.system_key AS user_role_system_key,
                    u.locale AS user_locale, u.is_active AS user_is_active,
                    u.totp_enabled AS user_totp_enabled
             FROM api_tokens t
             JOIN users u ON u.id = t.user_id
             JOIN roles r ON r.id = u.role_id
             WHERE t.token_hash = ?
               AND t.revoked_at IS NULL
               AND (t.expires_at IS NULL OR t.expires_at > NOW())
             LIMIT 1'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        if ((int) $row['user_is_active'] !== 1 || (int) $row['user_role_active'] !== 1) {
            return null;
        }

        $row['id']                = (int) $row['id'];
        $row['user_id']           = (int) $row['user_id'];
        $row['user_role_id']      = (int) $row['user_role_id'];
        $row['user_role_active']  = true;
        $row['supplier_id']       = $row['supplier_id'] !== null ? (int) $row['supplier_id'] : null;
        $row['user_is_active']    = true;
        $row['user_totp_enabled'] = (int) ($row['user_totp_enabled'] ?? 0) === 1;
        return $row;
    }

    /**
     * Update last_used_at / last_used_ip. Throttle 5 min přes Redis;
     * při nedostupném Redisu updatuje pokaždé (vzácný edge-case).
     */
    public function touch(int $tokenId, string $ip): void
    {
        $key = 'apitok:touch:' . $tokenId;
        // SET key 1 EX 300 NX — vrátí "OK" pokud nastaveno, null pokud klíč už existuje (= updateováno nedávno)
        $throttled = $this->redis->run(
            fn($r) => $r->set($key, '1', 'EX', self::TOUCH_INTERVAL_SEC, 'NX') === null,
            false,
        );
        if ($throttled === true) {
            return;
        }

        $packed = @inet_pton($ip);
        $stmt = $this->db->pdo()->prepare(
            'UPDATE api_tokens SET last_used_at = NOW(), last_used_ip = ? WHERE id = ?'
        );
        $stmt->execute([$packed !== false ? $packed : null, $tokenId]);
    }

    /**
     * Vypíše tokeny daného usera (bez plaintextu).
     */
    public function listForUser(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT t.id, t.supplier_id, s.display_name AS supplier_name, s.company_name AS supplier_company,
                    t.name, t.prefix, t.scope, t.allow_payroll_submission_docs,
                    t.last_used_at, t.last_used_ip,
                    t.expires_at, t.revoked_at, t.created_at,
                    (SELECT COUNT(*) FROM api_token_ips ip WHERE ip.token_id = t.id) AS ip_rule_count
             FROM api_tokens t
             LEFT JOIN supplier s ON s.id = t.supplier_id
             WHERE t.user_id = ?
             ORDER BY t.revoked_at IS NOT NULL, t.created_at DESC'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['id']            = (int) $r['id'];
            $r['supplier_id']   = $r['supplier_id'] !== null ? (int) $r['supplier_id'] : null;
            $r['ip_rule_count'] = (int) $r['ip_rule_count'];
            // PDO vrací TINYINT jako řetězec, a "0" je v JS pravdivé — bez tohohle
            // přetypování by štítek schopnosti svítil i u tokenů, které ji nemají.
            $r['allow_payroll_submission_docs'] = (int) $r['allow_payroll_submission_docs'] === 1;
            if ($r['last_used_ip'] !== null) {
                $r['last_used_ip'] = @inet_ntop($r['last_used_ip']) ?: null;
            }
            $r['is_revoked'] = $r['revoked_at'] !== null;
            $r['is_expired'] = $r['expires_at'] !== null && strtotime((string) $r['expires_at']) < time();
        }
        return $rows;
    }

    /**
     * Pravidla IP allowlistu tokenu. PRÁZDNÝ SEZNAM = bez omezení.
     *
     * @return list<array{id:int,cidr:string,note:string,created_at:string}>
     */
    public function listIpRules(int $tokenId, int $userId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ip.id, ip.cidr, ip.note, ip.created_at
               FROM api_token_ips ip
               JOIN api_tokens t ON t.id = ip.token_id
              WHERE ip.token_id = ? AND t.user_id = ?
              ORDER BY ip.id'
        );
        $stmt->execute([$tokenId, $userId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
        }
        return $rows;
    }

    /**
     * Jen samotná pravidla (bez kontroly vlastnictví) — pro ověření při autentizaci.
     *
     * @return list<string>
     */
    public function ipRulesFor(int $tokenId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT cidr FROM api_token_ips WHERE token_id = ?');
        $stmt->execute([$tokenId]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * Přidá pravidlo. Vrací id, nebo null pokud token uživateli nepatří.
     *
     * @throws \InvalidArgumentException při neplatném zápisu adresy/rozsahu
     */
    public function addIpRule(int $tokenId, int $userId, string $cidr, string $note): ?int
    {
        $cidr = self::normalizeRule($cidr);
        $note = mb_substr(trim($note), 0, 255);

        $pdo  = $this->db->pdo();
        $own  = $pdo->prepare('SELECT 1 FROM api_tokens WHERE id = ? AND user_id = ?');
        $own->execute([$tokenId, $userId]);
        if ($own->fetchColumn() === false) {
            return null;
        }

        // Opakované přidání téhož pravidla není chyba — jen ho nechceme duplikovat.
        $stmt = $pdo->prepare(
            'INSERT INTO api_token_ips (token_id, cidr, note) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE note = VALUES(note)'
        );
        $stmt->execute([$tokenId, $cidr, $note]);

        $id = (int) $pdo->lastInsertId();
        if ($id > 0) {
            return $id;
        }
        $existing = $pdo->prepare('SELECT id FROM api_token_ips WHERE token_id = ? AND cidr = ?');
        $existing->execute([$tokenId, $cidr]);
        return (int) $existing->fetchColumn();
    }

    /**
     * Odebere pravidlo (jen z tokenu daného usera). Idempotentní.
     */
    public function deleteIpRule(int $ruleId, int $userId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE ip FROM api_token_ips ip
               JOIN api_tokens t ON t.id = ip.token_id
              WHERE ip.id = ? AND t.user_id = ?'
        );
        $stmt->execute([$ruleId, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Ověří a zkanonizuje zápis pravidla IP allowlistu.
     *
     * Kanonizaci adresy dělá {@see IpMatcher::canonicalize()} — tedy přesně ta
     * funkce, kterou pak používá i matchování. Vlastní normalizace by uložila
     * pravidlo v jiném tvaru (typicky `::ffff:1.2.3.4` místo `1.2.3.4`) a
     * allowlist by tiše nefungoval.
     *
     * Prefix se kontroluje proti rodině adresy (IPv4 max /32, IPv6 max /128) —
     * jinak by "1.2.3.0/64" prošlo do DB jako pravidlo, které nikdy nic
     * nenamatchuje, a uživatel by si myslel, že si přístup povolil.
     */
    public static function normalizeRule(string $rule): string
    {
        $rule = trim($rule);
        if ($rule === '') {
            throw new \InvalidArgumentException('Zadejte IP adresu nebo rozsah.');
        }
        if (mb_strlen($rule) > 64) {
            throw new \InvalidArgumentException('Pravidlo je příliš dlouhé.');
        }

        $addr   = $rule;
        $prefix = null;
        if (str_contains($rule, '/')) {
            [$addr, $prefixStr] = explode('/', $rule, 2);
            $addr = trim($addr);
            if (preg_match('/^\d{1,3}$/', trim($prefixStr)) !== 1) {
                throw new \InvalidArgumentException('Neplatná délka prefixu: ' . $rule);
            }
            $prefix = (int) trim($prefixStr);
        }

        $canonical = IpMatcher::canonicalize($addr);
        $bits      = IpMatcher::addressBits($canonical ?? '');
        if ($canonical === null || $bits === null) {
            throw new \InvalidArgumentException('Neplatná IP adresa: ' . $rule);
        }

        if ($prefix !== null && $prefix > $bits) {
            throw new \InvalidArgumentException(
                'Prefix /' . $prefix . ' je mimo rozsah pro ' . ($bits === 32 ? 'IPv4' : 'IPv6') . ' (max /' . $bits . ').'
            );
        }

        return $prefix !== null ? $canonical . '/' . $prefix : $canonical;
    }

    /**
     * Revokuje token. Idempotentní (opakované volání nezpůsobí chybu).
     * Bezpečnost: kontroluje, že token patří danému userovi.
     */
    public function revoke(int $tokenId, int $userId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE api_tokens SET revoked_at = COALESCE(revoked_at, NOW())
             WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$tokenId, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Trvale smaže vlastní token. Na rozdíl od {@see revoke()} po něm nezůstane
     * v přehledu nic — proto vrací SNÍMEK smazaného řádku, ať má volající co
     * zapsat do auditní stopy. Bez něj by mazání bylo jediná operace nad tokeny,
     * která po sobě nenechá dohledatelné, co vlastně zmizelo.
     *
     * ⚠️ Řádky `api_request_log` přežijí, ale FK je `ON DELETE SET NULL`, takže
     * ztratí přiřazení k tokenu (zůstanou jako volání bez jména). Zrušení tuhle
     * historii zachová celou — mazat má smysl až na uklizení přehledu.
     *
     * @return array{id:int,name:string,prefix:string,scope:string,
     *               allow_payroll_submission_docs:bool,revoked_at:?string}|null
     *         null = token neexistuje nebo nepatří uživateli
     */
    public function delete(int $tokenId, int $userId): ?array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            // Zámek řádku, ať se snímek nerozejde se skutečně smazaným tokenem
            // při souběžném mazání ze dvou záložek.
            $stmt = $pdo->prepare(
                'SELECT id, name, prefix, scope, allow_payroll_submission_docs, revoked_at
                 FROM api_tokens WHERE id = ? AND user_id = ? FOR UPDATE'
            );
            $stmt->execute([$tokenId, $userId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                $pdo->rollBack();
                return null;
            }

            $del = $pdo->prepare('DELETE FROM api_tokens WHERE id = ? AND user_id = ?');
            $del->execute([$tokenId, $userId]);
            $pdo->commit();

            return [
                'id'     => (int) $row['id'],
                'name'   => (string) $row['name'],
                'prefix' => (string) $row['prefix'],
                'scope'  => (string) $row['scope'],
                'allow_payroll_submission_docs' =>
                    (int) $row['allow_payroll_submission_docs'] === 1,
                'revoked_at' => $row['revoked_at'] !== null ? (string) $row['revoked_at'] : null,
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
