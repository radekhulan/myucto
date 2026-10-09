<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Repository\DimensionRuleRepository;
use MyInvoice\Repository\PaymentCardRepository;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Bank\Card\PaymentCardVehicleResolver;
use PDO;

/**
 * Pravidla dimenzí podle účtu (Firma → Dimenze → Pravidla): správa, výchozí hodnoty
 * při zaúčtování a vynucení povinné dimenze.
 *
 * Do účtování vstupuje na dvou místech, obě volá jen tento objekt:
 *   • {@see applyDefaults()} — z {@see DimensionStamper} po dimenzích dokladu (zaúčtování
 *     i přerazítkování), takže výchozí hodnota pravidla nikdy nepřebije doklad;
 *   • {@see check()} — z PostingService::postDocument() těsně před zápisem. Pravidlo
 *     `error` zápis odmítne (PostingException `dimension_required` se jménem účtu
 *     a chybějící dimenze), `warning` vrátí varování volajícímu.
 *
 * Platí jen pro doklady a ruční zápisy ({@see RULE_SOURCES}); systémové zápisy
 * (uzávěrka, otevření, odpisy, mzdy, přeúčtování DPH…) pravidla neblokují — jejich
 * řádky ukáže kontrola {@see DimensionRuleAudit}. Firma bez zapnutých dimenzí nebo
 * bez pravidel účtuje beze změny.
 */
final class DimensionRuleService
{
    /** Zdroje zápisu, na které se pravidla vztahují (doklady a ruční zápis). */
    public const RULE_SOURCES = ['invoice', 'purchase_invoice', 'cash', 'bank', 'manual'];

    /** Třída, se kterou se maska pravidla porovnává u příjmu daňové evidence. */
    public const CASH_INCOME_CODE = '6';

    /** Třída, se kterou se maska pravidla porovnává u výdaje daňové evidence. */
    public const CASH_EXPENSE_CODE = '5';

    private const MAX_LISTED = 5;

    public function __construct(private readonly Connection $db) {}

    // ── správa ───────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    public function list(int $supplierId): array
    {
        return $this->repo()->listForSupplier($supplierId);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function create(int $supplierId, array $body, ?int $userId = null): array
    {
        $data = $this->validate($supplierId, $body, null);
        $data['created_by'] = $userId;
        $id = $this->repo()->create($supplierId, $data);
        return (array) $this->repo()->find($supplierId, $id);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function update(int $supplierId, int $id, array $body): array
    {
        $current = $this->repo()->find($supplierId, $id);
        if ($current === null) {
            throw new DimensionException('not_found', 'Pravidlo dimenze nenalezeno.', 404);
        }
        $data = $this->validate($supplierId, $body + $current, $current);
        $this->repo()->update($supplierId, $id, $data);
        return (array) $this->repo()->find($supplierId, $id);
    }

    public function delete(int $supplierId, int $id): void
    {
        if (!$this->repo()->delete($supplierId, $id)) {
            throw new DimensionException('not_found', 'Pravidlo dimenze nenalezeno.', 404);
        }
    }

    // ── účtování ─────────────────────────────────────────────────────────────

    /**
     * Doplní výchozí hodnoty pravidel řádkům, které hodnotu typu nemají.
     *
     * @param list<array<string,mixed>> $lines řádky s `account_id`
     * @return list<array<string,mixed>>
     */
    public function applyDefaults(int $supplierId, string $sourceType, ?int $sourceId, array $lines, string $entryDate): array
    {
        if (!in_array($sourceType, self::RULE_SOURCES, true)) {
            return $lines;
        }
        $rules = $this->usableRules($supplierId);
        if ($rules === [] || !array_filter($rules, static fn (array $r): bool
            => $r['default_value_id'] !== null || $r['default_from_card'])) {
            return $lines;
        }
        $lines = array_values($lines);
        $codes = $this->accountCodes($supplierId);
        $withCodes = array_map(static fn (array $l): array
            => $l + ['account_code' => $codes[(int) $l['account_id']]['code'] ?? ''], $lines);
        $card = fn (int $typeId): ?int => $this->cardVehicleValue($supplierId, $sourceType, $sourceId, $typeId);
        $result = DimensionRuleEngine::apply($rules, $withCodes, $entryDate, $card);
        foreach ($result['lines'] as $i => $line) {
            if (!empty($line['dimensions'])) {
                $lines[$i]['dimensions'] = $line['dimensions'];
            }
        }
        return $lines;
    }

    /**
     * Ověří povinné dimenze zapisovaných řádků.
     *
     * @param list<array<string,mixed>> $lines řádky s `account_id`, `side`, `amount`
     * @return list<array{account_code:string, account_name:string, type_id:int, type_name:string, message:string}> varování
     * @throws PostingException `dimension_required`, když řádku chybí dimenze pravidla s vynucením `error`
     */
    public function check(int $supplierId, string $sourceType, array $lines, string $entryDate): array
    {
        if (!in_array($sourceType, self::RULE_SOURCES, true)) {
            return [];
        }
        $violations = $this->violations($supplierId, $lines, $entryDate);
        $errors = array_values(array_filter($violations, static fn (array $v): bool => $v['enforcement'] === 'error'));
        if ($errors !== []) {
            $messages = array_map(static fn (array $v): string => $v['message'], array_slice($errors, 0, self::MAX_LISTED));
            if (count($errors) > self::MAX_LISTED) {
                $messages[] = 'a další (' . (count($errors) - self::MAX_LISTED) . ')';
            }
            throw new PostingException(
                'dimension_required',
                'Zápis nelze zaúčtovat bez povinných dimenzí: ' . implode('; ', $messages) . '.',
                422,
                ['violations' => array_map(static fn (array $v): array => array_diff_key($v, ['enforcement' => 1]), $errors)],
            );
        }
        return array_map(static fn (array $v): array => array_diff_key($v, ['enforcement' => 1]), $violations);
    }

    /**
     * Totéž ověření pro ruční změnu dimenzí řádků už zaúčtovaného zápisu — povinnou
     * dimenzi pravidla `error` nejde z řádku odebrat.
     *
     * @param list<array<string,mixed>> $lines řádky s `account_id`, `side`, `amount`, `dimensions`, `dimension_splits`
     * @throws DimensionException `dimension_required`
     */
    public function assertLines(int $supplierId, string $sourceType, array $lines, string $entryDate): void
    {
        try {
            $this->check($supplierId, $sourceType, $lines, $entryDate);
        } catch (PostingException $e) {
            throw new DimensionException($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    /**
     * Pravidlo pro pohyb peněžního deníku daňové evidence. Pohyb nemá účet, maska se
     * proto porovnává s třídou, do které by stejný pohyb patřil v podvojném účetnictví:
     * příjem {@see CASH_INCOME_CODE}, výdaj {@see CASH_EXPENSE_CODE}. Pravidlo `5, 6`
     * tak platí v obou režimech; maska na konkrétní účet (`518`) v daňové evidenci nic
     * nezachytí. Vozidlo podle platební karty se dohledá stejně jako při zaúčtování
     * (bankovní pohyb, přijatá faktura).
     *
     * @param list<array<string,mixed>> $rules {@see usableRules()} firmy
     * @return array{value_id:?int, enforcement:?string} výchozí hodnota, jinak nejpřísnější vynucení
     */
    public function cashMovementRule(array $rules, int $supplierId, string $sourceType, ?int $sourceId, string $code, string $date, int $typeId): array
    {
        $rules = array_values(array_filter($rules, static fn (array $r): bool => $r['dimension_type_id'] === $typeId));
        if ($rules === []) {
            return ['value_id' => null, 'enforcement' => null];
        }
        $postingSource = match ($sourceType) {
            'invoice_payment' => 'invoice',
            default => $sourceType,
        };
        $card = fn (int $type): ?int => $this->cardVehicleValue($supplierId, $postingSource, $sourceId, $type);
        $result = DimensionRuleEngine::apply($rules, [['account_code' => $code]], $date, $card);
        $valueId = $result['lines'][0]['dimensions'][$typeId] ?? null;
        if ($valueId !== null) {
            return ['value_id' => (int) $valueId, 'enforcement' => null];
        }
        return ['value_id' => null, 'enforcement' => $result['violations'][0]['enforcement'] ?? null];
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return list<array{account_code:string, account_name:string, type_id:int, type_name:string, enforcement:string, message:string}>
     */
    private function violations(int $supplierId, array $lines, string $entryDate): array
    {
        $rules = $this->usableRules($supplierId);
        if ($rules === []) {
            return [];
        }
        $codes = $this->accountCodes($supplierId);
        $withCodes = array_map(static fn (array $l): array
            => $l + ['account_code' => $codes[(int) ($l['account_id'] ?? 0)]['code'] ?? ''], array_values($lines));
        $result = DimensionRuleEngine::apply($rules, $withCodes, $entryDate, null, false);
        if ($result['violations'] === []) {
            return [];
        }
        $typeNames = [];
        foreach ((new DimensionRepository($this->db))->listTypes($supplierId) as $t) {
            $typeNames[$t['id']] = (string) $t['name'];
        }
        $out = [];
        foreach ($result['violations'] as $v) {
            $line = $withCodes[$v['line']];
            $name = $codes[(int) ($line['account_id'] ?? 0)]['name'] ?? '';
            $typeName = $typeNames[$v['type_id']] ?? ('#' . $v['type_id']);
            $side = ($line['side'] ?? '') === 'credit' ? 'D' : 'MD';
            $out[] = [
                'account_code' => $v['account_code'],
                'account_name' => $name,
                'type_id' => $v['type_id'],
                'type_name' => $typeName,
                'enforcement' => $v['enforcement'],
                'message' => sprintf(
                    'řádek %s%s (%s %s) nemá dimenzi „%s"',
                    $v['account_code'],
                    $name !== '' ? ' ' . $name : '',
                    $side,
                    number_format((float) ($line['amount'] ?? 0), 2, ',', ' '),
                    $typeName,
                ),
            ];
        }
        return $out;
    }

    /**
     * Aktivní pravidla firmy připravená pro {@see DimensionRuleEngine}. Neaktivní typ,
     * typ, který firma nevidí, a uzavřená výchozí hodnota se neuplatní.
     *
     * @return list<array{id:int, dimension_type_id:int, mask:DimensionAccountMask, enforcement:string,
     *                    default_value_id:?int, default_from_card:bool, valid_from:?string, valid_to:?string}>
     */
    public function usableRules(int $supplierId): array
    {
        $dimensions = new DimensionRepository($this->db);
        if (!$dimensions->enabled($supplierId)) {
            return [];
        }
        $rows = $this->repo()->listForSupplier($supplierId, true);
        if ($rows === []) {
            return [];
        }
        $visible = [];
        foreach ($dimensions->listTypes($supplierId, false) as $t) {
            $visible[$t['id']] = true;
        }
        $out = [];
        foreach ($rows as $r) {
            if (!isset($visible[$r['dimension_type_id']])) {
                continue;
            }
            try {
                $mask = DimensionAccountMask::parse($r['account_mask']);
            } catch (DimensionException) {
                continue;
            }
            $out[] = [
                'id' => $r['id'],
                'dimension_type_id' => $r['dimension_type_id'],
                'mask' => $mask,
                'enforcement' => $r['enforcement'],
                'default_value_id' => $r['default_value_active'] === true ? $r['default_value_id'] : null,
                'default_from_card' => $r['default_from_card'] && $r['type_kind'] === 'vehicle',
                'valid_from' => $r['valid_from'],
                'valid_to' => $r['valid_to'],
            ];
        }
        return $out;
    }

    /**
     * Vozidlo podle platební karty dokladu: koncovka karty z bankovního pohybu
     * (datum zaúčtování) nebo z přijatého dokladu (DUZP) → karta platná k datu →
     * držitel → jeho jediné aktivní vozidlo → aktivní hodnota typu navázaná na vůz.
     */
    private function cardVehicleValue(int $supplierId, string $sourceType, ?int $sourceId, int $typeId): ?int
    {
        if ($sourceId === null) {
            return null;
        }
        $sql = match ($sourceType) {
            'bank' => 'SELECT bt.card_last4, DATE(bt.posted_at) AS d
                         FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id
                        WHERE bt.id = ? AND bs.supplier_id = ?',
            'purchase_invoice' => 'SELECT card_last4, COALESCE(tax_date, issue_date) AS d
                                     FROM purchase_invoices WHERE id = ? AND supplier_id = ?',
            default => null,
        };
        if ($sql === null) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$sourceId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row['card_last4'] === null || $row['d'] === null) {
            return null;
        }
        $carId = (new PaymentCardVehicleResolver($this->db, new PaymentCardRepository($this->db)))
            ->vehicleForCard($supplierId, (string) $row['card_last4'], (string) $row['d']);
        if ($carId === null) {
            return null;
        }
        $value = $this->db->pdo()->prepare(
            'SELECT id FROM dimension_values
              WHERE type_id = ? AND supplier_id = ? AND car_id = ? AND is_active = 1
              ORDER BY id LIMIT 1'
        );
        $value->execute([$typeId, $supplierId, $carId]);
        $id = $value->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @return array<int,array{code:string,name:string}> */
    private function accountCodes(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, account_code, name FROM chart_of_accounts WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = ['code' => (string) $r['account_code'], 'name' => (string) $r['name']];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed>|null $current
     * @return array<string,mixed>
     */
    private function validate(int $supplierId, array $body, ?array $current): array
    {
        $dimensions = new DimensionRepository($this->db);
        if (!$dimensions->enabled($supplierId)) {
            throw new DimensionException('dimensions_disabled', 'Dimenze nejsou u firmy zapnuté (Nastavení firmy).', 409);
        }
        $typeId = (int) ($body['dimension_type_id'] ?? 0);
        $type = $typeId > 0 ? $dimensions->findType($supplierId, $typeId) : null;
        if ($type === null) {
            throw new DimensionException('validation_failed', 'Vyberte typ dimenze.');
        }
        if (!$type['is_active'] && ($current === null || $current['dimension_type_id'] !== $typeId)) {
            throw new DimensionException('validation_failed', 'Typ dimenze „' . $type['name'] . '" je neaktivní.');
        }
        $mask = DimensionAccountMask::parse((string) ($body['account_mask'] ?? ''));
        $enforcement = (string) ($body['enforcement'] ?? 'error');
        if (!in_array($enforcement, DimensionRuleRepository::ENFORCEMENTS, true)) {
            throw new DimensionException('validation_failed', 'Neznámé vynucení pravidla.');
        }
        $defaultValueId = (int) ($body['default_value_id'] ?? 0);
        if ($defaultValueId > 0) {
            $value = $dimensions->findValue($supplierId, $defaultValueId);
            if ($value === null || $value['type_id'] !== $typeId) {
                throw new DimensionException('invalid_dimension', 'Výchozí hodnota musí patřit k vybranému typu dimenze.', 400);
            }
            if (!$value['is_active'] && ($current === null || $current['default_value_id'] !== $defaultValueId)) {
                throw new DimensionException('dimension_closed', 'Hodnota dimenze „' . $value['name'] . '" je uzavřená.');
            }
        }
        $fromCard = (bool) ($body['default_from_card'] ?? false);
        if ($fromCard && $type['kind'] !== 'vehicle') {
            throw new DimensionException('validation_failed', 'Vozidlo podle platební karty jde doplňovat jen u typu Vozidlo.');
        }
        if ($enforcement === 'none' && $defaultValueId <= 0 && !$fromCard) {
            throw new DimensionException(
                'validation_failed',
                'Pravidlo bez vynucení musí doplňovat výchozí hodnotu nebo vozidlo podle karty.',
            );
        }
        $validFrom = self::date($body['valid_from'] ?? null, 'Platnost od');
        $validTo = self::date($body['valid_to'] ?? null, 'Platnost do');
        if ($validFrom !== null && $validTo !== null && $validTo < $validFrom) {
            throw new DimensionException('validation_failed', 'Platnost do nesmí být před platností od.');
        }
        $note = trim((string) ($body['note'] ?? ''));
        if (mb_strlen($note) > 500) {
            throw new DimensionException('validation_failed', 'Poznámka je delší než 500 znaků.');
        }
        return [
            'dimension_type_id' => $typeId,
            'account_mask' => $mask->normalized(),
            'enforcement' => $enforcement,
            'default_value_id' => $defaultValueId > 0 ? $defaultValueId : null,
            'default_from_card' => $fromCard,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'is_active' => (bool) ($body['is_active'] ?? true),
            'note' => $note === '' ? null : $note,
        ];
    }

    private static function date(mixed $raw, string $label): ?string
    {
        $raw = trim((string) ($raw ?? ''));
        if ($raw === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($d === false || $d->format('Y-m-d') !== $raw) {
            throw new DimensionException('validation_failed', $label . ' musí být datum (RRRR-MM-DD).');
        }
        return $raw;
    }

    private function repo(): DimensionRuleRepository
    {
        return new DimensionRuleRepository($this->db);
    }
}
