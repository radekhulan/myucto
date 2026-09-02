<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollOpeningBalanceService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Počáteční stavy mzdových kumulací — úhrny z předchozího zpracování.
 *
 * Tabulka pro ně existovala od migrace 1258, ale nevedla k ní žádná cesta:
 * `appendOpeningBalance()` volaly jen testy. Zaměstnanec převzatý z jiného
 * programu tak zablokoval celý mzdový běh a odblokovat to nešlo.
 */
final class PayrollOpeningBalanceAction
{
    use PayrollActionSupport;

    /**
     * Měsíční částky, které uživatel opisuje ze sestavy předchozího programu.
     *
     * Klíč je sloupec, hodnota je NÁZEV SLOUPCE V TABULCE NA OBRAZOVCE. Hláška
     * musí ukázat na buňku, do které se má sáhnout; „Částka
     * »advance_base_minor_units« za měsíc 3" účetní nikam nenavede.
     */
    private const MONTH_FIELDS = [
        'social_assessment_base_minor_units' => 'Vyměřovací základ sociálního pojištění',
        'advance_base_minor_units' => 'Základ zálohové daně',
        'advance_tax_minor_units' => 'Záloha na daň',
        'withholding_base_minor_units' => 'Základ srážkové daně',
        'withholding_tax_minor_units' => 'Srážková daň',
        'applied_non_refundable_credits_minor_units' => 'Uplatněné slevy na dani',
        'applied_child_credit_minor_units' => 'Uplatněné daňové zvýhodnění na děti',
        'tax_bonus_minor_units' => 'Daňový bonus',
        'bonus_qualifying_income_minor_units' => 'Příjem rozhodný pro bonus',
    ];

    public function __construct(
        private readonly PayrollOpeningBalanceService $openings,
        private readonly PayrollModuleAccess $access,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array{id:string} $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        try {
            return Json::ok($response, ['openings' => $this->openings->current(
                $this->currentSupplierId($request),
                (int) $args['id'],
                $this->year($request->getQueryParams()['year'] ?? null),
            )]);
        } catch (\Throwable $e) {
            return $this->failure($response, $e);
        }
    }

    /** @param array{id:string} $args */
    public function save(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $body = $request->getParsedBody();
            if (!is_array($body)) {
                throw new \InvalidArgumentException('Tělo požadavku musí být objekt.');
            }
            $reference = trim(is_string($body['source_reference'] ?? null) ? $body['source_reference'] : '');

            return Json::ok($response, ['openings' => $this->openings->save(
                $this->currentSupplierId($request),
                (int) $args['id'],
                $this->year($body['year'] ?? null),
                $this->months($body['months'] ?? null),
                $reference,
                $this->userId($request),
            )]);
        } catch (\Throwable $e) {
            return $this->failure($response, $e);
        }
    }

    /** @return list<array<string,int>> */
    private function months(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Měsíce musí být pole.');
        }
        $seen = [];
        $months = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('Každý měsíc musí být objekt.');
            }
            $month = filter_var($item['month'] ?? null, FILTER_VALIDATE_INT);
            if ($month === false || $month < 1 || $month > 12) {
                throw new \InvalidArgumentException('Měsíc musí být číslo 1 až 12.');
            }
            if (isset($seen[$month])) {
                throw new \InvalidArgumentException("Měsíc {$month} je v podkladech dvakrát.");
            }
            $seen[$month] = true;

            $row = ['month' => $month];
            foreach (self::MONTH_FIELDS as $field => $label) {
                $amount = filter_var($item[$field] ?? 0, FILTER_VALIDATE_INT);
                if ($amount === false || $amount < 0) {
                    throw new \InvalidArgumentException(sprintf(
                        'Sloupec „%s" v měsíci %d musí být částka nula nebo vyšší.',
                        $label,
                        $month,
                    ));
                }
                $row[$field] = $amount;
            }
            $months[] = $row;
        }
        ksort($seen);

        return $months;
    }

    private function year(mixed $value): int
    {
        $year = filter_var($value, FILTER_VALIDATE_INT);
        if ($year === false || $year < 2000 || $year > 2200) {
            throw new \InvalidArgumentException('Rok počátečních stavů není platný.');
        }

        return $year;
    }

    private function failure(Response $response, \Throwable $e): Response
    {
        if ($e instanceof \InvalidArgumentException) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        if ($e instanceof \DomainException) {
            return Json::error($response, 'opening_balance_conflict', $e->getMessage(), 409);
        }
        throw $e;
    }

    private function authorize(Request $request, Response $response, AccessLevel $level): ?Response
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            );
        }
        $error = null;
        $permission = $level === AccessLevel::WRITE
            ? 'payroll.employment.write'
            : 'payroll.employment.read';
        if (!$this->requirePermission($request, $response, $permission, $level, $error)) {
            return $error ?? throw new \LogicException('Chybí chybová odpověď oprávnění.');
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error ?? throw new \LogicException('Chybí chybová odpověď modulu.');
        }

        return null;
    }
}
