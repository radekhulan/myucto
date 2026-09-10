<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { CatalogImportPreset } from '@/api/catalogImport'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'

const props = withDefaults(defineProps<{
  presets: CatalogImportPreset[]
  sourceFormat: 'csv' | 'xlsx'
  disabled?: boolean
}>(), {
  disabled: false,
})

const emit = defineEmits<{
  apply: [preset: CatalogImportPreset]
}>()

const { t } = useI18n()
const compatiblePresets = computed(() => props.presets.filter(preset => preset.format === props.sourceFormat))

function titleKey(preset: CatalogImportPreset): string {
  return preset.system === 'abra_flexi'
    ? 'eshop.import2.preset_abra_title'
    : 'eshop.import2.preset_pohoda_title'
}

function descriptionKey(preset: CatalogImportPreset): string {
  return preset.system === 'abra_flexi'
    ? 'eshop.import2.preset_abra_description'
    : 'eshop.import2.preset_pohoda_description'
}
</script>

<template>
  <section v-if="compatiblePresets.length" aria-labelledby="catalog-import-presets-title">
    <h2 id="catalog-import-presets-title" class="font-semibold text-neutral-900">
      {{ t('eshop.import2.presets_title') }}
    </h2>
    <p class="mt-0.5 text-xs text-neutral-500">{{ t('eshop.import2.presets_hint') }}</p>

    <div class="mt-3 grid min-w-0 grid-cols-1 gap-3 md:grid-cols-2">
      <article
        v-for="preset in compatiblePresets"
        :key="preset.id"
        class="min-w-0 rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm"
        :data-test="`catalog-import-preset-${preset.id}`"
      >
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div>
            <h3 class="text-sm font-semibold text-neutral-900">{{ t(titleKey(preset)) }}</h3>
            <p class="mt-1 text-xs leading-relaxed text-neutral-600">{{ t(descriptionKey(preset)) }}</p>
          </div>
          <span class="rounded-full bg-neutral-100 px-2 py-1 text-xs font-medium uppercase text-neutral-700">
            {{ preset.format }}
          </span>
        </div>

        <dl class="mt-3 space-y-1.5 text-xs">
          <div v-for="column in preset.supported_columns" :key="column.target" class="flex min-w-0 items-center gap-2">
            <dt class="min-w-0 flex-1 truncate font-mono text-neutral-700">{{ column.source }}</dt>
            <span aria-hidden="true" class="shrink-0 text-neutral-400">→</span>
            <dd class="min-w-0 flex-1 truncate text-neutral-600">{{ t(`eshop.import2.fields.${column.target}`) }}</dd>
          </div>
        </dl>

        <p class="mt-3 text-xs leading-relaxed text-neutral-600">
          {{ t('eshop.import2.preset_schema_notice') }}
        </p>

        <div class="mt-4 flex flex-wrap items-center gap-2">
          <button
            type="button"
            :class="btnOutline('primary')"
            :disabled="disabled"
            :data-test="`apply-preset-${preset.id}`"
            @click="emit('apply', preset)"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.check" />
            </svg>
            {{ t('eshop.import2.preset_apply') }}
          </button>
          <a
            :href="preset.documentation_url"
            target="_blank"
            rel="noopener noreferrer"
            class="inline-flex items-center gap-1.5 whitespace-nowrap text-xs font-medium text-primary-700 hover:underline"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.link" />
            </svg>
            {{ t('eshop.import2.preset_documentation') }}
          </a>
        </div>
        <p class="mt-2 text-xs text-neutral-500">{{ t('eshop.import2.preset_manual_mapping') }}</p>
      </article>
    </div>
  </section>
</template>
