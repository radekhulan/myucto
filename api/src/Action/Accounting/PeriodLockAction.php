<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Vat\FiledVatPeriodLookup;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Správa měkkého zámku účtování k datu (B8, audit 2026-07 core-posting) —
 * accounting_supplier_settings.locked_until. Doklady s entry_date <= locked_until
 * backend odmítne zaúčtovat/re-postnout (PostingService, 'date_locked'); storno je
 * možné jen s protizápisem do otevřeného data.
 *
 *   GET /api/accounting/period-lock — čtení (readonly+)
 *   PUT /api/accounting/period-lock — nastavení/posun zámku, povinný reason;
 *       smí ten, kdo spravuje účetní období, nebo role s právem `accounting.period_lock`
 *       (hlavní účetní), viz {@see RequestAuthorization::canChangePeriodLock()}
 *
 * Auto-posun zámku VPŘED dělá i {@see \MyInvoice\Service\Report\TaxSubmissionArchiver}
 * po archivaci DPH přiznání (VAT-lock/H7). Tento endpoint umožní zámek posunout ručně
 * i ZPĚT (oprava už podaného období) nebo zrušit (locked_until = null), proto povinné
 * zdůvodnění do activity_log (before/after + reason). Posun zpět přes podané období DPH
 * nebo přes uzavřené účetní období zůstává jen správcům
 * ({@see RequestAuthorization::canUnlockProtectedPeriods()}); hlavní účetní dostane 409.
 */
final class PeriodLockAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    private const MIN_REASON_LEN = 5;

    public function __construct(
        private readonly AccountingSupplierSettingsRepository $settings,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
        private readonly FiledVatPeriodLookup $filedVat,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        return Json::ok($response, ['locked_until' => $this->settings->getLockedUntil($supplierId)]);
    }

    public function update(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::canChangePeriodLock($request)) {
            return Json::error($response, 'forbidden', 'Zámek účtování k datu smí posunout jen správce nebo role s právem posunout zámek.', 403);
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);

        // Chybějící klíč nesmí tiše zrušit zámek — zrušení je jen explicitní null.
        if (!array_key_exists('locked_until', $body)) {
            return Json::error($response, 'validation_failed', 'Zadej locked_until (datum YYYY-MM-DD, nebo null pro zrušení zámku).', 400);
        }
        $raw = $body['locked_until'];
        $lockedUntil = null;
        if ($raw !== null && $raw !== '') {
            $lockedUntil = (string) $raw;
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $lockedUntil);
            if ($d === false || $d->format('Y-m-d') !== $lockedUntil) {
                return Json::error($response, 'validation_failed', 'locked_until musí být datum ve formátu YYYY-MM-DD, nebo null.', 422);
            }
        }

        $reason = trim((string) ($body['reason'] ?? ''));
        if (mb_strlen($reason) < self::MIN_REASON_LEN) {
            return Json::error($response, 'validation_failed', 'Zdůvodnění změny zámku je povinné (min. ' . self::MIN_REASON_LEN . ' znaků).', 422);
        }

        $before = $this->settings->getLockedUntil($supplierId);
        if ($before !== null && ($lockedUntil === null || $lockedUntil < $before)
            && !RequestAuthorization::canUnlockProtectedPeriods($request)) {
            $blocked = $this->protectedPeriodIn($supplierId, $lockedUntil, $before);
            if ($blocked !== null) {
                return Json::error($response, $blocked[0], $blocked[1], 409);
            }
        }
        $this->settings->setLockedUntil($supplierId, $lockedUntil);

        $this->activity->log(
            'accounting.lock_date_changed',
            $this->userId($request),
            'supplier',
            $supplierId,
            ['before' => $before, 'after' => $lockedUntil, 'reason' => $reason],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return Json::ok($response, ['locked_until' => $lockedUntil]);
    }

    /**
     * Odemyká posun zpět (z `$before` na `$after`, null = zrušení) uzavřené účetní období
     * nebo podané období DPH? Vrací [kód, hláška] první pojistky, nebo null.
     *
     * @return array{0:string, 1:string}|null
     */
    private function protectedPeriodIn(int $supplierId, ?string $after, string $before): ?array
    {
        $pdo = $this->db->pdo();
        $from = $after !== null ? (new \DateTimeImmutable($after))->modify('+1 day')->format('Y-m-d') : null;
        $closed = $pdo->prepare(
            "SELECT fiscal_year FROM accounting_periods
              WHERE supplier_id = ? AND status <> 'open' AND starts_on <= ? AND (? IS NULL OR ends_on >= ?)
              ORDER BY starts_on LIMIT 1"
        );
        $closed->execute([$supplierId, $before, $from, $from]);
        $year = $closed->fetchColumn();
        if ($year !== false) {
            return ['period_lock_closed_period', 'Zámek nejde posunout zpět do uzavřeného účetního období ' . $year . '. Posun zpět přes uzavřené období smí jen správce.'];
        }

        if ($from === null) {
            $first = $pdo->prepare('SELECT MIN(starts_on) FROM accounting_periods WHERE supplier_id = ?');
            $first->execute([$supplierId]);
            $from = (string) ($first->fetchColumn() ?: substr($before, 0, 4) . '-01-01');
        }
        $month = new \DateTimeImmutable(substr($from, 0, 7) . '-01');
        $last = new \DateTimeImmutable(substr($before, 0, 7) . '-01');
        for (; $month <= $last; $month = $month->modify('+1 month')) {
            $filed = $this->filedVat->describe($supplierId, $month->format('Y-m-d'));
            if ($filed !== null) {
                return ['period_lock_filed_vat', 'Zámek nejde posunout zpět přes podané období (' . $filed . '). Podané se opravuje opravným zápisem; posun zpět přes podané období smí jen správce.'];
            }
        }
        return null;
    }
}
