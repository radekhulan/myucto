<?php

declare(strict_types=1);

namespace MyInvoice\Service\NumberSeries;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\NumberSeriesRepository;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\DocumentSeriesService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\InvoiceSeriesCompletenessService;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use MyInvoice\Service\PurchaseInvoice\PurchaseNumberSeries;
use MyInvoice\Http\IsoDate;
use PDO;

/**
 * Správa číselníku řad firmy: seznam všech řad (převedených i nových) s tvarem čísla
 * a příštím číslem, založení a úprava nové řady, výchozí řada druhu, pokladny
 * a uživatele, ukončení řady a kontrola úplnosti po řadách.
 *
 * Převedená řada ({@see NumberSeriesCatalog}) se čísluje dosavadním generátorem, takže
 * její tvar čísla a příští číslo se tu čtou z dosavadního úložiště. Příští číslo jde
 * u vydaných dokladů a řad účetních dokladů nastavit přímo odsud (stejnou cestou jako
 * dosud v nastavení), šablona převedené řady se mění tam, kde se měnila dosud.
 */
final class NumberSeriesService
{
    /** Vzorek pro kontrolu, že se čísla dvou řad nemohou splést. */
    private const SAMPLE_DATE = '2099-12-15';

    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly NumberSeriesRepository $series,
        private readonly NumberSeriesCatalog $catalog,
        private readonly NumberSeriesAllocator $allocator,
        private readonly NativeSeriesCompleteness $nativeCompleteness,
        private readonly VarsymbolGenerator $varsymbol,
        private readonly DocumentSeriesService $documentSeries,
        private readonly InvoiceSeriesCompletenessService $issuedCompleteness,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Všechny řady firmy pro číselník.
     *
     * @return list<array<string,mixed>>
     */
    public function list(int $supplierId, ?int $userId = null): array
    {
        $this->catalog->sync($supplierId);
        $live = array_flip(array_column($this->catalog->liveLegacy($supplierId), 'legacy_key'));
        $userDefaults = $userId !== null ? $this->series->userDefaults($supplierId, $userId) : [];
        $scopeNames = $this->scopeNames($supplierId);
        $documented = $this->seriesWithDocuments($supplierId);

        $out = [];
        foreach ($this->series->listForSupplier($supplierId) as $row) {
            if ($row['storage'] === 'legacy' && !isset($live[(string) $row['legacy_key']])) {
                continue;
            }
            $out[] = $this->present($supplierId, $row, $scopeNames, $userDefaults, $documented);
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public function get(int $supplierId, int $id, ?int $userId = null): array
    {
        $row = $this->require($supplierId, $id);
        $userDefaults = $userId !== null ? $this->series->userDefaults($supplierId, $userId) : [];
        return $this->present($supplierId, $row, $this->scopeNames($supplierId), $userDefaults, $this->seriesWithDocuments($supplierId));
    }

    /**
     * Řady, ze kterých jde vybrat při zakládání dokladu druhu (a pokladny).
     *
     * @return array{kind:string, multiple:bool, default_id:?int, series:list<array<string,mixed>>}
     */
    public function options(int $supplierId, string $kind, int $registerId = 0, ?int $userId = null): array
    {
        if (!NumberSeriesKinds::isSelectable($kind)) {
            throw new NumberSeriesException('kind_invalid', 'Pro tento druh dokladu se řada nevybírá.');
        }
        $this->catalog->sync($supplierId);
        $today = date('Y-m-d');
        $scopeNames = $this->scopeNames($supplierId);
        $usable = [];
        foreach ($this->series->listForKind($supplierId, $kind) as $row) {
            if (!NumberSeriesAllocator::usableFor($row, $kind, $registerId)) {
                continue;
            }
            if ($row['valid_to'] !== null && (string) $row['valid_to'] < $today) {
                continue;
            }
            // Z převedených řad je na výběr jen hlavní řada druhu (u pokladny i řada pokladny);
            // ostatní převedené řady volí dosavadní generátor sám.
            if ($row['storage'] === 'legacy' && !$this->isMainLegacy($row)) {
                continue;
            }
            $usable[] = $row;
        }
        // Pokladna s vlastní řadou čísluje svou řadou; firemní PPD/VPD se pro ni nenabízí.
        if ($registerId > 0 && array_filter($usable, static fn (array $r): bool => $r['storage'] === 'legacy' && $r['scope_type'] === 'cash_register') !== []) {
            $usable = array_values(array_filter($usable, static fn (array $r): bool => !($r['storage'] === 'legacy' && $r['scope_type'] === 'company')));
        }
        try {
            $default = $this->allocator->resolve($supplierId, $kind, null, $registerId, $userId);
        } catch (NumberSeriesException) {
            $default = null;
        }
        $series = [];
        foreach ($usable as $row) {
            $series[] = [
                'id'       => $row['id'],
                'name'     => $row['name'],
                'label'    => $this->label($row, $scopeNames),
                'storage'  => $row['storage'],
                'prefix'   => $row['prefix'],
                'template' => $this->describe($supplierId, $row)['template'],
                'valid_to' => $row['valid_to'],
            ];
        }
        $defaultId = $default !== null && in_array($default['id'], array_column($series, 'id'), true) ? $default['id'] : null;
        return [
            'kind'       => $kind,
            'multiple'   => count($series) > 1,
            'default_id' => $defaultId ?? ($series[0]['id'] ?? null),
            'series'     => $series,
        ];
    }

    /**
     * Založí novou řadu.
     *
     * @param array<string,mixed> $input doc_kind, name, prefix, template, period, scope_id
     *                                   (pokladna u PPD/VPD), valid_to, next_number, is_default
     * @return array<string,mixed>
     */
    public function create(int $supplierId, array $input, ?int $userId): array
    {
        $kind = (string) ($input['doc_kind'] ?? '');
        if (!NumberSeriesKinds::isSelectable($kind)) {
            throw new NumberSeriesException('kind_invalid', 'Pro tento druh dokladu nejde založit další řadu.');
        }
        if ($kind === 'manual' && !$this->isDoubleEntry($supplierId)) {
            throw new NumberSeriesException('kind_invalid', 'Interní doklady vede jen podvojné účetnictví.');
        }
        $scopeId = (int) ($input['scope_id'] ?? 0);
        $scopeType = 'company';
        if ($scopeId > 0) {
            if (!NumberSeriesKinds::isRegisterScoped($kind) || !$this->registerBelongs($supplierId, $scopeId)) {
                throw new NumberSeriesException('scope_invalid', 'Pokladna nepatří k této firmě.');
            }
            $scopeType = 'cash_register';
        }
        $data = [
            'doc_kind'   => $kind,
            'scope_type' => $scopeType,
            'scope_id'   => $scopeId,
            'name'       => self::name($input['name'] ?? ''),
            'prefix'     => self::prefix($input['prefix'] ?? null),
            'template'   => trim((string) ($input['template'] ?? '')) ?: NumberSeriesKinds::DEFAULT_NATIVE_TEMPLATE,
            'period'     => (string) ($input['period'] ?? 'year'),
            'valid_to'   => self::validTo($input['valid_to'] ?? null),
            'sort_order' => (int) ($input['sort_order'] ?? 0),
        ];
        $this->assertTemplate($supplierId, $data, null);

        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $id = $this->series->insertNative($supplierId, $data, $userId);
            $row = $this->require($supplierId, $id);
            if (isset($input['next_number']) && (int) $input['next_number'] > 1) {
                $this->allocator->setNextNumber($row, (int) $input['next_number'], new \DateTimeImmutable('today'));
            }
            if (!empty($input['is_default'])) {
                $this->series->setDefault($supplierId, $row);
            }
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $this->audit('number_series.created', $userId, $supplierId, $id, $data);
        return $this->get($supplierId, $id, $userId);
    }

    /**
     * Úprava řady. Název, pořadí a ukončení u každé řady; prefix, šablona a období jen
     * u nové řady, dokud z ní nevzniklo číslo; příští číslo u nové řady a u převedených
     * řad vydaných dokladů a účetních dokladů.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function update(int $supplierId, int $id, array $input, ?int $userId): array
    {
        $row = $this->require($supplierId, $id);
        $patch = [];
        if (array_key_exists('name', $input)) {
            $patch['name'] = $row['storage'] === 'legacy'
                ? mb_substr(trim((string) $input['name']), 0, 100)
                : self::name($input['name']);
        }
        if (array_key_exists('sort_order', $input)) {
            $patch['sort_order'] = (int) $input['sort_order'];
        }
        if (array_key_exists('valid_to', $input)) {
            $validTo = self::validTo($input['valid_to']);
            if ($validTo !== null) {
                if (!NumberSeriesKinds::isSelectable((string) $row['doc_kind']) || !in_array($row['scope_type'], ['company', 'cash_register'], true)) {
                    throw new NumberSeriesException('end_not_supported', 'Tuto řadu nejde ukončit v číselníku.');
                }
                if ($row['is_default']) {
                    throw new NumberSeriesException('default_cannot_end', 'Výchozí řadu nejde ukončit. Nejdřív nastavte výchozí jinou řadu.');
                }
            }
            $patch['valid_to'] = $validTo;
        }
        $templateKeys = array_intersect_key($input, array_flip(['prefix', 'template', 'period']));
        if ($templateKeys !== []) {
            if ($row['storage'] !== 'native') {
                throw new NumberSeriesException('legacy_template', 'Tvar čísla převedené řady se mění v původním nastavení.');
            }
            $next = [
                'doc_kind' => $row['doc_kind'],
                'prefix'   => array_key_exists('prefix', $input) ? self::prefix($input['prefix']) : $row['prefix'],
                'template' => array_key_exists('template', $input) ? trim((string) $input['template']) : (string) $row['template'],
                'period'   => array_key_exists('period', $input) ? (string) $input['period'] : (string) $row['period'],
            ];
            $changed = $next['prefix'] !== $row['prefix'] || $next['template'] !== $row['template'] || $next['period'] !== $row['period'];
            if ($changed) {
                if ($this->series->hasCounters($id) || $this->hasDocuments($supplierId, $row)) {
                    throw new NumberSeriesException('series_in_use', 'Z řady už vznikla čísla dokladů, její tvar nejde změnit. Založte novou řadu a tuto ukončete.', 409);
                }
                $this->assertTemplate($supplierId, $next, $id);
                $patch += ['prefix' => $next['prefix'], 'template' => $next['template'], 'period' => $next['period']];
            }
        }
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $this->series->update($supplierId, $id, $patch);
            if (array_key_exists('next_number', $input) && $input['next_number'] !== null && $input['next_number'] !== '') {
                $this->setNextNumber($supplierId, $this->require($supplierId, $id), (int) $input['next_number']);
            }
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $this->audit('number_series.updated', $userId, $supplierId, $id, $patch + array_intersect_key($input, ['next_number' => true]));
        return $this->get($supplierId, $id, $userId);
    }

    /** Nastaví řadu jako výchozí pro její druh (a pokladnu). */
    public function setDefault(int $supplierId, int $id, ?int $userId): array
    {
        $row = $this->require($supplierId, $id);
        if (!NumberSeriesKinds::isSelectable((string) $row['doc_kind'])
            || !in_array($row['scope_type'], ['company', 'cash_register'], true)
            || ($row['storage'] === 'legacy' && !$this->isMainLegacy($row))) {
            throw new NumberSeriesException('default_not_supported', 'Tuto řadu nejde nastavit jako výchozí.');
        }
        if ($row['valid_to'] !== null && (string) $row['valid_to'] < date('Y-m-d')) {
            throw new NumberSeriesException('series_ended', 'Ukončená řada nemůže být výchozí.');
        }
        $this->series->setDefault($supplierId, $row);
        $this->audit('number_series.default_set', $userId, $supplierId, $id, ['doc_kind' => $row['doc_kind'], 'scope_id' => $row['scope_id']]);
        return $this->get($supplierId, $id, $userId);
    }

    /** Výchozí řada uživatele pro druh (u PPD/VPD pro pokladnu); null = zrušit. */
    public function setUserDefault(int $supplierId, int $userId, string $kind, int $scopeId, ?int $seriesId): void
    {
        if (!NumberSeriesKinds::isSelectable($kind)) {
            throw new NumberSeriesException('kind_invalid', 'Pro tento druh dokladu se řada nevybírá.');
        }
        if ($scopeId > 0 && (!NumberSeriesKinds::isRegisterScoped($kind) || !$this->registerBelongs($supplierId, $scopeId))) {
            throw new NumberSeriesException('scope_invalid', 'Pokladna nepatří k této firmě.');
        }
        if ($seriesId !== null) {
            $row = $this->require($supplierId, $seriesId);
            if (!NumberSeriesAllocator::usableFor($row, $kind, $scopeId)) {
                throw new NumberSeriesException('series_invalid', 'Vybraná číselná řada nepatří k tomuto druhu dokladu.');
            }
        }
        $this->series->setUserDefault($supplierId, $userId, $kind, $scopeId, $seriesId);
    }

    /** Smaže novou řadu, ze které ještě nevznikl žádný doklad. Jinak ji jde jen ukončit. */
    public function delete(int $supplierId, int $id, ?int $userId): void
    {
        $row = $this->require($supplierId, $id);
        if ($row['storage'] !== 'native') {
            throw new NumberSeriesException('legacy_delete', 'Převedenou řadu nejde smazat, jde ji jen ukončit.', 409);
        }
        if ($row['is_default']) {
            throw new NumberSeriesException('default_cannot_delete', 'Výchozí řadu nejde smazat. Nejdřív nastavte výchozí jinou řadu.', 409);
        }
        if ($this->series->hasCounters($id) || $this->hasDocuments($supplierId, $row)) {
            throw new NumberSeriesException('series_in_use', 'Řada už má doklady, nejde ji smazat, jen ukončit.', 409);
        }
        $space = NumberSeriesKinds::space((string) $row['doc_kind']);
        if ($space !== null) {
            // Koncepty s volbou této řady se vrací na výchozí řadu.
            $table = NumberSeriesKinds::SPACES[$space]['table'];
            $this->db->pdo()->prepare("UPDATE {$table} SET number_series_id = NULL WHERE supplier_id = ? AND number_series_id = ?")
                ->execute([$supplierId, $id]);
        }
        $this->series->delete($supplierId, $id);
        $this->audit('number_series.deleted', $userId, $supplierId, $id, ['doc_kind' => $row['doc_kind'], 'name' => $row['name']]);
    }

    /**
     * Úplnost řad za rok, po řadách. Řady vydaných daňových dokladů přes
     * {@see InvoiceSeriesCompletenessService} (stejný výsledek jako report § 61.5), nové
     * řady ostatních druhů přes {@see NativeSeriesCompleteness}.
     *
     * @return array{year:int, series:list<array<string,mixed>>, unassigned:list<array<string,mixed>>}
     */
    public function completeness(int $supplierId, int $year): array
    {
        $this->catalog->sync($supplierId);
        $byKey = [];
        foreach ($this->series->listForSupplier($supplierId) as $row) {
            if ($row['legacy_key'] !== null) {
                $byKey[(string) $row['legacy_key']] = $row;
            }
        }
        $result = [];
        $unassigned = [];
        foreach ($this->issuedCompleteness->build($supplierId, $year) as $report) {
            $seriesId = $report['number_series_id'] ?? null;
            if ($seriesId === null && empty($report['inferred'])) {
                $type = $report['types'][0];
                $key = match (true) {
                    $report['client_id'] > 0           => "client:{$report['client_id']}:{$type}",
                    $report['revenue_category_id'] > 0 => "revenue_category:{$report['revenue_category_id']}:{$type}",
                    default                            => "issued:{$type}",
                };
                $seriesId = isset($byKey[$key]) ? $byKey[$key]['id'] : null;
            }
            if ($seriesId === null) {
                $unassigned[] = $report;
                continue;
            }
            $result[] = ['number_series_id' => (int) $seriesId, 'types' => $report['types'], 'period' => $report['period'],
                'template' => reset($report['template_by_type']) ?: '', 'buckets' => $report['buckets']];
        }
        foreach ($this->series->listForSupplier($supplierId) as $row) {
            if ($row['storage'] !== 'native' || in_array($row['doc_kind'], ['invoice', 'credit_note'], true)) {
                continue;
            }
            $buckets = $this->nativeCompleteness->buckets($supplierId, $row, $year);
            if ($buckets === []) {
                continue;
            }
            $result[] = ['number_series_id' => $row['id'], 'types' => [$row['doc_kind']], 'period' => $row['period'],
                'template' => str_replace('{PREFIX}', (string) $row['prefix'], (string) $row['template']), 'buckets' => $buckets];
        }
        return ['year' => $year, 'series' => $result, 'unassigned' => $unassigned];
    }

    // ── interní ──────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function require(int $supplierId, int $id): array
    {
        return $this->series->find($supplierId, $id)
            ?? throw new NumberSeriesException('not_found', 'Číselná řada nenalezena.', 404);
    }

    /** @param array<string,mixed> $row */
    private function isMainLegacy(array $row): bool
    {
        $key = (string) $row['legacy_key'];
        if (($row['scope_type'] === 'cash_register') && str_starts_with($key, 'doc:cash_')) {
            return true;
        }
        return in_array($key, NumberSeriesCatalog::MAIN_LEGACY_KEYS, true);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,array<int,string>> $scopeNames
     * @param array<string,int> $userDefaults
     * @param array<int,true> $documented
     * @return array<string,mixed>
     */
    private function present(int $supplierId, array $row, array $scopeNames, array $userDefaults, array $documented): array
    {
        $d = $this->describe($supplierId, $row);
        $kind = (string) $row['doc_kind'];
        $selectableRow = NumberSeriesKinds::isSelectable($kind)
            && in_array($row['scope_type'], ['company', 'cash_register'], true)
            && ($row['storage'] === 'native' || $this->isMainLegacy($row));
        $userKey = $kind . '|' . ($row['scope_type'] === 'cash_register' ? $row['scope_id'] : 0);
        return [
            'id'                => $row['id'],
            'doc_kind'          => $kind,
            'group'             => NumberSeriesKinds::KINDS[$kind]['group'] ?? 'other',
            'scope_type'        => $row['scope_type'],
            'scope_id'          => $row['scope_id'],
            'scope_name'        => $scopeNames[$row['scope_type']][$row['scope_id']] ?? null,
            'storage'           => $row['storage'],
            'legacy_key'        => $row['legacy_key'],
            'name'              => $row['name'],
            'label'             => $this->label($row, $scopeNames),
            'prefix'            => $d['prefix'],
            'template'          => $d['template'],
            'period'            => $d['period'],
            'next_preview'      => $d['preview'],
            'next_number'       => $d['next_number'],
            'is_default'        => $row['is_default'],
            'is_user_default'   => isset($userDefaults[$userKey]) && $userDefaults[$userKey] === $row['id'],
            'valid_to'          => $row['valid_to'],
            'sort_order'        => $row['sort_order'],
            'selectable'        => $selectableRow,
            'can_edit_template' => $row['storage'] === 'native' && !isset($documented[$row['id']]),
            'can_set_next'      => $d['can_set_next'],
            'can_end'           => $selectableRow && !$row['is_default'],
            'can_delete'        => $row['storage'] === 'native' && !$row['is_default'] && !isset($documented[$row['id']]),
            'edit_target'       => $d['edit_target'],
        ];
    }

    /**
     * Tvar čísla a příští číslo řady (u převedené z dosavadního úložiště).
     *
     * @param array<string,mixed> $row
     * @return array{template:string, prefix:?string, period:?string, preview:?string, next_number:?int,
     *               can_set_next:bool, edit_target:?string}
     */
    private function describe(int $supplierId, array $row): array
    {
        $today = new \DateTimeImmutable('today');
        if ($row['storage'] === 'native') {
            return [
                'template'     => (string) $row['template'],
                'prefix'       => $row['prefix'],
                'period'       => $row['period'],
                'preview'      => $this->allocator->peek($supplierId, $row, $today),
                'next_number'  => $this->allocator->nextCounter($row, $today),
                'can_set_next' => true,
                'edit_target'  => null,
            ];
        }
        $key = (string) $row['legacy_key'];
        $parts = explode(':', $key);
        $none = ['template' => '', 'prefix' => $row['prefix'], 'period' => null, 'preview' => null, 'next_number' => null,
            'can_set_next' => false, 'edit_target' => null];

        if ($parts[0] === 'issued' || $parts[0] === 'client' || $parts[0] === 'revenue_category') {
            $type = $parts[0] === 'issued' ? $parts[1] : $parts[2];
            $clientId = $parts[0] === 'client' ? (int) $parts[1] : 0;
            $categoryId = $parts[0] === 'revenue_category' ? (int) $parts[1] : 0;
            [$template, $period] = $this->issuedTemplate($supplierId, $type, $clientId, $categoryId);
            $peek = null;
            try {
                $peek = $this->varsymbol->peek($supplierId, $type, $today, $clientId, $categoryId);
            } catch (\InvalidArgumentException) {
            }
            return ['template' => $template, 'prefix' => null, 'period' => $period,
                'preview' => $peek['preview'] ?? null, 'next_number' => $peek['next_number'] ?? null,
                'can_set_next' => $peek !== null,
                'edit_target' => match ($parts[0]) { 'client' => 'client', 'revenue_category' => 'revenue_category', default => 'invoice_numbering' }];
        }
        if ($parts[0] === 'purchase') {
            $settings = $this->purchaseSettings($supplierId);
            $all = PurchaseNumberSeries::all($settings);
            $seriesKey = $parts[1] ?? '';
            $template = $all[$seriesKey]['template'] ?? PurchaseNumberSeries::DEFAULT_TEMPLATE;
            $period = $today->format('Ym');
            $counterKey = PurchaseNumberSeries::counterKey($seriesKey, $template, $period);
            $stmt = $this->db->pdo()->prepare('SELECT last_number FROM purchase_invoice_counters WHERE supplier_id = ? AND period = ?');
            $stmt->execute([$supplierId, $counterKey]);
            $next = (int) ($stmt->fetchColumn() ?: 0) + 1;
            $scope = str_contains($template, '{MM}') ? 'month' : (preg_match('/\{(YYYY|YY)\}/', $template) ? 'year' : 'none');
            return ['template' => $template, 'prefix' => null, 'period' => $scope,
                'preview' => PurchaseNumberSeries::render($template, 'PF', $period, $next), 'next_number' => $next,
                'can_set_next' => false, 'edit_target' => 'purchase_numbering'];
        }
        if ($parts[0] === 'doc') {
            $code = $parts[1];
            $register = (int) $parts[2];
            $year = (int) $today->format('Y');
            $stmt = $this->db->pdo()->prepare(
                'SELECT fiscal_year, prefix, number_format, next_number FROM accounting_document_series
                  WHERE supplier_id = ? AND series_code = ? AND register_id = ? ORDER BY (fiscal_year = ?) DESC, fiscal_year DESC LIMIT 1'
            );
            $stmt->execute([$supplierId, $code, $register, $year]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $prefix = $r !== null ? (string) $r['prefix'] : (DocumentSeriesService::DEFAULT_PREFIXES[$code] ?? '');
            $template = ($r['number_format'] ?? null) ?: DocumentSeriesService::DEFAULT_TEMPLATE;
            $next = $r !== null && (int) $r['fiscal_year'] === $year ? (int) $r['next_number'] : 1;
            return ['template' => $template, 'prefix' => $prefix, 'period' => 'year',
                'preview' => DocumentSeriesService::format($prefix, $year, $next, $template), 'next_number' => $next,
                'can_set_next' => $register === 0 || $r !== null, 'edit_target' => 'document_series'];
        }
        if ($parts[0] === 'bank') {
            $prefix = (string) $row['prefix'];
            return ['template' => '{PREFIX}-{MM}', 'prefix' => $prefix, 'period' => 'month',
                'preview' => $prefix . '-' . $today->format('m'), 'next_number' => null,
                'can_set_next' => false, 'edit_target' => 'bank_account'];
        }
        return $none;
    }

    /** Příští číslo řady: nová řada přímo, převedená dosavadní cestou. */
    private function setNextNumber(int $supplierId, array $row, int $next): void
    {
        $today = new \DateTimeImmutable('today');
        if ($row['storage'] === 'native') {
            $this->allocator->setNextNumber($row, $next, $today);
            return;
        }
        $parts = explode(':', (string) $row['legacy_key']);
        try {
            if ($parts[0] === 'issued') {
                $this->varsymbol->setCounter($supplierId, $parts[1], $next, $today);
                return;
            }
            if ($parts[0] === 'client' || $parts[0] === 'revenue_category') {
                $this->varsymbol->setCounter($supplierId, $parts[2], $next, $today,
                    $parts[0] === 'client' ? (int) $parts[1] : 0, $parts[0] === 'revenue_category' ? (int) $parts[1] : 0);
                return;
            }
            if ($parts[0] === 'doc') {
                $this->documentSeries->updateSeries($supplierId, $parts[1], (int) $today->format('Y'), ['next_number' => $next], (int) $parts[2]);
                return;
            }
        } catch (\InvalidArgumentException | ClosingException $e) {
            throw new NumberSeriesException('next_number_invalid', $e->getMessage());
        }
        throw new NumberSeriesException('next_number_unsupported', 'Příští číslo této řady se nastavuje v původním nastavení.');
    }

    /** @return array{0:string, 1:string} [šablona, období] převedené řady vydaných dokladů */
    private function issuedTemplate(int $supplierId, string $type, int $clientId, int $categoryId): array
    {
        $col = $type === VarsymbolGenerator::SIMPLIFIED ? 'simplified_number_format' : "{$type}_number_format";
        $stmt = $this->db->pdo()->prepare("SELECT {$col} AS tpl, invoice_number_period FROM supplier WHERE id = ?");
        $stmt->execute([$supplierId]);
        $sup = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $template = trim((string) ($sup['tpl'] ?? '')) ?: (string) $this->config->get("varsymbol.templates.{$type}", '');
        $period = (string) ($sup['invoice_number_period'] ?? 'month');
        foreach (['clients' => $clientId, 'revenue_categories' => $categoryId] as $table => $scopeId) {
            if ($scopeId <= 0 || $type === VarsymbolGenerator::SIMPLIFIED) {
                continue;
            }
            $stmt = $this->db->pdo()->prepare("SELECT {$col} AS tpl, invoice_number_period FROM {$table} WHERE id = ? AND supplier_id = ?");
            $stmt->execute([$scopeId, $supplierId]);
            $own = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if (trim((string) ($own['tpl'] ?? '')) !== '') {
                $template = trim((string) $own['tpl']);
                $period = (string) ($own['invoice_number_period'] ?? '') ?: $period;
            }
        }
        return [$template, in_array($period, NumberSeriesTemplate::PERIODS, true) ? $period : 'month'];
    }

    /** @return array<string,mixed> */
    private function purchaseSettings(int $supplierId): array
    {
        $cols = array_values(array_filter(PurchaseNumberSeries::supplierColumns(), fn (string $c): bool => $this->db->hasColumn('supplier', $c)));
        $stmt = $this->db->pdo()->prepare('SELECT ' . implode(', ', $cols) . ' FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Šablona nové řady je platná a její čísla se nemohou splést s čísly jiné řady téhož
     * číselného prostoru (vzorek čísla jedné řady nesmí projít vzorem druhé, oběma směry).
     *
     * @param array<string,mixed> $data doc_kind, prefix, template, period
     */
    private function assertTemplate(int $supplierId, array $data, ?int $selfId): void
    {
        $errors = NumberSeriesTemplate::validate((string) $data['doc_kind'], (string) $data['template'], $data['prefix'], (string) $data['period']);
        if ($errors !== []) {
            throw new NumberSeriesException('template_invalid', self::templateMessage($errors[0]));
        }
        $space = NumberSeriesKinds::space((string) $data['doc_kind']);
        $date = new \DateTimeImmutable(self::SAMPLE_DATE);
        $mine = ['template' => (string) $data['template'], 'prefix' => $data['prefix']];
        foreach ($this->series->listForSupplier($supplierId) as $row) {
            if ($row['id'] === $selfId || NumberSeriesKinds::space((string) $row['doc_kind']) !== $space) {
                continue;
            }
            $other = $this->describe($supplierId, $row);
            if ($other['template'] === '' || !preg_match('/\{C+\}/', $other['template'])) {
                continue;
            }
            $theirs = ['template' => $other['template'], 'prefix' => $other['prefix']];
            if (self::matches($mine, $theirs, $date) || self::matches($theirs, $mine, $date)) {
                throw new NumberSeriesException(
                    'template_overlap',
                    'Čísla řady by se mohla plést s řadou „' . $this->label($row, $this->scopeNames($supplierId)) . '" (' . $other['template'] . '). Zvolte jiný prefix nebo tvar čísla.',
                );
            }
        }
    }

    /** @param array{template:string, prefix:?string} $a @param array{template:string, prefix:?string} $b */
    private static function matches(array $a, array $b, \DateTimeImmutable $date): bool
    {
        $regex = NumberSeriesTemplate::counterRegex($b['template'], $b['prefix'], (int) $date->format('Y'), (int) $date->format('n'));
        foreach ([7, 1234] as $counter) {
            if (preg_match($regex, NumberSeriesTemplate::render($a['template'], $a['prefix'], $date, $counter)) === 1) {
                return true;
            }
        }
        return false;
    }

    private static function templateMessage(string $code): string
    {
        return match ($code) {
            'template_length'      => 'Tvar čísla musí mít 1 až ' . NumberSeriesTemplate::MAX_LENGTH . ' znaků.',
            'period_invalid'       => 'Období řady musí být rok, měsíc nebo bez resetu.',
            'template_placeholder' => 'Tvar čísla obsahuje neznámý zástupný symbol. Dovolené: {PREFIX} {YYYY} {YY} {MM} {C…} (u přijatých dokladů i {PP}).',
            'template_whitespace'  => 'Tvar čísla nesmí obsahovat mezery.',
            'template_counter'     => 'Tvar čísla musí obsahovat právě jeden čítač {C…}, např. {CCCC}.',
            'prefix_invalid'       => 'Prefix řady musí mít 1 až 10 znaků A-Z nebo 0-9.',
            'template_period'      => 'Tvar čísla musí obsahovat rok (u roční řady) nebo rok i měsíc (u měsíční řady), jinak by se čísla po resetu opakovala.',
            default                => 'Neplatný tvar čísla.',
        };
    }

    private static function name(mixed $value): string
    {
        $name = trim((string) $value);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new NumberSeriesException('name_invalid', 'Název řady musí mít 1 až 100 znaků.');
        }
        return $name;
    }

    private static function prefix(mixed $value): ?string
    {
        $prefix = strtoupper(trim((string) $value));
        return $prefix === '' ? null : $prefix;
    }

    private static function validTo(mixed $value): ?string
    {
        $v = trim((string) $value);
        if ($v === '') {
            return null;
        }
        if (!IsoDate::isValid($v)) {
            throw new NumberSeriesException('valid_to_invalid', 'Datum ukončení řady není platné datum.');
        }
        return $v;
    }

    private function isDoubleEntry(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return $stmt->fetchColumn() === 'double_entry';
    }

    private function registerBelongs(int $supplierId, int $registerId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM cash_registers WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$registerId, $supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    /** @param array<string,mixed> $row */
    private function hasDocuments(int $supplierId, array $row): bool
    {
        $space = NumberSeriesKinds::space((string) $row['doc_kind']);
        if ($space === null) {
            return false;
        }
        $s = NumberSeriesKinds::SPACES[$space];
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM {$s['table']} WHERE supplier_id = ? AND number_series_id = ? AND {$s['number']} IS NOT NULL LIMIT 1"
        );
        $stmt->execute([$supplierId, (int) $row['id']]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return array<int,true> nové řady, ze kterých už vzniklo číslo */
    private function seriesWithDocuments(int $supplierId): array
    {
        $out = [];
        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT c.series_id FROM number_series_counters c JOIN number_series n ON n.id = c.series_id
              WHERE n.supplier_id = ? AND c.last_number > 0"
        );
        $stmt->execute([$supplierId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $out[(int) $id] = true;
        }
        return $out;
    }

    /** @return array<string,array<int,string>> scope_type => id => název */
    private function scopeNames(int $supplierId): array
    {
        $out = ['cash_register' => [], 'client' => [], 'revenue_category' => [], 'other_item_kind' => [], 'bank_account' => []];
        $queries = [
            'cash_register'    => 'SELECT id, name FROM cash_registers WHERE supplier_id = ?',
            'other_item_kind'  => "SELECT id, COALESCE(name, '') FROM other_item_kinds WHERE supplier_id = ?",
            'bank_account'     => "SELECT id, COALESCE(NULLIF(label, ''), account_number, '') FROM supplier_bank_accounts WHERE supplier_id = ?",
        ];
        foreach ($queries as $type => $sql) {
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute([$supplierId]);
            foreach ($stmt->fetchAll(PDO::FETCH_NUM) ?: [] as [$id, $name]) {
                $out[$type][(int) $id] = (string) $name;
            }
        }
        // Klienti a kategorie jen ti, kdo mají vlastní řadu (klientů může být hodně).
        foreach (['client' => ['clients', 'company_name'], 'revenue_category' => ['revenue_categories', 'label']] as $type => [$table, $col]) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT t.id, t.{$col} FROM {$table} t JOIN number_series n
                    ON n.supplier_id = t.supplier_id AND n.scope_type = ? AND n.scope_id = t.id
                  WHERE t.supplier_id = ?"
            );
            $stmt->execute([$type, $supplierId]);
            foreach ($stmt->fetchAll(PDO::FETCH_NUM) ?: [] as [$id, $name]) {
                $out[$type][(int) $id] = (string) $name;
            }
        }
        return $out;
    }

    /**
     * Popisek řady: název, je-li vyplněný, jinak prázdný (UI ho složí z druhu a rozsahu
     * přes překlad) — doplněný o název pokladny, klienta, kategorie nebo účtu.
     *
     * @param array<string,mixed> $row
     * @param array<string,array<int,string>> $scopeNames
     */
    private function label(array $row, array $scopeNames): string
    {
        $name = trim((string) $row['name']);
        if ($name !== '') {
            return $name;
        }
        return $scopeNames[$row['scope_type']][$row['scope_id']] ?? '';
    }

    /** @param array<string,mixed> $payload */
    private function audit(string $action, ?int $userId, int $supplierId, int $id, array $payload): void
    {
        $this->activity->log($action, $userId, 'number_series', $id, $payload, null, null, $supplierId);
    }
}
