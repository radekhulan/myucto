<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Service\Ares\AresClient;
use MyInvoice\Support\CompanyIdNormalizer;

/**
 * Resolve klienta z importovaného XML/ISDOC:
 *   1. Lookup v `clients` podle (supplier_id, ic) — pokud existuje, vrátí jeho id.
 *   2. Pokud ne, ARES lookup podle IČO — preferovaná fakturační adresa z ARES.
 *   3. Pokud registr subjekt nepotvrdí, hledá existující kartu podle klíče názvu
 *      a teprve pak zakládá kartu jen z údajů dokladu (chybějící adresa zůstane
 *      prázdná) s poznámkou ke kontrole.
 *   4. Vytvoří nový clients row, vrátí id.
 *
 * Vstup: array z parseru (`company_name, ic, dic, street, city, zip, country_iso2, email, phone`).
 * Pokud je email dostupný, použije se jako volitelný `main_email`.
 *
 * Doklad bez karty nechat nejde: `invoices.client_id` i `purchase_invoices.vendor_id`
 * jsou NOT NULL a na kartě stojí párování plateb, saldo i kontrolní hlášení.
 */
final class ClientResolver
{
    public const UNVERIFIED_NOTE = 'Karta založena importem dokladu bez ověření v registru (ARES/VIES). '
        . 'Zkontrolujte název, IČO, DIČ a adresu, případně doklad přiřaďte ke správné kartě.';

    public function __construct(
        private readonly Connection $db,
        private readonly ClientRepository $clients,
        private readonly AresClient $ares,
        private readonly \MyInvoice\Service\Ares\ViesClient $vies,
        private readonly \MyInvoice\Service\Ares\VendorVatPayerResolver $vatPayer,
    ) {}

    /**
     * Resolve klient pro vydané faktury (default — `is_customer=1, is_vendor=0`).
     *
     * @param array<string,?string> $parsedClient
     * @return array{id:int, created:bool, registry_verified?:bool}
     */
    public function resolve(array $parsedClient, int $supplierId): array
    {
        return $this->resolveAny($parsedClient, $supplierId, isCustomer: true, isVendor: false);
    }

    /**
     * Resolve vendor pro přijaté faktury — `is_vendor=1`. Pokud existující klient
     * matchuje IČ ale dosud nebyl vendor, jen flagne is_vendor=1 (zachová is_customer).
     * Dual-role firma OK.
     *
     * @param array<string,?string> $parsedVendor
     * @return array{id:int, created:bool, role_added:bool, is_vat_payer:?bool}
     */
    public function resolveVendor(array $parsedVendor, int $supplierId): array
    {
        $r = $this->resolveAny($parsedVendor, $supplierId, isCustomer: false, isVendor: true);

        // Pokud našel existující (created=false), zkontroluj is_vendor flag a doplň.
        $roleAdded = false;
        if (!$r['created']) {
            $stmt = $this->db->pdo()->prepare('SELECT is_vendor FROM clients WHERE id = ?');
            $stmt->execute([$r['id']]);
            $isVendor = (int) $stmt->fetchColumn();
            if ($isVendor === 0) {
                $this->clients->markAsVendor($r['id']);
                $roleAdded = true;
            }
        }

        // Plátcovství DPH dodavatele z ARES (CZ) / VIES (zahraniční) — uloží na klienta
        // (nový i existující). Z toho pak import vynutí vat_deduction='none' u neplátce.
        //
        // `vat_dic` (DIČ k DPH / skupinová registrace, issue #8) jde ZÁMĚRNĚ jen sem, do
        // ověření plátcovství. Dohledání i zakládání karty výše zůstává na `dic` (DIČ
        // subjektu) — kdyby se do lookupu dostalo skupinové DIČ, přestala by se párovat
        // existující karta dodavatele a založila by se duplicitní.
        $vat = $this->vatPayer->resolveAndPersist(
            $r['id'],
            $parsedVendor['ic'] ?? null,
            $parsedVendor['dic'] ?? null,
            $parsedVendor['vat_dic'] ?? null,
        );

        return ['id' => $r['id'], 'created' => $r['created'], 'role_added' => $roleAdded, 'is_vat_payer' => $vat['is_vat_payer']];
    }

    /**
     * @param array<string,?string> $parsed
     * @return array{id:int, created:bool, registry_verified?:bool}
     */
    private function resolveAny(array $parsed, int $supplierId, bool $isCustomer, bool $isVendor): array
    {
        $ic = $this->normalizeIc($parsed['ic'] ?? null);
        $companyName = trim((string) ($parsed['company_name'] ?? ''));

        // Víc karet se stejným IČO/DIČ (pobočky, provozovny, nová karta vedle staré) je
        // legitimní stav. Přednost má aktivní karta, v ní karta se shodným názvem, jinak
        // nejstarší karta: dřív `LIMIT 1` bez řazení přiřadil doklad libovolné z nich.
        // Archivovaná karta zůstává poslední možností, ať se nezakládá duplicita.
        $preferName = ' ORDER BY archived_at IS NULL DESC, company_name = ? DESC, id LIMIT 1';

        // 1a. Lookup podle (supplier_id, ic) — primární klíč pro CZ entity. Uložené
        // IČO normalizujeme stejně jako vstup (LPAD na 8 míst) — starší karty
        // založené před opravou BUG 2/FR 2 mohly mít IČO uložené bez úvodní nuly.
        if ($ic !== null) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT id FROM clients WHERE supplier_id = ? AND ' . \MyInvoice\Support\CompanyIdNormalizer::icSql('ic') . ' = ?' . $preferName
            );
            $stmt->execute([$supplierId, $ic, $companyName]);
            $existing = $stmt->fetchColumn();
            if ($existing !== false) {
                return ['id' => (int) $existing, 'created' => false];
            }
        }

        // 1b. Lookup podle DIČ (pro EU dodavatele bez CZ IČO — Anthropic PBC, Stripe, ...).
        // Porovnání normalizované na obou stranách (bez mezer/pomlček, velká
        // písmena) — jinak stejné DIČ zapsané jednou s mezerou a podruhé bez ní
        // založí duplicitní kartu (FR 2, vendor bugreport 2026-08-06).
        $dicRaw = trim((string) ($parsed['dic'] ?? ''));
        $dicNormalized = \MyInvoice\Support\CompanyIdNormalizer::dic($dicRaw);
        if ($ic === null && $dicNormalized !== null) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT id FROM clients WHERE supplier_id = ? AND UPPER(REGEXP_REPLACE(dic, '[^A-Za-z0-9]', '')) = ?" . $preferName
            );
            $stmt->execute([$supplierId, $dicNormalized, $companyName]);
            $existing = $stmt->fetchColumn();
            if ($existing !== false) {
                return ['id' => (int) $existing, 'created' => false];
            }
        }

        // 1c. Last resort — exact company_name match (pro zahraniční bez IČO i DIČ).
        // Bez tohoto by AI/inbox scan vytvořila duplikát pro každou fakturu.
        if ($ic === null && $dicRaw === '' && $companyName !== '') {
            $stmt = $this->db->pdo()->prepare(
                "SELECT id FROM clients
                  WHERE supplier_id = ?
                    AND (ic IS NULL OR ic = '')
                    AND (dic IS NULL OR dic = '')
                    AND company_name = ?
                  LIMIT 1"
            );
            $stmt->execute([$supplierId, $companyName]);
            $existing = $stmt->fetchColumn();
            if ($existing !== false) {
                return ['id' => (int) $existing, 'created' => false];
            }
        }

        $parsedClient = $parsed;

        // 2. ARES merge — pokud IČO je české (8 číslic) a ARES odpoví
        $aresData = null;
        if ($ic !== null && strlen($ic) === 8) {
            $resp = $this->ares->lookup($ic);
            if ($resp !== null && ($resp['found'] ?? false) && isset($resp['data'])) {
                $aresData = $resp['data'];
            }
        }

        // 2b. VIES fallback — pokud nemáme IČO ale máme DIČ (zahraniční dodavatel),
        // zkusíme VIES validation pro company_name + adresu z EU registru.
        $viesData = null;
        $viesValid = false;
        $rawDic = trim((string) ($parsedClient['dic'] ?? ''));
        if ($aresData === null && $ic === null && $rawDic !== '') {
            try {
                $viesResp = $this->vies->lookup($rawDic);
                $viesValid = !empty($viesResp['valid']);
                if ($viesValid && !empty($viesResp['data'])) {
                    $viesData = $viesResp['data'];
                }
            } catch (\Throwable) {
                // VIES timeout / network failure — silent fallback na parsedClient data
            }
        }

        // Registr subjekt nepotvrdil (IČO není v ARES, typicky špatně přečtené; DIČ
        // neprošlo VIES). Než se založí karta jen z vytěžených údajů, zkusí se existující
        // karta podle názvu bez právní formy, diakritiky a interpunkce. Jinak by každé
        // jinak přečtené IČO nebo název založilo další variantu téže firmy.
        $registryVerified = $aresData !== null || $viesValid;
        if (!$registryVerified) {
            $byName = $this->findByNameKey($supplierId, $companyName);
            if ($byName !== null) {
                return ['id' => $byName, 'created' => false, 'registry_verified' => false];
            }
        }

        // 3. Sestavení dat klienta — ARES → VIES → AI/XML extracted data (priority)
        // main_email je od #221 nullable — neznámý e-mail necháváme prázdný,
        // dřívější placeholder 'unknown@import.local' vypadal jako reálná adresa.
        $email = trim((string) ($parsedClient['email'] ?? '')) ?: null;

        // Aktivní skupina DPH bez DIČ skupiny v ARES: plátce je doložený, DIČ vezmeme
        // z dokladu (DIČ k DPH má přednost). Bez toho by vznikla karta plátce bez DIČ.
        if ($aresData !== null && ($aresData['dic'] ?? '') === '' && !empty($aresData['is_vat_payer'])) {
            $aresData['dic'] = trim((string) ($parsedClient['vat_dic'] ?? '')) ?: (trim((string) ($parsedClient['dic'] ?? '')) ?: null);
        }

        // Adresa jen z registru nebo z dokladu. Chybějící pole zůstávají prázdná: náhrady
        // „—" a „00000" vypadaly v adresáři jako skutečné údaje.
        $data = [
            'company_name' => $aresData['company_name']
                ?? $viesData['company_name']
                ?? (($companyName !== '' ? $companyName : null) ?? $ic ?? ($rawDic !== '' ? $rawDic : null) ?? 'Importovaný klient'),
            'ic'           => $ic,
            'dic'          => $aresData['dic'] ?? $viesData['dic'] ?? ($parsedClient['dic'] ?? null) ?: null,
            'street'       => trim((string) ($aresData['street'] ?? $viesData['street'] ?? ($parsedClient['street'] ?? ''))),
            'city'         => trim((string) ($aresData['city']   ?? $viesData['city']   ?? ($parsedClient['city']   ?? ''))),
            'zip'          => trim((string) ($aresData['zip']    ?? $viesData['zip']    ?? ($parsedClient['zip']    ?? ''))),
            'country_iso2' => $aresData['country_iso2'] ?? $viesData['country_iso2']
                ?? ($parsedClient['country_iso2'] ?? 'CZ') ?: 'CZ',
            'main_email'   => $email,
            'phone'        => $parsedClient['phone'] ?? null,
            'web'          => $parsedClient['web'] ?? null,
            'language'     => 'cs',
            'is_customer'  => $isCustomer,
            'is_vendor'    => $isVendor,
        ];
        if (!$registryVerified) {
            $data['note'] = self::UNVERIFIED_NOTE;
        }

        // 4. Create — vytěžené hodnoty nemají zaručenou délku (AI vrátí do PSČ celý
        // řádek „AT-1230 Wien", do DIČ i s popiskem); na sloupce je upraví repository
        // přes ClientFieldLimits::fit().
        $id = $this->clients->create($data, $supplierId);
        return ['id' => $id, 'created' => true, 'registry_verified' => $registryVerified];
    }

    /**
     * Existující karta firmy se stejným klíčem názvu ({@see CompanyIdNormalizer::nameKey()}).
     * Kandidáty zúží prefix prvního slova názvu po indexu `company_name` (collation
     * nerozlišuje velikost písmen ani diakritiku), klíč se porovná v PHP.
     */
    private function findByNameKey(int $supplierId, string $companyName): ?int
    {
        $key = CompanyIdNormalizer::nameKey($companyName);
        if ($key === null || !preg_match('/^[\p{L}\p{N}]+/u', $companyName, $m)) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, company_name FROM clients
              WHERE supplier_id = ? AND company_name LIKE ?
              ORDER BY archived_at IS NULL DESC, id
              LIMIT 200'
        );
        $stmt->execute([$supplierId, $m[0] . '%']);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (CompanyIdNormalizer::nameKey((string) $row['company_name']) === $key) {
                return (int) $row['id'];
            }
        }
        return null;
    }

    /**
     * Zero-pad na 8 míst (kanonický tvar IČO) — {@see \MyInvoice\Support\CompanyIdNormalizer}.
     * Bez toho by AI/ISDOC import u firmy s IČO začínajícím nulou nenašel existující
     * kartu (lookup podle (supplier_id, ic) v resolveAny() níže) a založil duplicitní
     * kartu dodavatele (FR 2, vendor bugreport 2026-08-06).
     */
    private function normalizeIc(?string $ic): ?string
    {
        return \MyInvoice\Support\CompanyIdNormalizer::ic($ic);
    }
}
