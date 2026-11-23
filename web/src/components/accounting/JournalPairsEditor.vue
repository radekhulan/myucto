<script setup lang="ts">
import { computed, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatMoney } from '@/composables/useFormat'
import { ICONS, btnOutlineSm } from '@/components/ui/buttonStyles'
import type { ChartAccount } from '@/api/accounting'

/**
 * Editor ručního zápisu po dvojicích: Účet MD, Účet Dal, Částka, text.
 *
 * Dvojice je vždy právě jeden řádek MD proti jednomu řádku Dal se stejnou částkou,
 * takže zápis je vyrovnaný z definice a editor neukazuje rozdíl stran. Kontroly
 * (chybějící účet, stejné účty, nekladná částka) jdou na server shodně s kritériem
 * zápisu; tady se jen předem zvýrazní, ať uživatel nečeká na 422. Chyba ze serveru
 * míří na dvojici přes `errors[index]`.
 *
 * Sám nic neukládá ani neposílá.
 */
export interface PairEditorRow {
  debit_account_code: string
  credit_account_code: string
  amount: number | null
  line_text: string
  is_red_storno?: boolean
  cost_center?: string
}

const props = withDefaults(defineProps<{
  modelValue: PairEditorRow[]
  accounts: ChartAccount[]
  /** Chyby ze serveru podle indexu dvojice (0 = první dvojice). */
  errors?: Record<number, string>
  /** Středisko na dvojici (jen výsledkový účet ho smysluplně nese). */
  withCostCenter?: boolean
  /** Id datalistu středisek, pokud ho hostitel renderuje. */
  costCenterListId?: string
  disabled?: boolean
  listId?: string
}>(), {
  errors: () => ({}),
  withCostCenter: false,
  costCenterListId: undefined,
  disabled: false,
  listId: 'jpe-coa',
})

const emit = defineEmits<{ 'update:modelValue': [PairEditorRow[]] }>()

const { t } = useI18n()
const accountListId = `journal-pairs-${useId()}-${props.listId}`

const activeAccounts = computed(() =>
  props.accounts.filter(a => a.is_active).sort((a, b) => a.account_code.localeCompare(b.account_code)))
const accountByCode = computed<Record<string, ChartAccount>>(() => {
  const m: Record<string, ChartAccount> = {}
  for (const a of props.accounts) m[a.account_code] = a
  return m
})

export type PairProblem = 'missing_debit' | 'missing_credit' | 'same_account' | 'amount' | null

/** První nedostatek dvojice; null = dvojice je úplná. */
function problemOf(p: PairEditorRow): PairProblem {
  const d = p.debit_account_code.trim()
  const c = p.credit_account_code.trim()
  if (!d) return 'missing_debit'
  if (!c) return 'missing_credit'
  if (d === c) return 'same_account'
  if (!(Number(p.amount) > 0)) return 'amount'
  return null
}

const PROBLEM_KEY: Record<Exclude<PairProblem, null>, string> = {
  missing_debit: 'accounting.error.pair_missing_debit_account',
  missing_credit: 'accounting.error.pair_missing_credit_account',
  same_account: 'accounting.error.pair_same_account',
  amount: 'accounting.error.pair_amount_not_positive',
}

/** Hláška pod dvojicí: chyba serveru má přednost, jinak předběžná kontrola. */
function messageOf(p: PairEditorRow, i: number): string {
  if (props.errors[i]) return props.errors[i]
  if (touched(p)) {
    const problem = problemOf(p)
    if (problem) return t(PROBLEM_KEY[problem])
  }
  return ''
}
/** Prázdný nový řádek nehlásí chyby, dokud do něj uživatel něco nenapíše. */
function touched(p: PairEditorRow): boolean {
  return !!(p.debit_account_code || p.credit_account_code || p.amount != null || p.line_text)
}

function update(rows: PairEditorRow[]) { emit('update:modelValue', rows) }
function addPair() {
  update([...props.modelValue, { debit_account_code: '', credit_account_code: '', amount: null, line_text: '' }])
}
function removePair(i: number) {
  if (props.modelValue.length <= 1) return
  const next = [...props.modelValue]
  next.splice(i, 1)
  update(next)
}

// Součet jen z úplných dvojic: chybná dvojice (chybí účet, stejné účty) by jinak zvýšila „Celkem“,
// zatímco vedle stojí, že zápis není hotový.
const total = computed(() =>
  Math.round(props.modelValue.reduce((s, p) => s + (problemOf(p) === null ? (p.is_red_storno ? -1 : 1) * Number(p.amount) : 0), 0) * 100) / 100)
const complete = computed(() => props.modelValue.length > 0 && props.modelValue.every(p => problemOf(p) === null))

defineExpose({ complete, valid: complete, problemOf })
</script>

<template>
  <div class="space-y-2" data-test="journal-pairs-editor">
    <div class="hidden sm:grid grid-cols-12 gap-2 px-0.5 text-xs font-medium text-neutral-500">
      <span class="col-span-3">{{ t('accounting.pairs.col_debit') }}</span>
      <span class="col-span-3">{{ t('accounting.pairs.col_credit') }}</span>
      <span class="col-span-2 text-right">{{ t('accounting.pairs.col_amount') }}</span>
      <span :class="withCostCenter ? 'col-span-2' : 'col-span-3'">{{ t('accounting.pairs.col_text') }}</span>
      <span v-if="withCostCenter" class="col-span-1">{{ t('accounting.manual.cost_center') }}</span>
    </div>

    <div v-for="(p, i) in modelValue" :key="i" class="grid grid-cols-12 gap-2 items-start" :data-pair-row="i + 1">
      <div class="col-span-12 sm:col-span-3">
        <input v-model="p.debit_account_code" :list="accountListId" :disabled="disabled" type="text"
          :placeholder="t('accounting.pairs.debit_placeholder')"
          :aria-label="t('accounting.pairs.col_debit')"
          class="w-full h-9 px-2 border rounded-md text-sm font-mono"
          :class="messageOf(p, i) ? 'border-danger-500' : 'border-neutral-300'" />
        <div v-if="p.debit_account_code && accountByCode[p.debit_account_code.trim()]" class="text-xs text-neutral-500 mt-0.5 pl-1 truncate">
          {{ accountByCode[p.debit_account_code.trim()].name }}
        </div>
        <div v-else-if="p.debit_account_code" class="text-xs text-danger-500 mt-0.5 pl-1">{{ t('accounting.manual.unknown_code') }}</div>
      </div>
      <div class="col-span-12 sm:col-span-3">
        <input v-model="p.credit_account_code" :list="accountListId" :disabled="disabled" type="text"
          :placeholder="t('accounting.pairs.credit_placeholder')"
          :aria-label="t('accounting.pairs.col_credit')"
          class="w-full h-9 px-2 border rounded-md text-sm font-mono"
          :class="messageOf(p, i) ? 'border-danger-500' : 'border-neutral-300'" />
        <div v-if="p.credit_account_code && accountByCode[p.credit_account_code.trim()]" class="text-xs text-neutral-500 mt-0.5 pl-1 truncate">
          {{ accountByCode[p.credit_account_code.trim()].name }}
        </div>
        <div v-else-if="p.credit_account_code" class="text-xs text-danger-500 mt-0.5 pl-1">{{ t('accounting.manual.unknown_code') }}</div>
      </div>
      <div class="col-span-6 sm:col-span-2">
        <input v-model.number="p.amount" :disabled="disabled" type="number" step="0.01" min="0"
          :placeholder="t('accounting.manual.amount')" :aria-label="t('accounting.pairs.col_amount')"
          class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm text-right" />
        <label class="mt-1 flex items-center gap-1 text-xs text-neutral-600">
          <input v-model="p.is_red_storno" :disabled="disabled" type="checkbox" />
          {{ t('accounting.journal.red_storno') }}
        </label>
      </div>
      <div :class="withCostCenter ? 'col-span-6 sm:col-span-2' : 'col-span-6 sm:col-span-3'">
        <input v-model="p.line_text" :disabled="disabled" type="text" maxlength="255"
          :placeholder="t('accounting.pairs.text_placeholder')" :aria-label="t('accounting.pairs.col_text')"
          class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
      </div>
      <div v-if="withCostCenter" class="col-span-10 sm:col-span-1">
        <input v-model="p.cost_center" :list="costCenterListId" :disabled="disabled" type="text" maxlength="50"
          :placeholder="t('accounting.manual.cost_center')" :aria-label="t('accounting.manual.cost_center')"
          class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
      </div>
      <div class="col-span-2 sm:col-span-1 flex items-center justify-center h-9">
        <button type="button" :disabled="disabled || modelValue.length <= 1"
          class="cursor-pointer text-danger-500 hover:text-danger-600 disabled:opacity-30 disabled:cursor-not-allowed"
          :title="t('accounting.pairs.remove')" @click="removePair(i)">✕</button>
      </div>
      <div v-if="messageOf(p, i)" class="col-span-12 text-xs text-danger-500 -mt-1" role="alert" data-test="pair-error">
        {{ t('accounting.pairs.pair_label', { n: i + 1 }) }}: {{ messageOf(p, i) }}
      </div>
      <slot name="extra" :pair="p" :index="i" />
    </div>

    <div class="flex flex-wrap items-center justify-between gap-2 pt-1">
      <button v-if="!disabled" type="button" :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']" @click="addPair">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('accounting.pairs.add') }}
      </button>
      <div class="text-sm text-neutral-500">
        {{ complete ? t('accounting.pairs.total') : t('accounting.pairs.total_valid') }}: <strong class="font-mono text-neutral-800" data-test="pairs-total">{{ formatMoney(total) }}</strong>
        <span class="ml-2 px-2 py-0.5 rounded text-xs font-medium"
          :class="complete ? 'bg-success-50 text-success-600' : 'bg-warning-50 text-warning-600'">
          {{ complete ? t('accounting.pairs.complete') : t('accounting.pairs.incomplete') }}
        </span>
      </div>
    </div>

    <datalist :id="accountListId">
      <option v-for="a in activeAccounts" :key="a.id" :value="a.account_code">
        {{ a.account_code }} - {{ a.name }}
      </option>
    </datalist>
  </div>
</template>
