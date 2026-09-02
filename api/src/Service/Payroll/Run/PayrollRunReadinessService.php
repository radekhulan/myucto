<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Service\Payroll\Payment\PayrollInstitutionVerificationWindow;
use PDO;

/**
 * KONTROLA PŘED ZAHÁJENÍM mzdového běhu.
 *
 * ── Co bylo špatně ──────────────────────────────────────────────────────────
 * Všechny kontroly běhu se dosud vyhodnocovaly až v okamžiku, kdy běh UŽ
 * existoval a jeho vstupy byly zmrazené. Účetní se tedy o chybějící registraci
 * účtárny u ČSSZ, o neschválené docházce nebo o rozpracovaných vstupech
 * dozvěděla ve třetí fázi — po dvou kliknutích, které mezitím zamkly snímek.
 * Cesta zpátky vedla přes zrušení běhu a novou revizi. Dvě z těch věcí
 * (chybějící zaměstnavatelská politika, chybějící ověřený účet instituce)
 * dokonce nebyly validace, ale výjimky: první shodila zamykání, druhá se
 * ozvala až u přípravy plateb, tedy o čtyři kroky později.
 *
 * ── Pravidlo teď ────────────────────────────────────────────────────────────
 * Tahle služba pustí TYTÉŽ kontroly nasucho, DŘÍV než se cokoli zmrazí, a jen
 * je vrátí k prohlédnutí. Vzor je {@see \MyInvoice\Service\Payroll\PayrollYearCloseService::status()}:
 * čtecí volání vrátí seznam nálezů a samo nic neudělá ani neuloží.
 *
 * NIC z toho neblokuje. Nález je informace pro rozhodnutí („opravdu zahájit?"),
 * ne podmínka — účetní má vidět problém a rozhodnout se sama. Skutečné brány
 * zůstávají tam, kde byly (schválení běhu, příprava plateb); tohle je jen to,
 * aby o nich věděla předem.
 *
 * Kontrola je fail-soft: když se sama nepovede, vrátí nález a pustí dál.
 * Zastavit práci kvůli tomu, že kontrola spadla, by bylo horší než nekontrolovat.
 */
final class PayrollRunReadinessService
{
    /** Kolik konkrétních jmen se u nálezu vypíše, než se přejde na počet. */
    private const MAX_ENTITIES = 25;

    /** Zdroje ověření účtu instituce, které platí za doklad. */
    private const VERIFIED_SOURCE_KINDS = [
        'official_registry',
        'official_document',
        'institution_notice',
        'user_verified',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollRunSnapshotBuilder $snapshotBuilder,
        private readonly PayrollEmployerPolicyRepository $employerPolicies,
    ) {}

    /**
     * @return array{
     *   period_start:string,
     *   payment_date:string,
     *   office_id:?int,
     *   ready:bool,
     *   findings:list<array{
     *     code:string,
     *     severity:string,
     *     message:string,
     *     remediation_path:?string,
     *     count:int,
     *     entities:list<array{entity_type:string,entity_id:?int}>
     *   }>
     * }
     */
    public function inspect(
        int $supplierId,
        string $periodStart,
        string $paymentDate,
        ?int $officeId = null,
    ): array {
        $findings = [];
        $snapshot = null;

        // Zaměstnavatelská politika se ověřuje PRVNÍ a zvlášť: bez ní snapshot
        // vůbec nevznikne (builder hodí výjimku), takže by se všechny ostatní
        // nálezy schovaly za jednu hlášku o politice.
        $policyFinding = $this->employerPolicyFinding($supplierId, $periodStart);
        if ($policyFinding !== null) {
            $findings[] = $policyFinding;
        }

        try {
            $snapshot = $this->snapshotBuilder->build(
                $supplierId,
                $periodStart,
                $paymentDate,
                $officeId,
            );
        } catch (\Throwable $e) {
            // Nasucho postavený snímek se zahazuje, takže výjimka tady nic
            // nerozbila — jen se z ní stane nález. Duplicitní hlášku o politice
            // (kterou už máme výš) sem znovu netaháme.
            if ($policyFinding === null) {
                $findings[] = [
                    'code' => 'readiness_check_failed',
                    'severity' => 'warning',
                    'message' => 'Předběžnou kontrolu se nepodařilo dokončit: '
                        . $e->getMessage()
                        . ' Mzdový běh tím není zablokovaný — po zahájení se '
                        . 'kontroly spustí znovu nad zmrazenými vstupy.',
                    'remediation_path' => null,
                    'count' => 1,
                    'entities' => [],
                ];
            }
        }

        if ($snapshot !== null) {
            foreach ($this->groupValidations($snapshot->validations) as $finding) {
                $findings[] = $finding;
            }
            foreach ($this->institutionAccountFindings(
                $supplierId,
                $paymentDate,
                $snapshot->data,
            ) as $finding) {
                $findings[] = $finding;
            }
        }

        return [
            'period_start' => $periodStart,
            'payment_date' => $paymentDate,
            'office_id' => $officeId,
            'ready' => $findings === [],
            'findings' => $findings,
        ];
    }

    /**
     * @return array{
     *   code:string,severity:string,message:string,remediation_path:?string,
     *   count:int,entities:list<array{entity_type:string,entity_id:?int}>
     * }|null
     */
    private function employerPolicyFinding(int $supplierId, string $periodStart): ?array
    {
        try {
            if ($this->employerPolicies->findEffective($supplierId, $periodStart) !== null) {
                return null;
            }
            $message = sprintf(
                'Za období %s není účinná žádná zaměstnavatelská mzdová '
                . 'politika. Otevřete Mzdy → Nastavení zaměstnavatele → '
                . 'Mzdové politiky a založte politiku účinnou od %s; bez ní '
                . 'se mzdový běh nedá zahájit.',
                substr($periodStart, 0, 7),
                $periodStart,
            );
        } catch (\Throwable $e) {
            // Překryv dvou politik má vlastní výjimku — je to taky nález, jen
            // s jinou příčinou. Text výjimky říká, co je přesně špatně.
            $message = $e->getMessage();
        }

        return [
            'code' => 'employer_policy_missing',
            'severity' => 'blocker',
            'message' => $message,
            'remediation_path' => '/payroll/settings',
            'count' => 1,
            'entities' => [],
        ];
    }

    /**
     * Nálezy ze snímku sloučené po kódu.
     *
     * Účetní nepotřebuje dvacet řádků „pracovní vztah nemá schválenou
     * docházku" — potřebuje jeden řádek, počet a jména.
     *
     * @param list<PayrollRunValidation> $validations
     * @return list<array{
     *   code:string,severity:string,message:string,remediation_path:?string,
     *   count:int,entities:list<array{entity_type:string,entity_id:?int}>
     * }>
     */
    private function groupValidations(array $validations): array
    {
        $groups = [];
        foreach ($validations as $validation) {
            if ($validation->severity === 'info') {
                continue;
            }
            $code = $validation->code;
            if (!isset($groups[$code])) {
                $groups[$code] = [
                    'code' => $code,
                    'severity' => $validation->severity,
                    'message' => $validation->message,
                    'remediation_path' => $validation->remediationPath,
                    'count' => 0,
                    'entities' => [],
                ];
            }
            // Blocker přebíjí warning: sloučená skupina má nést tu horší
            // závažnost, ne tu, která přišla první.
            if ($validation->severity === 'blocker') {
                $groups[$code]['severity'] = 'blocker';
            }
            ++$groups[$code]['count'];
            if (count($groups[$code]['entities']) < self::MAX_ENTITIES) {
                $groups[$code]['entities'][] = [
                    'entity_type' => $validation->entityType,
                    'entity_id' => $validation->entityId,
                ];
            }
        }

        return array_values($groups);
    }

    /**
     * Ověřené účty institucí, na které se za tenhle běh bude odvádět.
     *
     * Tohle je nález, který dosud přišel NEJPOZDĚJI ze všech: účet instituce
     * se hledá až při přípravě plateb, tedy po zamknutí, výpočtu, schválení
     * i zaúčtování. Účetní pak stála před hotovým zaúčtovaným během, který
     * nešlo zaplatit. Kontrola je záměrně hrubší než resolver plateb — hlídá
     * jen to, jestli ověřený a účinný účet vůbec existuje; přesnou volbu účtu
     * (a fallbacky u organizačních značek) dělá dál
     * {@see \MyInvoice\Service\Payroll\Payment\PayrollInstitutionPaymentTargetResolver}.
     *
     * @param array<string,mixed> $snapshotData
     * @return list<array{
     *   code:string,severity:string,message:string,remediation_path:?string,
     *   count:int,entities:list<array{entity_type:string,entity_id:?int}>
     * }>
     */
    private function institutionAccountFindings(
        int $supplierId,
        string $paymentDate,
        array $snapshotData,
    ): array {
        if (!$this->db->hasTable('payroll_institution_accounts')) {
            return [];
        }
        $verified = $this->verifiedAccountCodes($supplierId, $paymentDate);
        $missing = [];

        // ČSSZ a finanční úřad se platí u každého běhu se zaměstnanci.
        if (($verified['social_security'] ?? []) === []) {
            $missing[] = 'Správa sociálního zabezpečení';
        }
        if (($verified['tax_office'] ?? []) === []) {
            $missing[] = 'Finanční úřad (záloha na daň ze závislé činnosti)';
        }

        $insurerCodes = self::healthInsurerCodes($snapshotData);
        $knownInsurers = $verified['health_insurer'] ?? [];
        foreach ($insurerCodes as $code) {
            // U zdravotní pojišťovny kód NENÍ značka, ale identita příjemce —
            // jiný ověřený účet se místo něj použít nesmí, takže se hledá
            // přesná shoda kódu.
            if (!in_array($code, $knownInsurers, true)) {
                $missing[] = sprintf('Zdravotní pojišťovna %s', $code);
            }
        }

        if ($missing === []) {
            return [];
        }

        return [[
            'code' => 'institution_account_unverified',
            'severity' => 'warning',
            'message' => sprintf(
                'Bez ověřeného účinného účtu je zatím: %s. Spočítat a schválit '
                . 'mzdy jde i tak, ale příprava plateb se o tenhle účet zastaví. '
                . 'Doplňte ho v Mzdy → Nastavení zaměstnavatele → Účty '
                . 'institucí a označte ho jako ověřený.',
                implode(', ', $missing),
            ),
            'remediation_path' => '/payroll/settings',
            'count' => count($missing),
            'entities' => [],
        ]];
    }

    /**
     * Kódy institucí, které mají k datu výplaty ověřený a účinný účet.
     *
     * @return array<string,list<string>> institution_type => kódy
     */
    private function verifiedAccountCodes(int $supplierId, string $paymentDate): array
    {
        $latestVerification = PayrollInstitutionVerificationWindow::latestAcceptable(
            $paymentDate,
        );
        $placeholders = implode(',', array_fill(0, count(self::VERIFIED_SOURCE_KINDS), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT institution.institution_type,
                    institution.institution_code
               FROM payroll_institution_accounts account
               JOIN payroll_institutions institution
                 ON institution.id = account.institution_id
                AND institution.supplier_id = account.supplier_id
              WHERE account.supplier_id = ?
                AND account.currency_code = ?
                AND account.valid_from <= ?
                AND (account.valid_to IS NULL OR account.valid_to >= ?)
                AND account.verified_by IS NOT NULL
                AND account.verified_on <= ?
                AND account.source_kind IN (' . $placeholders . ')'
        );
        $stmt->execute(array_merge(
            [$supplierId, 'CZK', $paymentDate, $paymentDate, $latestVerification],
            self::VERIFIED_SOURCE_KINDS,
        ));

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['institution_type']][] =
                (string) $row['institution_code'];
        }

        return $result;
    }

    /**
     * Kódy zdravotních pojišťoven, na které se za období bude odvádět.
     *
     * Berou se ze zákonné evidence osob ve snímku — tedy z týchž dat, ze
     * kterých pak vyjde odvod.
     *
     * @param array<string,mixed> $snapshotData
     * @return list<string>
     */
    private static function healthInsurerCodes(array $snapshotData): array
    {
        $people = $snapshotData['people'] ?? [];
        if (!is_array($people)) {
            return [];
        }
        $codes = [];
        foreach ($people as $person) {
            if (!is_array($person)) {
                continue;
            }
            $evidence = $person['statutory_evidence'] ?? null;
            if (!is_array($evidence)) {
                continue;
            }
            $health = $evidence['health'] ?? null;
            if (!is_array($health)) {
                continue;
            }
            $coverage = $health['coverage'] ?? null;
            if (!is_array($coverage)) {
                continue;
            }
            $code = $coverage['insurer_code'] ?? null;
            if (is_string($code) && preg_match('/^[0-9]{3}$/D', $code) === 1) {
                $codes[$code] = true;
            }
        }

        return array_keys($codes);
    }
}
