<?php

declare(strict_types=1);

namespace MyInvoice\Service\Dashboard;

/** Uspořádání stávajících bloků a doplněných widgetů; datové volby určuje datový registr. */
final class DashboardLayoutRegistry
{
    public const MAX_ITEMS = 64;
    public const MAX_BYTES = 16384;
    private array $manifest;

    public function __construct(private readonly DashboardWidgetRegistry $widgets)
    {
        $this->manifest = json_decode(file_get_contents(dirname(__DIR__, 3) . '/resources/dashboard/layouts.json'), true, 16, JSON_THROW_ON_ERROR);
    }

    public function defaults(string $board): array
    {
        DashboardWidgetRegistry::assertBoard($board);
        return ['schemaVersion' => 2, 'items' => array_map(static fn (array $section): array => [
            'id' => 'section-' . $section['type'], 'kind' => 'section', 'type' => $section['type'],
            'span' => 12, 'height' => 'auto', 'options' => (object) [],
        ], $this->manifest['sections'][$board])];
    }

    /** Práva se ověřují při čtení dat; známá dočasně nedostupná položka zůstává uložená. */
    public function validate(string $board, mixed $layout): array
    {
        DashboardWidgetRegistry::assertBoard($board);
        if (!is_array($layout) || count($layout) !== 2 || array_diff(array_keys($layout), ['schemaVersion', 'items']) !== []
            || $layout['schemaVersion'] !== 2 || !is_array($layout['items']) || !array_is_list($layout['items'])
            || count($layout['items']) > self::MAX_ITEMS || strlen(json_encode($layout, JSON_THROW_ON_ERROR)) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Neplatná struktura nebo velikost rozložení.');
        }
        $ids = $sections = $normalized = [];
        $widgets = $dividers = $todos = 0;
        $knownSections = array_column($this->manifest['sections'][$board], 'type');
        foreach ($layout['items'] as $item) {
            if (!is_array($item) || count($item) !== 6
                || array_diff(array_keys($item), ['id', 'kind', 'type', 'span', 'height', 'options']) !== []
                || !is_string($item['id'] ?? null) || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}\z/D', $item['id']) !== 1
                || isset($ids[$item['id']]) || !is_string($item['type'] ?? null)
                || !is_int($item['span'] ?? null) || !is_string($item['height'] ?? null)) {
                throw new \InvalidArgumentException('Neplatná nebo duplicitní položka rozložení.');
            }
            $options = $item['options'] instanceof \stdClass ? (array) $item['options'] : $item['options'];
            switch ($item['kind']) {
                case 'section':
                    if (!in_array($item['type'], $knownSections, true) || isset($sections[$item['type']])
                        || $item['span'] !== 12 || $item['height'] !== 'auto' || $options !== []) {
                        throw new \InvalidArgumentException('Neznámý, duplicitní nebo změněný výchozí blok.');
                    }
                    $sections[$item['type']] = true;
                    break;
                case 'divider':
                    if ($item['type'] !== 'divider' || $item['span'] !== 12 || $item['height'] !== 'auto' || $options !== [] || ++$dividers > 16) {
                        throw new \InvalidArgumentException('Neplatný oddělovač.');
                    }
                    break;
                case 'widget':
                    if ($item['type'] === 'actions.todo' && ++$todos > 1) throw new \InvalidArgumentException('Úkoly lze přidat jen jednou.');
                    $geometry = $this->manifest['widgets'][$item['type']] ?? null;
                    if ($geometry === null || ++$widgets > DashboardWidgetRegistry::MAX_INSTANCES
                        || $item['span'] < $geometry['minSpan'] || $item['span'] > $geometry['maxSpan']
                        || !in_array($item['height'], $geometry['heights'], true)) {
                        throw new \InvalidArgumentException('Neplatný typ nebo rozměr widgetu.');
                    }
                    $query = $this->widgets->validateWidgets([['id' => $item['id'], 'type' => $item['type'], 'options' => $options]]);
                    $options = (array) $query[0]['options'];
                    break;
                default:
                    throw new \InvalidArgumentException('Neplatný druh položky rozložení.');
            }
            $ids[$item['id']] = true;
            $normalized[] = ['id' => $item['id'], 'kind' => $item['kind'], 'type' => $item['type'],
                'span' => $item['span'], 'height' => $item['height'], 'options' => (object) $options];
        }
        $result = ['schemaVersion' => 2, 'items' => $normalized];
        if (strlen(json_encode($result, JSON_THROW_ON_ERROR)) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Rozložení je příliš velké.');
        }
        return $result;
    }
}
