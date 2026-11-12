<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Import\OpeningBalance\OpeningBalanceMonthValidator;
use MyInvoice\Service\Payroll\Import\OpeningBalance\OpeningBalanceTabularImportService;
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

    public function __construct(
        private readonly PayrollOpeningBalanceService $openings,
        private readonly OpeningBalanceTabularImportService $tabular,
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
                true,
            )]);
        } catch (\Throwable $e) {
            return $this->failure($response, $e);
        }
    }

    /**
     * Vzorový soubor se správnou hlavičkou.
     *
     * Bez něj hlavičku nikdo netrefí a import skončí na „chybí povinný sloupec".
     */
    public function importTemplate(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        $response->getBody()->write(OpeningBalanceTabularImportService::template());

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="pocatecni-stavy-vzor.csv"');
    }

    /** Náhled tabulkového importu za celou firmu — nic nezapisuje. */
    public function importPreview(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $body = $this->body($request);

            return Json::ok($response, ['preview' => $this->tabular->preview(
                $this->currentSupplierId($request),
                is_string($body['format'] ?? null) ? $body['format'] : '',
                is_string($body['source_name'] ?? null) ? $body['source_name'] : '',
                $this->content($body),
            )]);
        } catch (\Throwable $e) {
            return $this->failure($response, $e);
        }
    }

    public function importApply(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        try {
            $body = $this->body($request);

            return Json::ok($response, ['import' => $this->tabular->apply(
                $this->currentSupplierId($request),
                is_string($body['format'] ?? null) ? $body['format'] : '',
                is_string($body['source_name'] ?? null) ? $body['source_name'] : '',
                $this->content($body),
                $this->userId($request),
            )]);
        } catch (\Throwable $e) {
            return $this->failure($response, $e);
        }
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new \InvalidArgumentException('Tělo požadavku musí být objekt.');
        }

        return $body;
    }

    /** @param array<string,mixed> $body */
    private function content(array $body): string
    {
        $encoded = is_string($body['content_base64'] ?? null) ? $body['content_base64'] : '';
        if ($encoded === '' || strlen($encoded) > 6_700_000) {
            throw new \InvalidArgumentException('Importní obsah chybí nebo překračuje bezpečný limit.');
        }
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('content_base64 není platné Base64.');
        }

        return $decoded;
    }

    /** @return list<array<string,int|bool>> */
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
            // Sada sloupců i jejich popisky mají jediný zdroj — mřížka, tabulkový
            // import a import hlášení JMHZ musí znát tytéž sloupce.
            foreach (OpeningBalanceMonthValidator::labels() as $field => $label) {
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
            // Nulový měsíc se musí potvrdit; prázdná mřížka nula není.
            if (($item[OpeningBalanceMonthValidator::CONFIRMED_ZERO] ?? false) === true) {
                $row[OpeningBalanceMonthValidator::CONFIRMED_ZERO] = true;
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
        if (!RequestAuthorization::actsAsSession($request)) {
            return Json::sessionRequired($response);
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
