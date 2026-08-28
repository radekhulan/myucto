<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Service\Invoice\ProformaPaymentDocuments;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\OwnBankAccountRegistrar;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\License\LicenseCapacityGate;
use MyInvoice\Service\License\LicenseCompanyLimitExceeded;
use MyInvoice\Service\Mail\RecipientResolver;
use MyInvoice\Service\Mail\SafeLogoPath;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Settings — multi-supplier + currencies (per-supplier bankovní účty).
 *
 *   GET  /api/settings/supplier             — aktuální supplier (X-Supplier-Id)
 *   PUT  /api/settings/supplier             — update aktuálního (admin)
 *   GET  /api/suppliers                     — list všech (pro switcher)
 *   POST /api/suppliers                     — nový supplier (admin)
 *   GET  /api/suppliers/{id}                — detail
 *   PUT  /api/suppliers/{id}                — update (admin)
 *   DELETE /api/suppliers/{id}              — smaz (admin, jen pokud nemá data)
 *   GET  /api/settings/currencies           — currencies aktuálního supplier
 *   PUT  /api/settings/currencies/{id}      — update (admin, jen vlastní supplier)
 */
final class SettingsAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly InvoicePdfRenderer $pdf,
        private readonly Config $config,
        private readonly \MyInvoice\Service\Ares\SupplierRegistryEnricher $enricher,
        private readonly \MyInvoice\Repository\UserSupplierRepository $userSuppliers,
        // Epic F1: při zapnutí podvojného účetnictví naseedujeme směrnou osnovu.
        private readonly \MyInvoice\Service\Accounting\ChartOfAccountsSeeder $coaSeeder,
        private readonly \MyInvoice\Repository\AccountingModeRepository $accountingModes,
        private readonly \MyInvoice\Service\Accounting\Activation\PendingBackfillCounter $pendingBackfill,
        private readonly \MyInvoice\Service\Invoice\VarsymbolSeriesCollisionChecker $seriesCollisions,
        // SEC-01: brání „nárokování" cizího bankovního účtu do currencies.
        private readonly \MyInvoice\Repository\BankStatementOwnershipResolver $bankOwnership,
        // E4: atomický licenční limit počtu firem při zakládání dodavatele.
        private readonly LicenseCapacityGate $licenseCapacity,
        // VH-01: sdílená zápisová cesta do supplier_vat_status_history.
        private readonly \MyInvoice\Service\Vat\VatStatusService $vatStatus,
        private readonly \MyInvoice\Service\Vat\VatStatusGuard $vatStatusGuard,
        // MZ-03: legacy identifikátory odvodů se u PO nulují jen proti zapnutému mzdovému
        // modulu — s vypnutými Mzdami jsou jediným zdrojem (viz updateSupplier()).
        private readonly \MyInvoice\Service\Payroll\PayrollModuleAccess $payrollAccess,
    ) {}

    /**
     * SEC-01: číslo účtu (resp. IBAN) evidované jinou firmou nesmí jít uložit.
     * Vlastní účet nemohou mít dvě firmy současně a útok na bankovní výpisy
     * začínal právě tím, že si útočník do své měny zapsal cizí (z faktur veřejně
     * známé) číslo účtu. Vrací chybovou odpověď, nebo NULL když je vše v pořádku.
     *
     * @param array<string,mixed> $body
     */
    private function rejectForeignBankAccount(Request $request, Response $response, int $sid, array $body): ?Response
    {
        if (!array_key_exists('account_number', $body) && !array_key_exists('iban', $body)) {
            return null;
        }
        $account = trim((string) ($body['account_number'] ?? ''));
        $iban    = trim((string) ($body['iban'] ?? ''));
        $acc  = $account !== '' ? $account : null;
        $ibn  = $iban !== '' ? $iban : null;

        if ($this->bankOwnership->accountClaimedByOtherSupplier($sid, $acc, $ibn)) {
            $this->log($request, 'currency.foreign_account_rejected', null, [
                'account_number' => $account,
                'iban'           => $iban,
                'reason'         => 'claimed_by_other_supplier',
            ]);

            return Json::error(
                $response,
                'account_claimed',
                'Tento bankovní účet už je evidovaný u jiné firmy. Opravte číslo účtu, nebo ho nejdřív odeberte tam.',
                409,
            );
        }

        // SEC-01 (2. kolo): účet zatím nikdo nemá v currencies, ale v DB k němu leží
        // výpisy cizí/nejasné firmy → nesmí si ho zabrat kdokoliv kdo přijde první.
        if ($this->bankOwnership->accountBlockedByForeignStatements($sid, $acc, $ibn)) {
            $this->log($request, 'currency.foreign_account_rejected', null, [
                'account_number' => $account,
                'iban'           => $iban,
                'reason'         => 'foreign_statements',
            ]);

            return Json::error(
                $response,
                'account_has_foreign_statements',
                'K tomuto účtu jsou v systému bankovní výpisy jiné firmy. Přiřazení účtu musí potvrdit správce.',
                409,
            );
        }

        return null;
    }

    /**
     * Varianta {@see rejectForeignBankAccount()} pro zakládání firmy, kde bankovní
     * účet chodí zanořený v `bank_account` a supplier ještě nemá id (porovnává se
     * proti všem firmám). SEC-01, 2. kolo: bez tohohle šlo guard v updateCurrency
     * obejít prostým POST /api/suppliers s cizím účtem.
     *
     * @param array<string,mixed> $body
     */
    private function rejectForeignBankAccountOnCreate(Request $request, Response $response, array $body): ?Response
    {
        $bank = isset($body['bank_account']) && is_array($body['bank_account']) ? $body['bank_account'] : null;
        if ($bank === null) {
            return null;
        }

        return $this->rejectForeignBankAccount($request, $response, 0, [
            'account_number' => (string) ($bank['account_number'] ?? ''),
            'iban'           => (string) ($bank['iban'] ?? ''),
        ]);
    }

    /** Aktuální supplier (z X-Supplier-Id middleware). */
    public function getSupplier(Request $request, Response $response): Response
    {
        $id = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        return $this->respondSupplier($response, $id);
    }

    /** Update aktuálního supplier (admin). */
    public function updateSupplier(Request $request, Response $response): Response
    {
        $id = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        return $this->updateSupplierById($request, $response, ['id' => (string) $id]);
    }

    /** GET /api/suppliers — list pro switcher. Epic F0: uživatel s membership vidí jen přiřazené firmy. */
    public function listSuppliers(Request $request, Response $response): Response
    {
        $user    = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $boundSupplierId = $this->boundSupplierId($request);
        // Bound PAT je autoritativní i pro globálního admina. Bez omezení zde by
        // token vydaný pro jedinou firmu přes switcher vypsal všechny tenanty.
        $allowed = $boundSupplierId !== null
            ? [$boundSupplierId]
            : (RequestAuthorization::isSuperadmin($request)
                ? []
                : $this->userSuppliers->allowedSupplierIds((int) ($user['id'] ?? 0)));
        // Epic F6 (H3): client bez membershipu = prázdný seznam (fail-closed),
        // ne fail-open "bez omezení" jako u legacy rolí.
        if (RequestAuthorization::isClientType($request) && $allowed === []) {
            return Json::ok($response, []);
        }
        $where   = '';
        $params  = [];
        if ($allowed !== []) {
            $where  = ' WHERE s.id IN (' . implode(',', array_fill(0, count($allowed), '?')) . ')';
            $params = $allowed;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.id, s.company_name, s.display_name, s.ic, s.dic, s.is_vat_payer,
                    s.email, s.accounting_mode, c.iso2 AS country_iso,
                    (SELECT COUNT(*) FROM clients cl  WHERE cl.supplier_id  = s.id) AS clients_count,
                    (SELECT COUNT(*) FROM invoices i  WHERE i.supplier_id   = s.id) AS invoices_count
               FROM supplier s
               JOIN countries c ON c.id = s.country_id'
            . $where .
            ' ORDER BY s.id'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id']             = (int) $r['id'];
            $r['is_vat_payer']   = (bool) $r['is_vat_payer'];
            $r['clients_count']  = (int) $r['clients_count'];
            $r['invoices_count'] = (int) $r['invoices_count'];
        }
        return Json::ok($response, $rows);
    }

    /**
     * GET /api/settings/mode-switch-preview — kolik historických dokladů (pokladna,
     * vydané/přijaté faktury) čeká na doúčtování do deníku (audit 2026-07, nález G5).
     * Slouží FE confirm dialogu PŘED přepnutím accounting_mode na 'double_entry' —
     * počty platí bez ohledu na aktuální režim firmy (co by po přepnutí zbylo
     * nedoúčtované). Backfill samotný běží mimo web (CLI skripty
     * api/bin/backfill-accounting.php a api/bin/backfill-cash-accounting.php).
     */
    public function modeSwitchPreview(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $id = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($id <= 0) return Json::error($response, 'not_found', 'Supplier nenalezen.', 404);
        return Json::ok($response, $this->pendingAccountingBackfillCounts($id));
    }

    /**
     * @return array{cash_documents:int, invoices:int, purchase_invoices:int, bank_transactions:int, total:int}
     */
    private function pendingAccountingBackfillCounts(int $supplierId): array
    {
        return $this->pendingBackfill->count($supplierId);
    }

    /** GET /api/suppliers/{id}. Epic F0: firma mimo membership → 404 (konvence pro cizí entity). */
    public function getSupplierById(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($this->membershipDenies($request, $id)) {
            return Json::error($response, 'not_found', 'Supplier nenalezen.', 404);
        }
        return $this->respondSupplier($response, $id);
    }

    /** Epic F0: true = supplier je mimo bound PAT nebo membership. Nebound globální admin vidí vše. */
    private function membershipDenies(Request $request, int $supplierId): bool
    {
        $boundSupplierId = $this->boundSupplierId($request);
        if ($boundSupplierId !== null) {
            return $supplierId !== $boundSupplierId;
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (RequestAuthorization::isSuperadmin($request)) return false;
        $allowed = $this->userSuppliers->allowedSupplierIds((int) ($user['id'] ?? 0));
        // Epic F6 (H3): role 'client' je fail-closed — bez membershipu nevidí nic.
        if (RequestAuthorization::isClientType($request) && $allowed === []) {
            return true;
        }
        return $allowed !== [] && !in_array($supplierId, $allowed, true);
    }

    private function boundSupplierId(Request $request): ?int
    {
        $apiToken = $request->getAttribute(AuthMiddleware::ATTR_API_TOKEN);
        if (!is_array($apiToken) || ($apiToken['supplier_id'] ?? null) === null) {
            return null;
        }
        $supplierId = (int) $apiToken['supplier_id'];
        return $supplierId > 0 ? $supplierId : null;
    }

    /** POST /api/suppliers — nový supplier (admin). */
    public function createSupplier(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;

        $b = (array) ($request->getParsedBody() ?? []);

        $required = ['company_name', 'street', 'city', 'zip', 'email'];
        foreach ($required as $f) {
            if (trim((string) ($b[$f] ?? '')) === '') {
                return Json::error($response, 'validation_failed', "Pole '$f' je povinné.", 400);
            }
        }

        // SEC-01: cizí bankovní účet nesmí projít ani přes zakládání nové firmy
        // (createSupplier píše do currencies tytéž sloupce jako updateCurrency).
        if (($claimed = $this->rejectForeignBankAccountOnCreate($request, $response, $b)) !== null) {
            return $claimed;
        }

        $pdo = $this->db->pdo();

        // Country (default CZ)
        $countryIso = strtoupper((string) ($b['country_iso2'] ?? 'CZ'));
        $stmtCountry = $pdo->prepare('SELECT id FROM countries WHERE iso2 = ?');
        $stmtCountry->execute([$countryIso]);
        $countryId = (int) $stmtCountry->fetchColumn();
        if ($countryId === 0) $countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ'")->fetchColumn();

        $defaultVatId = (int) $pdo->query("SELECT id FROM vat_rates WHERE is_default = 1 ORDER BY id LIMIT 1")->fetchColumn()
            ?: (int) $pdo->query("SELECT id FROM vat_rates ORDER BY id LIMIT 1")->fetchColumn();

        $fkSuspended = false;
        try {
            $newSupplierId = $this->licenseCapacity->createCompany(function () use (
                $pdo,
                $b,
                $countryId,
                $defaultVatId,
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
                                               ic, dic, is_vat_payer, is_identified, email, phone, web, tagline, commercial_register, taxpayer_type,
                                               default_currency_id, default_vat_rate_id,
                                               default_payment_due_days, default_hourly_rate)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                    );
                    // Heuristika „má DIČ → je plátce" neplatí pro identifikovanou osobu
                    // (§ 6g–6l, issue #94) — IO má DIČ, ale plátce není.
                    $isIdentified = !empty($b['is_identified']);
                    $stmt->execute([
                        (string) $b['company_name'],
                        $this->nullable($b, 'display_name') ?: (string) $b['company_name'],
                        (string) $b['street'],
                        (string) $b['city'],
                        (string) $b['zip'],
                        $countryId,
                        $this->nullable($b, 'ic'),
                        $this->nullable($b, 'dic'),
                        $isIdentified ? 0 : (!empty($b['is_vat_payer']) ? 1 : (!empty($b['dic']) ? 1 : 0)),
                        $isIdentified ? 1 : 0,
                        (string) $b['email'],
                        $this->nullable($b, 'phone'),
                        $this->nullable($b, 'web'),
                        $this->nullable($b, 'tagline'),
                        $this->nullable($b, 'commercial_register'),
                        in_array($b['taxpayer_type'] ?? null, ['fo', 'po'], true) ? (string) $b['taxpayer_type'] : null,
                        $bootstrapCurId ?: 0,
                        $defaultVatId ?: 1,
                        (int) ($b['default_payment_due_days'] ?? 14),
                        (float) ($b['default_hourly_rate'] ?? 1500.00),
                    ]);
                    $newSupplierId = (int) $pdo->lastInsertId();
                    \MyInvoice\Service\Vat\VatStatusService::seedInitialStatus(
                        $pdo,
                        $newSupplierId,
                        $isIdentified ? false : (!empty($b['is_vat_payer']) || !empty($b['dic'])),
                        $isIdentified,
                    );

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
                            $this->nullable($bank, 'account_number'),
                            $this->nullable($bank, 'bank_code'),
                            $this->nullable($bank, 'bank_name'),
                            $this->nullable($bank, 'iban'),
                            $this->nullable($bank, 'bic'),
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

                    // 4. Historie účetního režimu a registr vlastních účtů — stejný důvod
                    //    jako v SetupAction::insertSupplier(): seed migrace 1066 ani backfill
                    //    migrace 1053 se na firmu založenou později nevztahují, takže by
                    //    zůstala bez záznamu o režimu a bez vlastního účtu v registru.
                    $newMode = (string) $pdo->query(
                        'SELECT accounting_mode FROM supplier WHERE id = ' . (int) $newSupplierId
                    )->fetchColumn();
                    $this->accountingModes->record($newSupplierId, '1900-01-01', $newMode);
                    OwnBankAccountRegistrar::syncSupplier($pdo, $newSupplierId);
                    if ($ownsTransaction) {
                        $pdo->commit();
                    } else {
                        $pdo->exec('RELEASE SAVEPOINT create_supplier');
                    }
                    return $newSupplierId;
                } catch (\Throwable $e) {
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
        } catch (LicenseCompanyLimitExceeded) {
            return Json::error($response, 'license_company_limit',
                'Byl dosažen počet firem podle vaší licence. Rozšiřte předplatné na myucto.cz.', 403);
        } catch (\Throwable $e) {
            return Json::error($response, 'create_failed', 'Vytvoření supplier selhalo: ' . $e->getMessage(), 500);
        }

        // Po commitu (mimo DB transakci — síťové volání): doplň z veřejných registrů,
        // co jde (čísla domu, NACE, spisová značka, typ poplatníka, kód FÚ).
        $this->enricher->enrich($newSupplierId, $b['ic'] ?? null, $b['dic'] ?? null);

        $this->log($request, 'supplier.created', $newSupplierId, ['company_name' => $b['company_name'], 'ic' => $b['ic'] ?? null]);
        return Json::ok($response, ['id' => $newSupplierId], 201);
    }

    /** PUT /api/suppliers/{id} (admin; výjimka stock_enabled/stock_auto_issue níže). */
    public function updateSupplierById(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) return Json::error($response, 'validation_failed', 'Neplatné id.', 400);

        $body = (array) ($request->getParsedBody() ?? []);

        if (!$this->db->hasColumn('supplier', 'oss_enabled')) {
            $ossFields = ['oss_enabled', 'oss_valid_from', 'oss_valid_to', 'oss_identification_country', 'oss_return_currency'];
            $wantsOss = !empty($body['oss_enabled']);
            foreach (['oss_valid_from', 'oss_valid_to', 'oss_identification_country'] as $field) {
                if (trim((string) ($body[$field] ?? '')) !== '') {
                    $wantsOss = true;
                }
            }
            if ($wantsOss) {
                return Json::error($response, 'migration_required',
                    'Nastavení OSS vyžaduje databázovou migraci 0137_oss_foundation.sql. Spusťte php api/bin/migrate.php.', 409);
            }
            foreach ($ossFields as $field) {
                unset($body[$field]);
            }
        }

        // Sklad (Epic SKLAD): stock_enabled/stock_auto_issue/stock_in_transit_from smí
        // přepínat i účetní, ne jen admin — cílený bypass guard() JEN pro tato pole
        // (least-invasive: guard() zůstává admin-only pro všechno ostatní; sem se
        // accountant dostane pouze pokud body neobsahuje NIC jiného než tato pole).
        $stockOnlyFields = ['stock_enabled', 'stock_auto_issue', 'stock_in_transit_from'];
        $isStockOnlyUpdate = $body !== [] && array_diff(array_keys($body), $stockOnlyFields) === [];
        if ($isStockOnlyUpdate) {
            if (!RequestAuthorization::allows($request, 'stock', AccessLevel::WRITE)) {
                return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
            }
        } elseif (!$this->guard($request, $response, $err)) {
            return $err;
        }

        if ($this->membershipDenies($request, $id)) {
            return Json::error($response, 'not_found', 'Supplier nenalezen.', 404);
        }

        $allowed = [
            'company_name', 'display_name', 'street', 'city', 'zip', 'country_id',
            'ic', 'dic', 'is_vat_payer', 'is_identified', 'email', 'phone', 'web', 'tagline', 'commercial_register',
            'default_currency_id', 'default_vat_rate_id', 'default_payment_due_days', 'default_payment_due_unit',
            // logo_path / signature_path se NIKDY nemění přes mass-assignment — jen přes
            // dedikované endpointy EmailBrandingAction::uploadLogo (multipart, processed by
            // SupplierLogoConverter do storage/branding/sup-N/). Mass-assign by umožnil
            // admin-planted LFI (security report @andrejtomci #2).
            'default_hourly_rate', 'auto_send_reminders', 'reminder_days_after_due', 'auto_generate_recurring', 'embed_isdoc',
            'default_prices_include_vat',
            // Pohoda kódy; `pohoda_accounting_code` = předkontace (migrace 1376) — bez ní
            // si Pohoda po importu dosadí vlastní default a doklad se zaúčtuje jinam.
            'pohoda_account_code', 'pohoda_centre_code', 'pohoda_activity_code', 'pohoda_contract_code',
            'pohoda_accounting_code',
            // Per-supplier konfigurace číslování faktur (migrace 0014; přijaté 0095)
            'invoice_number_format', 'proforma_number_format', 'credit_note_number_format',
            'purchase_invoice_number_format',
            'invoice_number_period',
            // Per-supplier branding emailů (migrace 0016) + PDF logo+název (migrace 0058)
            'email_branding_enabled', 'email_accent_color', 'pdf_logo_show_name', 'branding_profiles_enabled',
            // Tax settings pro EPO výkazy (migrace 0038, fáze 6)
            'taxpayer_type', 'vat_period', 'financial_office_code', 'workplace_code',
            'cz_nace_code', 'data_box_type', 'data_box_id', 'flat_tax_band',
            'oss_enabled', 'oss_valid_from', 'oss_valid_to', 'oss_identification_country', 'oss_return_currency',
            // Identifikátory ČSSZ/ZP pro přehled OSVČ (Epic DP v2, migrace 1032)
            'cssz_vsdp', 'cssz_ossz_code', 'health_insurance_number',
            'sest_jmeno', 'sest_prijmeni', 'sest_telefon', 'sest_email', 'sest_funkce',
            // Doplňky pro DPH/KH XML VetaP (migrace 0043)
            'street_number_pop', 'street_number_orient',
            'opr_jmeno', 'opr_prijmeni', 'opr_postaveni',
            // Děkovný e-mail za úhradu (issue #57)
            'payment_thanks_enabled', 'payment_thanks_auto_send', 'payment_thanks_default_checked', 'payment_thanks_attach_paid_pdf',
            // Kopie odchozích e-mailů dodavateli (migrace 0102) — JSON, validace níže
            'self_copy',
            // Režim účetnictví (Epic F0, migrace 1001) — daňová evidence vs podvojné
            'accounting_mode',
            // „Vést účetnictví" (migrace 1179) — opt-out účetní nadstavby v menu.
            'accounting_enabled',
            // „Vést mzdy" (migrace 1187) — výchozí opt-in modulu, bez vlivu na licenci.
            'payroll_enabled',
            // Doklad po úhradě proformy (issue #39, migrace 1565) — rychlý prodej vs.
            // zakázková výroba; výchozí hodnota drží dnešní chování.
            'proforma_payment_document',
            // Sklad (Epic SKLAD, migrace 1023) — opt-in modul evidence zásob + auto-výdejka
            // při vystavení FV; smí přepínat i účetní (viz bypass guard() výše).
            // `stock_in_transit_from` (migrace 1331) rozhoduje, od kterého stavu objednávky
            // se zboží počítá „na cestě" — čte ho InTransitRepository::inTransitStates().
            'stock_enabled', 'stock_auto_issue', 'stock_in_transit_from',
            // Auto-post hook (A2, migrace 1035) — auto-zaúčtování FV po vystavení / PF po
            // přijetí; admin-only (jako ostatní účetní nastavení firmy), účinek jen v double_entry.
            'auto_post_invoices', 'auto_post_purchases',
            // F7 — AI provider selection (NON-secret; secrety `*_enc` jdou VÝHRADNĚ přes
            // AiProviderCredentialsAction, NIKDY tady — mustFix #11).
            'ai_provider', 'ai_data_region', 'ai_eu_residency_required',
        ];

        // F7 — ENUM validace AI provider selection (§3.8).
        if (array_key_exists('ai_provider', $body)
            && !in_array($body['ai_provider'], ['anthropic', 'azure_openai', 'openai', 'gemini'], true)
        ) {
            return Json::error($response, 'validation_failed', "ai_provider musí být 'anthropic', 'azure_openai', 'openai' nebo 'gemini'.", 400);
        }
        if (array_key_exists('ai_data_region', $body)
            && !in_array($body['ai_data_region'], ['eu', 'us'], true)
        ) {
            return Json::error($response, 'validation_failed', "ai_data_region musí být 'eu' nebo 'us'.", 400);
        }

        // Validace accounting_mode (Epic F0)
        if (array_key_exists('accounting_mode', $body)
            && !in_array($body['accounting_mode'], ['tax_evidence', 'double_entry'], true)
        ) {
            return Json::error($response, 'validation_failed', "accounting_mode musí být 'tax_evidence' nebo 'double_entry'.", 400);
        }

        // Jaký doklad vzniká po úhradě proformy (issue #39, migrace 1565). Cizí hodnota
        // by v strict mode shodila UPDATE na PDOException → 500; tady je z ní čitelná 400.
        if (array_key_exists('proforma_payment_document', $body)
            && !in_array($body['proforma_payment_document'], ProformaPaymentDocuments::modes(), true)
        ) {
            return Json::error(
                $response,
                'validation_failed',
                "proforma_payment_document musí být 'final_on_full_payment' nebo 'always_tax_document'.",
                400,
            );
        }

        // Sklad — od kterého stavu objednávky se zboží počítá „na cestě" (rozhodnutí #2).
        // ENUM v DB je ('sent','confirmed'); cizí hodnota by v strict mode shodila UPDATE
        // na PDOException → 500, tady se z ní stane čitelná 400.
        if (array_key_exists('stock_in_transit_from', $body)
            && !in_array($body['stock_in_transit_from'], ['sent', 'confirmed'], true)
        ) {
            return Json::error($response, 'validation_failed', "stock_in_transit_from musí být 'sent' nebo 'confirmed'.", 400);
        }
        $modeEffectiveFrom = null;
        $current = [];
        if (array_key_exists('accounting_mode', $body)) {
            $currentStmt = $this->db->pdo()->prepare('SELECT accounting_mode, taxpayer_type FROM supplier WHERE id = ?');
            $currentStmt->execute([$id]);
            $current = $currentStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            $currentMode = (string) ($current['accounting_mode'] ?? 'tax_evidence');
            $currentTaxpayerType = (string) ($current['taxpayer_type'] ?? '');
            $effectiveTaxpayerType = (string) ($body['taxpayer_type'] ?? $current['taxpayer_type'] ?? 'fo');
            // Formulář nastavení posílá celý objekt, tedy i režim, který uživatel
            // nesahal. Kontroly proto platí jen na SKUTEČNOU změnu: jinak by firma
            // převzatá z MyInvoice (právnická osoba, kterou migrace nechala
            // v daňové evidenci) nemohla uložit ani e-mail — každé uložení by
            // spadlo na pravidlo o vedení účetnictví, které vůbec neporušuje
            // (issue myinvoice#265). Zakázaná kombinace se hlídá tam, kde vzniká:
            // při přepnutí režimu nebo právní formy.
            $modeChanged = (string) $body['accounting_mode'] !== $currentMode;
            $taxpayerTypeChanged = array_key_exists('taxpayer_type', $body)
                && (string) ($body['taxpayer_type'] ?? '') !== $currentTaxpayerType;
            if ($body['accounting_mode'] === 'tax_evidence' && $effectiveTaxpayerType === 'po'
                && ($modeChanged || $taxpayerTypeChanged)
            ) {
                return Json::error($response, 'legal_form_requires_accounting', 'Právnická osoba musí vést účetnictví.', 422);
            }
            // Datum účinnosti se řeší jen u změny režimu — nebo když ho volající
            // pošle sám, protože opravuje historii.
            if ($modeChanged || array_key_exists('accounting_mode_effective_from', $body)) {
                $modeEffectiveFrom = trim((string) ($body['accounting_mode_effective_from'] ?? date('Y-01-01')));
                $effectiveDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $modeEffectiveFrom);
                if ($effectiveDate === false || $effectiveDate->format('Y-m-d') !== $modeEffectiveFrom
                    || substr($modeEffectiveFrom, 5) !== '01-01') {
                    return Json::error($response, 'accounting_mode_effective_date', 'Změna účetního režimu musí být účinná k 1. lednu.', 422);
                }
                if ($currentMode === 'double_entry' && $body['accounting_mode'] === 'tax_evidence') {
                    $doubleEntrySince = $this->accountingModes->continuousDoubleEntrySince($id, $modeEffectiveFrom);
                    if ($doubleEntrySince === null) {
                        return Json::error($response, 'accounting_mode_history_missing', 'Nelze ověřit zákonnou dobu vedení účetnictví; zkontrolujte historii účetního režimu.', 422);
                    }
                    $completedPeriods = (int) substr($modeEffectiveFrom, 0, 4) - (int) substr($doubleEntrySince, 0, 4);
                    if ($completedPeriods < 5) {
                        $earliestYear = (int) substr($doubleEntrySince, 0, 4) + 5;
                        return Json::error(
                            $response,
                            'accounting_minimum_periods',
                            sprintf('Vedení účetnictví lze podle § 4 odst. 7 ZoÚ ukončit nejdříve po 5 po sobě jdoucích účetních obdobích, tedy k 1. 1. %d.', $earliestYear),
                            422,
                        );
                    }
                }
            }
        }

        if (($body['accounting_mode'] ?? null) === 'double_entry'
            && ($current['accounting_mode'] ?? null) !== 'double_entry'
        ) {
            $pending = $this->pendingAccountingBackfillCounts($id);
            if ($pending['total'] > 0) {
                return Json::error(
                    $response,
                    'backfill_required',
                    'Firma má nedoúčtované historické doklady — použijte průvodce aktivací podvojného účetnictví.',
                    409,
                    $pending,
                );
            }
        }

        // Identifikovaná osoba (§ 6g–6l ZDPH, issue #94) je z definice NEPLÁTCE
        // v tuzemsku — kombinace obou flagů je nevalidní. Kontrolujeme efektivní
        // stav po této změně (z body, jinak ze současného řádku).
        $cur = [];
        if (array_key_exists('is_identified', $body)
            || array_key_exists('is_vat_payer', $body)
            || array_key_exists('vat_status_effective_from', $body)
        ) {
            $stmt = $this->db->pdo()->prepare('SELECT is_vat_payer, is_identified FROM supplier WHERE id = ?');
            $stmt->execute([$id]);
            $cur = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            $effVatPayer = array_key_exists('is_vat_payer', $body) ? (bool) $body['is_vat_payer'] : (bool) ($cur['is_vat_payer'] ?? false);
            $effIdentified = array_key_exists('is_identified', $body) ? (bool) $body['is_identified'] : (bool) ($cur['is_identified'] ?? false);
            if ($effVatPayer && $effIdentified) {
                return Json::error($response, 'validation_failed',
                    'Identifikovaná osoba je z definice neplátce DPH — nelze kombinovat s plátcovstvím. Plátce DPH přepínač identifikované osoby vypne.', 422);
            }
        }
        $vatStatusEffectiveFrom = null;
        $vatStatusChanged = (array_key_exists('is_vat_payer', $body)
                && (bool) $body['is_vat_payer'] !== (bool) ($cur['is_vat_payer'] ?? false))
            || (array_key_exists('is_identified', $body)
                && (bool) $body['is_identified'] !== (bool) ($cur['is_identified'] ?? false));
        if ($vatStatusChanged || array_key_exists('vat_status_effective_from', $body)) {
            $vatStatusEffectiveFrom = trim((string) ($body['vat_status_effective_from'] ?? date('Y-m-d')));
            $effectiveDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $vatStatusEffectiveFrom);
            if ($effectiveDate === false || $effectiveDate->format('Y-m-d') !== $vatStatusEffectiveFrom) {
                return Json::error($response, 'validation_failed', 'Datum účinnosti plátcovství DPH není platné.', 422);
            }
            // Retro-guard sdílený s VatStatusHistoryAction — legacy checkbox nesmí
            // obcházet zámky období a podaná přiznání (nález review security-tenant).
            $collisions = $this->vatStatusGuard->collisions($id, $vatStatusEffectiveFrom);
            if ($collisions !== [] && empty($body['vat_status_acknowledge'])) {
                return Json::error($response, 'vat_status_locked_conflict',
                    'Změna plátcovství zasahuje do uzamčeného období nebo už podaných přiznání.', 409,
                    ['collisions' => $collisions]);
            }
        }
        // Validace tax fields
        if (array_key_exists('taxpayer_type', $body) && $body['taxpayer_type'] !== null
            && !in_array($body['taxpayer_type'], ['fo', 'po'], true)) {
            return Json::error($response, 'validation_failed', "taxpayer_type musí být 'fo' (fyzická) nebo 'po' (právnická).", 400);
        }
        if (array_key_exists('vat_period', $body) && $body['vat_period'] !== null
            && !in_array($body['vat_period'], ['monthly', 'quarterly'], true)) {
            return Json::error($response, 'validation_failed', "vat_period musí být 'monthly' nebo 'quarterly'.", 400);
        }
        if (array_key_exists('oss_identification_country', $body)) {
            $value = strtoupper(trim((string) ($body['oss_identification_country'] ?? '')));
            $body['oss_identification_country'] = $value === '' ? null : $value;
            if ($body['oss_identification_country'] !== null && !preg_match('/^[A-Z]{2}$/', $body['oss_identification_country'])) {
                return Json::error($response, 'validation_failed', 'oss_identification_country musí být ISO kód země (např. CZ).', 400);
            }
        }
        if (array_key_exists('oss_return_currency', $body)) {
            $value = strtoupper(trim((string) ($body['oss_return_currency'] ?? 'EUR')));
            $body['oss_return_currency'] = $value === '' ? 'EUR' : $value;
            if (!preg_match('/^[A-Z]{3}$/', $body['oss_return_currency'])) {
                return Json::error($response, 'validation_failed', 'oss_return_currency musí být ISO kód měny (např. EUR).', 400);
            }
        }
        foreach (['oss_valid_from', 'oss_valid_to'] as $field) {
            if (!array_key_exists($field, $body)) continue;
            $value = trim((string) ($body[$field] ?? ''));
            if ($value === '') continue;
            $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
            if ($date === false || $date->format('Y-m-d') !== $value) {
                return Json::error($response, 'validation_failed', "{$field} musí být platné datum ve formátu RRRR-MM-DD.", 400);
            }
        }
        // Paušální daň pásmo — enum + podmínka §7a ZDP: paušalista nesmí být plátce DPH.
        if (array_key_exists('flat_tax_band', $body)) {
            $band = trim((string) ($body['flat_tax_band'] ?? ''));
            if ($band === '') { $band = 'none'; }
            $body['flat_tax_band'] = $band;
            if (!in_array($band, ['none', 'band1', 'band2', 'band3'], true)) {
                return Json::error($response, 'validation_failed', "flat_tax_band musí být 'none', 'band1', 'band2' nebo 'band3'.", 400);
            }
            if ($band !== 'none') {
                // Efektivní plátcovství DPH po této změně (z body, jinak ze současného stavu).
                if (array_key_exists('is_vat_payer', $body)) {
                    $vatPayer = (bool) $body['is_vat_payer'];
                } else {
                    $stmt = $this->db->pdo()->prepare('SELECT is_vat_payer FROM supplier WHERE id = ?');
                    $stmt->execute([$id]);
                    $vatPayer = (bool) $stmt->fetchColumn();
                }
                if ($vatPayer) {
                    return Json::error($response, 'validation_failed',
                        'Paušální daň lze zvolit jen pro neplátce DPH (§ 7a ZDP).', 422);
                }
            }
        }
        // Empty string → null pro tax fields (NULL = nevyplněno)
        foreach (['taxpayer_type', 'vat_period', 'financial_office_code', 'workplace_code',
                  'cz_nace_code', 'data_box_type', 'data_box_id',
                  'oss_valid_from', 'oss_valid_to', 'oss_identification_country',
                  'cssz_vsdp', 'cssz_ossz_code', 'health_insurance_number',
                  'sest_jmeno', 'sest_prijmeni', 'sest_telefon', 'sest_email', 'sest_funkce',
                  'street_number_pop', 'street_number_orient',
                  'opr_jmeno', 'opr_prijmeni', 'opr_postaveni'] as $f) {
            if (array_key_exists($f, $body) && trim((string) ($body[$f] ?? '')) === '') {
                $body[$f] = null;
            }
        }
        // Identifikátory odvodů: u OSVČ jsou to VŽDY osobní údaje na supplier. U právnické
        // osoby je kanonickým zdrojem mzdový modul (migrace 1189/1221) — ale jen když je
        // zapnutý. S vypnutými Mzdami (payroll_enabled = 0) není kam VS ČSSZ / VS zdravotní
        // pojišťovny uložit, a tahle legacy pole zůstávají jediným zdrojem pro detekci
        // odvodů v bance i pro šablony pravidel. Nulovat je tedy smíme pouze proti běžícímu
        // mzdovému modulu, jinak by je každé uložení Nastavení firmy tiše smazalo.
        $personalInsuranceFields = [
            'cssz_vsdp',
            'cssz_ossz_code',
            'health_insurance_number',
        ];
        if (array_key_exists('taxpayer_type', $body)
            || array_intersect_key($body, array_flip($personalInsuranceFields)) !== []
        ) {
            $effectiveTaxpayerType = $body['taxpayer_type'] ?? null;
            if ($effectiveTaxpayerType === null) {
                $stmt = $this->db->pdo()->prepare(
                    'SELECT taxpayer_type FROM supplier WHERE id = ?'
                );
                $stmt->execute([$id]);
                $effectiveTaxpayerType = $stmt->fetchColumn();
            }
            $payrollEnabled = array_key_exists('payroll_enabled', $body)
                ? (bool) $body['payroll_enabled']
                : $this->payrollAccess->isEnabled($id);
            if ($effectiveTaxpayerType === 'po' && $payrollEnabled) {
                foreach ($personalInsuranceFields as $field) {
                    $body[$field] = null;
                }
            }
        }
        // Validace email_accent_color — musí být hex (#RRGGBB)
        if (array_key_exists('email_accent_color', $body)) {
            $v = trim((string) ($body['email_accent_color'] ?? ''));
            if ($v === '') {
                $body['email_accent_color'] = '#3B2D83';
            } elseif (!preg_match('/^#[0-9A-Fa-f]{6}$/', $v)) {
                return Json::error($response, 'validation_failed', "email_accent_color musí být hex barva (#RRGGBB).", 400);
            }
        }
        // Validace per-supplier varsymbol templatů: prázdný string → NULL (= fallback na cfg);
        // jinak max 60 znaků a musí obsahovat alespoň jeden counter placeholder {C+}.
        foreach (['invoice_number_format', 'proforma_number_format', 'credit_note_number_format', 'purchase_invoice_number_format'] as $f) {
            if (array_key_exists($f, $body)) {
                $v = trim((string) ($body[$f] ?? ''));
                if ($v === '') {
                    $body[$f] = null;
                } else {
                    if (strlen($v) > 60) {
                        return Json::error($response, 'validation_failed', "Pole '$f' má max 60 znaků.", 400);
                    }
                    if (!preg_match('/\{C+\}/', $v)) {
                        return Json::error($response, 'validation_failed', "Pole '$f' musí obsahovat counter placeholder, např. {CCC}.", 400);
                    }
                    $body[$f] = $v;
                }
            }
        }
        if (array_key_exists('invoice_number_period', $body)
            && !in_array($body['invoice_number_period'], ['year', 'month', 'none'], true)
        ) {
            return Json::error($response, 'validation_failed', "Neplatné invoice_number_period (year|month|none).", 400);
        }
        if (array_key_exists('default_payment_due_unit', $body)
            && !in_array($body['default_payment_due_unit'], ['days', 'month'], true)
        ) {
            return Json::error($response, 'validation_failed', "default_payment_due_unit musí být 'days' nebo 'month'.", 400);
        }
        // Legacy: pokud frontend pošle 'default_currency' jako code, převedeme na id (scoped to supplier)
        if (isset($body['default_currency']) && !isset($body['default_currency_id'])) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT id FROM currencies WHERE supplier_id = ? AND code = ? ORDER BY is_default DESC, id ASC LIMIT 1'
            );
            $stmt->execute([$id, strtoupper((string) $body['default_currency'])]);
            $body['default_currency_id'] = (int) $stmt->fetchColumn();
        }
        // Práh dní pro první upomínku je INT — clamp zrcadlí rozsah UI (1–365 dní),
        // ať přímý API caller neuloží nesmyslnou hodnotu.
        if (array_key_exists('reminder_days_after_due', $body)) {
            $body['reminder_days_after_due'] = max(1, min(365, (int) $body['reminder_days_after_due']));
        }
        // Kopie odchozích e-mailů dodavateli — JSON {typ: 'off'|'cc'|'bcc'},
        // klíče = typy zpráv resolveru. Ukládají se jen explicitně zvolené typy;
        // null/prázdný objekt → NULL = vše dle cfg (živý fallback).
        if (array_key_exists('self_copy', $body)) {
            $sc = $body['self_copy'];
            if ($sc === null || $sc === [] || $sc === '') {
                $body['self_copy'] = null;
            } else {
                if (!is_array($sc)) {
                    return Json::error($response, 'validation_failed', 'self_copy musí být objekt {typ: off|cc|bcc} nebo null.', 400);
                }
                $validTypes = [RecipientResolver::TYPE_DOCUMENTS, RecipientResolver::TYPE_REMINDERS, RecipientResolver::TYPE_APPROVALS];
                $clean = [];
                foreach ($sc as $k => $v) {
                    if (!in_array($k, $validTypes, true)) {
                        return Json::error($response, 'validation_failed', "self_copy: neznámý typ zprávy '$k' (documents|reminders|approvals).", 400);
                    }
                    if (!in_array($v, RecipientResolver::SELF_COPY_MODES, true)) {
                        return Json::error($response, 'validation_failed', "self_copy.$k: hodnota musí být off|cc|bcc.", 400);
                    }
                    $clean[$k] = $v;
                }
                $body['self_copy'] = $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_UNICODE);
            }
        }
        $sets = [];
        $params = [];
        foreach ($allowed as $f) {
            // Plátcovství/IO jde přes historii + přepočet cache (níže) — přímý zápis
            // by u budoucí účinnosti přepnul cache hned a cron ji druhý den vracel.
            if ($vatStatusEffectiveFrom !== null && in_array($f, ['is_vat_payer', 'is_identified'], true)) {
                continue;
            }
            if (array_key_exists($f, $body)) {
                $sets[] = "$f = ?";
                $params[] = in_array($f, ['is_vat_payer', 'is_identified', 'oss_enabled', 'auto_send_reminders', 'auto_generate_recurring', 'embed_isdoc', 'default_prices_include_vat', 'email_branding_enabled', 'pdf_logo_show_name', 'branding_profiles_enabled', 'payment_thanks_enabled', 'payment_thanks_auto_send', 'payment_thanks_default_checked', 'payment_thanks_attach_paid_pdf', 'stock_enabled', 'stock_auto_issue', 'accounting_enabled', 'payroll_enabled', 'auto_post_invoices', 'auto_post_purchases', 'ai_eu_residency_required'], true)
                    ? ((int) (bool) $body[$f])
                    : $body[$f];
            }
        }

        if (!empty($sets)) {
            $params[] = $id;
            $sql = 'UPDATE supplier SET ' . implode(', ', $sets) . ' WHERE id = ?';
            $this->db->pdo()->prepare($sql)->execute($params);
        }
        if ($vatStatusEffectiveFrom !== null) {
            // Stejná kódová cesta jako správa historie (VatStatusHistoryAction):
            // upsert řádku + přepočet živé cache z historie. Budoucí účinnost tak
            // cache nemění — propíše ji až cron vat-status-apply v den účinnosti.
            $this->vatStatus->upsert(
                $id,
                $vatStatusEffectiveFrom,
                array_key_exists('is_vat_payer', $body)
                    ? !empty($body['is_vat_payer'])
                    : !empty($cur['is_vat_payer']),
                array_key_exists('is_identified', $body)
                    ? !empty($body['is_identified'])
                    : !empty($cur['is_identified']),
                null,
                (int) (((array) $request->getAttribute(AuthMiddleware::ATTR_USER, []))['id'] ?? 0) ?: null,
            );
            $this->vatStatus->refreshLiveCache($id);
        }
        if ($modeEffectiveFrom !== null) {
            $this->accountingModes->record($id, $modeEffectiveFrom, (string) $body['accounting_mode']);
        }
        // Branding (barva/toggle) se v PDF renderuje živě → po změně invaliduj cached
        // draft PDF dodavatele, ať se přegenerují s novou barvou (mtime cache je sama
        // od sebe neobnoví). Vystavené regenerují přes ?regenerate=1.
        if (array_key_exists('email_accent_color', $body)
            || array_key_exists('email_branding_enabled', $body)
            || array_key_exists('pdf_logo_show_name', $body)
        ) {
            $this->pdf->invalidateDraftsBySupplier($id);
        }
        // Epic F1: zapnutí podvojného účetnictví → naseeduj směrnou osnovu (idempotentní,
        // takže opakované uložení double_entry nic nerozbije). Bez osnovy by PostingService
        // neměl na co mapovat account_code.
        // Audit 2026-07 (G5): seed osnovy NEDOÚČTOVÁVÁ historické doklady (FV/PF/pokladna) —
        // ty zůstanou beze zápisu v deníku, dokud se ručně nespustí backfill CLI
        // (api/bin/backfill-accounting.php, api/bin/backfill-cash-accounting.php). Pending
        // počet se zaloguje do audit logu, FE si stav před přepnutím ověří přes
        // GET /api/settings/mode-switch-preview a nabídne uživateli instrukci k backfillu.
        if (array_key_exists('accounting_mode', $body) && $body['accounting_mode'] === 'double_entry') {
            $this->coaSeeder->seedForSupplier($id);
            $pending = $this->pendingAccountingBackfillCounts($id);
            if ($pending['total'] > 0) {
                $this->log($request, 'supplier.mode_switch_pending_backfill', $id, $pending);
            }
        }
        // F7 (mustFix #9) — WARN (nebrání) při přepnutí ai_provider na providera BEZ
        // nakonfigurovaných credentials → extrakce by tiše selhala. Audit warning;
        // FE si stav creds tahá z /ai/credentials a zobrazí banner.
        if (array_key_exists('ai_provider', $body) && !$this->aiProviderHasCredentials($id, (string) $body['ai_provider'])) {
            $this->log($request, 'supplier.ai_provider_no_credentials', $id, ['ai_provider' => (string) $body['ai_provider']]);
        }
        $this->log($request, 'supplier.updated', $id, ['fields' => array_keys(array_intersect_key($body, array_flip($allowed)))]);
        return $this->respondSupplier($response, $id);
    }

    /**
     * DELETE /api/suppliers/{id} — fyzicky smaž firmu.
     *
     * Povoleno JEN pro firmu bez účetních/business dat. Firma, která vede deník,
     * přijaté/vydané faktury, pokladnu, majetek, sklad, DMS nebo daňové výkazy,
     * jde jen archivovat (soft-delete) — fyzické smazání by nevratně osiřelo
     * účetní záznamy a porušilo archivační povinnost §31/§32 ZoÚ (audit C3).
     *
     * Vlastní DELETE FROM supplier běží se ZAPNUTOU FK kontrolou, aby se korektně
     * spustily ON DELETE CASCADE na všech dětských tabulkách (nic neosiří). FK
     * kontrola se vypíná JEN lokálně kolem odstranění per-supplier konfigurace
     * (RESTRICT FK) a cyklu supplier.default_currency_id ↔ currencies.supplier_id
     * (oba RESTRICT a NOT NULL — cyklus nelze rozbít UPDATE … = NULL).
     */
    public function deleteSupplierById(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) return Json::error($response, 'validation_failed', 'Neplatné id.', 400);
        if ($this->membershipDenies($request, $id)) {
            return Json::error($response, 'not_found', 'Supplier nenalezen.', 404);
        }

        $pdo = $this->db->pdo();
        $count = (int) $pdo->query("SELECT COUNT(*) FROM supplier")->fetchColumn();
        if ($count <= 1) {
            return Json::error($response, 'cannot_delete_last', 'Posledního supplier nelze smazat.', 409);
        }

        // Dependency check přes VŠECHNY tabulky nesoucí účetní/business data firmy
        // (nejen clients+invoices jako dřív — audit C3: dřívější check + FK_CHECKS=0
        // osiřel deník/PF/majetek/pokladnu/sklad). Bankovní výpisy mají od E8
        // autoritativní supplier_id; řádkové děti
        // (journal_entry_lines, cash_document_vat_lines, depreciation_entries…) kryjí
        // jejich hlavičkové tabulky níže.
        $dataChecks = [
            'clients'            => 'klienty (odběratele)',
            'invoices'           => 'vydané faktury',
            'purchase_invoices'  => 'přijaté faktury',
            'journal_entries'    => 'účetní deník',
            'cash_documents'     => 'pokladní doklady',
            'cash_registers'     => 'pokladny',
            'assets'             => 'majetek',
            'stock_items'        => 'skladové karty',
            'stock_documents'    => 'skladové doklady',
            'warehouses'         => 'sklady',
            'documents'          => 'dokumenty (DMS)',
            'income_tax_returns' => 'přiznání k dani z příjmů',
            'tax_submissions'    => 'daňové výkazy (DPH/KH/SH)',
            'payment_orders'     => 'příkazy k úhradě',
            'bank_statements'    => 'bankovní výpisy',
        ];
        $blocking = [];
        foreach ($dataChecks as $table => $label) {
            $stmt = $pdo->prepare("SELECT 1 FROM `$table` WHERE supplier_id = ? LIMIT 1");
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() !== false) {
                $blocking[] = $label;
            }
        }
        if ($blocking !== []) {
            return Json::error(
                $response,
                'has_dependencies',
                'Firmu nelze smazat — obsahuje účetní data: ' . implode(', ', $blocking)
                    . '. Data nejdřív odstraňte, nebo firmu archivujte.',
                409
            );
        }

        // Per-supplier konfigurace s RESTRICT FK na supplier/currencies (jinak by
        // blokovala DELETE FROM supplier) + currencies (cyklický FK). Smažeme je
        // s lokálně vypnutou FK kontrolou; VLASTNÍ DELETE FROM supplier pak proběhne
        // se ZAPNUTOU kontrolou, aby se u ostatních (~CASCADE) tabulek korektně
        // spustil ON DELETE CASCADE a nic nezůstalo osiřelé.
        $pdo->beginTransaction();
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            try {
                foreach ([
                    'invoice_counters',
                    'purchase_invoice_counters',
                    'expense_categories',
                    'revenue_categories',
                    'vat_classifications',
                    'import_jobs',
                    'crm_monthly_summary',
                    'recurring_invoice_templates',
                    // Metadata bez FK na supplier — bez explicitního smazání by po
                    // úspěšném DELETE (dependency check výše prošel) zůstaly osiřelé
                    // (audit C3 doauditováno, doplňkový nález security-review).
                    'work_report_links',
                    'document_folders',
                    'document_tags',
                    'activity_log',
                    'currencies',
                ] as $cfgTable) {
                    $pdo->prepare("DELETE FROM `$cfgTable` WHERE supplier_id = ?")->execute([$id]);
                }
            } finally {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
            // FK kontrola je zpět ZAPNUTÁ → ON DELETE CASCADE se korektně spustí.
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return Json::error($response, 'delete_failed', 'Smazání selhalo: ' . $e->getMessage(), 500);
        }

        // MS-P3-4: smaž PDF cache subfolder pro tohoto supplier
        $pdfDir = \MyInvoice\Infrastructure\Config\RuntimePaths::storage('invoices') . '/sup-' . $id;
        if (is_dir($pdfDir)) {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($pdfDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iter as $f) {
                if ($f->isDir()) @rmdir($f->getPathname());
                else @unlink($f->getPathname());
            }
            @rmdir($pdfDir);
        }

        $this->log($request, 'supplier.deleted', $id, []);
        return Json::ok($response, ['deleted' => true]);
    }

    private function respondSupplier(Response $response, int $id): Response
    {
        if ($id <= 0) return Json::error($response, 'not_found', 'Supplier nenalezen.', 404);
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.*, c.name_cs AS country_name_cs, c.name_en AS country_name_en, c.iso2 AS country_iso,
                    cur.code AS default_currency
               FROM supplier s
               JOIN countries c ON c.id = s.country_id
               JOIN currencies cur ON cur.id = s.default_currency_id
              WHERE s.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return Json::error($response, 'not_found', 'Supplier nenalezen.', 404);
        $row['id']                       = (int) $row['id'];
        $row['is_vat_payer']             = (bool) $row['is_vat_payer'];
        // Uložený CZ-NACE přeložený přes číselník ČINNOSTI — UI z něj pozná, že kód
        // sice existuje, ale po přechodu na NACE rev. 2.1 (1. 1. 2026) už EXPIROVAL
        // a EPO by na něj hlásilo propustnou chybu 30. Read-only, dopočítané.
        $row['cz_nace_resolved'] = \MyInvoice\Service\Report\EpoOkecCodebook::describe(
            $row['cz_nace_code'] ?? null
        );
        $history = $this->db->pdo()->prepare(
            'SELECT id, effective_from, is_vat_payer, is_identified, note, annual_deduction_percent
               FROM supplier_vat_status_history WHERE supplier_id = ? ORDER BY effective_from'
        );
        $history->execute([$id]);
        $row['vat_status_history'] = array_map(static fn (array $item): array => [
            'id' => (int) $item['id'],
            'effective_from' => (string) $item['effective_from'],
            'is_vat_payer' => (bool) $item['is_vat_payer'],
            'is_identified' => (bool) $item['is_identified'],
            'note' => $item['note'] !== null ? (string) $item['note'] : null,
            'annual_deduction_percent' => (float) $item['annual_deduction_percent'],
        ], $history->fetchAll(\PDO::FETCH_ASSOC) ?: []);
        // Identifikovaná osoba (§ 6g–6l, issue #94) — doplněk k neplátci.
        $row['is_identified']            = (bool) ($row['is_identified'] ?? false);
        $row['oss_enabled']              = (bool) ($row['oss_enabled'] ?? false);
        $row['oss_return_currency']      = (string) ($row['oss_return_currency'] ?? 'EUR');
        $row['default_vat_rate_id']      = (int) $row['default_vat_rate_id'];
        $row['default_currency_id']      = (int) $row['default_currency_id'];
        $row['default_payment_due_days'] = (int) $row['default_payment_due_days'];
        $row['default_payment_due_unit'] = (string) ($row['default_payment_due_unit'] ?? 'days');
        $row['default_hourly_rate']      = (float) $row['default_hourly_rate'];
        $row['auto_send_reminders']      = (bool) $row['auto_send_reminders'];
        $row['reminder_days_after_due']  = (int) ($row['reminder_days_after_due'] ?? 3);
        $row['auto_generate_recurring']  = (bool) ($row['auto_generate_recurring'] ?? true);
        $row['default_prices_include_vat'] = (bool) ($row['default_prices_include_vat'] ?? false);
        $row['embed_isdoc']              = (bool) ($row['embed_isdoc'] ?? true);
        // Režim účetnictví (Epic F0, migrace 1001)
        $row['accounting_mode']          = (string) ($row['accounting_mode'] ?? 'tax_evidence');
        // „Vést účetnictví" (migrace 1179) — vypnuté schová účetní sekce z menu. Na licenci
        // vliv nemá: licencují se všechny firmy i uživatelé bez ohledu na tenhle přepínač.
        $row['accounting_enabled']       = (bool) ($row['accounting_enabled'] ?? true);
        // „Vést mzdy" (migrace 1187, opt-in od 1290) — stejný vzor jako sklad níž:
        // chybějící hodnota znamená vypnuto, ne zapnuto.
        $row['payroll_enabled']          = (bool) ($row['payroll_enabled'] ?? false);
        // Doklad po úhradě proformy (issue #39, migrace 1565 + 1567). Chybějící hodnota
        // = nedoběhlá migrace; fallback drží VÝCHOZÍ režim (daňový doklad k přijaté
        // platbě) a hlavně nenechá FE select bez vybrané položky — ten by se vykreslil
        // prázdný a uložil prázdnou hodnotu.
        $row['proforma_payment_document'] = in_array(
            $row['proforma_payment_document'] ?? null,
            ProformaPaymentDocuments::modes(),
            true,
        ) ? (string) $row['proforma_payment_document'] : ProformaPaymentDocuments::MODE_ALWAYS_TAX_DOCUMENT;
        // Sklad (Epic SKLAD, migrace 1023) — opt-in modul; FE nav sekci gatuje MeAction.
        $row['stock_enabled']            = (bool) ($row['stock_enabled'] ?? false);
        $row['stock_auto_issue']         = (bool) ($row['stock_auto_issue'] ?? true);
        // Od kterého stavu objednávky se zboží počítá „na cestě" (migrace 1331,
        // rozhodnutí #2). Výchozí 'sent' musí odpovídat fallbacku
        // v InTransitRepository::inTransitStates(), jinak by obrazovka ukazovala
        // jiný stav, než podle kterého se doopravdy počítá.
        $row['stock_in_transit_from']    = (string) ($row['stock_in_transit_from'] ?? 'sent');
        // Auto-post hook (A2, migrace 1035) — opt-in auto-zaúčtování; FE gatuje na double_entry.
        $row['auto_post_invoices']       = (bool) ($row['auto_post_invoices'] ?? false);
        $row['auto_post_purchases']      = (bool) ($row['auto_post_purchases'] ?? false);
        // F7 — AI provider selection (non-secret; klíče `*_enc` jsou níže redigovány).
        $row['ai_provider']              = (string) ($row['ai_provider'] ?? 'anthropic');
        $row['ai_data_region']           = (string) ($row['ai_data_region'] ?? 'us');
        $row['ai_eu_residency_required'] = (bool) ($row['ai_eu_residency_required'] ?? false);
        $row['email_branding_enabled']   = (bool) ($row['email_branding_enabled'] ?? false);
        $row['email_accent_color']       = (string) ($row['email_accent_color'] ?? '#3B2D83');
        $row['pdf_logo_show_name']       = (bool) ($row['pdf_logo_show_name'] ?? false);
        $row['branding_profiles_enabled'] = (bool) ($row['branding_profiles_enabled'] ?? false);
        $row['default_branding_profile_id'] = $row['default_branding_profile_id'] !== null
            ? (int) $row['default_branding_profile_id']
            : null;
        $row['has_email_logo']           = SafeLogoPath::resolve($row['logo_path'] ?? null, $row['id']) !== null;
        $row['payment_thanks_enabled']        = (bool) ($row['payment_thanks_enabled'] ?? false);
        $row['payment_thanks_auto_send']      = (bool) ($row['payment_thanks_auto_send'] ?? false);
        $row['payment_thanks_default_checked']= (bool) ($row['payment_thanks_default_checked'] ?? false);
        $row['payment_thanks_attach_paid_pdf']= (bool) ($row['payment_thanks_attach_paid_pdf'] ?? false);
        // Kopie odchozích e-mailů dodavateli (migrace 0102) — parsed objekt nebo null.
        $sc = $row['self_copy'] !== null ? json_decode((string) $row['self_copy'], true) : null;
        $row['self_copy'] = is_array($sc) && $sc !== [] ? $sc : null;
        // Efektivní cfg fallback per typ — UI ho ukáže u volby „dle konfigurace".
        // `approvals` má v cfg dva flagy (žádost/upomínka) — posíláme oba.
        $row['cfg_self_copy_fallback'] = [
            'documents'          => ((bool) $this->config->get('smtp.cc_supplier_on_send', false)) ? 'cc' : 'off',
            'reminders'          => ((bool) $this->config->get('smtp.cc_supplier_on_reminder', false)) ? 'cc' : 'off',
            'approvals'          => ((bool) $this->config->get('approval.cc_supplier_on_approval', true)) ? 'bcc' : 'off',
            'approval_reminders' => ((bool) $this->config->get('approval.cc_supplier_on_approval_reminder', true)) ? 'bcc' : 'off',
        ];
        // Bezpečnost: do API NIKDY neposílat žádná tajemství. Redakce vzorem `*_enc`
        // je odolná vůči nově přidaným šifrovaným sloupcům (původní explicitní výčet
        // nechával unikat idoklad/fakturoid/anthropic credentials). Doplněno o
        // obecné secret-ish názvy, aby se případný budoucí tajný sloupec BEZ přípony
        // `_enc` taky neprosákl (defense-in-depth — allowlist by byl křehčí, supplier
        // má desítky legitimních polí, které FE potřebuje).
        foreach (array_keys($row) as $k) {
            $lk = strtolower((string) $k);
            if (str_ends_with($lk, '_enc')
                || str_contains($lk, 'password')
                || str_contains($lk, 'secret')
                || str_contains($lk, 'api_key')
                || str_contains($lk, 'access_token')
                || str_contains($lk, 'passphrase')
                || str_contains($lk, 'private_key')
                // Pseudonymizační salt (ai_pseudo_salt) = random_bytes(32) — tajemství,
                // které nesmí ven; navíc jde o binární data (nevalidní UTF-8), takže
                // by shodilo json_encode(JSON_THROW_ON_ERROR) celého endpointu.
                || str_contains($lk, 'salt')
            ) {
                unset($row[$k]);
            }
        }
        unset($row['idoklad_access_token']);
        // Globální cfg fallback pro varsymbol — UI ho použije jako placeholder
        // u prázdných per-supplier polí (aby uživatel viděl, jaká šablona by se
        // použila kdyby ponechal pole prázdné).
        $row['cfg_varsymbol_fallback'] = [
            'invoice'     => (string) $this->config->get('varsymbol.templates.invoice', ''),
            'proforma'    => (string) $this->config->get('varsymbol.templates.proforma', ''),
            'credit_note' => (string) $this->config->get('varsymbol.templates.credit_note', ''),
            // Přijaté faktury nemají cfg fallback — výchozí je vestavěná šablona generátoru.
            'purchase'    => \MyInvoice\Repository\PurchaseInvoiceRepository::PURCHASE_DEFAULT_TEMPLATE,
        ];
        // Featura G — preventivní varování na kolizi číselných řad (VS napříč
        // supplier-wide i per-client šablonami). FE dělá živou kontrolu jen nad
        // supplier-wide poli; backend navíc pokrývá per-client přepsání.
        $row['number_series_collisions'] = $this->seriesCollisions->findForSupplier($id);
        return Json::ok($response, $row);
    }

    private function nullable(array $b, string $key): ?string
    {
        $v = trim((string) ($b[$key] ?? ''));
        return $v === '' ? null : $v;
    }

    /**
     * F7 — má daný provider u suppliera nakonfigurovaný API klíč (`*_enc` non-null)?
     * Používá se jen pro non-blocking warning při přepnutí ai_provider.
     */
    private function aiProviderHasCredentials(int $supplierId, string $provider): bool
    {
        $col = match ($provider) {
            'anthropic'    => 'anthropic_api_key_enc',
            'azure_openai' => 'azure_openai_api_key_enc',
            'openai'       => 'openai_api_key_enc',
            'gemini'       => 'gemini_api_key_enc',
            default        => null,
        };
        if ($col === null) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare("SELECT $col IS NOT NULL FROM supplier WHERE id = ?");
        $stmt->execute([$supplierId]);
        return (bool) $stmt->fetchColumn();
    }

    public function listCurrencies(Request $request, Response $response): Response
    {
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.id, c.code, c.label, c.symbol, c.name_cs, c.name_en, c.decimals,
                    c.is_active, c.is_default,
                    c.account_number, c.bank_code, c.bank_name, c.iban, c.bic,
                    (
                        (SELECT COUNT(*) FROM invoices i WHERE i.currency_id = c.id)
                      + (SELECT COUNT(*) FROM purchase_invoices pi WHERE pi.currency_id = c.id OR pi.payment_currency_id = c.id)
                      + (SELECT COUNT(*) FROM projects p WHERE p.currency_id = c.id)
                      + (SELECT COUNT(*) FROM recurring_invoice_templates rit WHERE rit.currency_id = c.id)
                    ) AS invoices_count
               FROM currencies c
              WHERE c.supplier_id = ?
           ORDER BY c.code, c.is_default DESC, c.label'
        );
        $stmt->execute([$sid]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id']              = (int) $r['id'];
            $r['decimals']        = (int) $r['decimals'];
            $r['is_active']       = (bool) $r['is_active'];
            $r['is_default']      = (bool) $r['is_default'];
            $r['invoices_count']  = (int) $r['invoices_count'];
        }
        return Json::ok($response, $rows);
    }

    public function updateCurrency(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) return Json::error($response, 'validation_failed', 'Neplatné id.', 400);

        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT code, supplier_id FROM currencies WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return Json::error($response, 'not_found', 'Měna nenalezena.', 404);
        if ((int) $row['supplier_id'] !== $sid) {
            return Json::error($response, 'wrong_supplier', 'Tato měna patří jinému supplier.', 403);
        }
        $code = (string) $row['code'];

        $body = (array) ($request->getParsedBody() ?? []);
        if (($claimed = $this->rejectForeignBankAccount($request, $response, $sid, $body)) !== null) {
            return $claimed;
        }
        $allowed = ['label', 'symbol', 'decimals', 'is_active', 'is_default', 'account_number', 'bank_code', 'bank_name', 'iban', 'bic'];
        $sets = [];
        $params = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $body)) {
                $sets[] = "$f = ?";
                if (in_array($f, ['is_active', 'is_default'], true)) {
                    $params[] = (int) (bool) $body[$f];
                } elseif ($f === 'decimals') {
                    $params[] = max(0, min(6, (int) $body[$f]));
                } elseif ($f === 'symbol') {
                    // NOT NULL sloupec — prázdné ulož jako '' (ne null).
                    $params[] = (string) $body[$f];
                } else {
                    $params[] = ($body[$f] === '' || $body[$f] === null) ? null : $body[$f];
                }
            }
        }
        if (empty($sets)) {
            return $this->respondCurrencyById($response, $id);
        }

        // Pokud je is_default=1, vypneme default na ostatních řádcích pro stejný code v RÁMCI supplier
        if (array_key_exists('is_default', $body) && (int) (bool) $body['is_default'] === 1) {
            $pdo->prepare('UPDATE currencies SET is_default = 0 WHERE supplier_id = ? AND code = ? AND id <> ?')
                ->execute([$sid, $code, $id]);
        }

        $params[] = $id;
        $sql = 'UPDATE currencies SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $pdo->prepare($sql)->execute($params);

        // Pokud se měnily bank fields, invaliduj PDF cache faktur v této měně (drafty + faktury bez snapshotu)
        $bankFields = ['account_number', 'bank_code', 'bank_name', 'iban', 'bic'];
        $changedBankFields = array_intersect($bankFields, array_keys($body));
        $invalidated = 0;
        if (!empty($changedBankFields)) {
            $invalidated = $this->pdf->invalidateByCurrency($id);
            // Účet zadaný na měně musí vidět i registr vlastních účtů — jinak se do
            // něj dostane až prvním importem výpisu a do té doby nemá účtování banky
            // na co mapovat analytiku 221. Původní řádek registru se ZÁMĚRNĚ neruší:
            // váže se na něj naimportovaná historie (viz OwnBankAccountRegistrar).
            OwnBankAccountRegistrar::syncFromCurrency($pdo, $sid, $id);
        }

        $this->log($request, 'currency.updated', $id, [
            'code' => $code,
            'fields' => array_keys(array_intersect_key($body, array_flip($allowed))),
            'pdf_invalidated' => $invalidated,
        ]);

        return $this->respondCurrencyById($response, $id);
    }

    private function respondCurrencyById(Response $response, int $id): Response
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default,
                    account_number, bank_code, bank_name, iban, bic
               FROM currencies WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return Json::error($response, 'not_found', 'Měna nenalezena.', 404);
        $row['id']         = (int) $row['id'];
        $row['decimals']   = (int) $row['decimals'];
        $row['is_active']  = (bool) $row['is_active'];
        $row['is_default'] = (bool) $row['is_default'];
        return Json::ok($response, $row);
    }

    // ============================================================================
    // VAT RATES (číselník DPH sazeb)
    // ============================================================================

    public function listVatRates(Request $request, Response $response): Response
    {
        $rows = $this->db->pdo()->query(
            'SELECT v.id, v.code, v.rate_percent, v.country, v.label_cs, v.label_en, v.is_default,
                    v.is_reverse_charge, v.valid_from, v.valid_to,
                    (SELECT COUNT(*) FROM invoice_items i WHERE i.vat_rate_id = v.id) AS items_count
               FROM vat_rates v
           ORDER BY v.country, v.rate_percent DESC'
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id']                = (int) $r['id'];
            $r['rate_percent']      = (float) $r['rate_percent'];
            $r['is_default']        = (bool) $r['is_default'];
            $r['is_reverse_charge'] = (bool) $r['is_reverse_charge'];
            $r['items_count']       = (int) $r['items_count'];
        }
        return Json::ok($response, $rows);
    }

    public function createVatRate(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $b = (array) ($request->getParsedBody() ?? []);
        $code = trim((string) ($b['code'] ?? ''));
        $rate = (float) ($b['rate_percent'] ?? -1);
        if ($code === '' || $rate < 0) {
            return Json::error($response, 'validation_failed', 'code a rate_percent jsou povinné.', 400);
        }
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO vat_rates (code, rate_percent, country, label_cs, label_en, is_default,
                                    is_reverse_charge, valid_from, valid_to)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $code, $rate,
            strtoupper((string) ($b['country'] ?? 'CZ')),
            (string) ($b['label_cs'] ?? $code),
            (string) ($b['label_en'] ?? $code),
            !empty($b['is_default']) ? 1 : 0,
            !empty($b['is_reverse_charge']) ? 1 : 0,
            (string) ($b['valid_from'] ?? date('Y-m-d')),
            !empty($b['valid_to']) ? (string) $b['valid_to'] : null,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        if (!empty($b['is_default'])) $this->makeOnlyDefault($id, (string) ($b['country'] ?? 'CZ'));
        $this->log($request, 'vat_rate.created', $id, ['code' => $code, 'rate' => $rate]);
        return Json::ok($response, ['id' => $id], 201);
    }

    public function updateVatRate(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);
        $b = (array) ($request->getParsedBody() ?? []);
        $allowed = ['code', 'rate_percent', 'country', 'label_cs', 'label_en',
                    'is_default', 'is_reverse_charge', 'valid_from', 'valid_to'];
        $sets = []; $params = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $b)) {
                $sets[] = "$f = ?";
                $params[] = in_array($f, ['is_default', 'is_reverse_charge'], true)
                    ? ((int) (bool) $b[$f])
                    : ($b[$f] === '' ? null : $b[$f]);
            }
        }
        if (empty($sets)) return Json::ok($response, ['ok' => true]);
        $params[] = $id;
        $this->db->pdo()->prepare('UPDATE vat_rates SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        if (!empty($b['is_default'])) $this->makeOnlyDefault($id, (string) ($b['country'] ?? 'CZ'));
        $this->log($request, 'vat_rate.updated', $id, ['fields' => array_keys($b)]);
        return Json::ok($response, ['ok' => true]);
    }

    public function deleteVatRate(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoice_items WHERE vat_rate_id = ?');
        $stmt->execute([$id]);
        $count = (int) $stmt->fetchColumn();
        if ($count > 0) {
            return Json::error($response, 'has_dependencies',
                "Sazbu nelze smazat — používá ji $count položek faktur. Nastav jí konec platnosti (valid_to).", 409);
        }
        $this->db->pdo()->prepare('DELETE FROM vat_rates WHERE id = ?')->execute([$id]);
        $this->log($request, 'vat_rate.deleted', $id, []);
        return Json::ok($response, ['deleted' => true]);
    }

    private function makeOnlyDefault(int $id, string $country): void
    {
        $this->db->pdo()->prepare(
            'UPDATE vat_rates SET is_default = 0 WHERE id <> ? AND country = ?'
        )->execute([$id, strtoupper($country)]);
    }

    // ============================================================================
    // COUNTRIES (číselník zemí)
    // ============================================================================

    public function listCountries(Request $request, Response $response): Response
    {
        $rows = $this->db->pdo()->query(
            'SELECT c.id, c.iso2, c.iso3, c.name_cs, c.name_en, c.is_eu,
                    (SELECT COUNT(*) FROM clients cl WHERE cl.country_id = c.id) +
                    (SELECT COUNT(*) FROM supplier s WHERE s.country_id = c.id) AS uses_count
               FROM countries c ORDER BY c.name_cs'
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id']         = (int) $r['id'];
            $r['is_eu']      = (bool) $r['is_eu'];
            $r['uses_count'] = (int) $r['uses_count'];
        }
        return Json::ok($response, $rows);
    }

    public function createCountry(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $b = (array) ($request->getParsedBody() ?? []);
        $iso2 = strtoupper(trim((string) ($b['iso2'] ?? '')));
        if (!preg_match('/^[A-Z]{2}$/', $iso2)) {
            return Json::error($response, 'validation_failed', 'iso2 musí být 2 znaky.', 400);
        }
        try {
            $this->db->pdo()->prepare(
                'INSERT INTO countries (iso2, iso3, name_cs, name_en, is_eu) VALUES (?,?,?,?,?)'
            )->execute([
                $iso2,
                strtoupper((string) ($b['iso3'] ?? '')),
                (string) ($b['name_cs'] ?? ''),
                (string) ($b['name_en'] ?? ''),
                !empty($b['is_eu']) ? 1 : 0,
            ]);
        } catch (\PDOException $e) {
            return Json::error($response, 'duplicate', 'Země s tímto iso2 už existuje.', 409);
        }
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->log($request, 'country.created', $id, ['iso2' => $iso2]);
        return Json::ok($response, ['id' => $id], 201);
    }

    public function updateCountry(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);
        $b = (array) ($request->getParsedBody() ?? []);
        $allowed = ['iso3', 'name_cs', 'name_en', 'is_eu'];
        $sets = []; $params = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $b)) {
                $sets[] = "$f = ?";
                $params[] = $f === 'is_eu' ? (int) (bool) $b[$f] : $b[$f];
            }
        }
        if (empty($sets)) return Json::ok($response, ['ok' => true]);
        $params[] = $id;
        $this->db->pdo()->prepare('UPDATE countries SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        $this->log($request, 'country.updated', $id, ['fields' => array_keys($b)]);
        return Json::ok($response, ['ok' => true]);
    }

    public function deleteCountry(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM clients WHERE country_id = ?');
        $stmt->execute([$id]);
        $clients = (int) $stmt->fetchColumn();
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM supplier WHERE country_id = ?');
        $stmt->execute([$id]);
        $supplier = (int) $stmt->fetchColumn();
        if ($clients > 0 || $supplier > 0) {
            return Json::error($response, 'has_dependencies',
                "Zemi nelze smazat — používá ji $clients klientů + supplier=$supplier.", 409);
        }
        $this->db->pdo()->prepare('DELETE FROM countries WHERE id = ?')->execute([$id]);
        $this->log($request, 'country.deleted', $id, []);
        return Json::ok($response, ['deleted' => true]);
    }

    // ============================================================================
    // CURRENCIES — create/delete (update existující)
    // ============================================================================

    public function createCurrency(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $b = (array) ($request->getParsedBody() ?? []);
        $code = strtoupper(trim((string) ($b['code'] ?? '')));
        if (!preg_match('/^[A-Z]{3}$/', $code)) {
            return Json::error($response, 'validation_failed', 'code musí být 3 znaky.', 400);
        }
        $label = trim((string) ($b['label'] ?? "$code — výchozí"));
        if ($label === '') $label = $code;
        if (($claimed = $this->rejectForeignBankAccount($request, $response, $sid, $b)) !== null) {
            return $claimed;
        }

        $pdo = $this->db->pdo();
        // Existuje code v rámci tohoto supplier?
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM currencies WHERE supplier_id = ? AND code = ?');
        $stmt->execute([$sid, $code]);
        $existsForCode = (int) $stmt->fetchColumn();
        $isDefault = array_key_exists('is_default', $b) ? ((int) (bool) $b['is_default']) : ($existsForCode === 0 ? 1 : 0);

        if ($isDefault === 1 && $existsForCode > 0) {
            $pdo->prepare('UPDATE currencies SET is_default = 0 WHERE supplier_id = ? AND code = ?')->execute([$sid, $code]);
        }

        $pdo->prepare(
            'INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default,
                 account_number, bank_code, bank_name, iban, bic)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $sid,
            $code,
            $label,
            (string) ($b['symbol'] ?? $code),
            (string) ($b['name_cs'] ?? $code),
            (string) ($b['name_en'] ?? $code),
            (int) ($b['decimals'] ?? 2),
            array_key_exists('is_active', $b) ? ((int) (bool) $b['is_active']) : 1,
            $isDefault,
            ($b['account_number'] ?? '') !== '' ? (string) $b['account_number'] : null,
            ($b['bank_code'] ?? '') !== '' ? (string) $b['bank_code'] : null,
            ($b['bank_name'] ?? '') !== '' ? (string) $b['bank_name'] : null,
            ($b['iban'] ?? '') !== '' ? (string) $b['iban'] : null,
            ($b['bic'] ?? '') !== '' ? (string) $b['bic'] : null,
        ]);
        $newId = (int) $pdo->lastInsertId();
        // Nová měna může rovnou nést vlastní účet (typicky EUR účet vedle CZK) —
        // ať se do registru dostane hned, ne až prvním importem výpisu.
        OwnBankAccountRegistrar::syncFromCurrency($pdo, $sid, $newId);
        $this->log($request, 'currency.created', $newId, ['supplier_id' => $sid, 'code' => $code, 'label' => $label]);
        return Json::ok($response, ['id' => $newId, 'code' => $code], 201);
    }

    public function deleteCurrency(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) return Json::error($response, 'validation_failed', 'Neplatné id.', 400);

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT supplier_id FROM currencies WHERE id = ?');
        $stmt->execute([$id]);
        $ownerSid = (int) $stmt->fetchColumn();
        if ($ownerSid === 0) return Json::error($response, 'not_found', 'Měna nenalezena.', 404);
        if ($ownerSid !== $sid) return Json::error($response, 'wrong_supplier', 'Tato měna patří jinému supplier.', 403);

        // Použití napříč doklady (vydané, přijaté vč. platební měny, zakázky, pravidelné fakturace).
        $stmt = $pdo->prepare(
            'SELECT (
                (SELECT COUNT(*) FROM invoices WHERE currency_id = ?)
              + (SELECT COUNT(*) FROM purchase_invoices WHERE currency_id = ? OR payment_currency_id = ?)
              + (SELECT COUNT(*) FROM projects WHERE currency_id = ?)
              + (SELECT COUNT(*) FROM recurring_invoice_templates WHERE currency_id = ?)
            ) AS cnt'
        );
        $stmt->execute([$id, $id, $id, $id, $id]);
        $deps = (int) $stmt->fetchColumn();
        if ($deps > 0) {
            return Json::error($response, 'has_dependencies', "Měnu nelze smazat — je použita na $deps dokladech.", 409);
        }
        try {
            $pdo->prepare('DELETE FROM currencies WHERE id = ?')->execute([$id]);
        } catch (\PDOException $e) {
            // Pojistka pro ostatní FK (cache přepočtů, výchozí měna klienta/dodavatele apod.).
            if ($e->getCode() === '23000') {
                return Json::error($response, 'has_dependencies', 'Měnu nelze smazat — je použita v jiných záznamech.', 409);
            }
            throw $e;
        }
        $this->log($request, 'currency.deleted', $id, []);
        return Json::ok($response, ['deleted' => true]);
    }

    // ============================================================================
    // UNITS (číselník měrných jednotek — globální, ne per-supplier)
    // ============================================================================

    public function listUnits(Request $request, Response $response): Response
    {
        $rows = $this->db->pdo()->query(
            'SELECT u.id, u.code, u.label_cs, u.label_en, u.is_default, u.display_order,
                    (SELECT COUNT(*) FROM invoice_items i WHERE i.unit = u.code) AS items_count
               FROM units u ORDER BY u.display_order, u.code'
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id']            = (int) $r['id'];
            $r['is_default']    = (bool) $r['is_default'];
            $r['display_order'] = (int) $r['display_order'];
            $r['items_count']   = (int) $r['items_count'];
        }
        return Json::ok($response, $rows);
    }

    public function createUnit(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $b = (array) ($request->getParsedBody() ?? []);
        $code = trim((string) ($b['code'] ?? ''));
        if ($code === '' || mb_strlen($code) > 20) {
            return Json::error($response, 'validation_failed', 'code je povinný (max 20 znaků).', 400);
        }
        try {
            $this->db->pdo()->prepare(
                'INSERT INTO units (code, label_cs, label_en, is_default, display_order)
                 VALUES (?,?,?,?,?)'
            )->execute([
                $code,
                (string) ($b['label_cs'] ?? $code),
                (string) ($b['label_en'] ?? $code),
                !empty($b['is_default']) ? 1 : 0,
                (int) ($b['display_order'] ?? 0),
            ]);
        } catch (\PDOException $e) {
            return Json::error($response, 'duplicate', 'Jednotka s tímto kódem už existuje.', 409);
        }
        $id = (int) $this->db->pdo()->lastInsertId();
        if (!empty($b['is_default'])) $this->makeOnlyDefaultUnit($id);
        $this->log($request, 'unit.created', $id, ['code' => $code]);
        return Json::ok($response, ['id' => $id], 201);
    }

    public function updateUnit(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) return Json::error($response, 'validation_failed', 'Neplatné id.', 400);
        $b = (array) ($request->getParsedBody() ?? []);
        $allowed = ['code', 'label_cs', 'label_en', 'is_default', 'display_order'];
        $sets = []; $params = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $b)) {
                $sets[] = "$f = ?";
                $params[] = $f === 'is_default'
                    ? ((int) (bool) $b[$f])
                    : ($f === 'display_order' ? (int) $b[$f] : $b[$f]);
            }
        }
        if (empty($sets)) return Json::ok($response, ['ok' => true]);
        $params[] = $id;
        try {
            $this->db->pdo()->prepare('UPDATE units SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        } catch (\PDOException $e) {
            return Json::error($response, 'duplicate', 'Jednotka s tímto kódem už existuje.', 409);
        }
        if (!empty($b['is_default'])) $this->makeOnlyDefaultUnit($id);
        $this->log($request, 'unit.updated', $id, ['fields' => array_keys($b)]);
        return Json::ok($response, ['ok' => true]);
    }

    public function deleteUnit(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT code FROM units WHERE id = ?');
        $stmt->execute([$id]);
        $code = (string) $stmt->fetchColumn();
        if ($code === '') return Json::error($response, 'not_found', 'Jednotka nenalezena.', 404);

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM invoice_items WHERE unit = ?');
        $stmt->execute([$code]);
        $count = (int) $stmt->fetchColumn();
        if ($count > 0) {
            return Json::error($response, 'has_dependencies',
                "Jednotku nelze smazat — používá ji $count položek faktur.", 409);
        }
        $pdo->prepare('DELETE FROM units WHERE id = ?')->execute([$id]);
        $this->log($request, 'unit.deleted', $id, ['code' => $code]);
        return Json::ok($response, ['deleted' => true]);
    }

    private function makeOnlyDefaultUnit(int $id): void
    {
        $this->db->pdo()->prepare('UPDATE units SET is_default = 0 WHERE id <> ?')->execute([$id]);
    }

    private function guard(Request $request, Response $response, ?Response &$err): bool
    {
        if (!RequestAuthorization::allows($request, 'settings.company.write', AccessLevel::WRITE)) {
            $err = Json::error($response, 'forbidden', 'Pouze admin.', 403);
            return false;
        }
        $err = null;
        return true;
    }

    private function log(Request $request, string $action, ?int $entityId, array $payload): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, (int) ($user['id'] ?? 0), 'supplier', $entityId, $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
