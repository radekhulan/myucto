<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatMoney } from '@/composables/useFormat'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'

/**
 * Tabulka dvojic jednoduchého účetního zápisu: číslo dvojice, účet MD, účet Dal,
 * částka a text. Sdílí ji detail zápisu v deníku a náhled zaúčtování dokladu
 * (bod 2), proto zná jen data dvojice, ne zápis.
 *
 * Sama nic neposílá. Výběr dvojic (`selectable`) a lišta akcí (`actions`, tedy
 * ActionBar se Storno / Přeúčtovat) jdou ven přes `v-model` a prop.
 */

export interface PairRow {
  pair_no: number
  debit_account_code: string
  credit_account_code: string
  debit_account_name?: string | null
  credit_account_name?: string | null
  amount: number
  is_red_storno?: boolean
  line_text?: string | null
  /** Zápis, který dvojici stornoval nebo přeúčtoval; dvojice je pak jen informativní. */
  reversed_by_entry_id?: number | null
}

const props = withDefaults(defineProps<{
  pairs: PairRow[]
  /** Zaškrtávátka pro výběr dvojic (storno / přeúčtování). */
  selectable?: boolean
  /** Vybraná čísla dvojic (v-model). */
  modelValue?: number[]
  /** Akce nad vybranými dvojicemi; ActionBar je ukáže jen, když nějaká je viditelná. */
  actions?: ActionItem[]
  /** Dvojice, které server nepovolí vybrat (např. už stornované). */
  disabledPairs?: number[]
  /** Měna součtu. */
  currency?: string
  /** Menší varianta do vnořeného panelu. */
  dense?: boolean
}>(), {
  selectable: false,
  modelValue: () => [],
  actions: () => [],
  disabledPairs: () => [],
  currency: 'CZK',
  dense: false,
})

const emit = defineEmits<{
  'update:modelValue': [number[]]
  'open-entry': [entryId: number]
}>()

const { t } = useI18n()

const total = computed(() =>
  Math.round(props.pairs.reduce((s, p) => s + (p.is_red_storno ? -1 : 1) * Number(p.amount || 0), 0) * 100) / 100)
const hasText = computed(() => props.pairs.some(p => (p.line_text ?? '') !== ''))
const selectableNos = computed(() =>
  props.pairs.filter(p => !props.disabledPairs.includes(p.pair_no) && !p.reversed_by_entry_id).map(p => p.pair_no))
const allSelected = computed(() =>
  selectableNos.value.length > 0 && selectableNos.value.every(n => props.modelValue.includes(n)))
const visibleActions = computed(() => props.actions.filter(a => a.show === undefined || !!a.show))

function isSelected(no: number): boolean { return props.modelValue.includes(no) }
function canSelect(p: PairRow): boolean {
  return props.selectable && !props.disabledPairs.includes(p.pair_no) && !p.reversed_by_entry_id
}
function toggle(no: number) {
  emit('update:modelValue', isSelected(no)
    ? props.modelValue.filter(n => n !== no)
    : [...props.modelValue, no].sort((a, b) => a - b))
}
function toggleAll() {
  emit('update:modelValue', allSelected.value ? [] : [...selectableNos.value])
}
function amountText(p: PairRow): string {
  return formatMoney(p.is_red_storno ? -Math.abs(p.amount) : p.amount, props.currency)
}
</script>

<template>
  <div class="bg-surface border border-neutral-200 rounded-lg overflow-hidden" data-test="journal-pairs-table">
    <div v-if="visibleActions.length || selectable" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 border-b border-neutral-100 bg-neutral-50">
      <span class="text-xs text-neutral-500">
        {{ selectable ? t('accounting.pairs.selected', { n: modelValue.length }) : t('accounting.pairs.title') }}
      </span>
      <ActionBar v-if="visibleActions.length" :actions="visibleActions" />
    </div>

    <!-- Desktop -->
    <div class="hidden md:block overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th v-if="selectable" class="px-3 py-2 w-8">
              <input type="checkbox" :checked="allSelected" :disabled="selectableNos.length === 0"
                :aria-label="t('accounting.pairs.select_all')" @change="toggleAll" />
            </th>
            <th class="px-3 py-2 text-left font-medium w-12">{{ t('accounting.pairs.col_no') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('accounting.pairs.col_debit') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('accounting.pairs.col_credit') }}</th>
            <th class="px-3 py-2 text-right font-medium">{{ t('accounting.pairs.col_amount') }}</th>
            <th v-if="hasText" class="px-3 py-2 text-left font-medium">{{ t('accounting.pairs.col_text') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in pairs" :key="p.pair_no" class="border-t border-neutral-100"
            :class="[p.reversed_by_entry_id ? 'opacity-60' : '', dense ? 'text-xs' : '']"
            :data-pair-no="p.pair_no">
            <td v-if="selectable" class="px-3 py-2">
              <input type="checkbox" :checked="isSelected(p.pair_no)" :disabled="!canSelect(p)"
                :aria-label="t('accounting.pairs.select_pair', { n: p.pair_no })" @change="toggle(p.pair_no)" />
            </td>
            <td class="px-3 py-2 font-mono text-neutral-500">{{ p.pair_no }}</td>
            <td class="px-3 py-2">
              <span class="font-mono">{{ p.debit_account_code }}</span>
              <span v-if="p.debit_account_name" class="ml-1.5 text-xs text-neutral-500">{{ p.debit_account_name }}</span>
            </td>
            <td class="px-3 py-2">
              <span class="font-mono">{{ p.credit_account_code }}</span>
              <span v-if="p.credit_account_name" class="ml-1.5 text-xs text-neutral-500">{{ p.credit_account_name }}</span>
            </td>
            <td class="px-3 py-2 text-right font-mono whitespace-nowrap">
              {{ amountText(p) }}
              <span v-if="p.is_red_storno" class="ml-1 text-xs font-sans text-danger-600">{{ t('accounting.journal.red_storno') }}</span>
            </td>
            <td v-if="hasText" class="px-3 py-2 text-neutral-700">
              {{ p.line_text || '' }}
              <button v-if="p.reversed_by_entry_id" type="button"
                class="cursor-pointer ml-1 text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-600 hover:underline"
                @click="emit('open-entry', p.reversed_by_entry_id!)">
                {{ t('accounting.pairs.reversed_by', { id: p.reversed_by_entry_id }) }}
              </button>
            </td>
          </tr>
        </tbody>
        <tfoot v-if="pairs.length > 1">
          <tr class="border-t border-neutral-200 bg-neutral-50 font-medium">
            <td :colspan="(selectable ? 4 : 3)" class="px-3 py-2 text-right text-xs text-neutral-500">{{ t('accounting.pairs.total') }}</td>
            <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(total, currency) }}</td>
            <td v-if="hasText"></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <!-- Mobil: karty -->
    <ul class="md:hidden divide-y divide-neutral-100">
      <li v-for="p in pairs" :key="`m-${p.pair_no}`" class="px-3 py-2 text-sm" :class="p.reversed_by_entry_id ? 'opacity-60' : ''">
        <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
          <label class="flex items-center gap-2 min-w-0">
            <input v-if="selectable" type="checkbox" :checked="isSelected(p.pair_no)" :disabled="!canSelect(p)"
              :aria-label="t('accounting.pairs.select_pair', { n: p.pair_no })" @change="toggle(p.pair_no)" />
            <span class="font-mono text-xs text-neutral-500">{{ p.pair_no }}.</span>
            <span class="font-mono">{{ p.debit_account_code }} / {{ p.credit_account_code }}</span>
          </label>
          <span class="font-mono whitespace-nowrap">{{ amountText(p) }}</span>
        </div>
        <div v-if="p.line_text" class="mt-0.5 text-xs text-neutral-600">{{ p.line_text }}</div>
        <button v-if="p.reversed_by_entry_id" type="button"
          class="cursor-pointer mt-1 text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-600 hover:underline"
          @click="emit('open-entry', p.reversed_by_entry_id!)">
          {{ t('accounting.pairs.reversed_by', { id: p.reversed_by_entry_id }) }}
        </button>
      </li>
      <li v-if="pairs.length > 1" class="px-3 py-2 flex justify-between text-sm font-medium bg-neutral-50">
        <span class="text-xs text-neutral-500">{{ t('accounting.pairs.total') }}</span>
        <span class="font-mono">{{ formatMoney(total, currency) }}</span>
      </li>
    </ul>
  </div>
</template>
