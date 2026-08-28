<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Repository\BankStatementOwnershipResolver;
use PDO;

/**
 * Propsání vlastního bankovního účtu z `currencies` do registru `supplier_bank_accounts`.
 *
 * PROČ: účet firmy se zadává na měně (`currencies.account_number` / `iban`), ale
 * účtování banky, analytika 221 i párování protistran čtou registr
 * `supplier_bank_accounts`. Ten se dosud plnil jen dvěma cestami — jednorázovým
 * backfillem v migraci 1053 a {@see SupplierBankAccountRepository::registerSeen()}
 * při importu výpisu. Firma založená po migraci tedy až do prvního importu výpisu
 * svůj vlastní účet v registru neměla: účtování banky nemělo na co mapovat
 * analytiku a příchozí platba z vlastního účtu se nepoznala jako vlastní.
 *
 * Statická metoda (vzor {@see \MyInvoice\Service\Vat\VatStatusService::seedInitialStatus()}),
 * aby šla volat i ze setup wizardu, kde ještě neběží plný DI kontejner nad hotovým
 * supplierem.
 *
 * ⚠️ Claim guard se dělá TADY, ne jen u volajícího. Vstupní kontrola v Nastavení
 * ({@see \MyInvoice\Action\Settings\SettingsAction::rejectForeignBankAccount()}) se
 * spustí jen tehdy, když tělo požadavku nese `account_number` nebo `iban` — PATCH,
 * který mění jen `bank_code`/`bank_name`/`bic`, jí propadne, přestože na měně
 * uložený účet do registru propsat chceme. Bez vlastní kontroly by tak na legacy
 * datech, kde týž účet drží dvě firmy, vznikl druhý řádek registru a
 * {@see \MyInvoice\Service\Bank\StatementImporter} by pak u toho účtu viděl dva
 * vlastníky a přestal výpisy přiřazovat.
 */
final class OwnBankAccountRegistrar
{
    /**
     * Zaeviduje účet z jednoho řádku `currencies`. Idempotentní — opakované volání
     * osvěží jen měnu a vazbu na měnu.
     *
     * @return bool true, pokud řádek registru vznikl nebo se aktualizoval
     */
    public static function syncFromCurrency(
        PDO $pdo,
        int $supplierId,
        int $currencyId,
        BankStatementOwnershipResolver $ownership,
    ): bool {
        $stmt = $pdo->prepare(
            'SELECT id, code, label, account_number, bank_code, iban
               FROM currencies WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$currencyId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return false;
        }

        $account = trim((string) ($row['account_number'] ?? ''));
        $iban    = trim((string) ($row['iban'] ?? ''));

        $canonical = AccountNumberNormalizer::canonical(
            $account !== '' ? $account : null,
            $iban !== '' ? $iban : null,
        );
        if ($canonical === null) {
            return false;
        }

        // Účet, který si už nárokuje jiná firma, do registru nepatří — stejná pojistka
        // jako v registerSeen(). Bez ní by u téhož účtu vznikli dva vlastníci a import
        // výpisů by ho přestal přiřazovat vůbec.
        if ($ownership->accountClaimedByOtherSupplier(
            $supplierId,
            $account !== '' ? $account : null,
            $iban !== '' ? $iban : null,
        )) {
            return false;
        }

        // Kód banky ZÁMĚRNĚ bez IBAN fallbacku — shodně s registerSeen(). `bank_code_norm`
        // se porovnává s `bank_statements.bank_code`, které import plní z téhož
        // `currencies.bank_code`; dopočet z IBANu by registru dal kód, jaký výpis nenese,
        // a první import by kvůli jinému UNIQUE klíči založil druhý řádek na týž účet.
        $bank = AccountNumberNormalizer::canonicalBankCode($row['bank_code'] ?? null);

        $label = trim((string) ($row['label'] ?? ''));
        if ($label === '') {
            $label = 'Účet ' . ($account !== '' ? $account : $iban);
        }

        $pdo->prepare(
            'INSERT INTO supplier_bank_accounts
                (supplier_id, currency_id, label, account_number, bank_code, bank_code_norm,
                 iban, currency, account_canonical, kind, source, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "current", "currencies", 1)
             ON DUPLICATE KEY UPDATE
                currency_id    = VALUES(currency_id),
                account_number = COALESCE(VALUES(account_number), account_number),
                iban           = COALESCE(VALUES(iban), iban),
                currency       = COALESCE(VALUES(currency), currency)'
            // `label` ani `is_active` se ZÁMĚRNĚ nepřepisují (shodně s registerSeen()):
            // obojí je uživatelské rozhodnutí učiněné přes PATCH /accounting/bank-accounts/{id}.
            // Jinak by přejmenovaný účet dostal zpátky popisek měny a vyřazený účet by
            // ožil při každé editaci bankovních polí na měně — a rovnou by se vrátil do
            // párování vlastních převodů a přidělování analytiky 221.
        )->execute([
            $supplierId,
            (int) $row['id'],
            $label,
            $account !== '' ? $account : null,
            $bank,
            $bank ?? '',
            $iban !== '' ? $iban : null,
            strtoupper((string) ($row['code'] ?? '')) ?: null,
            $canonical,
        ]);

        return true;
    }

    /**
     * Projde všechny měny firmy a zaeviduje ty, které nesou číslo účtu nebo IBAN.
     *
     * Starý řádek registru se při změně účtu ZÁMĚRNĚ needituje ani neruší: váže se
     * na něj analytika 221 a naimportované výpisy. Registr je evidence účtů, které
     * firma kdy měla — vyřadit se dá přes PATCH /accounting/bank-accounts/{id}.
     */
    public static function syncSupplier(
        PDO $pdo,
        int $supplierId,
        BankStatementOwnershipResolver $ownership,
    ): int {
        $stmt = $pdo->prepare(
            "SELECT id FROM currencies
              WHERE supplier_id = ?
                AND (COALESCE(account_number, '') <> '' OR COALESCE(iban, '') <> '')"
        );
        $stmt->execute([$supplierId]);

        $synced = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $currencyId) {
            if (self::syncFromCurrency($pdo, $supplierId, (int) $currencyId, $ownership)) {
                $synced++;
            }
        }

        return $synced;
    }
}
