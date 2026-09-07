<script setup lang="ts">
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { assetsApi, type AccMethod, type AssetKind, type AssetPayload, type AssetStatus, type FirstYearIncrease, type TaxMethod } from '@/api/assets'
import { accountingApi, type ChartAccount } from '@/api/accounting'
import { useToast } from '@/composables/useToast'
import { formatMoney } from '@/composables/useFormat'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import DateInput from '@/components/ui/DateInput.vue'

const { t } = useI18n()
const toast = useToast()
const route = useRoute()
const router = useRouter()

const assetId = computed(() => (route.params.id ? Number(route.params.id) : null))
const isEdit = computed(() => assetId.value !== null)

const loading = ref(false)
const saving = ref(false)

const form = reactive({
  inventory_number: '',
  name: '',
  description: '',
  kind: 'tangible' as AssetKind,
  asset_account_code: '022',
  accumulated_account_code: '082' as string | null,
  acquisition_account_code: '042',
  purchase_invoice_id: null as number | null,
  input_price: null as number | null,
  acquisition_date: '',
  put_into_use_date: '' as string,
  tax_method: 'straight' as TaxMethod,
  tax_group: null as number | null,
  tax_first_year_increase: 'none' as FirstYearIncrease,
  is_first_owner: false,
  is_m1_vehicle: false,
  m1_limit_exception: false,
  is_zero_emission: false,
  opening_tax_years: 0,
  opening_tax_amount: 0,
  opening_acc_months: 0,
  opening_acc_amount: 0,
  acc_useful_life_months: null as number | null,
  acc_method: 'straight_line' as AccMethod,
  acc_residual_value: 0,
})

const status = ref<AssetStatus>('draft')
const isHistorical = ref(false)
const locks = reactive({ input_price: false, acquisition: false, tax_params: false })

// ── Osnova (selecty účtů) ──────────────────────────────────────────────────
const accounts = ref<ChartAccount[]>([])

function accountOptions(prefixes: string[]) {
  return accounts.value
    .filter(a => a.is_active && prefixes.some(p => a.account_code.startsWith(p)))
    .map(a => ({ value: a.account_code, label: `${a.account_code} — ${a.name}` }))
}
const assetAccountOptions = computed(() => accountOptions(['01', '02', '03']))
const accumulatedAccountOptions = computed(() => accountOptions(['07', '08']))
const acquisitionAccountOptions = computed(() => accountOptions(['041', '042']))

/** Mapa odvození oprávek z majetkového účtu (R18). */
const ACCUMULATED_MAP: Record<string, string | null> = {
  '012': '072', '013': '073', '014': '074', '015': '075', '019': '079',
  '021': '081', '022': '082', '025': '085', '026': '086', '029': '089',
  '031': null, '032': null,
}

function onAssetAccountChange(code: string | null) {
  form.asset_account_code = code || ''
  if (!code) return
  const synthetic = code.slice(0, 3)
  if (synthetic in ACCUMULATED_MAP) {
    form.accumulated_account_code = ACCUMULATED_MAP[synthetic]
  }
  form.acquisition_account_code = synthetic.startsWith('01') ? '041' : '042'
  if (form.accumulated_account_code === null) {
    form.tax_method = 'none'
    form.acc_useful_life_months = null
  }
}

// ── Validační matice (§3.3) ────────────────────────────────────────────────
const methodOptions = computed<TaxMethod[]>(() =>
  form.kind === 'intangible' ? ['by_accounting', 'none'] : ['straight', 'accelerated', 'extraordinary', 'none'])

const showTaxGroup = computed(() => form.tax_method === 'straight' || form.tax_method === 'accelerated')
const showIncrease = computed(() =>
  showTaxGroup.value && form.is_first_owner && form.tax_group !== null && form.tax_group >= 1 && form.tax_group <= 3)
const showVehicleFlags = computed(() => form.kind === 'tangible' && form.tax_method !== 'none')
const isExtraordinary = computed(() => form.tax_method === 'extraordinary')
const isDepreciable = computed(() => form.accumulated_account_code !== null && form.accumulated_account_code !== '')

// „Účetní = daňový" nemá u neodpisovaného majetku (tax_method='none') co zrcadlit.
const accMethodOptions = computed<AccMethod[]>(() =>
  form.tax_method === 'none' ? ['straight_line'] : ['straight_line', 'by_tax'])
const isAccByTax = computed(() => form.acc_method === 'by_tax')

watch(() => form.kind, (kind) => {
  if (kind === 'intangible') {
    if (!methodOptions.value.includes(form.tax_method)) form.tax_method = 'by_accounting'
    form.tax_group = null
    form.is_m1_vehicle = false
    form.m1_limit_exception = false
    form.is_zero_emission = false
    if (!isEdit.value) { form.asset_account_code = '013'; form.accumulated_account_code = '073'; form.acquisition_account_code = '041' }
  } else if (!methodOptions.value.includes(form.tax_method)) {
    form.tax_method = 'straight'
    if (!isEdit.value) { form.asset_account_code = '022'; form.accumulated_account_code = '082'; form.acquisition_account_code = '042' }
  }
})

watch(() => form.tax_method, (m) => {
  if (m !== 'straight' && m !== 'accelerated') {
    form.tax_group = null
    form.tax_first_year_increase = 'none'
  }
  if (m === 'none') form.acc_method = 'straight_line'
})
watch([() => form.tax_group, () => form.is_first_owner], () => {
  if (!showIncrease.value) form.tax_first_year_increase = 'none'
})

// ── Načtení (edit / prefill z PF) ──────────────────────────────────────────
onMounted(async () => {
  loading.value = true
  try {
    try { accounts.value = await accountingApi.listAccounts() } catch { accounts.value = [] }
    if (isEdit.value) {
      const a = await assetsApi.get(assetId.value!)
      form.inventory_number = a.inventory_number
      form.name = a.name
      form.description = a.description || ''
      form.kind = a.kind
      form.asset_account_code = a.asset_account_code
      form.accumulated_account_code = a.accumulated_account_code
      form.acquisition_account_code = a.acquisition_account_code
      form.purchase_invoice_id = a.purchase_invoice_id
      form.input_price = Number(a.input_price)
      form.acquisition_date = a.acquisition_date
      form.put_into_use_date = a.put_into_use_date || ''
      form.tax_method = a.tax_method
      form.tax_group = a.tax_group
      form.tax_first_year_increase = a.tax_first_year_increase
      form.is_first_owner = !!Number(a.is_first_owner)
      form.is_m1_vehicle = !!Number(a.is_m1_vehicle)
      form.m1_limit_exception = !!Number(a.m1_limit_exception)
      form.is_zero_emission = !!Number(a.is_zero_emission)
      form.opening_tax_years = Number(a.opening_tax_years) || 0
      form.opening_tax_amount = Number(a.opening_tax_amount) || 0
      form.opening_acc_months = Number(a.opening_acc_months) || 0
      form.opening_acc_amount = Number(a.opening_acc_amount) || 0
      form.acc_useful_life_months = a.acc_useful_life_months
      form.acc_method = a.acc_method ?? 'straight_line'
      form.acc_residual_value = Number(a.acc_residual_value) || 0
      status.value = a.status
      isHistorical.value = a.opening_tax_years > 0 || Number(a.opening_tax_amount) > 0
        || a.opening_acc_months > 0 || Number(a.opening_acc_amount) > 0
      // Zamčená pole (R13): flagy z API (locked.tax_params/acquisition), fallback dle stavu karty.
      const afterPutIntoUse = a.status !== 'draft'
      locks.tax_params = a.locked?.tax_params ?? false
      locks.acquisition = a.locked?.acquisition ?? a.locked?.in_use ?? afterPutIntoUse
      locks.input_price = locks.acquisition || locks.tax_params
    } else if (route.query.invoice_id) {
      await prefillFromInvoice(Number(route.query.invoice_id))
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
  }
})

/** Předvyplnění z PF kandidáta — VC dle R25 (bez nároku na odpočet z ceny s DPH). */
async function prefillFromInvoice(invoiceId: number) {
  const candidates = await assetsApi.purchaseCandidates()
  const c = candidates.find(x => x.id === invoiceId)
  if (!c) return
  form.purchase_invoice_id = c.id
  const rate = Number(c.exchange_rate) || 1
  const base = c.vat_deduction === 'none' ? Number(c.total_with_vat) : Number(c.total_without_vat)
  form.input_price = Math.round(base * rate * 100) / 100
  form.acquisition_date = c.tax_date || c.issue_date || ''
  if (c.description) form.name = c.description
  else if (c.vendor) form.name = `${c.vendor} ${c.varsymbol || c.vendor_invoice_number || ''}`.trim()
}

// ── Uložení ────────────────────────────────────────────────────────────────
const errors = ref<string[]>([])

function validate(): boolean {
  errors.value = []
  if (!form.inventory_number.trim()) errors.value.push(t('accounting.assets.editor.err_inventory_number'))
  if (!form.name.trim()) errors.value.push(t('accounting.assets.editor.err_name'))
  if (!form.input_price || form.input_price <= 0) errors.value.push(t('accounting.assets.editor.err_input_price'))
  if (!form.acquisition_date) errors.value.push(t('accounting.assets.editor.err_acquisition_date'))
  if (showTaxGroup.value && !form.tax_group) errors.value.push(t('accounting.assets.editor.err_tax_group'))
  if (isExtraordinary.value && (!form.is_zero_emission || !form.is_first_owner)) {
    errors.value.push(t('accounting.assets.editor.err_extraordinary'))
  }
  if (isDepreciable.value && !isAccByTax.value && (!form.acc_useful_life_months || form.acc_useful_life_months < 1)) {
    errors.value.push(t('accounting.assets.editor.err_acc_months'))
  }
  if (isHistorical.value && !form.put_into_use_date) {
    errors.value.push(t('accounting.assets.editor.err_historical_date'))
  }
  return errors.value.length === 0
}

async function save() {
  if (!validate()) return
  saving.value = true
  try {
    const payload: AssetPayload = {
      inventory_number: form.inventory_number.trim(),
      name: form.name.trim(),
      description: form.description.trim() || null,
      kind: form.kind,
      asset_account_code: form.asset_account_code,
      accumulated_account_code: isDepreciable.value ? form.accumulated_account_code : null,
      acquisition_account_code: form.acquisition_account_code,
      purchase_invoice_id: form.purchase_invoice_id,
      input_price: Number(form.input_price),
      acquisition_date: form.acquisition_date,
      tax_method: form.tax_method,
      tax_group: showTaxGroup.value ? form.tax_group : null,
      tax_first_year_increase: showIncrease.value ? form.tax_first_year_increase : 'none',
      is_first_owner: form.is_first_owner,
      is_m1_vehicle: showVehicleFlags.value ? form.is_m1_vehicle : false,
      m1_limit_exception: showVehicleFlags.value ? form.m1_limit_exception : false,
      is_zero_emission: showVehicleFlags.value ? form.is_zero_emission : false,
      acc_method: isDepreciable.value ? form.acc_method : 'straight_line',
      acc_useful_life_months: isDepreciable.value && !isAccByTax.value ? form.acc_useful_life_months : null,
      acc_residual_value: isDepreciable.value && !isAccByTax.value ? Number(form.acc_residual_value) || 0 : 0,
    }
    if (isHistorical.value) {
      payload.status = 'in_use'
      payload.put_into_use_date = form.put_into_use_date
      payload.opening_tax_years = Number(form.opening_tax_years) || 0
      payload.opening_tax_amount = Number(form.opening_tax_amount) || 0
      payload.opening_acc_months = Number(form.opening_acc_months) || 0
      payload.opening_acc_amount = Number(form.opening_acc_amount) || 0
    }
    const result = isEdit.value
      ? await assetsApi.update(assetId.value!, payload)
      : await assetsApi.create(payload)
    for (const w of result.warnings || []) toast.warning(w.message)
    toast.success(t('common.saved'))
    router.push({ name: 'accounting-asset-detail', params: { id: result.asset?.id ?? assetId.value } })
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    saving.value = false
  }
}

const inputCls = 'w-full h-9 px-2 border border-neutral-300 rounded-md text-sm disabled:bg-neutral-50 disabled:text-neutral-400'
const selectCls = inputCls + ' bg-surface'
const labelCls = 'block text-xs font-medium text-neutral-500 mb-1'
const lockedTitle = computed(() => t('accounting.assets.editor.locked_hint'))
</script>

<template>
  <div class="max-w-4xl">
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-semibold">
          {{ isEdit ? t('accounting.assets.editor.title_edit') : t('accounting.assets.editor.title_new') }}
        </h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.assets.editor.subtitle') }}</p>
      </div>
      <RouterLink :to="{ name: 'accounting-assets' }" class="text-sm text-neutral-500 hover:text-neutral-700">
        {{ t('common.back') }}
      </RouterLink>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <form v-else @submit.prevent="save" class="space-y-4">
      <!-- Identifikace -->
      <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
        <h2 class="text-sm font-semibold mb-3">{{ t('accounting.assets.editor.section_identity') }}</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div>
            <label :class="labelCls">{{ t('accounting.assets.fields.inventory_number') }} *</label>
            <input v-model="form.inventory_number" type="text" placeholder="M-000001" :class="inputCls" />
          </div>
          <div class="sm:col-span-2">
            <label :class="labelCls">{{ t('accounting.assets.fields.name') }} *</label>
            <input v-model="form.name" type="text" :class="inputCls" />
          </div>
          <div class="sm:col-span-2">
            <label :class="labelCls">{{ t('accounting.assets.fields.description') }}</label>
            <input v-model="form.description" type="text" :class="inputCls" />
          </div>
          <div>
            <label :class="labelCls">{{ t('accounting.assets.fields.kind') }}</label>
            <select v-model="form.kind" :disabled="locks.tax_params" :title="locks.tax_params ? lockedTitle : undefined" :class="selectCls">
              <option value="tangible">{{ t('accounting.assets.kind.tangible') }}</option>
              <option value="intangible">{{ t('accounting.assets.kind.intangible') }}</option>
            </select>
          </div>
        </div>
      </section>

      <!-- Účty -->
      <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
        <h2 class="text-sm font-semibold mb-3">{{ t('accounting.assets.editor.section_accounts') }}</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div>
            <label :class="labelCls">{{ t('accounting.assets.fields.asset_account') }} *</label>
            <SearchableSelect :modelValue="form.asset_account_code" :options="assetAccountOptions"
              :clearable="false" @update:modelValue="onAssetAccountChange" />
          </div>
          <div>
            <label :class="labelCls">{{ t('accounting.assets.fields.accumulated_account') }}</label>
            <SearchableSelect :modelValue="form.accumulated_account_code" :options="accumulatedAccountOptions"
              :emptyLabel="t('accounting.assets.editor.no_depreciation')"
              @update:modelValue="v => form.accumulated_account_code = v" />
            <p class="text-xs text-neutral-400 mt-1">{{ t('accounting.assets.editor.accumulated_hint') }}</p>
          </div>
          <div>
            <label :class="labelCls">{{ t('accounting.assets.fields.acquisition_account') }} *</label>
            <SearchableSelect :modelValue="form.acquisition_account_code" :options="acquisitionAccountOptions"
              :clearable="false" @update:modelValue="v => form.acquisition_account_code = v || '042'" />
          </div>
        </div>
      </section>

      <!-- Pořízení -->
      <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
        <h2 class="text-sm font-semibold mb-3">{{ t('accounting.assets.editor.section_acquisition') }}</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div>
            <label :class="labelCls">{{ t('accounting.assets.fields.input_price') }} *</label>
            <input v-model.number="form.input_price" type="number" step="0.01" min="0"
              :disabled="locks.input_price" :title="locks.input_price ? lockedTitle : undefined" :class="inputCls" />
          </div>
          <div>
            <label :class="labelCls">{{ t('accounting.assets.fields.acquisition_date') }} *</label>
            <DateInput v-model="form.acquisition_date"
              :disabled="locks.acquisition" :title="locks.acquisition ? lockedTitle : undefined" :class="inputCls" />
          </div>
          <div v-if="form.purchase_invoice_id">
            <label :class="labelCls">{{ t('accounting.assets.fields.purchase_invoice') }}</label>
            <RouterLink :to="{ name: 'purchase-invoice-detail', params: { id: form.purchase_invoice_id } }"
              class="inline-flex items-center h-9 text-sm text-primary-600 hover:text-primary-700">
              {{ t('accounting.assets.editor.invoice_link', { id: form.purchase_invoice_id }) }}
            </RouterLink>
          </div>
        </div>
        <p v-if="form.input_price && form.input_price <= 80000 && form.kind === 'tangible' && form.tax_method !== 'none' && form.tax_method !== 'by_accounting'"
          class="mt-2 text-xs text-warning-600">
          {{ t('accounting.assets.hints.below_80k') }}
        </p>
      </section>

      <!-- Daňové odpisy -->
      <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
        <h2 class="text-sm font-semibold mb-1">{{ t('accounting.assets.editor.section_tax') }}</h2>
        <p v-if="locks.tax_params" class="text-xs text-warning-600 mb-2">{{ t('accounting.assets.editor.tax_locked') }}</p>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div>
            <label :class="labelCls">{{ t('accounting.assets.fields.tax_method') }}</label>
            <select v-model="form.tax_method" :disabled="locks.tax_params" :title="locks.tax_params ? lockedTitle : undefined" :class="selectCls">
              <option v-for="m in methodOptions" :key="m" :value="m">{{ t(`accounting.assets.method.${m}`) }}</option>
            </select>
          </div>
          <div v-if="showTaxGroup">
            <label :class="labelCls">{{ t('accounting.assets.fields.tax_group') }} *</label>
            <select v-model.number="form.tax_group" :disabled="locks.tax_params" :title="locks.tax_params ? lockedTitle : undefined" :class="selectCls">
              <option :value="null">—</option>
              <option v-for="g in [1, 2, 3, 4, 5, 6]" :key="g" :value="g">{{ t(`accounting.assets.group.${g}`) }}</option>
            </select>
          </div>
          <div v-if="showIncrease">
            <label :class="labelCls">{{ t('accounting.assets.fields.tax_first_year_increase') }}</label>
            <select v-model="form.tax_first_year_increase" :disabled="locks.tax_params" :title="locks.tax_params ? lockedTitle : undefined" :class="selectCls">
              <option v-for="i in ['none', 'p10', 'p15', 'p20']" :key="i" :value="i">{{ t(`accounting.assets.increase.${i}`) }}</option>
            </select>
          </div>
        </div>
        <div v-if="form.tax_method !== 'none' && form.tax_method !== 'by_accounting'" class="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-sm">
          <label class="inline-flex items-center gap-2">
            <input v-model="form.is_first_owner" type="checkbox" :disabled="locks.tax_params" class="rounded border-neutral-300" />
            {{ t('accounting.assets.fields.is_first_owner') }}
          </label>
          <template v-if="showVehicleFlags">
            <label class="inline-flex items-center gap-2">
              <input v-model="form.is_m1_vehicle" type="checkbox" :disabled="locks.tax_params" class="rounded border-neutral-300" />
              {{ t('accounting.assets.fields.is_m1_vehicle') }}
            </label>
            <label v-if="form.is_m1_vehicle" class="inline-flex items-center gap-2">
              <input v-model="form.m1_limit_exception" type="checkbox" :disabled="locks.tax_params" class="rounded border-neutral-300" />
              {{ t('accounting.assets.fields.m1_limit_exception') }}
            </label>
            <label class="inline-flex items-center gap-2">
              <input v-model="form.is_zero_emission" type="checkbox" :disabled="locks.tax_params" class="rounded border-neutral-300" />
              {{ t('accounting.assets.fields.is_zero_emission') }}
            </label>
          </template>
        </div>
        <p v-if="isExtraordinary" class="mt-2 text-xs text-neutral-500">{{ t('accounting.assets.editor.extraordinary_hint') }}</p>
        <p v-if="form.is_m1_vehicle && !form.m1_limit_exception && (form.input_price || 0) > 2000000"
          class="mt-2 text-xs text-warning-600">
          {{ t('accounting.assets.editor.m1_limit_hint', { limit: formatMoney(2000000) }) }}
        </p>
      </section>

      <!-- Účetní odpisy -->
      <section v-if="isDepreciable" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
        <h2 class="text-sm font-semibold mb-3">{{ t('accounting.assets.editor.section_accounting_dep') }}</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div>
            <label :class="labelCls">{{ t('accounting.assets.fields.acc_method') }}</label>
            <select v-model="form.acc_method" :class="selectCls">
              <option v-for="m in accMethodOptions" :key="m" :value="m">{{ t(`accounting.assets.accMethod.${m}`) }}</option>
            </select>
          </div>
          <template v-if="!isAccByTax">
            <div>
              <label :class="labelCls">{{ t('accounting.assets.fields.acc_useful_life_months') }} *</label>
              <input v-model.number="form.acc_useful_life_months" type="number" min="1" step="1" :class="inputCls" />
            </div>
            <div>
              <label :class="labelCls">{{ t('accounting.assets.fields.acc_residual_value') }}</label>
              <input v-model.number="form.acc_residual_value" type="number" min="0" step="0.01" :class="inputCls" />
            </div>
          </template>
        </div>
        <p class="mt-2 text-xs text-neutral-400">
          {{ isAccByTax ? t('accounting.assets.editor.acc_by_tax_hint') : t('accounting.assets.editor.acc_prospective_hint') }}
        </p>
      </section>

      <!-- Historický majetek (R23) -->
      <section v-if="!isEdit || isHistorical" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
        <label class="inline-flex items-center gap-2 text-sm font-semibold">
          <input v-model="isHistorical" type="checkbox" :disabled="isEdit" class="rounded border-neutral-300" />
          {{ t('accounting.assets.editor.section_historical') }}
        </label>
        <p class="text-xs text-neutral-400 mt-1">{{ t('accounting.assets.editor.historical_hint') }}</p>
        <div v-if="isHistorical" class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-3">
          <div>
            <label :class="labelCls">{{ t('accounting.assets.fields.put_into_use_date') }} *</label>
            <DateInput v-model="form.put_into_use_date" :disabled="isEdit" :class="inputCls" />
          </div>
          <div>
            <label :class="labelCls">{{ t('accounting.assets.fields.opening_tax_years') }}</label>
            <input v-model.number="form.opening_tax_years" type="number" min="0" step="1" :disabled="isEdit" :class="inputCls" />
          </div>
          <div>
            <label :class="labelCls">{{ t('accounting.assets.fields.opening_tax_amount') }}</label>
            <input v-model.number="form.opening_tax_amount" type="number" min="0" step="0.01" :disabled="isEdit" :class="inputCls" />
          </div>
          <div>
            <label :class="labelCls">{{ t('accounting.assets.fields.opening_acc_months') }}</label>
            <input v-model.number="form.opening_acc_months" type="number" min="0" step="1" :disabled="isEdit" :class="inputCls" />
          </div>
          <div>
            <label :class="labelCls">{{ t('accounting.assets.fields.opening_acc_amount') }}</label>
            <input v-model.number="form.opening_acc_amount" type="number" min="0" step="0.01" :disabled="isEdit" :class="inputCls" />
          </div>
        </div>
      </section>

      <div v-if="errors.length" class="bg-danger-50 border border-danger-500/30 text-danger-600 rounded-lg p-3 text-sm">
        <div v-for="(err, i) in errors" :key="i">{{ err }}</div>
      </div>

      <div class="flex justify-end gap-2">
        <RouterLink :to="isEdit ? { name: 'accounting-asset-detail', params: { id: assetId } } : { name: 'accounting-assets' }"
          :class="btnOutline('neutral')">
          {{ t('common.cancel') }}
        </RouterLink>
        <button type="submit" :disabled="saving" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
      </div>
    </form>
  </div>
</template>
