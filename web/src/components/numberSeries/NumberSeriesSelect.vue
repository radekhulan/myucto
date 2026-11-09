<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { numberSeriesApi, type NumberSeriesOptions, type NumberSeriesScope } from '@/api/numberSeries'
import { numberSeriesLabel } from '@/utils/numberSeries'

/**
 * Výběr číselné řady v hlavičce dokladu. Ukáže se jen tehdy, když má druh dokladu
 * víc řad; firma s jedinou řadou editor nevidí změněný. `modelValue` null = bez volby
 * (při vystavení výchozí řada druhu, pokladny nebo uživatele) — výchozí řadu ukazuje
 * jako předvybranou, ale do dokladu ji nezapisuje, dokud ji uživatel nezmění.
 */
const props = defineProps<{
  scope: NumberSeriesScope
  modelValue: number | null | undefined
  disabled?: boolean
}>()
const emit = defineEmits<{ (e: 'update:modelValue', v: number | null): void }>()

const { t } = useI18n()
const options = ref<NumberSeriesOptions | null>(null)

const scopeKey = computed(() => JSON.stringify(props.scope))

async function load() {
  try {
    options.value = await numberSeriesApi.options(props.scope)
  } catch {
    options.value = null
  }
}
watch(scopeKey, load, { immediate: true })

const selected = computed<number | null>(() => props.modelValue ?? options.value?.default_id ?? null)

function onChange(e: Event) {
  const v = Number((e.target as HTMLSelectElement).value)
  emit('update:modelValue', v > 0 ? v : null)
}
</script>

<template>
  <div v-if="options?.multiple">
    <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('number_series.select_label') }}</label>
    <select :value="selected ?? ''" :disabled="disabled" @change="onChange"
      class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface disabled:bg-neutral-50">
      <option v-for="s in options.series" :key="s.id" :value="s.id">
        {{ numberSeriesLabel(t, { doc_kind: options.kind, name: s.name, label: s.label, storage: s.storage }) }}
        ({{ s.template.replace('{PREFIX}', s.prefix ?? '') }})
      </option>
    </select>
  </div>
</template>
