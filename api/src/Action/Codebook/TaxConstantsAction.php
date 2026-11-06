<?php

declare(strict_types=1);

namespace MyInvoice\Action\Codebook;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Tax\PausalSchedule;
use MyInvoice\Service\Tax\TaxConstants;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Číselník ročních daňových konstant (GLOBÁLNÍ, admin-only):
 *   GET    /api/codebooks/tax-constants        — seznam roků (efektivní data + is_override)
 *   PUT    /api/codebooks/tax-constants/{year}  — uložit override pro rok
 *   DELETE /api/codebooks/tax-constants/{year}  — reset na default (smazat override)
 *
 * List je čitelný i pro ne-admina (read-only zobrazení), zápis jen admin.
 * Hodnoty jsou národní (ne per-supplier), proto bez supplier scope.
 */
final class TaxConstantsAction
{
    public function __construct(
        private readonly TaxConstantsRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** GET /api/codebooks/tax-constants */
    public function list(Request $request, Response $response): Response
    {
        return Json::ok($response, ['years' => $this->repo->listEffective()]);
    }

    /** PUT /api/codebooks/tax-constants/{year} */
    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin smí měnit daňové konstanty.', 403);
        }
        $year = (int) ($args['year'] ?? 0);
        if ($year < 2018 || $year > 2100) {
            return Json::error($response, 'invalid_year', 'Neplatný rok.', 422);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : $body;
        $data = $this->repo->mergeWithOverride($year, $data);
        $err = $this->validate($data, $year);
        if ($err !== null) {
            return Json::error($response, 'validation_failed', $err, 422);
        }

        $this->repo->upsert($year, $data);
        $this->audit($request, 'tax_constants.updated', $year);
        return Json::ok($response, [
            'year'        => $year,
            'is_override' => true,
            'data'        => $this->repo->forYear($year),
        ]);
    }

    /** DELETE /api/codebooks/tax-constants/{year} — reset na default */
    public function reset(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin smí měnit daňové konstanty.', 403);
        }
        $year = (int) ($args['year'] ?? 0);
        $this->repo->reset($year);
        $this->audit($request, 'tax_constants.reset', $year);
        return Json::ok($response, [
            'year'        => $year,
            'is_override' => false,
            'data'        => $this->repo->forYear($year),
        ]);
    }

    /** Minimální validace — povinné skalární klíče + struktura vnořených. */
    private function validate(array $d, int $year): ?string
    {
        $scalars = [
            'credit_taxpayer', 'credit_spouse', 'tax_rate_low', 'tax_rate_high', 'tax_high_threshold',
            'social_rate', 'health_rate', 'social_assessment_pct', 'health_assessment_pct',
            'social_min_base_main', 'social_min_base_secondary', 'health_min_base',
            'social_max_base', 'child_bonus_min', 'fixed_asset_limit', 'transition_receivables_max_years',
            'mortgage_cap', 'mortgage_cap_pre2021', 'pension_cap', 'vat_limit_low', 'vat_limit_high',
            'vat_rate_standard', 'vat_rate_reduced', 'kh_item_threshold',
            'sickness_rate', 'sickness_min_monthly_base', 'donation_min_fo', 'donation_cap_fo_pct',
            'donation_cap_po_pct', 'corporate_tax_rate', 'disabled_employee_credit',
            'disabled_employee_credit_severe', 'advance_threshold_low', 'advance_threshold_high',
            'rounding_base_fo', 'rounding_base_po',
            'minimum_wage', 'child_bonus_min_income', 'spouse_income_limit', 'spouse_child_max_age',
            'tax_loss_carry_years', 'vat_coefficient_full_threshold_pct', 'donation_min_fo_pct',
            'donation_min_po', 'advance_semiannual_rate', 'advance_quarterly_rate',
            'advance_rounding_step', 'm1_depreciation_limit',
        ];
        foreach ($scalars as $k) {
            if (!isset($d[$k]) || !is_numeric($d[$k])) {
                return "Chybí nebo není číslo: {$k}";
            }
        }
        try {
            \MyInvoice\Service\Tax\Nonprofit\NonprofitDppoReduction::configuration($d);
        } catch (\MyInvoice\Service\Tax\Return\TaxReturnException $e) {
            return $e->getMessage();
        }
        foreach (['tax_loss_carryback_years','tax_loss_carryback_limit'] as $key) {
            $value = $d[$key] ?? null;
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0 || $value > ($key === 'tax_loss_carryback_years' ? 100 : 99999999999999) || floor($value) !== (float) $value) {
                return "{$key} musí být nezáporné celé číslo v povoleném rozsahu.";
            }
        }
        // Měsíční hranice §38h odst. 2 — přibyla později než ostatní skaláry, proto stejně
        // jako payroll.advance_tax_high jen volitelně (chybějící doplní merge).
        if (isset($d['advance_tax_high_threshold'])
            && (!is_numeric($d['advance_tax_high_threshold']) || (float) $d['advance_tax_high_threshold'] < 0)
        ) {
            return 'advance_tax_high_threshold musí být nezáporné číslo.';
        }
        foreach (['band_ceilings', 'expense_caps', 'extraordinary_depreciation',
            'depreciation_straight_rates', 'depreciation_accelerated_coefficients',
            'entity_category_thresholds', 'filing_deadlines'] as $k) {
            if (!isset($d[$k]) || !is_array($d[$k])) {
                return "{$k} musí být objekt.";
            }
        }
        if (!isset($d['child_credits']) || !is_array($d['child_credits']) || $d['child_credits'] === []) {
            return 'child_credits musí být neprázdné pole.';
        }
        // Mzdové sazby (PayrollCalculator). Validujeme jen když je override pošle —
        // chybějící blok doplní merge v TaxConstantsRepository::forYear() z defaultů,
        // takže starší FE, které klíč nezná, override neuloží nevalidní.
        if (isset($d['payroll'])) {
            if (!is_array($d['payroll'])) {
                return 'payroll musí být objekt.';
            }
            foreach (['employee_social', 'employee_health', 'employer_social',
                'employer_health', 'health_total', 'advance_tax'] as $k) {
                if (!isset($d['payroll'][$k]) || !is_numeric($d['payroll'][$k])
                    || (float) $d['payroll'][$k] < 0 || (float) $d['payroll'][$k] > 1
                ) {
                    return "payroll.{$k} musí být sazba v rozsahu 0–1.";
                }
            }
            // Progresivní sazba (§38h odst. 2) přibyla později — starší FE ji neposílá
            // a merge ji doplní z defaultů, proto validujeme jen když dorazí.
            if (isset($d['payroll']['advance_tax_high'])) {
                $high = $d['payroll']['advance_tax_high'];
                if (!is_numeric($high) || (float) $high < 0 || (float) $high > 1) {
                    return 'payroll.advance_tax_high musí být sazba v rozsahu 0–1.';
                }
                if ((float) $high < (float) $d['payroll']['advance_tax']) {
                    return 'payroll.advance_tax_high nesmí být nižší než payroll.advance_tax.';
                }
            }
        }
        foreach (['advance_semiannual_months', 'advance_quarterly_months'] as $k) {
            if (!isset($d[$k]) || !is_array($d[$k]) || $d[$k] === []) {
                return "{$k} musí být neprázdné pole.";
            }
            foreach ($d[$k] as $month) {
                if (!is_numeric($month) || (int) $month < 1 || (int) $month > 12) {
                    return "{$k} obsahuje neplatný měsíc.";
                }
            }
        }
        if (!isset($d['mortgage_pre2021_cutoff']) || !$this->isDate((string) $d['mortgage_pre2021_cutoff'])) {
            return 'mortgage_pre2021_cutoff musí být datum YYYY-MM-DD.';
        }
        $extra = (array) $d['extraordinary_depreciation'];
        foreach (['eligible_from', 'eligible_to'] as $key) {
            if (!isset($extra[$key]) || !$this->isDate((string) $extra[$key])) {
                return "extraordinary_depreciation.{$key} musí být datum YYYY-MM-DD.";
            }
        }
        foreach (['total_months', 'phase1_months', 'phase1_share'] as $key) {
            if (!isset($extra[$key]) || !is_numeric($extra[$key]) || (float) $extra[$key] <= 0) {
                return "extraordinary_depreciation.{$key} musí být kladné číslo.";
            }
        }
        if ((int) $extra['phase1_months'] >= (int) $extra['total_months'] || (float) $extra['phase1_share'] >= 1) {
            return 'Fáze mimořádných odpisů musí být kratší než celý plán a mít podíl menší než 1.';
        }
        foreach (['basic', 'p20', 'p15', 'p10'] as $variant) {
            if (!isset($d['depreciation_straight_rates'][$variant]) || !is_array($d['depreciation_straight_rates'][$variant])) {
                return "Chybí tabulka rovnoměrných odpisů {$variant}.";
            }
            foreach ($d['depreciation_straight_rates'][$variant] as $rates) {
                if (!is_array($rates) || count($rates) !== 3 || count(array_filter($rates, 'is_numeric')) !== 3) {
                    return "Tabulka rovnoměrných odpisů {$variant} musí mít pro každou skupinu tři sazby.";
                }
            }
        }
        foreach ($d['depreciation_accelerated_coefficients'] as $coefficients) {
            if (!is_array($coefficients) || count($coefficients) !== 3 || count(array_filter($coefficients, 'is_numeric')) !== 3) {
                return 'Koeficienty zrychlených odpisů musí mít pro každou skupinu tři hodnoty.';
            }
        }
        foreach (['micro', 'small', 'medium'] as $category) {
            $limits = $d['entity_category_thresholds'][$category] ?? null;
            if (!is_array($limits)) {
                return "Chybí prahy kategorie {$category}.";
            }
            foreach (['assets_net', 'net_turnover', 'employees'] as $key) {
                if (!isset($limits[$key]) || !is_numeric($limits[$key]) || (float) $limits[$key] < 0) {
                    return "Neplatný práh {$category}.{$key}.";
                }
            }
        }
        foreach (['dpfo_paper', 'dpfo_electronic', 'advisor', 'insurance_electronic', 'insurance_advisor'] as $key) {
            if (!isset($d['filing_deadlines'][$key]) || !preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$/', (string) $d['filing_deadlines'][$key])) {
                return "filing_deadlines.{$key} musí být MM-DD.";
            }
        }
        foreach (['health_advance_day', 'tax_advance_day'] as $key) {
            $day = $d['filing_deadlines'][$key] ?? null;
            if (!is_numeric($day) || (int) $day < 1 || (int) $day > 31) {
                return "filing_deadlines.{$key} musí být den 1–31.";
            }
        }
        // Paušální daň: buď rozvrh měsíčních záloh (nově), nebo roční částky
        // (starší klienti API — `pausal_annual` se pak bere doslova).
        if (isset($d['pausal_monthly'])) {
            return $this->validatePausalMonthly($d['pausal_monthly'], $year);
        }
        if (!isset($d['pausal_annual']) || !is_array($d['pausal_annual'])) {
            return 'pausal_monthly (nebo alespoň pausal_annual) musí být objekt.';
        }
        return null;
    }

    /**
     * Rozvrh měsíčních záloh: neprázdný seznam segmentů, první účinný k 1. 1.
     * daného roku, data uvnitř roku, ostře rostoucí, vždy všechna tři pásma.
     */
    private function validatePausalMonthly(mixed $segments, int $year): ?string
    {
        if (!is_array($segments) || $segments === []) {
            return 'pausal_monthly musí být neprázdný seznam období.';
        }
        $prev = null;
        foreach (array_values($segments) as $i => $seg) {
            if (!is_array($seg)) {
                return 'pausal_monthly: každé období musí být objekt.';
            }
            $from = is_string($seg['from'] ?? null) ? trim((string) $seg['from']) : '';
            if (!preg_match('/^\d{4}-\d{2}-01$/', $from) || substr($from, 0, 4) !== (string) $year) {
                return "pausal_monthly: „od\" musí být 1. den měsíce roku {$year} (YYYY-MM-01).";
            }
            if ($i === 0 && $from !== sprintf('%04d-01-01', $year)) {
                return 'pausal_monthly: první období musí začínat 1. ledna.';
            }
            if ($prev !== null && $from <= $prev) {
                return 'pausal_monthly: období musí jít vzestupně a bez duplicit.';
            }
            foreach (PausalSchedule::BANDS as $band) {
                if (!isset($seg[$band]) || !is_numeric($seg[$band]) || (float) $seg[$band] < 0) {
                    return "pausal_monthly: chybí nebo je záporná měsíční záloha ({$band}) od {$from}.";
                }
            }
            $prev = $from;
        }
        return null;
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function isAdmin(Request $request): bool
    {
        return RequestAuthorization::isSuperadmin($request);
    }

    private function audit(Request $request, string $action, int $year): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, $user['id'] ?? null, 'tax_constants', $year, [], $ip, $request->getHeaderLine('User-Agent'));
    }
}
