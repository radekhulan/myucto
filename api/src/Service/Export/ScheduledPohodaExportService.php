<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\NamedLockName;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Mail\MailDeliveredArchiveException;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Mail\SupplierEmailContext;

/**
 * Plánovaný export vydaných dokladů do Pohody: po uplynutí měsíce (v den `run_day`, zameškané
 * měsíce hned) se doklady vyexportují stejným generátorem jako ruční export
 * ({@see PohodaXmlExporter}) a pošlou e-mailem účetní. Za běh se zpracuje jeden měsíc na firmu.
 *
 * Doklady se do měsíce řadí podle data vystavení, tedy jako výchozí ruční export (`date_by=issue`).
 *
 * Měsíc se nesmí ztratit:
 *  - exportují se jen uzavřené měsíce, nejdřív od `start_month` (měsíc před zapnutím plánu),
 *  - běh si pod `GET_LOCK` (firma + měsíc) pamatuje otisk každého dokladu; doklad, který po odeslání
 *    přibyl, zmizel nebo se změnil (včetně storna), se hlásí v panelu a v dalším měsíčním e-mailu,
 *  - e-mail ve frontě pošty má stav `queued`, dokud ho fronta nedoručí nebo nevzdá.
 */
final class ScheduledPohodaExportService
{
    /** Kolik uzavřených měsíců zpět se hlídají změny po odeslání. */
    public const WATCH_MONTHS = 12;

    /** Nejvýš tolik čísel dokladů se vypíše do jednoho upozornění v e-mailu. */
    private const MAIL_LIST_LIMIT = 50;

    private const GENERIC_ERROR = 'Export se nepodařilo odeslat. Podrobnosti jsou v protokolu aktivit.';

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaXmlExporter $pohoda,
        private readonly ExportPeriodResolver $periods,
        private readonly Mailer $mailer,
        private readonly SupplierEmailContext $emailContext,
        private readonly ActivityLogger $logger,
    ) {}

    /** @return array{enabled:bool,run_day:int,include_credit_notes:bool,recipient_email:string,start_month:?string} */
    public function config(int $supplierId): array
    {
        $st = $this->db->pdo()->prepare('SELECT * FROM scheduled_pohoda_export WHERE supplier_id = ?');
        $st->execute([$supplierId]);
        $r = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'enabled' => (bool) ($r['enabled'] ?? false),
            'run_day' => (int) ($r['run_day'] ?? 5),
            'include_credit_notes' => (bool) ($r['include_credit_notes'] ?? true),
            'recipient_email' => (string) ($r['recipient_email'] ?? ''),
            'start_month' => $r['start_month'] ?? null,
        ];
    }

    /** @param array<string,mixed> $in */
    public function saveConfig(int $supplierId, array $in, ?int $userId = null, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable();
        $old = $this->config($supplierId);
        $day = (int) ($in['run_day'] ?? 5);
        $email = trim((string) ($in['recipient_email'] ?? ''));
        $enabled = (bool) ($in['enabled'] ?? false);
        $credit = (bool) ($in['include_credit_notes'] ?? true);
        if ($day < 1 || $day > 28) {
            throw new \InvalidArgumentException('Den spuštění musí být 1 až 28.');
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('E-mail příjemce není platný.');
        }
        if ($enabled && $email === '') {
            throw new \InvalidArgumentException('Pro zapnutí vyplň e-mail účetní.');
        }
        // Zapnutí (i opětovné) začíná uplynulým měsícem: historie před zapnutím se neposílá.
        $start = $enabled ? ($old['enabled'] && $old['start_month'] !== null ? $old['start_month'] : self::previousMonth($now)) : $old['start_month'];
        $this->db->pdo()->prepare(
            'INSERT INTO scheduled_pohoda_export (supplier_id, enabled, run_day, include_credit_notes, recipient_email, start_month)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), run_day = VALUES(run_day),
               include_credit_notes = VALUES(include_credit_notes), recipient_email = VALUES(recipient_email),
               start_month = VALUES(start_month)'
        )->execute([$supplierId, (int) $enabled, $day, (int) $credit, $email, $start]);
        $this->logger->log('pohoda_export.config_changed', $userId, 'supplier', $supplierId, [
            'old' => $old,
            'new' => ['enabled' => $enabled, 'run_day' => $day, 'include_credit_notes' => $credit, 'recipient_email' => $email, 'start_month' => $start],
        ]);
    }

    public static function previousMonth(DateTimeImmutable $now): string
    {
        return $now->modify('first day of last month')->format('Y-m');
    }

    /**
     * Firma => nejstarší uzavřený měsíc od `start_month`, který ještě nemá úspěšný běh.
     * Nejnovější uzavřený měsíc je na řadě od `run_day`, starší (zameškané) hned.
     *
     * @return array<int,string>
     */
    public function dueMonths(DateTimeImmutable $now): array
    {
        $last = self::previousMonth($now);
        $rows = $this->db->pdo()->query(
            "SELECT e.supplier_id, e.run_day, e.start_month FROM scheduled_pohoda_export e
               JOIN supplier s ON s.id = e.supplier_id
              WHERE e.enabled = 1 AND e.recipient_email <> '' AND e.start_month IS NOT NULL"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $due = [];
        foreach ($rows as $r) {
            $sid = (int) $r['supplier_id'];
            $this->reconcileQueued($sid);
            for ($m = new DateTimeImmutable($r['start_month'] . '-01'); $m->format('Y-m') <= $last; $m = $m->modify('+1 month')) {
                $label = $m->format('Y-m');
                if ($this->hasDelivered($sid, $label)) {
                    continue;
                }
                if ($label < $last || (int) $now->format('j') >= (int) $r['run_day']) {
                    $due[$sid] = $label;
                }
                break;
            }
        }
        return $due;
    }

    /**
     * @return array{status:'ok'|'queued'|'error'|'exists'|'busy',doc_count:int,error?:?string}
     * @throws \DomainException Měsíc není uzavřený.
     */
    public function run(int $supplierId, string $periodLabel, string $trigger, bool $force = false, ?int $userId = null, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        if ($periodLabel >= $now->format('Y-m')) {
            throw new \DomainException('Exportovat lze jen uzavřený měsíc.');
        }
        $pdo = $this->db->pdo();
        $lock = NamedLockName::for($this->db, 'pohoda-export', $supplierId . ':' . $periodLabel);
        $got = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $got->execute([$lock]);
        if ((int) $got->fetchColumn() !== 1) {
            return ['status' => 'busy', 'doc_count' => 0];
        }
        try {
            $this->reconcileQueued($supplierId);
            if (!$force && $this->hasDelivered($supplierId, $periodLabel)) {
                return ['status' => 'exists', 'doc_count' => 0];
            }
            return $this->runLocked($supplierId, $periodLabel, $trigger, $userId);
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
        }
    }

    /** @return array{status:'ok'|'queued'|'error',doc_count:int,error?:?string} */
    private function runLocked(int $supplierId, string $periodLabel, string $trigger, ?int $userId): array
    {
        $cfg = $this->config($supplierId);
        $docs = [];
        $exportIds = [];
        $changes = [];
        $status = 'ok';
        $outboxId = null;
        $error = null;
        try {
            if ($cfg['recipient_email'] === '') {
                throw new \DomainException('Není vyplněný e-mail účetní.');
            }
            $docs = $this->fingerprints($supplierId, $periodLabel, $cfg['include_credit_notes']);
            $exportIds = array_keys(array_filter($docs, fn (array $d): bool => !$d['cancelled']));
            $changes = $this->changedMonths($supplierId, $periodLabel, true);
            if ($exportIds !== [] || $changes !== []) {
                $file = $exportIds === [] ? null : $this->pohoda->export($exportIds, $supplierId, $periodLabel);
                $delivery = $this->send($supplierId, $cfg['recipient_email'], $periodLabel, count($exportIds), $file, $changes, $userId);
                if ($delivery['deferred']) {
                    $status = 'queued';
                    $outboxId = $delivery['outbox_id'];
                }
            }
        } catch (\Throwable $e) {
            $error = $e instanceof \DomainException ? $e->getMessage() : self::GENERIC_ERROR;
            $status = 'error';
            $this->logger->log('pohoda_export.scheduled_failed', $userId, 'supplier', $supplierId, [
                'period' => $periodLabel, 'trigger' => $trigger, 'detail' => mb_substr($e->getMessage(), 0, 1000),
            ]);
        }

        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        $own && $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO scheduled_pohoda_export_run (supplier_id, period_label, trigger_by, status, doc_count, include_credit_notes, recipient_email, outbox_id, error)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([$supplierId, $periodLabel, $trigger, $status, $status === 'error' ? 0 : count($exportIds), (int) $cfg['include_credit_notes'], $cfg['recipient_email'], $outboxId, $error]);
            $runId = (int) $pdo->lastInsertId();
            if ($status !== 'error') {
                $ins = $pdo->prepare('INSERT INTO scheduled_pohoda_export_doc (run_id, invoice_id, fp, varsymbol, cancelled) VALUES (?,?,?,?,?)');
                foreach ($docs as $id => $d) {
                    $ins->execute([$runId, $id, $d['fp'], mb_substr($d['vs'], 0, 64), (int) $d['cancelled']]);
                }
                // Oznámené změny starších měsíců se už příště nehlásí, dokud nepřibude další.
                $warn = $pdo->prepare('UPDATE scheduled_pohoda_export_run SET warned_changed = ? WHERE id = ?');
                foreach ($changes as $c) {
                    $warn->execute([$c['changed'], $c['run_id']]);
                }
            }
            $own && $pdo->commit();
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $this->logger->log('pohoda_export.scheduled_run', $userId, 'supplier', $supplierId, [
            'period' => $periodLabel, 'trigger' => $trigger, 'status' => $status, 'count' => count($exportIds),
            'recipient_email' => $cfg['recipient_email'],
        ]);
        return $status === 'error'
            ? ['status' => 'error', 'doc_count' => 0, 'error' => $error]
            : ['status' => $status, 'doc_count' => count($exportIds)];
    }

    /**
     * Měsíce (nejvýš WATCH_MONTHS uzavřených zpět), ve kterých se po posledním odeslání změnily doklady.
     * Porovnává se ve stejném rozsahu, v jakém běh proběhl (dobropisy ano/ne), takže přepnutí
     * volby změny nevyrobí. Doklad, který byl už při běhu stornovaný, se do Pohody nedostal, tedy
     * jeho storno ani nepřibyl nic nemění.
     *
     * @return array<string,array{period:string,changed:int,run_id:int,warned:int,items:list<array{vs:string,kind:string}>}>
     */
    public function changedMonths(int $supplierId, ?string $exclude = null, bool $onlyUnannounced = false, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $from = $now->modify('first day of this month')->modify('-' . self::WATCH_MONTHS . ' months')->format('Y-m');
        $st = $this->db->pdo()->prepare(
            "SELECT r.id, r.period_label, r.warned_changed, r.include_credit_notes FROM scheduled_pohoda_export_run r
              WHERE r.supplier_id = ? AND r.period_label >= ? AND r.period_label < ? AND r.status IN ('ok','queued')
                AND r.id = (SELECT MAX(r2.id) FROM scheduled_pohoda_export_run r2
                             WHERE r2.supplier_id = r.supplier_id AND r2.period_label = r.period_label AND r2.status IN ('ok','queued'))"
        );
        $st->execute([$supplierId, $from, $now->format('Y-m')]);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $label = (string) $r['period_label'];
            if ($label === $exclude) {
                continue;
            }
            $map = $this->db->pdo()->prepare('SELECT invoice_id, fp, varsymbol, cancelled FROM scheduled_pohoda_export_doc WHERE run_id = ?');
            $map->execute([(int) $r['id']]);
            $before = [];
            foreach ($map->fetchAll(\PDO::FETCH_ASSOC) as $d) {
                $before[(int) $d['invoice_id']] = $d;
            }
            $current = $this->fingerprints($supplierId, $label, (bool) $r['include_credit_notes']);
            $items = [];
            foreach ($current as $id => $d) {
                $was = $before[$id] ?? null;
                if ($was === null) {
                    if (!$d['cancelled']) {
                        $items[] = ['vs' => $d['vs'], 'kind' => 'new'];
                    }
                } elseif ($was['fp'] !== $d['fp']) {
                    $items[] = ['vs' => $d['vs'], 'kind' => $d['cancelled'] && !$was['cancelled'] ? 'cancelled' : 'changed'];
                }
            }
            foreach (array_diff_key($before, $current) as $d) {
                if (!(bool) $d['cancelled']) {
                    $items[] = ['vs' => (string) $d['varsymbol'], 'kind' => 'removed'];
                }
            }
            $changed = count($items);
            if ($changed > 0 && (!$onlyUnannounced || $changed > (int) $r['warned_changed'])) {
                $out[$label] = ['period' => $label, 'changed' => $changed, 'run_id' => (int) $r['id'], 'warned' => (int) $r['warned_changed'], 'items' => $items];
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Historie po měsících: nejnovější běh měsíce, počet pokusů a změny po odeslání.
     * `problem` = poslední selhání měsíce, který zatím nemá úspěšný běh.
     *
     * @return array{runs:list<array<string,mixed>>,problem:?array<string,mixed>}
     */
    public function history(int $supplierId, int $limit = 12): array
    {
        $this->reconcileQueued($supplierId);
        $st = $this->db->pdo()->prepare('SELECT * FROM scheduled_pohoda_export_run WHERE supplier_id = ? ORDER BY id DESC LIMIT 500');
        $st->execute([$supplierId]);
        $byPeriod = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $p = (string) $r['period_label'];
            if (!isset($byPeriod[$p])) {
                $r['attempts'] = 0;
                $r['delivered'] = false;
                $byPeriod[$p] = $r;
            }
            $byPeriod[$p]['attempts']++;
            $byPeriod[$p]['delivered'] = $byPeriod[$p]['delivered'] || $r['status'] !== 'error';
        }
        $changes = $this->changedMonths($supplierId);
        $problem = null;
        foreach ($byPeriod as $p => &$r) {
            $r['changed_after'] = (int) ($changes[$p]['changed'] ?? 0);
            if ($problem === null && !$r['delivered']) {
                $problem = ['period_label' => $p, 'error' => $r['error'], 'attempts' => $r['attempts'], 'created_at' => $r['created_at']];
            }
        }
        unset($r);
        return ['runs' => array_slice(array_values($byPeriod), 0, $limit), 'problem' => $problem];
    }

    /** Dotáhne stav e-mailů, které čekaly ve frontě pošty: doručeno => ok, vzdáno => error. */
    public function reconcileQueued(int $supplierId): void
    {
        $pdo = $this->db->pdo();
        $st = $pdo->prepare("SELECT id, outbox_id FROM scheduled_pohoda_export_run WHERE supplier_id = ? AND status = 'queued'");
        $st->execute([$supplierId]);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $state = $this->outboxState((int) $r['outbox_id']);
            if ($state === 'sent') {
                $pdo->prepare("UPDATE scheduled_pohoda_export_run SET status = 'ok', outbox_id = NULL WHERE id = ?")->execute([$r['id']]);
            } elseif ($state === 'failed') {
                $pdo->prepare("UPDATE scheduled_pohoda_export_run SET status = 'error', outbox_id = NULL, error = ? WHERE id = ?")
                    ->execute(['E-mail se nepodařilo doručit ani po opakovaných pokusech.', $r['id']]);
            }
        }
    }

    /** @return 'sent'|'failed'|'pending' Zpráva odložená brzdou může pokračovat pod novým id (viz MailOutbox::markRequeued). */
    private function outboxState(int $id): string
    {
        for ($i = 0; $i < 20 && $id > 0; $i++) {
            $st = $this->db->pdo()->prepare('SELECT status, last_error FROM mail_outbox WHERE id = ?');
            $st->execute([$id]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            if ($row === false) {
                return 'failed';
            }
            if ($row['status'] === 'requeued' && preg_match('/#(\d+)/', (string) $row['last_error'], $m) === 1) {
                $id = (int) $m[1];
                continue;
            }
            return in_array($row['status'], ['sent', 'failed'], true) ? (string) $row['status'] : 'pending';
        }
        return 'pending';
    }

    private function hasDelivered(int $supplierId, string $periodLabel): bool
    {
        $st = $this->db->pdo()->prepare(
            "SELECT 1 FROM scheduled_pohoda_export_run WHERE supplier_id = ? AND period_label = ? AND status IN ('ok','queued') LIMIT 1"
        );
        $st->execute([$supplierId, $periodLabel]);
        return $st->fetchColumn() !== false;
    }

    /**
     * Doklady měsíce podle data vystavení (jako ruční export) a otisk obsahu každého.
     * Storna se vedou s příznakem `cancelled`: neexportují se, ale změna stavu na storno je změna.
     *
     * @return array<int,array{fp:string,cancelled:bool,vs:string}>
     */
    private function fingerprints(int $supplierId, string $periodLabel, bool $creditNotes): array
    {
        $period = $this->periods->resolve(['period' => 'monthly', 'month' => $periodLabel]);
        $types = $creditNotes ? "'invoice','credit_note'" : "'invoice'";
        // Výchozí limit GROUP_CONCAT (1024 znaků) by u dlouhé faktury odřízl konec a jeho změna by se nepoznala.
        $this->db->pdo()->exec('SET SESSION group_concat_max_len = 16777216');
        $st = $this->db->pdo()->prepare(
            "SELECT i.id, i.varsymbol, i.status = 'cancelled' AS cancelled,
                    MD5(CONCAT_WS('|', i.invoice_type, i.varsymbol, i.issue_date, i.tax_date, i.due_date, i.client_id,
                        i.status = 'cancelled', i.total_without_vat, i.total_with_vat,
                        COALESCE((SELECT GROUP_CONCAT(CONCAT_WS(',', it.description, it.quantity, it.unit_price_without_vat,
                                                                it.vat_rate_snapshot, it.total_with_vat) ORDER BY it.id SEPARATOR ';')
                                    FROM invoice_items it WHERE it.invoice_id = i.id), ''))) AS fp
               FROM invoices i
              WHERE i.supplier_id = ? AND i.issue_date >= ? AND i.issue_date < ?
                AND i.status IN ('issued','sent','reminded','paid','cancelled') AND i.invoice_type IN ($types)
           ORDER BY i.issue_date, i.id"
        );
        $st->execute([$supplierId, $period->dateFrom, $period->dateToExclusive]);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = ['fp' => (string) $r['fp'], 'cancelled' => (bool) $r['cancelled'], 'vs' => (string) $r['varsymbol']];
        }
        return $out;
    }

    /**
     * @param array{filename:string,content:string,mime:string}|null $file
     * @param array<string,array{period:string,changed:int,items:list<array{vs:string,kind:string}>}> $changes
     * @return array{deferred:bool,outbox_id:?int}
     */
    private function send(int $supplierId, string $to, string $label, int $count, ?array $file, array $changes, ?int $userId): array
    {
        $notes = [];
        $kinds = [
            'changed' => 'změněn',
            'new' => 'nový, v exportu dosud nebyl',
            'cancelled' => 'stornován, v Pohodě je potřeba zrušit',
            'removed' => 'zmizel z měsíce, v Pohodě je potřeba zrušit',
        ];
        foreach ($changes as $c) {
            $n = $c['changed'];
            $list = [];
            foreach (array_slice($c['items'], 0, self::MAIL_LIST_LIMIT) as $it) {
                $list[] = ($it['vs'] !== '' ? $it['vs'] : 'bez čísla') . ' (' . $kinds[$it['kind']] . ')';
            }
            if ($n > self::MAIL_LIST_LIMIT) {
                $list[] = 'a dalších ' . ($n - self::MAIL_LIST_LIMIT);
            }
            $notes[] = sprintf('Za %s se po odeslání %s %d %s: %s. Export lze znovu odeslat v aplikaci MyÚčto v části Export.', $c['period'],
                $n === 1 ? 'změnil' : ($n < 5 ? 'změnily' : 'změnilo'), $n, $n === 1 ? 'doklad' : ($n < 5 ? 'doklady' : 'dokladů'), implode(', ', $list));
        }
        $attachments = $file === null ? [] : [['content' => $file['content'], 'name' => $file['filename'], 'contentType' => $file['mime']]];
        try {
            $result = $this->mailer->sendTemplateDetailed('scheduled_export', 'cs', [$to], [
                'period' => $label,
                'count' => $count,
                'has_attachment' => $file !== null,
                'change_notes' => $notes,
                'supplier' => $this->emailContext->forSupplier($supplierId),
            ], $file === null ? 'Upozornění k exportu dokladů do Pohody' : "Export dokladů do Pohody za $label", [], [], $attachments, $userId, templateSupplierId: $supplierId);
        } catch (MailDeliveredArchiveException) {
            // e-mail odešel, jen se nepodařilo uložit kopii do složky Odeslané
            return ['deferred' => false, 'outbox_id' => null];
        }
        return ['deferred' => ($result['deferred'] ?? false) === true, 'outbox_id' => isset($result['outbox_id']) ? (int) $result['outbox_id'] : null];
    }
}
