<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollDeletionException;
use MyInvoice\Repository\Payroll\PayrollEmployeeDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentNotFoundException;
use MyInvoice\Repository\Payroll\PayrollModuleStateRepository;
use MyInvoice\Repository\PayrollEmployeeRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\Payroll\PayrollCalculator;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Zaměstnanci/jednatelé-společníci pro mzdový list (§38j ZDP) — REST CRUD nad
 * `payroll_employees` (migrace 1105). Čistě identifikace + prohlášení poplatníka;
 * samotný rozpad mzdy za měsíc počítá a účtuje {@see PayrollAction}.
 *
 *   GET    /api/accounting/payroll/employees        — seznam (readonly+)
 *   POST   /api/accounting/payroll/employees        — založit (účetní|admin)
 *   PUT    /api/accounting/payroll/employees/{id}    — upravit (účetní|admin)
 *   DELETE /api/accounting/payroll/employees/{id}    — smazat, jen bez historie (účetní|admin;
 *          patří-li osoba novému mzdovému modulu, navíc `payroll.person.write` — viz {@see self::delete()})
 */
final class PayrollEmployeeAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    /**
     * Pracovněprávní vztah — migrace 1156, `statutory_body` doplněn migrací 1302;
     * řídí režim pojistného a srážkové daně.
     *
     * `statutory_body` (smlouva o výkonu funkce, § 59 ZOK) má SHODNÝ klíč jako
     * `payroll_employments.relation_type` v novějším mzdovém modulu — jeden právní
     * pojem, jeden identifikátor, aby mapování mezi větvemi bylo identita.
     */
    private const EMPLOYMENT_TYPES = ['hpp', 'dpp', 'dpc', 'statutory_body'];

    /**
     * Kombinace, které se nevylučují, ale skoro jistě jsou překlep — hlásí se
     * varováním, neblokují.
     *
     * `employment_type` a `taxpayer_type` popisují dvě různé věci: první právní titul
     * příjmu, druhá kontaci (521/331 vs. 522/366). Vynutit jedno druhým by z jednoho
     * pole udělalo dvě autority nad týmž faktem, a kombinace může legitimně vzniknout
     * u někoho, kdo má u firmy obojí (jednatel se zároveň zaměstnaneckou smlouvou).
     */
    private const TAXPAYER_TYPE_HINTS = [
        'statutory_body' => PayrollCalculator::TYPE_MANAGING_PARTNER,
    ];

    /** Účtové skupiny, na které čistou mzdu přeúčtovat nelze — vlastní modul si je hlídá sám. */
    private const MONEY_ACCOUNT_PREFIXES = ['21', '22', '26'];

    /** Shodný strop jako {@see PayrollAction} — nad ním už rozpad není důvěryhodný. */
    private const MAX_MONTHLY_GROSS = 10_000_000;

    private const GROSS_RANGE_MESSAGE = 'Pravidelná hrubá mzda musí být celé číslo v rozsahu 0 až 10 000 000 Kč.';

    /**
     * Stavy, ve kterých osobu vlastní NOVÝ mzdový modul a mazání proto rozhoduje
     * {@see PayrollEmployeeDeletionRepository}. `suspended` je pozastavený modul,
     * který už jednou běžel — data v něm jsou stejně reálná jako v `active`.
     *
     * @var list<string>
     */
    private const MODULE_OWNED_STATUSES = ['active', 'suspended'];

    /**
     * Překlad tabulek nového modulu do řeči účetní. Neúplný záměrně — co v mapě
     * není, se shrne jako „další evidence modulu Mzdy"; hláška se tím nerozbije,
     * jen bude obecnější, a přesný seznam tabulek jde ven v detailu chyby.
     *
     * @var array<string,string>
     */
    private const MODULE_DATA_LABELS = [
        'payroll_employments' => 'pracovní vztah',
        'payroll_employee_profiles' => 'mzdový profil',
        'payroll_person_identity_history' => 'osobní údaje',
        'payroll_person_addresses' => 'adresa',
        'payroll_person_contacts' => 'kontakt',
        'payroll_person_identifiers' => 'osobní identifikátor',
        'payroll_person_accounts' => 'výplatní účet',
        'payroll_payout_rules' => 'výplatní pravidlo',
        'payroll_dependants' => 'vyživovaná osoba',
        'payroll_person_tax_declarations' => 'prohlášení poplatníka',
        'payroll_inputs' => 'mzdový vstup',
        'payroll_business_trips' => 'pracovní cesta',
        'payroll_generated_documents' => 'vydaný mzdový doklad',
        'payroll_net_results' => 'schválený mzdový výpočet',
        'payroll_payment_liabilities' => 'platební závazek',
        'payroll_enforcement_cases' => 'exekuce nebo insolvence',
        'payroll_person_external_ids' => 'registrace u ČSSZ nebo MPSV',
    ];

    public function __construct(
        private readonly PayrollEmployeeRepository $employees,
        private readonly \MyInvoice\Repository\ChartOfAccountsRepository $accounts,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly PayrollModuleStateRepository $moduleState,
        private readonly PayrollEmployeeDeletionRepository $moduleDeletion,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $q = $request->getQueryParams();
        $active = array_key_exists('active', $q) && $q['active'] !== ''
            ? (bool) filter_var($q['active'], FILTER_VALIDATE_BOOLEAN)
            : null;

        return Json::ok($response, ['items' => $this->employees->listForTenant($supplierId, $active)]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $body = (array) ($request->getParsedBody() ?? []);
        $data = $this->normalize($supplierId, $body, $response, $err);
        if ($data === null) return $err;
        if (!$this->autoPostHasGross($data, $response, $err)) return $err;

        $id = $this->employees->insert($supplierId, $data);
        $this->log($request, 'payroll_employee.created', $id, ['full_name' => $data['full_name']]);
        return Json::ok($response, [
            'employee' => $this->employees->find($supplierId, $id),
            'warnings' => self::consistencyWarnings($data),
        ], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $id = (int) $args['id'];
        $current = $this->employees->find($supplierId, $id);
        if ($current === null) {
            return Json::error($response, 'not_found', 'Zaměstnanec nenalezen.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $fields = $this->normalizePartial($supplierId, $body, $response, $err);
        if ($fields === null) return $err;
        if (!$this->autoPostHasGross(array_merge($current, $fields), $response, $err)) return $err;

        $this->employees->update($supplierId, $id, $fields);
        $this->log($request, 'payroll_employee.updated', $id, array_keys($fields));
        return Json::ok($response, [
            'employee' => $this->employees->find($supplierId, $id),
            // Varování se počítá nad SLOUČENÝM stavem — částečný update může poslat
            // jen jedno z dvojice polí a nesoulad vznikne až vůči tomu, co je na kartě.
            'warnings' => self::consistencyWarnings(array_merge($current, $fields)),
        ]);
    }

    /**
     * Smazání zaměstnance ze STARŠÍ agendy.
     *
     * ── Proč to není prosté DELETE ────────────────────────────────────────────────
     * `payroll_employees` je TÁŽ tabulka, nad kterou stojí novější mzdový modul.
     * Tahle routa hlídala jedinou navázanou tabulku (`payroll_monthly_records`),
     * takže osobu, která žije jen v novém modulu, pustila — a databázová kaskáda
     * pod ní tiše smetla profil, pracovní vztahy, docházku, absence, identitu,
     * kontakty, účty i vyživované osoby. Rozhodnutí proto přebírá
     * {@see PayrollEmployeeDeletionRepository}, jakmile osoba patří novému modulu.
     *
     * ── Tři větve ─────────────────────────────────────────────────────────────────
     *  1. modul `active`/`suspended` → rozhoduje NOVÁ kontrola (blokátory + rekurze
     *     přes pracovní vztahy + atomické mazání pod zámkem),
     *  2. modul `setup`/`disabled`, ale data nového modulu UŽ EXISTUJÍ → odmítnout;
     *     ve stavu `setup` totiž data vznikají dřív, než se modul překlopí, a nová
     *     kontrola by se na ně nepodívala,
     *  3. modul neaktivní a bez dat → stará agenda beze změny; firmy, které nový
     *     modul nepoužívají, tahle oprava omezit nesmí.
     *
     * @param array{id:string} $args
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $id = (int) $args['id'];
        // 404 dřív, než se cokoli dozví o stavu — cizí tenant nesmí poznat ani to,
        // jestli id existuje.
        $employee = $this->employees->find($supplierId, $id);
        if ($employee === null) {
            return Json::error($response, 'not_found', 'Zaměstnanec nenalezen.', 404);
        }

        $status = $this->moduleStatus($supplierId);
        if (in_array($status, self::MODULE_OWNED_STATUSES, true)) {
            return $this->deleteViaModule($request, $response, $supplierId, $id, $employee, $status);
        }

        $moduleData = $this->moduleData($supplierId, $id);
        if ($moduleData !== []) {
            return Json::error(
                $response,
                'employee_has_payroll_module_data',
                'Zaměstnanec má rozepsaná data v modulu Mzdy (' . self::describe($moduleData) . '), '
                    . 'a to i když modul ještě není zapnutý naostro. Smazáním odsud by se ta data '
                    . 'ztratila i s pracovními vztahy a docházkou. Smažte osobu v modulu Mzdy '
                    . '(Mzdy → Zaměstnanci), kde uvidíte, co přesně zmizí, nebo ji tady jen deaktivujte.',
                409,
                ['payroll_module_status' => $status, 'tables' => $moduleData],
            );
        }

        if ($this->employees->hasMonthlyRecords($supplierId, $id)) {
            return Json::error(
                $response,
                'employee_has_records',
                'Zaměstnanec má uložené mzdové záznamy (mzdový list) — smazáním by se ztratila evidence. Deaktivuj ho místo mazání.',
                409,
            );
        }

        try {
            $this->employees->delete($supplierId, $id);
        } catch (\PDOException $e) {
            // FK RESTRICT z tabulky, kterou tahle větev nezná. Bez odchycení by
            // uživatel dostal 500 se syrovou databázovou hláškou.
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            return Json::error(
                $response,
                'employee_has_related_records',
                'Na zaměstnance je navázaný další záznam, který smazání brání. '
                    . 'Načtěte seznam znovu; pokud potíž trvá, osobu místo mazání deaktivujte.',
                409,
            );
        }

        $this->log($request, 'payroll_employee.deleted', $id, self::auditPayload($employee, 'legacy', $status, []));
        return Json::ok($response, ['deleted' => true]);
    }

    /**
     * Větev 1 — osobu vlastní nový mzdový modul.
     *
     * ── Oprávnění ─────────────────────────────────────────────────────────────────
     * Middleware sem pustí obecné `accounting` WRITE, protože z cesty stav modulu
     * poznat nejde. Jenže tady se maže OSOBNÍ KARTA mzdového modulu se vším, co na
     * ní visí — týž úkon jako DELETE /api/payroll/people/{id}, který si žádá
     * `payroll.person.write`. Kdyby to obojí nešlo přes stejné právo, byla by tahle
     * routa obchvat kolem něj. Vestavěná role „účetní" `payroll.person.write` má,
     * takže účetní, která mzdy legitimně vede, nic neztratí; přijde o to jen role,
     * které mzdy někdo vědomě odebral — a té ta osoba nepatří.
     *
     * @param array<string,mixed> $employee
     */
    private function deleteViaModule(
        Request $request,
        Response $response,
        int $supplierId,
        int $id,
        array $employee,
        string $status,
    ): Response {
        if (!RequestAuthorization::allows($request, 'payroll.person.write', AccessLevel::WRITE)) {
            return Json::error(
                $response,
                'forbidden',
                'Tenhle zaměstnanec patří do modulu Mzdy, takže jeho smazání vyžaduje právo '
                    . '„Spravovat zaměstnance" v mzdách — samotné právo na účetnictví nestačí, '
                    . 'protože by se smazala celá mzdová karta.',
                403,
            );
        }

        try {
            $cascade = $this->moduleDeletion->delete(
                $supplierId,
                $id,
                $this->userId($request),
                $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (PayrollDeletionException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 409, array_filter([
                'employment_id' => $e->employmentId,
                'employment_code' => $e->employmentCode,
            ], static fn ($value): bool => $value !== null));
        } catch (PayrollEmploymentNotFoundException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            return Json::error(
                $response,
                'payroll_employee_delete_conflict',
                'Na zaměstnance mezitím vznikla vazba, takže ho už smazat nejde. Načtěte seznam znovu.',
                409,
            );
        }

        $this->log($request, 'payroll_employee.deleted', $id, self::auditPayload($employee, 'payroll_module', $status, $cascade));
        return Json::ok($response, ['deleted' => true, 'cascade' => $cascade]);
    }

    /**
     * Stav mzdového modulu firmy. Chybějící tabulka = modul ve schématu ještě není,
     * takže se chová jako `disabled` — starší agenda musí fungovat i na databázi,
     * kde mzdové migrace neproběhly.
     */
    private function moduleStatus(int $supplierId): string
    {
        if (!$this->db->hasTable('payroll_module_state')) {
            return 'disabled';
        }

        return $this->moduleState->get($supplierId)['status'];
    }

    /** @return array<string,int> tabulka => počet záznamů nového modulu */
    private function moduleData(int $supplierId, int $id): array
    {
        if (!$this->db->hasTable('payroll_employments')) {
            return [];
        }

        return $this->moduleDeletion->moduleDataCounts($supplierId, $id);
    }

    /**
     * Hláška musí říct, CO přesně v novém modulu leží — „nelze smazat" bez důvodu
     * uživateli nedovolí nic udělat. Jména tabulek jsou pro účetní nesrozumitelná,
     * takže se překládají; strojový seznam tabulek jde ven v detailu chyby.
     *
     * @param array<string,int> $counts
     */
    private static function describe(array $counts): string
    {
        $labels = [];
        foreach (array_keys($counts) as $table) {
            $labels[self::MODULE_DATA_LABELS[$table] ?? 'další evidence modulu Mzdy'] = true;
        }
        $named = array_slice(array_keys($labels), 0, 3);
        $rest = count($labels) - count($named);

        return implode(', ', $named) . ($rest > 0 ? ' a další (' . $rest . ')' : '');
    }

    /**
     * Auditní stopa smazání. Do teď byl payload PRÁZDNÝ, takže po smazané osobě
     * nezůstalo nic dohledatelného — řádek zmizel a log neřekl ani jméno.
     * Zapisuje se proto koho se to týkalo, kolik navázaných záznamů zmizelo
     * a KTEROU cestou to šlo (stará agenda vs. kontrola nového modulu).
     *
     * U modulové cesty vzniknou záznamy dva: `payroll.employee.deleted` z repozitáře
     * (co zmizelo v modulu) a `payroll_employee.deleted` odsud (kdo a kudy to spustil).
     * Duplicita je záměrná — z prvního se nepozná, že šlo o starší agendu.
     *
     * @param array<string,mixed> $employee
     * @param array<string,int> $cascade
     * @return array<string,mixed>
     */
    private static function auditPayload(array $employee, string $path, string $status, array $cascade): array
    {
        return [
            'path' => $path,
            'payroll_module_status' => $status,
            'full_name' => (string) ($employee['full_name'] ?? ''),
            'employment_type' => (string) ($employee['employment_type'] ?? ''),
            'taxpayer_type' => (string) ($employee['taxpayer_type'] ?? ''),
            'is_active' => (bool) ($employee['is_active'] ?? false),
            'cascade' => $cascade,
            'cascade_total' => array_sum($cascade),
        ];
    }

    /** @param array<string,mixed> $body @return array<string,mixed>|null */
    private function normalize(int $supplierId, array $body, Response $response, ?Response &$err): ?array
    {
        $fullName = trim((string) ($body['full_name'] ?? ''));
        if ($fullName === '') {
            $err = Json::error($response, 'validation_failed', 'Jméno a příjmení je povinné.', 422);
            return null;
        }
        $employmentType = in_array((string) ($body['employment_type'] ?? ''), self::EMPLOYMENT_TYPES, true)
            ? (string) $body['employment_type']
            : 'hpp';
        // Nezadaný typ poplatníka se u výkonu funkce PŘEDVYPLNÍ na jednatele-společníka
        // (kontace 522/366), protože to je u členů statutárního orgánu obvyklý stav.
        // Předvyplní, NEvynutí: výslovně poslanou hodnotu se nepřebíjí, jen se na
        // nesoulad upozorní varováním — viz self::TAXPAYER_TYPE_HINTS.
        $type = (string) ($body['taxpayer_type']
            ?? self::TAXPAYER_TYPE_HINTS[$employmentType]
            ?? PayrollCalculator::TYPE_EMPLOYEE);
        if (!in_array($type, PayrollCalculator::types(), true)) {
            $err = Json::error($response, 'validation_failed', 'Neznámý typ poplatníka.', 422);
            return null;
        }
        $birthDate = $this->nullableDate($body['birth_date'] ?? null);
        if ($birthDate === false) {
            $err = Json::error($response, 'validation_failed', 'Neplatné datum narození (očekává se YYYY-MM-DD).', 422);
            return null;
        }
        $childCount = (int) ($body['child_count'] ?? 0);
        if ($childCount < 0 || $childCount > 20) {
            $err = Json::error($response, 'validation_failed', 'Počet dětí musí být v rozsahu 0 až 20.', 422);
            return null;
        }
        $monthlyGross = $this->nullableGross($body['monthly_gross'] ?? null);
        if ($monthlyGross === false) {
            $err = Json::error($response, 'validation_failed', self::GROSS_RANGE_MESSAGE, 422);
            return null;
        }
        $settlement = $this->settlementAccount($supplierId, $body['net_settlement_account_code'] ?? null, $response, $err);
        if ($err !== null) {
            return null;
        }
        if (!$this->rejectPlaintextIdentity($body, $response, $err)) {
            return null;
        }

        $err = null;
        return [
            'full_name'           => $fullName,
            'birth_date'          => $birthDate,
            'taxpayer_type'       => $type,
            'tax_credit_taxpayer' => array_key_exists('tax_credit_taxpayer', $body)
                ? (bool) filter_var($body['tax_credit_taxpayer'], FILTER_VALIDATE_BOOLEAN)
                : true,
            // § 38k odst. 4 — bez podepsaného prohlášení se měsíční sleva uplatnit NESMÍ.
            // Chybí-li údaj, bere se jako NEPODEPSANÉ: nesrazit dost je horší než srazit
            // víc, protože přeplatek se vrátí v ročním zúčtování, kdežto za nesraženou
            // zálohu ručí plátce (§ 38s ZDP). Pole do teď v API vůbec nebylo, takže
            // zaměstnance bez prohlášení nešlo zadat.
            'tax_declaration_signed' => array_key_exists('tax_declaration_signed', $body)
                ? (bool) filter_var($body['tax_declaration_signed'], FILTER_VALIDATE_BOOLEAN)
                : false,
            'employment_type'     => $employmentType,
            'child_count'         => $childCount,
            // Migrace 1178 — kam se měsíčně přeúčtuje čistá mzda (typicky 365.x).
            'net_settlement_account_code' => $settlement,
            // Migrace 1175 — pravidelná mzda a pověření cronu, ať se měsíc od měsíce
            // neopisuje táž konstanta.
            'monthly_gross'       => $monthlyGross,
            'auto_post'           => array_key_exists('auto_post', $body)
                ? (bool) filter_var($body['auto_post'], FILTER_VALIDATE_BOOLEAN)
                : false,
            'is_active'           => array_key_exists('is_active', $body)
                ? (bool) filter_var($body['is_active'], FILTER_VALIDATE_BOOLEAN)
                : true,
        ];
    }

    /** @param array<string,mixed> $body @return array<string,mixed>|null */
    private function normalizePartial(int $supplierId, array $body, Response $response, ?Response &$err): ?array
    {
        $fields = [];
        if (!$this->rejectPlaintextIdentity($body, $response, $err)) {
            return null;
        }
        if (array_key_exists('full_name', $body)) {
            $fullName = trim((string) $body['full_name']);
            if ($fullName === '') {
                $err = Json::error($response, 'validation_failed', 'Jméno a příjmení je povinné.', 422);
                return null;
            }
            $fields['full_name'] = $fullName;
        }
        if (array_key_exists('taxpayer_type', $body)) {
            $type = (string) $body['taxpayer_type'];
            if (!in_array($type, PayrollCalculator::types(), true)) {
                $err = Json::error($response, 'validation_failed', 'Neznámý typ poplatníka.', 422);
                return null;
            }
            $fields['taxpayer_type'] = $type;
        }
        if (array_key_exists('birth_date', $body)) {
            $birthDate = $this->nullableDate($body['birth_date']);
            if ($birthDate === false) {
                $err = Json::error($response, 'validation_failed', 'Neplatné datum narození (očekává se YYYY-MM-DD).', 422);
                return null;
            }
            $fields['birth_date'] = $birthDate;
        }
        if (array_key_exists('tax_credit_taxpayer', $body)) {
            $fields['tax_credit_taxpayer'] = (bool) filter_var($body['tax_credit_taxpayer'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('tax_declaration_signed', $body)) {
            $fields['tax_declaration_signed'] = (bool) filter_var($body['tax_declaration_signed'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('employment_type', $body)) {
            $type = (string) $body['employment_type'];
            if (!in_array($type, self::EMPLOYMENT_TYPES, true)) {
                $err = Json::error($response, 'validation_failed', sprintf(
                    'Neznámý typ pracovněprávního vztahu (%s).',
                    implode('|', self::EMPLOYMENT_TYPES),
                ), 422);
                return null;
            }
            $fields['employment_type'] = $type;
        }
        if (array_key_exists('child_count', $body)) {
            $childCount = (int) $body['child_count'];
            if ($childCount < 0 || $childCount > 20) {
                $err = Json::error($response, 'validation_failed', 'Počet dětí musí být v rozsahu 0 až 20.', 422);
                return null;
            }
            $fields['child_count'] = $childCount;
        }
        if (array_key_exists('monthly_gross', $body)) {
            $monthlyGross = $this->nullableGross($body['monthly_gross']);
            if ($monthlyGross === false) {
                $err = Json::error($response, 'validation_failed', self::GROSS_RANGE_MESSAGE, 422);
                return null;
            }
            $fields['monthly_gross'] = $monthlyGross;
        }
        if (array_key_exists('net_settlement_account_code', $body)) {
            $fields['net_settlement_account_code'] =
                $this->settlementAccount($supplierId, $body['net_settlement_account_code'], $response, $err);
            if ($err !== null) {
                return null;
            }
        }
        if (array_key_exists('auto_post', $body)) {
            $fields['auto_post'] = (bool) filter_var($body['auto_post'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('is_active', $body)) {
            $fields['is_active'] = (bool) filter_var($body['is_active'], FILTER_VALIDATE_BOOLEAN);
        }
        $err = null;
        return $fields;
    }

    /**
     * Rodné číslo ani adresa tudy dovnitř NESMÍ (W1/P-02).
     *
     * Tahle routa je chráněná jen právem `accounting`, takže by otevřené rodné
     * číslo zapsal i uživatel bez jediného mzdového práva — mimo šifrovanou
     * evidenci `payroll_person_identifiers` a mimo stopu o odhalení. Zápis proto
     * nekončí zápisem, ale odmítnutím.
     *
     * PRÁZDNÁ hodnota se ale toleruje: legacy formulář (`PayrollRecap.vue`) posílá
     * `birth_number` i `address` v KAŽDÉM uložení, u nevyplněného pole jako `null`.
     * Tvrdé 422 na pouhou přítomnost klíče by rozbilo úplně běžné uložení karty,
     * kdežto odmítnout se má jen skutečný pokus uložit osobní údaj. Vyplněné pole
     * proto vrací 422 s důvodem — tiché zahození by uživateli tvrdilo, že se
     * rodné číslo uložilo, a on by ho v novém modulu nikdy nenašel.
     *
     * @param array<string,mixed> $body
     */
    private function rejectPlaintextIdentity(array $body, Response $response, ?Response &$err): bool
    {
        foreach (['birth_number' => 'Rodné číslo', 'address' => 'Adresa'] as $key => $label) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            if (trim((string) ($body[$key] ?? '')) === '') {
                continue;
            }
            $err = Json::error($response, 'validation_failed', sprintf(
                '%s se přes tuto routu ukládat nesmí — patří do mzdové karty osoby,'
                . ' kde se ukládá šifrovaně a se stopou o nahlédnutí.',
                $label,
            ), 422);
            return false;
        }
        $err = null;
        return true;
    }

    /**
     * Účet pro měsíční přeúčtování čisté mzdy (migrace 1178). Prázdno = NULL (nepřeúčtovávat).
     *
     * Musí existovat v osnově TÉTO firmy — kód přitéká z API a bez scope kontroly by karta
     * ukazovala na účet cizího tenanta, který by pak zaúčtování stejně neumělo přeložit.
     *
     * PENĚŽNÍ ÚČTY SE ODMÍTAJÍ. Výplatu z pokladny musí zapsat výdajový pokladní doklad,
     * jinak se pokladní kniha (zákonná evidence dle §29 ZoÚ) rozejde s hlavní knihou;
     * bankovní výplatu zaúčtuje párování výpisu a mzdový automat by ji zdvojil. Obojí má
     * v aplikaci vlastní modul, tenhle přepínač je na bezhotovostní přeúčtování (365, 479…).
     */
    private function settlementAccount(int $supplierId, mixed $value, Response $response, ?Response &$err): ?string
    {
        $code = trim((string) ($value ?? ''));
        if ($code === '') {
            $err = null;
            return null;
        }
        $account = $this->accounts->findByCode($supplierId, $code);
        if ($account === null) {
            $err = Json::error($response, 'validation_failed', sprintf(
                'Účet %s není v účtové osnově firmy.',
                $code,
            ), 422);
            return null;
        }
        if (empty($account['is_active'])) {
            $err = Json::error($response, 'validation_failed', sprintf(
                'Účet %s je neaktivní — vyber jiný.',
                $code,
            ), 422);
            return null;
        }
        foreach (self::MONEY_ACCOUNT_PREFIXES as $prefix) {
            if (str_starts_with($code, $prefix)) {
                $err = Json::error($response, 'validation_failed', sprintf(
                    'Na peněžní účet (%s) čistou mzdu přeúčtovat nelze — výplatu z pokladny zapiš '
                        . 'výdajovým pokladním dokladem a výplatu z účtu spáruj v bance, jinak se '
                        . 'pokladní kniha a výpis rozejdou s deníkem.',
                    $code,
                ), 422);
                return null;
            }
        }

        $err = null;
        return $code;
    }

    /**
     * Zapnutý automat bez částky je past: cron by neměl co zaúčtovat a uživatel by
     * čekal zápis, který nikdy nepřijde. Kontrola je nutně nad SLOUČENÝM stavem —
     * částečný update může poslat jen příznak a mzda přitom už na kartě je (a naopak,
     * vynulování mzdy musí shodit i příznak).
     *
     * @param array<string,mixed> $effective stav karty po aplikaci změn
     */
    private function autoPostHasGross(array $effective, Response $response, ?Response &$err): bool
    {
        $gross = $effective['monthly_gross'] ?? null;
        if (!empty($effective['auto_post']) && ($gross === null || (int) $gross <= 0)) {
            $err = Json::error(
                $response,
                'validation_failed',
                'Automatické měsíční účtování jde zapnout jen s vyplněnou pravidelnou hrubou mzdou — bez ní by cron neměl co zaúčtovat.',
                422,
            );
            return false;
        }
        $err = null;
        return true;
    }

    /**
     * Nesoulad pracovněprávního vztahu a typu poplatníka — VAROVÁNÍ, ne chyba.
     *
     * Odměna člena statutárního orgánu se u nás v drtivé většině účtuje 522/366, takže
     * kombinace „výkon funkce + zaměstnanec" je skoro jistě překlep. Blokovat ji ale
     * nelze: jeden člověk může mít u téže firmy zároveň smlouvu o výkonu funkce
     * a pracovní poměr, a kdo si to na jedné kartě vede jinak, má na to právo — kontace
     * je věcí účtové osnovy firmy, ne zákona.
     *
     * Metoda je veřejná a statická záměrně: totéž pravidlo zrcadlí formulář ve frontendu
     * a testy ho volají přímo. Schované v `private` by se okopírovalo dřív, než by se
     * našlo (viz AGENTS.md — „SSOT musí jít ZAVOLAT").
     *
     * @param array<string,mixed> $effective stav karty po aplikaci změn
     * @return list<string>
     */
    public static function consistencyWarnings(array $effective): array
    {
        $employmentType = (string) ($effective['employment_type'] ?? 'hpp');
        $expected = self::TAXPAYER_TYPE_HINTS[$employmentType] ?? null;
        if ($expected === null || (string) ($effective['taxpayer_type'] ?? '') === $expected) {
            return [];
        }

        return [
            'Vztah „smlouva o výkonu funkce" (§ 59 ZOK) se obvykle pojí s typem poplatníka '
                . '„jednatel/společník" — odměna se pak účtuje 522/366 místo 521/331. '
                . 'Zvolená kombinace není chyba, jen ověřte, že je to záměr.',
        ];
    }

    /** @return int|null|false false = mimo rozsah */
    private function nullableGross(mixed $v): int|null|false
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_numeric($v)) {
            return false;
        }
        $gross = (int) $v;
        return ($gross < 0 || $gross > self::MAX_MONTHLY_GROSS) ? false : $gross;
    }

    /** @return string|null|false false = neplatný formát */
    private function nullableDate(mixed $v): string|null|false
    {
        if ($v === null || $v === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $v);
        if ($d === false || $d->format('Y-m-d') !== $v) {
            return false;
        }
        return $v;
    }

    /** @param array<mixed> $payload */
    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log($action, $this->userId($request), 'payroll_employee', $id, $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $this->currentSupplierId($request));
    }
}
