<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Branding\AccentColor;
use MyInvoice\Service\Branding\BrandingProfileOverlay;

/**
 * Jediný zdroj `supplier` kontextu pro e-mailové šablony vázané na doklad.
 *
 * Why: `_layout.html.twig` rozhoduje o brandingu podle
 * `supplier.email_branding_enabled` + `supplier.logo_path`, a `Mailer` podle
 * `supplier.id` připojuje logo jako CID a vybírá per-supplier SMTP profil.
 * Když builder vrátí jen „patičkovou" podmnožinu bez těchto polí, e-mail se
 * odešle pod výchozí hlavičkou MyÚčto.cz — a co je horší, `Mailer::sendTemplate`
 * doplňuje výchozího dodavatele jen tehdy, když `supplier` NENÍ nastaven vůbec,
 * takže neúplné pole záchranný fallback zablokuje. Přesně tak přišel o branding
 * e-mail se žádostí o schválení výkazu práce.
 *
 * Logika je netriviální (snapshot vs. živá data vs. overlay profilu), takže
 * místo kopie v každém builderu žije tady.
 */
final class SupplierEmailContext
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Kompletní supplier kontext pro e-mail, který se neváže na doklad, ale ví,
     * za kterou firmu se posílá (cronové upomínky apod.).
     *
     * Bez něj by `Mailer` sáhl po svém fallbacku `MIN(id) FROM supplier`, což je
     * u jednofiremní instalace neškodné, ale u víc firem pošle e-mail s logem
     * a SMTP profilem cizí firmy.
     */
    public function forSupplier(int $supplierId): ?array
    {
        if ($supplierId <= 0) return null;

        $stmt = $this->db->pdo()->prepare(
            'SELECT s.id, s.company_name, s.display_name, s.tagline, s.street, s.city, s.zip,
                    s.email, s.phone, s.web,
                    s.email_branding_enabled, s.email_accent_color, s.logo_path,
                    s.branding_profiles_enabled, s.default_branding_profile_id,
                    co.name_cs AS country
               FROM supplier s
          LEFT JOIN countries co ON co.id = s.country_id
              WHERE s.id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return null;

        // Patička a e-mailový profil existují jen v brandingových profilech (supplier je nemá),
        // overlay je případně doplní.
        $row['email_footer'] = null;
        $row['email_profile_id'] = null;

        // Výchozí profil firmy přebije živá nastavení, stejně jako u dokladů.
        if (!empty($row['branding_profiles_enabled']) && !empty($row['default_branding_profile_id'])) {
            $bStmt = $this->db->pdo()->prepare(
                'SELECT * FROM branding_profiles WHERE id = ? AND supplier_id = ? AND is_active = 1'
            );
            $bStmt->execute([(int) $row['default_branding_profile_id'], $supplierId]);
            $br = $bStmt->fetch(\PDO::FETCH_ASSOC);
            if ($br !== false) {
                $row = BrandingProfileOverlay::apply($row, $br);
            }
        }

        $row['email_branding_enabled'] = (bool) ($row['email_branding_enabled'] ?? false);
        $row['email_accent_color'] = (string) ($row['email_accent_color'] ?: '#3B2D83');
        $row['accent_soft'] = AccentColor::emailBackground($row['email_branding_enabled'], $row['email_accent_color']);

        return $row;
    }

    /**
     * Kompletní supplier kontext pro e-mail k dokladu (podle invoice.supplier_id).
     * Preferuje supplier_snapshot, fallback na živý supplier+countries lookup.
     */
    public function forInvoice(array $invoice): ?array
    {
        $row = null;
        // 1. Snapshot — zmrazená patička (company_name, adresa, kontakt)
        if (!empty($invoice['supplier_snapshot'])) {
            $snap = is_string($invoice['supplier_snapshot'])
                ? json_decode($invoice['supplier_snapshot'], true)
                : $invoice['supplier_snapshot'];
            if (is_array($snap)) {
                $row = [
                    'company_name' => $snap['company_name'] ?? '',
                    'display_name' => $snap['display_name'] ?? null,
                    'tagline'      => $snap['tagline'] ?? null,
                    'street'       => $snap['street'] ?? '',
                    'city'         => $snap['city'] ?? '',
                    'zip'          => $snap['zip'] ?? '',
                    'country'      => $snap['country_name_cs'] ?? '',
                    'email'        => $snap['email'] ?? null,
                    'phone'        => $snap['phone'] ?? null,
                    'web'          => $snap['web'] ?? null,
                    'email_footer' => $snap['email_footer'] ?? null,
                    'branding_profile_id' => $snap['branding_profile_id'] ?? null,
                    'email_branding_enabled' => (bool) ($snap['email_branding_enabled'] ?? false),
                    'email_accent_color' => $snap['email_accent_color'] ?? '#3B2D83',
                    'logo_path' => $snap['logo_path'] ?? null,
                    'email_profile_id' => $snap['email_profile_id'] ?? null,
                ];
            }
        }
        // 2. Živý fallback pro text patičky (pokud chybí snapshot)
        $sid = (int) ($invoice['supplier_id'] ?? 0);
        if ($row === null && $sid > 0) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT s.id, s.company_name, s.display_name, s.tagline, s.street, s.city, s.zip,
                        s.email, s.phone, s.web, co.name_cs AS country
                   FROM supplier s
              LEFT JOIN countries co ON co.id = s.country_id
                  WHERE s.id = ?'
            );
            $stmt->execute([$sid]);
            $live = $stmt->fetch(\PDO::FETCH_ASSOC);
            $row = $live ?: null;
        }
        // `id` musí být přítomné pro SafeLogoPath v sink fázích (Mailer::sendTemplate
        // + addLogoDisplaySize). Když row vznikl ze snapshotu, sid může chybět.
        if ($row !== null && empty($row['id']) && $sid > 0) {
            $row['id'] = $sid;
        }

        // 3. Profilový branding vystaveného dokladu je immutable ve snapshotu.
        //    Bez profilového snapshotu je základ VŽDY živý branding ze supplier
        //    (shodně s PDF resolveSupplier: logo/barva/přepínač nejsou ve snapshotu) —
        //    platí pro vypnutý modul i pro zapnutý modul u dokladu bez vybraného
        //    profilu. Explicitní profil (draft) tento základ přebije živým overlayem.
        if ($row !== null && $sid > 0) {
            $hasSnapshotProfile = !empty($row['branding_profile_id']);
            if (!$hasSnapshotProfile) {
                $legacyStmt = $this->db->pdo()->prepare(
                    'SELECT branding_profiles_enabled, email_branding_enabled, email_accent_color, logo_path
                       FROM supplier WHERE id = ?'
                );
                $legacyStmt->execute([$sid]);
                $legacy = $legacyStmt->fetch(\PDO::FETCH_ASSOC);

                if ($legacy !== false) {
                    $row['email_branding_enabled'] = (bool) $legacy['email_branding_enabled'];
                    $row['email_accent_color'] = (string) ($legacy['email_accent_color'] ?: '#3B2D83');
                    $row['logo_path'] = $legacy['logo_path'] ?: null;
                }

                if ($legacy !== false && !empty($legacy['branding_profiles_enabled']) && !empty($invoice['branding_profile_id'])) {
                    $profileId = (int) $invoice['branding_profile_id'];
                    $bStmt = $this->db->pdo()->prepare(
                        'SELECT bp.* FROM supplier s
                           JOIN branding_profiles bp ON bp.id = ?
                                                    AND bp.supplier_id = s.id AND bp.is_active = 1
                          WHERE s.id = ? AND s.branding_profiles_enabled = 1'
                    );
                    $bStmt->execute([$profileId, $sid]);
                    $br = $bStmt->fetch(\PDO::FETCH_ASSOC);
                    if ($br !== false) {
                        $row = BrandingProfileOverlay::apply($row, $br);
                    }
                }
            }
            $row['email_branding_enabled'] = (bool) ($row['email_branding_enabled'] ?? false);
            $row['email_accent_color'] = (string) ($row['email_accent_color'] ?? '#3B2D83');
            $row['accent_soft'] = AccentColor::emailBackground($row['email_branding_enabled'], $row['email_accent_color']);
        }

        return $row;
    }
}
