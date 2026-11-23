<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Check;

/**
 * Náhled opravy: co se změní (`changes`) a co oprava jen ohlásí (`skipped`).
 *
 * `blocked` nese důvod, proč oprava teď nejde spustit vůbec (např. doklad v uzavřeném
 * období u opravy, která firmu opravuje jen celou). `fingerprint` je otisk změn; oprava
 * ho porovná s aktuálním stavem a zastaví se, když se data mezi náhledem a potvrzením změnila.
 */
final class FixPreview
{
    /**
     * @param list<CheckItem> $changes
     * @param list<CheckItem> $skipped
     * @param list<string> $notes
     */
    public function __construct(
        public readonly array $changes,
        public readonly array $skipped,
        public readonly array $notes = [],
        public readonly ?string $blocked = null,
    ) {}

    /** @param list<string> $notes */
    public static function fromResult(CheckResult $result, array $notes = [], ?string $blocked = null): self
    {
        return new self(
            $result->itemsInState(CheckItem::FIXABLE),
            array_values(array_filter($result->items, static fn (CheckItem $i): bool => $i->state !== CheckItem::FIXABLE)),
            array_values(array_merge($result->notes, $notes)),
            $blocked,
        );
    }

    /**
     * Plán spočtený při opravě musí být tentýž, jaký uživatel potvrdil v náhledu: stejné
     * nálezy, částky a vstupy (otisk). Jinak se nezapíše nic.
     */
    public function assertConfirmed(?string $fingerprint): void
    {
        if ($fingerprint !== null && $fingerprint !== $this->fingerprint()) {
            throw new MigrationDataCheckException('stale_preview', 'Data se od náhledu změnila. Otevřete náhled opravy znovu.', 409);
        }
        if (!$this->canFix()) {
            throw new MigrationDataCheckException('nothing_to_fix', $this->blocked ?? 'Kontrola nemá nic k opravě.', 409);
        }
    }

    public function canFix(): bool
    {
        return $this->blocked === null && $this->changes !== [];
    }

    public function fingerprint(): string
    {
        $keys = array_map(static fn (CheckItem $i): string => $i->entityType . ':' . ($i->entityId ?? 0) . ':' . number_format($i->amountCzk ?? 0.0, 2, '.', '')
            . ':' . json_encode($i->details, JSON_UNESCAPED_UNICODE), $this->changes);
        sort($keys);
        return substr(hash('sha256', implode('|', $keys)), 0, 16);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $amount = 0.0;
        foreach ($this->changes as $c) {
            $amount += abs($c->amountCzk ?? 0.0);
        }
        return [
            'can_fix' => $this->canFix(),
            'blocked' => $this->blocked,
            'fingerprint' => $this->fingerprint(),
            'count' => count($this->changes),
            'amount_czk' => round($amount, 2),
            'changes' => array_map(static fn (CheckItem $i): array => $i->toArray(), $this->changes),
            'skipped' => array_map(static fn (CheckItem $i): array => $i->toArray(), $this->skipped),
            'notes' => $this->notes,
        ];
    }
}
