<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Accounting\Vat\VatClearingTrigger;
use MyInvoice\Service\Demo\DemoMode;
use MyInvoice\Service\Validation\XmlSchemaValidator;

/**
 * Sjednocený archiving + XSD validation pipeline pro EPO XML.
 *
 * Volaný z DphPriznani/KontrolniHlaseni/SouhrnneHlaseni/IncomeTax action `download()`
 * **před** posláním XML response. Vrátí ID archivovaného záznamu (pro odkaz v UI).
 *
 * B8/H7 (audit 2026-07): po archivaci DPH přiznání/hlášení (dphdp3/dphkh1) tiše posune
 * měkký zámek účtování (accounting_supplier_settings.locked_until) na konec vykázaného
 * období — jen vpřed (MAX). Doklady s DUZP v podaném období tím backend chrání proti
 * dodatečnému zaúčtování/re-postu. Účetní může zámek posunout zpět přes admin endpoint.
 *
 * Posun zámku (dorevize B8, HIGH#1) je záměrně KONZERVATIVNÍ — zamyká se JEN:
 *  - u výkazu zakládajícího neměnnost období (dphdp3/dphkh1; dphshv NE — viz níž),
 *  - když je akce oprávněná zamykat ($allowLock; readonly GET/download NEmutuje stav),
 *  - když XSD validace neselhala (neplatné přiznání nezamyká),
 *  - a jen pro UZAVŘENÉ období (konec < dnešek) — probíhající/budoucí období se nikdy
 *    nezamyká, jinak by pouhé stažení náhledu běžného měsíce zmrazilo celé účtování.
 * Rozhodovací logika je v čisté {@see self::lockDateFor()} (jednotkově testovatelná).
 */
final class TaxSubmissionArchiver
{
    /**
     * DPH výkazy, jejichž archivace posouvá zámek účtování (VAT-lock). Souhrnné hlášení
     * (dphshv) je informativní výkaz (EC sales list, § 102) — nezakládá neměnnost
     * účetního období, proto zámek NEposouvá (dorevize B8, LOW#4).
     */
    private const VAT_LOCK_FORMS = ['dphdp3', 'dphkh1'];

    public function __construct(
        private readonly TaxSubmissionRepository $repo,
        private readonly XmlSchemaValidator $validator,
        private readonly AccountingSupplierSettingsRepository $settings,
        private readonly VatClearingTrigger $vatClearing,
        private readonly DemoMode $demo,
    ) {}

    /**
     * Archivuje VYGENEROVANÝ/STAŽENÝ XML + spustí XSD validation (pokud schema existuje).
     *
     * **Audit §2.4:** archivace = technická historie snapshotu (status `downloaded`), NIKOLI
     * podání. Proto zde NEPOSOUVÁ daňový zámek — ten se posune až explicitním {@see markSubmitted()}
     * (stav `submitted` doložený časem podání + identifikátorem podatelny). Parametr `$allowLock`
     * je zachován kvůli zpětné kompatibilitě volajících, ale na archivaci už nemá vliv.
     *
     * @param array<string,mixed> $summary
     */
    public function archive(
        int $supplierId,
        string $formCode,
        int $year,
        ?int $month,
        ?int $quarter,
        string $xml,
        array $summary,
        ?int $generatedBy,
        bool $allowLock = true,
        string $variant = 'B',
    ): array {
        // XSD validation
        $validation = $this->validator->validate($xml, $formCode);

        // Ukázka stahuje XML přes GET a návštěvník tím nesmí zakládat snímky do
        // `tax_submissions` (ani přepočítat zúčtování DPH). `submission_id` null
        // říká volajícím, že není k čemu cokoli dalšího navázat.
        if ($this->demo->enabled()) {
            return [
                'submission_id'     => null,
                'validation_status' => $validation['status'],
                'validation_errors' => $validation['errors'],
                'status'            => 'not_archived',
            ];
        }

        $id = $this->repo->archive(
            $supplierId,
            $formCode,
            $year,
            $month,
            $quarter,
            $xml,
            $summary,
            $validation['status'],
            $validation['errors'],
            $generatedBy,
            $variant,
            'downloaded',
        );

        // Zúčtování DPH (migrace 1332): koncept přiznání UŽ EXISTUJÍCÍ zúčtovací doklad
        // období obnoví, aby neukazoval jinou daň než ta, kterou účetní právě vidí.
        // NOVÝ doklad nezakládá — archivace snímku není podání (§2.4 výše) a účetní
        // zápis z pouhého stažení náhledu vzniknout nesmí. Rozhodovací logika a její
        // odůvodnění jsou ve {@see VatClearingTrigger}. `$allowLock` je tady použit
        // v původním významu „akce smí mutovat účetní stav" — readonly GET nemutuje.
        if ($allowLock) {
            $this->vatClearing->onSubmissionDrafted(
                [
                    'id'             => $id,
                    'supplier_id'    => $supplierId,
                    'form_code'      => $formCode,
                    'period_year'    => $year,
                    'period_month'   => $month,
                    'period_quarter' => $quarter,
                    'form_variant'   => $variant,
                    'submitted_at'   => null,
                ],
                ['user_id' => $generatedBy],
            );
        }

        return [
            'submission_id'     => $id,
            'validation_status' => $validation['status'],
            'validation_errors' => $validation['errors'],
            'status'            => 'downloaded',
        ];
    }

    /**
     * Označí archivovaný snapshot jako PROKAZATELNĚ PODANÝ (audit §2.4) a TEPRVE TEĎ
     * — pokud jde o VAT výkaz uzavřeného období s platnou validací — posune daňový zámek.
     *
     * Pořadí je zásadní: nejdřív přepíšeme stav na `submitted` (+ čas podání, identifikátor
     * podatelny), pak z uloženého (nyní submitted) záznamu rozhodneme o zámku přes čistou
     * {@see lockDateFor()}. Posun zámku je best-effort — chyba nesmí shodit označení podání.
     *
     * @return array<string,mixed>|null aktualizovaný záznam nebo null (nenalezen/cizí tenant)
     */
    public function markSubmitted(
        int $submissionId,
        int $supplierId,
        string $submittedAt,
        ?string $submissionRef,
        ?int $submittedBy,
    ): ?array {
        $row = $this->repo->markSubmitted($submissionId, $supplierId, $submittedAt, $submissionRef, $submittedBy);
        if ($row === null) {
            return null;
        }

        // ── Zúčtování DPH PŘED posunem zámku (migrace 1332) ────────────────────────────
        // Podání je autoritativní okamžik, kdy je daň za období známá: až teď se
        // zúčtovací doklad (343.100/343.200 → 343.900) zaúčtuje nebo přepočítá, takže
        // hlavní kniha ukazuje přesně tu daň, která odešla na FÚ. Opravné i dodatečné
        // přiznání sem přijdou znovu a doklad přepočítají.
        //
        // POŘADÍ JE KRITICKÉ, NE KOSMETICKÉ: `advanceLockedUntil()` níž zamkne datum
        // konce vykázaného období — a přesně to je datum účetního případu zúčtovacího
        // dokladu. Po zámku by ho PostingService odmítl s `date_locked`, takže tenhle
        // krok MUSÍ zůstat nad ním. Selhání nikdy neshodí označení „podáno" (nález si
        // vyzvedne kontrola uzávěrky `vat_clearing_stale`) — ošetřeno ve VatClearingTrigger.
        // Výsledek jde zpátky volajícímu (a tím do odpovědi endpointu „označit jako podané"),
        // protože právě tohle je okamžik, kdy má účetní vědět, že se zúčtování NEZAÚČTOVALO —
        // typicky u dodatečného přiznání do období, kterému zámek nikdo nevrátil zpět.
        $clearing = $this->vatClearing->onSubmissionFiled($row, ['user_id' => $submittedBy]);
        if ($clearing !== null) {
            $row['vat_clearing'] = $clearing;
        }

        // VAT-lock (B8/H7 + §2.4): posun zámku váže na PODÁNÍ, ne na stažení. allowLock=true,
        // protože submit je vědomá zapisující akce oprávněného uživatele; ostatní podmínky
        // (typ výkazu, validace, uzavřené období) řeší lockDateFor().
        $lockDate = self::lockDateFor(
            (string) $row['form_code'],
            (int) $row['period_year'],
            $row['period_month'] !== null ? (int) $row['period_month'] : null,
            $row['period_quarter'] !== null ? (int) $row['period_quarter'] : null,
            (string) $row['validation_status'],
            true,
            date('Y-m-d'),
        );
        // Zúčtování, které selhalo nečekanou chybou (typicky odvolaná transakce 1020/1213),
        // se musí dát zopakovat. Zámek data konce období by to znemožnil (`date_locked`),
        // takže se posune až s příštím podáním nebo ručně. `skipped` (zavřené období,
        // zámek) je naopak vědomý stav a zámek neblokuje.
        if (($clearing['status'] ?? null) === 'error') {
            $lockDate = null;
        }
        if ($lockDate !== null) {
            try {
                $this->settings->advanceLockedUntil($supplierId, $lockDate);
            } catch (\Throwable) {
                // ignore — zámek se doplní při příštím podání / ručně přes admin endpoint
            }
        }

        return $row;
    }

    /**
     * Rozhodne, zda a k jakému datu archivace posune měkký zámek účtování. Čistá
     * (bez DB/IO), aby šla jednotkově testovat. Vrací datum konce vykázaného období
     * (Y-m-d) k zamčení, nebo null když se NEmá zamykat.
     *
     * Zamyká se JEN, pokud jsou splněny VŠECHNY podmínky:
     *  - $allowLock === true — akce smí mutovat zámek (readonly cesta předá false),
     *  - $validationStatus !== 'failed' — neplatné/neodeslatelné přiznání nezamyká
     *    ('passed' i 'skipped' zamykají — skipped = XSD schema není nainstalované),
     *  - $formCode je VAT výkaz zakládající neměnnost (dphdp3/dphkh1),
     *  - konec vykázaného období je STRIKTNĚ před dneškem ($today) — probíhající ani
     *    budoucí období se NIKDY nezamyká (jinak náhled běžného měsíce zmrazí účtování).
     *
     * @return string|null datum zámku nebo null
     */
    public static function lockDateFor(
        string $formCode,
        int $year,
        ?int $month,
        ?int $quarter,
        string $validationStatus,
        bool $allowLock,
        string $today,
    ): ?string {
        if (!$allowLock || $validationStatus === 'failed') {
            return null;
        }
        if (!in_array($formCode, self::VAT_LOCK_FORMS, true)) {
            return null;
        }
        $lockDate = self::periodEndDate($year, $month, $quarter);
        // Nikdy nezamykat probíhající ani budoucí období (konec >= dnešek).
        if ($lockDate === null || $lockDate >= $today) {
            return null;
        }
        return $lockDate;
    }

    /**
     * Konec vykázaného období: měsíc → poslední den měsíce; kvartál → poslední den
     * kvartálu. Bez měsíce i kvartálu neexistuje jednoznačné vykázané období —
     * vrací null (nezamykat celý rok; dorevize B8, LOW#4).
     */
    private static function periodEndDate(int $year, ?int $month, ?int $quarter): ?string
    {
        if ($month !== null && $month >= 1 && $month <= 12) {
            return (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))
                ->modify('last day of this month')->format('Y-m-d');
        }
        if ($quarter !== null && $quarter >= 1 && $quarter <= 4) {
            return (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $quarter * 3)))
                ->modify('last day of this month')->format('Y-m-d');
        }
        return null;
    }
}
