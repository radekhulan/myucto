<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\ActivityLogger;
use PDO;

/**
 * Smazání pracovního vztahu, který **vůbec neměl vzniknout**.
 *
 * ── Proč to není totéž co `no_show` ───────────────────────────────────────────
 * „Nenástup" je záznam o tom, že něco nastalo: člověk byl přijat a nenastoupil.
 * Tohle je pro jiný případ — vztah byl založený omylem a nemá po sobě nechat
 * fiktivní nenástup. `no_show` proto zůstává a obě akce žijí vedle sebe.
 *
 * ── Co smí blokovat ───────────────────────────────────────────────────────────
 * Blokovat smí VÝHRADNĚ důkaz pohybu: vnější úkon (registrace u ČSSZ nebo
 * zdravotní pojišťovny, odeslané hlášení JMHZ), schválený výpočet (revize
 * mzdového běhu, výstupní doklad), nebo peníze (mzdové vstupy, cestovní náhrady,
 * příspěvky na spoření). Nic jiného. Když u něčeho nejsou pohyby, není co chránit.
 *
 * Odškrtnutý checklist proto NEBLOKUJE. Položka „Registrace ČSSZ — Splněno" je
 * poznámka člověka: někdo v aplikaci klikl. Navenek se nestalo nic. Důkazem je až
 * řádek v `payroll_registration_identity_snapshots` / `payroll_employment_external_ids`
 * — teprve ten mazání drží.
 *
 * ── Souběh ────────────────────────────────────────────────────────────────────
 * Mezi vykreslením `can_delete` a kliknutím mohla vzniknout vazba. Rozhodnutí se
 * proto pod zámkem řádku přeověřuje a samotný DELETE je znovu podmíněný VŠEMI
 * blokátory (vzor `PayrollRunRepository::deleteEmptyRunRow()`), takže souběh skončí
 * srozumitelnou hláškou, ne syrovou FK chybou z databáze.
 */
final class PayrollEmploymentDeletionRepository
{
    /**
     * Důkazy pohybu. Klíč = alias, hodnota = tabulky, kód pro frontend a věta,
     * podle které se dá jednat. Z téhle jediné definice se staví jak rozhodnutí,
     * tak podmínka mazání — nemůžou se tedy rozejít.
     *
     * Volitelné `where` zúží tabulku na řádky, které opravdu blokují (`{t}` =
     * alias tabulky v dotazu).
     *
     * @var array<string,array{tables:list<string>,where?:string,code:string,message:string}>
     */
    private const BLOCKERS = [
        // Profil registrace A1 (`payroll_registration_a1_profiles`) tu záměrně
        // chybí: je to pracovní podklad pro podání, ne úkon. Převzatý z importu
        // registrací jen opisuje cizí podklady, a když z něj vznikl chybný vztah,
        // musí jít smazat. Co z aplikace k ČSSZ skutečně šlo, drží snímek identity
        // a události, identifikátory vztahu od ČSSZ drží external ids. Profil se
        // proto maže se vztahem (viz RESTRICT_SCAFFOLD).
        'registration' => [
            'tables' => [
                'payroll_registration_identity_snapshots',
                'payroll_registration_event_snapshots',
                'payroll_registration_a2_evidence_ledger',
                'payroll_employment_external_ids',
                'payroll_identity_resolution_tasks',
            ],
            'code' => 'payroll_employment_registered',
            'message' => 'Pracovní vztah má skutečný záznam registrace u ČSSZ nebo zdravotní pojišťovny. '
                . 'Ten úkon už proběhl navenek, takže vztah smazat nelze. '
                . 'Pokud člověk nakonec nenastoupil, použijte Označit nenástup.',
        ],
        // Přihláška P1/A1 ve frontě podání: připravená (zmrazená XML čeká na
        // odeslání), odeslaná nebo přijatá. Součást podání nemá `employment_id`,
        // váže se k vztahu jen přes `subject_reference`, proto se blokátor
        // navěšuje na podmínky vztahu (vztah má vždy aspoň jednu verzi).
        // Odmítnutá, nahrazená nebo včas zrušená přihláška u ČSSZ nic nezaložila
        // a mazání nedrží. Bez toho šel smazat vztah s odeslanou přihláškou
        // a připravená přihláška zůstala odesílatelná ve frontě bez vztahu.
        'registration_submission' => [
            'tables' => ['payroll_employment_terms'],
            'where' => self::LIVE_REGISTRATION_SUBMISSION,
            'code' => 'payroll_employment_registered',
            'message' => 'K pracovnímu vztahu je přihláška ČSSZ, která se odeslala nebo čeká na odeslání. '
                . 'Vztah proto smazat nelze. Pokud člověk nenastoupil, použijte Označit nenástup.',
        ],
        // Souhrn pracovního měsíce (`payroll_jmhz_work_month_revisions`) tu není:
        // vzniká každým schválením docházky a podání to není. Hlášení JMHZ drží
        // snímky níže; souhrn otevřeného měsíce zmizí se vztahem stejně jako při
        // sloučení ({@see PayrollJmhzWorkMonthRevisionPurger}), schválený měsíc
        // hlásí `approved_time`.
        'submission' => [
            'tables' => [
                'payroll_jmhz_eldp_evidence_snapshots',
                'payroll_jmhz_eldp_idempotency_claims',
                'payroll_jmhz_ordinary_evidence_snapshots',
                'payroll_jmhz_ordinary_evidence_idempotency_claims',
            ],
            'code' => 'payroll_employment_has_submissions',
            'message' => 'Za tento pracovní vztah už bylo připraveno nebo odesláno hlášení JMHZ. '
                . 'Podání nejde vzít zpět smazáním vztahu — použijte Označit nenástup nebo Ukončit.',
        ],
        'eldp' => [
            'tables' => [
                'payroll_eldp_statements',
                'payroll_eldp_statement_claims',
            ],
            'code' => 'payroll_employment_has_eldp_statement',
            'message' => 'Za tento pracovní vztah je sestavený evidenční list důchodového '
                . 'pojištění. Z něj se člověku jednou počítá důchod, takže je neměnný a vztah '
                . 'smazat nelze — použijte Označit nenástup nebo Ukončit.',
        ],
        // Blokuje i zrušený běh: revize běhu drží vztah cizím klíčem a zrušený
        // běh s revizí smazat nejde. Hlášku doplní {@see self::describe()}
        // o období a stav běhu.
        'run' => [
            'tables' => ['payroll_run_employments'],
            'code' => 'payroll_employment_in_run',
            'message' => 'Pracovní vztah je zahrnutý v revizi mzdového běhu. Výpočet běhu se váže '
                . 'k tomuto vztahu, proto vztah smazat nelze. Použijte Označit nenástup nebo Ukončit.',
        ],
        'exit_document' => [
            'tables' => ['payroll_employment_exit_revisions'],
            'code' => 'payroll_employment_has_exit_documents',
            'message' => 'K pracovnímu vztahu je vydaný výstupní doklad. '
                . 'Ten je neměnný, takže vztah smazat nelze.',
        ],
        'wage_statement' => [
            'tables' => ['payroll_wage_statement_revisions'],
            'code' => 'payroll_employment_has_wage_statement',
            'message' => 'K pracovnímu vztahu je vydaný mzdový výměr. '
                . 'Ten je neměnný, takže vztah smazat nelze.',
        ],
        // Mzdový vstup se nemaže, jen ruší, a zrušený zůstává u vztahu. Hláška
        // proto nesmí radit „smažte je"; období a další krok podle stavu
        // vstupu doplní {@see self::describe()}.
        'input' => [
            'tables' => ['payroll_inputs'],
            'code' => 'payroll_employment_has_inputs',
            'message' => 'Pracovní vztah má mzdové vstupy. Mzdový vstup se nemaže, jen ruší, a zrušený '
                . 'zůstává u vztahu, proto vztah smazat nelze.',
        ],
        // Schválená docházka. Souhrn pro JMHZ vzniká schválením, takže blokuje
        // jen souhrn měsíce, který je pořád schválený; po znovuotevření měsíce
        // je souhrn jen historie a zmizí se vztahem.
        'approved_time' => [
            'tables' => ['payroll_jmhz_work_month_revisions'],
            'where' => 'EXISTS (SELECT 1 FROM payroll_time_months approved_month'
                . ' WHERE approved_month.supplier_id = {t}.supplier_id'
                . ' AND approved_month.id = {t}.time_month_id'
                . " AND approved_month.status = 'approved')",
            'code' => 'payroll_employment_has_approved_time',
            'message' => 'Pracovní vztah má schválenou docházku. Nejdřív ji v Docházce znovu otevřete, '
                . 'teprve pak půjde vztah smazat.',
        ],
        'hourly_wage_claim' => [
            'tables' => ['payroll_hourly_wage_claims', 'payroll_hourly_time_entitlements', 'payroll_hourly_included_consumption', 'payroll_hourly_evidence_revisions'],
            'code' => 'payroll_employment_has_hourly_wage_claims',
            'message' => 'Pracovní vztah má evidované nároky na hodinovou mzdu a nelze jej smazat.',
        ],
        'trip' => [
            'tables' => ['payroll_business_trips'],
            'code' => 'payroll_employment_has_trips',
            'message' => 'Pracovní vztah má zaevidované pracovní cesty s náhradami. '
                . 'Nejdřív smažte ty cesty, teprve pak půjde vztah smazat.',
        ],
        'savings' => [
            'tables' => ['payroll_risky_savings_contributions'],
            'code' => 'payroll_employment_has_savings',
            'message' => 'K pracovnímu vztahu jsou zaevidované příspěvky na spoření na stáří '
                . 'z rizikové práce. Jde o peníze, takže vztah smazat nelze.',
        ],
        // Případ dávky blokuje jen tehdy, když z něj vzniklo podání (NEMPRI nebo
        // HZUPN) nebo ho někdo vedl jako podaný jiným kanálem. Rozpracovaný
        // případ je lešení a maže se se vztahem (viz RESTRICT_SCAFFOLD).
        'sickness' => [
            'tables' => ['payroll_sickness_cases'],
            'where' => self::SICKNESS_SUBMITTED,
            'code' => 'payroll_employment_has_sickness_submission',
            'message' => 'Z případu dávky nemocenského pojištění k tomuto vztahu už bylo '
                . 'připravené nebo odeslané podání ČSSZ (NEMPRI nebo HZUPN). Podání nejde vzít '
                . 'zpět smazáním vztahu — zkontrolujte případ v Podání → Dávky nemocenského '
                . 'pojištění a použijte Označit nenástup nebo Ukončit.',
        ],
    ];

    /**
     * Živá přihláška P1/A1 vztahu ve frontě podání (připravená, odeslaná,
     * přijatá). `{t}` = řádek s `supplier_id` a `employment_id` vztahu. Sdílí
     * ji mazání vztahu i sloučení ({@see \MyInvoice\Service\Payroll\PayrollEmploymentMergeService}).
     */
    public const LIVE_REGISTRATION_SUBMISSION = 'EXISTS (SELECT 1 FROM payroll_submission_parts registration_part'
        . ' JOIN payroll_submissions registration_submission'
        . ' ON registration_submission.supplier_id = registration_part.supplier_id'
        . ' AND registration_submission.id = registration_part.submission_id'
        . ' WHERE registration_part.supplier_id = {t}.supplier_id'
        . " AND registration_part.environment IN ('production', 'test')"
        . " AND registration_part.subject_reference = CONCAT('payroll_employment:', {t}.employment_id)"
        . " AND registration_part.agenda_code IN ('PREZEC26', 'REGZEC25')"
        . " AND registration_submission.status NOT IN ('rejected', 'superseded', 'cancelled_in_time'))";

    /**
     * Případ dávky, o kterém už ví ČSSZ: má navázané podání, nebo stav, který
     * odpovídá podání mimo aplikaci. Protějšek {@see self::SICKNESS_DRAFT}.
     */
    private const SICKNESS_SUBMITTED = "({t}.nempri_submission_id IS NOT NULL"
        . " OR {t}.nempri_transfer_submission_id IS NOT NULL"
        . " OR {t}.hzupn_submission_id IS NOT NULL"
        . " OR {t}.status NOT IN ('draft', 'cancelled'))";

    /** Rozpracovaný nebo zrušený případ bez jakéhokoli podání. */
    private const SICKNESS_DRAFT = "({t}.nempri_submission_id IS NULL"
        . " AND {t}.nempri_transfer_submission_id IS NULL"
        . " AND {t}.hzupn_submission_id IS NULL"
        . " AND {t}.status IN ('draft', 'cancelled'))";

    /**
     * Vlastní lešení vztahu — zmizí spolu s ním. Klíč se propisuje do potvrzovacího
     * dialogu (musí jmenovat, co přesně zmizí) a do auditního payloadu.
     *
     * @var array<string,list<string>>
     */
    private const CASCADE = [
        'terms' => ['payroll_employment_terms'],
        'checklist' => ['payroll_employment_checklist_items'],
        'events' => ['payroll_employment_events'],
        'dimensions' => ['payroll_employment_dimensions'],
        'components' => ['payroll_recurring_components'],
        'time' => [
            'payroll_work_calendars',
            'payroll_shifts',
            'payroll_time_entries',
            'payroll_time_months',
            'payroll_time_month_import_summaries',
            'payroll_time_month_manual_summaries',
        ],
        'absences' => [
            'payroll_absences',
            'payroll_leave_ledger',
            'payroll_leave_entitlement_snapshots',
            'payroll_average_earning_snapshots',
        ],
        'sickness' => ['payroll_sickness_cases'],
        'registration_profile' => ['payroll_registration_a1_profiles'],
        'registration_changes' => ['payroll_registration_change_proposals'],
    ];

    /**
     * Zúžení skupin z {@see self::CASCADE} na řádky, které se opravdu smažou.
     *
     * @var array<string,string>
     */
    private const CASCADE_WHERE = [
        'sickness' => self::SICKNESS_DRAFT,
    ];

    /**
     * Lešení, které má FK RESTRICT — databáze ho sama nekaskáduje, musíme ho smazat
     * ručně. Dimenze jsou účetní zatřídění a pravidelné složky stálý předpis; ani
     * jedno není pohyb. Rozpracovaný případ dávky je příprava podání, které nikdy
     * neodešlo; musí zmizet PŘED vztahem, protože na něj i na jeho absenci ukazuje
     * cizím klíčem RESTRICT. Profil registrace A1 má FK RESTRICT taky; jeho
     * mazací trigger pustí řádek vztahu, za který neodešlo registrační podání,
     * a jinak mazání zastaví (viz {@see self::REGISTRATION_PROFILE_RETAINED}).
     * Návrh hlášení změny registrace je výsledek detekce nad profilem; skutečné
     * podání změny drží událost registrace, která mazání blokuje dřív.
     * Souhrny pracovního měsíce pro JMHZ maže před vztahem
     * {@see PayrollJmhzWorkMonthRevisionPurger} (append-only, FK RESTRICT).
     * Zbytek lešení odklidí ON DELETE CASCADE, což zároveň korektně obejde
     * append-only triggery na vnucích (FK kaskáda triggery nespouští).
     *
     * @var array<string,string|null> tabulka => zúžení na mazané řádky
     */
    private const RESTRICT_SCAFFOLD = [
        'payroll_employment_dimensions' => null,
        'payroll_recurring_components' => null,
        'payroll_sickness_cases' => self::SICKNESS_DRAFT,
        'payroll_registration_a1_profiles' => null,
        'payroll_registration_change_proposals' => null,
    ];

    /** Stavy mzdového běhu do hlášky blokátoru. */
    private const RUN_STATUS_LABELS = [
        'draft' => 'rozpracovaný',
        'inputs_locked' => 'se zamčenými vstupy',
        'calculated' => 'spočítaný',
        'reviewed' => 'zkontrolovaný',
        'approved' => 'schválený',
        'posted' => 'zaúčtovaný',
        'payment_ready' => 'připravený k výplatě',
        'paid' => 'vyplacený',
        'closed' => 'uzavřený',
        'correction_pending' => 'čeká na opravu',
        'reopened' => 'otevřený k opravě',
        'cancelled' => 'zrušený',
    ];

    /**
     * Hláška triggeru `trg_payroll_registration_a1_profile_immutable_delete`
     * (migrace 1716): profil vztahu, za který už odešlo registrační podání,
     * se nesmaže. Z aplikace takové podání nechá i snímek identity, který mazání
     * blokuje dřív; trigger je pojistka pro podání bez snímku.
     */
    private const REGISTRATION_PROFILE_RETAINED = 'retained once registration was submitted';

    /**
     * Důkazy, které drží druh pracovního vztahu. Stejně jako u mazání chybí
     * převzatý profil registrace (`payroll_registration_a1_profiles`): ten jen
     * opisuje registraci podanou mimo aplikaci a oprava druhu je právě náprava
     * jejího chybného převzetí. Mzdové vstupy drží druh jen schválené, koncept vstupu
     * opravu nepřežije bez kontroly, ale nic nepotvrdil. Dovolená drží proto,
     * že dohoda nárok na dovolenou nemá a vedená kniha dovolené by osiřela.
     *
     * @var array<string,array{tables:list<string>,where?:string,code:string,message:string}>
     */
    private const RELATION_TYPE_BLOCKERS = [
        'registration' => [
            'tables' => [
                'payroll_registration_identity_snapshots',
                'payroll_registration_event_snapshots',
                'payroll_registration_a2_evidence_ledger',
                'payroll_employment_external_ids',
                'payroll_identity_resolution_tasks',
            ],
            'code' => 'payroll_relation_type_registered',
            'message' => 'Pracovní vztah má z aplikace připravenou nebo potvrzenou registraci u ČSSZ '
                . 'nebo identifikátory vztahu od ČSSZ. Druh vztahu proto opravit nelze. '
                . 'Ukončete vztah a založte nový se správným druhem.',
        ],
        // Souhrn pracovního měsíce není podání; schválenou docházku drží `approved_time`.
        'submission' => [
            'tables' => [
                'payroll_jmhz_eldp_evidence_snapshots',
                'payroll_jmhz_eldp_idempotency_claims',
                'payroll_jmhz_ordinary_evidence_snapshots',
                'payroll_jmhz_ordinary_evidence_idempotency_claims',
            ],
            'code' => 'payroll_relation_type_has_submissions',
            'message' => 'Za tento pracovní vztah už bylo připraveno nebo odesláno hlášení JMHZ. '
                . 'Druh vztahu proto opravit nelze. Ukončete vztah a založte nový se správným druhem.',
        ],
        'eldp' => [
            'tables' => ['payroll_eldp_statements', 'payroll_eldp_statement_claims'],
            'code' => 'payroll_relation_type_has_eldp_statement',
            'message' => 'Za tento pracovní vztah je sestavený evidenční list důchodového pojištění. '
                . 'Druh vztahu proto opravit nelze.',
        ],
        'run' => [
            'tables' => ['payroll_run_employments'],
            'code' => 'payroll_relation_type_in_run',
            'message' => 'Pracovní vztah je zahrnutý v revizi mzdového běhu. Nejdřív smažte nebo zrušte '
                . 'ten mzdový běh, teprve pak půjde druh vztahu opravit.',
        ],
        'exit_document' => [
            'tables' => ['payroll_employment_exit_revisions'],
            'code' => 'payroll_relation_type_has_exit_documents',
            'message' => 'K pracovnímu vztahu je vydaný výstupní doklad. Druh vztahu proto opravit nelze.',
        ],
        'wage_statement' => [
            'tables' => ['payroll_wage_statement_revisions'],
            'code' => 'payroll_relation_type_has_wage_statement',
            'message' => 'K pracovnímu vztahu je vydaný mzdový výměr. Druh vztahu proto opravit nelze.',
        ],
        'approved_input' => [
            'tables' => ['payroll_inputs'],
            'where' => "{t}.status = 'approved'",
            'code' => 'payroll_relation_type_has_approved_inputs',
            'message' => 'Pracovní vztah má schválené mzdové vstupy. Schválený vstup se počítal podle '
                . 'dosavadního druhu vztahu, proto druh vztahu opravit nelze.',
        ],
        'hourly_wage_claim' => [
            'tables' => ['payroll_hourly_wage_claims', 'payroll_hourly_time_entitlements', 'payroll_hourly_included_consumption', 'payroll_hourly_evidence_revisions'],
            'code' => 'payroll_relation_type_has_hourly_wage_claims',
            'message' => 'Pracovní vztah má evidované nároky na hodinovou mzdu. Druh vztahu proto opravit nelze.',
        ],
        'approved_time' => [
            'tables' => ['payroll_time_months'],
            'where' => "{t}.status = 'approved'",
            'code' => 'payroll_relation_type_has_approved_time',
            'message' => 'Pracovní vztah má schválenou docházku za měsíc. Schválení se posuzovalo podle '
                . 'dosavadního druhu vztahu, proto druh vztahu opravit nelze.',
        ],
        'average_earning' => [
            'tables' => ['payroll_average_earning_snapshots'],
            'where' => "{t}.status IN ('approved', 'superseded')",
            'code' => 'payroll_relation_type_has_average_earning',
            'message' => 'Pracovní vztah má schválený průměrný výdělek. Druh vztahu proto opravit nelze.',
        ],
        'discount' => [
            'tables' => ['payroll_discount_intents'],
            'where' => "{t}.status NOT IN ('draft', 'cancelled')",
            'code' => 'payroll_relation_type_has_discount',
            'message' => 'K pracovnímu vztahu je podané oznámení o slevě na pojistném. Druh vztahu proto opravit nelze.',
        ],
        'leave' => [
            'tables' => ['payroll_leave_ledger', 'payroll_leave_entitlement_snapshots'],
            'code' => 'payroll_relation_type_has_leave',
            'message' => 'Pracovní vztah má vedenou knihu dovolené. Druh vztahu proto opravit nelze.',
        ],
        'sickness' => [
            'tables' => ['payroll_sickness_cases'],
            'where' => self::SICKNESS_SUBMITTED,
            'code' => 'payroll_relation_type_has_sickness_submission',
            'message' => 'Z případu dávky nemocenského pojištění k tomuto vztahu už bylo připravené '
                . 'nebo odeslané podání ČSSZ. Druh vztahu proto opravit nelze.',
        ],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly ActivityLogger $activityLogger,
        private readonly PayrollJmhzWorkMonthRevisionPurger $workMonthRevisions,
    ) {}

    /**
     * Co brání opravě druhu vztahu. Volá se pod zámkem řádku vztahu.
     *
     * `$except` vynechá skupiny blokátorů podle aliasu. Sloučení vztahů vynechá
     * `registration`: cílem sloučení je právě registrovaný vztah a nesoulad
     * s registrací hlásí varováním (podejte opravu registrace).
     *
     * @param list<string> $except
     * @return array{code:string,message:string}|null
     */
    public function relationTypeChangeBlocker(int $supplierId, int $employmentId, array $except = []): ?array
    {
        $columns = [];
        $blockers = array_diff_key(self::RELATION_TYPE_BLOCKERS, array_flip($except));
        foreach ($blockers as $alias => $blocker) {
            $columns[] = self::existsExpression($blocker['tables'], $blocker['where'] ?? null) . " AS blocker_{$alias}";
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . implode(', ', $columns)
            . ' FROM payroll_employments employment WHERE employment.supplier_id = ? AND employment.id = ?'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        foreach ($blockers as $alias => $blocker) {
            if ((int) $row["blocker_{$alias}"] > 0) {
                return ['code' => $blocker['code'], 'message' => $blocker['message']];
            }
        }

        return null;
    }

    /**
     * Vrací `null`, když vztah v tomto tenantu neexistuje — volající to překlopí na
     * 404, aby cizí tenant nezjistil ani to, jestli id existuje.
     */
    public function canDelete(int $supplierId, int $employmentId): ?PayrollDeletionDecision
    {
        $stmt = $this->db->pdo()->prepare(self::decisionSql());
        $stmt->execute([$supplierId, $employmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        foreach (array_keys(self::BLOCKERS) as $alias) {
            if ((int) $row["blocker_{$alias}"] > 0) {
                $described = $this->describe($alias, $supplierId, $employmentId);

                return PayrollDeletionDecision::blocked(
                    $described['code'],
                    $described['message'],
                    $described['period'],
                );
            }
        }

        $cascade = [];
        foreach (array_keys(self::CASCADE) as $alias) {
            $cascade[$alias] = (int) $row["cascade_{$alias}"];
        }

        return PayrollDeletionDecision::allowed($cascade);
    }

    /**
     * Mzdový běh, který vztah drží, pojmenovaný pro hlášku: období a stav.
     * Nezrušený běh má přednost před zrušeným, novější před starším. Sdílí ho
     * mazání i sloučení vztahu, aby obě hlášky jmenovaly tentýž běh.
     *
     * @return array{period_start:string,status:string,label:string}|null
     */
    public function runInvolvement(int $supplierId, int $employmentId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT run.period_start, run.status
               FROM payroll_run_employments run_employment
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run_employment.supplier_id
                AND revision.id = run_employment.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE run_employment.supplier_id = ? AND run_employment.employment_id = ?
              ORDER BY run.status = 'cancelled', run.period_start DESC, run.id DESC
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $employmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $period = (string) $row['period_start'];
        $status = (string) $row['status'];
        $label = $status === 'cancelled'
            ? sprintf('zrušeném mzdovém běhu za %s', self::monthLabel($period))
            : sprintf(
                'mzdovém běhu za %s (stav: %s)',
                self::monthLabel($period),
                self::RUN_STATUS_LABELS[$status] ?? $status,
            );

        return ['period_start' => $period, 'status' => $status, 'label' => $label];
    }

    /**
     * Hláška blokátoru s konkrétním obdobím a dalším krokem. Volá se jen pro
     * blokátor, který opravdu platí, takže stojí nejvýš jeden dotaz navíc.
     *
     * @return array{code:string,message:string,period:?string}
     */
    private function describe(string $alias, int $supplierId, int $employmentId): array
    {
        $blocker = self::BLOCKERS[$alias];
        $described = ['code' => $blocker['code'], 'message' => $blocker['message'], 'period' => null];

        if ($alias === 'run') {
            $run = $this->runInvolvement($supplierId, $employmentId);
            if ($run !== null) {
                $described['period'] = $run['period_start'];
                $described['message'] = $run['status'] === 'cancelled'
                    ? sprintf(
                        'Pracovní vztah je zahrnutý ve %s. Zrušený běh si nechává svou revizi i s tímto '
                        . 'vztahem, proto vztah smazat nelze. Použijte Označit nenástup nebo Ukončit.',
                        $run['label'],
                    )
                    : sprintf(
                        'Pracovní vztah je zahrnutý v %s. Výpočet běhu se váže k tomuto vztahu, proto '
                        . 'vztah smazat nelze. Použijte Označit nenástup nebo Ukončit.',
                        $run['label'],
                    );
            }
        } elseif ($alias === 'input') {
            $stmt = $this->db->pdo()->prepare(
                "SELECT status, MIN(period_start) AS period_start
                   FROM payroll_inputs
                  WHERE supplier_id = ? AND employment_id = ?
                  GROUP BY status
                  ORDER BY FIELD(status, 'locked', 'approved', 'draft', 'cancelled')
                  LIMIT 1"
            );
            $stmt->execute([$supplierId, $employmentId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $period = (string) $row['period_start'];
                $described['period'] = $period;
                $month = self::monthLabel($period);
                $described['message'] = match ((string) $row['status']) {
                    'locked' => sprintf(
                        'Mzdové vstupy za %s už zamkl a zpracoval mzdový běh. Vztah proto smazat nelze. '
                        . 'Použijte Označit nenástup nebo Ukončit.',
                        $month,
                    ),
                    'approved' => sprintf(
                        'Pracovní vztah má schválené mzdové vstupy za %s. Mzdový vstup se nemaže, jen ruší, '
                        . 'a zrušený zůstává u vztahu, proto vztah smazat nelze. Patří-li vstupy jinému vztahu '
                        . 'téže osoby, přesuňte je přes Další akce vztahu, Sloučit do jiného vztahu (schválení '
                        . 'sloučení samo vrátí do konceptu). Jinak vztah ukončete.',
                        $month,
                    ),
                    default => sprintf(
                        'Pracovní vztah má mzdové vstupy za %s. Mzdový vstup se nemaže, jen ruší, a zrušený '
                        . 'zůstává u vztahu, proto vztah smazat nelze. Patří-li vstupy jinému vztahu téže osoby, '
                        . 'přesuňte je přes Další akce vztahu, Sloučit do jiného vztahu. Jinak vztah ukončete.',
                        $month,
                    ),
                };
            }
        } elseif ($alias === 'registration' || $alias === 'registration_submission') {
            $unsent = $this->unsentRegistration($supplierId, $employmentId);
            if ($unsent !== null) {
                $described['message'] = $unsent['status'] === 'cancelled_in_time'
                    ? sprintf(
                        'K vztahu byla připravená přihláška ČSSZ (podání č. %d), která se zrušila a neodešla. '
                        . 'Její zmrazený podklad zůstává k vztahu vázaný, proto vztah smazat nelze. '
                        . 'Pokud člověk nenastoupil, použijte Označit nenástup, jinak vztah ukončete.',
                        $unsent['submission_id'],
                    )
                    : sprintf(
                        'K vztahu je připravená přihláška ČSSZ (podání č. %d), zatím neodeslaná. '
                        . 'Dokud čeká na odeslání, vztah smazat nelze. Pokud vztah vznikl omylem, '
                        . 'přihlášku v Podání a hlášení zrušte (neodesílejte ji), pak vztah půjde smazat.',
                        $unsent['submission_id'],
                    );
            }
        } elseif ($alias === 'approved_time') {
            $stmt = $this->db->pdo()->prepare(
                "SELECT MIN(period_start) FROM payroll_time_months
                  WHERE supplier_id = ? AND employment_id = ? AND status = 'approved'"
            );
            $stmt->execute([$supplierId, $employmentId]);
            $period = $stmt->fetchColumn();
            if (is_string($period) && $period !== '') {
                $described['period'] = $period;
                $described['message'] = sprintf(
                    'Docházka za %s je schválená. Nejdřív ji v Docházce znovu otevřete, teprve pak půjde '
                    . 'vztah smazat.',
                    self::monthLabel($period),
                );
            }
        }

        return $described;
    }

    /**
     * Registrace vztahu, která navenek neodešla: žádné ID PPV a nejnovější
     * základní přihláška (A1/P1) jen připravená nebo včas zrušená, bez
     * události A2 až A8. Jinak `null` (platí obecná hláška o úkonu u ČSSZ).
     *
     * @return array{submission_id:int,status:string}|null
     */
    private function unsentRegistration(int $supplierId, int $employmentId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT submission.id, submission.status,
                    EXISTS (SELECT 1 FROM payroll_employment_external_ids external_id
                             WHERE external_id.supplier_id = ? AND external_id.employment_id = ?) AS has_external,
                    EXISTS (SELECT 1 FROM payroll_registration_event_snapshots event_snapshot
                             WHERE event_snapshot.supplier_id = ? AND event_snapshot.employment_id = ?) AS has_event,
                    EXISTS (SELECT 1 FROM payroll_submission_parts sent_part
                              JOIN payroll_submissions sent
                                ON sent.supplier_id = sent_part.supplier_id
                               AND sent.id = sent_part.submission_id
                             WHERE sent_part.supplier_id = ? AND sent_part.subject_reference = ?
                               AND sent.status NOT IN ('draft', 'validated', 'ready', 'cancelled_in_time')) AS has_sent
               FROM payroll_submission_parts part
               JOIN payroll_submissions submission
                 ON submission.supplier_id = part.supplier_id
                AND submission.id = part.submission_id
              WHERE part.supplier_id = ? AND part.subject_reference = ?
                AND part.agenda_code IN ('PREZEC26', 'REGZEC25')
              ORDER BY submission.id DESC
              LIMIT 1"
        );
        $reference = PayrollRegistrationSubmissionRepository::employmentReference($employmentId);
        $stmt->execute([
            $supplierId, $employmentId,
            $supplierId, $employmentId,
            $supplierId, $reference,
            $supplierId, $reference,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)
            || (int) $row['has_external'] > 0
            || (int) $row['has_event'] > 0
            || (int) $row['has_sent'] > 0
            || !in_array((string) $row['status'], ['draft', 'validated', 'ready', 'cancelled_in_time'], true)
        ) {
            return null;
        }

        return ['submission_id' => (int) $row['id'], 'status' => (string) $row['status']];
    }

    private static function monthLabel(string $period): string
    {
        return substr($period, 5, 2) . '/' . substr($period, 0, 4);
    }

    /**
     * Smaže vztah ve vlastní transakci (nebo savepointu, běží-li už transakce).
     *
     * @return array<string,int> počty smazaného lešení pro audit a potvrzení
     */
    public function delete(
        int $supplierId,
        int $employmentId,
        ?int $expectedVersion,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT payroll_employment_delete');
        }

        try {
            $result = $this->deleteLocked(
                $supplierId,
                $employmentId,
                $expectedVersion,
                $userId,
                $ip,
                $userAgent,
            );
            if ($owns) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT payroll_employment_delete');
            }

            return $result;
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($owns) {
                $pdo->rollBack();
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT payroll_employment_delete');
                $pdo->exec('RELEASE SAVEPOINT payroll_employment_delete');
            }
            throw $e;
        }
    }

    /**
     * Smaže vztah i jeho vlastní lešení. Musí běžet uvnitř transakce volajícího.
     *
     * @return array<string,int> počty smazaného lešení pro audit a potvrzení
     */
    public function deleteLocked(
        int $supplierId,
        int $employmentId,
        ?int $expectedVersion,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $employment = $this->lock($supplierId, $employmentId);
        if ($employment === null) {
            throw new PayrollEmploymentNotFoundException('Pracovní vztah nebyl nalezen.');
        }
        $rowVersion = (int) $employment['row_version'];
        if ($expectedVersion !== null && $rowVersion !== $expectedVersion) {
            throw new PayrollEmploymentConflictException($rowVersion);
        }

        // Přeověření POD ZÁMKEM — `can_delete` z vykresleného seznamu už mohlo zestárnout.
        $decision = $this->canDelete($supplierId, $employmentId);
        if ($decision === null) {
            throw new PayrollEmploymentNotFoundException('Pracovní vztah nebyl nalezen.');
        }
        if (!$decision->canDelete) {
            throw new PayrollDeletionException(
                (string) $decision->blockerCode,
                (string) $decision->blockerMessage,
                $employmentId,
                (string) $employment['code'],
            );
        }

        // Souhrny JMHZ dřívějších schválení docházky. Schválený měsíc zablokoval
        // `approved_time`, podání JMHZ blokátor `submission`; zbývá historie.
        $droppedSummaries = $this->workMonthRevisions->drop($supplierId, 'employment_id', [$employmentId]);

        foreach (self::RESTRICT_SCAFFOLD as $table => $where) {
            $filter = $where === null ? '' : ' AND ' . str_replace('{t}', 'scaffold', $where);
            $stmt = $this->db->pdo()->prepare(
                "DELETE scaffold FROM {$table} scaffold"
                . " WHERE scaffold.supplier_id = ? AND scaffold.employment_id = ?{$filter}"
            );
            try {
                $stmt->execute([$supplierId, $employmentId]);
            } catch (\PDOException $e) {
                if (
                    TransactionFailure::aborted($e)
                    || !str_contains($e->getMessage(), self::REGISTRATION_PROFILE_RETAINED)
                ) {
                    throw $e;
                }
                throw new PayrollDeletionException(
                    self::BLOCKERS['registration']['code'],
                    self::BLOCKERS['registration']['message'],
                    $employmentId,
                    (string) $employment['code'],
                );
            }
        }

        $guard = $this->db->pdo()->prepare(self::guardedDeleteSql());
        $guard->execute([$supplierId, $employmentId, $rowVersion]);
        if ($guard->rowCount() !== 1) {
            throw new PayrollDeletionException(
                'payroll_employment_delete_conflict',
                'Pracovní vztah se mezitím změnil — někdo k němu mezitím přidal záznam. '
                . 'Načtěte kartu znovu a zkuste to prosím ještě jednou.',
                $employmentId,
                (string) $employment['code'],
            );
        }

        // Řádek zmizel, ale fakt, že existoval a kdo ho smazal, zůstat musí.
        $this->activityLogger->log(
            'payroll.employment.deleted',
            $userId,
            'payroll_employment',
            $employmentId,
            [
                'employee_id' => (int) $employment['employee_id'],
                'code' => (string) $employment['code'],
                'relation_type' => (string) $employment['relation_type'],
                'status' => (string) $employment['status'],
                'start_date' => $employment['start_date'],
                'end_date' => $employment['end_date'],
                'row_version' => $rowVersion,
                'cascade' => $decision->cascade,
                'dropped_jmhz_work_month_revisions' => $droppedSummaries,
            ],
            $ip,
            $userAgent,
            $supplierId,
        );

        return $decision->cascade;
    }

    /**
     * První vztah osoby, který blokuje mazání — jedním dotazem, ne dotazem na
     * vztah. Seznam zaměstnanců počítá `can_delete` pro každý řádek, takže
     * rekurze „dotaz na vztah" by z něj udělala N+1.
     *
     * @return array{id:int,code:string,code_key:string,message:string}|null
     */
    public function firstBlockingEmployment(int $supplierId, int $employeeId): ?array
    {
        $branches = [];
        foreach (self::BLOCKERS as $alias => $blocker) {
            $branches[] = 'WHEN ' . self::existsExpression($blocker['tables'], $blocker['where'] ?? null)
                . " THEN '{$alias}'";
        }
        $sql = 'SELECT employment.id, employment.code, CASE '
            . implode(' ', $branches)
            . ' END AS blocker'
            . ' FROM payroll_employments employment'
            . ' WHERE employment.supplier_id = ? AND employment.employee_id = ?'
            . ' HAVING blocker IS NOT NULL'
            . ' ORDER BY employment.id ASC LIMIT 1';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $alias = (string) $row['blocker'];
        if (!isset(self::BLOCKERS[$alias])) {
            throw new \LogicException('Neznámý blokátor pracovního vztahu.');
        }
        $described = $this->describe($alias, $supplierId, (int) $row['id']);

        return [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'code_key' => $described['code'],
            'message' => $described['message'],
        ];
    }

    /** @return array<string,string|int|null>|null */
    public function lock(int $supplierId, int $employmentId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, employee_id, code, relation_type, status,
                    start_date, end_date, row_version
               FROM payroll_employments
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $result = [];
        foreach ($row as $key => $value) {
            if (is_string($key) && (is_string($value) || is_int($value) || $value === null)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /** Existují vůbec pro tenhle vztah nějaké pohyby? Pro rekurzi z mazání osoby. */
    private static function decisionSql(): string
    {
        $columns = [];
        foreach (self::BLOCKERS as $alias => $blocker) {
            $columns[] = self::countExpression($blocker['tables'], $blocker['where'] ?? null)
                . " AS blocker_{$alias}";
        }
        foreach (self::CASCADE as $alias => $tables) {
            $columns[] = self::countExpression($tables, self::CASCADE_WHERE[$alias] ?? null)
                . " AS cascade_{$alias}";
        }

        return 'SELECT ' . implode(",\n       ", $columns)
            . "\n  FROM payroll_employments employment"
            . "\n WHERE employment.supplier_id = ? AND employment.id = ?";
    }

    /**
     * DELETE podmíněný VŠEMI blokátory najednou. Když mezi rozhodnutím a mazáním
     * vznikla vazba, smaže se 0 řádků a volající to ohlásí jako souběh — místo aby
     * uživatel dostal syrovou FK chybu z databáze.
     */
    private static function guardedDeleteSql(): string
    {
        // Lešení z RESTRICT_SCAFFOLD je v tu chvíli už smazané, takže jakýkoli jeho
        // zbylý řádek (i nový rozpracovaný případ ze souběhu) znamená konflikt.
        $tables = [];
        foreach (self::BLOCKERS as $blocker) {
            foreach ($blocker['tables'] as $table) {
                $tables[] = [$table, $blocker['where'] ?? null];
            }
        }
        foreach (array_keys(self::RESTRICT_SCAFFOLD) as $table) {
            $tables[] = [$table, null];
        }
        // Souhrny JMHZ smazal purger; nový souhrn ze souběžného schválení je konflikt.
        $tables[] = ['payroll_jmhz_work_month_revisions', null];

        $joins = [];
        $conditions = [];
        foreach ($tables as $index => [$table, $where]) {
            $alias = "guard{$index}";
            $joins[] = "  LEFT JOIN {$table} {$alias}"
                . "\n         ON {$alias}.supplier_id = employment.supplier_id"
                . "\n        AND {$alias}.employment_id = employment.id"
                . ($where === null ? '' : "\n        AND " . str_replace('{t}', $alias, $where));
            $conditions[] = "   AND {$alias}.id IS NULL";
        }

        return "DELETE employment\n  FROM payroll_employments employment\n"
            . implode("\n", $joins)
            . "\n WHERE employment.supplier_id = ?"
            . "\n   AND employment.id = ?"
            . "\n   AND employment.row_version = ?\n"
            . implode("\n", $conditions);
    }

    /** @param list<string> $tables */
    private static function existsExpression(array $tables, ?string $where = null): string
    {
        $parts = [];
        foreach ($tables as $index => $table) {
            $alias = 'e' . $index . '_' . substr(md5($table), 0, 6);
            $parts[] = "EXISTS (SELECT 1 FROM {$table} {$alias}"
                . " WHERE {$alias}.supplier_id = employment.supplier_id"
                . " AND {$alias}.employment_id = employment.id"
                . ($where === null ? '' : ' AND ' . str_replace('{t}', $alias, $where))
                . ')';
        }

        return '(' . implode(' OR ', $parts) . ')';
    }

    /** @param list<string> $tables */
    private static function countExpression(array $tables, ?string $where = null): string
    {
        $parts = [];
        foreach ($tables as $index => $table) {
            $alias = 'c' . $index . '_' . substr(md5($table), 0, 6);
            $parts[] = "(SELECT COUNT(*) FROM {$table} {$alias}"
                . " WHERE {$alias}.supplier_id = employment.supplier_id"
                . " AND {$alias}.employment_id = employment.id"
                . ($where === null ? '' : ' AND ' . str_replace('{t}', $alias, $where))
                . ')';
        }

        return implode(' + ', $parts);
    }
}
