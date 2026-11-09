<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\EntryForm;

use MyInvoice\Http\IsoDate;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Security\FrameworkScopePolicy;
use MyInvoice\Service\ActivityLogger;

/**
 * Forma účetního zápisu firmy K DATU (SSOT čtení i zápisu accounting_entry_form_history).
 *
 * Historie je řada řádků {valid_from, entry_form}; forma k datu D = řádek s nejvyšším
 * valid_from <= D. Firma bez řádku (i před prvním řádkem) účtuje složeně, takže firma,
 * která volbu nezapne, nepozná změnu. Rozhoduje `entry_date` zápisu, ne datum pořízení.
 *
 * Čtenáři deníku (opis, cash flow, exporty) se ptají nejdřív {@see hasAnySingle()}:
 * firma bez jediného řádku `single` jde beze změny dnešní cestou (i dnešním SQL).
 *
 * Paměť v instanci: history() a hasAnySingle() se v rámci požadavku čtou jednou.
 * Zaúčtování uvnitř transakce pod zámkem období volá formAt(..., currentRead: true),
 * které paměť obchází, aby nečetlo stav starší než souběžná změna formy.
 */
final class EntryFormService
{
    public const AUDIT_ACTION = 'accounting.entry_form_changed';

    /** @var array<int, list<array{id:int, valid_from:string, entry_form:string, created_by:?int, created_at:string, note:?string}>> */
    private array $historyMemo = [];

    /** @var array<int,bool> */
    private array $anySingleMemo = [];

    public function __construct(
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly AccountingModeRepository $modes,
        private readonly FrameworkScopePolicy $frameworkScope,
    ) {}

    /** Forma zápisu firmy k datu (YYYY-MM-DD). Bez řádku historie = složený zápis. */
    public function formAt(int $supplierId, string $date, bool $currentRead = false): EntryForm
    {
        if ($currentRead) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT entry_form FROM accounting_entry_form_history
                  WHERE supplier_id = ? AND valid_from <= ?
                  ORDER BY valid_from DESC LIMIT 1'
            );
            $stmt->execute([$supplierId, $date]);
            $form = $stmt->fetchColumn();
            return is_string($form) ? EntryForm::from($form) : EntryForm::Composite;
        }
        if (!$this->hasAnySingle($supplierId)) {
            return EntryForm::Composite;
        }
        return self::formFromHistory($this->history($supplierId), $date);
    }

    /**
     * Forma k datu nad už načtenou historií (seřazenou vzestupně podle valid_from).
     * Statická, aby ji mohly sdílet dávkové cesty (převod, export) bez dotazu na zápis.
     *
     * @param list<array{valid_from:string, entry_form:string}> $history
     */
    public static function formFromHistory(array $history, string $date): EntryForm
    {
        $form = EntryForm::Composite;
        foreach ($history as $row) {
            if ($row['valid_from'] > $date) {
                break;
            }
            $form = EntryForm::from($row['entry_form']);
        }
        return $form;
    }

    /**
     * Celá historie formy firmy, vzestupně podle valid_from.
     *
     * @return list<array{id:int, valid_from:string, entry_form:string, created_by:?int, created_at:string, note:?string}>
     */
    public function history(int $supplierId): array
    {
        if (isset($this->historyMemo[$supplierId])) {
            return $this->historyMemo[$supplierId];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, valid_from, entry_form, created_by, created_at, note
               FROM accounting_entry_form_history
              WHERE supplier_id = ?
              ORDER BY valid_from ASC'
        );
        $stmt->execute([$supplierId]);
        $rows = array_map(static fn (array $r): array => [
            'id'         => (int) $r['id'],
            'valid_from' => (string) $r['valid_from'],
            'entry_form' => (string) $r['entry_form'],
            'created_by' => $r['created_by'] !== null ? (int) $r['created_by'] : null,
            'created_at' => (string) $r['created_at'],
            'note'       => $r['note'] !== null ? (string) $r['note'] : null,
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));

        return $this->historyMemo[$supplierId] = $rows;
    }

    /**
     * Měla firma někdy (i do budoucna) jednoduchý zápis? Levná rychlá cesta pro čtenáře:
     * false = všechno jako dosud, žádná nová větev SQL ani PHP.
     */
    public function hasAnySingle(int $supplierId): bool
    {
        if (isset($this->anySingleMemo[$supplierId])) {
            return $this->anySingleMemo[$supplierId];
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM accounting_entry_form_history
              WHERE supplier_id = ? AND entry_form = 'single' LIMIT 1"
        );
        $stmt->execute([$supplierId]);

        return $this->anySingleMemo[$supplierId] = $stmt->fetchColumn() !== false;
    }

    /**
     * Naplánuje formu zápisu od data. Jediná zápisová cesta do historie.
     *
     * Pravidla: firma vede podvojné účetnictví k datu; nejde o SVJ ani o neziskovku
     * či rámec 504 (v1 je jednoduchý zápis jen pro obchodní firmy); valid_from leží
     * v otevřeném účetním období a za zámkem k datu (locked_until); neexistuje novější
     * ani stejně datovaná změna; forma k datu se skutečně mění. Oprávnění (jen admin
     * firmy) řeší Action přes RequestAuthorization, služba ho nepředpokládá.
     *
     * Zápis historie a auditní záznam `accounting.entry_form_changed` jsou v jedné
     * transakci. Převod starších zápisů v okně valid_from..dnes spouští volající
     * (JournalPairConversionService), ne tahle metoda.
     *
     * @return int id nového řádku historie
     * @throws EntryFormException
     */
    public function schedule(
        int $supplierId,
        string $validFrom,
        EntryForm $form,
        int $actor,
        ?int $conversionPreviewId = null,
        ?string $note = null,
    ): int {
        if (!IsoDate::isValid($validFrom)) {
            throw new EntryFormException('invalid_entry_form_date', 'Datum účinnosti formy zápisu není platné.');
        }
        $note = $note !== null ? trim($note) : null;
        if ($note === '') {
            $note = null;
        }
        if ($note !== null && mb_strlen($note) > 255) {
            $note = mb_substr($note, 0, 255);
        }
        if ($this->modes->forDate($supplierId, $validFrom) !== 'double_entry') {
            throw new EntryFormException(
                'entry_form_requires_double_entry',
                'Formu účetního zápisu lze nastavit jen firmě, která k tomuto datu vede podvojné účetnictví.',
                409,
            );
        }
        if ($this->frameworkScope->allows($supplierId, FrameworkScopePolicy::SVJ)
            || $this->frameworkScope->allows($supplierId, FrameworkScopePolicy::NONPROFIT_504)
        ) {
            throw new EntryFormException(
                'entry_form_unsupported_module',
                'Jednoduchý zápis zatím není dostupný pro společenství vlastníků jednotek ani pro neziskové organizace.',
                409,
            );
        }

        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT entry_form_schedule');
        }

        try {
            // Pořadí zámků jako zaúčtování (období -> nastavení firmy -> zbytek), aby
            // souběžné účtování a změna formy nevytvořily deadlock. Zaúčtování v období
            // od valid_from pak pod zámkem období vidí už novou formu.
            $periods = $pdo->prepare(
                'SELECT id, starts_on, ends_on, status FROM accounting_periods
                  WHERE supplier_id = ? AND ends_on >= ?
                  ORDER BY starts_on, id FOR UPDATE'
            );
            $periods->execute([$supplierId, $validFrom]);
            $period = null;
            foreach ($periods->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                if ((string) $row['starts_on'] <= $validFrom && (string) $row['ends_on'] >= $validFrom
                    && ($period === null || (string) $row['starts_on'] > (string) $period['starts_on'])
                ) {
                    $period = $row;
                }
            }
            if ($period === null || (string) $period['status'] !== 'open') {
                throw new EntryFormException(
                    'entry_form_period_not_open',
                    'Formu zápisu lze změnit jen k datu v otevřeném účetním období.',
                    409,
                    ['period_status' => $period !== null ? (string) $period['status'] : null],
                );
            }

            $lock = $pdo->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ? FOR UPDATE');
            $lock->execute([$supplierId]);
            $lockedUntil = $lock->fetchColumn();
            if (is_string($lockedUntil) && $lockedUntil !== '' && $validFrom <= $lockedUntil) {
                throw new EntryFormException(
                    'entry_form_date_locked',
                    'Datum účinnosti formy zápisu leží v uzamčeném období (zámek k ' . $lockedUntil . ').',
                    409,
                    ['locked_until' => $lockedUntil],
                );
            }

            $existing = $pdo->prepare(
                'SELECT valid_from, entry_form FROM accounting_entry_form_history
                  WHERE supplier_id = ? ORDER BY valid_from ASC FOR UPDATE'
            );
            $existing->execute([$supplierId]);
            $history = array_map(static fn (array $r): array => [
                'valid_from' => (string) $r['valid_from'],
                'entry_form' => (string) $r['entry_form'],
            ], $existing->fetchAll(\PDO::FETCH_ASSOC));
            foreach ($history as $row) {
                if ($row['valid_from'] >= $validFrom) {
                    throw new EntryFormException(
                        'entry_form_newer_change_exists',
                        'Forma zápisu má už naplánovanou změnu k ' . $row['valid_from'] . ' — novější změnu nejde předběhnout.',
                        409,
                        ['valid_from' => $row['valid_from']],
                    );
                }
            }
            $from = self::formFromHistory($history, $validFrom);
            if ($from === $form) {
                throw new EntryFormException(
                    'entry_form_unchanged',
                    'Firma má k tomuto datu už nastavenou stejnou formu zápisu.',
                    409,
                );
            }

            $pdo->prepare(
                'INSERT INTO accounting_entry_form_history (supplier_id, valid_from, entry_form, created_by, note)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$supplierId, $validFrom, $form->value, $actor > 0 ? $actor : null, $note]);
            $id = (int) $pdo->lastInsertId();

            $this->logger->log(
                self::AUDIT_ACTION,
                $actor > 0 ? $actor : null,
                'supplier',
                $supplierId,
                [
                    'valid_from'            => $validFrom,
                    'from'                  => $from->value,
                    'to'                    => $form->value,
                    'conversion_preview_id' => $conversionPreviewId,
                ],
                supplierId: $supplierId,
            );

            if ($ownsTransaction) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT entry_form_schedule');
            }
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif (!$ownsTransaction && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT entry_form_schedule');
                $pdo->exec('RELEASE SAVEPOINT entry_form_schedule');
            }
            throw $e;
        }

        $this->forget($supplierId);

        return $id;
    }

    /** Zahodí paměť instance (po změně formy, v dlouho běžících workerech mezi dávkami). */
    public function forget(?int $supplierId = null): void
    {
        if ($supplierId === null) {
            $this->historyMemo = [];
            $this->anySingleMemo = [];
            return;
        }
        unset($this->historyMemo[$supplierId], $this->anySingleMemo[$supplierId]);
    }
}
