<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\PublicHolidayProvider;
use PDO;

/**
 * Číselník českých svátků (z. č. 245/2000 Sb.) — globální, admin editovatelný.
 *
 * Migrace 1783. Čtecí cestu má jedinou: {@see \MyInvoice\Service\Report\CzechWorkingDays},
 * odkud svátky bere jak posun lhůt podle § 33 odst. 4 daňového řádu, tak fond
 * pracovní doby ve mzdách.
 *
 * Čte se přes cache v rámci běhu (holidayRules se volá při každém dotazu na
 * pracovní den, tedy klidně stokrát za request). Změna číselníku se proto
 * projeví až v následujícím requestu — stejně jako u `tax_constants`.
 */
final class PublicHolidayRepository implements PublicHolidayProvider
{
    /** @var list<array<string,mixed>>|null */
    private ?array $cache = null;

    public function __construct(private readonly Connection $db) {}

    /** @inheritDoc */
    public function holidayRules(): array
    {
        if ($this->cache !== null) {
            /** @var list<array{code:string,name:string,rule_type:'fixed'|'easter',month_day:?string,easter_offset:?int,valid_from:string,valid_to:?string}> */
            return $this->cache;
        }

        $rows = $this->db->pdo()->query(
            'SELECT code, name, rule_type, month_day, easter_offset, valid_from, valid_to
               FROM public_holidays
              ORDER BY valid_from, code'
        )->fetchAll(PDO::FETCH_ASSOC);

        /** @var list<array{code:string,name:string,rule_type:'fixed'|'easter',month_day:?string,easter_offset:?int,valid_from:string,valid_to:?string}> $rules */
        $rules = array_map(static fn (array $r): array => [
            'code'          => (string) $r['code'],
            'name'          => (string) $r['name'],
            'rule_type'     => (string) $r['rule_type'] === 'easter' ? 'easter' : 'fixed',
            'month_day'     => $r['month_day'] !== null ? (string) $r['month_day'] : null,
            'easter_offset' => $r['easter_offset'] !== null ? (int) $r['easter_offset'] : null,
            'valid_from'    => (string) $r['valid_from'],
            'valid_to'      => $r['valid_to'] !== null ? (string) $r['valid_to'] : null,
        ], $rows);

        return $this->cache = $rules;
    }

    /**
     * Číselník pro administraci — stejné řádky plus `id`, `note` a časy, ať jde
     * řádek adresovat a je vidět, odkud se vzal.
     *
     * @return list<array<string,mixed>>
     */
    public function listAll(): array
    {
        $rows = $this->db->pdo()->query(
            'SELECT id, code, name, rule_type, month_day, easter_offset, valid_from, valid_to, note, updated_at
               FROM public_holidays
              ORDER BY valid_from DESC, COALESCE(month_day, \'\'), code'
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $r): array => [
            'id'            => (int) $r['id'],
            'code'          => (string) $r['code'],
            'name'          => (string) $r['name'],
            'rule_type'     => (string) $r['rule_type'],
            'month_day'     => $r['month_day'] !== null ? (string) $r['month_day'] : null,
            'easter_offset' => $r['easter_offset'] !== null ? (int) $r['easter_offset'] : null,
            'valid_from'    => (string) $r['valid_from'],
            'valid_to'      => $r['valid_to'] !== null ? (string) $r['valid_to'] : null,
            'note'          => $r['note'] !== null ? (string) $r['note'] : null,
            'updated_at'    => (string) $r['updated_at'],
        ], $rows);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $this->cache = null;
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO public_holidays (code, name, rule_type, month_day, easter_offset, valid_from, valid_to, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute($this->bind($data));

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): bool
    {
        $this->cache = null;
        $stmt = $this->db->pdo()->prepare(
            'UPDATE public_holidays
                SET code = ?, name = ?, rule_type = ?, month_day = ?, easter_offset = ?,
                    valid_from = ?, valid_to = ?, note = ?
              WHERE id = ?'
        );
        $params = $this->bind($data);
        $params[] = $id;
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        $this->cache = null;
        $stmt = $this->db->pdo()->prepare('DELETE FROM public_holidays WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM public_holidays WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @param array<string,mixed> $data
     * @return list<mixed>
     */
    private function bind(array $data): array
    {
        $ruleType = (string) ($data['rule_type'] ?? 'fixed') === 'easter' ? 'easter' : 'fixed';

        return [
            (string) $data['code'],
            (string) $data['name'],
            $ruleType,
            $ruleType === 'fixed' ? (string) $data['month_day'] : null,
            $ruleType === 'easter' ? (int) $data['easter_offset'] : null,
            (string) $data['valid_from'],
            ($data['valid_to'] ?? null) !== null && (string) $data['valid_to'] !== '' ? (string) $data['valid_to'] : null,
            ($data['note'] ?? null) !== null && (string) $data['note'] !== '' ? (string) $data['note'] : null,
        ];
    }
}
