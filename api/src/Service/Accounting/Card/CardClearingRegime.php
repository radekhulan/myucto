<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Card;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CardClearingSettingsRepository;
use MyInvoice\Service\Bank\Card\CardNumberMask;

/**
 * Platí pro tenhle pohyb režim „platba kartou přes mezičlen"? Jediné místo té otázky.
 *
 * Režim platí, když firma vede podvojné účetnictví, má ho zapnutý, pohyb je z výpisu
 * (avízo se neúčtuje), nese koncovku karty a je datovaný od data účinnosti. Pohyb před
 * datem účinnosti zůstává ve starém režimu navždy — historie se nepřeúčtovává.
 *
 * Rozhodnutí o konkrétním pohybu dělá {@see \MyInvoice\Service\Accounting\Bank\BankPostingService}
 * (zná zaúčtování a pravidla výběru hotovosti a poplatků); tahle třída odpovídá jen
 * na podmínky firmy a data a dodá analytiku.
 */
final class CardClearingRegime
{
    /** @var array<int, array<string,mixed>> */
    private array $settingsMemo = [];

    public function __construct(
        private readonly Connection $db,
        private readonly CardClearingSettingsRepository $settings,
        private readonly CardClearingAccounts $accounts,
    ) {}

    /** @return array<string,mixed> */
    public function settings(int $supplierId): array
    {
        return $this->settingsMemo[$supplierId] ??= $this->settings->find($supplierId);
    }

    /** Po uložení nastavení (a v testech) — memo by jinak drželo starý stav do konce requestu. */
    public function forget(int $supplierId): void
    {
        unset($this->settingsMemo[$supplierId]);
    }

    public function isActiveOn(int $supplierId, string $date): bool
    {
        $s = $this->settings($supplierId);
        if (empty($s['enabled']) || $s['effective_from'] === null) {
            return false;
        }
        if (substr($date, 0, 10) < (string) $s['effective_from']) {
            return false;
        }
        return $this->isDoubleEntry($supplierId);
    }

    /**
     * Mezičlen pro pohyb, pokud režim platí; jinak null.
     *
     * @param array<string,mixed> $tx
     * @return array{code:string, card_id:?int, resolved:bool}|null
     */
    public function clearingFor(int $supplierId, array $tx, bool $create = false): ?array
    {
        if ((string) ($tx['source'] ?? 'statement') !== 'statement') {
            return null;
        }
        if (!CardNumberMask::isValidLast4((string) ($tx['card_last4'] ?? ''))) {
            return null;
        }
        if (!$this->isActiveOn($supplierId, (string) ($tx['posted_at'] ?? ''))) {
            return null;
        }
        return $this->accounts->resolveForTransaction($supplierId, $tx, $this->settings($supplierId), $create);
    }

    /** Je kód účtu analytikou mezičlenu karet firmy (pod kteroukoli povolenou syntetikou)? */
    public function isClearingCode(int $supplierId, string $code): bool
    {
        return in_array($code, $this->accounts->allClearingCodes($supplierId), true);
    }

    private function isDoubleEntry(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return (string) $stmt->fetchColumn() === 'double_entry';
    }
}
