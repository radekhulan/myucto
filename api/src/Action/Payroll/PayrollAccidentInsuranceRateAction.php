<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollAccidentInsuranceRateRepository;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\AccidentInsuranceRateAdvisor;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Nastavení sazby zákonného pojištění odpovědnosti zaměstnavatele
 * (vyhláška č. 125/1993 Sb.). `institution_code` odkazuje na řádek
 * v ověřeném registru institucí ({@see PayrollInstitutionAccountsAction},
 * `institution_type = 'statutory_insurance'`), kde firma vede účet a VS
 * pojistitele — tady se ukládá jen sazba a od kdy platí.
 */
final class PayrollAccidentInsuranceRateAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollAccidentInsuranceRateRepository $rates,
        private readonly PayrollModuleAccess $access,
        private readonly AccidentInsuranceRateAdvisor $advisor,
        // Kód pojistitele je odkaz do registru institucí, ne popisek. Bez téhle
        // kontroly se překlep pozná až při čtvrtletním předpisu pojistného,
        // tedy v měsíci, kdy už na opravu není čas.
        private readonly PayrollInstitutionAccountRepository $institutionAccounts,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($error = $this->guard($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }

        return Json::ok($response, [
            'rates' => $this->rates->list($this->currentSupplierId($request)),
        ]);
    }

    /**
     * GET /payroll/settings/accident-insurance-rate-schedule — celá příloha
     * č. 2 vyhlášky č. 125/1993 Sb. plus nezávazný návrh podle CZ-NACE firmy.
     *
     * Vrací se celý sazebník najednou (8 skupin, 98 činností ≈ pár kilobajtů),
     * takže filtrování běží v prohlížeči a endpoint nemá vyhledávací parametr —
     * na rozdíl od CZ-ISCO, které má skoro dva tisíce položek a hledá na
     * serveru.
     *
     * `suggestions_binding` je v odpovědi natvrdo `false`: sazba, za kterou se
     * ručí, je ta, kterou účetní určí podle skutečné převažující činnosti.
     */
    public function schedule(Request $request, Response $response): Response
    {
        if (($error = $this->guard($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }

        return Json::ok(
            $response,
            $this->advisor->advise($this->currentSupplierId($request)),
        );
    }

    public function create(Request $request, Response $response): Response
    {
        if (($error = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $institutionCode = strtoupper(trim((string) ($body['institution_code'] ?? '')));
        $rateRaw = trim((string) ($body['rate_per_mille'] ?? ''));
        $effectiveFrom = trim((string) ($body['effective_from'] ?? ''));

        if ($institutionCode === '') {
            return Json::error(
                $response,
                'validation_failed',
                'Vyplňte kód pojistitele — je to kód platebního účtu typu Zákonné pojištění'
                . ' z Mzdy → Nastavení mezd → Účty institucí.',
                422,
            );
        }
        if (preg_match('/^[A-Z0-9][A-Z0-9._-]{0,31}$/D', $institutionCode) !== 1) {
            return Json::error(
                $response,
                'validation_failed',
                'Kód pojistitele smí obsahovat jen písmena, číslice, tečku, podtržítko a pomlčku'
                . ' (nejvýše 32 znaků).',
                422,
            );
        }
        if (preg_match('/^[0-9]{1,3}(?:[.,][0-9]{1,2})?$/D', $rateRaw) !== 1) {
            return Json::error(
                $response,
                'validation_failed',
                'Sazba pojistného musí být kladné číslo v promile s nejvýše dvěma desetinnými místy.',
                422,
            );
        }
        $rate = str_replace(',', '.', $rateRaw);
        if ((float) $rate <= 0 || (float) $rate > 1000) {
            return Json::error(
                $response,
                'validation_failed',
                'Sazba pojistného musí být kladné číslo v promile.',
                422,
            );
        }
        $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveFrom);
        if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $effectiveFrom) {
            return Json::error(
                $response,
                'validation_failed',
                'Datum účinnosti sazby musí být platné datum ve tvaru RRRR-MM-DD.',
                422,
            );
        }

        $supplierId = $this->currentSupplierId($request);
        if (($mismatch = $this->rejectUnknownInsurerCode(
            $response,
            $supplierId,
            $institutionCode,
        )) !== null) {
            return $mismatch;
        }
        try {
            $id = $this->rates->insert(
                $supplierId,
                $institutionCode,
                $rate,
                $effectiveFrom,
                $this->userId($request),
            );
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            return Json::error(
                $response,
                'accident_insurance_rate_effective_from_conflict',
                'Pro toto datum účinnosti už sazba existuje.',
                409,
            );
        }

        $created = array_values(array_filter(
            $this->rates->list($supplierId),
            static fn (array $row): bool => $row['id'] === $id,
        ))[0] ?? null;

        return Json::ok($response, ['rate' => $created], 201);
    }

    /**
     * Kód pojistitele NENÍ popisek — předpis pojistného pod ním hledá platební
     * účet typu „Zákonné pojištění". Když se nenajde, spadne až čtvrtletní
     * příprava plateb, tedy o tři měsíce později. Proto se to ověřuje tady,
     * před uložením, a hláška rovnou vypíše kódy, které firma vede.
     *
     * Záměrně se neomezuje na účet účinný k datu sazby: sazba se běžně zapisuje
     * dřív, než se doplní platební účet na další období. Chytá se to, co je
     * skutečná past — kód, který v registru institucí není vůbec.
     */
    private function rejectUnknownInsurerCode(
        Response $response,
        int $supplierId,
        string $institutionCode,
    ): ?Response {
        $known = [];
        foreach ($this->institutionAccounts->list($supplierId) as $account) {
            if (($account['institution_type'] ?? null) !== 'statutory_insurance') {
                continue;
            }
            $code = strtoupper(trim((string) ($account['institution_code'] ?? '')));
            if ($code !== '') {
                $known[$code] = true;
            }
        }
        if (isset($known[$institutionCode])) {
            return null;
        }
        if ($known === []) {
            return Json::error(
                $response,
                'accident_insurance_institution_missing',
                'Firma zatím nemá žádný platební účet typu Zákonné pojištění, takže se sazba'
                . ' nemá k čemu navázat. Založte účet pojistitele v Mzdy → Nastavení mezd →'
                . ' Účty institucí a pak sazbu uložte se stejným kódem instituce.',
                422,
            );
        }

        $codes = array_keys($known);
        sort($codes);

        return Json::error(
            $response,
            'accident_insurance_institution_mismatch',
            'Kód pojistitele „' . $institutionCode . '“ neodpovídá žádnému platebnímu účtu typu'
            . ' Zákonné pojištění. Firma vede tyto kódy: ' . implode(', ', $codes)
            . '. Vyberte jeden z nich, nebo účet s novým kódem nejdřív založte'
            . ' v Mzdy → Nastavení mezd → Účty institucí.',
            422,
            ['known_institution_codes' => $codes],
        );
    }

    private function guard(Request $request, Response $response, AccessLevel $level): ?Response
    {
        $error = null;
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            );
        }
        if (!$this->requirePermission($request, $response, 'payroll.settings', $level, $error)) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        return null;
    }
}
