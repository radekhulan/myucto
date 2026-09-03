<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\BankPostingSuggestionRepository;
use MyInvoice\Service\Accounting\Bank\TransferAutoPolicyInterface;
use PDO;

final class AutoPostingPolicyService implements TransferAutoPolicyInterface
{
    private const LEVEL_ORDER = ['off' => 0, 'suggest' => 1, 'auto' => 2];

    /**
     * Účty, kde úhrada bez předpisu znamená chybu: závazek musí vzniknout dřív, než ho zaplatíš
     * (336 až po mzdové rekapitulaci, 342 po srážce, 343 po přiznání k DPH, 345 po vyměření).
     * Debetní zůstatek by tam vypadal jako přeplatek, který ve skutečnosti není.
     *
     * 341 (daň z příjmů) sem ZÁMĚRNĚ NEPATŘÍ: zálohy dle §38a ZDP se ze zákona platí v průběhu
     * období, tedy dřív než jakýkoli závazek vznikne — předpis daně (591/341) přijde až v závěrce
     * a zálohy proti němu zúčtuje. Debet na 341 je normální průběžný stav („zaplacené zálohy"),
     * ne chyba, a guard tu blokoval legitimní platby (nález uživatele: záloha DPPO ~180 tis. Kč
     * k 15. 9. 2025, jistota 90 %, kontace 341/221 správně — přesto nezaúčtováno).
     */
    private const LIABILITY_PREFIXES = ['336', '342', '343', '345'];
    private const SALDO_PREFIXES = ['311', '321', '314', '324', '325'];

    /**
     * Odchylka úhrady od zůstatku zúčtovacího účtu, kterou guard ještě bere jako TÝŽ závazek.
     *
     * Předpis v deníku je v haléřích, ale odváděná částka je celokorunová a vzniká jinou
     * cestou: přiznání k DPH uvádí každý řádek zaokrouhlený na celé koruny (§ 37 a příloha
     * tiskopisu), takže součet přiznaných řádků se od haléřového zůstatku 343.900 běžně liší
     * o jednotky korun. Pevná tolerance 1 Kč tenhle rozdíl neustála: platba 205 993 Kč proti
     * předpisu 205 988,74 Kč (rozdíl 4,26 Kč, tj. 0,002 %) se hlásila jako „předpis chybí".
     *
     * Tolerance je proto relativní se spodní hranicí 1 Kč. Bezpečnostní vlastnost guardu
     * zůstává: `has_credit` pořád vyžaduje EXISTUJÍCÍ předpis a 0,1 % nepustí úhradu, která
     * závazek reálně přesahuje (u malých odvodů zůstává v praxi na koruně).
     */
    private const LIABILITY_TOLERANCE_PCT = 0.001;
    private const LIABILITY_TOLERANCE_MIN_CENTS = 100;

    /**
     * Důvody návrhu, které může ZMĚNIT pozdější zaúčtování — a jen ty smí periodický
     * přepočet ({@see \MyInvoice\Service\Accounting\Bank\StaleSuggestionSweep}) sáhnout znovu.
     *
     * Obě poznámky vyrábí {@see liabilityCoverage()} a obě mluví o stavu deníku
     * v okamžiku vyhodnocení: úhrada vyhodnocená DŘÍV, než vznikl předpis závazku
     * (počáteční stav, zúčtování DPH, mzdová rekapitulace, závěrka), dostane „předpis
     * chybí" — a ta věta přestane platit o pár vteřin později, aniž by ji kdokoli
     * přepsal. Po přepočtu se buď zaúčtuje, nebo aspoň dostane pravdivý důvod.
     *
     * Zbytek poznámek sem ZÁMĚRNĚ nepatří: `low_confidence`, `no_rule`, `anomaly`,
     * `rule_conflict` ani `duplicate_suspect` přepočet nezmění (chce to jiná vstupní
     * data, ne další zápis v deníku), `period_closed` řeší otevření období a odmítnutý
     * návrh se oživovat nesmí vůbec. `daily_limit_reached` je transientní časem, ne
     * zaúčtováním — denní strop je vědomá brzda uživatele, ne zastaralá informace.
     *
     * `document_not_posted` (úhrada dokladu bez zaúčtovaného předpisu) je stejná třída
     * problému, jenže se NEUKLÁDÁ: engine takový pohyb přeskočí úplně bez návrhu.
     * Dohledává se proto zvlášť, viz
     * {@see \MyInvoice\Repository\BankPostingSuggestionRepository::matchedUnpostedWithoutSuggestion()}.
     */
    public const TRANSIENT_NOTES = [
        'liability_prescription_missing',
        'liability_prescription_short',
    ];

    /**
     * Vlastní peněžní účty pro interní převod: 221* (běžný účet i analytika termínovaného
     * vkladu 221100) a 261 (peníze na cestě). Pravidlo, jehož OBĚ nohy míří sem, je jen
     * přesun peněz mezi vlastními účty firmy — částka = přesný pohyb na výpisu, žádný
     * výsledkový ani saldokontní dopad, plně reverzibilní.
     */
    private const INTERNAL_TRANSFER_PREFIXES = ['221', '261'];

    public function __construct(
        private readonly Connection $db,
        private readonly AccountingPeriodRepository $periods,
    ) {}

    public function level(int $supplierId): string
    {
        return $this->levelFor($supplierId, OperationType::BANK_TRANSFER_OWN);
    }

    public function levelFor(int $supplierId, string $operationType): string
    {
        $level = $this->storedLevel($supplierId, $operationType);
        if ($level === null) {
            $level = $this->legacyDocumentLevel($supplierId, $operationType)
                ?? $this->presetDefault($supplierId, $operationType);
        }
        $detector = OperationType::detectorFor($operationType);
        if ($detector === null) {
            return $level;
        }
        $detectorLevel = $this->storedLevel($supplierId, $detector) ?? 'suggest';
        return self::LEVEL_ORDER[$detectorLevel] < self::LEVEL_ORDER[$level] ? $detectorLevel : $level;
    }

    public function decide(int $supplierId, PolicyInput $in): PolicyDecision
    {
        $level = $this->levelFor($supplierId, $in->operationType);
        if ($level === 'off') {
            return new PolicyDecision('skip');
        }

        $decision = 'auto';
        $note = null;
        if (in_array($in->source, BankPostingSuggestionRepository::AI_SOURCES, true)) {
            $decision = 'suggest';
        }
        if ($in->crossCurrency) {
            return new PolicyDecision('blocked', 'cross_currency');
        }
        if ($in->operationType !== OperationType::BANK_PAYMENT_MATCHED
            && ($this->hasPrefix($in->debitAccountCode, self::SALDO_PREFIXES)
                || $this->hasPrefix($in->creditAccountCode, self::SALDO_PREFIXES))) {
            return new PolicyDecision('blocked', 'saldo_forbidden');
        }
        if (!$this->isOpenDate($supplierId, $in->entryDate)) {
            return new PolicyDecision('blocked', 'period_closed');
        }
        if ($in->duplicateSuspect) {
            return new PolicyDecision('needs_input', 'duplicate_suspect');
        }
        if ($this->hasPrefix($in->debitAccountCode, self::LIABILITY_PREFIXES)
            && str_starts_with($in->creditAccountCode, '221')) {
            $coverage = $this->liabilityCoverage($supplierId, $in->debitAccountCode, $in->entryDate, $in->amountCzk);
            if ($coverage !== null) {
                $decision = 'suggest';
                $note = $coverage;
            }
        }
        // Anomálie (z-score částky / duplicitní VS) degraduje na návrh — ALE ne u úhrady
        // navázané na konkrétní doklad s jistotou 1.00. Tam už není co posuzovat: víme,
        // KTEROU fakturu platba hradí, a kontace je z ní odvozená. Detektor tu jen hlásí,
        // že částka vybočuje z historie protistrany, což u faktur běžně nastane (Chromservis
        // z=10,7 při průměru 20 167 Kč — jen větší objednávka). Bez téhle výjimky se právě
        // ty největší, a přitom nejjistější, platby účtovaly ručně. Nespárované účtování
        // z pravidel, learned kontací i detektorů si anomálii hlídá dál.
        $provenPaymentMatch = $in->operationType === OperationType::BANK_PAYMENT_MATCHED
            && $in->source === 'payment_match'
            && $in->confidence !== null && $in->confidence >= 1.0;
        if ($in->anomaly && !$provenPaymentMatch) {
            $decision = 'suggest';
            $note ??= 'anomaly';
        }
        if ($in->confidence !== null && $in->confidence < 0.40) {
            return new PolicyDecision('needs_input', 'low_confidence');
        }
        if ($in->confidence !== null && $in->confidence < 0.90) {
            $decision = 'suggest';
        }
        if ($in->rule !== null) {
            $rule = $in->rule;
            $cap = $rule['auto_amount_cap'] ?? null;
            // Amplitudový pás (amount_min/max) + tři potvrzení (hit_count) chrání fuzzy
            // uživatelská pravidla párovaná dle protistrany/zprávy, kde částka může nečekaně
            // ujet. Interní převod mezi vlastními účty (221↔221 termínovaný vklad, 261↔221
            // peníze na cestě) takovou brzdu nepotřebuje: částka je definičně přesný pohyb
            // na výpisu a zápis nemá výsledkový ani saldokontní dopad — proto se účtuje
            // stejně jako spárovaná platba či dedikovaný TransferPairService (ten vlastní
            // převody účtuje bez těchto brzd taky). Symetrie s guardem
            // {@see BankPostingService::assertFxResultAccounts} posvěcujícím 221↔221 vklad.
            $needsTrackRecord = !$this->isInternalTransfer($in->debitAccountCode, $in->creditAccountCode);
            if (($rule['mode'] ?? null) !== 'auto'
                || ($needsTrackRecord && (
                    (int) ($rule['hit_count'] ?? 0) < 3
                    || ($rule['amount_min'] ?? null) === null
                    || ($rule['amount_max'] ?? null) === null
                ))) {
                $decision = 'suggest';
            }
            if ($cap !== null && $in->amountCzk > (float) $cap + 0.00001) {
                $decision = 'suggest';
                $note ??= 'amount_over_cap';
            }
        }
        if (!$in->autoAllowed) {
            $decision = 'suggest';
        }
        if ($this->dailyLimitExceeded($supplierId, $in->amountCzk)) {
            $decision = 'suggest';
            $note ??= 'daily_limit_reached';
        }
        if ($level !== 'auto') {
            $decision = 'suggest';
        }
        return new PolicyDecision($decision, $note);
    }

    public function applyPreset(int $supplierId, string $preset, ?int $userId): void
    {
        if (!in_array($preset, ['off', 'suggest', 'assisted', 'full'], true)) {
            throw new PostingException('invalid_automation_level', 'Neplatný preset automatiky.', 422);
        }
        foreach (OperationType::all() as $type) {
            $level = match ($preset) {
                'off' => 'off',
                'suggest' => 'suggest',
                'assisted' => in_array($type, [
                    OperationType::BANK_PAYMENT_MATCHED,
                    OperationType::BANK_TRANSFER_OWN,
                    OperationType::REMITTANCE_SOCIAL,
                    OperationType::REMITTANCE_HEALTH,
                    OperationType::DETECTOR_TAX_REMITTANCE,
                    OperationType::DETECTOR_OWN_TRANSFER,
                ], true) ? 'auto' : 'suggest',
                'full' => OperationType::isAi($type)
                    || in_array($type, [OperationType::BANK_LEARNED, OperationType::REMITTANCE_FLAT, OperationType::REMITTANCE_OTHER], true)
                        ? 'suggest' : 'auto',
            };
            $this->upsertRow($supplierId, $type, $level, $userId);
        }
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, automation_level)
             VALUES (?, ?) ON DUPLICATE KEY UPDATE automation_level = VALUES(automation_level)'
        )->execute([$supplierId, $preset]);
    }

    /**
     * Výchozí nastavení účetní jednotky ve chvíli, kdy se ZAPÍNÁ podvojné účetnictví:
     * automatické účtování vydaných i přijatých faktur a preset `full` („plná
     * automatika").
     *
     * Proč zrovna tohle: účetní jednotka, která si zapnula deník, ho chce mít vedený —
     * a s výchozím `suggest` u všeho zůstane deník prázdný, dokud někdo ručně neprojde
     * frontu návrhů. Nový zákazník tenhle stav nepozná jako nastavení, pozná ho jako
     * „nefunguje to". Preset `full` přitom neznamená, že se odklikne všechno: nejisté
     * a AI návrhy v něm zůstávají na `suggest` (viz {@see applyPreset()}), takže se samy
     * účtují jen deterministické operace.
     *
     * NEPŘEPISUJE si už zvolené: volá se jedině z přechodu do podvojného účetnictví
     * (aktivační průvodce, zřízení nové firmy), a to jen tehdy, když v něm jednotka
     * ještě nebyla. Opakovaný běh aktivace po neúspěchu tak nesmaže, co si mezitím
     * účetní nastavila. Rozhodnutí „byla už v podvojném?" patří volajícímu — ten ho
     * zjistí ze stavu PŘED svým UPDATE, kdežto odsud už by bylo pozdě.
     */
    public function applyAccountingUnitDefaults(int $supplierId, ?int $userId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE supplier SET auto_post_invoices = 1, auto_post_purchases = 1 WHERE id = ?'
        )->execute([$supplierId]);

        $this->applyPreset($supplierId, 'full', $userId);
    }

    /** @return array{automation_level:string,automation_daily_limit_czk:?float,automation_digest_enabled:bool,automation_digest_hour:int,rows:list<array<string,mixed>>} */
    public function listPolicy(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT operation_type, level FROM auto_posting_policy WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $stored = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stored[(string) $row['operation_type']] = (string) $row['level'];
        }
        $settings = $this->settings($supplierId);
        $rows = [];
        foreach (OperationType::all() as $type) {
            $rows[] = [
                'operation_type' => $type,
                'level' => $stored[$type] ?? 'suggest',
                'is_default' => !array_key_exists($type, $stored),
                'effective_level' => $this->levelFor($supplierId, $type),
            ];
        }
        return [
            'automation_level' => $settings['automation_level'],
            'automation_daily_limit_czk' => $settings['automation_daily_limit_czk'],
            'automation_digest_enabled' => $settings['automation_digest_enabled'],
            'automation_digest_hour' => $settings['automation_digest_hour'],
            'rows' => $rows,
        ];
    }

    public function upsertRow(int $supplierId, string $operationType, string $level, ?int $userId): void
    {
        if (!in_array($operationType, OperationType::all(), true)) {
            throw new PostingException('invalid_operation_type', 'Neplatný typ operace.', 422);
        }
        if (!isset(self::LEVEL_ORDER[$level])) {
            throw new PostingException('invalid_level', 'Neplatná úroveň automatiky.', 422);
        }
        if ($level === 'auto' && OperationType::isAi($operationType)) {
            throw new PostingException('ai_auto_forbidden', 'AI návrhy nelze automaticky účtovat.', 422);
        }
        $this->db->pdo()->prepare(
            'INSERT INTO auto_posting_policy (supplier_id, operation_type, level, updated_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE level = VALUES(level), updated_by = VALUES(updated_by)'
        )->execute([$supplierId, $operationType, $level, $userId]);
    }

    public function updateSettings(int $supplierId, ?float $dailyLimit, bool $digestEnabled, int $digestHour = 7): void
    {
        if ($dailyLimit !== null && $dailyLimit < 0) {
            throw new PostingException('invalid_daily_limit', 'Denní limit nesmí být záporný.', 422);
        }
        if ($digestHour < 0 || $digestHour > 23) {
            throw new PostingException('invalid_digest_hour', 'Hodina souhrnu musí být 0 až 23.', 422);
        }
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings
                (supplier_id, automation_daily_limit_czk, automation_digest_enabled, automation_digest_hour)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE automation_daily_limit_czk = VALUES(automation_daily_limit_czk),
                                     automation_digest_enabled = VALUES(automation_digest_enabled),
                                     automation_digest_hour = VALUES(automation_digest_hour)'
        )->execute([$supplierId, $dailyLimit, (int) $digestEnabled, $digestHour]);
    }

    /**
     * Úroveň pro typ operace, který nemá vlastní řádek v auto_posting_policy.
     *
     * `applyPreset()` zapisuje SNÍMEK typů existujících v okamžiku volání. Typ přidaný
     * do {@see OperationType::all()} později tak řádek nikdy nedostane a dřív padal na
     * natvrdo `suggest` — i u firmy s presetem `full`. Prakticky to znamenalo, že se
     * odvody pojistného zaměstnavatele tiše neúčtovaly, přestože UI hlásilo plnou
     * automatiku. Nově se default odvodí ze stejného presetu, jaký by na ten typ
     * použil `applyPreset()`, takže nový typ operace zapadne do zvoleného režimu sám.
     */
    private function presetDefault(int $supplierId, string $operationType): string
    {
        $preset = $this->settings($supplierId)['automation_level'];
        return match ($preset) {
            'off' => 'off',
            'assisted' => in_array($operationType, [
                OperationType::BANK_PAYMENT_MATCHED,
                OperationType::BANK_TRANSFER_OWN,
                OperationType::REMITTANCE_SOCIAL,
                OperationType::REMITTANCE_HEALTH,
                OperationType::DETECTOR_TAX_REMITTANCE,
                OperationType::DETECTOR_OWN_TRANSFER,
            ], true) ? 'auto' : 'suggest',
            'full' => OperationType::isAi($operationType)
                || in_array($operationType, [
                    OperationType::BANK_LEARNED,
                    OperationType::REMITTANCE_FLAT,
                    OperationType::REMITTANCE_OTHER,
                ], true) ? 'suggest' : 'auto',
            default => 'suggest',
        };
    }

    private function storedLevel(int $supplierId, string $operationType): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT level FROM auto_posting_policy WHERE supplier_id = ? AND operation_type = ?'
        );
        $stmt->execute([$supplierId, $operationType]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    private function legacyDocumentLevel(int $supplierId, string $operationType): ?string
    {
        $column = match ($operationType) {
            OperationType::DOCUMENT_INVOICE => 'auto_post_invoices',
            OperationType::DOCUMENT_PURCHASE => 'auto_post_purchases',
            default => null,
        };
        if ($column === null) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare("SELECT {$column} FROM supplier WHERE id = ?");
        $stmt->execute([$supplierId]);
        return (bool) $stmt->fetchColumn() ? 'auto' : 'suggest';
    }

    /**
     * Smí se k tomuhle datu vůbec účtovat? Období musí být `open` a datum za měkkým
     * zámkem `locked_until`. Veřejné, protože stejnou otázku si musí položit i
     * periodický přepočet fronty, PŘEDTÍM než engine cokoli zapíše — a dvě různé
     * odpovědi na „je období otevřené" by v účetnictví byly to poslední, co chceme.
     */
    public function isOpenDate(int $supplierId, string $date): bool
    {
        $period = $this->periods->findForDate($supplierId, substr($date, 0, 10));
        if ($period === null || (string) $period['status'] !== 'open') {
            return false;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $locked = $stmt->fetchColumn();
        return $locked === false || $locked === null || substr($date, 0, 10) > (string) $locked;
    }

    /**
     * Kryje předpis na zúčtovacím účtu tuhle úhradu?
     *
     * @return string|null null = kryje; jinak kód důvodu pro frontu návrhů:
     *                     `liability_prescription_missing` (na účtu není žádný předpis),
     *                     `liability_prescription_short` (předpis je, ale úhrada ho přesahuje
     *                     o víc než {@see self::LIABILITY_TOLERANCE_PCT}). Rozlišení není
     *                     kosmetika: „chybí" znamená doúčtovat doklad, „nestačí" znamená
     *                     porovnat s podáním — u zdravotního pojistného tenhle rozdíl
     *                     odhalil sedmikorunovou nesrovnalost v převzatém počátečním stavu.
     */
    private function liabilityCoverage(int $supplierId, string $code, string $date, float $amount): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE -l.amount END), 0) AS balance,
                MAX(CASE WHEN e.reversed_by IS NULL AND l.side = 'credit' THEN 1 ELSE 0 END) AS has_credit
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL AND e.entry_date <= ?
                AND a.account_code LIKE CONCAT(?, '%')"
        );
        $stmt->execute([$supplierId, substr($date, 0, 10), $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['balance' => 0, 'has_credit' => 0];
        if ((int) $row['has_credit'] !== 1) {
            return 'liability_prescription_missing';
        }
        $balanceCents = (int) round((float) $row['balance'] * 100);
        $amountCents = (int) round(abs($amount) * 100);
        $toleranceCents = max(
            self::LIABILITY_TOLERANCE_MIN_CENTS,
            (int) ceil($amountCents * self::LIABILITY_TOLERANCE_PCT),
        );
        return $balanceCents >= $amountCents - $toleranceCents
            ? null
            : 'liability_prescription_short';
    }

    private function dailyLimitExceeded(int $supplierId, float $amount): bool
    {
        $pdo = $this->db->pdo();
        $lock = $pdo->inTransaction() ? ' FOR UPDATE' : '';
        $settings = $pdo->prepare(
            'SELECT automation_daily_limit_czk FROM accounting_supplier_settings WHERE supplier_id = ?' . $lock
        );
        $settings->execute([$supplierId]);
        $value = $settings->fetchColumn();
        $limit = $value === false || $value === null ? null : (float) $value;
        if ($limit === null) {
            return false;
        }
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM bank_posting_suggestions
              WHERE supplier_id = ? AND status = 'auto_posted' AND DATE(created_at) = CURDATE()"
        );
        $stmt->execute([$supplierId]);
        return (float) $stmt->fetchColumn() + abs($amount) > $limit + 0.00001;
    }

    /** @return array{automation_level:string,automation_daily_limit_czk:?float,automation_digest_enabled:bool,automation_digest_hour:int} */
    private function settings(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT automation_level, automation_daily_limit_czk, automation_digest_enabled, automation_digest_hour
               FROM accounting_supplier_settings WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'automation_level' => $row === false ? 'suggest' : (string) $row['automation_level'],
            'automation_daily_limit_czk' => $row === false || $row['automation_daily_limit_czk'] === null
                ? null : (float) $row['automation_daily_limit_czk'],
            'automation_digest_enabled' => $row !== false && (bool) $row['automation_digest_enabled'],
            'automation_digest_hour' => $row === false ? 7 : (int) $row['automation_digest_hour'],
        ];
    }

    /**
     * Obě nohy zápisu jsou vlastní peněžní účet (221x nebo 261) → interní převod mezi
     * vlastními účty. Saldokonta (311/321/…) sem nespadají (nezačínají 221/261), takže
     * úhrada faktury se přes tuhle větev neprotlačí.
     */
    private function isInternalTransfer(string $debit, string $credit): bool
    {
        return $this->hasPrefix($debit, self::INTERNAL_TRANSFER_PREFIXES)
            && $this->hasPrefix($credit, self::INTERNAL_TRANSFER_PREFIXES);
    }

    /** @param list<string> $prefixes */
    private function hasPrefix(string $account, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($account, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
