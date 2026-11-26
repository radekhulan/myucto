<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import {
  NOT_PAYROLL_POSTING, UNMAPPED_ACCOUNTING_CODES, payrollPostingImportsApi,
  type PayrollPostingCheck, type PayrollPostingCodeMapRow, type PayrollPostingPreview, type PayrollPostingResult, type PayrollPostingUnmappedCode,
} from '@/api/pamicaPosting'
import PayrollPostingCodeMap from './PayrollPostingCodeMap.vue'
import { payrollPostingCodeLabel } from './payrollPostingCodeLabel'

/**
 * Náhled a načtení měsíčního zaúčtování mezd z PAMICY. Sdílí ho průvodce PAMICA
 * i import přijatých faktur: obě místa předají nahraný soubor a server sám rozpozná,
 * jestli jde o zaúčtování mezd. Když ne, komponenta vyšle `not-posting` a volající
 * pokračuje svou cestou (průvodce převodem celých mezd).
 */
const props = defineProps<{ file: File }>()
const emit = defineEmits<{ (e: 'close'): void; (e: 'not-posting'): void; (e: 'done', result: PayrollPostingResult): void }>()

const { t, locale } = useI18n()
const toast = useToast()

const loading = ref(true)
const applying = ref(false)
const loadError = ref<string | null>(null)
const preview = ref<PayrollPostingPreview | null>(null)
const result = ref<PayrollPostingResult | null>(null)
const createAccounts = ref(false)
// Zkratky předkontací bez přiřazených účtů: náhled nejde sestavit, dokud je účetní nepřiřadí.
const unmappedCodes = ref<PayrollPostingUnmappedCode[]>([])
const unmappedCanWrite = ref(true)
const editingCodes = ref(false)
// Řádky editoru přiřazení: zkratky z náhledu, nebo (když náhled nejde sestavit, třeba kvůli
// chybně přiřazeným účtům) všechna uložená přiřazení firmy.
const codeRows = ref<PayrollPostingCodeMapRow[]>([])
const codeRowsAll = ref(false)
const loadingCodes = ref(false)
const canEditCodes = computed(() => preview.value?.can_write ?? unmappedCanWrite.value)
const codeLabel = (code: string): string => payrollPostingCodeLabel(code, t)

async function openCodeMap(): Promise<void> {
  if (preview.value && preview.value.code_map.length > 0) {
    codeRows.value = preview.value.code_map
    codeRowsAll.value = false
    editingCodes.value = true
    return
  }
  loadingCodes.value = true
  try {
    const rows = (await payrollPostingImportsApi.codeMap()).code_map
    if (rows.length === 0) {
      toast.info(t('payroll_posting_import.code_map.none_saved'))
      return
    }
    codeRows.value = rows
    codeRowsAll.value = true
    editingCodes.value = true
  } catch (error: unknown) {
    toast.error(apiErrorMessage(error, t('payroll_posting_import.code_map.load_failed')))
  } finally {
    loadingCodes.value = false
  }
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  preview.value = null
  result.value = null
  createAccounts.value = false
  unmappedCodes.value = []
  unmappedCanWrite.value = true
  editingCodes.value = false
  try {
    preview.value = await payrollPostingImportsApi.preview(props.file)
  } catch (error: any) {
    const apiError = error?.response?.data?.error
    if (apiError?.code === NOT_PAYROLL_POSTING) {
      emit('not-posting')
      return
    }
    if (apiError?.code === UNMAPPED_ACCOUNTING_CODES && Array.isArray(apiError.unmapped_codes)) {
      unmappedCodes.value = apiError.unmapped_codes
      unmappedCanWrite.value = apiError.can_write !== false
      return
    }
    loadError.value = apiErrorMessage(error, t('payroll_posting_import.preview_failed'))
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(() => props.file, load)

const errors = computed(() => (preview.value?.checks ?? []).filter(c => c.level === 'error' && !(createAccounts.value && c.code === 'accounts_creatable')))
const canApply = computed(() => !!preview.value && preview.value.can_write && errors.value.length === 0
  && (preview.value.can_apply || (createAccounts.value && preview.value.can_apply_with_new_accounts && preview.value.can_create_accounts)))
const offerAccounts = computed(() => !!preview.value?.can_apply_with_new_accounts || (preview.value?.accounts ?? []).some(a => a.status === 'creatable'))
const problemAccounts = computed(() => (preview.value?.accounts ?? []).filter(a => a.status !== 'ok'))
const monthLabel = computed(() => {
  const period = preview.value?.source.period ?? ''
  return /^\d{4}-\d{2}$/.test(period) ? `${period.slice(5, 7)}/${period.slice(0, 4)}` : period
})
const disabledReason = computed(() => {
  if (!preview.value) return ''
  if (!preview.value.can_write) return t('payroll_posting_import.rights_missing')
  if (errors.value.length > 0) return errors.value[0].message
  if (createAccounts.value && !preview.value.can_create_accounts) return t('payroll_posting_import.rights_accounts_missing')
  return t('payroll_posting_import.accounts_confirm_first')
})

function checkClass(check: PayrollPostingCheck): string {
  return check.level === 'error' ? 'border-danger-500/40 bg-danger-50 text-danger-600'
    : check.level === 'warning' ? 'border-warning-500/40 bg-warning-50 text-warning-700'
      : check.level === 'info' ? 'border-primary-500/40 bg-primary-50 text-primary-700'
        : 'border-success-500/40 bg-success-50 text-success-600'
}
function accountStatus(status: string): string {
  return t(`payroll_posting_import.account_status.${status}`)
}
function money(value: number): string {
  return new Intl.NumberFormat(locale?.value === 'en' ? 'en-GB' : 'cs-CZ', { style: 'currency', currency: 'CZK', minimumFractionDigits: 2 }).format(value)
}

async function apply(): Promise<void> {
  if (!canApply.value) return
  applying.value = true
  try {
    result.value = await payrollPostingImportsApi.apply(props.file, createAccounts.value)
    toast.success(t('payroll_posting_import.done_toast', { month: monthLabel.value }))
    emit('done', result.value)
  } catch (error: any) {
    toast.error(apiErrorMessage(error, t('payroll_posting_import.apply_failed')))
    // Kontroly se mohly mezitím změnit (souběžné načtení, zamčené období).
    try { preview.value = await payrollPostingImportsApi.preview(props.file) } catch { /* hláška už je venku */ }
  } finally {
    applying.value = false
  }
}

const actions = computed<ActionItem[]>(() => result.value
  ? [
      { key: 'internal', label: t('payroll_posting_import.open_internal'), icon: 'doc', tier: 'primary', variant: 'primary', to: { name: 'accounting-internal-documents' } },
      { key: 'items', label: t('payroll_posting_import.open_other_items'), icon: 'coin', tier: 'secondary', variant: 'neutral', to: { name: 'other-items' } },
      { key: 'close', label: t('payroll_posting_import.close'), icon: 'x', tier: 'secondary', variant: 'neutral', run: () => emit('close') },
    ]
  : [
      { key: 'apply', label: t('payroll_posting_import.apply'), icon: 'check', tier: 'primary', variant: 'success', show: !!preview.value && !editingCodes.value,
        disabled: !canApply.value || applying.value, disabledReason: disabledReason.value, loading: applying.value, run: () => { void apply() } },
      // Cesta ven i z chyby náhledu: chybné přiřazení zkratek (různé účty Dal v závazku,
      // prohozené MD a Dal) jde opravit jen v editoru přiřazení.
      { key: 'codes', label: t('payroll_posting_import.edit_codes'), icon: 'edit',
        tier: loadError.value ? 'primary' : 'secondary', variant: loadError.value ? 'primary' : 'neutral',
        show: !loading.value && !unmappedCodes.value.length && !editingCodes.value,
        disabled: loadingCodes.value, loading: loadingCodes.value, run: () => { void openCodeMap() } },
      { key: 'reload', label: t('payroll_posting_import.reload'), icon: 'cycle', tier: 'secondary', variant: 'neutral', show: !loading.value, run: () => { void load() } },
      { key: 'close', label: t('payroll_posting_import.other_file'), icon: 'x', tier: 'secondary', variant: 'neutral', run: () => emit('close') },
    ])
</script>

<template>
  <div class="space-y-4" data-testid="payroll-posting-import">
    <div>
      <h2 class="text-lg font-semibold">{{ t('payroll_posting_import.title') }}</h2>
      <p class="mt-1 text-sm text-neutral-500">{{ t('payroll_posting_import.hint', { file: file.name }) }}</p>
    </div>

    <div v-if="loading" class="py-8 text-center text-neutral-400">{{ t('common.loading') }}</div>
    <div v-else-if="loadError" class="rounded-md border border-danger-500/40 bg-danger-50 px-3 py-2 text-sm text-danger-600" role="alert" data-testid="payroll-posting-error">{{ loadError }}</div>
    <PayrollPostingCodeMap v-else-if="unmappedCodes.length" :unmapped="unmappedCodes" :mapped="[]" :can-edit="canEditCodes" @saved="load" />

    <PayrollPostingCodeMap v-if="!loading && editingCodes && !result" :unmapped="[]" :mapped="codeRows" :all-saved="codeRowsAll"
      :can-edit="canEditCodes" @saved="load" @cancel="editingCodes = false" />

    <template v-if="!loading && !loadError && !unmappedCodes.length && preview">
      <div v-if="result" class="rounded-lg border border-success-500/40 bg-success-50 px-4 py-3 text-sm text-success-700" data-testid="payroll-posting-done">
        <p class="font-medium">{{ t('payroll_posting_import.done_title', { month: monthLabel }) }}</p>
        <p class="mt-1">{{ t('payroll_posting_import.done_detail', { internal: result.internal_documents.map(d => d.document_no).join(', '), items: result.other_items.length }) }}</p>
        <p v-if="result.created_accounts.length" class="mt-1">{{ t('payroll_posting_import.done_accounts', { accounts: result.created_accounts.join(', ') }) }}</p>
      </div>

      <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
        <div class="rounded-lg border border-neutral-200 px-3 py-2"><dt class="text-xs text-neutral-500">{{ t('payroll_posting_import.month') }}</dt><dd class="font-semibold">{{ monthLabel }} <span class="font-mono text-xs text-neutral-500">{{ preview.source.sym_par }}</span></dd></div>
        <div class="rounded-lg border border-neutral-200 px-3 py-2"><dt class="text-xs text-neutral-500">{{ t('payroll_posting_import.ico') }}</dt><dd class="font-mono">{{ preview.source.ico }}</dd></div>
        <div class="rounded-lg border border-neutral-200 px-3 py-2"><dt class="text-xs text-neutral-500">{{ t('payroll_posting_import.total_internal') }}</dt><dd class="font-semibold">{{ money(preview.totals.internal) }}</dd></div>
        <div class="rounded-lg border border-neutral-200 px-3 py-2"><dt class="text-xs text-neutral-500">{{ t('payroll_posting_import.total_commitments') }}</dt><dd class="font-semibold">{{ money(preview.totals.commitments) }}</dd></div>
      </dl>

      <ul class="space-y-1.5" data-testid="payroll-posting-checks">
        <li v-for="check in preview.checks" :key="check.code + check.message" class="rounded-md border px-3 py-2 text-sm" :class="checkClass(check)" :data-testid="`payroll-posting-check-${check.code}`">{{ check.message }}</li>
      </ul>

      <section v-for="doc in preview.internal_documents" :key="doc.item_id">
        <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('payroll_posting_import.internal_title') }}</h3>
        <p class="mb-2 text-sm">{{ doc.title }} · {{ t('payroll_posting_import.accounting_date') }} {{ doc.accounting_date }}</p>
        <div class="overflow-x-auto rounded-lg border border-neutral-200">
          <table class="min-w-full text-sm">
            <thead class="bg-neutral-50 text-left text-xs text-neutral-500">
              <tr>
                <th class="px-3 py-2">{{ t('payroll_posting_import.col_text') }}</th>
                <th class="px-3 py-2 whitespace-nowrap">{{ t('payroll_posting_import.col_debit') }}</th>
                <th class="px-3 py-2 whitespace-nowrap">{{ t('payroll_posting_import.col_credit') }}</th>
                <th class="px-3 py-2 text-right">{{ t('payroll_posting_import.col_amount') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(line, i) in doc.lines" :key="i">
                <td class="px-3 py-2">{{ line.text }} <span v-if="line.note" class="text-neutral-500">{{ line.note }}</span>
                  <span v-if="line.code" class="ml-1 rounded-full bg-neutral-100 px-2 py-0.5 font-mono text-xs text-neutral-600">{{ codeLabel(line.code) }}</span></td>
                <td class="px-3 py-2 font-mono">{{ line.debit }}</td>
                <td class="px-3 py-2 font-mono">{{ line.credit }}</td>
                <td class="px-3 py-2 text-right whitespace-nowrap">{{ money(line.amount) }}</td>
              </tr>
            </tbody>
            <tfoot class="bg-neutral-50 font-medium">
              <tr><td class="px-3 py-2" colspan="3">{{ t('payroll_posting_import.sum') }}</td><td class="px-3 py-2 text-right whitespace-nowrap">{{ money(doc.amount) }}</td></tr>
            </tfoot>
          </table>
        </div>
      </section>

      <section>
        <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('payroll_posting_import.commitments_title') }}</h3>
        <div class="overflow-x-auto rounded-lg border border-neutral-200">
          <table class="min-w-full text-sm">
            <thead class="bg-neutral-50 text-left text-xs text-neutral-500">
              <tr>
                <th class="px-3 py-2">{{ t('payroll_posting_import.col_partner') }}</th>
                <th class="px-3 py-2 whitespace-nowrap">{{ t('payroll_posting_import.col_symbols') }}</th>
                <th class="px-3 py-2 whitespace-nowrap">{{ t('payroll_posting_import.col_due') }}</th>
                <th class="px-3 py-2 whitespace-nowrap">{{ t('payroll_posting_import.col_account') }}</th>
                <th class="px-3 py-2">{{ t('payroll_posting_import.col_posting') }}</th>
                <th class="px-3 py-2 text-right">{{ t('payroll_posting_import.col_amount') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="c in preview.commitments" :key="c.item_id" :data-testid="`payroll-posting-commitment-${c.item_id}`">
                <td class="px-3 py-2">
                  <div class="font-medium">{{ c.partner_name ?? t('payroll_posting_import.no_partner') }}</div>
                  <div class="text-xs text-neutral-500">{{ c.title }}</div>
                </td>
                <td class="px-3 py-2 font-mono text-xs whitespace-nowrap">
                  <div>{{ t('payroll_posting_import.vs') }} {{ c.variable_symbol ?? '—' }}</div>
                  <div>{{ t('payroll_posting_import.ks') }} {{ c.constant_symbol ?? '—' }}</div>
                </td>
                <td class="px-3 py-2 whitespace-nowrap">{{ c.due_on }}</td>
                <td class="px-3 py-2 font-mono text-xs whitespace-nowrap">
                  {{ c.payment_type === 'cash' ? t('payroll_posting_import.cash') : (c.account ?? '—') }}
                  <div v-if="c.account_error" class="font-sans text-warning-700">{{ c.account_error }}</div>
                </td>
                <td class="px-3 py-2">
                  <span v-if="c.posting === 'without_posting'" class="rounded-full bg-neutral-100 px-2 py-0.5 text-xs whitespace-nowrap text-neutral-600">{{ t('payroll_posting_import.without_posting', { account: c.credit }) }}</span>
                  <div v-else class="space-y-0.5 font-mono text-xs">
                    <div v-for="(line, i) in c.lines" :key="i" class="whitespace-nowrap"><span v-if="line.code" class="text-neutral-500">{{ codeLabel(line.code) }}: </span>{{ line.debit }} / {{ line.credit }} · {{ money(line.amount) }}</div>
                  </div>
                </td>
                <td class="px-3 py-2 text-right font-medium whitespace-nowrap">{{ money(c.amount) }}</td>
              </tr>
            </tbody>
            <tfoot class="bg-neutral-50 font-medium">
              <tr><td class="px-3 py-2" colspan="5">{{ t('payroll_posting_import.sum') }}</td><td class="px-3 py-2 text-right whitespace-nowrap">{{ money(preview.totals.commitments) }}</td></tr>
            </tfoot>
          </table>
        </div>
      </section>

      <section v-if="problemAccounts.length" data-testid="payroll-posting-accounts">
        <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('payroll_posting_import.accounts_title') }}</h3>
        <div class="overflow-x-auto rounded-lg border border-neutral-200">
          <table class="min-w-full text-sm">
            <thead class="bg-neutral-50 text-left text-xs text-neutral-500">
              <tr>
                <th class="px-3 py-2 whitespace-nowrap">{{ t('payroll_posting_import.col_source_account') }}</th>
                <th class="px-3 py-2 whitespace-nowrap">{{ t('payroll_posting_import.col_target_account') }}</th>
                <th class="px-3 py-2">{{ t('payroll_posting_import.col_status') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="a in problemAccounts" :key="a.source">
                <td class="px-3 py-2 font-mono">{{ a.source }}</td>
                <td class="px-3 py-2 font-mono">{{ a.target ?? a.proposed ?? '—' }}</td>
                <td class="px-3 py-2">{{ accountStatus(a.status) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <label v-if="offerAccounts && !result" class="mt-3 flex cursor-pointer items-start gap-3 text-sm" data-testid="payroll-posting-create-accounts">
          <input v-model="createAccounts" type="checkbox" class="mt-1 rounded border-neutral-300 text-primary-600" :disabled="!preview.can_create_accounts" />
          <span>
            <span class="font-medium text-neutral-800">{{ t('payroll_posting_import.create_accounts') }}</span>
            <span class="block text-neutral-600">{{ preview.can_create_accounts ? t('payroll_posting_import.create_accounts_hint') : t('payroll_posting_import.rights_accounts_missing') }}</span>
          </span>
        </label>
      </section>

      <p class="text-sm text-neutral-500">{{ t('payroll_posting_import.warning_no_parallel_payroll') }}</p>
    </template>

    <ActionBar :actions="actions" />
  </div>
</template>
