<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { accrualsApi, type AccrualClosingHandover, type AccrualDocumentSourceType, type AccrualSourceCandidates } from '@/api/accruals'
import { useAuthStore } from '@/stores/auth'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { accrualStatusClass } from '@/utils/accrualStatus'
import { accrualPrefillQuery } from '@/utils/accrualSource'
import { ICONS, btnOutlineSm } from '@/components/ui/buttonStyles'

/**
 * Panel „Časové rozlišení" v detailu dokladu: řádky dokladu na účtech 381/384 a položky
 * agendy, které z dokladu vznikly. Rodič ho vykreslí jen u podvojného účetnictví a s právem
 * `accounting.accruals`; bez řádků i položek se panel nezobrazí vůbec.
 */
const props = defineProps<{
  sourceType: AccrualDocumentSourceType
  sourceId: number
}>()

const { t } = useI18n()
const auth = useAuthStore()
const data = ref<AccrualSourceCandidates | null>(null)
const canWrite = computed(() => auth.canWrite('accounting.accruals.write'))
const handovers = computed(() => data.value?.closing_deferrals ?? [])
const visible = computed(() => !!data.value && (data.value.accrual_lines.length > 0 || data.value.items.length > 0 || handovers.value.length > 0))

let request = 0
async function load() {
  const current = ++request
  if (!props.sourceId) {
    data.value = null
    return
  }
  try {
    const result = await accrualsApi.sourceCandidates(props.sourceType, props.sourceId)
    if (current === request) data.value = result
  } catch {
    if (current === request) data.value = null
  }
}

/** Předvyplnění editoru: řádek dokladu na 38x (zápis vzniku) a období z řádku dokladu. */
function createQuery(line?: AccrualSourceCandidates['accrual_lines'][number]) {
  const source = data.value
  if (!source) return {}
  const period = source.periods.find(p => !p.item_id) ?? source.periods[0]
  // Řádek dokladu, který položka spravuje, vybere editor (návrh podle shody částky a období).
  return accrualPrefillQuery({
    source_type: source.source_type,
    source_id: source.source_id,
    ...(line ? { origin_entry_id: line.entry_id, origin_amount: Math.abs(Number(line.amount)), account: line.account_code, amount: Math.abs(Number(line.amount)) } : {}),
    from: period?.from,
    to: period?.to,
    partner_id: source.partner_id ?? undefined,
    partner_name: source.partner_name ?? undefined,
    title: source.title ?? source.document_no ?? undefined,
  })
}

/** Předání řádku odloženého uzávěrkou do agendy: zůstatek k datu, plán od data, navázání odkladu. */
function handoverQuery(h: AccrualClosingHandover) {
  const source = data.value
  if (!source) return {}
  return accrualPrefillQuery({
    source_type: source.source_type,
    source_id: source.source_id,
    source_item_id: h.source_item_id ?? undefined,
    closing_handover: 1,
    account: h.account_code,
    amount: h.basis,
    from: h.period_from ?? undefined,
    to: h.period_to ?? undefined,
    partner_id: source.partner_id ?? undefined,
    partner_name: source.partner_name ?? undefined,
    title: source.title ?? source.document_no ?? undefined,
  })
}

watch(() => [props.sourceType, props.sourceId], () => void load())
onMounted(load)
defineExpose({ reload: load })
</script>

<template>
  <section v-if="visible && data" class="overflow-hidden rounded-lg border border-neutral-200 bg-surface shadow-sm" data-test="accrual-source-panel">
    <header class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-200 px-5 py-3">
      <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('accruals.panel.title') }}</h3>
      <RouterLink v-if="canWrite && !data.accrual_lines.length && !handovers.length" :to="{ name: 'accounting-accrual-new', query: createQuery() }"
        :class="[btnOutlineSm('primary'), 'whitespace-nowrap']" data-test="accrual-panel-create">
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('accruals.panel.create') }}
      </RouterLink>
    </header>
    <div class="space-y-3 px-5 py-3 text-sm">
      <ul v-if="data.items.length" class="space-y-1" data-test="accrual-panel-items">
        <li v-for="entry in data.items" :key="entry.id" class="flex flex-wrap items-center gap-2">
          <RouterLink :to="{ name: 'accounting-accrual-detail', params: { id: entry.id } }" class="font-mono text-primary-600 hover:underline">{{ entry.item_no }}</RouterLink>
          <span>{{ entry.title }}</span>
          <span class="rounded px-2 py-0.5 text-xs" :class="accrualStatusClass(entry.status)">{{ t(`accruals.status.${entry.status}`) }}</span>
        </li>
      </ul>
      <div v-if="handovers.length" data-test="accrual-panel-handovers">
        <p class="mb-1 text-xs text-neutral-500">{{ t('accruals.panel.handover_hint') }}</p>
        <ul class="divide-y divide-neutral-100 rounded-md border border-neutral-200">
          <li v-for="h in handovers" :key="`${h.deferral_entry_id}-${h.source_item_id ?? 0}`" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
            <span>
              <span class="font-mono">{{ h.account_code }}</span>
              <span class="ml-2 text-xs text-neutral-500">{{ t('accruals.panel.handover_as_of', { date: formatDate(h.opening_as_of) }) }}</span>
            </span>
            <span class="flex flex-wrap items-center gap-2">
              <span class="font-mono">{{ formatMoney(h.remaining) }}</span>
              <RouterLink v-if="canWrite" :to="{ name: 'accounting-accrual-new', query: handoverQuery(h) }"
                :class="[btnOutlineSm('primary'), 'whitespace-nowrap']" data-test="accrual-panel-handover">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
                {{ t('accruals.panel.handover') }}
              </RouterLink>
            </span>
          </li>
        </ul>
      </div>
      <div v-if="data.accrual_lines.length" data-test="accrual-panel-lines">
        <p class="mb-1 text-xs text-neutral-500">{{ t(data.items.length ? 'accruals.panel.lines_hint' : 'accruals.panel.lines_hint_none') }}</p>
        <ul class="divide-y divide-neutral-100 rounded-md border border-neutral-200">
          <li v-for="line in data.accrual_lines" :key="`${line.entry_id}-${line.account_code}`" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
            <span>
              <span class="font-mono">{{ line.account_code }}</span>
              <span class="ml-1 text-neutral-600">{{ line.account_name }}</span>
              <span class="ml-2 text-xs text-neutral-500">{{ formatDate(line.entry_date) }}</span>
            </span>
            <span class="flex flex-wrap items-center gap-2">
              <span class="font-mono">{{ formatMoney(line.amount) }}</span>
              <RouterLink v-if="canWrite" :to="{ name: 'accounting-accrual-new', query: createQuery(line) }"
                :class="[btnOutlineSm('primary'), 'whitespace-nowrap']" data-test="accrual-panel-create-line">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
                {{ t('accruals.panel.create') }}
              </RouterLink>
            </span>
          </li>
        </ul>
      </div>
    </div>
  </section>
</template>
