<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Submission;

use MyInvoice\Support\PeriodFilter;

/**
 * Filtry, hledání, řazení a stránka seznamu příchozích zpráv datové schránky.
 *
 * Hodnoty se čtou z query stringu, který UI drží v URL. Neznámou hodnotu
 * odmítne {@see fromQuery()} výjimkou, ne tichým ignorováním: filtr, který
 * se neuplatní, by vypadal jako prázdný výsledek.
 */
final readonly class SubmissionInboxListQuery
{
    public const SORTS = ['delivered', 'sender', 'subject', 'category'];
    public const CLASSIFICATIONS = [
        'delivery_receipt', 'cssz_protocol', 'health_insurer_response', 'tax_office_response', 'unclassified',
    ];
    public const MAX_SEARCH_LENGTH = 200;

    public function __construct(
        public string $visibility = 'active',
        public ?string $classification = null,
        public ?PeriodFilter $period = null,
        public ?string $search = null,
        public ?int $categoryId = null,
        public ?string $senderBoxId = null,
        public ?string $direction = null,
        public ?bool $read = null,
        public ?bool $hasAttachments = null,
        public string $sort = 'delivered',
        public string $order = 'desc',
        public int $limit = SubmissionInboxRepository::LIST_DEFAULT_LIMIT,
        public int $offset = 0,
    ) {}

    /**
     * @param array<string,mixed> $params
     * @throws \InvalidArgumentException
     */
    public static function fromQuery(array $params): self
    {
        $text = static function (string $key) use ($params): ?string {
            $value = $params[$key] ?? null;
            if ($value === null || is_array($value)) {
                return null;
            }
            $value = trim((string) $value);
            return $value === '' ? null : $value;
        };

        $visibility = $text('visibility') ?? 'active';
        if (!in_array($visibility, ['active', 'hidden', 'all'], true)) {
            throw new \InvalidArgumentException('Neznámý pohled příchozích zpráv.');
        }
        $classification = $text('classification');
        if ($classification !== null && !in_array($classification, self::CLASSIFICATIONS, true)) {
            throw new \InvalidArgumentException('Neznámý typ zprávy.');
        }
        $search = $text('q');
        if ($search !== null && mb_strlen($search) > self::MAX_SEARCH_LENGTH) {
            throw new \InvalidArgumentException('Hledaný text je příliš dlouhý.');
        }
        $category = $text('category');
        if ($category !== null && preg_match('/^[1-9][0-9]{0,9}$/D', $category) !== 1) {
            throw new \InvalidArgumentException('Neplatná kategorie.');
        }
        $sender = $text('sender');
        if ($sender !== null) {
            $sender = strtolower($sender);
            if (preg_match('/^[a-z0-9]{7}$/D', $sender) !== 1) {
                throw new \InvalidArgumentException('Neplatné ID schránky odesílatele.');
            }
        }
        $direction = $text('direction');
        if ($direction !== null && !in_array($direction, ['received', 'sent'], true)) {
            throw new \InvalidArgumentException('Neznámý směr zprávy.');
        }
        $read = match ($text('read')) {
            null => null,
            'read' => true,
            'unread' => false,
            default => throw new \InvalidArgumentException('Neznámý stav přečtení.'),
        };
        $attachments = match ($text('attachments')) {
            null => null,
            '1', 'yes' => true,
            '0', 'no' => false,
            default => throw new \InvalidArgumentException('Neznámý filtr příloh.'),
        };
        $sort = $text('sort') ?? 'delivered';
        if (!in_array($sort, self::SORTS, true)) {
            throw new \InvalidArgumentException('Neznámé řazení.');
        }
        $order = $text('order') ?? ($sort === 'delivered' ? 'desc' : 'asc');
        if (!in_array($order, ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException('Neznámý směr řazení.');
        }

        return new self(
            visibility: $visibility,
            classification: $classification,
            period: PeriodFilter::fromQuery($params),
            search: $search,
            categoryId: $category !== null ? (int) $category : null,
            senderBoxId: $sender,
            direction: $direction,
            read: $read,
            hasAttachments: $attachments,
            sort: $sort,
            order: $order,
            limit: max(1, min(
                SubmissionInboxRepository::LIST_MAX_LIMIT,
                (int) ($params['limit'] ?? SubmissionInboxRepository::LIST_DEFAULT_LIMIT),
            )),
            offset: max(0, (int) ($params['offset'] ?? 0)),
        );
    }
}
