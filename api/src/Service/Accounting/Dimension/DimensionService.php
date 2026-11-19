<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Repository\CostCenterRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\DimensionDefaultRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use MyInvoice\Service\Accounting\DocumentRepostService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\Allocation\JointAllocationUseGuard;
use MyInvoice\Service\Accounting\Allocation\JointAllocationException;
use PDO;

/**
 * Firma → Dimenze: číselník typů a stromů hodnot, dimenze dokladů a řádků deníku.
 *
 * Středisko je dimenze navázaná na číselník `cost_centers`: nová hodnota typu
 * Středisko si středisko se stejným kódem najde nebo založí, uzavření hodnoty
 * středisko deaktivuje. Textový `cost_center` na řádcích (mzdy, ruční zápisy) tak
 * zůstává platný a sestavy po středisku ho započítají ({@see DimensionFilter}).
 */
final class DimensionService
{
    /** Výchozí typy: kód => [název, druh, globální je-li firma ve skupině]. */
    public const DEFAULT_TYPES = [
        'stredisko' => ['Středisko', 'cost_center', false],
        'projekt' => ['Projekt', 'project', true],
        'vozidlo' => ['Vozidlo', 'vehicle', false],
        'lokalita' => ['Lokalita', 'location', true],
        'obchodni_pripad' => ['Obchodní případ', 'deal', false],
    ];

    /** Typ dokladu => [tabulka, zdroj zápisu v deníku]. */
    private const DOCUMENTS = [
        'purchase_invoice' => ['purchase_invoices', 'purchase_invoice'],
        'invoice' => ['invoices', 'invoice'],
        'cash_document' => ['cash_documents', 'cash'],
        'bank_transaction' => [null, 'bank'],
        'journal_template' => ['journal_entry_templates', null],
        'recurring_template' => ['recurring_invoice_templates', null],
        'other_item' => ['other_items', 'other_item'],
        'asset' => ['assets', 'asset'],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly DimensionRepository $repo,
        private readonly DimensionAssignmentRepository $assignments,
        private readonly CostCenterRepository $costCenters,
        private readonly PostingService $posting,
        private readonly DimensionDefaultRepository $defaults,
        private readonly DimensionDefaults $defaultsResolver,
    ) {}

    /** @return array<string,mixed> */
    public function overview(int $supplierId): array
    {
        $groupId = $this->repo->groupIdOf($supplierId);
        $group = $groupId !== null ? $this->repo->findGroup($groupId) : null;
        return [
            'enabled' => $this->repo->enabled($supplierId),
            'group' => $group === null ? null : $group + ['members' => $this->repo->groupMembers($groupId)],
            'types' => $this->repo->listTypes($supplierId),
            'values' => $this->repo->listValues($supplierId),
        ];
    }

    public function enabled(int $supplierId): bool
    {
        return $this->repo->enabled($supplierId);
    }

    /** Úklid dimenzí smazaného dokladu (vazba je polymorfní, bez FK na doklad). */
    public function forgetDocument(int $supplierId, string $docType, int $docId): void
    {
        $this->assignments->deleteDocument($supplierId, $docType, $docId);
    }

    public function setEnabled(int $supplierId, bool $enabled): void
    {
        $this->repo->setEnabled($supplierId, $enabled);
    }

    /**
     * Založí výchozí typy, které firma (nebo její skupina) ještě nemá. Projekt
     * a Lokalita jsou u firmy ve skupině globální, jinak firemní.
     *
     * @param list<string>|null $only kódy výchozích typů (null = všechny)
     * @return array<string,int> druh => id typu
     */
    public function ensureDefaultTypes(int $supplierId, ?array $only = null): array
    {
        $inGroup = $this->repo->groupIdOf($supplierId) !== null;
        $out = [];
        $order = 0;
        foreach (self::DEFAULT_TYPES as $code => [$name, $kind, $globalInGroup]) {
            $order += 10;
            if ($only !== null && !in_array($code, $only, true)) {
                continue;
            }
            $global = $globalInGroup && $inGroup;
            $existing = $this->repo->findTypeByKind($supplierId, $kind, $global);
            if ($existing === null && !$global) {
                // Firma bez skupiny může mít projekt i lokalitu jako firemní typ.
                $existing = $this->repo->findTypeByKind($supplierId, $kind, false);
            }
            $out[$kind] = $existing !== null
                ? (int) $existing['id']
                : $this->repo->createType($supplierId, $global, [
                    'code' => $code, 'name' => $name, 'kind' => $kind, 'sort_order' => $order,
                ]);
        }
        return $out;
    }

    // ── typy ─────────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $body */
    public function createType(int $supplierId, array $body): array
    {
        $code = strtolower(trim((string) ($body['code'] ?? '')));
        $name = trim((string) ($body['name'] ?? ''));
        $kind = (string) ($body['kind'] ?? 'custom');
        $global = ($body['level'] ?? 'company') === 'global';
        if (preg_match('/^[a-z0-9_-]{1,30}$/', $code) !== 1) {
            throw new DimensionException('validation_failed', 'Kód typu: povolené znaky a-z, 0-9, _ a - (nejvýš 30).');
        }
        if ($name === '' || mb_strlen($name) > 100) {
            throw new DimensionException('validation_failed', 'Název typu musí mít 1–100 znaků.');
        }
        if (!in_array($kind, DimensionRepository::KINDS, true)) {
            throw new DimensionException('validation_failed', 'Neznámý druh dimenze.');
        }
        if ($global && $this->repo->groupIdOf($supplierId) === null) {
            throw new DimensionException('no_supplier_group', 'Globální typ vyžaduje, aby firma patřila do skupiny firem.', 409);
        }
        foreach ($this->repo->listTypes($supplierId) as $t) {
            if ($t['code'] === $code && ($t['level'] === 'global') === $global) {
                throw new DimensionException('duplicate_code', "Typ s kódem '{$code}' už existuje.", 409);
            }
        }
        $id = $this->repo->createType($supplierId, $global, [
            'code' => $code,
            'name' => $name,
            'kind' => $kind,
            'show_on_documents' => (bool) ($body['show_on_documents'] ?? true),
            'sort_order' => (int) ($body['sort_order'] ?? 100),
        ]);
        return (array) $this->repo->findType($supplierId, $id);
    }

    /** @param array<string,mixed> $body */
    public function updateType(int $supplierId, int $typeId, array $body, ?int $actor = null, string $source = 'service'): array
    {
        $type = $this->requireType($supplierId, $typeId);
        $changes = array_intersect_key($body, array_flip(['name', 'is_active', 'show_on_documents', 'sort_order']));
        // Účtotvorná dimenze (migrace 1952): nejvýš jeden typ na firmu, maska jen třídy 5 a 6.
        $changes += (new DimensionAccountMapService($this->db))->typeChanges($supplierId, $type, $body);
        $changes += self::approvalChanges($body);
        if (array_key_exists('name', $changes)) {
            $changes['name'] = trim((string) $changes['name']);
            if ($changes['name'] === '' || mb_strlen($changes['name']) > 100) {
                throw new DimensionException('validation_failed', 'Název typu musí mít 1–100 znaků.');
            }
        }
        $this->repo->updateType($supplierId, $typeId, $changes, $actor, $source);
        return (array) $this->repo->findType($supplierId, $typeId);
    }

    /**
     * Schvalování přijatých dokladů odpovědnou osobou hodnoty (F6, migrace 1959):
     * `requires_approval` a limit `approval_threshold` (Kč bez DPH, null/0 = vždy).
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private static function approvalChanges(array $body): array
    {
        $out = [];
        if (array_key_exists('requires_approval', $body)) {
            $out['requires_approval'] = (bool) $body['requires_approval'];
        }
        if (array_key_exists('approval_threshold', $body)) {
            $raw = $body['approval_threshold'];
            if ($raw === null || $raw === '') {
                $out['approval_threshold'] = null;
            } elseif (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 9999999999999.99) {
                throw new DimensionException('validation_failed', 'Limit schvalování musí být nezáporná částka v Kč.');
            } else {
                $out['approval_threshold'] = round((float) $raw, 2);
            }
        }
        return $out;
    }

    /** @return array{deleted:bool} */
    public function deleteType(int $supplierId, int $typeId): array
    {
        $this->requireType($supplierId, $typeId);
        if ($this->repo->typeInUse($typeId)) {
            $this->repo->updateType($supplierId, $typeId, ['is_active' => false]);
            return ['deleted' => false];
        }
        return ['deleted' => $this->repo->deleteType($supplierId, $typeId)];
    }

    // ── hodnoty ──────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $body */
    public function createValue(int $supplierId, int $typeId, array $body, ?int $actor = null, string $source = 'service'): array
    {
        $type = $this->requireType($supplierId, $typeId);
        $code = trim((string) ($body['code'] ?? ''));
        if ($code === '' || mb_strlen($code) > 50 || preg_match('/[\x00-\x1F\x7F]/u', $code) === 1) {
            throw new DimensionException('validation_failed', 'Kód hodnoty musí mít 1–50 znaků bez řídicích znaků.');
        }
        if ($this->repo->findValueByCode($typeId, $code) !== null) {
            throw new DimensionException('duplicate_code', "Hodnota s kódem '{$code}' už v typu existuje.", 409);
        }
        $data = $this->valueData($supplierId, $type, $body, null) + ['code' => $code];
        if (!isset($data['name'])) {
            throw new DimensionException('validation_failed', 'Název hodnoty je povinný.');
        }
        if ($type['kind'] === 'cost_center' && $type['level'] === 'company' && !array_key_exists('cost_center_id', $body)) {
            $data['cost_center_id'] = $this->costCenterFor($supplierId, $code, (string) $data['name']);
        }
        $id = $this->repo->createValue($type, $data, $actor, $source);
        return (array) $this->repo->findValue($supplierId, $id);
    }

    /** @param array<string,mixed> $body */
    public function updateValue(int $supplierId, int $valueId, array $body, ?int $actor = null, string $source = 'service'): array
    {
        $value = $this->requireValue($supplierId, $valueId);
        $type = $this->requireType($supplierId, $value['type_id']);
        $changes = $this->valueData($supplierId, $type, $body, $value);
        $this->repo->updateValue($supplierId, $valueId, $changes, $actor, $source);
        if (array_key_exists('is_active', $changes) && $value['cost_center_id'] !== null && $value['supplier_id'] !== null) {
            $this->costCenters->update($supplierId, $value['cost_center_id'], ['is_active' => (bool) $changes['is_active']]);
        }
        return (array) $this->repo->findValue($supplierId, $valueId);
    }

    /** @return array{deleted:bool} */
    public function deleteValue(int $supplierId, int $valueId, ?int $actor = null, string $source = 'service'): array
    {
        $this->requireValue($supplierId, $valueId);
        if ($this->repo->valueInUse($valueId)) {
            $this->repo->updateValue($supplierId, $valueId, ['is_active' => false], $actor, $source);
            return ['deleted' => false];
        }
        return ['deleted' => $this->repo->deleteValue($supplierId, $valueId, $actor, $source)];
    }

    /**
     * Uživatelé firmy, které lze vybrat jako odpovědnou osobu hodnoty: přiřazení k firmě
     * a superadmin, který má přístup ke všem firmám bez přiřazení.
     *
     * Odpovědná osoba je zároveň schvalovatel přijatých dokladů (F6), proto i e-mail.
     *
     * @return list<array{id:int,name:string,email:string}>
     */
    public function responsibleCandidates(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT u.id, COALESCE(NULLIF(u.name, ''), u.email) AS name, u.email
               FROM users u
          LEFT JOIN roles r ON r.id = u.role_id
              WHERE u.is_active = 1
                AND (EXISTS (SELECT 1 FROM user_suppliers us WHERE us.user_id = u.id AND us.supplier_id = ?)
                     OR (r.role_type = 'superadmin' AND r.system_key = 'superadmin'))
              ORDER BY name"
        );
        $stmt->execute([$supplierId]);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'email' => (string) ($r['email'] ?? ''),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // ── přiřazení ────────────────────────────────────────────────────────────

    /**
     * Ověří mapu typ => hodnota (nebo seznam id hodnot) proti číselníku firmy.
     * Prázdná hodnota typ z mapy vypustí. Neaktivní hodnotu odmítne jen u nové
     * volby — uzavřený projekt na starém dokladu zůstat smí.
     *
     * @param array<int|string,mixed> $raw
     * @param array<int,int> $current dosavadní hodnoty (pro povolení uzavřených)
     * @return array<int,int> typ => hodnota
     */
    public function normalize(int $supplierId, array $raw, array $current = []): array
    {
        $pairs = [];
        foreach ($raw as $key => $valueId) {
            if ($valueId === null || $valueId === '' || (int) $valueId <= 0) {
                continue;
            }
            $pairs[] = [array_is_list($raw) ? null : (int) $key, (int) $valueId];
        }
        $values = $this->repo->valuesByIds($supplierId, array_column($pairs, 1));
        $types = [];
        foreach ($this->repo->listTypes($supplierId) as $t) {
            $types[$t['id']] = $t;
        }
        $out = [];
        foreach ($pairs as [$typeId, $valueId]) {
            $value = $values[$valueId] ?? null;
            if ($value === null || ($typeId !== null && $value['type_id'] !== $typeId) || !isset($types[$value['type_id']])) {
                throw new DimensionException('invalid_dimension', 'Neplatná hodnota dimenze #' . $valueId . '.', 400);
            }
            if (!$value['is_active'] && !in_array($valueId, $current, true)) {
                throw new DimensionException('dimension_closed', 'Hodnota dimenze „' . $value['name'] . '" je uzavřená.', 422);
            }
            if (isset($out[$value['type_id']])) {
                throw new DimensionException('invalid_dimension', 'Za jeden typ dimenze lze vybrat jen jednu hodnotu.', 400);
            }
            $out[$value['type_id']] = $valueId;
        }
        ksort($out);
        return $out;
    }

    /**
     * Ověří rozpad řádku nebo dokladu mezi víc hodnot typu. Vstup je typ => seznam
     * `{value_id, share}` (nebo typ => hodnota => podíl); podíl 0–1, aspoň dvě hodnoty,
     * součet 1 (tolerance miliontiny kvůli převodu částky na podíl). Prázdný typ se vypustí.
     *
     * @param array<int|string,mixed> $raw
     * @param list<int> $current dosavadní hodnoty (smí zůstat i uzavřené)
     * @return array<int,array<int,float>> typ => hodnota => podíl
     */
    public function normalizeSplits(int $supplierId, array $raw, array $current = []): array
    {
        $types = [];
        foreach ($this->repo->listTypes($supplierId) as $t) {
            $types[$t['id']] = $t;
        }
        $out = [];
        foreach ($raw as $typeId => $entries) {
            $typeId = (int) $typeId;
            if (!is_array($entries) || $entries === []) {
                continue;
            }
            if (!isset($types[$typeId])) {
                throw new DimensionException('invalid_dimension', 'Neplatný typ dimenze #' . $typeId . ' v rozpadu.', 400);
            }
            $shares = [];
            foreach ($entries as $key => $entry) {
                [$valueId, $share] = is_array($entry)
                    ? [(int) ($entry['value_id'] ?? 0), (float) ($entry['share'] ?? 0)]
                    : [(int) $key, (float) $entry];
                if ($valueId <= 0) {
                    continue;
                }
                if (isset($shares[$valueId])) {
                    throw new DimensionException('invalid_split', 'Hodnota se v rozpadu opakuje.', 400);
                }
                if ($share <= 0 || $share > 1) {
                    throw new DimensionException('invalid_split', 'Podíl v rozpadu musí být větší než 0 % a nejvýš 100 %.', 422);
                }
                $shares[$valueId] = round($share, 10);
            }
            if ($shares === []) {
                continue;
            }
            $values = $this->repo->valuesByIds($supplierId, array_keys($shares));
            foreach (array_keys($shares) as $valueId) {
                $value = $values[$valueId] ?? null;
                if ($value === null || $value['type_id'] !== $typeId) {
                    throw new DimensionException('invalid_dimension', 'Neplatná hodnota dimenze #' . $valueId . ' v rozpadu.', 400);
                }
                if (!$value['is_active'] && !in_array($valueId, $current, true)) {
                    throw new DimensionException('dimension_closed', 'Hodnota dimenze „' . $value['name'] . '" je uzavřená.', 422);
                }
            }
            if (count($shares) < 2) {
                throw new DimensionException('invalid_split', 'Rozpad typu „' . $types[$typeId]['name'] . '" potřebuje aspoň dvě hodnoty.', 422);
            }
            if (abs(array_sum($shares) - 1.0) > 0.000001) {
                throw new DimensionException(
                    'invalid_split',
                    'Rozpad typu „' . $types[$typeId]['name'] . '" musí dát dohromady 100 % (je '
                        . number_format(array_sum($shares) * 100, 2, ',', ' ') . ' %).',
                    422,
                );
            }
            ksort($shares);
            $out[$typeId] = $shares;
        }
        ksort($out);
        return $out;
    }

    /**
     * Rozpad pro API: typ => seznam `{value_id, share}` (JSON objekt podle typu).
     *
     * @param array<int,array<int,float>> $splits typ => hodnota => podíl
     * @return array<int,list<array{value_id:int, share:float}>>
     */
    public static function splitsForApi(array $splits): array
    {
        $out = [];
        foreach ($splits as $typeId => $shares) {
            foreach ($shares as $valueId => $share) {
                $out[(int) $typeId][] = ['value_id' => (int) $valueId, 'share' => (float) $share];
            }
        }
        return $out;
    }

    /**
     * `splits` = pořadí položky (0 = hlavička) => typ => seznam `{value_id, share}`.
     *
     * @return array{header:array<int,int>, items:array<int,array<int,int>>, splits:array<int,array<int,list<array{value_id:int, share:float}>>>}
     */
    public function documentDimensions(int $supplierId, string $docType, int $docId): array
    {
        $this->requireDocument($supplierId, $docType, $docId);
        return $this->assignments->documentDimensions($supplierId, $docType, $docId)
            + ['splits' => array_map([self::class, 'splitsForApi'], $this->assignments->documentSplits($supplierId, $docType, $docId))];
    }

    /**
     * Uloží dimenze dokladu (hlavička + položky podle pořadí od 1) a promítne je do
     * už zaúčtovaných řádků dokladu.
     *
     * Změna dimenzí je možná před uzavřením knih, i při zámku účetního data.
     * Uzavřené knihy uchovávají dimenzované zůstatky závěrky a počátečních stavů.
     * Bezezměnové uložení zůstává povolené. Rozdělení řádků či změna analytického
     * účtu vyžadují přeúčtování; v zamčeném období se takové uložení odmítne.
     * `$forRepost` = volá přeúčtování, které řádky hned potom zapíše znovu a rozdělí.
     *
     * `$splits` = rozpad hlavičky (0) a položek (pořadí od 1) mezi víc hodnot typu,
     * pořadí => typ => seznam `{value_id, share}`; null = ponechat dosavadní. Typ
     * s rozpadem nemá jedinou hodnotu a naopak — novější volba vyhrává.
     *
     * @param array<int|string,mixed> $header
     * @param array<int|string,mixed>|null $items pořadí položky => mapa typ => hodnota; null = ponechat
     * @param array<int|string,mixed>|null $splits
     * @return array{header:array<int,int>, items:array<int,array<int,int>>, splits:array<int,array<int,list<array{value_id:int, share:float}>>>, restamp:array{lines:int,needs_repost:bool,account_change:bool,locked:bool}}
     */
    public function saveDocument(int $supplierId, string $docType, int $docId, array $header, ?array $items, bool $forRepost = false, ?array $splits = null): array
    {
        $this->requireEnabled($supplierId);
        return $this->atomically(fn (): array => self::refuseLockedRepost(
            $this->applyDocument($supplierId, $docType, $docId, $header, $items, $splits),
            $forRepost,
        ));
    }

    /**
     * Změní jen vybrané typy dimenzí hlavičky dokladu, ostatní ponechá. Hromadná úprava
     * ze seznamu dokladů: dosavadní hlavička se čte až pod zámky uložení, takže souběžná
     * změna jiného typu se nepřepíše. Odebraný typ zmizí z hlavičky i z jejího rozpadu;
     * dimenze položek zůstávají. Pravidla zámků i promítnutí do deníku jsou tatáž jako
     * u {@see saveDocument()}.
     *
     * @param array<int,int> $set typ => nová hodnota
     * @param list<int> $remove typy k odebrání
     * @return array{header:array<int,int>, items:array<int,array<int,int>>, splits:array<int,array<int,list<array{value_id:int, share:float}>>>, restamp:array{lines:int,needs_repost:bool,account_change:bool,locked:bool}, changed:bool}
     */
    public function patchDocumentHeader(int $supplierId, string $docType, int $docId, array $set, array $remove): array
    {
        $this->requireEnabled($supplierId);
        $patch = static function (array $current) use ($set, $remove): array {
            $header = array_replace($current, $set);
            foreach ($remove as $typeId) {
                unset($header[$typeId]);
            }
            return $header;
        };
        return $this->atomically(fn (): array => self::refuseLockedRepost(
            $this->applyDocument($supplierId, $docType, $docId, $patch, null, null, $remove),
            false,
        ));
    }

    /**
     * @template T of array{restamp:array{needs_repost:bool,account_change?:bool,locked:bool}}
     * @param T $result
     * @return T
     */
    private static function refuseLockedRepost(array $result, bool $forRepost): array
    {
        if (!$forRepost && $result['restamp']['needs_repost'] && $result['restamp']['locked']) {
            throw !empty($result['restamp']['account_change'])
                ? new DimensionException('account_change_in_locked_period', self::ACCOUNT_CHANGE_LOCKED_MESSAGE, 409)
                : new DimensionException('split_in_locked_period', self::SPLIT_LOCKED_MESSAGE, 409);
        }
        return $result;
    }

    public const SPLIT_LOCKED_MESSAGE = 'Položky dokladu mají různé dimenze, ale zaúčtovaný řádek je jen jeden a zápis '
        . 'leží v uzavřeném nebo zamčeném období — rozdělit ho podle položek by změnilo částky řádků, a to jde jen '
        . 'přeúčtováním. Dejte položkám stejnou dimenzi (nebo ji zadejte jen v hlavičce dokladu), případně upravte '
        . 'dimenze přímo na řádcích zápisu v účetním deníku.';

    public const ACCOUNT_CHANGE_LOCKED_MESSAGE = 'Změna dimenze by přesunula zaúčtovaný řádek na jiný analytický účet '
        . '(účtotvorná dimenze), ale zápis leží v uzavřeném nebo zamčeném období — změnit účet jde jen přeúčtováním. '
        . 'Ponechte dosavadní hodnotu účtotvorné dimenze, nebo doklad stornujte a zaúčtujte znovu.';

    public const ACCOUNT_CHANGE_MESSAGE = 'Změna dimenze by přesunula řádek na jiný analytický účet (účtotvorná dimenze) — '
        . 'účet zaúčtovaného řádku se mění jen přeúčtováním. Přeúčtujte doklad, nebo ponechte dosavadní hodnotu.';

    /**
     * Náhled uložení dimenzí dokladu: co by po uložení neslo každý řádek jeho živých
     * zápisů. Počítá se TOUTÉŽ cestou jako {@see saveDocument()} (zápis + rollback),
     * takže se náhled s výsledkem nemůže rozejít.
     *
     * @param array<int|string,mixed> $header
     * @param array<int|string,mixed>|null $items
     * @param array<int|string,mixed>|null $splits
     * @return array{header:array<int,int>, items:array<int,array<int,int>>,
     *               restamp:array{lines:int,needs_repost:bool,account_change:bool,locked:bool}, refused:bool,
     *               lines:list<array{id:int, entry_id:int, account_code:?string, account_name:?string, side:string, amount:float, is_red_storno:bool, dimensions:array<int,int>}>}
     */
    public function previewDocument(int $supplierId, string $docType, int $docId, array $header, ?array $items, ?array $splits = null): array
    {
        $this->requireEnabled($supplierId);
        return $this->atomically(function () use ($supplierId, $docType, $docId, $header, $items, $splits): array {
            $result = $this->applyDocument($supplierId, $docType, $docId, $header, $items, $splits);
            $result['refused'] = $result['restamp']['needs_repost'] && $result['restamp']['locked'];
            // Náhled ukáže i zápisy, které dimenze dokladu přebírají (úhrady, zápočty, karty).
            $result['lines'] = [];
            $sources = $this->postingSources($supplierId, $docType, $docId);
            foreach ($this->cascadeSources($supplierId, $docType, $docId) as [$sourceType, $sourceId]) {
                $sources[] = [$sourceType, $sourceId];
            }
            foreach ($sources as [$sourceType, $sourceId]) {
                array_push($result['lines'], ...$this->postedLines($supplierId, $sourceType, $sourceId));
            }
            return $result;
        }, true);
    }

    /**
     * `$header` jako closure dostane dosavadní hlavičku (přečtenou pod zámky) a vrátí
     * novou. `$dropHeaderSplitTypes` = typy, kterým se při ponechaném rozpadu
     * (`$splits` null) zahodí rozpad hlavičky.
     *
     * `$items` jako closure dostane dosavadní dimenze položek a pořadí všech položek
     * dokladu a vrátí úplnou novou mapu. `$dropItemSplitTypes` = typy, kterým se při
     * ponechaném rozpadu zahodí rozpad položek. `$allowIds` = další hodnoty, které smí
     * projít, i když už nejsou aktivní (vrácení hromadné akce do původního stavu).
     *
     * @param array<int|string,mixed>|\Closure(array<int,int>):array<int|string,mixed> $header
     * @param array<int|string,mixed>|\Closure(array<int,array<int,int>>, list<int>):array<int,array<int,int>>|null $items
     * @param array<int|string,mixed>|null $splits
     * @param list<int> $dropHeaderSplitTypes
     * @param list<int> $dropItemSplitTypes
     * @param list<int> $allowIds
     * @return array{header:array<int,int>, items:array<int,array<int,int>>, splits:array<int,array<int,list<array{value_id:int, share:float}>>>, restamp:array{lines:int,needs_repost:bool,account_change:bool,locked:bool}, changed:bool}
     */
    private function applyDocument(int $supplierId, string $docType, int $docId, array|\Closure $header, array|\Closure|null $items, ?array $splits = null, array $dropHeaderSplitTypes = [], array $dropItemSplitTypes = [], array $allowIds = []): array
    {
        $guard = new DimensionPeriodGuard($this->db);
        (new \MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard($this->db))->lockClaims($supplierId);
        (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->acquire($supplierId);
        $context = (new DocumentDimensionContext($this->db))->lockCurrent($supplierId, $docType, $docId);
        $sources = $this->postingSources($supplierId, $docType, $docId, true);
        $cascade = $this->cascadeSources($supplierId, $docType, $docId, true);
        $lockedEntries = $guard->lockSources($supplierId, [...$sources, ...$cascade]);
        $current = $this->assignments->documentDimensions($supplierId, $docType, $docId, true);
        $currentSplits = $this->assignments->documentSplits($supplierId, $docType, $docId, true);
        $currentIds = array_values($current['header']);
        foreach ($current['items'] as $dims) {
            array_push($currentIds, ...array_values($dims));
        }
        foreach ($currentSplits as $byType) {
            foreach ($byType as $shares) {
                array_push($currentIds, ...array_keys($shares));
            }
        }
        array_push($currentIds, ...$allowIds);
        if ($header instanceof \Closure) {
            $header = $header($current['header']);
        }
        if ($items instanceof \Closure) {
            $items = $items($current['items'], array_keys($context['items']));
        }
        $normHeader = $this->normalize($supplierId, $header, $currentIds);
        $normItems = $items === null ? $current['items'] : [];
        foreach ($items ?? [] as $itemNo => $dims) {
            if ((int) $itemNo <= 0 || !is_array($dims)) {
                continue;
            }
            $norm = $this->normalize($supplierId, $dims, $currentIds);
            if ($norm !== []) {
                $normItems[(int) $itemNo] = $norm;
            }
        }
        if ($splits === null) {
            // Rozpad se ponechá, jen typ, kterému teď volba dala jedinou hodnotu, ho ztratí.
            $normSplits = $currentSplits;
            foreach ($normSplits as $itemNo => $byType) {
                $single = $itemNo === 0
                    ? $normHeader + array_fill_keys($dropHeaderSplitTypes, 0)
                    : ($normItems[$itemNo] ?? []) + array_fill_keys($dropItemSplitTypes, 0);
                $normSplits[$itemNo] = array_diff_key($byType, $single);
            }
        } else {
            $normSplits = [];
            foreach ($splits as $itemNo => $byType) {
                if ((int) $itemNo < 0 || !is_array($byType)) {
                    continue;
                }
                $norm = $this->normalizeSplits($supplierId, $byType, $currentIds);
                if ($norm !== []) {
                    $normSplits[(int) $itemNo] = $norm;
                }
            }
            foreach ($normSplits as $itemNo => $byType) {
                if ($itemNo === 0) {
                    $normHeader = array_diff_key($normHeader, $byType);
                } elseif (isset($normItems[$itemNo])) {
                    $normItems[$itemNo] = array_diff_key($normItems[$itemNo], $byType);
                }
            }
        }
        $normSplits = array_filter($normSplits, static fn (array $byType): bool => $byType !== []);
        ksort($normSplits);
        $itemNos = array_keys($context['items']);
        $prospective = DocumentDimensionContext::identify(self::documentAssignmentLines($normHeader, $normItems, $normSplits, $itemNos), $context, $normHeader, $normItems, $normSplits);
        $previous = DocumentDimensionContext::identify(self::documentAssignmentLines($current['header'], $current['items'], $currentSplits, $itemNos), $context, $current['header'], $current['items'], $currentSplits);
        $sourceKeys = array_fill_keys(array_map(static fn (array $source): string => $source[0] . ':' . $source[1], $sources), true);
        $dates = [];
        foreach ($lockedEntries as $entry) {
            if ($entry['reversed_by'] === null && isset($sourceKeys[$entry['source_type'] . ':' . $entry['source_id']])) $dates[(string) $entry['entry_date']] = (string) $entry['entry_date'];
        }
        if ($dates === []) $dates = [$context['date']];
        $changed = $normHeader != $current['header'] || $normItems != $current['items'] || $normSplits != $currentSplits;
        try {
            if ($changed) {
                (new \MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard($this->db))->assertSourcesMutable($supplierId, [...$sources, ...$cascade]);
                (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->assertSourcesMutable($supplierId, [...$sources, ...$cascade]);
                (new JointAllocationUseGuard($this->db))->assertSourcesMutable($supplierId, [...$sources, ...$cascade]);
                foreach ($dates as $date) (new SystemDimensionWritePolicy($this->db))->assertDocumentAssignments($supplierId, $prospective, $date, $previous);
            }
        } catch (PostingException|JointAllocationException $e) {
            throw new DimensionException($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        $ref = 'document:' . $docType . ':' . $docId . ':';
        $itemOrigins = [];
        foreach ($normItems as $itemNo => $dims) $itemOrigins[$itemNo] = self::explicitOrigins($dims, $ref . $itemNo);
        $splitOrigins = [];
        foreach ($normSplits as $itemNo => $byType) {
            foreach ($byType as $typeId => $values) $splitOrigins[$itemNo][$typeId] = self::explicitOrigins($values, $ref . $itemNo);
        }
        $this->assignments->replaceDocumentDimensions($supplierId, $docType, $docId, $normHeader, $normItems,
            self::explicitOrigins($normHeader, $ref . '0'), $itemOrigins);
        if ($normSplits !== [] || $currentSplits !== []) {
            $this->assignments->replaceDocumentSplits($supplierId, $docType, $docId, $normSplits, $splitOrigins);
        }
        // Co doklad určoval před uložením: typ, který teď neurčuje, se z řádků zápisů
        // odebere jen tam, kde je pořád tato hodnota (ruční dimenze řádku zůstane).
        $released = $current['header'];
        foreach ($currentSplits[0] ?? [] as $typeId => $_) {
            $released[$typeId] = null;
        }
        $restamp = ['lines' => 0, 'needs_repost' => false, 'account_change' => false, 'locked' => false];
        foreach ($sources as [$source, $sourceId]) {
            $one = $this->posting->restampDimensions($supplierId, $source, $sourceId, false, $released);
            $restamp['lines'] += $one['lines'];
            $restamp['needs_repost'] = $restamp['needs_repost'] || $one['needs_repost'];
            $restamp['account_change'] = $restamp['account_change'] || !empty($one['account_change']);
            $restamp['locked'] = $restamp['locked'] || $this->postedOutsideOpenPeriod($supplierId, $source, $sourceId);
        }
        foreach ($cascade as [$source, $sourceId, $keep]) {
            $one = $this->posting->restampDimensions($supplierId, $source, $sourceId, $keep, $keep ? $released : []);
            $restamp['lines'] += $one['lines'];
            // Účtotvorná dimenze i v kaskádě: zápis úhrady / zápočtu, jehož řádek by změnil
            // analytiku, se nepřerazítkoval — doklad (a jeho úhrada) potřebuje přeúčtovat.
            if (!empty($one['account_change'])) {
                $restamp['needs_repost'] = true;
                $restamp['account_change'] = true;
                $restamp['locked'] = $restamp['locked'] || $this->postedOutsideOpenPeriod($supplierId, $source, $sourceId);
            }
        }
        if ($docType === 'other_item') {
            $this->syncScheduleDrafts($supplierId, $docId);
        }
        return [
            'header' => $normHeader,
            'items' => $normItems,
            'splits' => array_map([self::class, 'splitsForApi'], $normSplits),
            'restamp' => $restamp,
            'changed' => $changed,
        ];
    }

    /**
     * Zápisy jiných dokladů, které dimenze dokladu přebírají, a proto se s jeho změnou
     * přerazítkují:
     *   • faktura: bankovní a pokladní úhrady (dosavadní chování, přepíšou celou sadu),
     *     zápočty proti účtu a vzájemné zápočty (ruční typy řádků zůstanou),
     *   • přijatá faktura navíc karty majetku, které z ní vznikly a vlastní dimenze nemají,
     *   • ostatní pohledávka / závazek: jejich bankovní a pokladní úhrady.
     *
     * @return list<array{0:string, 1:int, 2:bool}> zdroj, id, zachovat ruční typy řádků
     */
    private function cascadeSources(int $supplierId, string $docType, int $docId, bool $forUpdate = false): array
    {
        $pdo = $this->db->pdo();
        $out = [];
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        if ($docType === 'other_item') {
            $stmt = $pdo->prepare(
                'SELECT DISTINCT bank_transaction_id, cash_document_id FROM other_item_allocations
                  WHERE supplier_id = ? AND other_item_id = ?' . $lock
            );
            $stmt->execute([$supplierId, $docId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = $r['bank_transaction_id'] !== null
                    ? ['bank', (int) $r['bank_transaction_id'], true]
                    : ['cash', (int) $r['cash_document_id'], true];
            }
            return $out;
        }
        if ($docType !== 'invoice' && $docType !== 'purchase_invoice') {
            return [];
        }
        $settlements = $pdo->prepare('SELECT id FROM invoice_settlements WHERE supplier_id = ? AND doc_type = ? AND doc_id = ?' . $lock);
        $settlements->execute([$supplierId, $docType, $docId]);
        foreach ($settlements->fetchAll(PDO::FETCH_COLUMN) as $settlementId) {
            $out[] = ['settlement', (int) $settlementId, true];
        }
        $offsets = $pdo->prepare('SELECT DISTINCT agreement_id FROM offset_agreement_items WHERE supplier_id = ? AND doc_type = ? AND doc_id = ?' . $lock);
        $offsets->execute([$supplierId, $docType, $docId]);
        foreach ($offsets->fetchAll(PDO::FETCH_COLUMN) as $agreementId) {
            $out[] = ['offset', (int) $agreementId, true];
        }
        $bankIds = [];
        if ($docType === 'invoice') {
            foreach ([
                ['SELECT ip.bank_transaction_id FROM invoice_payments ip
                   JOIN invoices i ON i.id = ip.invoice_id AND i.supplier_id = ?
                  WHERE ip.invoice_id = ? AND ip.bank_transaction_id IS NOT NULL', [$supplierId, $docId]],
                ['SELECT bank_transaction_id FROM payment_matches WHERE supplier_id = ? AND invoice_id = ?', [$supplierId, $docId]],
                ['SELECT bt.id FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id
                  WHERE bt.matched_invoice_id = ? AND ' . BankStatementOwnershipResolver::sql(currentRead: $forUpdate), [$docId, ...BankStatementOwnershipResolver::params($supplierId)]],
            ] as [$sql, $params]) {
                $bank = $pdo->prepare($sql . $lock);
                $bank->execute($params);
                array_push($bankIds, ...$bank->fetchAll(PDO::FETCH_COLUMN));
            }
            $bankIds = array_unique($bankIds);
            $cashColumn = 'invoice_id';
        } else {
            $bank = $pdo->prepare('SELECT DISTINCT bank_transaction_id FROM payment_matches WHERE supplier_id = ? AND purchase_invoice_id = ?' . $lock);
            $bank->execute([$supplierId, $docId]);
            $bankIds = $bank->fetchAll(PDO::FETCH_COLUMN);
            $cashColumn = 'purchase_invoice_id';
        }
        $cash = $pdo->prepare("SELECT id FROM cash_documents WHERE supplier_id = ? AND {$cashColumn} = ?" . $lock);
        $cash->execute([$supplierId, $docId]);
        foreach ($bankIds as $txId) {
            $out[] = ['bank', (int) $txId, false];
        }
        foreach ($cash->fetchAll(PDO::FETCH_COLUMN) as $cashId) {
            $out[] = ['cash', (int) $cashId, false];
        }
        if ($docType === 'purchase_invoice') {
            $assets = $pdo->prepare(
                "SELECT a.id FROM assets a
                  WHERE a.supplier_id = ? AND a.purchase_invoice_id = ?
                  ORDER BY a.id" . $lock
            );
            $assets->execute([$supplierId, $docId]);
            foreach ($assets->fetchAll(PDO::FETCH_COLUMN) as $assetId) {
                $own = $this->assignments->documentDimensions($supplierId, 'asset', (int) $assetId, $forUpdate);
                if ($own['header'] !== [] || $own['items'] !== []
                    || $this->assignments->documentSplits($supplierId, 'asset', (int) $assetId, $forUpdate) !== []) {
                    continue;
                }
                foreach ($this->postingSources($supplierId, 'asset', (int) $assetId, $forUpdate) as [$source, $sourceId]) {
                    $out[] = [$source, $sourceId, true];
                }
            }
        }
        return $out;
    }

    /**
     * Rozvrh opakování: nezaúčtované koncepty výskytů dostanou dimenze zdrojového
     * dokladu znovu (jsou to jeho kopie, generátor je kopíroval při vzniku).
     */
    private function syncScheduleDrafts(int $supplierId, int $sourceItemId): void
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT o.item_id FROM other_item_schedules s
               JOIN other_item_schedule_occurrences o ON o.schedule_id = s.id AND o.supplier_id = s.supplier_id
               JOIN other_items oi ON oi.id = o.item_id AND oi.supplier_id = o.supplier_id
              WHERE s.supplier_id = ? AND s.source_item_id = ? AND o.item_id <> s.source_item_id
                AND oi.status = 'draft' AND oi.deleted_at IS NULL ORDER BY o.item_id FOR UPDATE"
        );
        $stmt->execute([$supplierId, $sourceItemId]);
        $targets = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $source = $this->assignments->documentDimensions($supplierId, 'other_item', $sourceItemId, true);
        $sourceSplits = $this->assignments->documentSplits($supplierId, 'other_item', $sourceItemId, true);
        $guard = new DimensionPeriodGuard($this->db);
        foreach ($targets as $itemId) {
            $context = (new DocumentDimensionContext($this->db))->lockCurrent($supplierId, 'other_item', $itemId);
            $entries = $guard->lockSources($supplierId, [['other_item', $itemId]]);
            $dates = [];
            foreach ($entries as $entry) if ($entry['reversed_by'] === null) {
                $guard->assertMutable($entry);
                $dates[(string) $entry['entry_date']] = (string) $entry['entry_date'];
            }
            if ($dates === []) $dates = [$context['date']];
            $old = $this->assignments->documentDimensions($supplierId, 'other_item', $itemId, true);
            $oldSplits = $this->assignments->documentSplits($supplierId, 'other_item', $itemId, true);
            $lines = DocumentDimensionContext::identify(self::documentAssignmentLines($source['header'], $source['items'], $sourceSplits), $context, $source['header'], $source['items'], $sourceSplits);
            $previous = DocumentDimensionContext::identify(self::documentAssignmentLines($old['header'], $old['items'], $oldSplits), $context, $old['header'], $old['items'], $oldSplits);
            try {
                foreach ($dates as $date) (new SystemDimensionWritePolicy($this->db))->assertDocumentAssignments($supplierId, $lines, $date, $previous);
            } catch (PostingException $e) {
                throw new DimensionException($e->errorCode, $e->getMessage(), $e->httpStatus);
            }
        }
        foreach ($targets as $itemId) {
            $this->assignments->deleteDocument($supplierId, 'other_item', $itemId);
            $this->assignments->copyDocument($supplierId, 'other_item', $sourceItemId, $itemId);
        }
    }

    /**
     * Zdroje zápisů v deníku, které nesou dimenze dokladu: karta majetku zařazení,
     * vyřazení a všechny účetní odpisy, ostatní doklady jen sebe.
     *
     * @return list<array{0:string, 1:int}>
     */
    private function postingSources(int $supplierId, string $docType, int $docId, bool $forUpdate = false): array
    {
        if ($docType === 'asset') {
            $stmt = $this->db->pdo()->prepare(
                "SELECT id FROM depreciation_entries WHERE supplier_id = ? AND asset_id = ? AND kind = 'accounting' ORDER BY id" . ($forUpdate ? ' FOR UPDATE' : '')
            );
            $stmt->execute([$supplierId, $docId]);
            return [
                ['asset', $docId],
                ...array_map(static fn (mixed $id): array => ['depreciation', (int) $id], $stmt->fetchAll(PDO::FETCH_COLUMN)),
                ['asset_disposal', $docId],
            ];
        }
        $sourceType = self::DOCUMENTS[$docType][1];
        return $sourceType === null ? [] : [[$sourceType, $docId]];
    }

    /**
     * Leží některý živý zápis dokladu tam, kde ho nejde přepsat na místě (uzavřené
     * období, zamčené datum)? Rozhoduje totéž pravidlo jako dialog Přeúčtovat.
     */
    public function postedOutsideOpenPeriod(int $supplierId, string $sourceType, int $docId): bool
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT je.entry_date, ap.status
               FROM journal_entries je
               LEFT JOIN accounting_periods ap ON ap.id = je.period_id AND ap.supplier_id = je.supplier_id
              WHERE je.supplier_id = ? AND je.source_type = ? AND je.source_id = ? AND je.reversed_by IS NULL'
        );
        $stmt->execute([$supplierId, $sourceType, $docId]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ((new DocumentPostingGroup($this->db))->active($supplierId, $sourceType, $docId) as $owned) {
            if ((bool) $owned['grouped']) {
                $entries[] = ['entry_date' => $owned['entry_date'], 'status' => $owned['period_status']];
            }
        }
        if ($entries === []) {
            return false;
        }
        $lock = $pdo->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ?');
        $lock->execute([$supplierId]);
        $lockedUntil = $lock->fetchColumn();
        $lockedUntil = $lockedUntil === false || $lockedUntil === null ? null : (string) $lockedUntil;
        $today = date('Y-m-d');
        foreach ($entries as $entry) {
            $decision = DocumentRepostService::decide(
                false,
                $entry['status'] === null ? null : (string) $entry['status'],
                (string) $entry['entry_date'],
                $lockedUntil,
                null,
                $today,
            );
            if ($decision['strategy'] !== DocumentRepostService::STRATEGY_REPLACE) {
                return true;
            }
        }
        return false;
    }

    /**
     * Řádky živých (nestornovaných) zápisů dokladu i s dimenzemi.
     *
     * @return list<array{id:int, entry_id:int, account_code:?string, account_name:?string, side:string, amount:float, is_red_storno:bool, dimensions:array<int,int>}>
     */
    private function postedLines(int $supplierId, ?string $sourceType, int $docId): array
    {
        if ($sourceType === null) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id, l.entry_id, a.account_code, a.name AS account_name, l.side, l.amount, l.is_red_storno
               FROM journal_entries je
               JOIN journal_entry_lines l ON l.entry_id = je.id AND l.supplier_id = je.supplier_id
               LEFT JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = je.supplier_id
              WHERE je.supplier_id = ? AND je.source_type = ? AND je.source_id = ? AND je.reversed_by IS NULL
              ORDER BY je.id, l.line_no, l.id'
        );
        $stmt->execute([$supplierId, $sourceType, $docId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $dims = $this->assignments->lineDimensions($supplierId, array_map(static fn (array $r): int => (int) $r['id'], $rows));
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'entry_id' => (int) $r['entry_id'],
            'account_code' => $r['account_code'] === null ? null : (string) $r['account_code'],
            'account_name' => $r['account_name'] === null ? null : (string) $r['account_name'],
            'side' => (string) $r['side'],
            'amount' => (float) $r['amount'],
            'is_red_storno' => (bool) $r['is_red_storno'],
            'dimensions' => $dims[(int) $r['id']] ?? [],
        ], $rows);
    }

    /**
     * Provede `$fn` atomicky: ve vlastní transakci, nebo uvnitř transakce volajícího
     * přes savepoint (odmítnuté uložení tak po sobě nenechá půlku změn ani tam).
     * `$discard` = výsledek jen spočítat a změny zahodit (náhled).
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function atomically(callable $fn, bool $discard = false): mixed
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        $ownTx ? $pdo->beginTransaction() : $pdo->exec('SAVEPOINT dimension_document');
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            if (!TransactionFailure::rollbackIfAborted($pdo, $e) && $pdo->inTransaction()) {
                $ownTx ? $pdo->rollBack() : $pdo->exec('ROLLBACK TO SAVEPOINT dimension_document');
            }
            if ($e instanceof PostingException && str_starts_with($e->errorCode, 'allocation_')) {
                throw new DimensionException($e->errorCode, $e->getMessage(), $e->httpStatus);
            }
            throw $e;
        }
        if ($discard) {
            $ownTx ? $pdo->rollBack() : $pdo->exec('ROLLBACK TO SAVEPOINT dimension_document');
        } else {
            $ownTx ? $pdo->commit() : $pdo->exec('RELEASE SAVEPOINT dimension_document');
        }
        return $result;
    }

    // ── hromadné akce (Hromadné akce v seznamech, operace set_dimensions) ─────────

    /**
     * Hromadná změna dimenzí dokladu. Každý typ má režim `set` (přepsat), `fill` (jen kde
     * je prázdné) nebo `clear` (vymazat). Ukládá se toutéž cestou jako panel Dimenze na
     * detailu ({@see applyDocument()}: zámky, promítnutí do zaúčtovaných řádků, kaskáda
     * na úhrady), navíc se:
     *   • odmítne doklad, jehož živý zápis leží v uzavřeném období nebo v období se
     *     zmrazenými dimenzemi (uzavřené knihy),
     *   • odmítne změna, po které by řádek zápisu porušil pravidlo povinné dimenze.
     *
     * Rozsah `document` mění hlavičku dokladu (platí pro všechny řádky zápisu) a u typů
     * `set`/`clear` zruší vlastní hodnoty a rozpady položek, aby celý doklad nesl jednu
     * hodnotu. Rozsah `items` mění jen položky; do deníku se pak promítnou jen na
     * nákladové a výnosové řádky (pravidlo {@see DimensionStamper::assign()}), rozvahové
     * řádky (311/321/343) hodnotu nedostanou.
     *
     * `$dryRun` = jen spočítat a vrátit, nic neuložit. `$expected` = otisk stavu, který
     * musí doklad mít (ostrý běh navazuje na běh nanečisto).
     *
     * @param list<array{type_id:int, mode:string, value_id:?int}> $changes
     * @return array{changed:bool, before:list<array<string,mixed>>, after:list<array<string,mixed>>, fingerprint_before:string, fingerprint_after:string}
     * @throws DimensionException
     */
    public function bulkPatchDocument(int $supplierId, string $docType, int $docId, array $changes, string $scope, bool $dryRun, ?string $expected = null): array
    {
        $this->requireEnabled($supplierId);
        $this->requireDocument($supplierId, $docType, $docId);
        return $this->atomically(function () use ($supplierId, $docType, $docId, $changes, $scope, $expected): array {
            $sourceType = self::DOCUMENTS[$docType][1];
            $this->assertBulkMutable($supplierId, [[$sourceType, $docId]]);
            $before = $this->documentSnapshot($supplierId, $docType, $docId);
            if ($expected !== null && self::snapshotFingerprint($before) !== $expected) {
                throw new DimensionException('changed_after_dry_run', 'Dimenze dokladu se od běhu nanečisto změnily.', 409);
            }
            $cascadeBefore = $this->cascadeLineSnapshot($supplierId, $docType, $docId);
            $header = $before[0];
            $set = [];
            $remove = [];
            $dropItemTypes = [];
            foreach ($changes as $change) {
                $typeId = (int) $change['type_id'];
                $mode = (string) $change['mode'];
                if ($scope === 'items') {
                    if ($mode !== 'fill') $dropItemTypes[] = $typeId;
                    continue;
                }
                $headerEmpty = !isset($header['dimensions'][$typeId]) && !isset($header['splits'][$typeId]);
                if ($mode === 'set' || ($mode === 'fill' && $headerEmpty)) {
                    $set[$typeId] = (int) $change['value_id'];
                }
                if ($mode === 'clear') {
                    $remove[] = $typeId;
                }
                if ($mode !== 'fill') {
                    $dropItemTypes[] = $typeId;
                }
            }
            $splitsByItem = [];
            foreach ($before as $row) {
                if ($row['kind'] === 'item') $splitsByItem[(int) $row['no']] = $row['splits'];
            }
            $headerPatch = static function (array $current) use ($set, $remove): array {
                $out = array_replace($current, $set);
                foreach ($remove as $typeId) unset($out[$typeId]);
                return $out;
            };
            $itemsPatch = static function (array $current, array $itemNos) use ($changes, $scope, $dropItemTypes, $header, $splitsByItem): array {
                $out = [];
                foreach ($itemNos as $no) {
                    $dims = $current[$no] ?? [];
                    if ($scope === 'items') {
                        foreach ($changes as $change) {
                            $typeId = (int) $change['type_id'];
                            $empty = !isset($dims[$typeId]) && !isset($splitsByItem[$no][$typeId])
                                && !isset($header['dimensions'][$typeId]) && !isset($header['splits'][$typeId]);
                            if ($change['mode'] === 'set' || ($change['mode'] === 'fill' && $empty)) {
                                $dims[$typeId] = (int) $change['value_id'];
                            } elseif ($change['mode'] === 'clear') {
                                unset($dims[$typeId]);
                            }
                        }
                    } else {
                        foreach ($dropItemTypes as $typeId) unset($dims[$typeId]);
                    }
                    if ($dims !== []) $out[(int) $no] = $dims;
                }
                return $out;
            };
            $result = self::refuseLockedRepost($this->applyDocument(
                $supplierId, $docType, $docId, $headerPatch, $itemsPatch, null,
                $scope === 'items' ? [] : array_values(array_unique([...$remove, ...array_keys($set)])),
                array_values(array_unique($dropItemTypes)),
            ), false);
            $after = $this->documentSnapshot($supplierId, $docType, $docId);
            $this->assertBulkRules($supplierId, $sourceType, $before, $after);
            $this->assertBulkCascadeRules($supplierId, $docType, $docId, $cascadeBefore);
            return [
                'changed' => $result['changed'],
                'before' => $before,
                'after' => $after,
                'fingerprint_before' => self::snapshotFingerprint($before),
                'fingerprint_after' => self::snapshotFingerprint($after),
            ];
        }, $dryRun);
    }

    /**
     * Vrácení hromadné změny: doklad dostane přesně dimenze hlavičky, položek a rozpadů
     * ze snímku `$target` (i „bez hodnoty"), jen když má pořád stav `$expected`.
     *
     * @param list<array<string,mixed>> $target
     * @return array{changed:bool, before:list<array<string,mixed>>, after:list<array<string,mixed>>, fingerprint_before:string, fingerprint_after:string}
     * @throws DimensionException
     */
    public function bulkRestoreDocument(int $supplierId, string $docType, int $docId, array $target, string $expected): array
    {
        $this->requireEnabled($supplierId);
        $this->requireDocument($supplierId, $docType, $docId);
        return $this->atomically(function () use ($supplierId, $docType, $docId, $target, $expected): array {
            $sourceType = self::DOCUMENTS[$docType][1];
            $this->assertBulkMutable($supplierId, [[$sourceType, $docId]]);
            $before = $this->documentSnapshot($supplierId, $docType, $docId);
            if (self::snapshotFingerprint($before) !== $expected) {
                throw new DimensionException('changed_after_apply', 'Dimenze dokladu se od hromadné změny znovu změnily.', 409);
            }
            $cascadeBefore = $this->cascadeLineSnapshot($supplierId, $docType, $docId);
            $header = [];
            $items = [];
            $splits = [];
            $allow = [];
            foreach ($target as $row) {
                if ($row['kind'] === 'line') continue;
                $dims = self::intMap((array) $row['dimensions']);
                $rowSplits = self::splitMap((array) $row['splits']);
                array_push($allow, ...array_values($dims));
                foreach ($rowSplits as $shares) array_push($allow, ...array_keys($shares));
                if ($row['kind'] === 'header') {
                    $header = $dims;
                } elseif ($dims !== []) {
                    $items[(int) $row['no']] = $dims;
                }
                if ($rowSplits !== []) $splits[(int) $row['no']] = $rowSplits;
            }
            $result = self::refuseLockedRepost(
                $this->applyDocument($supplierId, $docType, $docId, $header, $items, $splits, [], [], $allow),
                false,
            );
            $after = $this->documentSnapshot($supplierId, $docType, $docId);
            $this->assertBulkRules($supplierId, $sourceType, $before, $after);
            $this->assertBulkCascadeRules($supplierId, $docType, $docId, $cascadeBefore);
            return [
                'changed' => $result['changed'],
                'before' => $before,
                'after' => $after,
                'fingerprint_before' => self::snapshotFingerprint($before),
                'fingerprint_after' => self::snapshotFingerprint($after),
            ];
        });
    }

    /**
     * Hromadná změna dimenzí na řádcích jednoho zápisu deníku (zápis vybraný přímo
     * v Účetním deníku). Mění jen řádky na účtech s některým z prefixů `$accountPrefixes`
     * (prázdné = všechny řádky) a ukládá se toutéž cestou jako úprava dimenzí řádků
     * v deníku ({@see applyEntryLines()}: zámek období, povinné dimenze, účtotvorná
     * dimenze). Zápis v uzavřeném období nebo se zmrazenými dimenzemi se odmítne.
     *
     * @param list<array{type_id:int, mode:string, value_id:?int}> $changes
     * @param list<string> $accountPrefixes
     * @return array{changed:bool, matched:int, before:list<array<string,mixed>>, after:list<array<string,mixed>>, fingerprint_before:string, fingerprint_after:string}
     * @throws DimensionException
     */
    public function bulkPatchEntryLines(int $supplierId, int $entryId, array $changes, array $accountPrefixes, bool $dryRun, ?string $expected = null): array
    {
        $this->requireEnabled($supplierId);
        return $this->atomically(function () use ($supplierId, $entryId, $changes, $accountPrefixes, $expected): array {
            $this->assertBulkEntryMutable($supplierId, $entryId);
            $before = $this->entrySnapshot($supplierId, $entryId);
            if ($expected !== null && self::snapshotFingerprint($before) !== $expected) {
                throw new DimensionException('changed_after_dry_run', 'Dimenze zápisu se od běhu nanečisto změnily.', 409);
            }
            $lines = [];
            $splits = [];
            $matched = 0;
            foreach ($before as $row) {
                if (!self::matchesPrefix((string) ($row['account_code'] ?? ''), $accountPrefixes)) continue;
                $matched++;
                $dims = self::intMap((array) $row['dimensions']);
                $rowSplits = self::splitMap((array) $row['splits']);
                foreach ($changes as $change) {
                    $typeId = (int) $change['type_id'];
                    $empty = !isset($dims[$typeId]) && !isset($rowSplits[$typeId]);
                    if ($change['mode'] === 'set' || ($change['mode'] === 'fill' && $empty)) {
                        $dims[$typeId] = (int) $change['value_id'];
                        unset($rowSplits[$typeId]);
                    } elseif ($change['mode'] === 'clear') {
                        unset($dims[$typeId], $rowSplits[$typeId]);
                    }
                }
                ksort($dims);
                if ($dims != self::intMap((array) $row['dimensions']) || $rowSplits != self::splitMap((array) $row['splits'])) {
                    $lines[(int) $row['line_id']] = $dims;
                    $splits[(int) $row['line_id']] = $rowSplits;
                }
            }
            if ($lines !== []) {
                $this->applyEntryLines($supplierId, $entryId, $lines, $splits);
            }
            $after = $this->entrySnapshot($supplierId, $entryId);
            return [
                'changed' => $lines !== [],
                'matched' => $matched,
                'before' => $before,
                'after' => $after,
                'fingerprint_before' => self::snapshotFingerprint($before),
                'fingerprint_after' => self::snapshotFingerprint($after),
            ];
        }, $dryRun);
    }

    /**
     * Vrácení hromadné změny řádků zápisu do snímku `$target`, jen když má zápis pořád
     * stav `$expected`.
     *
     * @param list<array<string,mixed>> $target
     * @return array{changed:bool, before:list<array<string,mixed>>, after:list<array<string,mixed>>, fingerprint_before:string, fingerprint_after:string}
     * @throws DimensionException
     */
    public function bulkRestoreEntryLines(int $supplierId, int $entryId, array $target, string $expected): array
    {
        $this->requireEnabled($supplierId);
        return $this->atomically(function () use ($supplierId, $entryId, $target, $expected): array {
            $this->assertBulkEntryMutable($supplierId, $entryId);
            $before = $this->entrySnapshot($supplierId, $entryId);
            if (self::snapshotFingerprint($before) !== $expected) {
                throw new DimensionException('changed_after_apply', 'Dimenze zápisu se od hromadné změny znovu změnily.', 409);
            }
            $current = [];
            foreach ($before as $row) $current[(int) $row['line_id']] = $row;
            $lines = [];
            $splits = [];
            foreach ($target as $row) {
                $lineId = (int) $row['line_id'];
                if (!isset($current[$lineId])) continue;
                $dims = self::intMap((array) $row['dimensions']);
                $rowSplits = self::splitMap((array) $row['splits']);
                if ($dims != self::intMap((array) $current[$lineId]['dimensions']) || $rowSplits != self::splitMap((array) $current[$lineId]['splits'])) {
                    $lines[$lineId] = $dims;
                    $splits[$lineId] = $rowSplits;
                }
            }
            if ($lines !== []) {
                $this->applyEntryLines($supplierId, $entryId, $lines, $splits);
            }
            $after = $this->entrySnapshot($supplierId, $entryId);
            return [
                'changed' => $lines !== [],
                'before' => $before,
                'after' => $after,
                'fingerprint_before' => self::snapshotFingerprint($before),
                'fingerprint_after' => self::snapshotFingerprint($after),
            ];
        });
    }

    /**
     * Otisk snímku dimenzí ({@see documentSnapshot()}, {@see entrySnapshot()}).
     *
     * @param list<array<string,mixed>> $snapshot
     */
    public static function snapshotFingerprint(array $snapshot): string
    {
        $norm = array_map(static fn (array $row): array => [
            $row['kind'], $row['no'], $row['line_id'],
            self::intMap((array) $row['dimensions']),
            array_map(static fn (array $shares): array => array_map(static fn (mixed $v): string => number_format((float) $v, 10, '.', ''), $shares), self::splitMap((array) $row['splits'])),
        ], $snapshot);
        return hash('sha256', json_encode($norm, JSON_THROW_ON_ERROR));
    }

    /**
     * Názvy typů a hodnot pro zobrazení snímků.
     *
     * @param list<int> $valueIds
     * @return array{types:array<int,string>, values:array<int,string>}
     */
    public function labels(int $supplierId, array $valueIds): array
    {
        $types = [];
        foreach ($this->repo->listTypes($supplierId) as $type) {
            $types[(int) $type['id']] = (string) $type['name'];
        }
        $values = [];
        foreach ($this->repo->valuesByIds($supplierId, $valueIds) as $id => $value) {
            $values[(int) $id] = trim(((string) ($value['code'] ?? '')) . ' ' . (string) ($value['name'] ?? ''));
        }
        return ['types' => $types, 'values' => $values];
    }

    /**
     * Hlavička, položky a živé řádky zápisů dokladu s dimenzemi a rozpady, čtené pod
     * zámky probíhající transakce.
     *
     * @return list<array<string,mixed>>
     */
    private function documentSnapshot(int $supplierId, string $docType, int $docId): array
    {
        $dims = $this->assignments->documentDimensions($supplierId, $docType, $docId, true);
        $splits = $this->assignments->documentSplits($supplierId, $docType, $docId, true);
        $rows = [self::snapshotRow('header', 0, null, $dims['header'], $splits[0] ?? [])];
        $itemNos = array_unique([...array_keys($dims['items']), ...array_filter(array_keys($splits), static fn (int $no): bool => $no > 0)]);
        sort($itemNos);
        foreach ($itemNos as $no) {
            $rows[] = self::snapshotRow('item', (int) $no, null, $dims['items'][$no] ?? [], $splits[$no] ?? []);
        }
        foreach ($this->postingSources($supplierId, $docType, $docId) as [$sourceType, $sourceId]) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT l.id FROM journal_entries je
                   JOIN journal_entry_lines l ON l.entry_id = je.id AND l.supplier_id = je.supplier_id
                  WHERE je.supplier_id = ? AND je.source_type = ? AND je.source_id = ? AND je.reversed_by IS NULL
                  ORDER BY je.id, l.line_no, l.id'
            );
            $stmt->execute([$supplierId, $sourceType, $sourceId]);
            array_push($rows, ...$this->lineSnapshotRows($supplierId, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function entrySnapshot(int $supplierId, int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM journal_entry_lines WHERE supplier_id = ? AND entry_id = ? ORDER BY line_no, id');
        $stmt->execute([$supplierId, $entryId]);
        return $this->lineSnapshotRows($supplierId, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * @param list<int> $lineIds
     * @return list<array<string,mixed>>
     */
    private function lineSnapshotRows(int $supplierId, array $lineIds): array
    {
        if ($lineIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($lineIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.id, l.account_id, a.account_code, l.side, l.amount, je.entry_date
               FROM journal_entry_lines l
               JOIN journal_entries je ON je.id = l.entry_id AND je.supplier_id = l.supplier_id
          LEFT JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND l.id IN ({$marks})"
        );
        $stmt->execute([$supplierId, ...$lineIds]);
        $byId = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $byId[(int) $r['id']] = $r;
        $dims = $this->assignments->lineDimensions($supplierId, $lineIds, true);
        $splits = $this->assignments->lineSplits($supplierId, $lineIds, true);
        $rows = [];
        foreach ($lineIds as $lineId) {
            $r = $byId[$lineId] ?? null;
            if ($r === null) continue;
            $rows[] = array_merge(self::snapshotRow('line', null, $lineId, $dims[$lineId] ?? [], $splits[$lineId] ?? []), [
                'account_id' => (int) $r['account_id'],
                'account_code' => $r['account_code'] === null ? null : (string) $r['account_code'],
                'side' => (string) $r['side'],
                'amount' => round((float) $r['amount'], 2),
                'entry_date' => (string) $r['entry_date'],
            ]);
        }
        return $rows;
    }

    /**
     * @param array<int,int> $dims
     * @param array<int,array<int,float>> $splits
     * @return array<string,mixed>
     */
    private static function snapshotRow(string $kind, ?int $no, ?int $lineId, array $dims, array $splits): array
    {
        ksort($dims);
        ksort($splits);
        return [
            'kind' => $kind, 'no' => $no, 'line_id' => $lineId, 'account_code' => null, 'side' => null, 'amount' => null,
            'dimensions' => $dims, 'splits' => $splits,
        ];
    }

    /**
     * Mapa typ => hodnota ze snímku (i po průchodu JSON).
     *
     * @param array<int|string,mixed> $raw
     * @return array<int,int>
     */
    private static function intMap(array $raw): array
    {
        $out = [];
        foreach ($raw as $typeId => $valueId) $out[(int) $typeId] = (int) $valueId;
        ksort($out);
        return $out;
    }

    /**
     * Rozpad typ => hodnota => podíl ze snímku (i po průchodu JSON).
     *
     * @param array<int|string,mixed> $raw
     * @return array<int,array<int,float>>
     */
    private static function splitMap(array $raw): array
    {
        $out = [];
        foreach ($raw as $typeId => $shares) {
            $byValue = [];
            foreach ((array) $shares as $valueId => $share) $byValue[(int) $valueId] = round((float) $share, 10);
            if ($byValue === []) continue;
            ksort($byValue);
            $out[(int) $typeId] = $byValue;
        }
        ksort($out);
        return $out;
    }

    /** @param list<string> $prefixes */
    private static function matchesPrefix(string $code, array $prefixes): bool
    {
        if ($prefixes === []) return true;
        foreach ($prefixes as $prefix) {
            if (str_starts_with($code, $prefix)) return true;
        }
        return false;
    }

    /**
     * Hromadná změna nesmí do uzavřeného období ani do období se zmrazenými dimenzemi
     * (uzavřené knihy). Zamyká zápisy zdrojů do konce transakce.
     *
     * @param list<array{0:?string, 1:int}> $sources
     */
    private function assertBulkMutable(int $supplierId, array $sources): void
    {
        $sources = array_values(array_filter($sources, static fn (array $s): bool => $s[0] !== null));
        foreach ((new DimensionPeriodGuard($this->db))->lockSources($supplierId, $sources) as $entry) {
            if ($entry['reversed_by'] === null && $entry['posted_at'] !== null) {
                self::assertBulkEntryState($entry);
            }
        }
    }

    private function assertBulkEntryMutable(int $supplierId, int $entryId): void
    {
        $entries = (new DimensionPeriodGuard($this->db))->lockEntries($supplierId, [$entryId]);
        if (!isset($entries[$entryId])) {
            throw new DimensionException('not_found', 'Účetní zápis nenalezen.', 404);
        }
        if ($entries[$entryId]['reversed_by'] !== null) {
            throw new DimensionException('entry_reversed', 'Stornovaný zápis se hromadně nemění.', 409);
        }
        self::assertBulkEntryState($entries[$entryId]);
    }

    /** @param array<string,mixed> $entry */
    private static function assertBulkEntryState(array $entry): void
    {
        if (\MyInvoice\Service\Accounting\AccountingPeriodStatus::isClosed((string) $entry['period_status'])) {
            throw new DimensionException('period_not_open', 'Zápis leží v uzavřeném účetním období.', 409);
        }
        if (!empty($entry['dimensions_frozen'])) {
            throw new DimensionException('dimensions_frozen', 'Dimenze období jsou zmrazené uzavřením knih.', 409);
        }
    }

    /**
     * Pravidla povinných dimenzí ({@see DimensionRuleService::check()}) nad řádky zápisů,
     * kterým hromadná změna dimenze změnila. Řádky, které změna nezasáhla, se neposuzují:
     * starší porušení jinde nesmí zablokovat opravu.
     *
     * @param list<array<string,mixed>> $before
     * @param list<array<string,mixed>> $after
     */
    private function assertBulkRules(int $supplierId, ?string $sourceType, array $before, array $after): void
    {
        if ($sourceType === null) {
            return;
        }
        $previous = [];
        foreach ($before as $row) {
            if ($row['kind'] === 'line') $previous[(int) $row['line_id']] = $row;
        }
        $byDate = [];
        $prevByDate = [];
        foreach ($after as $row) {
            if ($row['kind'] !== 'line') continue;
            $old = $previous[(int) $row['line_id']] ?? null;
            if ($old !== null && self::intMap((array) $old['dimensions']) == self::intMap((array) $row['dimensions'])
                && self::splitMap((array) $old['splits']) == self::splitMap((array) $row['splits'])) {
                continue;
            }
            $line = static fn (array $r): array => [
                'id' => (int) $r['line_id'], 'account_id' => (int) $r['account_id'], 'side' => (string) $r['side'],
                'amount' => (float) $r['amount'], 'dimensions' => self::intMap((array) $r['dimensions']),
                'dimension_splits' => self::splitMap((array) $r['splits']),
            ];
            $byDate[(string) $row['entry_date']][] = $line($row);
            if ($old !== null) $prevByDate[(string) $row['entry_date']][] = $line($old);
        }
        foreach ($byDate as $date => $lines) {
            try {
                $this->ruleService()->check($supplierId, $sourceType, $lines, (string) $date, $prevByDate[$date] ?? []);
            } catch (PostingException $e) {
                throw new DimensionException($e->errorCode, $e->getMessage(), $e->httpStatus);
            }
        }
    }

    /**
     * Řádky živých zápisů, které uložení dimenzí dokladu přerazítkuje mimo jeho vlastní
     * zápis ({@see cascadeSources()}: bankovní a pokladní úhrady, zápočty, karty majetku),
     * podle zdroje zápisu. Hromadná změna je ověřuje stejnými pravidly povinných dimenzí
     * jako řádky dokladu: uložení dimenzí jednoho dokladu je přerazítkuje taky.
     *
     * @return array<string,list<array<string,mixed>>> zdroj zápisu => řádky
     */
    private function cascadeLineSnapshot(int $supplierId, string $docType, int $docId): array
    {
        $bySource = [];
        foreach ($this->cascadeSources($supplierId, $docType, $docId) as [$source, $sourceId]) {
            $bySource[(string) $source][] = (int) $sourceId;
        }
        $out = [];
        foreach ($bySource as $source => $ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT l.id FROM journal_entries je
                   JOIN journal_entry_lines l ON l.entry_id = je.id AND l.supplier_id = je.supplier_id
                  WHERE je.supplier_id = ? AND je.source_type = ? AND je.source_id IN ({$marks})
                    AND je.reversed_by IS NULL AND je.posted_at IS NOT NULL
                  ORDER BY je.id, l.line_no, l.id"
            );
            $stmt->execute([$supplierId, $source, ...$ids]);
            $lineIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            if ($lineIds !== []) {
                $out[$source] = $this->lineSnapshotRows($supplierId, $lineIds);
            }
        }
        return $out;
    }

    /** @param array<string,list<array<string,mixed>>> $before {@see cascadeLineSnapshot()} před uložením */
    private function assertBulkCascadeRules(int $supplierId, string $docType, int $docId, array $before): void
    {
        foreach ($this->cascadeLineSnapshot($supplierId, $docType, $docId) as $source => $after) {
            $this->assertBulkRules($supplierId, $source, $before[$source] ?? [], $after);
        }
    }

    /** @return array<int,array<int,int>> řádek => typ => hodnota */
    public function entryLineDimensions(int $supplierId, int $entryId): array
    {
        return $this->assignments->entryLineDimensions($supplierId, $entryId);
    }

    private static function documentAssignmentLines(array $header, array $items, array $splits, array $itemNos = []): array
    {
        $out = [['line_no' => 0, 'dimensions' => $header, 'dimension_splits' => $splits[0] ?? []]];
        foreach (array_unique([...array_keys($items), ...array_keys($splits), ...$itemNos]) as $itemNo) {
            if ((int) $itemNo === 0) {
                continue;
            }
            $dimensions = $header;
            $shares = $splits[0] ?? [];
            foreach ($items[$itemNo] ?? [] as $typeId => $valueId) {
                $dimensions[$typeId] = $valueId;
                unset($shares[$typeId]);
            }
            foreach ($splits[$itemNo] ?? [] as $typeId => $values) {
                $shares[$typeId] = $values;
                unset($dimensions[$typeId]);
            }
            $out[] = ['line_no' => (int) $itemNo, 'dimensions' => $dimensions, 'dimension_splits' => $shares];
        }
        return $out;
    }

    /**
     * Ruční změna dimenzí řádků zápisu před uzavřením účetních knih.
     *
     * `$splits` = id řádku => typ => seznam `{value_id, share}` (rozpad řádku mezi víc
     * hodnot typu); null = rozpady ponechat. Typ s rozpadem jedinou hodnotu nemá
     * a naopak: jediná hodnota zvolená teď rozpad téhož typu zruší. Povinnou dimenzi
     * pravidla s vynucením `error` z řádku odebrat nejde (DimensionRuleService).
     *
     * @param array<int|string,mixed> $lines id řádku => mapa typ => hodnota
     * @param array<int|string,mixed>|null $splits
     * @return int počet změněných řádků
     */
    public function saveEntryLines(int $supplierId, int $entryId, array $lines, ?array $splits = null): int
    {
        $this->requireEnabled($supplierId);
        return (new DimensionPeriodGuard($this->db))->transaction(
            fn (): int => $this->applyEntryLines($supplierId, $entryId, $lines, $splits),
        );
    }

    private function applyEntryLines(int $supplierId, int $entryId, array $lines, ?array $splits): int
    {
        (new SystemDimensionWritePolicy($this->db))->lockContext($supplierId);
        (new \MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard($this->db))->lockClaims($supplierId);
        (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->acquire($supplierId);
        $guard = new DimensionPeriodGuard($this->db);
        $entries = $guard->lockEntries($supplierId, [$entryId]);
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT l.id, l.account_id, l.side, l.amount, je.source_type, je.entry_date
               FROM journal_entry_lines l
               JOIN journal_entries je ON je.id = l.entry_id AND je.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND l.entry_id = ?
              ORDER BY l.line_no, l.id FOR UPDATE'
        );
        $stmt->execute([$supplierId, $entryId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[(int) $r['id']] = $r;
        }
        if ($rows === []) {
            throw new DimensionException('not_found', 'Účetní zápis nenalezen.', 404);
        }
        $current = $this->assignments->entryLineDimensions($supplierId, $entryId, true);
        $currentSplits = $this->assignments->entryLineSplits($supplierId, $entryId, true);
        $newDims = $current;
        $newSplits = $currentSplits;
        foreach ($lines as $lineId => $dims) {
            $lineId = (int) $lineId;
            if (!isset($rows[$lineId]) || !is_array($dims)) {
                throw new DimensionException('invalid_line', 'Řádek #' . $lineId . ' do zápisu nepatří.', 400);
            }
            $newDims[$lineId] = $this->normalize($supplierId, $dims, array_values($current[$lineId] ?? []));
            if ($splits === null && isset($newSplits[$lineId])) {
                $newSplits[$lineId] = array_diff_key($newSplits[$lineId], $newDims[$lineId]);
            }
        }
        foreach ($splits ?? [] as $lineId => $byType) {
            $lineId = (int) $lineId;
            if (!isset($rows[$lineId]) || !is_array($byType)) {
                throw new DimensionException('invalid_line', 'Řádek #' . $lineId . ' do zápisu nepatří.', 400);
            }
            $currentIds = [];
            foreach ($currentSplits[$lineId] ?? [] as $shares) {
                array_push($currentIds, ...array_keys($shares));
            }
            $newSplits[$lineId] = $this->normalizeSplits($supplierId, $byType, $currentIds);
            $newDims[$lineId] = array_diff_key($newDims[$lineId] ?? [], $newSplits[$lineId]);
        }

        // Kontrolují se jen upravované řádky — starší řádek bez povinné dimenze
        // (převzatá historie) nesmí zablokovat opravu jiného řádku téhož zápisu.
        $first = reset($rows);
        $touched = array_filter($rows, static function (array $r) use ($current, $newDims, $currentSplits, $newSplits): bool {
            $id = (int) $r['id'];
            $before = $current[$id] ?? [];
            $after = $newDims[$id] ?? [];
            ksort($before);
            ksort($after);
            return $before !== $after
                || !DimensionAssignmentRepository::sameSplits($currentSplits[$id] ?? [], $newSplits[$id] ?? []);
        });
        if ($touched !== []) {
            $guard->assertMutable($entries[$entryId]);
        }
        $previousLines = array_map(static fn (array $row): array => $row + [
            'dimensions' => $current[(int) $row['id']] ?? [],
            'dimension_splits' => $currentSplits[(int) $row['id']] ?? [],
        ], array_values($rows));
        $prospective = array_map(static fn (array $row): array => $row + [
            'dimensions' => $newDims[(int) $row['id']] ?? [],
            'dimension_splits' => $newSplits[(int) $row['id']] ?? [],
        ], array_values($rows));
        if ($touched !== []) {
            try {
                (new \MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard($this->db))->assertPostingLinesMutable($supplierId, array_map('intval', array_keys($touched)));
                (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->assertLinesMutable($supplierId, array_map('intval', array_keys($touched)));
                (new JointAllocationUseGuard($this->db))->assertLinesMutable($supplierId, array_map('intval', array_keys($touched)));
                (new SystemDimensionWritePolicy($this->db))->assertJournal(
                    $entries[$entryId] + ['supplier_id' => $supplierId], $prospective, $previousLines,
                );
            } catch (PostingException|JointAllocationException $e) {
                throw new DimensionException($e->errorCode, $e->getMessage(), $e->httpStatus);
            }
        }
        $this->ruleService()->assertLines(
            $supplierId,
            (string) $first['source_type'],
            array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'account_id' => (int) $r['account_id'],
                'side' => (string) $r['side'],
                'amount' => (float) $r['amount'],
                'dimensions' => $newDims[(int) $r['id']] ?? [],
                'dimension_splits' => $newSplits[(int) $r['id']] ?? [],
            ], array_values($touched)),
            (string) $first['entry_date'],
            $previousLines,
        );

        // Účtotvorná dimenze: nová hodnota nesmí tiše přesunout řádek na jinou analytiku.
        if ($touched !== []) {
            $projected = (new DimensionAccountRouter($this->db))->projectForEntry(
                $supplierId,
                (string) $first['entry_date'],
                array_map(static fn (array $r): array => [
                    'id' => (int) $r['id'],
                    'account_id' => (int) $r['account_id'],
                    'side' => (string) $r['side'],
                    'amount' => (float) $r['amount'],
                    'dimensions' => $newDims[(int) $r['id']] ?? [],
                    'dimension_splits' => $newSplits[(int) $r['id']] ?? [],
                ], array_values($rows)),
                $this->posting->singleAnalyticRedirects($supplierId),
                array_map(static fn (array $r): array => [
                    'dimensions' => $current[(int) $r['id']] ?? [],
                    'dimension_splits' => $currentSplits[(int) $r['id']] ?? [],
                ], $rows),
            );
            if (array_intersect($projected['conflicts'], array_map('intval', array_keys($touched))) !== []) {
                throw new DimensionException('account_change_needs_repost', self::ACCOUNT_CHANGE_MESSAGE, 409);
            }
        }

        $changed = 0;
        foreach (array_keys($rows) as $lineId) {
            $dimsChanged = isset($newDims[$lineId])
                && $this->assignments->replaceLineDimensions($supplierId, $lineId, $newDims[$lineId],
                    self::explicitOrigins($newDims[$lineId], 'journal:' . $entryId . ':' . $lineId));
            $splitsChanged = ($splits !== null || isset($currentSplits[$lineId]))
                && $this->assignments->replaceLineSplits($supplierId, $lineId, $newSplits[$lineId] ?? [],
                    array_map(static fn (array $values): array => self::explicitOrigins($values, 'journal:' . $entryId . ':' . $lineId), $newSplits[$lineId] ?? []));
            if ($dimsChanged || $splitsChanged) {
                $changed++;
            }
        }
        return $changed;
    }

    /** @return array<int,array<int,list<array{value_id:int, share:float}>>> řádek => typ => rozpad */
    public function entryLineSplits(int $supplierId, int $entryId): array
    {
        return array_map([self::class, 'splitsForApi'], $this->assignments->entryLineSplits($supplierId, $entryId));
    }

    /**
     * @param list<int> $lineIds
     * @return array<int,array<int,list<array{value_id:int, share:float}>>> řádek => typ => rozpad
     */
    public function lineSplits(int $supplierId, array $lineIds): array
    {
        return array_map([self::class, 'splitsForApi'], $this->assignments->lineSplits($supplierId, $lineIds));
    }

    private function ruleService(): DimensionRuleService
    {
        return new DimensionRuleService($this->db);
    }

    // ── výchozí dimenze klienta a zakázky ─────────────────────────────────────

    /**
     * @param 'client'|'project'|'product'|'product_category'|'bank_account' $entity
     * @return array<int,int> typ => hodnota
     */
    public function entityDefaults(int $supplierId, string $entity, int $entityId): array
    {
        $this->requireEntity($supplierId, $entity, $entityId);
        return $this->defaults->forEntity($supplierId, $entity, $entityId);
    }

    /**
     * Uloží výchozí dimenze klienta, zakázky, produktu, kategorie nebo bankovního účtu. Zaúčtované doklady se nemění —
     * výchozí hodnoty se uplatní až u dokladů, které se budou účtovat.
     *
     * @param 'client'|'project'|'product'|'product_category'|'bank_account' $entity
     * @param array<int|string,mixed> $raw typ => hodnota
     * @return array<int,int>
     */
    public function saveEntityDefaults(int $supplierId, string $entity, int $entityId, array $raw): array
    {
        $this->requireEnabled($supplierId);
        $this->requireEntity($supplierId, $entity, $entityId);
        $current = $this->defaults->forEntity($supplierId, $entity, $entityId);
        $norm = $this->normalize($supplierId, $raw, array_values($current));
        $this->defaults->replace($supplierId, $entity, $entityId, $norm);
        return $norm;
    }

    /**
     * Předvyplnění hlavičky dokladu v editoru: zakázka > klient, u platby dimenze
     * placeného dokladu. Klient/zakázka cizí firmy nic nevrátí (predikát firmy).
     *
     * `$vendorHistory` (přijatý doklad, F5) doplní typy, které zbyly prázdné, hodnotami
     * z posledního dokladu téhož dodavatele (`$clientId`) — zdroj `history` je jen návrh
     * a má nejnižší přednost. `$excludePurchaseInvoiceId` = doklad, který se právě
     * kontroluje (sám sobě návrhem není).
     *
     * @return array{header:array<int,int>, sources:array<int,string>}
     */
    public function prefill(int $supplierId, ?int $clientId, ?int $projectId, ?string $linkedDocType = null, ?int $linkedDocId = null, bool $vendorHistory = false, ?int $excludePurchaseInvoiceId = null): array
    {
        if (!$this->repo->enabled($supplierId)) {
            return ['header' => [], 'sources' => []];
        }
        $result = $this->defaultsResolver->resolve($supplierId, $clientId, $projectId);
        if ($linkedDocType !== null && $linkedDocId !== null && $linkedDocId > 0
            && in_array($linkedDocType, ['invoice', 'purchase_invoice'], true)) {
            foreach ($this->defaultsResolver->effectiveHeader($supplierId, $linkedDocType, $linkedDocId) as $typeId => $valueId) {
                if (!isset($result['header'][$typeId])) {
                    $result['header'][$typeId] = $valueId;
                    $result['sources'][$typeId] = 'document';
                }
            }
            ksort($result['header']);
            ksort($result['sources']);
        }
        if ($vendorHistory && $clientId !== null) {
            $history = (new VendorDimensionHistory($this->db))->lastHeader($supplierId, $clientId, $excludePurchaseInvoiceId);
            foreach ($history as $typeId => $valueId) {
                if (!isset($result['header'][$typeId])) {
                    $result['header'][$typeId] = $valueId;
                    $result['sources'][$typeId] = 'history';
                }
            }
            ksort($result['header']);
            ksort($result['sources']);
        }
        return $result;
    }

    /**
     * Předvyplnění dimenzí položky z produktu (produkt > kategorie). Totéž doplní
     * zaúčtování položce, která vlastní hodnotu typu nemá ({@see DimensionStamper}).
     *
     * @return array{header:array<int,int>, sources:array<int,string>}
     */
    public function productPrefill(int $supplierId, int $productId): array
    {
        if (!$this->repo->enabled($supplierId)) {
            return ['header' => [], 'sources' => []];
        }
        return $this->defaultsResolver->forProducts($supplierId, [$productId])[$productId] ?? ['header' => [], 'sources' => []];
    }

    /**
     * Filtr sestav na hodnotu dimenze včetně podřízených hodnot.
     */
    public function filter(int $supplierId, int $valueId, bool $withDescendants = true): DimensionFilter
    {
        $value = $this->requireValue($supplierId, $valueId);
        $ids = $withDescendants ? $this->repo->descendantIds($supplierId, $valueId) : [$valueId];
        $type = $this->repo->findType($supplierId, (int) $value['type_id']);
        return new DimensionFilter(
            $value['type_id'],
            $valueId,
            $ids,
            array_values($this->repo->costCenterCodes($supplierId, $ids)),
            trim(($type !== null ? $type['name'] . ': ' : '') . DimensionValueLabel::full($value))
                . ($withDescendants && count($ids) > 1 ? ' (vč. podřízených)' : ''),
        );
    }

    /**
     * Filtr sestav na víc hodnot: hodnoty stejného typu se sčítají (stačí kterákoli),
     * různé typy musí platit zároveň ({@see DimensionFilter::all()}).
     *
     * @param non-empty-list<int> $valueIds
     */
    public function filters(int $supplierId, array $valueIds, bool $withDescendants = true): DimensionFilter
    {
        $byType = [];
        foreach (array_values(array_unique($valueIds)) as $valueId) {
            $filter = $this->filter($supplierId, $valueId, $withDescendants);
            $byType[$filter->typeId][] = $filter;
        }
        $criteria = [];
        foreach ($byType as $filters) {
            $first = $filters[0];
            if (count($filters) === 1) {
                $criteria[] = $first;
                continue;
            }
            $type = $this->repo->findType($supplierId, $first->typeId);
            $prefix = $type !== null ? $type['name'] . ': ' : '';
            $criteria[] = new DimensionFilter(
                $first->typeId,
                $first->valueId,
                array_values(array_unique(array_merge(...array_map(static fn (DimensionFilter $f): array => $f->valueIds, $filters)))),
                array_values(array_unique(array_merge(...array_map(static fn (DimensionFilter $f): array => $f->costCenterCodes, $filters)))),
                $prefix . implode(', ', array_map(
                    static fn (DimensionFilter $f): string => $prefix !== '' && str_starts_with((string) $f->label, $prefix) ? substr((string) $f->label, strlen($prefix)) : (string) $f->label,
                    $filters,
                )),
            );
        }
        if (count($criteria) > DimensionFilter::MAX_TYPES) {
            throw new DimensionException('dimension_value_invalid', 'Filtr může kombinovat nejvýše ' . DimensionFilter::MAX_TYPES . ' typy dimenzí.');
        }
        return DimensionFilter::all($criteria);
    }

    // ── skupina firem ────────────────────────────────────────────────────────

    /**
     * @param list<int> $accessibleSupplierIds firmy, do kterých má uživatel přístup
     * @return array<string,mixed>
     */
    public function groupInfo(int $supplierId, array $accessibleSupplierIds): array
    {
        $groupId = $this->repo->groupIdOf($supplierId);
        $group = $groupId !== null ? $this->repo->findGroup($groupId) : null;
        return [
            'group' => $group === null ? null : $group + ['members' => $this->repo->groupMembers($groupId)],
            'candidates' => array_values(array_filter(
                $this->repo->groupsOfSuppliers($accessibleSupplierIds),
                static fn (array $g): bool => $g['id'] !== $groupId,
            )),
        ];
    }

    public function createGroup(int $supplierId, string $name): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 190) {
            throw new DimensionException('validation_failed', 'Název skupiny musí mít 1–190 znaků.');
        }
        if ($this->repo->groupIdOf($supplierId) !== null) {
            throw new DimensionException('already_in_group', 'Firma už do skupiny patří.', 409);
        }
        $id = $this->repo->createGroup($name);
        $this->repo->setSupplierGroup($supplierId, $id);
        return $id;
    }

    /**
     * Připojí firmu k existující skupině. Smí jen ten, kdo má přístup aspoň k jedné
     * firmě té skupiny — jinak by se cizí firma připojila ke globálním dimenzím
     * skupiny, kterou nevidí.
     *
     * @param list<int> $accessibleSupplierIds
     */
    public function joinGroup(int $supplierId, int $groupId, array $accessibleSupplierIds, bool $superadmin): void
    {
        if ($this->repo->findGroup($groupId) === null) {
            throw new DimensionException('not_found', 'Skupina nenalezena.', 404);
        }
        $members = array_column($this->repo->groupMembers($groupId), 'id');
        if (!$superadmin && array_intersect($members, $accessibleSupplierIds) === []) {
            throw new DimensionException('not_found', 'Skupina nenalezena.', 404);
        }
        $this->repo->setSupplierGroup($supplierId, $groupId);
    }

    public function leaveGroup(int $supplierId): void
    {
        $this->repo->setSupplierGroup($supplierId, null);
    }

    public function renameGroup(int $supplierId, string $name): void
    {
        $groupId = $this->repo->groupIdOf($supplierId);
        $name = trim($name);
        if ($groupId === null) {
            throw new DimensionException('no_supplier_group', 'Firma nepatří do skupiny firem.', 409);
        }
        if ($name === '' || mb_strlen($name) > 190) {
            throw new DimensionException('validation_failed', 'Název skupiny musí mít 1–190 znaků.');
        }
        $this->repo->renameGroup($groupId, $name);
    }

    // ── interní ──────────────────────────────────────────────────────────────

    public function requireType(int $supplierId, int $typeId): array
    {
        $type = $this->repo->findType($supplierId, $typeId);
        if ($type === null) {
            throw new DimensionException('not_found', 'Typ dimenze nenalezen.', 404);
        }
        return $type;
    }

    public function requireValue(int $supplierId, int $valueId): array
    {
        $value = $this->repo->findValue($supplierId, $valueId);
        if ($value === null) {
            throw new DimensionException('not_found', 'Hodnota dimenze nenalezena.', 404);
        }
        return $value;
    }

    /** Zápis hodnot dimenzí mimo doklady (pravidlo bankovních pohybů) jen se zapnutými dimenzemi. */
    public function requireEnabledFor(int $supplierId): void
    {
        $this->requireEnabled($supplierId);
    }

    private function requireEnabled(int $supplierId): void
    {
        if (!$this->repo->enabled($supplierId)) {
            throw new DimensionException('dimensions_disabled', 'Dimenze nejsou u firmy zapnuté (Nastavení firmy).', 409);
        }
    }

    private function requireEntity(int $supplierId, string $entity, int $entityId): void
    {
        if (!in_array($entity, DimensionDefaultRepository::ENTITIES, true)
            || !$this->defaults->ownsEntity($supplierId, $entity, $entityId)) {
            throw new DimensionException('not_found', match ($entity) {
                'project' => 'Zakázka nenalezena.',
                'product' => 'Skladová karta nenalezena.',
                'product_category' => 'Kategorie nenalezena.',
                'bank_account' => 'Bankovní účet nenalezen.',
                default => 'Klient nenalezen.',
            }, 404);
        }
    }

    public function requireDocument(int $supplierId, string $docType, int $docId): void
    {
        if (!isset(self::DOCUMENTS[$docType])) {
            throw new DimensionException('not_found', 'Neznámý typ dokladu.', 404);
        }
        $table = self::DOCUMENTS[$docType][0];
        if ($table === null) {
            // Výpis nemusí mít supplier_id vyplněné (starší import, avízo) a firmě
            // patří přes číslo účtu. Vlastnictví proto rozhoduje týž resolver jako
            // detail výpisu, jinak by se dimenze pohybu nedaly načíst ani uložit.
            $stmt = $this->db->pdo()->prepare(
                'SELECT 1 FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id
                  WHERE bt.id = ? AND ' . BankStatementOwnershipResolver::sql()
            );
            $stmt->execute([$docId, ...BankStatementOwnershipResolver::params($supplierId)]);
        } else {
            $stmt = $this->db->pdo()->prepare("SELECT 1 FROM {$table} WHERE id = ? AND supplier_id = ?");
            $stmt->execute([$docId, $supplierId]);
        }
        if ($stmt->fetchColumn() === false) {
            throw new DimensionException('not_found', 'Doklad nenalezen.', 404);
        }
    }

    /**
     * Zvaliduje a připraví pole hodnoty (create i update).
     *
     * @param array<string,mixed> $type
     * @param array<string,mixed> $body
     * @param array<string,mixed>|null $current
     * @return array<string,mixed>
     */
    private function valueData(int $supplierId, array $type, array $body, ?array $current): array
    {
        $data = [];
        if (array_key_exists('name', $body) || $current === null) {
            $name = trim((string) ($body['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 190) {
                throw new DimensionException('validation_failed', 'Název hodnoty musí mít 1–190 znaků.');
            }
            $data['name'] = $name;
        }
        if (array_key_exists('is_active', $body)) {
            $data['is_active'] = (bool) $body['is_active'];
        }
        if (array_key_exists('parent_id', $body)) {
            $parentId = $body['parent_id'] !== null && (int) $body['parent_id'] > 0 ? (int) $body['parent_id'] : null;
            if ($parentId !== null) {
                $parent = $this->repo->findValue($supplierId, $parentId);
                if ($parent === null || $parent['type_id'] !== (int) $type['id']) {
                    throw new DimensionException('invalid_parent', 'Nadřízená hodnota musí patřit ke stejnému typu.', 400);
                }
                if ($current !== null && in_array($parentId, $this->repo->descendantIds($supplierId, (int) $current['id']), true)) {
                    throw new DimensionException('invalid_parent', 'Hodnotu nelze přesunout pod sebe ani pod svou podřízenou.', 400);
                }
            }
            $data['parent_id'] = $parentId;
        }
        foreach (['responsible_note' => 190, 'note' => 500] as $field => $max) {
            if (array_key_exists($field, $body)) {
                $text = trim((string) ($body[$field] ?? ''));
                if (mb_strlen($text) > $max) {
                    throw new DimensionException('validation_failed', "Pole {$field} je delší než {$max} znaků.");
                }
                $data[$field] = $text === '' ? null : $text;
            }
        }
        if (array_key_exists('sort_order', $body)) {
            $data['sort_order'] = (int) $body['sort_order'];
        }
        if (array_key_exists('responsible_user_id', $body)) {
            $userId = (int) ($body['responsible_user_id'] ?? 0);
            if ($userId > 0 && !in_array($userId, array_column($this->responsibleCandidates($supplierId), 'id'), true)) {
                throw new DimensionException('invalid_reference', 'Odpovědná osoba musí být uživatelem firmy.', 400);
            }
            $data['responsible_user_id'] = $userId > 0 ? $userId : null;
        }
        // Vazby na vůz, zakázku a středisko jsou firemní záznamy — globální hodnota
        // (sdílená skupinou) je nést nemůže.
        $links = [
            'car_id' => 'SELECT 1 FROM cars WHERE id = ? AND supplier_id = ?',
            'project_id' => 'SELECT 1 FROM projects p JOIN clients c ON c.id = p.client_id WHERE p.id = ? AND c.supplier_id = ?',
            'cost_center_id' => 'SELECT 1 FROM cost_centers WHERE id = ? AND supplier_id = ?',
        ];
        foreach ($links as $field => $sql) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $id = (int) ($body[$field] ?? 0);
            if ($id > 0) {
                if ($type['level'] === 'global') {
                    throw new DimensionException('invalid_reference', 'Globální hodnota nemůže odkazovat na záznam jedné firmy.', 400);
                }
                $stmt = $this->db->pdo()->prepare($sql);
                $stmt->execute([$id, $supplierId]);
                if ($stmt->fetchColumn() === false) {
                    throw new DimensionException('invalid_reference', 'Odkazovaný záznam nepatří firmě.', 400);
                }
            }
            $data[$field] = $id > 0 ? $id : null;
        }
        return $data;
    }

    private static function explicitOrigins(array $values, string $reference): array
    {
        return array_fill_keys(array_keys($values), ['origin' => 'explicit', 'ref' => $reference]);
    }

    private function costCenterFor(int $supplierId, string $code, string $name): int
    {
        $existing = $this->costCenters->findByCode($supplierId, $code);
        return $existing !== null ? (int) $existing['id'] : $this->costCenters->create($supplierId, $code, $name);
    }
}
