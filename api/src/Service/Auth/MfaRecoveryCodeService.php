<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Záložní jednorázové kódy pro MFA (migrace 1176) — break-glass, když uživatel
 * přijde o silný faktor.
 *
 * Kód NENÍ rovnocenná náhrada passkey ani TOTP: neprokazuje držení zařízení, jen
 * to, že si uživatel bezpečně uschoval tajemství vygenerované serverem. Proto je
 * jednorázový, proto se po každém použití hlásí a proto po vyčerpání sady zbývá
 * jen rescue na serveru. Cílem je, aby se majitel dostal do vlastních dat bez
 * shellu — ne aby měl trvalý druhý faktor zadarmo.
 *
 * ── Abeceda kódu ────────────────────────────────────────────────────────────────
 * Crockfordovská base32 bez `I`, `L`, `O` a `U`: `I`/`L` splývá s `1`, `O` s `0`
 * a `U` se vynechává, aby z náhodných znaků nevznikaly urážlivé shluky. Kód se
 * opisuje z papíru rukou, takže záměna znaků je reálný provozní problém, ne
 * teorie. Normalizace na vstupu proto velká/malá písmena i oddělovače ignoruje.
 *
 * 10 znaků × 32 možností = 50 bitů entropie na kód. Uhádnout ho hrubou silou přes
 * API nelze ani bez zámku, ale zámek {@see BruteForceGuard} je na něm stejně.
 */
final class MfaRecoveryCodeService
{
    /** Kolik kódů tvoří jednu sadu. */
    public const BATCH_SIZE = 10;

    /** Délka kódu ve znacích (bez oddělovače). */
    private const CODE_LENGTH = 10;

    /** Pod tímhle počtem zbývajících kódů UI tlačí na regeneraci. */
    public const LOW_WATERMARK = 3;

    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function __construct(private readonly Connection $db) {}

    /**
     * Vygeneruje novou sadu a NEVRATNĚ zahodí tu předchozí.
     *
     * Vrácený plaintext je jediná příležitost, kdy kódy existují mimo hlavu
     * uživatele — server si je neukládá, takže je nelze zobrazit podruhé.
     *
     * @return list<string> kódy ve formátu `XXXXX-XXXXX`
     */
    public function generate(int $userId): array
    {
        $codes = [];
        $seen = [];
        while (count($codes) < self::BATCH_SIZE) {
            $code = $this->randomCode();
            // Duplikát uvnitř dávky by uživateli tiše ubral jeden kód z deseti.
            if (isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $codes[] = $code;
        }

        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $pdo->prepare('DELETE FROM mfa_recovery_codes WHERE user_id = ?')->execute([$userId]);
            $insert = $pdo->prepare(
                'INSERT INTO mfa_recovery_codes (user_id, code_hash) VALUES (?, ?)'
            );
            foreach ($codes as $code) {
                $insert->execute([$userId, self::hash($code)]);
            }
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return array_map([self::class, 'format'], $codes);
    }

    /**
     * Uplatní kód. Vrací true jen tehdy, když byl platný A dosud nepoužitý.
     *
     * Jednorázovost drží samotný `UPDATE … WHERE used_at IS NULL`: dva souběžné
     * požadavky s týmž kódem se poperou o jeden řádek a `rowCount()` rozhodne,
     * který z nich vyhrál. Kontrola „nejdřív SELECT, pak UPDATE" by tenhle závod
     * prohrála a pustila by oba.
     */
    /**
     * První sada k čerstvě zapnutému druhému faktoru.
     *
     * ⚠️ Volá se ze VŠECH cest, kterými se dá druhý faktor zapnout — TOTP
     * i passkey. Dokud to uměl jen TOTP, prošel uživatel, který si zvolil
     * passkey, celým prvním přihlášením a skončil na nástěnce bez jediného
     * záložního kódu; kdyby o klíč přišel, zbýval zásah na serveru.
     *
     * ⚠️ Existující sadu NEPŘEPISUJE. {@see self::generate()} starou zneplatní,
     * takže přidání druhého faktoru by tiše sebralo kódy, které si uživatel
     * dávno uložil.
     *
     * ⚠️ NIKDY nevyhodí výjimku. Faktor je v tu chvíli už zapnutý; chyba by
     * volajícímu tvrdila, že se nic nestalo, a uživatel by při dalším pokusu
     * narazil na „už je zapnuté". Kódy si pak vydá v nastavení bezpečnosti.
     *
     * @return list<string> prázdné, když už použitelné kódy má nebo se zápis nepovedl
     */
    public function issueFirstBatch(int $userId): array
    {
        try {
            if ($this->hasUsable($userId)) {
                return [];
            }

            return $this->generate($userId);
        } catch (\Throwable) {
            return [];
        }
    }

    public function consume(int $userId, string $code, ?string $ip = null): bool
    {
        $normalized = self::normalize($code);
        if ($normalized === '') {
            return false;
        }

        $stmt = $this->db->pdo()->prepare(
            'UPDATE mfa_recovery_codes
                SET used_at = NOW(), used_ip = ?
              WHERE user_id = ? AND code_hash = ? AND used_at IS NULL'
        );
        $stmt->execute([
            $ip !== null ? (@inet_pton($ip) ?: null) : null,
            $userId,
            self::hash($normalized),
        ]);

        return $stmt->rowCount() === 1;
    }

    /** Počet dosud nepoužitých kódů. */
    public function remaining(int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM mfa_recovery_codes WHERE user_id = ? AND used_at IS NULL'
        );
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    /** Má uživatel aspoň jeden použitelný kód? */
    public function hasUsable(int $userId): bool
    {
        return $this->remaining($userId) > 0;
    }

    /**
     * Stav sady pro UI — nikdy neobsahuje kódy samotné.
     *
     * @return array{total:int, remaining:int, generated_at:?string, low:bool}
     */
    public function status(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) AS total,
                    SUM(used_at IS NULL) AS remaining,
                    MIN(created_at) AS generated_at
               FROM mfa_recovery_codes WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $remaining = (int) ($row['remaining'] ?? 0);

        return [
            'total'        => (int) ($row['total'] ?? 0),
            'remaining'    => $remaining,
            'generated_at' => $row['generated_at'] ?? null,
            'low'          => $remaining > 0 && $remaining <= self::LOW_WATERMARK,
        ];
    }

    /** Zahodí celou sadu (rescue skript, deaktivace účtu). */
    public function revokeAll(int $userId): int
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM mfa_recovery_codes WHERE user_id = ?');
        $stmt->execute([$userId]);

        return $stmt->rowCount();
    }

    /** `ABCDE-FGHJK` — oddělovač jen pro čitelnost, normalizace ho zahodí. */
    public static function format(string $code): string
    {
        return substr($code, 0, 5) . '-' . substr($code, 5);
    }

    /**
     * Velká písmena, pryč všechno mimo abecedu. Uživatel kód opisuje z papíru,
     * takže pomlčka, mezera i malá písmena musí projít.
     */
    public static function normalize(string $code): string
    {
        $upper = strtoupper(trim($code));
        $clean = preg_replace('/[^' . self::ALPHABET . ']/', '', $upper) ?? '';

        return strlen($clean) === self::CODE_LENGTH ? $clean : '';
    }

    private static function hash(string $normalizedCode): string
    {
        return hash('sha256', $normalizedCode);
    }

    private function randomCode(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }
}
