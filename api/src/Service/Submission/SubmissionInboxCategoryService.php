<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Submission\SubmissionInboxCategoryRepository;
use MyInvoice\Repository\Submission\SubmissionRecipientRepository;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;

/**
 * Zařazování příchozích zpráv datové schránky do kategorií.
 *
 * Pořadí rozhodnutí u jedné zprávy:
 *   1. ruční přeřazení (`manual`) — přepočet na něj nesahá,
 *   2. ruční pravidla: věc → schránka odesílatele → jméno odesílatele,
 *   3. automatická pravidla podle schránky odesílatele,
 *   4. rozpoznání {@see SubmissionInboxCategoryHeuristics}.
 *
 * Když rozpoznání stojí na odesílateli, založí se pro jeho schránku automatické
 * pravidlo. Další zprávy téhož odesílatele pak jdou stejnou cestou a uživatel
 * je může jedním přesměrováním pravidla poslat jinam.
 *
 * Zařazení existujících zpráv je idempotentní ({@see ensureCategorized()}):
 * zpracuje jen zprávy bez kategorie, takže ho lze volat při každém načtení
 * seznamu. Tím se dorovnají zprávy stažené před migrací 1982 i zprávy, jejichž
 * vlastní kategorie byla smazána.
 */
final readonly class SubmissionInboxCategoryService
{
    public const MAX_NAME_LENGTH = 100;
    public const MAX_PATTERN_LENGTH = 190;

    public function __construct(
        private SubmissionInboxCategoryRepository $categories,
        private SubmissionRecipientRepository $recipients,
        private Connection $db,
    ) {}

    public function isAvailable(): bool
    {
        return $this->categories->isAvailable();
    }

    /** Dorovná zprávy bez kategorie nebo směru. Vrací počet zařazených zpráv. */
    public function ensureCategorized(int $supplierId): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }
        $this->categories->ensureSystemCategories($supplierId, SubmissionInboxCategoryHeuristics::SYSTEM_CODES);
        $pending = $this->categories->messageFacts($supplierId, true);
        if ($pending === []) {
            return 0;
        }

        return $this->apply($supplierId, $pending, true);
    }

    /** Přepočítá zařazení všech zpráv, které uživatel nepřeřadil ručně. */
    public function recategorize(int $supplierId): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }
        $this->categories->ensureSystemCategories($supplierId, SubmissionInboxCategoryHeuristics::SYSTEM_CODES);

        return $this->apply($supplierId, $this->categories->messageFacts($supplierId, false), false);
    }

    /**
     * @return array{
     *   categories:list<array{id:int,code:?string,name:?string,sort_order:int,is_system:bool}>,
     *   rules:list<array<string,mixed>>
     * }
     */
    public function overview(int $supplierId): array
    {
        $this->ensureCategorized($supplierId);

        return [
            'categories' => $this->listCategories($supplierId),
            'rules' => $this->categories->listRules($supplierId),
        ];
    }

    /** @return list<array{id:int,code:?string,name:?string,sort_order:int,is_system:bool}> */
    public function listCategories(int $supplierId): array
    {
        return array_map(
            static fn (array $category): array => [...$category, 'is_system' => $category['code'] !== null],
            $this->categories->listCategories($supplierId),
        );
    }

    public function createCategory(int $supplierId, string $name): int
    {
        return $this->categories->createCategory($supplierId, $this->validName($name));
    }

    /** `null` u systémové kategorie vrátí výchozí (přeložený) název. */
    public function renameCategory(int $supplierId, int $id, ?string $name): void
    {
        $category = $this->requireCategory($supplierId, $id);
        $name = $name === null || trim($name) === '' ? null : $this->validName($name);
        if ($name === null && $category['code'] === null) {
            throw new SubmissionChannelException('validation_failed', 'Vlastní kategorie musí mít název.', 422);
        }
        $this->categories->renameCategory($supplierId, $id, $name);
    }

    public function deleteCategory(int $supplierId, int $id): void
    {
        $category = $this->requireCategory($supplierId, $id);
        if ($category['code'] !== null) {
            throw new SubmissionChannelException(
                'inbox_category_system',
                'Systémovou kategorii nelze smazat, jen přejmenovat.',
                409,
            );
        }
        $this->transactional(function () use ($supplierId, $id): void {
            $this->categories->deleteCustomCategory($supplierId, $id);
            $this->ensureCategorized($supplierId);
        });
    }

    public function createRule(int $supplierId, int $categoryId, string $field, string $pattern, ?int $userId): int
    {
        $this->requireCategory($supplierId, $categoryId);
        [$field, $pattern] = $this->validRule($field, $pattern);

        return $this->transactional(function () use ($supplierId, $categoryId, $field, $pattern, $userId): int {
            $id = $this->categories->upsertUserRule($supplierId, $categoryId, $field, $pattern, $userId);
            $this->recategorize($supplierId);
            return $id;
        });
    }

    public function updateRule(int $supplierId, int $ruleId, int $categoryId): void
    {
        $this->requireCategory($supplierId, $categoryId);
        if ($this->categories->findRule($supplierId, $ruleId) === null) {
            throw new SubmissionChannelException('not_found', 'Pravidlo nebylo nalezeno.', 404);
        }
        $this->transactional(function () use ($supplierId, $ruleId, $categoryId): void {
            $this->categories->updateRuleCategory($supplierId, $ruleId, $categoryId);
            $this->recategorize($supplierId);
        });
    }

    public function deleteRule(int $supplierId, int $ruleId): void
    {
        $this->transactional(function () use ($supplierId, $ruleId): void {
            if (!$this->categories->deleteRule($supplierId, $ruleId)) {
                throw new SubmissionChannelException('not_found', 'Pravidlo nebylo nalezeno.', 404);
            }
            $this->recategorize($supplierId);
        });
    }

    /**
     * Ruční přeřazení zprávy. S `$applyToSender` vznikne (nebo se přesměruje)
     * pravidlo pro schránku odesílatele a přepočítají se i ostatní jeho zprávy.
     */
    public function assignMessage(
        int $supplierId,
        int $messageId,
        int $categoryId,
        bool $applyToSender,
        ?string $senderBoxId,
        ?int $userId,
    ): void {
        $this->requireCategory($supplierId, $categoryId);
        $this->transactional(function () use ($supplierId, $messageId, $categoryId, $applyToSender, $senderBoxId, $userId): void {
            if (!$this->categories->assignManual($supplierId, $messageId, $categoryId)) {
                throw new SubmissionChannelException('not_found', 'Zpráva nebyla nalezena.', 404);
            }
            $box = strtolower(trim((string) $senderBoxId));
            if ($applyToSender && $box !== '') {
                $this->categories->upsertUserRule($supplierId, $categoryId, 'sender_box', $box, $userId);
                $this->recategorize($supplierId);
            }
        });
    }

    /**
     * @param list<array<string,mixed>> $messages
     */
    private function apply(int $supplierId, array $messages, bool $createAutoRules): int
    {
        $systemIds = $this->categories->systemCategoryIds($supplierId);
        $rules = $this->categories->listRules($supplierId);
        $ownBoxes = array_flip($this->categories->ownBoxIds($supplierId));
        $recipientKinds = $this->recipientKinds($supplierId);

        /** @var array<string,list<int>> $groups "categoryId|source|direction" → ids */
        $groups = [];
        foreach ($messages as $message) {
            $box = strtolower(trim((string) ($message['sender_box_id'] ?? '')));
            $facts = [
                ...$message,
                'own_box' => $box !== '' && isset($ownBoxes[$box]),
                'recipient_kind' => $box !== '' ? ($recipientKinds[$box] ?? null) : null,
            ];
            $direction = SubmissionInboxCategoryHeuristics::isOwn($facts) ? 'sent' : 'received';

            $keepManual = $message['category_source'] === 'manual' && $message['category_id'] !== null;
            if ($keepManual) {
                $categoryId = (int) $message['category_id'];
                $source = 'manual';
            } else {
                [$categoryId, $source] = $this->resolve($supplierId, $facts, $rules, $systemIds, $createAutoRules);
            }
            if ($categoryId === $message['category_id']
                && $source === $message['category_source']
                && $direction === $message['direction']
            ) {
                continue;
            }
            $groups[$categoryId . '|' . $source . '|' . $direction][] = (int) $message['id'];
        }

        $changed = 0;
        foreach ($groups as $key => $ids) {
            [$categoryId, $source, $direction] = explode('|', $key);
            $this->categories->assign($supplierId, $ids, (int) $categoryId, $source, $direction);
            $changed += count($ids);
        }
        return $changed;
    }

    /**
     * @param array<string,mixed> $facts
     * @param list<array{id:int,category_id:int,match_field:string,pattern:string,origin:string}> $rules
     * @param array<string,int> $systemIds
     * @param-out list<array{id:int,category_id:int,match_field:string,pattern:string,origin:string}> $rules
     * @return array{0:int,1:string}
     */
    private function resolve(int $supplierId, array $facts, array &$rules, array $systemIds, bool $createAutoRules): array
    {
        $rule = self::matchRule($facts, $rules);
        if ($rule !== null) {
            return [$rule['category_id'], $rule['origin'] === 'user' ? 'rule' : 'auto'];
        }

        $verdict = SubmissionInboxCategoryHeuristics::decide($facts);
        $categoryId = $systemIds[$verdict['code']] ?? $systemIds[SubmissionInboxCategoryHeuristics::OTHER];
        $box = strtolower(trim((string) ($facts['sender_box_id'] ?? '')));
        if ($createAutoRules && $verdict['basis'] === 'sender' && preg_match('/^[a-z0-9]{7}$/', $box) === 1) {
            $this->categories->insertAutoRule($supplierId, $categoryId, $box);
            $rules[] = [
                'id' => 0,
                'category_id' => $categoryId,
                'match_field' => 'sender_box',
                'pattern' => $box,
                'origin' => 'auto',
            ];
        }

        return [$categoryId, 'auto'];
    }

    /**
     * Ruční pravidla před automatickými; v rámci původu věc → schránka →
     * jméno a delší (konkrétnější) vzor před kratším.
     *
     * @param array<string,mixed> $facts
     * @param list<array{id:int,category_id:int,match_field:string,pattern:string,origin:string}> $rules
     * @return array{id:int,category_id:int,match_field:string,pattern:string,origin:string}|null
     */
    public static function matchRule(array $facts, array $rules): ?array
    {
        $fieldOrder = ['subject' => 0, 'sender_box' => 1, 'sender_name' => 2];
        usort($rules, static function (array $a, array $b) use ($fieldOrder): int {
            return [$a['origin'] === 'user' ? 0 : 1, $fieldOrder[$a['match_field']] ?? 9, -mb_strlen($a['pattern']), $a['id']]
                <=> [$b['origin'] === 'user' ? 0 : 1, $fieldOrder[$b['match_field']] ?? 9, -mb_strlen($b['pattern']), $b['id']];
        });
        $box = strtolower(trim((string) ($facts['sender_box_id'] ?? '')));
        $name = SubmissionInboxCategoryHeuristics::fold((string) ($facts['sender_name'] ?? ''));
        $subject = SubmissionInboxCategoryHeuristics::fold((string) ($facts['subject'] ?? ''));
        foreach ($rules as $rule) {
            $pattern = SubmissionInboxCategoryHeuristics::fold($rule['pattern']);
            $hit = match ($rule['match_field']) {
                'sender_box' => $box !== '' && $box === strtolower($rule['pattern']),
                'sender_name' => $pattern !== '' && str_contains($name, $pattern),
                'subject' => $pattern !== '' && str_contains($subject, $pattern),
                default => false,
            };
            if ($hit) {
                return $rule;
            }
        }
        return null;
    }

    /** @return array<string,string> boxId → druh adresáta */
    private function recipientKinds(int $supplierId): array
    {
        if (!$this->recipients->isAvailable()) {
            return [];
        }
        $map = [];
        foreach ($this->recipients->listVisible($supplierId) as $recipient) {
            $box = $recipient['isds_box_id'] ?? null;
            if (is_string($box) && $box !== '') {
                $map[strtolower($box)] = (string) $recipient['kind'];
            }
        }
        return $map;
    }

    /** @return array{id:int,code:?string,name:?string,sort_order:int} */
    private function requireCategory(int $supplierId, int $id): array
    {
        $category = $id > 0 ? $this->categories->findCategory($supplierId, $id) : null;
        if ($category === null) {
            throw new SubmissionChannelException('inbox_category_not_found', 'Kategorie nebyla nalezena.', 404);
        }
        return $category;
    }

    private function validName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new SubmissionChannelException(
                'validation_failed',
                'Název kategorie musí mít 1 až ' . self::MAX_NAME_LENGTH . ' znaků.',
                422,
            );
        }
        return $name;
    }

    /** @return array{0:string,1:string} */
    private function validRule(string $field, string $pattern): array
    {
        if (!in_array($field, SubmissionInboxCategoryRepository::MATCH_FIELDS, true)) {
            throw new SubmissionChannelException('validation_failed', 'Neznámý typ pravidla.', 422);
        }
        $pattern = trim(preg_replace('/\s+/u', ' ', $pattern) ?? '');
        if ($field === 'sender_box') {
            $pattern = strtolower($pattern);
            if (preg_match('/^[a-z0-9]{7}$/', $pattern) !== 1) {
                throw new SubmissionChannelException(
                    'validation_failed',
                    'ID datové schránky má 7 znaků (písmena a číslice).',
                    422,
                );
            }
        } elseif (mb_strlen($pattern) < 2 || mb_strlen($pattern) > self::MAX_PATTERN_LENGTH) {
            throw new SubmissionChannelException(
                'validation_failed',
                'Hledaný text pravidla musí mít 2 až ' . self::MAX_PATTERN_LENGTH . ' znaků.',
                422,
            );
        }
        return [$field, $pattern];
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    private function transactional(callable $work): mixed
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            return $work();
        }
        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
