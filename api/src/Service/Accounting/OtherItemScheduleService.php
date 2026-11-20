<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\OtherItemRepository;
use MyInvoice\Service\Invoice\DescriptionPlaceholders;
use MyInvoice\Service\Invoice\MonthSynchronizer;
use PDO;

final class OtherItemScheduleService
{
    private const MONTHS = ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12];
    /** Nastavení názvu výskytu v šabloně rozvrhu; na doklad se nepřenáší. */
    private const TITLE_SETTINGS = ['title_template' => true, 'title_sync_period' => true];
    /** Kolik nejbližších výskytů se při uložení šablony názvu zkusí dosadit (měsíční rozvrh = celý rok). */
    private const TITLE_CHECK_COUNT = 12;
    /** Sloupce rozvrhu pro seznam i detail; jméno zakladatele z users (smazaný uživatel = null). */
    private const COLUMNS = 's.id, s.source_item_id, s.frequency, s.anchor_on, s.due_days,
            s.ends_on, s.next_index, s.status, s.auto_post, s.template_json, s.created_at, oi.status AS source_status,
            oi.document_no AS source_document_no, u.name AS created_by_name';

    public function __construct(
        private readonly Connection $db,
        private readonly OtherItemRepository $items,
        private readonly OtherItemService $service,
        private readonly OtherItemPostingProfileService $postingProfiles,
    ) {}

    public function create(int $supplierId, int $itemId, array $input, ?int $userId): array
    {
        $frequency = $input['frequency'] ?? '';
        if (!is_string($frequency) || !isset(self::MONTHS[$frequency])) {
            throw new OtherItemException('invalid_frequency', 'Vyberte měsíční, čtvrtletní nebo roční opakování.', 422, 'frequency');
        }
        $autoPost = self::autoPost($input['auto_post'] ?? false);
        $titleSettings = self::titleSettings($input);
        $endsOn = $input['ends_on'] ?? null;
        if ($endsOn !== null && !is_string($endsOn)) {
            throw new OtherItemException('invalid_date', 'Zadejte platné datum.', 422, 'ends_on');
        }
        $endsOn = $endsOn === '' ? null : $endsOn;
        if ($endsOn !== null) self::endsOn($endsOn);
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $item = $this->items->find($supplierId, $itemId, true)
                ?? throw new OtherItemException('not_found', 'Doklad nebyl nalezen.', 404);
            $this->assertSchedulable($supplierId, $item);
            if ($autoPost) self::assertConfirmedSource($item);
            if ($endsOn !== null && $endsOn < $item['issued_on']) {
                throw new OtherItemException('invalid_end', 'Konec opakování předchází prvnímu dokladu.', 422, 'ends_on');
            }
            $dueDays = (new \DateTimeImmutable($item['issued_on']))->diff(new \DateTimeImmutable($item['due_on']))->days;
            if ($item['due_on'] < $item['issued_on'] || $dueDays > 3650) {
                throw new OtherItemException('invalid_due_offset', 'Splatnost musí být nejvýše deset let od vystavení.');
            }
            $template = [];
            foreach (['side', 'kind', 'kind_id', 'title', 'partner_id', 'partner_name', 'currency', 'amount',
                      'exchange_rate', 'variable_symbol', 'counterparty_account', 'counterparty_bank_code',
                      'account_code', 'counter_account_code',
                      'posting_lines', 'note'] as $field) {
                if ($field === 'posting_lines' && $item[$field] === []) continue;
                $template[$field] = $item[$field];
            }
            // Vlastní kontace úhrad jde s šablonou na každý výskyt (vznik nesou pole výše).
            $overrides = [];
            foreach ($this->postingProfiles->overridesForItem($supplierId, $itemId) as $event => $rule) {
                $overrides[$event] = [
                    'debit_account_code' => $rule['debit_account_code'],
                    'credit_account_code' => $rule['credit_account_code'],
                    'description_template' => $rule['description_template'],
                ];
            }
            if ($overrides !== []) $template['posting_overrides'] = $overrides;
            $nextIndex = $this->firstOccurrenceIndex($supplierId, $item, $frequency);
            $template = self::withTitleSettings($template, $titleSettings);
            self::assertTitles($template, new \DateTimeImmutable($item['issued_on']), $nextIndex, self::MONTHS[$frequency], $endsOn);
            $stmt = $pdo->prepare('INSERT INTO other_item_schedules
                (supplier_id, source_item_id, frequency, anchor_on, due_days, ends_on, template_json, created_by, auto_post, next_index)
                VALUES (?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$supplierId, $itemId, $frequency, $item['issued_on'], $dueDays, $endsOn,
                json_encode($template, JSON_THROW_ON_ERROR), $userId, (int) $autoPost, $nextIndex]);
            $id = (int) $pdo->lastInsertId();
            $this->link($supplierId, $id, 0, $itemId);
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->get($supplierId, $id);
    }

    public function list(int $supplierId, ?int $sourceItemId = null): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . '
            FROM other_item_schedules s
            JOIN other_items oi ON oi.id = s.source_item_id AND oi.supplier_id = s.supplier_id
            LEFT JOIN users u ON u.id = s.created_by
            WHERE s.supplier_id = ?' . ($sourceItemId !== null ? ' AND s.source_item_id = ?' : '') . ' ORDER BY s.id DESC');
        $stmt->execute($sourceItemId !== null ? [$supplierId, $sourceItemId] : [$supplierId]);
        return array_map(self::decode(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function get(int $supplierId, int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . '
            FROM other_item_schedules s
            JOIN other_items oi ON oi.id = s.source_item_id AND oi.supplier_id = s.supplier_id
            LEFT JOIN users u ON u.id = s.created_by
            WHERE s.supplier_id = ? AND s.id = ?');
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) throw new OtherItemException('schedule_not_found', 'Rozvrh nebyl nalezen.', 404);
        $row = self::decode($row);
        $stmt = $this->db->pdo()->prepare('SELECT o.occurrence_index, o.item_id, oi.issued_on, oi.status, oi.document_no
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
                throw new OtherItemException('forbidden', 'Pro automatické účtování nemáte oprávnění.', 403);
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
     * Změní šablonu názvu budoucích výskytů. Už vytvořené položky se nepřejmenují.
     * Vynechané pole zachová uloženou hodnotu (šablona se ruší hodnotou null).
     * Stornovaný zdroj ani skončený rozvrh další výskyt nevytvoří, změna je pak chyba (409).
     * `$session` = požadavek člověka v aplikaci; API token u rozvrhu s automatikou název nemění.
     */
    public function setTitle(int $supplierId, int $id, array $input, bool $session): array
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT source_item_id, anchor_on, frequency, next_index, ends_on, auto_post, template_json
                FROM other_item_schedules WHERE supplier_id = ? AND id = ? FOR UPDATE');
            $stmt->execute([$supplierId, $id]);
            $schedule = $stmt->fetch(PDO::FETCH_ASSOC)
                ?: throw new OtherItemException('schedule_not_found', 'Rozvrh nebyl nalezen.', 404);
            // Automatika by doklady s názvem od API tokenu zaúčtovala, aniž je kdo viděl
            // (stejné pravidlo jako úprava konceptu z automatického opakování).
            if ((bool) $schedule['auto_post'] && !$session) {
                throw new OtherItemException('auto_post_session_only',
                    'Název opakování s automatickým účtováním lze změnit jen ve webovém rozhraní.', 403);
            }
            $source = $schedule['source_item_id'] === null ? null : $this->items->find($supplierId, (int) $schedule['source_item_id']);
            self::assertActiveSource($source ?? ['status' => 'cancelled']);
            self::assertNotFinished($schedule);
            $stored = json_decode($schedule['template_json'], true, 512, JSON_THROW_ON_ERROR);
            $settings = self::titleSettings($input + [
                'title_template' => $stored['title_template'] ?? null,
                'title_sync_period' => ($stored['title_sync_period'] ?? false) === true,
            ]);
            $template = self::withTitleSettings($stored, $settings);
            self::assertTitles($template, new \DateTimeImmutable($schedule['anchor_on']), (int) $schedule['next_index'],
                self::MONTHS[$schedule['frequency']], $schedule['ends_on']);
            $pdo->prepare('UPDATE other_item_schedules SET template_json = ? WHERE supplier_id = ? AND id = ?')
                ->execute([json_encode($template, JSON_THROW_ON_ERROR), $supplierId, $id]);
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->get($supplierId, $id);
    }

    /**
     * Náhled názvu nejbližšího výskytu pro rozepsané nastavení; nic neukládá.
     * Má-li položka rozvrh, bere jeho šablonu a příští termín, jinak první
     * výskyt nového rozvrhu se zadanou četností. Chyby jsou stejné jako při uložení.
     *
     * @return array{title: string, issued_on: string}
     */
    public function previewTitle(int $supplierId, int $itemId, array $input): array
    {
        $item = $this->items->find($supplierId, $itemId)
            ?? throw new OtherItemException('not_found', 'Doklad nebyl nalezen.', 404);
        $stmt = $this->db->pdo()->prepare('SELECT anchor_on, frequency, next_index, ends_on, template_json FROM other_item_schedules
            WHERE supplier_id = ? AND source_item_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$supplierId, $itemId]);
        $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
        // Stejné podmínky jako uložení: náhled nenabídne název, který by uložení odmítlo.
        if ($schedule !== false) {
            self::assertActiveSource($item);
            self::assertNotFinished($schedule);
            $template = json_decode($schedule['template_json'], true, 512, JSON_THROW_ON_ERROR);
            // Vynechané pole = uložená hodnota, stejně jako úprava (setTitle).
            $input += ['title_template' => $template['title_template'] ?? null,
                'title_sync_period' => ($template['title_sync_period'] ?? false) === true];
            $anchor = new \DateTimeImmutable($schedule['anchor_on']);
            $index = (int) $schedule['next_index'];
            $months = self::MONTHS[$schedule['frequency']];
            $endsOn = $schedule['ends_on'];
        } else {
            $frequency = (string) ($input['frequency'] ?? '');
            if (!isset(self::MONTHS[$frequency])) {
                throw new OtherItemException('invalid_frequency', 'Vyberte měsíční, čtvrtletní nebo roční opakování.', 422, 'frequency');
            }
            $this->assertSchedulable($supplierId, $item);
            $template = ['title' => $item['title']];
            $anchor = new \DateTimeImmutable($item['issued_on']);
            $index = $this->firstOccurrenceIndex($supplierId, $item, $frequency);
            $months = self::MONTHS[$frequency];
            // Konec rozepsaného rozvrhu: délka se kontroluje do něj stejně jako při založení.
            $endsOn = isset($input['ends_on']) && $input['ends_on'] !== '' ? (string) $input['ends_on'] : null;
            if ($endsOn !== null) self::endsOn($endsOn);
            if ($endsOn !== null && $endsOn < $item['issued_on']) {
                throw new OtherItemException('invalid_end', 'Konec opakování předchází prvnímu dokladu.', 422, 'ends_on');
            }
        }
        $template = self::withTitleSettings($template, self::titleSettings($input));
        self::assertTitles($template, $anchor, $index, $months, $endsOn);
        $issuedOn = self::occurrenceDate($anchor, $index * $months);
        return ['title' => self::occurrenceTitle($template, $issuedOn), 'issued_on' => $issuedOn];
    }

    /**
     * Zruší rozvrh opakování. Už vytvořené položky zůstávají jako samostatné doklady a svůj
     * původ si drží (vazba výskytu na rozvrh se vynuluje cizím klíčem ON DELETE SET NULL),
     * takže dál ukazují zdroj a nové opakování nenabídnou. Vazba zdroje (výskyt 0) se maže:
     * zdrojový doklad jde pak smazat i znovu naplánovat.
     */
    public function delete(int $supplierId, int $id): void
    {
        TransactionFailure::retrying($this->db->pdo(), fn () => $this->deleteOnce($supplierId, $id));
    }

    private function deleteOnce(int $supplierId, int $id): void
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            // Zámky ve stejném pořadí jako generování: zdrojová položka, rozvrh, výskyty. Opačné pořadí
            // by se se souběžným generováním zablokovalo (doplnění zdroje níž sdíleně zamyká i položku).
            $this->items->find($supplierId, $this->sourceId($supplierId, $id), true);
            $this->sourceId($supplierId, $id, true);
            // Výskyty převzaté importem nebo obnovou ze starší verze zdroj u sebe nemají: doplní se
            // dřív, než vazba na rozvrh zmizí, jinak by původ položky zrušení nepřežil.
            $pdo->prepare('UPDATE other_item_schedule_occurrences o
                JOIN other_item_schedules s ON s.id = o.schedule_id AND s.supplier_id = o.supplier_id
                SET o.source_item_id = s.source_item_id
                WHERE o.supplier_id = ? AND o.schedule_id = ? AND o.source_item_id IS NULL')->execute([$supplierId, $id]);
            $pdo->prepare('DELETE FROM other_item_schedule_occurrences WHERE supplier_id = ? AND schedule_id = ? AND occurrence_index = 0')
                ->execute([$supplierId, $id]);
            $pdo->prepare('DELETE FROM other_item_schedules WHERE supplier_id = ? AND id = ?')
                ->execute([$supplierId, $id]);
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
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
                throw new OtherItemException('forbidden', 'Pro automatické účtování nemáte oprávnění.', 403);
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
                $item = $this->service->create($supplierId, [
                    'title' => self::occurrenceTitle($template, $issued),
                    'issued_on' => $issued, 'accounting_on' => $issued, 'due_on' => $due,
                ] + $template, $userId, [
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

    /**
     * Splátkový kalendář se stavem každé splátky ({@see OtherItemInstallmentCoverage}):
     * uhrazeno, zbývá a stav unpaid / partial / paid / overdue.
     */
    public function installments(int $supplierId, int $itemId): array
    {
        $this->service->get($supplierId, $itemId);
        return $this->service->installmentCoverage($supplierId, $itemId);
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
            // Zámek kalendáře po první úhradě: i úhrada jen úroku či poplatku (jistina 0) ho zamyká.
            if (!in_array($item['status'], ['draft', 'confirmed', 'posted'], true)
                || (float) $item['paid_amount'] > 0 || $this->service->hasActivePayments($supplierId, $itemId)) {
                throw new OtherItemException('invalid_status', 'Splátky lze nastavit jen na aktivním neuhrazeném dokladu.', 409);
            }
            $normalized = [];
            $principalTotal = 0;
            $previous = '';
            foreach ($rows as $row) {
                if (!is_array($row)) throw new OtherItemException('invalid_installments', 'Neplatná splátka.');
                $date = (string) ($row['due_on'] ?? '');
                self::date($date);
                if ($date <= $previous || $date < $item['issued_on']) {
                    throw new OtherItemException('invalid_installment_date', 'Termíny splátek musí být vzestupné a po vystavení.');
                }
                $installment = self::installmentRow($row);
                $principalTotal += (int) round(($installment['principal_amount'] ?? $installment['amount']) * 100);
                $normalized[] = ['due_on' => $date] + $installment;
                $previous = $date;
            }
            if ($rows !== [] && $principalTotal !== (int) round((float) $item['amount'] * 100)) {
                throw new OtherItemException('installment_total', OtherItemInstallmentCoverage::hasSplit($normalized)
                    ? 'Součet jistiny splátek musí odpovídat částce dokladu.'
                    : 'Součet splátek musí odpovídat částce dokladu.');
            }
            $pdo->prepare('DELETE FROM other_item_installments WHERE supplier_id = ? AND other_item_id = ?')
                ->execute([$supplierId, $itemId]);
            $stmt = $pdo->prepare('INSERT INTO other_item_installments
                (supplier_id, other_item_id, position, due_on, amount, principal_amount, interest_amount, fee_amount)
                VALUES (?,?,?,?,?,?,?,?)');
            foreach ($normalized as $position => $row) {
                $stmt->execute([$supplierId, $itemId, $position + 1, $row['due_on'], $row['amount'],
                    $row['principal_amount'], $row['interest_amount'], $row['fee_amount']]);
            }
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->installments($supplierId, $itemId);
    }

    /**
     * Návrh splátkového kalendáře úvěru ({@see OtherItemLoanSchedule}) pro editor splátek.
     * Nic neukládá: uživatel řádky zkontroluje, případně upraví a uloží přes setInstallments.
     *
     * @return list<array{due_on:string,amount:float,principal_amount:float,interest_amount:float,fee_amount:float}>
     */
    public function planLoan(int $supplierId, int $itemId, array $input): array
    {
        $item = $this->service->get($supplierId, $itemId);
        $principal = $input['principal'] ?? $item['amount'];
        return OtherItemLoanSchedule::plan($input + ['principal' => $principal]);
    }

    /**
     * Částka splátky a její složky. Bez složek je splátka nerozdělená (jen `amount`).
     * Se složkou se chybějící složky berou jako 0 a jejich součet musí být `amount`;
     * bez `amount` se částka splátky dopočítá ze složek.
     *
     * @param array<string,mixed> $row
     * @return array{amount:float,principal_amount:?float,interest_amount:?float,fee_amount:?float}
     */
    private static function installmentRow(array $row): array
    {
        $parts = [];
        foreach (['principal_amount', 'interest_amount', 'fee_amount'] as $key) {
            $value = $row[$key] ?? null;
            if ($value === null || $value === '') continue;
            $number = filter_var($value, FILTER_VALIDATE_FLOAT);
            if ($number === false || !is_finite((float) $number) || $number < 0
                || round((float) $number, 2) !== (float) $number) {
                throw new OtherItemException('invalid_installment_component', 'Jistina, úrok a poplatek musí být nezáporné částky na haléře.');
            }
            $parts[$key] = (int) round((float) $number * 100);
        }
        $amountRaw = $row['amount'] ?? null;
        if (($amountRaw === null || $amountRaw === '') && $parts !== []) {
            $amountRaw = array_sum($parts) / 100.0;
        }
        $amount = filter_var($amountRaw, FILTER_VALIDATE_FLOAT);
        if ($amount === false || !is_finite((float) $amount) || $amount <= 0
            || round((float) $amount, 2) !== (float) $amount) {
            throw new OtherItemException('invalid_installment_amount', 'Splátka musí mít kladnou částku na haléře.');
        }
        if ($parts === []) {
            return ['amount' => round((float) $amount, 2), 'principal_amount' => null, 'interest_amount' => null, 'fee_amount' => null];
        }
        $parts += ['principal_amount' => 0, 'interest_amount' => 0, 'fee_amount' => 0];
        if (array_sum($parts) !== (int) round((float) $amount * 100)) {
            throw new OtherItemException('installment_components_total', 'Jistina, úrok a poplatek musí dát dohromady částku splátky.');
        }
        return [
            'amount' => round((float) $amount, 2),
            'principal_amount' => $parts['principal_amount'] / 100.0,
            'interest_amount' => $parts['interest_amount'] / 100.0,
            'fee_amount' => $parts['fee_amount'] / 100.0,
        ];
    }

    private function link(int $supplierId, int $scheduleId, int $index, int $itemId): void
    {
        // Zdroj si výskyt drží sám, aby po zrušení rozvrhu zůstal vidět původ položky.
        $stmt = $this->db->pdo()->prepare('INSERT INTO other_item_schedule_occurrences
            (supplier_id, schedule_id, occurrence_index, item_id, source_item_id)
            SELECT supplier_id, id, ?, ?, source_item_id FROM other_item_schedules WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$index, $itemId, $supplierId, $scheduleId]);
    }

    private function sourceId(int $supplierId, int $id, bool $lock = false): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT source_item_id FROM other_item_schedules WHERE supplier_id = ? AND id = ?'
            . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, $id]);
        $sourceId = $stmt->fetchColumn();
        if ($sourceId === false) throw new OtherItemException('schedule_not_found', 'Rozvrh nebyl nalezen.', 404);
        return (int) $sourceId;
    }

    /** Z dokladu jde založit rozvrh: aktivní a sám není výskytem jiného rozvrhu (založení i náhled). */
    private function assertSchedulable(int $supplierId, array $item): void
    {
        if (!in_array($item['status'], ['draft', 'confirmed', 'posted'], true)) {
            throw new OtherItemException('invalid_status', 'Opakování lze založit jen z aktivního dokladu.', 409);
        }
        $linked = $this->db->pdo()->prepare('SELECT schedule_id FROM other_item_schedule_occurrences WHERE supplier_id = ? AND item_id = ? LIMIT 1');
        $linked->execute([$supplierId, (int) $item['id']]);
        $linkedRow = $linked->fetch(PDO::FETCH_ASSOC);
        if ($linkedRow !== false && $linkedRow['schedule_id'] === null) {
            // Výskyt zrušeného opakování si drží původ; nové opakování z něj nevzniká.
            throw new OtherItemException('created_by_schedule', 'Doklad vznikl opakováním, nové opakování z něj založit nejde.', 409);
        }
        if ($linkedRow !== false) {
            throw new OtherItemException('already_scheduled', 'Doklad už patří do rozvrhu opakování.', 409);
        }
    }

    /**
     * První index nového rozvrhu. Znovu naplánovaný zdroj navazuje na položky zrušeného
     * opakování: první termín je až po poslední z nich, už vytvořené termíny nevzniknou
     * podruhé. Smazané koncepty se nepočítají, jejich termíny nový rozvrh vytvoří znovu.
     * Rozhoduje skutečné datum vzniku položky (zrušený rozvrh s četností už není).
     * Sdílí ho založení i náhled názvu, aby náhled ukázal termín, který založení vytvoří.
     */
    private function firstOccurrenceIndex(int $supplierId, array $item, string $frequency): int
    {
        $orphans = $this->db->pdo()->prepare('SELECT MAX(oi.issued_on) FROM other_item_schedule_occurrences o
            JOIN other_items oi ON oi.id = o.item_id AND oi.supplier_id = o.supplier_id
            WHERE o.supplier_id = ? AND o.source_item_id = ? AND o.schedule_id IS NULL AND o.occurrence_index > 0
              AND oi.deleted_at IS NULL');
        $orphans->execute([$supplierId, (int) $item['id']]);
        $lastOrphan = $orphans->fetchColumn();
        $nextIndex = 1;
        if (is_string($lastOrphan)) {
            $anchor = new \DateTimeImmutable($item['issued_on']);
            while ($nextIndex < 10000 && self::occurrenceDate($anchor, $nextIndex * self::MONTHS[$frequency]) <= $lastOrphan) {
                $nextIndex++;
            }
        }
        return $nextIndex;
    }

    /** Rozvrh po konci opakování už žádný výskyt nevytvoří; stejný výpočet jako next_on v decode(). */
    private static function assertNotFinished(array $schedule): void
    {
        $next = self::occurrenceDate(new \DateTimeImmutable($schedule['anchor_on']),
            (int) $schedule['next_index'] * self::MONTHS[$schedule['frequency']]);
        if ($schedule['ends_on'] !== null && $next > $schedule['ends_on']) {
            throw new OtherItemException('schedule_finished', 'Opakování už skončilo, další položka nevznikne.', 409);
        }
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

    /**
     * Název výskytu ze šablony rozvrhu. Stejné pořadí jako opakovaná faktura
     * ({@see \MyInvoice\Service\Invoice\RecurringInvoiceGenerator}): nejdřív zástupné znaky,
     * pak volitelné srovnání „MM/RRRR“ v textu. Referenční datum je datum vzniku výskytu
     * (u výskytu zároveň datum zaúčtování), obdoba DUZP faktury. Bez šablony a bez
     * srovnání zůstává název zdroje beze změny.
     */
    public static function occurrenceTitle(array $template, string $issuedOn): string
    {
        $ref = new \DateTimeImmutable($issuedOn);
        $custom = $template['title_template'] ?? null;
        $title = is_string($custom) ? DescriptionPlaceholders::apply($custom, $ref) : (string) ($template['title'] ?? '');
        if (($template['title_sync_period'] ?? false) === true) {
            $title = MonthSynchronizer::syncTo($title, $ref);
        }
        return $title;
    }

    /** @return array{title_template: ?string, title_sync_period: bool} */
    private static function titleSettings(array $input): array
    {
        $raw = $input['title_template'] ?? null;
        if ($raw !== null && !is_string($raw)) {
            throw new OtherItemException('invalid_title_template', 'Šablona názvu musí být text.', 422, 'title_template');
        }
        if ($raw !== null && !mb_check_encoding($raw, 'UTF-8')) {
            throw new OtherItemException('invalid_title_template', 'Šablona názvu musí být text.', 422, 'title_template');
        }
        $template = $raw === null || trim($raw) === '' ? null : trim($raw);
        if ($template !== null && mb_strlen($template) > 255) {
            throw new OtherItemException('title_template_too_long', 'Šablona názvu může mít nejvýše 255 znaků.', 422, 'title_template');
        }
        $unknown = $template === null ? [] : DescriptionPlaceholders::unknownTokens($template);
        if ($unknown !== []) {
            throw new OtherItemException('unknown_title_placeholder',
                'Šablona názvu obsahuje neznámý zástupný znak ' . implode(', ', $unknown) . '.', 422, 'title_template',
                ['tokens' => $unknown]);
        }
        $sync = $input['title_sync_period'] ?? false;
        if (!is_bool($sync)) {
            throw new OtherItemException('invalid_title_sync_period',
                'Volba srovnání období v názvu musí být boolean.', 422, 'title_sync_period');
        }
        return ['title_template' => $template, 'title_sync_period' => $sync];
    }

    /** Uloží nastavení názvu do šablony; výchozí hodnoty se nezapisují, starý rozvrh zůstane stejný. */
    private static function withTitleSettings(array $template, array $settings): array
    {
        $template = array_diff_key($template, self::TITLE_SETTINGS);
        if ($settings['title_template'] !== null) $template['title_template'] = $settings['title_template'];
        if ($settings['title_sync_period']) $template['title_sync_period'] = true;
        return $template;
    }

    /**
     * Dosadí šablonu do nejbližších výskytů (nejvýš do konce rozvrhu): příliš dlouhý název
     * je chyba hned při uložení, ne až v denní úloze (doklad má název nejvýše 255 znaků).
     * Dvanáct výskytů vystřídá všechny měsíce, čtvrtletí i zkrácení dne na konec měsíce.
     * Delší výsledek až za tímto oknem (jen umělé řetězení posunů roku přes rok 9999)
     * odmítne při generování OtherItemService::create (invalid_title). Prázdný výsledek nastat
     * nemůže: šablona bez neznámých znaků není prázdná a každý znak dosadí aspoň číslici.
     */
    private static function assertTitles(array $template, \DateTimeImmutable $anchor, int $fromIndex, int $months,
        ?string $endsOn): void
    {
        if (!isset($template['title_template']) && !isset($template['title_sync_period'])) return;
        $field = isset($template['title_template']) ? 'title_template' : 'title_sync_period';
        for ($i = 0; $i < self::TITLE_CHECK_COUNT; $i++) {
            $issuedOn = self::occurrenceDate($anchor, ($fromIndex + $i) * $months);
            if ($endsOn !== null && $issuedOn > $endsOn) break;
            $title = self::occurrenceTitle($template, $issuedOn);
            if (mb_strlen($title) > 255) {
                throw new OtherItemException('occurrence_title_too_long',
                    'Název položky po dosazení přesáhne 255 znaků.', 422, $field);
            }
        }
    }

    private static function occurrenceDate(\DateTimeImmutable $anchor, int $months): string
    {
        $first = $anchor->modify('first day of this month')->modify('+' . $months . ' months');
        $day = min((int) $anchor->format('d'), (int) $first->format('t'));
        return $first->setDate((int) $first->format('Y'), (int) $first->format('m'), $day)->format('Y-m-d');
    }

    /** Konec opakování ze vstupu: neplatné datum je chyba u pole (založení i náhled). */
    private static function endsOn(string $value): void
    {
        try {
            self::date($value);
        } catch (OtherItemException) {
            throw new OtherItemException('invalid_date', 'Zadejte platné datum.', 422, 'ends_on');
        }
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
        // Další termín počítá stejně jako generate(); po konci opakování nebo u pozastaveného rozvrhu žádný.
        $next = self::occurrenceDate(new \DateTimeImmutable($row['anchor_on']), (int) $row['next_index'] * self::MONTHS[$row['frequency']]);
        // Skončený = po konci opakování (i pozastavený); UI pak nenabízí změnu názvu.
        $row['finished'] = $row['ends_on'] !== null && $next > $row['ends_on'];
        $row['next_on'] = $row['status'] === 'active' && !$row['finished'] ? $next : null;
        $row['template'] = json_decode($row['template_json'], true, 512, JSON_THROW_ON_ERROR);
        $row['source_active'] = in_array($row['source_status'], ['draft', 'confirmed', 'posted'], true);
        $row['next_title'] = $row['next_on'] !== null ? self::occurrenceTitle($row['template'], $row['next_on']) : null;
        unset($row['template_json'], $row['source_status']);
        return $row;
    }
}
