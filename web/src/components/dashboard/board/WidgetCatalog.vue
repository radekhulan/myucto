<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { WidgetDefinition } from '@/dashboard/types'
const props = defineProps<{ definitions: WidgetDefinition[]; disabled?: boolean }>()
const emit = defineEmits<{ add: [definition: WidgetDefinition] }>()
const { t, locale } = useI18n()
const search = ref('')
const filtered = computed(() => props.definitions.filter(definition => `${t(definition.titleKey)} ${t(definition.descriptionKey)}`.toLocaleLowerCase().includes(search.value.toLocaleLowerCase())))
</script>
<template>
  <details class="widget-catalog space-y-3" data-widget-catalog :lang="locale">
    <summary class="cursor-pointer text-sm font-medium text-primary-600">{{ t('dashboardBoard.add') }}</summary>
    <input v-model="search" type="search" :aria-label="t('common.search')" :placeholder="t('common.search')" class="w-full border border-neutral-300 rounded-md bg-surface px-3 py-2 text-sm" />
    <div class="widget-catalog-grid" data-widget-catalog-grid>
      <div v-for="definition in filtered" :key="definition.type" :data-widget-catalog-item="definition.type" class="widget-catalog-item rounded-lg border border-neutral-200 flex flex-col min-w-0">
        <h3 class="widget-catalog-title font-medium">{{ t(definition.titleKey) }}</h3>
        <p class="widget-catalog-description text-neutral-500 flex-1">{{ t(definition.descriptionKey) }}</p>
        <button type="button" :disabled="disabled" class="inline-flex items-center justify-center gap-1.5 rounded-md border px-2.5 py-1.5 text-sm font-medium whitespace-nowrap disabled:opacity-50 disabled:cursor-not-allowed bg-primary-600 border-primary-600 text-white hover:bg-primary-700 self-start" @click="emit('add',definition)"><span aria-hidden="true">＋</span>{{ t('dashboardBoard.add') }}</button>
      </div>
    </div>
  </details>
</template>
<style scoped>
.widget-catalog { container: dashboard-catalog / inline-size; }
.widget-catalog-grid {
  --catalog-card-min: 15rem;
  --catalog-gap: .75rem;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, var(--catalog-card-min)), 1fr));
  gap: var(--catalog-gap);
}
.widget-catalog-item { padding: .75rem; gap: .5rem; }
.widget-catalog-title, .widget-catalog-description { min-width: 0; overflow-wrap: anywhere; hyphens: manual; }
.widget-catalog-title { font-size: 14px; line-height: 1.4; text-wrap: balance; }
.widget-catalog-description { font-size: 12px; line-height: 1.5; text-wrap: pretty; }
/* Šest karet po 15 rem a pět mezer po .75 rem; širší katalog už nepřidává sloupce. */
@container dashboard-catalog (min-width: 93.75rem) {
  .widget-catalog-grid { grid-template-columns: repeat(6, minmax(0, 1fr)); }
}
</style>
