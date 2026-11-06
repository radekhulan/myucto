<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tenant\TenantUrlResolver;
use PDO;
use Psr\Clock\ClockInterface;

/**
 * Veřejná účtenka pro zákazníka `/r/{token}` (tabulka `receipt_links`).
 *
 * Token má 256 bitů (64 hex znaků) a do databáze se ukládá jen jeho SHA-256. Kdo má
 * kopii databáze, odkaz nesestaví; plný token vidí jen ten, kdo odkaz vytvořil
 * (odpověď rychlé účtenky nebo správy odkazu). Odkaz platí omezenou dobu
 * (`public_receipt.ttl_days`, výchozí 90 dní) a lze ho odvolat.
 *
 * Účtenka patří jen k vystavenému a zaplacenému dokladu typu faktura (včetně
 * zjednodušeného daňového dokladu): je to potvrzení o zaplacení na místě, ne výzva
 * k platbě. Na nezaplacenou fakturu je „web faktura" (`/invoice/{token}`).
 *
 * Jediný vlastník URL formátu `/r/{token}`; cesta musí ladit s Vue routou ve
 * web/src/router/index.ts.
 */
final class ReceiptLinkService
{
    public const DEFAULT_TTL_DAYS = 90;
    public const MAX_TTL_DAYS = 365;

    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly ClockInterface $clock,
        private readonly ?TenantUrlResolver $tenantUrls = null,
    ) {}

    public static function isValidFormat(string $token): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $token) === 1;
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Smí doklad mít veřejnou účtenku? `null` = smí, jinak důvod (kód chyby).
     *
     * @param array<string,mixed> $invoice řádek z InvoiceRepository::find()
     */
    public static function ineligibility(array $invoice): ?string
    {
        if (in_array((string) ($invoice['status'] ?? ''), ['draft', 'cancelled'], true)) {
            return 'receipt_not_issued';
        }
        if ((string) ($invoice['invoice_type'] ?? '') !== 'invoice') {
            return 'receipt_invalid_type';
        }
        if (!InvoicePaidState::isEffectivelyPaid($invoice)) {
            return 'receipt_not_paid';
        }
        return null;
    }

    public function ttlDays(?int $requested = null): int
    {
        $days = $requested ?? (int) $this->config->get('public_receipt.ttl_days', self::DEFAULT_TTL_DAYS);
        return max(1, min(self::MAX_TTL_DAYS, $days));
    }

    /**
     * Nový odkaz. Dřívější odkazy téhož dokladu zůstávají platné (zákazník je už může
     * mít v telefonu); zneplatní je až {@see revokeAll()}.
     *
     * @return array{token:string, url:string, expires_at:string}
     */
    public function create(int $supplierId, int $invoiceId, ?int $userId, ?int $ttlDays = null): array
    {
        $token = bin2hex(random_bytes(32));
        $expires = $this->now()->modify('+' . $this->ttlDays($ttlDays) . ' days');
        $this->db->pdo()->prepare(
            'INSERT INTO receipt_links (supplier_id, invoice_id, token_hash, expires_at, created_by)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$supplierId, $invoiceId, self::hash($token), $expires->format('Y-m-d H:i:s'), $userId !== null && $userId > 0 ? $userId : null]);

        return [
            'token' => $token,
            'url' => $this->url($token, $supplierId),
            'expires_at' => $expires->format(DATE_ATOM),
        ];
    }

    /** Odvolá všechny platné odkazy dokladu. Vrací počet odvolaných. */
    public function revokeAll(int $supplierId, int $invoiceId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE receipt_links SET revoked_at = ? WHERE supplier_id = ? AND invoice_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$this->now()->format('Y-m-d H:i:s'), $supplierId, $invoiceId]);
        return $stmt->rowCount();
    }

    /**
     * Platný (neodvolaný, neprošlý) odkaz podle tokenu, nebo null. Vyhledává se podle
     * otisku přes unikátní index.
     *
     * @return array{supplier_id:int, invoice_id:int, expires_at:string}|null
     */
    public function resolve(string $token): ?array
    {
        if (!self::isValidFormat($token)) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT supplier_id, invoice_id, expires_at FROM receipt_links
              WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > ?'
        );
        $stmt->execute([self::hash($token), $this->now()->format('Y-m-d H:i:s')]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'supplier_id' => (int) $row['supplier_id'],
            'invoice_id' => (int) $row['invoice_id'],
            'expires_at' => (new \DateTimeImmutable((string) $row['expires_at'], $this->zone()))->format(DATE_ATOM),
        ];
    }

    /** Počet platných odkazů dokladu (pro detail v aplikaci). */
    public function activeCount(int $supplierId, int $invoiceId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM receipt_links
              WHERE supplier_id = ? AND invoice_id = ? AND revoked_at IS NULL AND expires_at > ?'
        );
        $stmt->execute([$supplierId, $invoiceId, $this->now()->format('Y-m-d H:i:s')]);
        return (int) $stmt->fetchColumn();
    }

    public function url(string $token, int $supplierId = 0): string
    {
        if ($this->tenantUrls !== null && $supplierId > 0) {
            return $this->tenantUrls->forSupplier($supplierId, 'public_links', '/r/' . $token);
        }
        return rtrim((string) $this->config->get('app.url', ''), '/') . '/r/' . $token;
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone($this->zone());
    }

    private function zone(): \DateTimeZone
    {
        return new \DateTimeZone((string) $this->config->get('app.timezone', 'Europe/Prague'));
    }
}
