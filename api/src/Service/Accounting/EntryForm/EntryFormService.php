<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\EntryForm;

use MyInvoice\Http\IsoDate;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Security\FrameworkScopePolicy;
use MyInvoice\Service\Accounting\LegalFormProfileRecommendation;
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
 * Paměť: history() se v instanci čte jednou. Odpověď hasAnySingle() sdílí celý proces
 * (webový požadavek; v dlouho běžícím workeru nejvýš {@see ANY_SINGLE_TTL} s), takže firma
 * bez jednoduchého zápisu po prvním dotazu neplatí za zaúčtování ani zápis řádků deníku
 * žádný dotaz navíc. schedule() paměť zahodí. Firma, která jednoduchý zápis má, čte
 * formu k datu s currentRead pokaždé znovu (pod zámkem období zaúčtování).
 */
final class EntryFormService
{
    public const AUDIT_ACTION = 'accounting.entry_form_changed';

    /** Počátek výchozí formy instalace: forma platí pro všechna data firmy. */
    public const INSTALLATION_DEFAULT_FROM = '1900-01-01';

    public const INSTALLATION_DEFAULT_NOTE = 'Výchozí forma zápisu instalace';

    /** @var array<int, list<array{id:int, valid_from:string, entry_form:string, created_by:?int, created_at:string, note:?string}>> */
    private array $historyMemo = [];

    /** Jak dlouho (s) smí proces věřit odpovědi hasAnySingle() (dlouho běžící workery). */
    private const ANY_SINGLE_TTL = 60.0;

    /** @var array<int, array{0:bool, 1:float}> supplier_id => [má jednoduchý zápis, platí do] */
    private static array $anySingleShared = [];

    public function __construct(
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly AccountingModeRepository $modes,
        private readonly FrameworkScopePolicy $frameworkScope,
    ) {}

    /** Forma zápisu firmy k datu (YYYY-MM-DD). Bez řádku historie = složený zápis. */
    public function formAt(int $supplierId, string $date, bool $currentRead = false): EntryForm
    {
        if (!$this->hasAnySingle($supplierId)) {
            return EntryForm::Composite;
        }
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
        $now = microtime(true);
        $cached = self::$anySingleShared[$supplierId] ?? null;
        if ($cached !== null && $cached[1] > $now) {
            return $cached[0];
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM accounting_entry_form_history
              WHERE supplier_id = ? AND entry_form = 'single' LIMIT 1"
        );
        $stmt->execute([$supplierId]);

        $any = $stmt->fetchColumn() !== false;
        self::$anySingleShared[$supplierId] = [$any, $now + self::ANY_SINGLE_TTL];
        return $any;
    }

    /**
     * Naplánuje formu zápisu od data. Jediná zápisová cesta do historie.
     *
     * Pravidla: firma vede podvojné účetnictví k datu; je to obchodní firma nebo SVJ
     * (spolky a ostatní NNO zatím ne, viz {@see supportsSingle()}); valid_from leží
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
        if (!$this->supportsSingle($supplierId)) {
            throw new EntryFormException(
                'entry_form_unsupported_module',
                'Jednoduchý zápis zatím není dostupný pro spolky a ostatní neziskové organizace.',
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

    /**
     * Výchozí forma instalace pro NOVOU firmu: řádek historie od
     * {@see INSTALLATION_DEFAULT_FROM}, takže forma platí pro všechna data účetnictví
     * včetně převzaté historie. Druhá zápisová cesta do historie vedle
     * {@see schedule()}; pravidla změny (otevřené období, zámek) se tu neuplatní,
     * protože firma ještě nic nezaúčtovala.
     *
     * Zapíše jen firmě, která historii formy nemá vůbec, nemá žádný zápis v deníku
     * a jednoduchý zápis smí mít ({@see supportsSingle()}). Jinak nic a vrací null,
     * takže opakované volání (založení, aktivace, převod) je bezpečné.
     */
    public function seedInstallationDefault(int $supplierId, EntryForm $form, ?int $actor = null): ?int
    {
        if ($form !== EntryForm::Single) {
            return null;
        }
        $pdo = $this->db->pdo();
        $history = $pdo->prepare('SELECT 1 FROM accounting_entry_form_history WHERE supplier_id = ? LIMIT 1');
        $history->execute([$supplierId]);
        if ($history->fetchColumn() !== false) {
            return null;
        }
        $journal = $pdo->prepare('SELECT 1 FROM journal_entries WHERE supplier_id = ? LIMIT 1');
        $journal->execute([$supplierId]);
        if ($journal->fetchColumn() !== false || !$this->supportsSingle($supplierId)) {
            return null;
        }

        $pdo->prepare(
            'INSERT IGNORE INTO accounting_entry_form_history (supplier_id, valid_from, entry_form, created_by, note)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$supplierId, self::INSTALLATION_DEFAULT_FROM, $form->value, $actor !== null && $actor > 0 ? $actor : null, self::INSTALLATION_DEFAULT_NOTE]);
        $id = (int) $pdo->lastInsertId();
        if ($id === 0) {
            return null;
        }
        $this->logger->log(
            self::AUDIT_ACTION,
            $actor !== null && $actor > 0 ? $actor : null,
            'supplier',
            $supplierId,
            [
                'valid_from'            => self::INSTALLATION_DEFAULT_FROM,
                'from'                  => EntryForm::Composite->value,
                'to'                    => $form->value,
                'conversion_preview_id' => null,
                'installation_default'  => true,
            ],
            supplierId: $supplierId,
        );
        $this->forget($supplierId);

        return $id;
    }

    /** Je řádek historie výchozí formou instalace zapsanou při založení firmy ({@see seedInstallationDefault()})? */
    public static function isInstallationDefaultRow(array $row): bool
    {
        return (string) ($row['valid_from'] ?? '') === self::INSTALLATION_DEFAULT_FROM
            && (string) ($row['entry_form'] ?? '') === EntryForm::Single->value
            && ($row['note'] ?? null) === self::INSTALLATION_DEFAULT_NOTE;
    }

    /**
     * Skutečné změny formy v historii: [valid_from, forma] jen tam, kde se forma oproti
     * předchozímu stavu (na počátku složený zápis) mění. Dvě historie se stejnými změnami
     * dávají formu stejnou ke každému datu.
     *
     * @param iterable<array{valid_from:string, entry_form:string}> $history libovolné pořadí
     * @return list<array{0:string, 1:string}>
     */
    public static function effectiveChanges(iterable $history): array
    {
        $rows = [];
        foreach ($history as $row) {
            $rows[(string) $row['valid_from']] = EntryForm::from((string) $row['entry_form'])->value;
        }
        ksort($rows, SORT_STRING);
        $changes = [];
        $current = EntryForm::Composite->value;
        foreach ($rows as $from => $form) {
            if ($form !== $current) {
                $changes[] = [(string) $from, $form];
                $current = $form;
            }
        }
        return $changes;
    }

    /**
     * Obnova firmy z exportu: nahradí celou historii formy historií zdroje (třetí zápisová
     * cesta vedle {@see schedule()} a {@see seedInstallationDefault()}). Obnova zachovává
     * systém zdroje, proto se pravidla změny ani výchozí forma instalace neuplatní; volající
     * ručí za to, že firma ještě nemá zápis v deníku. Běží v transakci volajícího, autor
     * řádku ze zdroje se nahradí aktérem obnovy, datum pořízení zůstává.
     *
     * @param list<array{valid_from:string, entry_form:string, note?:?string, created_at?:?string, created_by?:mixed}> $rows
     * @param array<string,mixed> $auditContext
     */
    public function restoreHistory(int $supplierId, array $rows, int $actor, array $auditContext = []): void
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Obnova historie formy zápisu vyžaduje transakci volajícího.');
        }
        $stmt = $pdo->prepare('SELECT valid_from, entry_form FROM accounting_entry_form_history WHERE supplier_id = ? ORDER BY valid_from FOR UPDATE');
        $stmt->execute([$supplierId]);
        $replaced = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($replaced === [] && $rows === []) {
            return;
        }
        $pdo->prepare('DELETE FROM accounting_entry_form_history WHERE supplier_id = ?')->execute([$supplierId]);
        $insert = $pdo->prepare(
            'INSERT INTO accounting_entry_form_history (supplier_id, valid_from, entry_form, created_by, created_at, note)
             VALUES (?, ?, ?, ?, COALESCE(?, CURRENT_TIMESTAMP), ?)'
        );
        foreach ($rows as $row) {
            $insert->execute([
                $supplierId,
                (string) $row['valid_from'],
                EntryForm::from((string) $row['entry_form'])->value,
                ($row['created_by'] ?? null) !== null && $actor > 0 ? $actor : null,
                $row['created_at'] ?? null,
                $row['note'] ?? null,
            ]);
        }
        $this->logger->log(
            self::AUDIT_ACTION,
            $actor > 0 ? $actor : null,
            'supplier',
            $supplierId,
            [
                ...$auditContext,
                'from' => self::effectiveChanges($replaced),
                'to'   => self::effectiveChanges($rows),
            ],
            supplierId: $supplierId,
        );
        $this->forget($supplierId);
    }

    /**
     * Smí firma zapnout jednoduchý zápis? Obchodní firmy a SVJ (i v rámci 504, i firma
     * s výslovně zapnutým modulem nemovitostí) ano; spolky a ostatní NNO (právní forma
     * nevýdělečné organizace nebo rámec 504 bez SVJ) zatím ne.
     */
    public function supportsSingle(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT legal_form, accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($supplier === false) {
            return false;
        }
        $profile = LegalFormProfileRecommendation::forLegalForm(
            isset($supplier['legal_form']) ? (string) $supplier['legal_form'] : null,
            isset($supplier['accounting_mode']) ? (string) $supplier['accounting_mode'] : null,
        )['entity_profile'];
        if ($profile === LegalFormProfileRecommendation::PROFILE_NONPROFIT) {
            return false;
        }
        if ($this->frameworkScope->allows($supplierId, FrameworkScopePolicy::SVJ)) {
            return true;
        }
        return !$this->frameworkScope->allows($supplierId, FrameworkScopePolicy::NONPROFIT_504);
    }

    /**
     * Zahodí sdílenou odpověď hasAnySingle() celého procesu. Pro kód (testy, převody),
     * který zapisuje historii formy mimo {@see schedule()}.
     */
    public static function forgetShared(?int $supplierId = null): void
    {
        if ($supplierId === null) {
            self::$anySingleShared = [];
            return;
        }
        unset(self::$anySingleShared[$supplierId]);
    }

    /** Zahodí paměť instance (po změně formy, v dlouho běžících workerech mezi dávkami). */
    public function forget(?int $supplierId = null): void
    {
        if ($supplierId === null) {
            $this->historyMemo = [];
            self::$anySingleShared = [];
            return;
        }
        unset($this->historyMemo[$supplierId], self::$anySingleShared[$supplierId]);
    }
}
