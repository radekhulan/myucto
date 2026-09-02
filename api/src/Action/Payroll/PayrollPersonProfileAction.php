<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollPersonNotFoundException;
use MyInvoice\Repository\Payroll\PayrollPersonProfileConflictException;
use MyInvoice\Repository\Payroll\PayrollPersonProfileRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollPersonProfileValidator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** @phpstan-import-type ProfileView from \MyInvoice\Repository\Payroll\PayrollPersonProfileRepository */
final class PayrollPersonProfileAction
{
    use PayrollActionSupport;

    /** Bod návratu pro `dry_run` uvnitř cizí transakce. */
    private const REHEARSAL_SAVEPOINT = 'payroll_person_profile_rehearsal';

    public function __construct(
        private readonly PayrollPersonProfileRepository $profiles,
        private readonly PayrollPersonProfileValidator $validator,
        private readonly PayrollModuleAccess $access,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
        // Z uložené karty odvodí výplatní pravidlo, plyne-li z ní jednoznačně
        // — viz komentář na konci put().
        private readonly \MyInvoice\Service\Payroll\Net\PayrollPayoutRuleDefaultsService $payoutDefaults,
    ) {}

    /** @param array{id:string} $args */
    public function get(Request $request, Response $response, array $args): Response
    {
        $error = null;
        if (!$this->requireSession($request, $response, $error)) {
            return $this->errorResponse($error);
        }
        if (!$this->requirePermission($request, $response, 'payroll', AccessLevel::READ, $error)) {
            return $this->errorResponse($error);
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $this->errorResponse($error);
        }

        $profile = $this->profiles->get(
            $this->currentSupplierId($request),
            (int) $args['id'],
        );
        if ($profile === null) {
            return Json::error($response, 'not_found', 'Zaměstnanec nenalezen.', 404);
        }

        return Json::ok($response, ['profile' => $profile]);
    }

    /** @param array{id:string} $args */
    public function put(Request $request, Response $response, array $args): Response
    {
        $error = null;
        if (!$this->requireSession($request, $response, $error)) {
            return $this->errorResponse($error);
        }
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.person.write',
            AccessLevel::WRITE,
            $error,
        )) {
            return $this->errorResponse($error);
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $this->errorResponse($error);
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return Json::error($response, 'validation_failed', 'Tělo požadavku musí být objekt.', 422);
        }
        try {
            $body = $this->stringKeyedArray($body);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        $version = filter_var($body['row_version'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);
        if (!is_int($version)) {
            return Json::error(
                $response,
                'validation_failed',
                'row_version musí být nezáporné celé číslo.',
                422,
            );
        }

        /*
         * Kontrola je SAMOSTATNÁ AKCE PŘED uložením, ne jeho podmínka.
         *
         * `dry_run: true` projde tutéž zápisovou cestu — tedy i kontroly, které
         * vidí až uložený stav (překryv intervalů, součet rozdělení výplaty,
         * podmínky stavu „hotová") — a pak celou transakci vrátí zpět. Uživatel
         * se tak dozví, co je špatně, aniž by cokoli riskoval, a rozdělaná
         * práce mu zůstává ve formuláři.
         */
        $dryRun = $body['dry_run'] ?? false;
        if (!is_bool($dryRun)) {
            return Json::error($response, 'validation_failed', 'dry_run musí být boolean.', 422);
        }

        $supplierId = $this->currentSupplierId($request);
        $employeeId = (int) $args['id'];
        $pdo = $this->db->pdo();
        /*
         * Chybějící `payout_effective_on` se ODVODÍ, nevyžaduje.
         *
         * Čtecí cesta ho vrací jako `null`, takže načtená karta poslaná zpět
         * beze změny skončila hláškou „payout_effective_on musí být datum
         * YYYY-MM-DD" — a jediná nabízená hodnota byl DNEŠEK. U člověka, který
         * nastoupil prvního a jehož kartu účetní dodělává až v dalším měsíci,
         * by pravidlo výplaty začalo platit po výplatním termínu, tedy pozdě.
         * Odvozuje se proto z nástupu; teprve když ani ten není, zbývá dnešek.
         */
        if (!isset($body['payout_effective_on'])
            || !is_string($body['payout_effective_on'])
            || trim($body['payout_effective_on']) === '') {
            $body['payout_effective_on'] = $this->defaultPayoutEffectiveOn($supplierId, $employeeId);
        }
        // Uvnitř cizí transakce (testy, dávkové zpracování) se zkouška zabalí
        // do SAVEPOINTu — jinak by `dry_run` tiše zapsal, což je horší než
        // kdyby neexistoval.
        $rehearsal = $dryRun;
        $rehearsalOwnsTransaction = false;
        if ($rehearsal) {
            if ($pdo->inTransaction()) {
                $pdo->exec('SAVEPOINT ' . self::REHEARSAL_SAVEPOINT);
            } else {
                $pdo->beginTransaction();
                $rehearsalOwnsTransaction = true;
            }
        }
        try {
            $normalized = $this->validator->validate($body);
            $profile = $this->profiles->save(
                $supplierId,
                $employeeId,
                $normalized,
                $version,
                $this->userId($request),
                $this->ipMatcher->clientIpFromRequest(
                    $this->stringKeyedArray($request->getServerParams()),
                ),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (PayrollPersonNotFoundException) {
            $this->rollBackRehearsal($rehearsal, $rehearsalOwnsTransaction);
            return Json::error($response, 'not_found', 'Zaměstnanec nenalezen.', 404);
        } catch (PayrollPersonProfileConflictException $e) {
            $this->rollBackRehearsal($rehearsal, $rehearsalOwnsTransaction);
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->rollBackRehearsal($rehearsal, $rehearsalOwnsTransaction);
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->rollBackRehearsal($rehearsal, $rehearsalOwnsTransaction);
            throw $e;
        }
        $this->rollBackRehearsal($rehearsal, $rehearsalOwnsTransaction);

        /*
         * Z uložené karty rovnou VZNIKNE výplatní pravidlo, pokud z ní plyne
         * jednoznačně.
         *
         * `payout_method` na kartě byl deklarativní údaj, ze kterého se nic
         * neodvozovalo — o výplatě rozhoduje samostatná sada v
         * `payroll_payout_rules`. Účetní tedy vyplnila „bankou" i číslo účtu,
         * karta vypadala hotově a chybějící pravidlo se ozvalo až u příkazu
         * Připravit platby, tedy PO zaúčtování mzdy; náprava pak znamenala
         * vyžádat opravu běhu a přepočítat novou revizi.
         *
         * ⚠️ Nic se nehádá a nic se nepřepisuje: služba zapíše jen tehdy, když
         * osoba žádné aktivní pravidlo nemá a z karty plyne jediný cíl
         * (viz {@see PayrollPayoutRuleDefaultsService}). Rozdělená výplata,
         * chybějící nebo neověřený účet zůstávají na ručním zadání.
         */
        if (!$dryRun) {
            try {
                $this->payoutDefaults->applyDefaults($supplierId, $employeeId);
            } catch (\Throwable) {
                // Nejednoznačná karta = pravidlo se nezaloží a zůstane ruční
                // cesta. Uložení karty to nesmí shodit.
            }
        }

        return Json::ok($response, ['profile' => $profile, 'dry_run' => $dryRun]);
    }

    /**
     * Odkdy platí pravidlo výplaty, když ho volající neposlal: dosud uložené
     * datum, jinak nejstarší nástup osoby, jinak dnešek.
     */
    private function defaultPayoutEffectiveOn(int $supplierId, int $employeeId): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT payout_effective_on FROM payroll_employee_profiles
              WHERE supplier_id = ? AND employee_id = ?'
        );
        $stmt->execute([$supplierId, $employeeId]);
        $stored = $stmt->fetchColumn();
        if (is_string($stored) && $stored !== '') {
            return substr($stored, 0, 10);
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT MIN(COALESCE(actual_start_date, start_date))
               FROM payroll_employments
              WHERE supplier_id = ? AND employee_id = ?'
        );
        $stmt->execute([$supplierId, $employeeId]);
        $start = $stmt->fetchColumn();

        return is_string($start) && $start !== '' ? substr($start, 0, 10) : date('Y-m-d');
    }

    private function rollBackRehearsal(bool $rehearsal, bool $ownsTransaction): void
    {
        if (!$rehearsal) {
            return;
        }
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            return;
        }
        if ($ownsTransaction) {
            $pdo->rollBack();

            return;
        }
        $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::REHEARSAL_SAVEPOINT);
        $pdo->exec('RELEASE SAVEPOINT ' . self::REHEARSAL_SAVEPOINT);
    }

    private function requireSession(
        Request $request,
        Response $response,
        ?Response &$error,
    ): bool {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            $error = Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            );
            return false;
        }
        $error = null;

        return true;
    }

    private function errorResponse(?Response $error): Response
    {
        return $error ?? throw new \LogicException('Chybí chybová HTTP odpověď.');
    }

    /**
     * @param array<mixed> $value
     * @return array<string,mixed>
     */
    private function stringKeyedArray(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Tělo požadavku musí být objekt.');
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
