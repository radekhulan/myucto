<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { accountingApi, type ChartAccount } from '@/api/accounting'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import ChartAccountSelect from '@/components/accounting/ChartAccountSelect.vue'
import {
  payrollPostingImportsApi,
  type PayrollPostingCodeMapRow, type PayrollPostingUnmappedCode,
} from '@/api/pamicaPosting'

/**
 * Přiřazení zkratek předkontací z PAMICY (7Int, 4Oz) k účtům MD a Dal z osnovy firmy.
 * Zkratka vždy znamená celou dvojici účtů. Server mapování uloží pro firmu a import
 * zaúčtování mezd ho příště použije sám; po uložení komponenta vyšle `saved`
 * a volající náhled načte znovu.
 */
const props = defineProps<{
  /** Zkratky ze souboru, které firma ještě nemá přiřazené (se vzorovými řádky). */
  unmapped: PayrollPostingUnmappedCode[]
  /** Už přiřazené zkratky, které soubor používá (k úpravě). */
  mapped: PayrollPostingCodeMapRow[]
  canEdit: boolean
  /** `mapped` nese všechna uložená přiřazení firmy, ne jen zkratky z tohoto souboru. */
  allSaved?: boolean
}>()
const emit = defineEmits<{ (e: 'saved'): void; (e: 'cancel'): void }>()

const { t, locale } = useI18n()
const toast = useToast()

interface Row { code: string; debit: string; credit: string; unmapped?: PayrollPostingUnmappedCode }
const rows = ref<Row[]>([])
const accounts = ref<ChartAccount[]>([])
const saving = ref(false)
const error = ref<string | null>(null)

function reset(): void {
  rows.value = [
    ...props.unmapped.map(u => ({ code: u.code, debit: '', credit: '', unmapped: u })),
    ...props.mapped.map(m => ({ code: m.code, debit: m.debit_account_code, credit: m.credit_account_code })),
  ]
  error.value = null
}
watch(() => [props.unmapped, props.mapped], reset, { immediate: true })
onMounted(() => {
  accountingApi.listAccounts().then(v => { accounts.value = v }).catch(() => { accounts.value = [] })
})

const known = computed(() => new Set(accounts.value.map(a => a.account_code)))
function rowError(row: Row): string | null {
  if (row.debit.trim() === '' || row.credit.trim() === '') return t('payroll_posting_import.code_map.both_required')
  if (row.debit.trim().toUpperCase() === row.credit.trim().toUpperCase()) return t('payroll_posting_import.code_map.same_accounts')
  if (accounts.value.length && (!known.value.has(row.debit.trim()) || !known.value.has(row.credit.trim()))) return t('payroll_posting_import.code_map.unknown_account')
  return null
}
const valid = computed(() => rows.value.length > 0 && rows.value.every(r => rowError(r) === null))

function money(value: number): string {
  return new Intl.NumberFormat(locale?.value === 'en' ? 'en-GB' : 'cs-CZ', { style: 'currency', currency: 'CZK', minimumFractionDigits: 2 }).format(value)
}
function usage(u: PayrollPostingUnmappedCode): string {
  return u.usage.map(k => t(`payroll_posting_import.code_map.usage_${k}`)).join(', ')
}

async function save(): Promise<void> {
  if (!valid.value || !props.canEdit) return
  saving.value = true
  error.value = null
  try {
    await payrollPostingImportsApi.saveCodeMap(rows.value.map(r => ({
      code: r.code, debit_account_code: r.debit.trim(), credit_account_code: r.credit.trim(),
    })))
    toast.success(t('payroll_posting_import.code_map.saved'))
    emit('saved')
  } catch (e: unknown) {
    error.value = apiErrorMessage(e, t('payroll_posting_import.code_map.save_failed'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="space-y-3" data-testid="payroll-posting-code-map">
    <div>
      <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('payroll_posting_import.code_map.title') }}</h3>
      <p class="mt-1 text-sm text-neutral-600">{{ t(unmapped.length ? 'payroll_posting_import.code_map.hint_unmapped' : allSaved ? 'payroll_posting_import.code_map.hint_all' : 'payroll_posting_import.code_map.hint_edit') }}</p>
    </div>
    <div class="overflow-x-auto rounded-lg border border-neutral-200">
      <table class="min-w-full text-sm">
        <thead class="bg-neutral-50 text-left text-xs text-neutral-500">
          <tr>
            <th class="px-3 py-2 whitespace-nowrap">{{ t('payroll_posting_import.code_map.col_code') }}</th>
            <th class="px-3 py-2">{{ t('payroll_posting_import.code_map.col_samples') }}</th>
            <th class="px-3 py-2 whitespace-nowrap">{{ t('payroll_posting_import.col_debit') }}</th>
            <th class="px-3 py-2 whitespace-nowrap">{{ t('payroll_posting_import.col_credit') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="row in rows" :key="row.code" class="align-top" :data-testid="`payroll-posting-code-${row.code}`">
            <td class="px-3 py-2 font-mono font-medium whitespace-nowrap">{{ row.code }}</td>
            <td class="px-3 py-2">
              <template v-if="row.unmapped">
                <div class="text-xs text-neutral-500">{{ usage(row.unmapped) }} · {{ t('payroll_posting_import.code_map.count', { n: row.unmapped.count }) }}</div>
                <div v-for="(s, i) in row.unmapped.samples" :key="i" class="text-xs">{{ s.text || s.document }} · {{ money(s.amount) }}</div>
              </template>
              <span v-else class="text-xs text-neutral-500">{{ t('payroll_posting_import.code_map.already_mapped') }}</span>
              <div v-if="rowError(row) && (row.debit || row.credit)" class="mt-1 text-xs text-danger-600">{{ rowError(row) }}</div>
            </td>
            <td class="px-3 py-2 min-w-40">
              <ChartAccountSelect v-model="row.debit" :accounts="accounts" :disabled="!canEdit" compact
                :aria-label="t('payroll_posting_import.code_map.debit_for', { code: row.code })" />
            </td>
            <td class="px-3 py-2 min-w-40">
              <ChartAccountSelect v-model="row.credit" :accounts="accounts" :disabled="!canEdit" compact
                :aria-label="t('payroll_posting_import.code_map.credit_for', { code: row.code })" />
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <p v-if="error" class="rounded-md border border-danger-500/40 bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert">{{ error }}</p>
    <p v-if="!canEdit" class="text-sm text-warning-700">{{ t('payroll_posting_import.rights_missing') }}</p>
    <div class="flex flex-wrap gap-2">
      <button type="button" :class="btnFilled('success')" class="whitespace-nowrap" :disabled="!valid || saving || !canEdit" data-testid="payroll-posting-code-map-save" @click="save">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
        {{ t(saving ? 'common.saving' : 'payroll_posting_import.code_map.save') }}
      </button>
      <button v-if="!unmapped.length" type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" :disabled="saving" @click="emit('cancel')">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
        {{ t('common.cancel') }}
      </button>
    </div>
  </section>
</template>
