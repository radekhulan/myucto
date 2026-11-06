<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

/**
 * Evidence tržby na základě pověření (§ 10 odst. 1 ZoET) — jediné místo pravdy
 * pro pravidla položek `eic_poverujiciho` a `povereni_vice_popl`. Používá ho
 * nastavení zařízení, politika evidence, vznik záznamu i sestavení zprávy.
 *
 * Pravidla podle dokumentu Finanční správy „Popis položek datové zprávy
 * s příklady jejich vyplnění“ v1.0 (5. 10. 2026), kap. 1 bod 6 a 7 a kap. 3:
 *  - situace 1 až 3 (poplatník sám, přímé a nepřímé zastoupení): žádná z položek
 *    se neuvádí, EIČ pověřujícího nesmí být vyplněné ani stejným EIČ (`none`);
 *  - situace 4, pověření jedním poplatníkem: povinné EIČ pověřujícího, příznak
 *    více poplatníků se neuvádí (`single`);
 *  - situace 5, pověření více poplatníky: `povereni_vice_popl="true"`, EIČ
 *    pověřujícího nepovinně (doporučené, když je pověřená třetí osoba, které
 *    tržba neplyne) (`multiple`).
 * EIČ pověřujícího se nikdy nesmí shodovat s EIČ poplatníka (varování 7 FS).
 */
final readonly class EetDelegation
{
    public const NONE = 'none';
    public const SINGLE = 'single';
    public const MULTIPLE = 'multiple';
    public const MODES = [self::NONE, self::SINGLE, self::MULTIPLE];

    public function __construct(
        public string $mode,
        public ?string $delegatingEic = null,
    ) {}

    public static function none(): self
    {
        return new self(self::NONE);
    }

    /** Ze snapshotu záznamu nebo z položek zprávy: EIČ pověřujícího a příznak více poplatníků. */
    public static function fromItems(?string $delegatingEic, bool $multipleDelegators): self
    {
        $eic = $delegatingEic !== null && trim($delegatingEic) !== '' ? EetIdentifiers::normalizeEic($delegatingEic) : null;
        if ($multipleDelegators) {
            return new self(self::MULTIPLE, $eic);
        }
        return new self($eic !== null ? self::SINGLE : self::NONE, $eic);
    }

    /** @param array<string,mixed> $row řádek eet_records (delegating_eic, multiple_delegators) */
    public static function fromRecord(array $row): self
    {
        return self::fromItems(
            isset($row['delegating_eic']) ? (string) $row['delegating_eic'] : null,
            (int) ($row['multiple_delegators'] ?? 0) === 1,
        );
    }

    /** @param array<string,mixed> $row řádek eet_devices (delegation, delegating_eic) */
    public static function fromDevice(array $row): self
    {
        $eic = isset($row['delegating_eic']) && $row['delegating_eic'] !== '' ? (string) $row['delegating_eic'] : null;
        return new self((string) ($row['delegation'] ?? self::NONE), $eic);
    }

    public function isDelegated(): bool
    {
        return $this->mode !== self::NONE;
    }

    /** Hodnota `povereni_vice_popl`: jen u pověření více poplatníky, jinak se položka neuvádí. */
    public function multipleDelegators(): bool
    {
        return $this->mode === self::MULTIPLE;
    }

    /**
     * Porušení pravidel vyplnění vůči EIČ poplatníka (`eic_popl`), nebo null.
     * Prázdné EIČ poplatníka (firma ho ještě nemá v nastavení) shodu neověřuje.
     *
     * @return array{field:string, message:string}|null
     */
    public function violation(?string $taxpayerEic): ?array
    {
        if (!in_array($this->mode, self::MODES, true)) {
            return ['field' => 'delegation', 'message' => 'Neplatný druh pověření (none, single nebo multiple).'];
        }
        if ($this->mode === self::NONE) {
            return $this->delegatingEic === null ? null : [
                'field' => 'delegating_eic',
                'message' => 'Bez pověření se EIČ pověřujícího poplatníka neuvádí (ani v zastoupení).',
            ];
        }
        if ($this->delegatingEic === null) {
            return $this->mode === self::SINGLE ? [
                'field' => 'delegating_eic',
                'message' => 'Při pověření jedním poplatníkem je EIČ pověřujícího poplatníka povinné.',
            ] : null;
        }
        if (!EetIdentifiers::isEic($this->delegatingEic)) {
            return ['field' => 'delegating_eic', 'message' => 'EIČ pověřujícího poplatníka musí mít tvar CZ a 8 až 10 číslic.'];
        }
        $taxpayer = $taxpayerEic !== null ? EetIdentifiers::normalizeEic($taxpayerEic) : '';
        if ($taxpayer !== '' && $taxpayer === $this->delegatingEic) {
            return ['field' => 'delegating_eic', 'message' => 'EIČ pověřujícího poplatníka nesmí být shodné s EIČ poplatníka.'];
        }
        return null;
    }

    /** @return array{delegation:string, delegating_eic:?string, multiple_delegators:bool} */
    public function toArray(): array
    {
        return [
            'delegation' => $this->mode,
            'delegating_eic' => $this->delegatingEic,
            'multiple_delegators' => $this->multipleDelegators(),
        ];
    }
}
