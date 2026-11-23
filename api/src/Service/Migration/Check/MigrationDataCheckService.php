<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Check;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Migration\Shared\MigrationCompanyLock;

/**
 * Spouští kontroly dat po převodu ({@see MigrationDataCheckRegistry}) pro API, CLI a MCP.
 *
 * Přehled a detail jen čtou. Oprava jde vždy přes náhled: volající pošle otisk náhledu
 * (`fingerprint`), oprava ho porovná s aktuálním stavem a při změně dat skončí 409, aby se
 * neprovedlo nic jiného, než co uživatel potvrdil. Opravy jedné firmy běží jedna po druhé
 * (zámek firmy), každá zapíše souhrn do auditní stopy navíc k záznamům jednotlivých změn.
 */
final class MigrationDataCheckService
{
    public const AUDIT_ACTION = 'migration_check.fixed';
    public const MAX_PER_PAGE = 200;

    public function __construct(
        private readonly MigrationDataCheckRegistry $registry,
        private readonly MigrationSources $sources,
        private readonly MigrationCompanyLock $lock,
        private readonly ActivityLogger $activity,
        private readonly Connection $db,
        private readonly MigrationCheckLocks $locks,
    ) {}

    /** @return array{sources:list<string>,checks:list<array<string,mixed>>} */
    public function summary(int $supplierId, ?string $only = null): array
    {
        $this->locks->reset();
        $checks = [];
        foreach ($this->registry->all() as $key => $check) {
            if (($only !== null && $only !== $key) || !$check->appliesTo($supplierId)) {
                continue;
            }
            try {
                $checks[] = $this->meta($check) + $check->detect($supplierId)->summary();
            } catch (\Throwable $e) {
                if (TransactionFailure::aborted($e)) {
                    throw $e;
                }
                error_log(sprintf('Kontrola dat po převodu %s firmy %d selhala: %s', $key, $supplierId, (string) $e));
                $checks[] = $this->meta($check) + ['status' => 'error', 'count' => 0, 'fixable_count' => 0, 'locked_count' => 0, 'review_count' => 0,
                    'amount_czk' => 0.0, 'fixable_amount_czk' => 0.0, 'notes' => ['Kontrola skončila chybou, podrobnosti jsou v logu serveru.']];
            }
        }
        return ['sources' => $this->sources->of($supplierId), 'checks' => $checks];
    }

    /**
     * Detail kontroly se stránkovaným seznamem nálezů (volitelně jen v jednom stavu).
     *
     * @return array<string,mixed>
     */
    public function detail(int $supplierId, string $key, int $page = 1, int $perPage = 50, ?string $state = null): array
    {
        $check = $this->check($supplierId, $key);
        if ($state !== null && !in_array($state, [CheckItem::FIXABLE, CheckItem::LOCKED, CheckItem::REVIEW], true)) {
            throw new MigrationDataCheckException('validation_failed', 'Neznámý stav nálezu.', 422);
        }
        $this->locks->reset();
        $page = max(1, $page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $result = $check->detect($supplierId);
        $items = $state === null ? $result->items : $result->itemsInState($state);
        return $this->meta($check) + $result->summary() + [
            'options' => $check instanceof FixableMigrationDataCheck ? $this->optionList($check) : [],
            'items' => array_map(static fn (CheckItem $i): array => $i->toArray(), array_slice($items, ($page - 1) * $perPage, $perPage)),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => count($items)],
        ];
    }

    /**
     * Náhled opravy. Opravy převodu Money S3 spočtou plán tak, že opravu provedou a vrátí
     * (stejný kód jako zápis), proto náhled běží v transakci, která se vždy vrátí.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function preview(int $supplierId, string $key, array $options = []): array
    {
        $check = $this->fixable($supplierId, $key);
        $options = $this->options($check, $options);
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        $own ? $pdo->beginTransaction() : $pdo->exec('SAVEPOINT migration_check_preview');
        $this->locks->reset();
        try {
            $preview = $check->preview($supplierId, $options)->toArray();
        } catch (\Throwable $e) {
            if (!TransactionFailure::rollbackIfAborted($pdo, $e) && $pdo->inTransaction()) {
                $own ? $pdo->rollBack() : $pdo->exec('ROLLBACK TO SAVEPOINT migration_check_preview');
            }
            throw $e;
        }
        $own ? $pdo->rollBack() : $pdo->exec('ROLLBACK TO SAVEPOINT migration_check_preview');
        return $this->meta($check) + $preview;
    }

    /**
     * Oprava v jedné transakci: zamkne stav období firmy (zámek k datu, účetní období), kontrola
     * spočte plán znovu, ověří otisk potvrzeného náhledu a zapíše tentýž plán.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function fix(int $supplierId, string $key, ?int $userId, array $options = [], ?string $fingerprint = null): array
    {
        $check = $this->fixable($supplierId, $key);
        $options = $this->options($check, $options);
        if (!$this->lock->acquire('migration_check', $supplierId)) {
            throw new MigrationDataCheckException('fix_running', 'Oprava dat této firmy už běží, zkuste to za chvíli.', 409);
        }
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        try {
            $own ? $pdo->beginTransaction() : $pdo->exec('SAVEPOINT migration_check_fix');
            $this->lockPeriodState($supplierId);
            $this->locks->reset();
            $result = $check->fix($supplierId, $userId, $options, $fingerprint);
            $this->activity->log(self::AUDIT_ACTION, $userId, 'supplier', $supplierId, [
                'check' => $key,
                'options' => $options,
                'fingerprint' => $fingerprint,
                'fixed' => count($result->fixed),
                'skipped' => count($result->skipped),
                'items' => array_map(static fn (CheckItem $i): array => ['type' => $i->entityType, 'id' => $i->entityId, 'label' => $i->label, 'amount_czk' => $i->amountCzk], $result->fixed),
            ], null, null, $supplierId);
            $own ? $pdo->commit() : $pdo->exec('RELEASE SAVEPOINT migration_check_fix');
        } catch (\Throwable $e) {
            if (!TransactionFailure::rollbackIfAborted($pdo, $e) && $pdo->inTransaction()) {
                $own ? $pdo->rollBack() : $pdo->exec('ROLLBACK TO SAVEPOINT migration_check_fix');
            }
            throw $e;
        } finally {
            $this->lock->release('migration_check', $supplierId);
        }
        $this->sources->reset();
        return $this->meta($check) + $result->toArray() + ['after' => $check->detect($supplierId)->summary()];
    }

    /**
     * Zamkne nastavení zámku k datu, účetní období a podání firmy do konce transakce opravy:
     * posun zámku, změna stavu období ani nové či označené podání (DPH, KH, OSS) nemůže
     * proběhnout mezi kontrolou období a zápisem. Podání se zamykají rozsahem indexu firmy
     * (zámky mezer v REPEATABLE READ), takže čeká i založení nového podání. Kontrola období
     * čte až po zamčení: první nezamykací čtení transakce vytvoří snímek až potom.
     */
    private function lockPeriodState(int $supplierId): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('SELECT supplier_id FROM accounting_supplier_settings WHERE supplier_id = ? FOR UPDATE')->execute([$supplierId]);
        $pdo->prepare('SELECT id FROM accounting_periods WHERE supplier_id = ? FOR UPDATE')->execute([$supplierId]);
        $pdo->prepare('SELECT id FROM tax_submissions FORCE INDEX (idx_supplier_form_period) WHERE supplier_id = ? FOR UPDATE')->execute([$supplierId]);
    }

    /** @return array<string,mixed> */
    private function meta(MigrationDataCheck $check): array
    {
        return [
            'key' => $check->key(),
            'title' => $check->title(),
            'fixable' => $check instanceof FixableMigrationDataCheck,
            'manual' => $check->manual(),
        ];
    }

    private function check(int $supplierId, string $key): MigrationDataCheck
    {
        $check = $this->registry->get($key);
        if ($check === null) {
            throw new MigrationDataCheckException('not_found', 'Kontrola neexistuje.', 404);
        }
        if (!$check->appliesTo($supplierId)) {
            throw new MigrationDataCheckException('not_applicable', 'Kontrola se této firmy netýká (firma nemá převod z daného programu).', 404);
        }
        return $check;
    }

    private function fixable(int $supplierId, string $key): FixableMigrationDataCheck
    {
        $check = $this->check($supplierId, $key);
        if (!$check instanceof FixableMigrationDataCheck) {
            throw new MigrationDataCheckException('not_fixable', 'Kontrola nemá jednoznačnou opravu, postup je v manuálu.', 409);
        }
        return $check;
    }

    /**
     * Volby opravy: jen ty, které kontrola zná, a jen jako ano/ne.
     *
     * @param array<string,mixed> $options
     * @return array<string,bool>
     */
    private function options(FixableMigrationDataCheck $check, array $options): array
    {
        $known = $check->options();
        $out = [];
        foreach ($options as $name => $value) {
            if (!is_string($name) || !isset($known[$name])) {
                throw new MigrationDataCheckException('validation_failed', sprintf('Kontrola nezná volbu %s.', (string) $name), 422);
            }
            if (!is_bool($value)) {
                throw new MigrationDataCheckException('validation_failed', sprintf('Volba %s musí být ano/ne.', $name), 422);
            }
            $out[$name] = $value;
        }
        return $out;
    }

    /** @return list<array{key:string,label:string}> */
    private function optionList(FixableMigrationDataCheck $check): array
    {
        $out = [];
        foreach ($check->options() as $key => $label) {
            $out[] = ['key' => $key, 'label' => $label];
        }
        return $out;
    }
}
