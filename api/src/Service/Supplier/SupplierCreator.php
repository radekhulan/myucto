<?php

declare(strict_types=1);

namespace MyInvoice\Service\Supplier;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\License\LicenseCapacityGate;

/**
 * Založení další firmy: řádek `supplier`, výchozí měny, volitelný bankovní účet,
 * inicializace ze {@see SupplierInitializer::seedWithinInsert()} a členství zakladatele,
 * atomicky a pod licenčním limitem počtu firem.
 *
 * Volá ho zakládání firmy v aplikaci ({@see \MyInvoice\Action\Settings\SettingsAction::createSupplier()})
 * i dávkový převod z cizího programu, který firmu zakládá podle zálohy. Validace vstupu
 * a kontrola cizího bankovního účtu (SEC-01) zůstávají u volajícího; obohacení z registrů
 * ({@see SupplierInitializer::completeAfterCommit()}) volá volající až po návratu, mimo
 * transakci zakládání.
 *
 * Uvnitř cizí transakce běží přes savepoint (zkouška převodu nanečisto celou firmu
 * na konci vrátí).
 */
final class SupplierCreator
{
    public function __construct(
        private readonly Connection $db,
        private readonly SupplierInitializer $supplierInitializer,
        private readonly LicenseCapacityGate $licenseCapacity,
    ) {}

    /**
     * @param array<string,mixed> $b vstup zakládání (company_name, street, city, zip, email, ic,
     *                               dic, is_vat_payer, is_identified, taxpayer_type, vat_period,
     *                               bank_account, …)
     * @return int id nové firmy
     * @throws \MyInvoice\Service\License\LicenseCompanyLimitExceeded
     */
    public function create(array $b, int $creatorUserId, bool $assignCreator): int
    {
        $b['person_title'] = SupplierPersonTitle::normalize($b['person_title'] ?? null);
        $pdo = $this->db->pdo();

        // Country (default CZ)
        $countryIso = strtoupper((string) ($b['country_iso2'] ?? 'CZ'));
        $stmtCountry = $pdo->prepare('SELECT id FROM countries WHERE iso2 = ?');
        $stmtCountry->execute([$countryIso]);
        $countryId = (int) $stmtCountry->fetchColumn();
        if ($countryId === 0) $countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ'")->fetchColumn();

        $defaultVatId = (int) $pdo->query("SELECT id FROM vat_rates WHERE is_default = 1 ORDER BY id LIMIT 1")->fetchColumn()
            ?: (int) $pdo->query("SELECT id FROM vat_rates ORDER BY id LIMIT 1")->fetchColumn();

        $isIdentified = !empty($b['is_identified']);
        $isVatPayer = self::isVatPayer($b);
        // Režim účetnictví a zdaňovací období stejně jako prvotní setup.
        $taxpayerType = SupplierInitializer::taxpayerType($b);
        $accountingMode = SupplierInitializer::accountingMode($taxpayerType);
        $vatPeriod = SupplierInitializer::vatPeriod($isVatPayer, $b['vat_period'] ?? null);

        $fkSuspended = false;
        return $this->licenseCapacity->createCompany(function () use (
            $pdo,
            $b,
            $countryId,
            $defaultVatId,
            $creatorUserId,
            $assignCreator,
            $isIdentified,
            $isVatPayer,
            $taxpayerType,
            $accountingMode,
            $vatPeriod,
            &$fkSuspended,
        ): int {
            $ownsTransaction = !$pdo->inTransaction();
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            } else {
                $pdo->exec('SAVEPOINT create_supplier');
            }
            try {
                // 1. Insert supplier (default_currency_id placeholder, opravíme po insertu currencies).
                //    Cyklický FK supplier.default_currency_id ↔ currencies.supplier_id: pokud už existuje
                //    nějaká currency (alespoň jeden supplier v DB), použijeme ji jako bootstrap placeholder.
                //    Při prvním supplier po deferred-supplier setupu currencies tabulka je prázdná
                //    → fallback na SET FOREIGN_KEY_CHECKS = 0 (stejný trik jako SetupAction::insertSupplier).
                $bootstrapCurId = (int) $pdo->query("SELECT id FROM currencies ORDER BY id LIMIT 1")->fetchColumn();
                if ($bootstrapCurId === 0) {
                    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                    $fkSuspended = true;
                }

                $stmt = $pdo->prepare(
                    'INSERT INTO supplier (company_name, display_name, street, city, zip, country_id,
                                           ic, dic, is_vat_payer, is_identified, email, phone, web, tagline, commercial_register,
                                           taxpayer_type, accounting_mode, vat_period, person_title,
                                           default_currency_id, default_vat_rate_id,
                                           default_payment_due_days, default_payment_due_unit, default_hourly_rate)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    (string) $b['company_name'],
                    self::nullable($b, 'display_name') ?: (string) $b['company_name'],
                    (string) $b['street'],
                    (string) $b['city'],
                    (string) $b['zip'],
                    $countryId,
                    self::nullable($b, 'ic'),
                    self::nullable($b, 'dic'),
                    $isVatPayer ? 1 : 0,
                    $isIdentified ? 1 : 0,
                    (string) $b['email'],
                    self::nullable($b, 'phone'),
                    self::nullable($b, 'web'),
                    self::nullable($b, 'tagline'),
                    self::nullable($b, 'commercial_register'),
                    $taxpayerType,
                    $accountingMode,
                    $vatPeriod,
                    $b['person_title'],
                    $bootstrapCurId ?: 0,
                    $defaultVatId ?: 1,
                    (int) ($b['default_payment_due_days'] ?? 14),
                    in_array($b['default_payment_due_unit'] ?? null, ['days', 'month'], true)
                        ? (string) $b['default_payment_due_unit']
                        : 'days',
                    (float) ($b['default_hourly_rate'] ?? 1500.00),
                ]);
                $newSupplierId = (int) $pdo->lastInsertId();

                // 2. Seed default currencies pro nového supplier (CZK + EUR, bez bank polí)
                $insertCur = $pdo->prepare(
                    'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1)'
                );
                $insertCur->execute([$newSupplierId, 'CZK', 'CZK — výchozí', 'Kč', 'Česká koruna', 'Czech Koruna', 2]);
                $newDefaultCurId = (int) $pdo->lastInsertId();
                $insertCur->execute([$newSupplierId, 'EUR', 'EUR — výchozí', '€', 'Euro', 'Euro', 2]);
                $newEurCurId = (int) $pdo->lastInsertId();

                // 2b. Volitelný bankovní účet (např. načtený z registru plátců DPH) → na seeded měnu.
                $bank = isset($b['bank_account']) && is_array($b['bank_account']) ? $b['bank_account'] : null;
                if ($bank !== null) {
                    $bankCcy = strtoupper((string) ($bank['currency'] ?? 'CZK'));
                    $targetCurId = $bankCcy === 'EUR' ? $newEurCurId : $newDefaultCurId;
                    $pdo->prepare(
                        'UPDATE currencies SET account_number = ?, bank_code = ?, bank_name = ?, iban = ?, bic = ? WHERE id = ?'
                    )->execute([
                        self::nullable($bank, 'account_number'),
                        self::nullable($bank, 'bank_code'),
                        self::nullable($bank, 'bank_name'),
                        self::nullable($bank, 'iban'),
                        self::nullable($bank, 'bic'),
                        $targetCurId,
                    ]);
                }

                // 3. Update supplier.default_currency_id na CZK supplier
                $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')
                    ->execute([$newDefaultCurId, $newSupplierId]);

                if ($fkSuspended) {
                    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                    $fkSuspended = false;
                }

                // 4. Stejná inicializace jako prvotní setup: historie plátcovství
                //    a účetního režimu, směrná osnova, registr vlastních účtů,
                //    šablony bankovních pravidel — atomicky s firmou.
                $this->supplierInitializer->seedWithinInsert(
                    $pdo,
                    $newSupplierId,
                    $accountingMode,
                    $isVatPayer,
                    $isIdentified,
                    $creatorUserId > 0 ? $creatorUserId : null,
                );
                if ($assignCreator) {
                    $pdo->prepare(
                        'INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, NULL)'
                    )->execute([$creatorUserId, $newSupplierId]);
                }
                if ($ownsTransaction) {
                    $pdo->commit();
                } else {
                    $pdo->exec('RELEASE SAVEPOINT create_supplier');
                }
                return $newSupplierId;
            } catch (\Throwable $e) {
                if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                    throw $e;
                }
                if ($ownsTransaction) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                } else {
                    $pdo->exec('ROLLBACK TO SAVEPOINT create_supplier');
                    $pdo->exec('RELEASE SAVEPOINT create_supplier');
                }
                if ($fkSuspended) {
                    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                    $fkSuspended = false;
                }
                throw $e;
            }
        });
    }

    /**
     * Plátcovství: výslovná volba formuláře vyhrává, bez ní heuristika „má DIČ →
     * je plátce". Neplatí pro identifikovanou osobu (§ 6g–6l, issue #94) — IO má
     * DIČ, ale plátce není.
     *
     * @param array<string,mixed> $b
     */
    public static function isVatPayer(array $b): bool
    {
        return empty($b['is_identified']) && (array_key_exists('is_vat_payer', $b)
            ? !empty($b['is_vat_payer'])
            : !empty($b['dic']));
    }

    /** @param array<string,mixed> $b */
    private static function nullable(array $b, string $key): ?string
    {
        $v = trim((string) ($b[$key] ?? ''));
        return $v === '' ? null : $v;
    }
}
