<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter, type LocationQuery } from 'vue-router'
import { accountingApi, type ChartAccount, type CostCenter } from '@/api/accounting'
import type { Client } from '@/api/clients'
import {
  accrualsApi, accrualErrorCode, accrualKindForAccount, ACCRUAL_AGENDA_KINDS, ACCRUAL_METHODS,
  type AccrualKind, type AccrualMethod, type AccrualPayload, type AccrualPlanPreviewRow, type AccrualSourceType,
} from '@/api/accruals'
import { useToast } from '@/composables/useToast'
import { useDemoMode } from '@/composables/useDemoMode'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { isCalendarIsoDate } from '@/utils/date'
import { accrualSourceRoute } from '@/utils/accrualSource'
import { btnOutlineSm } from '@/components/ui/buttonStyles'
import DateInput from '@/components/ui/DateInput.vue'
import ClientSearchSelect from '@/components/ui/ClientSearchSelect.vue'
import ChartAccountSelect from '@/components/accounting/ChartAccountSelect.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const { blockDemoMutation } = useDemoMode()
const pageId = useId()

const editId = computed(() => Number(route.params.id || 0))
const isEdit = computed(() => editId.value > 0)
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const accounts = ref<ChartAccount[]>([])
const costCenters = ref<CostCenter[]>([])
const rowVersion = ref<number | undefined>(undefined)
/** Úprava položky se zaúčtovanou historií: druh a účet drží server, plán se přepočte jen za ní. */
const editStatus = ref<string>('draft')

const form = reactive({
  title: '',
  kind: 'prepaid_expense' as AccrualKind,
  accrual_account_code: '',
  counter_account_code: '',
  termination_account_code: '',
  basis_amount: null as number | null,
  period_from: '',
  period_to: '',
  method: 'monthly_days' as AccrualMethod,
  partner_id: null as number | null,
  partner_name: '',
  contract_ref: '',
  cost_center: '',
  note: '',
  activate: true,
})
/** Vazba na zdroj a zápis vzniku z předvyplnění (z dokladu nebo z řádku bez položky). */
const source = reactive({
  source_type: null as AccrualSourceType | null,
  source_id: null as number | null,
  origin_entry_id: null as number | null,
  origin_amount: null as number | null,
  source_item_id: null as number | null,
  closing_handover: false,
})
/** Chyba načtení upravované položky: formulář se neukáže prázdný, aby ho nešlo uložit naslepo. */
const loadError = ref('')
/**
 * Řádky zdrojové faktury s obdobím od–do. Položka spravuje nejvýš jeden; ten uzávěrka přestane
 * odkládat, ostatní odkládá dál. Hodnota `0` = položka nespravuje žádný řádek s obdobím.
 */
const sourceLines = ref<Array<{ item_id: number | null; from: string; to: string; amount?: number; description?: string }>>([])
const sourceLineError = ref(false)
const loadedBasis = ref<number | null>(null)
const basisChanged = computed(() => isEdit.value && loadedBasis.value !== null && Number(form.basis_amount) !== loadedBasis.value)
/** Výchozí výběr řádku: řádek, který se s položkou shoduje částkou a obdobím, částkou nebo obdobím. */
function suggestLine() {
  const lines = sourceLines.value.filter(l => l.item_id)
  const basis = Number(form.basis_amount)
  for (const match of [
    (l: typeof lines[number]) => l.from === form.period_from && l.to === form.period_to && Math.abs(Number(l.amount) - basis) < 0.005,
    (l: typeof lines[number]) => Math.abs(Number(l.amount) - basis) < 0.005,
    (l: typeof lines[number]) => l.from === form.period_from && l.to === form.period_to,
  ]) {
    const found = lines.filter(match)
    if (found.length === 1) return found[0].item_id
  }
  return 0
}
async function loadSourceLines() {
  if (isEdit.value || !source.source_id || (source.source_type !== 'invoice' && source.source_type !== 'purchase_invoice')) return
  try {
    const candidates = await accrualsApi.sourceCandidates(source.source_type, source.source_id)
    sourceLines.value = candidates.periods.filter(p => p.item_id)
    if (source.source_item_id === null && sourceLines.value.length) source.source_item_id = suggestLine()
  } catch {
    sourceLines.value = []
  }
}

const activeAccounts = computed(() => accounts.value.filter(a => a.is_active).sort((a, b) => a.account_code.localeCompare(b.account_code)))
const KIND_PREFIX: Record<string, string> = { prepaid_expense: '381', deferred_revenue: '384' }
const accrualAccountChoices = computed(() => activeAccounts.value.filter(a => a.account_code.startsWith(KIND_PREFIX[form.kind] ?? '38')))
const sourceRoute = computed(() => accrualSourceRoute(source.source_type, source.source_id))

// Účet 38x musí patřit zvolenému druhu; při změně druhu se nabídne první vhodný.
watch(() => form.kind, kind => {
  const prefix = KIND_PREFIX[kind]
  if (prefix && !form.accrual_account_code.startsWith(prefix)) {
    form.accrual_account_code = accrualAccountChoices.value.find(a => !a.is_synthetic)?.account_code ?? prefix
  }
})
watch(() => form.accrual_account_code, code => {
  const kind = accrualKindForAccount(code)
  if (kind && kind !== form.kind) form.kind = kind
})

function onPartnerSelected(client: Client | null) {
  form.partner_name = client?.company_name || ''
}

// ─── Živý náhled plánu ───
const planRows = ref<AccrualPlanPreviewRow[]>([])
const planLoading = ref(false)
const planError = ref('')
let planTimer: ReturnType<typeof setTimeout> | null = null
let planRequest = 0
const planReady = computed(() => Number(form.basis_amount) > 0 && isCalendarIsoDate(form.period_from)
  && isCalendarIsoDate(form.period_to) && form.period_from <= form.period_to)
const planTotal = computed(() => planRows.value.reduce((sum, row) => sum + Number(row.amount_planned), 0))
async function loadPlan() {
  const request = ++planRequest
  if (!planReady.value) {
    planRows.value = []
    planError.value = ''
    return
  }
  planLoading.value = true
  try {
    const result = await accrualsApi.planPreview({
      method: form.method, basis_amount: Number(form.basis_amount), period_from: form.period_from, period_to: form.period_to,
    })
    if (request !== planRequest) return
    planRows.value = result.rows
    planError.value = ''
  } catch (e: any) {
    if (request !== planRequest) return
    planRows.value = []
    planError.value = errorText(e)
  } finally {
    if (request === planRequest) planLoading.value = false
  }
}
watch(() => [form.method, form.basis_amount, form.period_from, form.period_to], () => {
  if (planTimer) clearTimeout(planTimer)
  planTimer = setTimeout(() => void loadPlan(), 400)
})
onBeforeUnmount(() => { if (planTimer) clearTimeout(planTimer) })

function errorText(e: any): string {
  const code = accrualErrorCode(e)
  if (code) {
    const key = `accruals.error.${code}`
    const localized = t(key, (e?.response?.data?.error ?? {}) as Record<string, unknown>)
    if (localized !== key) return localized
  }
  return e?.response?.data?.error?.message || t('common.error')
}

function payload(): AccrualPayload {
  return {
    title: form.title.trim(),
    kind: form.kind,
    accrual_account_code: form.accrual_account_code.trim(),
    counter_account_code: form.counter_account_code.trim() || null,
    termination_account_code: form.termination_account_code.trim() || null,
    basis_amount: Number(form.basis_amount),
    period_from: form.period_from,
    period_to: form.period_to,
    method: form.method,
    contract_ref: form.contract_ref.trim() || null,
    note: form.note.trim() || null,
    partner_id: form.partner_id,
    partner_name: form.partner_name.trim() || null,
    cost_center: form.cost_center.trim() || null,
    ...(isEdit.value
      ? { row_version: rowVersion.value }
      : {
          activate: form.activate,
          ...(source.source_type ? { source_type: source.source_type, source_id: source.source_id } : {}),
          // 0 = položka výslovně nespravuje žádný řádek s obdobím (server ho pak nedohledává).
          ...(source.source_item_id !== null && (source.source_item_id > 0 || sourceLines.value.length) ? { source_item_id: source.source_item_id } : {}),
          ...(source.closing_handover ? { closing_handover: true } : {}),
          ...(source.origin_entry_id ? { origin_entry_id: source.origin_entry_id, origin_amount: source.origin_amount } : {}),
        }),
  }
}

async function save() {
  if (saving.value || blockDemoMutation()) return
  error.value = ''
  if (!form.title.trim() || !form.accrual_account_code.trim() || !planReady.value) {
    error.value = t('accruals.editor.validation_required')
    return
  }
  saving.value = true
  try {
    const saved = isEdit.value ? await accrualsApi.update(editId.value, payload()) : await accrualsApi.create(payload())
    toast.success(t(isEdit.value ? 'accruals.editor.saved' : 'accruals.editor.created'))
    await router.push({ name: 'accounting-accrual-detail', params: { id: saved.id } })
  } catch (e: any) {
    error.value = errorText(e)
    sourceLineError.value = accrualErrorCode(e) === 'source_item_required'
    if (sourceLineError.value && !sourceLines.value.length) void loadSourceLines()
  } finally {
    saving.value = false
  }
}

function str(value: LocationQuery[string]): string {
  return typeof value === 'string' ? value : ''
}
function positive(value: string): number | null {
  const n = Number(value)
  return Number.isFinite(n) && n > 0 ? n : null
}
/** Předvyplnění z dokladu (panel Časové rozlišení) nebo z řádku 38x bez položky. */
function applyPrefill(q: LocationQuery) {
  const sourceType = str(q.source_type)
  if (['invoice', 'purchase_invoice', 'other_item', 'internal_document'].includes(sourceType) && positive(str(q.source_id))) {
    source.source_type = sourceType as AccrualSourceType
    source.source_id = positive(str(q.source_id))
  }
  const line = positive(str(q.source_item_id))
  source.source_item_id = line && Number.isInteger(line) ? line : null
  source.closing_handover = str(q.closing_handover) === '1' && !!source.source_id
  source.origin_entry_id = positive(str(q.origin_entry_id))
  source.origin_amount = positive(str(q.origin_amount))
  const account = str(q.account)
  if (/^[0-9A-Za-z.]{3,20}$/.test(account)) {
    const kind = accrualKindForAccount(account)
    if (kind) form.kind = kind
    form.accrual_account_code = account
  }
  const amount = positive(str(q.amount)) ?? source.origin_amount
  if (amount) form.basis_amount = amount
  if (isCalendarIsoDate(str(q.from))) form.period_from = str(q.from)
  if (isCalendarIsoDate(str(q.to))) form.period_to = str(q.to)
  const partner = positive(str(q.partner_id))
  if (partner && Number.isInteger(partner)) form.partner_id = partner
  if (str(q.partner_name)) form.partner_name = str(q.partner_name).slice(0, 190)
  if (str(q.title)) form.title = str(q.title).slice(0, 255)
}

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const requests: Promise<unknown>[] = [
      accountingApi.listAccounts().then(value => { accounts.value = value }).catch(() => { accounts.value = [] }),
      accountingApi.listCostCenters().then(value => { costCenters.value = value }).catch(() => { costCenters.value = [] }),
    ]
    if (isEdit.value) {
      requests.push(accrualsApi.get(editId.value).then(item => {
        if (!item.can.edit) {
          void router.replace({ name: 'accounting-accrual-detail', params: { id: editId.value } })
          return
        }
        editStatus.value = item.status
        rowVersion.value = item.row_version
        form.title = item.title
        form.kind = item.kind
        form.accrual_account_code = item.accrual_account_code
        form.counter_account_code = item.counter_account_code || ''
        form.termination_account_code = item.termination_account_code || ''
        form.basis_amount = Number(item.basis_amount)
        loadedBasis.value = Number(item.basis_amount)
        form.period_from = item.period_from
        form.period_to = item.period_to
        form.method = item.method
        form.partner_id = item.partner_id
        form.partner_name = item.partner_name || ''
        form.contract_ref = item.contract_ref || ''
        form.cost_center = item.cost_center || ''
        form.note = item.note || ''
        source.source_type = item.source_type
        source.source_id = item.source_id
      }))
    } else {
      applyPrefill(route.query)
    }
    await Promise.all(requests)
  } catch (e: any) {
    if (isEdit.value) loadError.value = errorText(e)
    toast.error(errorText(e))
  } finally {
    loading.value = false
  }
  void loadPlan()
  void loadSourceLines()
}

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-4">
      <RouterLink :to="isEdit ? { name: 'accounting-accrual-detail', params: { id: editId } } : { name: 'accounting-accruals' }"
        class="text-sm text-primary-700 hover:underline">← {{ t('accruals.back_to_list') }}</RouterLink>
      <h1 class="mt-1 text-2xl font-semibold">{{ t(isEdit ? 'accruals.editor.edit_title' : 'accruals.editor.new_title') }}</h1>
      <p class="mt-0.5 text-sm text-neutral-500">{{ t('accruals.editor.hint') }}</p>
    </div>

    <div v-if="loading" class="py-12 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <div v-else-if="loadError" class="rounded-md border border-danger-200 bg-danger-50 px-4 py-3 text-sm text-danger-700" role="alert" data-test="editor-load-error">
      <p>{{ loadError }}</p>
      <button type="button" :class="[btnOutlineSm('neutral'), 'mt-2 whitespace-nowrap']" @click="load">{{ t('accruals.editor.reload') }}</button>
    </div>
    <form v-else class="max-w-5xl space-y-4" @submit.prevent="save">
      <p v-if="source.source_type || source.origin_entry_id" class="rounded-md border border-primary-200 bg-primary-50 px-3 py-2 text-sm text-primary-800" data-test="prefill-source">
        <template v-if="source.source_type">
          {{ t('accruals.editor.from_source', { source: t(`accruals.source.${source.source_type}`) }) }}
          <RouterLink v-if="sourceRoute" :to="sourceRoute" class="ml-1 font-medium underline">#{{ source.source_id }}</RouterLink>
        </template>
        <span v-if="source.origin_entry_id && !isEdit" class="ml-1">{{ t('accruals.editor.origin_entry', { entry: source.origin_entry_id }) }}</span>
      </p>
      <label v-if="!isEdit && sourceLines.length" class="block max-w-3xl text-sm font-medium" data-test="source-line">
        {{ t('accruals.editor.source_line') }}
        <select v-model.number="source.source_item_id" data-test="source-line-select"
          :class="['mt-1 block h-9 w-full rounded-md border bg-surface px-2', sourceLineError ? 'border-danger-500' : 'border-neutral-300']">
          <option :value="0">{{ t('accruals.editor.source_line_none') }}</option>
          <option v-for="line in sourceLines" :key="line.item_id ?? 0" :value="line.item_id">
            {{ t('accruals.editor.source_line_option', { description: line.description || `#${line.item_id}`, from: formatDate(line.from), to: formatDate(line.to), amount: formatMoney(line.amount ?? 0) }) }}
          </option>
        </select>
        <span class="mt-1 block text-xs font-normal text-neutral-500">{{ t('accruals.editor.source_line_hint') }}</span>
      </label>

      <section class="rounded-lg border border-neutral-200 bg-surface p-4">
        <h2 class="mb-4 font-semibold">{{ t('accruals.editor.basics') }}</h2>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <label class="text-sm font-medium sm:col-span-2 lg:col-span-3">{{ t('accruals.title_label') }}
            <input v-model="form.title" required maxlength="255" data-test="title" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2" />
          </label>
          <label class="text-sm font-medium">{{ t('accruals.kind_label') }}
            <select v-model="form.kind" data-test="kind" :disabled="isEdit && editStatus !== 'draft'" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 disabled:bg-neutral-50">
              <option v-for="value in ACCRUAL_AGENDA_KINDS" :key="value" :value="value">{{ t(`accruals.kind.${value}`) }}</option>
            </select>
          </label>
          <div class="text-sm font-medium">
            <span>{{ t('accruals.account') }}</span>
            <ChartAccountSelect v-model="form.accrual_account_code" :accounts="accrualAccountChoices" :disabled="isEdit && editStatus !== 'draft'"
              class="mt-1" :aria-label="t('accruals.account')" :input-id="`${pageId}-account`" />
          </div>
          <div class="text-sm font-medium">
            <span>{{ t('accruals.counter_account') }}</span>
            <ChartAccountSelect v-model="form.counter_account_code" :accounts="activeAccounts" class="mt-1" :aria-label="t('accruals.counter_account')" />
            <span class="mt-1 block text-xs font-normal text-neutral-500">{{ t(form.kind === 'deferred_revenue' ? 'accruals.editor.counter_hint_revenue' : 'accruals.editor.counter_hint_expense') }}</span>
          </div>
          <div class="text-sm font-medium">
            <span>{{ t('accruals.termination_account') }}</span>
            <ChartAccountSelect v-model="form.termination_account_code" :accounts="activeAccounts" class="mt-1" :aria-label="t('accruals.termination_account')" />
            <span class="mt-1 block text-xs font-normal text-neutral-500">{{ t('accruals.editor.termination_hint') }}</span>
          </div>
          <label class="text-sm font-medium">{{ t('accruals.basis_amount') }}
            <input v-model.number="form.basis_amount" type="number" step="0.01" min="0.01" required data-test="basis-amount"
              class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-right tabular-nums" />
          </label>
          <p v-if="basisChanged" class="rounded-md border border-warning-200 bg-warning-50 px-3 py-2 text-xs text-warning-800 sm:col-span-2 lg:col-span-4" role="status" data-test="basis-warning">
            {{ t('accruals.editor.basis_follows_links') }}
          </p>
        </div>
      </section>

      <section class="rounded-lg border border-neutral-200 bg-surface p-4">
        <h2 class="mb-4 font-semibold">{{ t('accruals.editor.plan') }}</h2>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <label class="text-sm font-medium">{{ t('accruals.editor.period_from') }}
            <DateInput v-model="form.period_from" data-test="period-from" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 px-2" />
          </label>
          <label class="text-sm font-medium">{{ t('accruals.editor.period_to') }}
            <DateInput v-model="form.period_to" data-test="period-to" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 px-2" />
          </label>
          <label class="text-sm font-medium sm:col-span-2">{{ t('accruals.method_label') }}
            <select v-model="form.method" data-test="method" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2">
              <option v-for="value in ACCRUAL_METHODS" :key="value" :value="value">{{ t(`accruals.method.${value}`) }}</option>
            </select>
            <span class="mt-1 block text-xs font-normal text-neutral-500">{{ t(`accruals.method_hint.${form.method}`) }}</span>
          </label>
        </div>
        <div class="mt-4" data-test="plan-preview">
          <h3 class="mb-2 text-sm font-semibold text-neutral-700">{{ t('accruals.editor.plan_preview') }}</h3>
          <p v-if="!planReady" class="text-sm text-neutral-500">{{ t('accruals.editor.plan_incomplete') }}</p>
          <p v-else-if="planError" class="text-sm text-danger-600" role="alert">{{ planError }}</p>
          <p v-else-if="planLoading && !planRows.length" class="text-sm text-neutral-500">{{ t('common.loading') }}</p>
          <p v-else-if="!planRows.length" class="text-sm text-neutral-500">{{ t('accruals.editor.plan_manual') }}</p>
          <div v-else class="max-h-80 overflow-auto rounded-md border border-neutral-200" :class="{ 'opacity-60': planLoading }">
            <table class="w-full text-sm">
              <thead class="sticky top-0 bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
                <tr>
                  <th class="px-3 py-1.5 text-left font-medium">{{ t('accruals.detail.due_on') }}</th>
                  <th class="px-3 py-1.5 text-left font-medium">{{ t('accruals.detail.row_period') }}</th>
                  <th class="px-3 py-1.5 text-right font-medium">{{ t('accruals.detail.amount_planned') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="(row, index) in planRows" :key="index" data-test="plan-row">
                  <td class="whitespace-nowrap px-3 py-1.5">{{ formatDate(row.due_on) }}</td>
                  <td class="whitespace-nowrap px-3 py-1.5 text-xs text-neutral-600">
                    <template v-if="row.from_on && row.to_on">{{ formatDate(row.from_on) }} – {{ formatDate(row.to_on) }}</template>
                    <span v-if="row.days" class="ml-1 text-neutral-400">({{ t('accruals.detail.days', row.days) }})</span>
                  </td>
                  <td class="whitespace-nowrap px-3 py-1.5 text-right font-mono">{{ formatMoney(row.amount_planned) }}</td>
                </tr>
              </tbody>
              <tfoot class="border-t border-neutral-200 bg-neutral-50 font-semibold">
                <tr>
                  <td class="px-3 py-1.5" colspan="2">{{ t('accruals.detail.total') }}</td>
                  <td class="whitespace-nowrap px-3 py-1.5 text-right font-mono" data-test="plan-total">{{ formatMoney(planTotal) }}</td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </section>

      <section class="rounded-lg border border-neutral-200 bg-surface p-4">
        <h2 class="mb-4 font-semibold">{{ t('accruals.editor.other') }}</h2>
        <div class="grid gap-4 sm:grid-cols-2">
          <div class="text-sm font-medium">
            <span>{{ t('accruals.editor.partner_directory') }}</span>
            <ClientSearchSelect v-model="form.partner_id" :selected-label="form.partner_name" class="mt-1 block" @selected="onPartnerSelected" />
          </div>
          <label class="text-sm font-medium">{{ t('accruals.partner') }}
            <input v-model="form.partner_name" maxlength="190" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2" @input="form.partner_id = null" />
          </label>
          <label class="text-sm font-medium">{{ t('accruals.contract_ref') }}
            <input v-model="form.contract_ref" maxlength="100" data-test="contract-ref" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2" />
          </label>
          <label class="text-sm font-medium">{{ t('accruals.cost_center') }}
            <input v-model="form.cost_center" :list="`${pageId}-cost-centers`" maxlength="50" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2" />
            <datalist :id="`${pageId}-cost-centers`">
              <option v-for="center in costCenters" :key="center.id" :value="center.code">{{ center.code }} - {{ center.name }}</option>
            </datalist>
          </label>
          <label class="text-sm font-medium sm:col-span-2">{{ t('accruals.note') }}
            <textarea v-model="form.note" rows="3" maxlength="10000" class="mt-1 block w-full rounded-md border border-neutral-300 bg-surface p-2" />
          </label>
        </div>
      </section>

      <label v-if="!isEdit" class="flex items-start gap-2 text-sm">
        <input v-model="form.activate" type="checkbox" data-test="activate" class="mt-0.5 h-4 w-4 rounded border-neutral-300 text-primary-600" />
        <span><span class="font-medium">{{ t('accruals.editor.activate') }}</span><span class="block text-xs text-neutral-500">{{ t('accruals.editor.activate_hint') }}</span></span>
      </label>

      <p v-if="error" class="text-sm text-danger-600" role="alert" data-test="error">{{ error }}</p>

      <div class="flex flex-wrap gap-2">
        <button type="submit" :disabled="saving" data-test="save" :class="[btnFilled('primary'), 'whitespace-nowrap']">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
        <RouterLink :to="isEdit ? { name: 'accounting-accrual-detail', params: { id: editId } } : { name: 'accounting-accruals' }" :class="[btnOutline('neutral'), 'whitespace-nowrap']">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </RouterLink>
      </div>
    </form>
  </div>
</template>
