<?php

declare(strict_types=1);

namespace MyInvoice\Service\Dashboard;

use InvalidArgumentException;

/** Registr čtecích poskytovatelů; neobsahuje rozložení ani stav editoru. */
final class DashboardWidgetRegistry
{
    public const MAX_INSTANCES = 32;
    public const MAX_BYTES = 16384;

    private array $widgets = [];

    public function __construct()
    {
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 3) . '/resources/dashboard/widgets.json'), true, 16, JSON_THROW_ON_ERROR);
        foreach ($manifest['widgets'] as $widget) {
            if (isset($this->widgets[$widget['type']])) {
                throw new \LogicException('Duplicitní typ dashboardového widgetu.');
            }
            $this->widgets[$widget['type']] = $widget;
        }
    }

    public function all(): array
    {
        return array_values($this->widgets);
    }

    public function get(string $type): ?array
    {
        return $this->widgets[$type] ?? null;
    }

    public static function assertBoard(string $board): void
    {
        if (!in_array($board, ['home', 'portal'], true)) {
            throw new InvalidArgumentException('Neplatná plocha přehledu.');
        }
    }

    /** Typy a volby validujeme před dotazy; identifikátor slouží jen ke spárování odpovědi. */
    public function validateWidgets(mixed $widgets): array
    {
        try {
            $bytes = strlen(json_encode($widgets, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            throw new InvalidArgumentException('Neplatný požadavek přehledu.');
        }
        if ($bytes > self::MAX_BYTES || !is_array($widgets) || !array_is_list($widgets)
            || count($widgets) > self::MAX_INSTANCES) {
            throw new InvalidArgumentException('Neplatná velikost nebo struktura požadavku přehledu.');
        }
        $ids = $instances = [];
        foreach ($widgets as $instance) {
            if (!is_array($instance) || count($instance) !== 3
                || array_diff(array_keys($instance), ['id', 'type', 'options']) !== []
                || !is_string($instance['id'] ?? null)
                || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}\z/D', $instance['id']) !== 1
                || isset($ids[$instance['id']]) || !is_string($instance['type'] ?? null)
                || ($widget = $this->get($instance['type'])) === null) {
                throw new InvalidArgumentException('Neplatný nebo duplicitní identifikátor nebo typ widgetu.');
            }
            $options = $instance['options'] ?? null;
            if ($options instanceof \stdClass) $options = (array) $options;
            if (!is_array($options) || ($options !== [] && array_is_list($options))
                || array_diff(array_keys($options), array_keys($widget['options'])) !== []) {
                throw new InvalidArgumentException('Nepodporované nastavení widgetu.');
            }
            $normalized = [];
            foreach ($widget['options'] as $key => $schema) {
                $value = array_key_exists($key, $options) ? $options[$key] : $schema['default'];
                $valid = match ($schema['type']) {
                    'enum' => in_array($value, $schema['values'], true),
                    'string' => is_string($value) && strlen($value) <= 32
                        && preg_match('~' . $schema['pattern'] . '~D', $value) === 1,
                    default => false,
                };
                if (!$valid) throw new InvalidArgumentException('Neplatná hodnota nastavení widgetu.');
                $normalized[$key] = $value;
            }
            $ids[$instance['id']] = true;
            $instances[] = ['id' => $instance['id'], 'type' => $instance['type'], 'options' => (object) $normalized];
        }
        return $instances;
    }
}
