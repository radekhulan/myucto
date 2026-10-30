<?php

declare(strict_types=1);

namespace MyInvoice\Service\Dashboard;

use InvalidArgumentException;

/** Sdílený manifest je jediný zdroj typů, rozměrů a nastavení obou ploch. */
final class DashboardWidgetRegistry
{
    public const MAX_INSTANCES = 64;
    public const MAX_DATA_WIDGETS = 32;
    public const MAX_DIVIDERS = 32;
    public const MAX_BYTES = 16384;

    private array $widgets = [];
    private array $presets;

    public function __construct()
    {
        $shared = dirname(__DIR__, 4) . '/web/shared/';
        $manifest = json_decode(file_get_contents($shared . 'dashboard-widgets.json'), true, 16, JSON_THROW_ON_ERROR);
        $this->presets = json_decode(file_get_contents($shared . 'dashboard-presets.json'), true, 16, JSON_THROW_ON_ERROR);
        foreach ($manifest['widgets'] as $widget) {
            if (isset($this->widgets[$widget['type']])) {
                throw new \LogicException('Duplicitní typ dashboardového widgetu.');
            }
            if (!is_int($widget['minSpan'] ?? null) || !is_int($widget['maxSpan'] ?? null)
                || !is_int($widget['defaultSpan'] ?? null) || $widget['minSpan'] < 1
                || $widget['maxSpan'] > 12 || $widget['minSpan'] > $widget['defaultSpan']
                || $widget['defaultSpan'] > $widget['maxSpan']) {
                throw new \LogicException('Neplatné hranice šířky dashboardového widgetu.');
            }
            // Katalog i validace používají stejnou souvislou řadu sloupců.
            $widget['spans'] = range($widget['minSpan'], $widget['maxSpan']);
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

    public function defaultLayout(string $board): array
    {
        self::assertBoard($board);
        return $this->validate($this->presets['presets'][$board . '-v1']);
    }

    public static function assertBoard(string $board): void
    {
        if (!in_array($board, ['home', 'portal'], true)) {
            throw new InvalidArgumentException('Neplatná plocha přehledu.');
        }
    }

    /** Validuje strukturu nezávisle na právech: dočasně skryté widgety nesmí zmizet. */
    public function validate(mixed $layout): array
    {
        try {
            $bytes = strlen(json_encode($layout, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            throw new InvalidArgumentException('Neplatná konfigurace přehledu.');
        }
        if ($bytes > self::MAX_BYTES || !is_array($layout)
            || !$this->keys($layout, ['schemaVersion', 'presetVersion', 'widgets'])
            || ($layout['schemaVersion'] ?? null) !== 1
            || ($layout['presetVersion'] ?? null) !== 1
            || !is_array($layout['widgets'] ?? null) || !array_is_list($layout['widgets'])
            || count($layout['widgets']) > self::MAX_INSTANCES
        ) {
            throw new InvalidArgumentException('Neplatná verze, velikost nebo struktura konfigurace přehledu.');
        }

        $ids = $counts = $instances = [];
        $dataCount = $dividerCount = 0;
        foreach ($layout['widgets'] as $instance) {
            if (!is_array($instance) || !$this->keys(array_diff_key($instance, ['height' => true]), ['id', 'type', 'span', 'options'])
                || !is_string($instance['id'] ?? null)
                || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}\z/D', $instance['id']) !== 1
                || isset($ids[$instance['id']]) || !is_string($instance['type'] ?? null)
            ) {
                throw new InvalidArgumentException('Neplatný nebo duplicitní identifikátor widgetu.');
            }
            $widget = $this->get($instance['type']);
            if ($widget === null || !is_int($instance['span'] ?? null)
                || !in_array($instance['span'], $widget['spans'], true)
                || (array_key_exists('height', $instance) && (!is_string($instance['height'])
                    || !in_array($instance['height'], $widget['heights'], true)))
                || (!($widget['repeatable']) && isset($counts[$instance['type']]))
            ) {
                throw new InvalidArgumentException('Neznámý widget, nepodporovaná šířka nebo opakování widgetu.');
            }
            if ($instance['type'] === 'layout.divider') {
                ++$dividerCount;
            } else {
                ++$dataCount;
            }
            if ($dataCount > self::MAX_DATA_WIDGETS || $dividerCount > self::MAX_DIVIDERS) {
                throw new InvalidArgumentException('Překročen počet widgetů nebo oddělovačů přehledu.');
            }
            $options = $instance['options'] ?? null;
            if ($options instanceof \stdClass) $options = (array) $options;
            if (!is_array($options) || ($options !== [] && array_is_list($options))
                || array_diff(array_keys($options), array_keys($widget['options'])) !== []
            ) {
                throw new InvalidArgumentException('Nepodporované nastavení widgetu.');
            }
            $normalized = [];
            foreach ($widget['options'] as $key => $schema) {
                $value = $options[$key] ?? $schema['default'];
                // NULL není chybějící hodnota; také musí projít konkrétním typem.
                if (array_key_exists($key, $options)) $value = $options[$key];
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
            $counts[$instance['type']] = true;
            $instances[] = ['id' => $instance['id'], 'type' => $instance['type'],
                'span' => $instance['span'], 'height' => $instance['height'] ?? 'auto', 'options' => (object) $normalized];
        }
        $result = ['schemaVersion' => 1, 'presetVersion' => 1, 'widgets' => $instances];
        if (strlen(json_encode($result, JSON_THROW_ON_ERROR)) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Konfigurace přehledu je příliš velká.');
        }
        return $result;
    }

    private function keys(array $value, array $keys): bool
    {
        return count($value) === count($keys) && array_diff(array_keys($value), $keys) === [];
    }
}
