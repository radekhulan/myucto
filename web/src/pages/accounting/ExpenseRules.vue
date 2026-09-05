<script setup lang="ts">
/**
 * Pravidla zaúčtování nákladů (§ automatizace) — editor pro tabulku expense-rules.
 *
 * Pravidlo předvyplní druh nákladu (expense_kind) na řádcích přijatých faktur podle
 * dodavatele / fragmentu názvu / fragmentu popisu. Shoda = VŠECHNA vyplněná kritéria (AND),
 * první podle priority vyhrává. Účtování samo neřeší — jen navrhuje druh a případně účet.
 */
import { ref, reactive, computed, onMounted, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  expenseRulesApi,
  expenseRuleErrorMessage,
  type ExpenseRule,
  type ExpenseKind,
  type ExpenseRulePayload,
} from '@/api/expenseRules'
import { accountingApi, type ChartAccount } from '@/api/accounting'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import Modal from '@/components/ui/Modal.vue'
import VendorPicker from '@/components/purchase/VendorPicker.vue'
import ChartAccountSelect from '@/components/accounting/ChartAccountSelect.vue'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'

// embedded = vykresleno jako záložka pod Šablony (menší nadpis, stránka má vlastní hlavičku).
const props = defineProps<{
  embedded?: boolean
  initialDraft?: { vendorId: number | null; vendorName?: string | null; description?: string | null; ruleId?: number; rule?: Partial<ExpenseRulePayload> }
}>()
const emit = defineEmits<{ close: []; saved: [] }>()

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const pageId = useId()

const canWrite = computed(() => auth.canWrite('accounting'))

const EXPENSE_KINDS: ExpenseKind[] = ['service', 'material', 'small_asset', 'small_intangible', 'fixed_asset']
// Účet odvozený z druhu nákladu, když pravidlo nemá vlastní target_account_code.
const KIND_DEFAULT_ACCOUNT: Record<ExpenseKind, string> = {
  service: '518',
  material: '501',
  small_asset: '501',
  small_intangible: '518',
  fixed_asset: '042',
}
function kindLabel(k: ExpenseKind): string {
  return t(`purchase_invoice.expense_kind.${k}`)
}

// ── seznam ──────────────────────────────────────────────────────────────────
const items = ref<ExpenseRule[]>([])
const accounts = ref<ChartAccount[]>([])
const loading = ref(false)
const busyId = ref<number | null>(null)
const page = ref(1)
const total = ref(0)
const perPage = ref(50)
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))

const filters = reactive({
  expense_kind: '' as ExpenseKind | '',
  active: '' as '' | 'true' | 'false',
})

const accountByCode = computed<Record<string, ChartAccount>>(() => {
  const m: Record<string, ChartAccount> = {}
  for (const a of accounts.value) m[a.account_code] = a
  return m
})
function accountName(code: string): string {
  return accountByCode.value[code]?.name ?? ''
}
// Účty na výběr do cíle: jen nákladové (501/518/548…) a aktivní.
const expenseAccounts = computed(() =>
  accounts.value
    .filter(a => (a.account_type === 'expense' || a.account_code.startsWith('04')) && a.is_active && !a.is_synthetic)
    .sort((a, b) => a.account_code.localeCompare(b.account_code)),
)

function effectiveAccount(r: ExpenseRule): string {
  return r.target_account_code || KIND_DEFAULT_ACCOUNT[r.expense_kind]
}

function amountRange(r: ExpenseRule): string {
  if (r.amount_min == null && r.amount_max == null) return ''
  if (r.amount_min == null) return `≤ ${formatMoney(r.amount_max!)}`
  if (r.amount_max == null) return `≥ ${formatMoney(r.amount_min)}`
  return `${formatMoney(r.amount_min)} – ${formatMoney(r.amount_max)}`
}

async function load() {
  loading.value = true
  try {
    const [r, accs] = await Promise.all([
      expenseRulesApi.listRules({
        expense_kind: filters.expense_kind || undefined,
        active: filters.active === '' ? undefined : filters.active === 'true',
        page: page.value,
      }),
      accountingApi.listAccounts(),
    ])
    if (r.items.length === 0 && r.total > 0 && page.value > 1) {
      page.value = Math.max(1, Math.ceil(r.total / r.per_page))
      return load()
    }
    items.value = r.items
    total.value = r.total
    perPage.value = r.per_page
    accounts.value = accs
  } catch (e) {
    toast.error(expenseRuleErrorMessage(e, t))
  } finally {
    loading.value = false
  }
}

function applyFilters() {
  page.value = 1
  load()
}
function goPage(p: number) {
  page.value = Math.min(Math.max(1, p), totalPages.value)
  load()
}

async function toggleActive(r: ExpenseRule) {
  if (busyId.value) return
  busyId.value = r.id
  try {
    await expenseRulesApi.updateRule(r.id, { is_active: !r.is_active })
    await load()
  } catch (e) {
    toast.error(expenseRuleErrorMessage(e, t))
  } finally {
    busyId.value = null
  }
}

async function removeRule(r: ExpenseRule) {
  if (busyId.value) return
  if (!window.confirm(t('accounting.expense_rules.confirm_delete', { name: r.name }))) return
  busyId.value = r.id
  try {
    await expenseRulesApi.deleteRule(r.id)
    toast.success(t('accounting.expense_rules.deleted'))
    await load()
  } catch (e) {
    toast.error(expenseRuleErrorMessage(e, t))
  } finally {
    busyId.value = null
  }
}

// ── formulář: založení / editace ────────────────────────────────────────────
const showForm = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const bindVendor = ref(true)
const form = reactive({
  name: '',
  vendor_client_id: null as number | null,
  vendor_name_contains: '',
  description_contains: '',
  amount_min: null as number | null,
  amount_max: null as number | null,
  expense_kind: 'service' as ExpenseKind,
  target_account_code: '',
  application_mode: 'auto' as 'suggest' | 'auto',
  priority: 100,
  is_active: true,
})

// Aspoň jedno kritérium (dodavatel / název / popis) — částka sama pravidlo netvoří.
const hasCriteria = computed(() =>
  form.vendor_client_id != null ||
  form.vendor_name_contains.trim() !== '' ||
  form.description_contains.trim() !== '',
)
const recommendationCreate = computed(() => !!props.initialDraft && !props.initialDraft.ruleId)
const validCriteria = computed(() => recommendationCreate.value
  ? form.description_contains.trim() !== '' && (!bindVendor.value || form.vendor_client_id != null || form.vendor_name_contains.trim() !== '')
  : hasCriteria.value)

function resetForm() {
  form.name = ''
  form.vendor_client_id = null
  form.vendor_name_contains = ''
  form.description_contains = ''
  form.amount_min = null
  form.amount_max = null
  form.expense_kind = 'service'
  form.target_account_code = ''
  form.application_mode = 'auto'
  form.priority = 100
  form.is_active = true
}

function openNew() {
  editingId.value = null
  resetForm()
  showForm.value = true
}

function closeForm() {
  showForm.value = false
  if (props.initialDraft) emit('close')
}

function openEdit(r: ExpenseRule) {
  editingId.value = r.id
  form.name = r.name
  form.vendor_client_id = r.vendor_client_id
  form.vendor_name_contains = r.vendor_name_contains ?? ''
  form.description_contains = r.description_contains ?? ''
  form.amount_min = r.amount_min
  form.amount_max = r.amount_max
  form.expense_kind = r.expense_kind
  form.target_account_code = r.target_account_code ?? ''
  form.application_mode = r.application_mode
  form.priority = r.priority
  form.is_active = r.is_active
  showForm.value = true
}

async function saveRule() {
  if (!form.name.trim()) {
    toast.error(t('accounting.expense_rules.name_required'))
    return
  }
  if (!validCriteria.value) {
    toast.error(t(recommendationCreate.value ? 'accounting.template.expense_description_hint' : 'accounting.expense_rules.err_criteria_missing'))
    return
  }
  saving.value = true
  try {
    const payload: ExpenseRulePayload = {
      name: form.name.trim(),
      vendor_client_id: props.initialDraft && !bindVendor.value ? null : form.vendor_client_id,
      vendor_name_contains: props.initialDraft && !bindVendor.value ? null : form.vendor_name_contains.trim() || null,
      description_contains: form.description_contains.trim() || null,
      amount_min: form.amount_min,
      amount_max: form.amount_max,
      expense_kind: form.expense_kind,
      target_account_code: form.target_account_code.trim() || null,
      application_mode: form.application_mode,
      priority: Number(form.priority),
      is_active: form.is_active,
    }
    if (editingId.value === null) {
      await expenseRulesApi.createRule(payload)
      toast.success(t('accounting.expense_rules.created'))
    } else {
      await expenseRulesApi.updateRule(editingId.value, payload)
      toast.success(t('accounting.expense_rules.updated'))
    }
    showForm.value = false
    if (props.initialDraft) emit('saved')
    else await load()
  } catch (e) {
    toast.error(expenseRuleErrorMessage(e, t))
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  if (!props.initialDraft) return load()

  try { accounts.value = await accountingApi.listAccounts() }
  catch (e) { toast.error(expenseRuleErrorMessage(e, t)) }

  if (props.initialDraft.ruleId) {
    try {
      const rule = await expenseRulesApi.getRule(props.initialDraft.ruleId)
      openEdit(rule)
      bindVendor.value = rule.vendor_client_id != null || !!rule.vendor_name_contains?.trim()
    } catch (e) {
      toast.error(expenseRuleErrorMessage(e, t))
      emit('close')
    }
    return
  }

  openNew()
  form.vendor_client_id = props.initialDraft.vendorId
  form.name = props.initialDraft.vendorName || ''
  form.description_contains = props.initialDraft.description || ''
  if (props.initialDraft.rule) {
    Object.assign(form, props.initialDraft.rule)
    form.vendor_name_contains = props.initialDraft.rule.vendor_name_contains ?? ''
    form.description_contains = props.initialDraft.rule.description_contains ?? form.description_contains ?? ''
    form.target_account_code = props.initialDraft.rule.target_account_code ?? ''
  }
  bindVendor.value = form.vendor_client_id != null
  form.application_mode = 'suggest'
})
</script>

<template>
  <div>
    <template v-if="!initialDraft">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 v-if="!embedded" class="text-2xl font-semibold">{{ t('accounting.expense_rules.title') }}</h1>
        <h2 v-else class="text-xl font-semibold">{{ t('accounting.expense_rules.title') }}</h2>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.expense_rules.subtitle') }}</p>
      </div>
      <button v-if="canWrite" @click="openNew" :class="btnFilled('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('accounting.expense_rules.new') }}
      </button>
    </div>

    <!-- Filtry -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.expense_rules.filter_kind') }}</label>
          <select v-model="filters.expense_kind" @change="applyFilters" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="">{{ t('accounting.expense_rules.kind_all') }}</option>
            <option v-for="k in EXPENSE_KINDS" :key="k" :value="k">{{ kindLabel(k) }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.expense_rules.filter_active') }}</label>
          <select v-model="filters.active" @change="applyFilters" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="">{{ t('accounting.expense_rules.active_all') }}</option>
            <option value="true">{{ t('accounting.expense_rules.active_yes') }}</option>
            <option value="false">{{ t('accounting.expense_rules.active_no') }}</option>
          </select>
        </div>
      </div>
    </div>

    <!-- Seznam -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div v-if="loading" class="p-8 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
      <EmptyState v-else-if="items.length === 0" icon="cycle"
        :title="t('accounting.expense_rules.empty')"
        :cta="canWrite ? t('accounting.expense_rules.new') : undefined"
        :secondary="t('accounting.setup_assistant.open')"
        secondary-to="/accounting/setup-assistant"
        secondary-icon="chart"
        @action="openNew" />
      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500">
            <tr>
              <th class="text-left font-medium px-3 py-2">{{ t('accounting.expense_rules.col_name') }}</th>
              <th class="text-left font-medium px-3 py-2">{{ t('accounting.expense_rules.col_criteria') }}</th>
              <th class="text-left font-medium px-3 py-2">{{ t('accounting.expense_rules.col_target') }}</th>
              <th class="text-right font-medium px-3 py-2">{{ t('accounting.expense_rules.col_priority') }}</th>
              <th class="text-left font-medium px-3 py-2">{{ t('accounting.expense_rules.col_usage') }}</th>
              <th class="text-left font-medium px-3 py-2">{{ t('accounting.expense_rules.col_active') }}</th>
              <th class="px-3 py-2"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in items" :key="r.id" class="border-t border-neutral-100" :class="{ 'opacity-50': !r.is_active }">
              <td class="px-3 py-2">
                <div class="font-medium text-neutral-700">{{ r.name }}</div>
              </td>
              <td class="px-3 py-2 text-xs text-neutral-500">
                <div class="space-y-0.5">
                  <div v-if="r.vendor_client_name">
                    <span class="text-neutral-400">{{ t('accounting.expense_rules.form_vendor') }}:</span> {{ r.vendor_client_name }}
                  </div>
                  <div v-if="r.vendor_name_contains" class="italic">„{{ r.vendor_name_contains }}"</div>
                  <div v-if="r.description_contains" class="italic">„{{ r.description_contains }}"</div>
                  <div v-if="amountRange(r)" class="font-mono text-neutral-400">{{ amountRange(r) }}</div>
                </div>
              </td>
              <td class="px-3 py-2 whitespace-nowrap">
                <span class="text-xs px-2 py-0.5 rounded-full bg-primary-50 text-primary-700 font-medium">{{ kindLabel(r.expense_kind) }}</span>
                <span class="ml-1 text-xs px-2 py-0.5 rounded-full" :class="r.application_mode === 'auto' ? 'bg-success-50 text-success-600' : 'bg-warning-50 text-warning-600'">
                  {{ t(`accounting.expense_rules.mode_${r.application_mode}`) }}
                </span>
                <div class="text-xs text-neutral-500 mt-0.5 font-mono" :title="accountName(effectiveAccount(r))">
                  → {{ effectiveAccount(r) }}
                  <span v-if="!r.target_account_code" class="not-italic text-neutral-400">({{ t('accounting.expense_rules.derived') }})</span>
                </div>
              </td>
              <td class="px-3 py-2 text-right font-mono text-xs">{{ r.priority }}</td>
              <td class="px-3 py-2 text-xs text-neutral-500 whitespace-nowrap">
                <div>{{ t('accounting.expense_rules.usage', { count: r.hit_count }) }}</div>
                <div v-if="r.last_hit_at" class="text-neutral-400">{{ formatDate(r.last_hit_at) }}</div>
                <div v-else class="text-neutral-400">{{ t('accounting.expense_rules.never_used') }}</div>
              </td>
              <td class="px-3 py-2">
                <button v-if="canWrite" @click="toggleActive(r)" :disabled="busyId === r.id"
                  class="cursor-pointer text-xs px-2 py-0.5 rounded font-medium disabled:opacity-50"
                  :class="r.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'">
                  {{ r.is_active ? t('accounting.expense_rules.active_yes') : t('accounting.expense_rules.active_no') }}
                </button>
                <span v-else class="text-xs px-2 py-0.5 rounded font-medium"
                  :class="r.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'">
                  {{ r.is_active ? t('accounting.expense_rules.active_yes') : t('accounting.expense_rules.active_no') }}
                </span>
              </td>
              <td class="px-3 py-2">
                <div v-if="canWrite" class="flex flex-wrap items-center justify-end gap-1">
                  <button @click="openEdit(r)" :class="btnOutlineSm('primary')" :title="t('common.edit')">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                  </button>
                  <button @click="removeRule(r)" :disabled="busyId === r.id" :class="btnOutlineSm('danger')" :title="t('common.delete')">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="totalPages > 1" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 border-t border-neutral-100">
        <span class="text-xs text-neutral-500">{{ t('accounting.expense_rules.count', { total }) }}</span>
        <div class="flex items-center gap-2">
          <button :disabled="page <= 1" @click="goPage(page - 1)" :class="btnOutlineSm('neutral')">{{ t('common.previous') }}</button>
          <span class="text-xs text-neutral-500">{{ page }} / {{ totalPages }}</span>
          <button :disabled="page >= totalPages" @click="goPage(page + 1)" :class="btnOutlineSm('neutral')">{{ t('common.next') }}</button>
        </div>
      </div>
    </div>

    </template>
    <!-- Modal: pravidlo -->
    <Modal v-if="showForm" :title="editingId === null ? t('accounting.expense_rules.new') : t('accounting.expense_rules.edit')" @close="closeForm">
      <p class="text-sm text-neutral-600 mb-4">{{ t('accounting.expense_rules.form_help') }}</p>
          <p v-if="recommendationCreate" class="text-sm text-neutral-600 mb-4">{{ t('accounting.template.expense_hint') }}</p>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="sm:col-span-2">
          <label :for="`${pageId}-name`" class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.expense_rules.form_name') }} *</label>
          <input :id="`${pageId}-name`" v-model="form.name" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
        </div>

        <!-- Kritéria (AND) -->
        <div class="sm:col-span-2 border border-neutral-200 rounded-md p-3">
          <div class="text-xs font-medium text-neutral-500 mb-2">{{ t('accounting.expense_rules.criteria_group') }}</div>
          <div class="grid grid-cols-1 gap-3">
            <label v-if="initialDraft" class="flex items-center gap-2 text-sm text-neutral-700 cursor-pointer">
              <input :id="`${pageId}-bind-vendor`" v-model="bindVendor" type="checkbox" class="rounded border-neutral-300" />
              {{ t('accounting.template.bind_vendor') }}
            </label>
            <p v-if="initialDraft && !bindVendor" class="text-xs text-neutral-500">{{ t('accounting.template.all_vendors') }}</p>
            <div v-if="!initialDraft || bindVendor">
              <VendorPicker v-model="form.vendor_client_id" />
            </div>
            <div v-if="!initialDraft || bindVendor">
              <label :for="`${pageId}-vendor-name`" class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.expense_rules.form_vendor_name_contains') }}</label>
              <input :id="`${pageId}-vendor-name`" v-model="form.vendor_name_contains" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
            </div>
            <div>
          <label :for="`${pageId}-description`" class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.expense_rules.form_description_contains') }}{{ recommendationCreate ? ' *' : '' }}</label>
          <input :id="`${pageId}-description`" v-model="form.description_contains" :required="recommendationCreate" :placeholder="recommendationCreate ? t('accounting.template.expense_description_placeholder') : undefined" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
            </div>
          </div>
          <p class="text-xs mt-2" :class="validCriteria ? 'text-neutral-400' : 'text-danger-500'">
            {{ t(recommendationCreate ? 'accounting.template.expense_description_hint' : 'accounting.expense_rules.criteria_hint') }}
          </p>
        </div>

        <!-- Zúžení částkou -->
        <div class="sm:col-span-2">
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.expense_rules.form_amount_band') }}</label>
          <div class="flex items-center gap-2">
            <input v-model.number="form.amount_min" type="number" step="0.01" :placeholder="t('accounting.expense_rules.amount_min')"
                   class="w-full min-w-0 h-9 px-2 border border-neutral-300 rounded-md text-sm text-right bg-surface" />
            <span class="text-neutral-400">–</span>
            <input v-model.number="form.amount_max" type="number" step="0.01" :placeholder="t('accounting.expense_rules.amount_max')"
                   class="w-full min-w-0 h-9 px-2 border border-neutral-300 rounded-md text-sm text-right bg-surface" />
          </div>
          <p class="text-xs text-neutral-400 mt-1">{{ t('accounting.expense_rules.amount_hint') }}</p>
        </div>

        <!-- Výsledek -->
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.expense_rules.form_kind') }} *</label>
          <select v-model="form.expense_kind" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option v-for="k in EXPENSE_KINDS" :key="k" :value="k">{{ kindLabel(k) }}</option>
          </select>
          <p class="text-xs text-neutral-400 mt-1">{{ t('accounting.expense_rules.kind_hint') }}</p>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.expense_rules.form_target_account') }}</label>
          <ChartAccountSelect v-model="form.target_account_code" :accounts="expenseAccounts"
            :input-id="`${pageId}-target-account`" :aria-label="t('accounting.expense_rules.form_target_account')" />
          <p class="text-xs text-neutral-400 mt-1">{{ t('accounting.expense_rules.target_account_hint') }}</p>
        </div>

        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.expense_rules.form_priority') }}</label>
          <input v-model.number="form.priority" type="number" min="0" max="999" step="1"
                 class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm text-right bg-surface" />
          <p class="text-xs text-neutral-400 mt-1">{{ t('accounting.expense_rules.priority_hint') }}</p>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.expense_rules.form_application_mode') }}</label>
          <select v-model="form.application_mode" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="suggest">{{ t('accounting.expense_rules.mode_suggest') }}</option>
            <option value="auto">{{ t('accounting.expense_rules.mode_auto') }}</option>
          </select>
          <p class="text-xs text-neutral-400 mt-1">{{ t('accounting.expense_rules.application_mode_hint') }}</p>
        </div>
        <div class="flex items-end">
          <label class="flex items-center gap-2 text-sm text-neutral-700 cursor-pointer h-9">
            <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300" />
            {{ t('accounting.expense_rules.form_active') }}
          </label>
        </div>
      </div>

      <div class="flex flex-wrap justify-end gap-2 mt-4">
        <button @click="closeForm" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
        <button :disabled="saving || !validCriteria || !form.name.trim()" @click="saveRule" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ t('common.save') }}
        </button>
      </div>
    </Modal>
  </div>
</template>
