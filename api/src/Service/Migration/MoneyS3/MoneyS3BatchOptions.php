<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

/**
 * Volby dávkového převodu více záloh Money S3 ({@see MoneyS3BatchImporter}).
 */
final class MoneyS3BatchOptions
{
    /** Firma se zálohou už v MyÚčtu je: přeskočit. */
    public const EXISTING_SKIP = 'skip';
    /** Firma se zálohou už v MyÚčtu je: převést znovu (doplní jen to, co chybí). */
    public const EXISTING_UPDATE = 'update';
    /**
     * Firma se zálohou už v MyÚčtu je a účtuje se v něm: opravný převod vazeb
     * ({@see ImportOptions::$relinkOnly}) - nic nového nezaloží, jen u převzatých záznamů
     * znovu dopočítá úhrady, zápočty a zálohy. Firmu, která v MyÚčtu není, přeskočí.
     */
    public const EXISTING_RELINK = 'relink';

    /** Rok „od" automaticky: první rok, od kterého v Money navazují počáteční a konečné stavy. */
    public const FROM_YEAR_AUTO = 'auto';

    /**
     * Rok „do" automaticky: u každé firmy poslední rok, který v MyÚčtu nemá vlastní zápisy
     * ({@see ImportOptions::$toYear} = null). Dávka uložená před zavedením volby ji nemá a
     * chová se stejně.
     */
    public const TO_YEAR_AUTO = 'auto';

    /**
     * @param string|int|null $fromYear `auto`, rok, nebo null = všechny roky zálohy
     * @param list<string> $relatedPartyIcos IČO spřízněných osob (typicky firmy dávky / skupiny)
     * @param string|int $toYear `auto` nebo poslední převáděný rok všech firem dávky
     */
    public function __construct(
        public readonly string $mode = ImportOptions::MODE_DRY_RUN,
        public readonly string|int|null $fromYear = self::FROM_YEAR_AUTO,
        public readonly string $existing = self::EXISTING_SKIP,
        public readonly bool $closeHistory = true,
        public readonly string $disposalYearTax = ImportOptions::DISPOSAL_YEAR_TAX_HALF,
        public readonly ?int $groupId = null,
        public readonly ?string $groupName = null,
        public readonly bool $relatedParties = false,
        public readonly array $relatedPartyIcos = [],
        public readonly bool $useRegistry = true,
        public readonly bool $takeOverFilings = true,
        public readonly string|int $toYear = self::TO_YEAR_AUTO,
    ) {
        if (!in_array($mode, [ImportOptions::MODE_DRY_RUN, ImportOptions::MODE_IMPORT], true)) {
            throw new MoneyS3Exception('invalid_mode', 'Neznámý režim převodu.');
        }
        if (!in_array($existing, [self::EXISTING_SKIP, self::EXISTING_UPDATE, self::EXISTING_RELINK], true)) {
            throw new MoneyS3Exception('invalid_existing', 'Volba pro existující firmu čeká „skip" (přeskočit), „update" (převést znovu) nebo „relink" (opravný převod vazeb).');
        }
        if (is_string($fromYear) && $fromYear !== self::FROM_YEAR_AUTO) {
            throw new MoneyS3Exception('invalid_from_year', 'První převáděný rok čeká rok nebo „auto".');
        }
        if (is_int($fromYear) && ($fromYear < 1990 || $fromYear > 2100)) {
            throw new MoneyS3Exception('invalid_from_year', 'První převáděný rok musí být mezi lety 1990 a 2100.');
        }
        if (is_string($toYear) && $toYear !== self::TO_YEAR_AUTO) {
            throw new MoneyS3Exception('invalid_to_year', 'Poslední převáděný rok čeká rok nebo „auto".');
        }
        if (is_int($toYear) && ($toYear < 1990 || $toYear > 2100)) {
            throw new MoneyS3Exception('invalid_to_year', 'Poslední převáděný rok musí být mezi lety 1990 a 2100.');
        }
        if (is_int($toYear) && is_int($fromYear) && $toYear < $fromYear) {
            throw new MoneyS3Exception('invalid_to_year', "Převést do roku {$toYear} nejde, když převod začíná rokem {$fromYear}.");
        }
        if (!in_array($disposalYearTax, [ImportOptions::DISPOSAL_YEAR_TAX_HALF, ImportOptions::DISPOSAL_YEAR_TAX_NONE], true)) {
            throw new MoneyS3Exception('invalid_disposal_year_tax', 'Daňový odpis v roce vyřazení čeká „half" (poloviční) nebo „none" (bez odpisu).');
        }
    }

    public function isDryRun(): bool
    {
        return $this->mode === ImportOptions::MODE_DRY_RUN;
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $fromYear = $data['from_year'] ?? self::FROM_YEAR_AUTO;
        if (is_numeric($fromYear)) {
            $fromYear = (int) $fromYear > 0 ? (int) $fromYear : null;
        } elseif ($fromYear === '' || $fromYear === 'all') {
            $fromYear = null;
        }
        $toYear = $data['to_year'] ?? self::TO_YEAR_AUTO;
        $toYear = is_numeric($toYear) && (int) $toYear > 0 ? (int) $toYear : self::TO_YEAR_AUTO;
        $groupId = (int) ($data['group_id'] ?? 0);
        $groupName = trim((string) ($data['group_name'] ?? ''));
        return new self(
            (string) ($data['mode'] ?? ImportOptions::MODE_DRY_RUN),
            $fromYear,
            (string) ($data['existing'] ?? self::EXISTING_SKIP),
            filter_var($data['close_history'] ?? true, FILTER_VALIDATE_BOOL),
            (string) ($data['disposal_year_tax'] ?? ImportOptions::DISPOSAL_YEAR_TAX_HALF),
            $groupId > 0 ? $groupId : null,
            $groupName !== '' ? $groupName : null,
            filter_var($data['related_parties'] ?? false, FILTER_VALIDATE_BOOL),
            array_values(array_map('strval', (array) ($data['related_party_icos'] ?? []))),
            filter_var($data['use_registry'] ?? true, FILTER_VALIDATE_BOOL),
            filter_var($data['take_over_filings'] ?? true, FILTER_VALIDATE_BOOL),
            $toYear,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'from_year' => $this->fromYear,
            'existing' => $this->existing,
            'close_history' => $this->closeHistory,
            'disposal_year_tax' => $this->disposalYearTax,
            'group_id' => $this->groupId,
            'group_name' => $this->groupName,
            'related_parties' => $this->relatedParties,
            'related_party_icos' => $this->relatedPartyIcos,
            'use_registry' => $this->useRegistry,
            'take_over_filings' => $this->takeOverFilings,
            'to_year' => $this->toYear,
        ];
    }

    /** Totéž s rozhodnutou skupinou (založenou na začátku dávky) a spřízněnými osobami. */
    public function withGroup(?int $groupId, array $relatedPartyIcos): self
    {
        return new self($this->mode, $this->fromYear, $this->existing, $this->closeHistory, $this->disposalYearTax,
            $groupId, null, $this->relatedParties, $relatedPartyIcos, $this->useRegistry, $this->takeOverFilings, $this->toYear);
    }
}
