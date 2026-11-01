<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\OtherItemRepository;
use PDO;

final class OtherItemScheduleService
{
    private const MONTHS = ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12];

    public function __construct(
        private readonly Connection $db,
        private readonly OtherItemRepository $items,
        private readonly OtherItemService $service,
    ) {}

    public function create(int $supplierId, int $itemId, array $input, ?int $userId): array
    {
        $frequency = (string) ($input['frequency'] ?? '');
        if (!isset(self::MONTHS[$frequency])) {
            throw new OtherItemException('invalid_frequency', 'Vyberte měsíční, čtvrtletní nebo roční opakování.');
        }
        $autoPost = self::autoPost($input['auto_post'] ?? false);
        $endsOn = isset($input['ends_on']) && $input['ends_on'] !== '' ? (string) $input['ends_on'] : null;
        if ($endsOn !== null) self::date($endsOn);
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $item = $this->items->find($supplierId, $itemId, true)
                ?? throw new OtherItemException('not_found', 'Doklad nebyl nalezen.', 404);
            if (!in_array($item['status'], ['draft', 'confirmed', 'posted'], true)) {
                throw new OtherItemException('invalid_status', 'Opakování lze založit jen z aktivního dokladu.', 409);
            }
            if ($autoPost) self::assertConfirmedSource($item);
            $linked = $pdo->prepare('SELECT 1 FROM other_item_schedule_occurrences WHERE supplier_id = ? AND item_id = ? LIMIT 1');
            $linked->execute([$supplierId, $itemId]);
            if ($linked->fetchColumn()) {
                throw new OtherItemException('already_scheduled', 'Doklad už patří do rozvrhu opakování.', 409);
            }
            if ($endsOn !== null && $endsOn < $item['issued_on']) {
                throw new OtherItemException('invalid_end', 'Konec opakování předchází prvnímu dokladu.');
            }
            $dueDays = (new \DateTimeImmutable($item['issued_on']))->diff(new \DateTimeImmutable($item['due_on']))->days;
            if ($item['due_on'] < $item['issued_on'] || $dueDays > 3650) {
                throw new OtherItemException('invalid_due_offset', 'Splatnost musí být nejvýše deset let od vystavení.');
            }
            $template = [];
            foreach (['side', 'kind', 'kind_id', 'title', 'partner_id', 'partner_name', 'currency', 'amount',
                      'exchange_rate', 'variable_symbol', 'account_code', 'counter_account_code',
                      'posting_lines', 'note'] as $field) {
                if ($field === 'posting_lines' && $item[$field] === []) continue;
                $template[$field] = $item[$field];
            }
            $stmt = $pdo->prepare('INSERT INTO other_item_schedules
                (supplier_id, source_item_id, frequency, anchor_on, due_days, ends_on, template_json, created_by, auto_post)
                VALUES (?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$supplierId, $itemId, $frequency, $item['issued_on'], $dueDays, $endsOn,
                json_encode($template, JSON_THROW_ON_ERROR), $userId, (int) $autoPost]);
            $id = (int) $pdo->lastInsertId();
            $this->link($supplierId, $id, 0, $itemId);
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->get($supplierId, $id);
    }

    public function list(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT s.id, s.source_item_id, s.frequency, s.anchor_on, s.due_days,
            s.ends_on, s.next_index, s.status, s.auto_post, s.template_json, s.created_at, oi.status AS source_status
            FROM other_item_schedules s
            JOIN other_items oi ON oi.id = s.source_item_id AND oi.supplier_id = s.supplier_id
            WHERE s.supplier_id = ? ORDER BY s.id DESC');
        $stmt->execute([$supplierId]);
        return array_map(self::decode(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function get(int $supplierId, int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT s.id, s.source_item_id, s.frequency, s.anchor_on, s.due_days,
            s.ends_on, s.next_index, s.status, s.auto_post, s.template_json, s.created_at, oi.status AS source_status
            FROM other_item_schedules s
            JOIN other_items oi ON oi.id = s.source_item_id AND oi.supplier_id = s.supplier_id
            WHERE s.supplier_id = ? AND s.id = ?');
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) throw new OtherItemException('schedule_not_found', 'Rozvrh nebyl nalezen.', 404);
        $row = self::decode($row);
        $stmt = $this->db->pdo()->prepare('SELECT o.occurrence_index, o.item_id, oi.issued_on, oi.status
            FROM other_item_schedule_occurrences o
            JOIN other_items oi ON oi.id = o.item_id AND oi.supplier_id = o.supplier_id
            WHERE o.supplier_id = ? AND o.schedule_id = ? ORDER BY o.occurrence_index');
        $stmt->execute([$supplierId, $id]);
        $row['occurrences'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $row;
    }

    public function setStatus(int $supplierId, int $id, string $status, ?bool $autoPost = null, bool $allowAutoPost = true): array
    {
        if (!in_array($status, ['active', 'paused'], true)) {
            throw new OtherItemException('invalid_status', 'Neplatný stav rozvrhu.');
        }
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $sourceId = $this->sourceId($supplierId, $id);
            $source = $this->items->find($supplierId, $sourceId, true)
                ?? throw new OtherItemException('not_found', 'Zdrojový doklad nebyl nalezen.', 404);
            if ($status === 'active') self::assertActiveSource($source);
            if ($autoPost === true) self::assertConfirmedSource($source);
            $schedule = $this->get($supplierId, $id);
            if (!$allowAutoPost && ($autoPost === true || $status === 'active' && ($autoPost ?? $schedule['auto_post']))) {
                throw new OtherItemException('forbidden', 'Pro automatické účtování nemáš oprávnění.', 403);
            }
            $stmt = $pdo->prepare('UPDATE other_item_schedules SET status = ?, auto_post = COALESCE(?, auto_post)
                WHERE supplier_id = ? AND id = ?');
            $stmt->execute([$status, $autoPost === null ? null : (int) $autoPost, $supplierId, $id]);
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->get($supplierId, $id);
    }

    /**
     * Zruší rozvrh opakování. Už vytvořené položky zůstávají jako samostatné
     * doklady (vazby výskytů smaže cascade), zdrojový doklad jde pak smazat.
     */
    public function delete(int $supplierId, int $id): void
    {
        $this->sourceId($supplierId, $id);
        $this->db->pdo()->prepare('DELETE FROM other_item_schedules WHERE supplier_id = ? AND id = ?')
            ->execute([$supplierId, $id]);
    }

    public function generate(int $supplierId, int $id, string $through, ?int $userId, bool $allowAutoPost = true): array
    {
        self::date($through);
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        else $pdo->exec('SAVEPOINT other_item_schedule_generate');
        try {
            $sourceId = $this->sourceId($supplierId, $id);
            $source = $this->items->find($supplierId, $sourceId, true)
                ?? throw new OtherItemException('not_found', 'Zdrojový doklad nebyl nalezen.', 404);
            self::assertActiveSource($source);
            $stmt = $pdo->prepare('SELECT * FROM other_item_schedules WHERE supplier_id = ? AND id = ? FOR UPDATE');
            $stmt->execute([$supplierId, $id]);
            $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($schedule === false) throw new OtherItemException('schedule_not_found', 'Rozvrh nebyl nalezen.', 404);
            if ($schedule['auto_post'] && !$allowAutoPost) {
                throw new OtherItemException('forbidden', 'Pro automatické účtování nemáš oprávnění.', 403);
            }
            if ($schedule['status'] !== 'active') {
                throw new OtherItemException('schedule_paused', 'Pozastavený rozvrh nelze generovat.', 409);
            }
            $anchor = new \DateTimeImmutable($schedule['anchor_on']);
            $template = json_decode($schedule['template_json'], true, 512, JSON_THROW_ON_ERROR);
            $index = (int) $schedule['next_index'];
            $created = [];
            for ($count = 0; $count < 120; $count++, $index++) {
                $issued = self::occurrenceDate($anchor, $index * self::MONTHS[$schedule['frequency']]);
                if ($issued > $through || $schedule['ends_on'] !== null && $issued > $schedule['ends_on']) break;
                $due = (new \DateTimeImmutable($issued))->modify('+' . (int) $schedule['due_days'] . ' days')->format('Y-m-d');
                $item = $this->service->create($supplierId, $template + [
                    'issued_on' => $issued, 'accounting_on' => $issued, 'due_on' => $due,
                ], $userId, [
                    'kind' => (string) ($template['kind'] ?? 'other'), 'side' => (string) ($template['side'] ?? ''),
                    'kind_id' => isset($template['kind_id']) ? (int) $template['kind_id'] : null,
                ]);
                $this->link($supplierId, $id, $index, (int) $item['id']);
                $documents = $pdo->prepare("INSERT IGNORE INTO document_links
                    (document_id, supplier_id, entity_type, entity_id)
                    SELECT document_id, supplier_id, 'other_item', ? FROM document_links
                     WHERE supplier_id = ? AND entity_type = 'other_item' AND entity_id = ?");
                $documents->execute([(int) $item['id'], $supplierId, $schedule['source_item_id']]);
                // Dimenze zdrojového dokladu jdou s každým výskytem (F4), jako šablona faktury.
                (new DimensionAssignmentRepository($this->db))
                    ->copyDocument($supplierId, 'other_item', (int) $schedule['source_item_id'], (int) $item['id']);
                $created[] = (int) $item['id'];
            }
            $stmt = $pdo->prepare('UPDATE other_item_schedules SET next_index = ? WHERE supplier_id = ? AND id = ?');
            $stmt->execute([$index, $supplierId, $id]);
            $posted = [];
            if ((bool) $schedule['auto_post']) {
                self::assertConfirmedSource($source);
                $postingThrough = min($through, (new \DateTimeImmutable('today'))->format('Y-m-d'));
                $dueItems = $pdo->prepare("SELECT oi.id FROM other_item_schedule_occurrences o
                    JOIN other_items oi ON oi.id = o.item_id AND oi.supplier_id = o.supplier_id
                    WHERE o.supplier_id = ? AND o.schedule_id = ? AND o.occurrence_index > 0
                      AND oi.status = 'draft' AND oi.deleted_at IS NULL AND oi.issued_on <= ?
                      AND COALESCE(oi.accounting_on, oi.issued_on) <= ?
                    ORDER BY o.occurrence_index FOR UPDATE");
                $dueItems->execute([$supplierId, $id, $postingThrough, $postingThrough]);
                foreach ($dueItems->fetchAll(PDO::FETCH_COLUMN) as $itemId) {
                    $this->service->post($supplierId, (int) $itemId, $userId);
                    $posted[] = (int) $itemId;
                }
            }
            if ($ownTx) $pdo->commit();
            else $pdo->exec('RELEASE SAVEPOINT other_item_schedule_generate');
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            elseif (!$ownTx && $pdo->inTransaction()) $pdo->exec('ROLLBACK TO SAVEPOINT other_item_schedule_generate');
            throw $e;
        }
        return ['created_ids' => $created, 'posted_ids' => $posted, 'schedule' => $this->get($supplierId, $id)];
    }

    public function installments(int $supplierId, int $itemId): array
    {
        $this->service->get($supplierId, $itemId);
        $stmt = $this->db->pdo()->prepare('SELECT id, position, due_on, amount FROM other_item_installments
            WHERE supplier_id = ? AND other_item_id = ? ORDER BY position');
        $stmt->execute([$supplierId, $itemId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function setInstallments(int $supplierId, int $itemId, array $rows): array
    {
        if (count($rows) !== 0 && (count($rows) < 2 || count($rows) > 120)) {
            throw new OtherItemException('invalid_installments', 'Zadejte 2 až 120 splátek.');
        }
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $item = $this->items->find($supplierId, $itemId, true)
                ?? throw new OtherItemException('not_found', 'Doklad nebyl nalezen.', 404);
            if (!in_array($item['status'], ['draft', 'confirmed', 'posted'], true)
                || (float) $item['paid_amount'] > 0) {
                throw new OtherItemException('invalid_status', 'Splátky lze nastavit jen na aktivním neuhrazeném dokladu.', 409);
            }
            $total = 0;
            $previous = '';
            foreach ($rows as $row) {
                if (!is_array($row)) throw new OtherItemException('invalid_installments', 'Neplatná splátka.');
                $date = (string) ($row['due_on'] ?? '');
                self::date($date);
                if ($date <= $previous || $date < $item['issued_on']) {
                    throw new OtherItemException('invalid_installment_date', 'Termíny splátek musí být vzestupné a po vystavení.');
                }
                $amount = filter_var($row['amount'] ?? null, FILTER_VALIDATE_FLOAT);
                if ($amount === false || !is_finite((float) $amount) || $amount <= 0
                    || round((float) $amount, 2) !== (float) $amount) {
                    throw new OtherItemException('invalid_installment_amount', 'Splátka musí mít kladnou částku na haléře.');
                }
                $total += (int) round((float) $amount * 100);
                $previous = $date;
            }
            if ($rows !== [] && $total !== (int) round((float) $item['amount'] * 100)) {
                throw new OtherItemException('installment_total', 'Součet splátek musí odpovídat částce dokladu.');
            }
            $pdo->prepare('DELETE FROM other_item_installments WHERE supplier_id = ? AND other_item_id = ?')
                ->execute([$supplierId, $itemId]);
            $stmt = $pdo->prepare('INSERT INTO other_item_installments
                (supplier_id, other_item_id, position, due_on, amount) VALUES (?,?,?,?,?)');
            foreach ($rows as $position => $row) {
                $stmt->execute([$supplierId, $itemId, $position + 1, $row['due_on'], $row['amount']]);
            }
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->installments($supplierId, $itemId);
    }

    private function link(int $supplierId, int $scheduleId, int $index, int $itemId): void
    {
        $stmt = $this->db->pdo()->prepare('INSERT INTO other_item_schedule_occurrences
            (supplier_id, schedule_id, occurrence_index, item_id) VALUES (?,?,?,?)');
        $stmt->execute([$supplierId, $scheduleId, $index, $itemId]);
    }

    private function sourceId(int $supplierId, int $id): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT source_item_id FROM other_item_schedules WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $id]);
        $sourceId = $stmt->fetchColumn();
        if ($sourceId === false) throw new OtherItemException('schedule_not_found', 'Rozvrh nebyl nalezen.', 404);
        return (int) $sourceId;
    }

    private static function assertActiveSource(array $source): void
    {
        if (!in_array($source['status'], ['draft', 'confirmed', 'posted'], true)) {
            throw new OtherItemException('schedule_source_inactive', 'Stornovaný zdrojový doklad nelze opakovat.', 409);
        }
    }

    private static function assertConfirmedSource(array $source): void
    {
        if (!in_array($source['status'], ['confirmed', 'posted'], true)) {
            throw new OtherItemException('schedule_source_unconfirmed', 'Před automatickým účtováním potvrďte zdrojový doklad.', 409);
        }
    }

    private static function autoPost(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new OtherItemException('invalid_auto_post', 'Volba automatického účtování musí být boolean.');
        }
        return $value;
    }

    private static function occurrenceDate(\DateTimeImmutable $anchor, int $months): string
    {
        $first = $anchor->modify('first day of this month')->modify('+' . $months . ' months');
        $day = min((int) $anchor->format('d'), (int) $first->format('t'));
        return $first->setDate((int) $first->format('Y'), (int) $first->format('m'), $day)->format('Y-m-d');
    }

    private static function date(string $value): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new OtherItemException('invalid_date', 'Zadejte platné datum.');
        }
    }

    private static function decode(array $row): array
    {
        $row['auto_post'] = (bool) $row['auto_post'];
        if (!in_array($row['source_status'], ['draft', 'confirmed', 'posted'], true)) $row['status'] = 'paused';
        $row['template'] = json_decode($row['template_json'], true, 512, JSON_THROW_ON_ERROR);
        unset($row['template_json'], $row['source_status']);
        return $row;
    }
}
