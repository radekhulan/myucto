<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  catalogPricingApi,
  type PricingCalculationMode,
  type PricingExchangeRate,
  type PricingExchangeRatePayload,
  type PricingMatchType,
  type PricingProfile,
  type PricingProfilePayload,
  type PricingRounding,
  type PricingRule,
  type PricingRulePayload,
} from '@/api/catalogPricing'
import { catalogJobsApi, type CatalogJob } from '@/api/catalogJobs'
import { eshopApi, type Category, type EshopCurrency, type Manufacturer } from '@/api/eshop'
import { clientsApi } from '@/api/clients'
import { stockApi, type StockItemSearchResult } from '@/api/stock'
import { apiErrorMessage } from '@/api/errors'
import CatalogJobProgress from '@/components/stock/CatalogJobProgress.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Modal from '@/components/ui/Modal.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { btnFilled, btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { useAuthStore } from '@/stores/auth'

type DialogKind = 'profile' | 'rule' | 'rate'
type SelectOption = { value: number; label: string; secondary?: string }
type ProfileForm = PricingProfilePayload & { id?: number }
type RuleForm = PricingRulePayload & { id?: number }
type RateForm = PricingExchangeRatePayload & { id?: number }

const CALCULATION_MODES: PricingCalculationMode[] = ['markup', 'target_margin']
const ROUNDINGS: PricingRounding[] = ['none', '0.01', '0.10', '0.50', '1', '9_ending']
const MATCH_TYPES: PricingMatchType[] = ['product', 'category', 'manufacturer', 'vendor', 'default']
const ROUNDING_KEYS: Record<PricingRounding, string> = {
  none: 'none',
  '0.01': 'hundredth',
  '0.10': 'tenth',
  '0.50': 'half',
  '1': 'whole',
  '9_ending': 'nine_ending',
}
const TERMINAL_JOB_STATUSES = new Set(['completed', 'failed', 'cancelled'])
const FIELD_CLASS = 'mt-1 block h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20'

const { t } = useI18n()
const auth = useAuthStore()

const canWrite = computed(() =>
  auth.canWrite('eshop.write') && auth.canWrite('stock.items.write'),
)
const profiles = ref<PricingProfile[]>([])
const rules = ref<PricingRule[]>([])
const rates = ref<PricingExchangeRate[]>([])
const manufacturers = ref<Manufacturer[]>([])
const categories = ref<Category[]>([])
const currencies = ref<EshopCurrency[]>([])
const loading = ref(true)
const loadFailed = ref(false)
const loadError = ref('')
const referenceLoadFailed = ref(false)
const actionError = ref('')

const dialog = ref<DialogKind | null>(null)
const profileForm = ref<ProfileForm | null>(null)
const ruleForm = ref<RuleForm | null>(null)
const rateForm = ref<RateForm | null>(null)
const saving = ref(false)
const saveError = ref('')
const deletingKey = ref('')

const targetLabels = ref(new Map<string, string>())
const targetOptions = ref<SelectOption[]>([])
const targetLoading = ref(false)
const targetLookupError = ref('')
const selectedTarget = ref<SelectOption | null>(null)

const job = ref<CatalogJob | null>(null)
const jobError = ref('')
let pollTimer: ReturnType<typeof setTimeout> | null = null
let disposed = false
let loadGeneration = 0
let actionGeneration = 0
let jobGeneration = 0
let targetSearchGeneration = 0
let labelGeneration = 0

const hasContent = computed(() =>
  profiles.value.length > 0 || rules.value.length > 0 || rates.value.length > 0,
)
const profileNames = computed(() => new Map(profiles.value.map(profile => [profile.id, profile.name])))
const manufacturerOptions = computed<SelectOption[]>(() => manufacturers.value
  .filter(item => !item.archived)
  .map(item => ({ value: item.id, label: item.name, secondary: item.code })))
const categoryOptions = computed<SelectOption[]>(() => categories.value
  .filter(item => !item.archived)
  .map(item => ({ value: item.id, label: item.path || item.name, secondary: item.code })))
const currentTargetOptions = computed<SelectOption[]>(() => {
  if (ruleForm.value?.match_type === 'manufacturer') return manufacturerOptions.value
  if (ruleForm.value?.match_type === 'category') return categoryOptions.value
  return targetOptions.value
})
const ruleCanSave = computed(() => {
  const form = ruleForm.value
  if (!form || form.profile_id <= 0 || !Number.isInteger(Number(form.priority))) return false
  return form.match_type === 'default' || (form.match_id !== null && form.match_id > 0)
})
const profileCanSave = computed(() => {
  const form = profileForm.value
  if (!form) return false
  return form.code.trim() !== '' && form.name.trim() !== '' && form.currency_code !== ''
    && form.percentage.trim() !== '' && form.fx_source.trim() !== ''
    && Number.isInteger(Number(form.max_rate_age_days)) && Number(form.max_rate_age_days) >= 0
})
const rateCanSave = computed(() => {
  const form = rateForm.value
  return !!form && form.currency_code !== '' && form.currency_code !== 'CZK'
    && form.rate_date !== '' && form.source.trim() !== '' && form.rate.trim() !== ''
})
const formCanSave = computed(() => {
  if (dialog.value === 'profile') return profileCanSave.value
  if (dialog.value === 'rule') return ruleCanSave.value
  if (dialog.value === 'rate') return rateCanSave.value
  return false
})
const dialogTitle = computed(() => {
  const editing = profileForm.value?.id || ruleForm.value?.id || rateForm.value?.id
  return t(`eshop.pricing.dialog.${editing ? 'edit' : 'add'}_${dialog.value}`)
})

function localDate(): string {
  const now = new Date()
  const local = new Date(now.getTime() - now.getTimezoneOffset() * 60_000)
  return local.toISOString().slice(0, 10)
}

function emptyProfile(): ProfileForm {
  return {
    code: '',
    name: '',
    currency_code: currencies.value.find(currency => currency.is_default)?.code
      ?? currencies.value[0]?.code
      ?? 'CZK',
    calculation_mode: 'markup',
    percentage: '0',
    rounding: 'none',
    fx_source: 'cnb',
    max_rate_age_days: 7,
    is_active: true,
  }
}

function emptyRule(): RuleForm {
  return {
    profile_id: profiles.value[0]?.id ?? 0,
    match_type: 'default',
    match_id: null,
    priority: 0,
    is_active: true,
  }
}

function emptyRate(): RateForm {
  return {
    currency_code: currencies.value.find(currency => !currency.archived && currency.code !== 'CZK')?.code ?? '',
    rate_date: localDate(),
    source: 'cnb',
    rate: '',
  }
}

function targetKey(type: PricingMatchType, id: number): string {
  return `${type}:${id}`
}

function targetLabel(rule: PricingRule): string {
  if (rule.match_type === 'default') return t('eshop.pricing.match_types.default')
  if (rule.match_id === null) return t('eshop.pricing.reference_unavailable')
  return targetLabels.value.get(targetKey(rule.match_type, rule.match_id))
    ?? t('eshop.pricing.reference_unavailable')
}

function profileLabel(profileId: number): string {
  return profileNames.value.get(profileId) ?? t('eshop.pricing.reference_unavailable')
}

function calculationLabel(mode: PricingCalculationMode): string {
  return t(`eshop.pricing.calculation_modes.${mode}`)
}

function roundingLabel(rounding: PricingRounding): string {
  return t(`eshop.pricing.roundings.${ROUNDING_KEYS[rounding]}`)
}

function matchTypeLabel(type: PricingMatchType): string {
  return t(`eshop.pricing.match_types.${type}`)
}

function currencyOptions(current: string): EshopCurrency[] {
  const options = currencies.value.filter(currency => !currency.archived || currency.code === current)
  if (current && !options.some(currency => currency.code === current)) {
    return [{ id: -1, code: current, name: current, symbol: null, display_order: -1, is_default: false, archived: true }, ...options]
  }
  return options
}

async function load(): Promise<void> {
  if (!canWrite.value) {
    loading.value = false
    return
  }
  const generation = ++loadGeneration
  loading.value = true
  loadFailed.value = false
  loadError.value = ''
  const results = await Promise.allSettled([
    catalogPricingApi.profiles(),
    catalogPricingApi.rules(),
    catalogPricingApi.rates(),
    eshopApi.listManufacturers(),
    eshopApi.listCategories(),
    eshopApi.listCurrencies(),
  ])
  if (disposed || generation !== loadGeneration) return

  const coreFailed = results.slice(0, 3).find(result => result.status === 'rejected')
  if (coreFailed?.status === 'rejected') {
    loadFailed.value = true
    loadError.value = apiErrorMessage(coreFailed.reason, t('eshop.pricing.errors.load'))
  } else {
    profiles.value = (results[0] as PromiseFulfilledResult<PricingProfile[]>).value
    rules.value = (results[1] as PromiseFulfilledResult<PricingRule[]>).value
    rates.value = (results[2] as PromiseFulfilledResult<PricingExchangeRate[]>).value
  }

  if (results[3]?.status === 'fulfilled') manufacturers.value = results[3].value
  if (results[4]?.status === 'fulfilled') categories.value = results[4].value
  if (results[5]?.status === 'fulfilled') currencies.value = results[5].value
  referenceLoadFailed.value = results.slice(3).some(result => result.status === 'rejected')
  loading.value = false
  void resolveRuleLabels(rules.value)
}

async function resolveRuleLabels(sourceRules: PricingRule[]): Promise<void> {
  const generation = ++labelGeneration
  const next = new Map<string, string>()
  for (const manufacturer of manufacturers.value) {
    next.set(targetKey('manufacturer', manufacturer.id), manufacturer.name)
  }
  for (const category of categories.value) {
    next.set(targetKey('category', category.id), category.path || category.name)
  }

  const lookups = new Map<string, Promise<string>>()
  for (const rule of sourceRules) {
    if (rule.match_id === null || rule.match_type === 'default') continue
    const key = targetKey(rule.match_type, rule.match_id)
    if (next.has(key) || lookups.has(key)) continue
    if (rule.match_type === 'product') {
      lookups.set(key, stockApi.getItem(rule.match_id).then(item => `${item.sku} - ${item.name}`))
    } else if (rule.match_type === 'manufacturer') {
      lookups.set(key, eshopApi.getManufacturer(rule.match_id).then(item => item.name))
    } else if (rule.match_type === 'category') {
      lookups.set(key, eshopApi.getCategory(rule.match_id).then(item => item.path || item.name))
    } else if (rule.match_type === 'vendor') {
      lookups.set(key, clientsApi.get(rule.match_id).then(item => item.company_name))
    }
  }

  const entries = [...lookups.entries()]
  const resolved = await Promise.allSettled(entries.map(([, promise]) => promise))
  if (disposed || generation !== labelGeneration) return
  resolved.forEach((result, index) => {
    if (result.status === 'fulfilled') next.set(entries[index]![0], result.value)
  })
  targetLabels.value = next
  const openRule = ruleForm.value
  if (openRule?.match_id && openRule.match_type !== 'default') {
    const label = next.get(targetKey(openRule.match_type, openRule.match_id))
    if (label) selectedTarget.value = { value: openRule.match_id, label }
  }
}

function openProfile(profile?: PricingProfile): void {
  if (!canWrite.value || saving.value || deletingKey.value) return
  profileForm.value = profile ? { ...profile } : emptyProfile()
  ruleForm.value = null
  rateForm.value = null
  saveError.value = ''
  dialog.value = 'profile'
}

function openRule(rule?: PricingRule): void {
  if (!canWrite.value || saving.value || deletingKey.value || profiles.value.length === 0) return
  ruleForm.value = rule ? { ...rule } : emptyRule()
  profileForm.value = null
  rateForm.value = null
  targetOptions.value = []
  targetLookupError.value = ''
  if (rule?.match_id && rule.match_type !== 'default') {
    const loadedOption = currentTargetOptions.value.find(option => option.value === rule.match_id)
    selectedTarget.value = loadedOption ?? {
      value: rule.match_id,
      label: targetLabels.value.get(targetKey(rule.match_type, rule.match_id))
        ?? t('eshop.pricing.reference_unavailable'),
    }
  } else {
    selectedTarget.value = null
  }
  saveError.value = ''
  dialog.value = 'rule'
}

function openRate(rate?: PricingExchangeRate): void {
  if (!canWrite.value || saving.value || deletingKey.value) return
  rateForm.value = rate ? { ...rate } : emptyRate()
  profileForm.value = null
  ruleForm.value = null
  saveError.value = ''
  dialog.value = 'rate'
}

function closeDialog(force = false): void {
  if (saving.value && !force) return
  dialog.value = null
  profileForm.value = null
  ruleForm.value = null
  rateForm.value = null
  selectedTarget.value = null
  targetOptions.value = []
  targetLookupError.value = ''
}

watch(() => ruleForm.value?.match_type, (next, previous) => {
  if (!next || !previous || next === previous) return
  targetSearchGeneration += 1
  targetOptions.value = []
  targetLoading.value = false
  selectedTarget.value = null
  targetLookupError.value = ''
  if (ruleForm.value) ruleForm.value.match_id = null
})

async function searchRuleTargets(query: string): Promise<void> {
  const type = ruleForm.value?.match_type
  if (type !== 'product' && type !== 'vendor') return
  const generation = ++targetSearchGeneration
  targetLoading.value = true
  targetLookupError.value = ''
  try {
    let options: SelectOption[]
    if (type === 'product') {
      const items: StockItemSearchResult[] = await stockApi.searchItems(query, 25)
      options = items.map(item => ({ value: item.id, label: `${item.sku} - ${item.name}`, secondary: item.unit }))
    } else {
      const result = await clientsApi.list({ q: query, role: 'vendors', per_page: 25, sort: 'name' })
      options = result.data.map(vendor => ({ value: vendor.id, label: vendor.company_name, secondary: vendor.ic || undefined }))
    }
    if (disposed || generation !== targetSearchGeneration || ruleForm.value?.match_type !== type) return
    targetOptions.value = options
  } catch (error) {
    if (disposed || generation !== targetSearchGeneration) return
    targetOptions.value = []
    targetLookupError.value = apiErrorMessage(error, t('eshop.pricing.errors.lookup'))
  } finally {
    if (!disposed && generation === targetSearchGeneration) targetLoading.value = false
  }
}

function pickTarget(id: number | null): void {
  if (!ruleForm.value) return
  ruleForm.value.match_id = id
  selectedTarget.value = id === null
    ? null
    : currentTargetOptions.value.find(option => option.value === id) ?? null
}

function upsertById<T extends { id: number }>(rows: T[], saved: T): T[] {
  const index = rows.findIndex(row => row.id === saved.id)
  if (index < 0) return [...rows, saved]
  const next = [...rows]
  next[index] = saved
  return next
}

function profilePayload(form: ProfileForm): PricingProfilePayload {
  return {
    code: form.code.trim(),
    name: form.name.trim(),
    currency_code: form.currency_code,
    calculation_mode: form.calculation_mode,
    percentage: form.percentage.trim(),
    rounding: form.rounding,
    fx_source: form.fx_source.trim(),
    max_rate_age_days: Number(form.max_rate_age_days),
    is_active: form.is_active,
  }
}

function rulePayload(form: RuleForm): PricingRulePayload {
  return {
    profile_id: Number(form.profile_id),
    match_type: form.match_type,
    match_id: form.match_type === 'default' ? null : form.match_id,
    priority: Number(form.priority),
    is_active: form.is_active,
  }
}

function ratePayload(form: RateForm): PricingExchangeRatePayload {
  return {
    currency_code: form.currency_code,
    rate_date: form.rate_date,
    source: form.source.trim(),
    rate: form.rate.trim(),
  }
}

async function save(): Promise<void> {
  if (!canWrite.value || saving.value || deletingKey.value || !formCanSave.value || !dialog.value) return
  const generation = ++actionGeneration
  saving.value = true
  saveError.value = ''
  try {
    let recomputeJobId: number | null = null
    if (dialog.value === 'profile' && profileForm.value) {
      const form = profileForm.value
      const result = form.id
        ? await catalogPricingApi.updateProfile(form.id, profilePayload(form))
        : await catalogPricingApi.createProfile(profilePayload(form))
      if (disposed || generation !== actionGeneration) return
      profiles.value = upsertById(profiles.value, result.profile)
      recomputeJobId = result.recompute_job_id
    } else if (dialog.value === 'rule' && ruleForm.value) {
      const form = ruleForm.value
      const result = form.id
        ? await catalogPricingApi.updateRule(form.id, rulePayload(form))
        : await catalogPricingApi.createRule(rulePayload(form))
      if (disposed || generation !== actionGeneration) return
      rules.value = upsertById(rules.value, result.rule)
      recomputeJobId = result.recompute_job_id
      void resolveRuleLabels(rules.value)
    } else if (dialog.value === 'rate' && rateForm.value) {
      const result = await catalogPricingApi.saveRate(ratePayload(rateForm.value))
      if (disposed || generation !== actionGeneration) return
      const saved = result.exchange_rate
      const filtered = rates.value.filter(rate => rate.id !== saved.id
        && !(rate.currency_code === saved.currency_code && rate.rate_date === saved.rate_date && rate.source === saved.source))
      rates.value = [saved, ...filtered]
      recomputeJobId = result.recompute_job_id
    }
    if (disposed || generation !== actionGeneration) return
    closeDialog(true)
    if (recomputeJobId !== null) void startJob(recomputeJobId)
  } catch (error) {
    if (!disposed && generation === actionGeneration) {
      saveError.value = apiErrorMessage(error, t('eshop.pricing.errors.save'))
    }
  } finally {
    if (!disposed && generation === actionGeneration) saving.value = false
  }
}

async function removeProfile(profile: PricingProfile): Promise<void> {
  if (!canWrite.value || saving.value || deletingKey.value) return
  if (!window.confirm(t('eshop.pricing.delete_profile_confirm', { name: profile.name }))) return
  const generation = ++actionGeneration
  deletingKey.value = `profile:${profile.id}`
  actionError.value = ''
  try {
    const result = await catalogPricingApi.deleteProfile(profile.id)
    if (disposed || generation !== actionGeneration) return
    profiles.value = profiles.value.filter(item => item.id !== profile.id)
    rules.value = rules.value.filter(rule => rule.profile_id !== profile.id)
    if (result.recompute_job_id !== null) void startJob(result.recompute_job_id)
  } catch (error) {
    if (!disposed && generation === actionGeneration) {
      actionError.value = apiErrorMessage(error, t('eshop.pricing.errors.delete'))
    }
  } finally {
    if (!disposed && generation === actionGeneration) deletingKey.value = ''
  }
}

async function removeRule(rule: PricingRule): Promise<void> {
  if (!canWrite.value || saving.value || deletingKey.value) return
  if (!window.confirm(t('eshop.pricing.delete_rule_confirm'))) return
  const generation = ++actionGeneration
  deletingKey.value = `rule:${rule.id}`
  actionError.value = ''
  try {
    const result = await catalogPricingApi.deleteRule(rule.id)
    if (disposed || generation !== actionGeneration) return
    rules.value = rules.value.filter(item => item.id !== rule.id)
    if (result.recompute_job_id !== null) void startJob(result.recompute_job_id)
  } catch (error) {
    if (!disposed && generation === actionGeneration) {
      actionError.value = apiErrorMessage(error, t('eshop.pricing.errors.delete'))
    }
  } finally {
    if (!disposed && generation === actionGeneration) deletingKey.value = ''
  }
}

function clearPollTimer(): void {
  if (pollTimer) clearTimeout(pollTimer)
  pollTimer = null
}

function schedulePoll(id: number, generation: number): void {
  clearPollTimer()
  pollTimer = setTimeout(() => void pollJob(id, generation), 2_000)
}

async function startJob(id: number): Promise<void> {
  if (disposed) return
  const generation = ++jobGeneration
  clearPollTimer()
  jobError.value = ''
  try {
    const loadedJob = await catalogJobsApi.get(id)
    if (disposed || generation !== jobGeneration) return
    job.value = loadedJob
    if (!TERMINAL_JOB_STATUSES.has(loadedJob.status)) schedulePoll(id, generation)
  } catch (error) {
    if (disposed || generation !== jobGeneration) return
    jobError.value = apiErrorMessage(error, t('eshop.pricing.errors.job'))
    clearPollTimer()
    pollTimer = setTimeout(() => {
      if (!disposed && generation === jobGeneration) void startJob(id)
    }, 2_000)
  }
}

async function pollJob(id: number, generation: number): Promise<void> {
  if (disposed || generation !== jobGeneration || job.value?.id !== id) return
  try {
    const loadedJob = await catalogJobsApi.get(id)
    if (disposed || generation !== jobGeneration || job.value?.id !== id) return
    job.value = loadedJob
    jobError.value = ''
    if (!TERMINAL_JOB_STATUSES.has(loadedJob.status)) schedulePoll(id, generation)
  } catch (error) {
    if (disposed || generation !== jobGeneration || job.value?.id !== id) return
    jobError.value = apiErrorMessage(error, t('eshop.pricing.errors.job'))
    schedulePoll(id, generation)
  }
}

onMounted(() => void load())
onBeforeUnmount(() => {
  disposed = true
  loadGeneration += 1
  actionGeneration += 1
  jobGeneration += 1
  targetSearchGeneration += 1
  labelGeneration += 1
  clearPollTimer()
})
</script>

<template>
  <div>
    <div class="mb-5 flex flex-wrap items-start justify-between gap-4">
      <div>
        <h2 class="text-xl font-semibold text-neutral-900">{{ t('eshop.pricing.title') }}</h2>
        <p class="mt-1 text-sm text-neutral-500">{{ t('eshop.pricing.subtitle') }}</p>
      </div>
      <div class="max-w-xl rounded-lg border border-primary-500/20 bg-primary-500/5 px-4 py-3 text-sm text-neutral-700">
        <span class="font-medium text-primary-700">{{ t('eshop.pricing.formula_title') }}</span>
        {{ t('eshop.pricing.formula_hint') }}
      </div>
    </div>

    <p v-if="!canWrite" class="mb-4 rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700">
      {{ t('eshop.pricing.readonly_hint') }}
    </p>

    <div v-if="actionError" class="mb-4 rounded-lg border border-danger-500/30 bg-danger-50 px-4 py-3 text-sm text-danger-700" role="alert">
      {{ actionError }}
    </div>
    <div v-if="referenceLoadFailed" class="mb-4 rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700" role="alert">
      {{ t('eshop.pricing.errors.references') }}
    </div>
    <div v-if="jobError" class="mb-4 rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700" role="alert">
      {{ jobError }}
    </div>
    <CatalogJobProgress v-if="job" :job="job" class="mb-5" />

    <div v-if="loading && !hasContent" class="py-12 text-center text-sm text-neutral-500">
      {{ t('common.loading') }}
    </div>
    <EmptyState
      v-else-if="loadFailed && !hasContent"
      variant="failed"
      boxed
      :message="loadError"
      @action="load"
    />

    <div v-else-if="canWrite" class="space-y-5">
      <div v-if="loadFailed" class="rounded-lg border border-danger-500/30 bg-danger-50 px-4 py-3 text-sm text-danger-700" role="alert">
        {{ loadError }}
      </div>

      <section class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm">
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-4 py-3">
          <div>
            <h3 class="font-semibold text-neutral-900">{{ t('eshop.pricing.profiles') }}</h3>
            <p class="mt-0.5 text-xs text-neutral-500">{{ t('eshop.pricing.profiles_hint') }}</p>
          </div>
          <button v-if="canWrite" type="button" :class="btnFilled('primary')" data-test="add-profile" @click="openProfile()">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
            {{ t('eshop.pricing.add_profile') }}
          </button>
        </header>

        <EmptyState
          v-if="profiles.length === 0"
          dense
          icon="coin"
          :title="t('eshop.pricing.empty_profiles')"
          :message="t('eshop.pricing.empty_profiles_hint')"
          :cta="canWrite ? t('eshop.pricing.add_profile') : undefined"
          @action="openProfile()"
        />
        <div v-else class="divide-y divide-neutral-200">
          <article v-for="profile in profiles" :key="profile.id" class="grid gap-3 px-4 py-3 sm:grid-cols-[minmax(10rem,1.4fr)_minmax(9rem,1fr)_minmax(10rem,1fr)_auto] sm:items-center">
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <span class="truncate font-medium text-neutral-900">{{ profile.name }}</span>
                <span class="rounded bg-neutral-100 px-1.5 py-0.5 font-mono text-xs text-neutral-600">{{ profile.code }}</span>
                <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="profile.is_active ? 'bg-success-50 text-success-700' : 'bg-neutral-100 text-neutral-500'">
                  {{ t(profile.is_active ? 'common.active' : 'common.inactive') }}
                </span>
              </div>
              <p class="mt-1 text-xs text-neutral-500">{{ profile.currency_code }} · {{ t('eshop.pricing.fx_summary', { source: profile.fx_source, days: profile.max_rate_age_days }) }}</p>
            </div>
            <div class="text-sm text-neutral-700">
              <span class="font-medium">{{ calculationLabel(profile.calculation_mode) }}</span>
              <span class="ml-1 tabular-nums">{{ profile.percentage }} %</span>
            </div>
            <div class="text-sm text-neutral-600">
              {{ t('eshop.pricing.rounding_summary', { value: roundingLabel(profile.rounding) }) }}
            </div>
            <div v-if="canWrite" class="flex flex-wrap justify-end gap-2">
              <button type="button" :class="btnOutlineSm('neutral')" :disabled="!!deletingKey" :data-test="`edit-profile-${profile.id}`" @click="openProfile(profile)">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
                {{ t('common.edit') }}
              </button>
              <button type="button" :class="btnOutlineSm('danger')" :disabled="!!deletingKey" @click="removeProfile(profile)">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>
                {{ deletingKey === `profile:${profile.id}` ? t('common.deleting') : t('common.delete') }}
              </button>
            </div>
          </article>
        </div>
      </section>

      <section class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm">
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-4 py-3">
          <div>
            <h3 class="font-semibold text-neutral-900">{{ t('eshop.pricing.rules') }}</h3>
            <p class="mt-0.5 text-xs text-neutral-500">{{ t('eshop.pricing.priority_hint') }}</p>
          </div>
          <button
            v-if="canWrite"
            type="button"
            :class="btnFilled('primary')"
            :disabled="profiles.length === 0"
            :title="profiles.length === 0 ? t('eshop.pricing.rule_requires_profile') : undefined"
            data-test="add-rule"
            @click="openRule()"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
            {{ t('eshop.pricing.add_rule') }}
          </button>
          <p v-if="canWrite && profiles.length === 0" class="basis-full text-right text-xs text-warning-700">{{ t('eshop.pricing.rule_requires_profile') }}</p>
        </header>

        <EmptyState
          v-if="rules.length === 0"
          dense
          icon="tag"
          :title="t('eshop.pricing.empty_rules')"
          :message="t('eshop.pricing.empty_rules_hint')"
          :cta="canWrite && profiles.length > 0 ? t('eshop.pricing.add_rule') : undefined"
          @action="openRule()"
        />
        <div v-else class="divide-y divide-neutral-200">
          <article v-for="rule in rules" :key="rule.id" class="grid gap-3 px-4 py-3 sm:grid-cols-[minmax(10rem,1.3fr)_minmax(11rem,1.6fr)_auto_auto] sm:items-center">
            <div>
              <p class="font-medium text-neutral-900">{{ profileLabel(rule.profile_id) }}</p>
              <span class="mt-1 inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="rule.is_active ? 'bg-success-50 text-success-700' : 'bg-neutral-100 text-neutral-500'">
                {{ t(rule.is_active ? 'common.active' : 'common.inactive') }}
              </span>
            </div>
            <div class="min-w-0 text-sm text-neutral-700">
              <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">{{ matchTypeLabel(rule.match_type) }}</p>
              <p class="mt-0.5 truncate" :title="targetLabel(rule)">{{ targetLabel(rule) }}</p>
            </div>
            <div class="text-sm text-neutral-600">
              {{ t('eshop.pricing.priority_value', { value: rule.priority }) }}
            </div>
            <div v-if="canWrite" class="flex flex-wrap justify-end gap-2">
              <button type="button" :class="btnOutlineSm('neutral')" :disabled="!!deletingKey" :data-test="`edit-rule-${rule.id}`" @click="openRule(rule)">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
                {{ t('common.edit') }}
              </button>
              <button type="button" :class="btnOutlineSm('danger')" :disabled="!!deletingKey" @click="removeRule(rule)">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>
                {{ deletingKey === `rule:${rule.id}` ? t('common.deleting') : t('common.delete') }}
              </button>
            </div>
          </article>
        </div>
      </section>

      <section class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm">
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-4 py-3">
          <div>
            <h3 class="font-semibold text-neutral-900">{{ t('eshop.pricing.rates') }}</h3>
            <p class="mt-0.5 text-xs text-neutral-500">{{ t('eshop.pricing.rates_hint') }}</p>
          </div>
          <button v-if="canWrite" type="button" :class="btnFilled('primary')" data-test="add-rate" @click="openRate()">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
            {{ t('eshop.pricing.add_rate') }}
          </button>
        </header>

        <EmptyState
          v-if="rates.length === 0"
          dense
          icon="swap"
          :title="t('eshop.pricing.empty_rates')"
          :message="t('eshop.pricing.empty_rates_hint')"
          :cta="canWrite ? t('eshop.pricing.add_rate') : undefined"
          @action="openRate()"
        />
        <div v-else class="divide-y divide-neutral-200">
          <article v-for="rate in rates" :key="rate.id" class="grid gap-3 px-4 py-3 sm:grid-cols-[minmax(7rem,0.8fr)_minmax(9rem,1fr)_minmax(8rem,1fr)_auto] sm:items-center">
            <div>
              <p class="font-mono font-semibold text-neutral-900">{{ rate.currency_code }}</p>
              <p class="mt-0.5 text-xs text-neutral-500">{{ rate.source }}</p>
            </div>
            <p class="text-sm text-neutral-700">{{ rate.rate_date }}</p>
            <p class="font-mono text-sm font-medium tabular-nums text-neutral-900">{{ rate.rate }}</p>
            <div v-if="canWrite" class="flex justify-end">
              <button type="button" :class="btnOutlineSm('neutral')" :data-test="`edit-rate-${rate.id}`" @click="openRate(rate)">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
                {{ t('common.edit') }}
              </button>
            </div>
          </article>
        </div>
      </section>
    </div>

    <Modal v-if="dialog" :title="dialogTitle" width-class="max-w-2xl" @close="closeDialog">
      <form data-test="pricing-form" @submit.prevent="save">
        <fieldset :disabled="saving" class="min-w-0">
        <div v-if="saveError" class="mb-4 rounded-lg border border-danger-500/30 bg-danger-50 px-4 py-3 text-sm text-danger-700" role="alert" data-test="save-error">
          {{ saveError }}
        </div>

        <div v-if="dialog === 'profile' && profileForm" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <label class="block text-sm font-medium text-neutral-700">
            {{ t('eshop.pricing.code') }}
            <input v-model="profileForm.code" :class="FIELD_CLASS" data-test="profile-code" type="text" maxlength="50" pattern="[A-Za-z0-9][A-Za-z0-9_.-]{0,49}" required autocomplete="off">
          </label>
          <label class="block text-sm font-medium text-neutral-700">
            {{ t('eshop.pricing.name') }}
            <input v-model="profileForm.name" :class="FIELD_CLASS" data-test="profile-name" type="text" maxlength="150" required>
          </label>
          <label class="block text-sm font-medium text-neutral-700">
            {{ t('eshop.pricing.currency') }}
            <select v-model="profileForm.currency_code" :class="FIELD_CLASS" data-test="profile-currency" required>
              <option v-for="currency in currencyOptions(profileForm.currency_code)" :key="currency.code" :value="currency.code">{{ currency.code }} - {{ currency.name }}</option>
            </select>
          </label>
          <label class="block text-sm font-medium text-neutral-700">
            {{ t('eshop.pricing.mode') }}
            <select v-model="profileForm.calculation_mode" :class="FIELD_CLASS" data-test="profile-mode">
              <option v-for="mode in CALCULATION_MODES" :key="mode" :value="mode">{{ calculationLabel(mode) }}</option>
            </select>
          </label>
          <label class="block text-sm font-medium text-neutral-700">
            {{ t('eshop.pricing.percentage') }}
            <input v-model="profileForm.percentage" :class="FIELD_CLASS" data-test="profile-percentage" type="text" inputmode="decimal" required>
            <span class="mt-1 block text-xs font-normal text-neutral-500">{{ t(`eshop.pricing.percentage_hint.${profileForm.calculation_mode}`) }}</span>
          </label>
          <label class="block text-sm font-medium text-neutral-700">
            {{ t('eshop.pricing.rounding') }}
            <select v-model="profileForm.rounding" :class="FIELD_CLASS" data-test="profile-rounding">
              <option v-for="rounding in ROUNDINGS" :key="rounding" :value="rounding">{{ roundingLabel(rounding) }}</option>
            </select>
          </label>
          <label class="block text-sm font-medium text-neutral-700">
            {{ t('eshop.pricing.fx_source') }}
            <input v-model="profileForm.fx_source" :class="FIELD_CLASS" data-test="profile-source" type="text" maxlength="40" pattern="[A-Za-z][A-Za-z0-9_.-]{0,39}" required autocomplete="off">
          </label>
          <label class="block text-sm font-medium text-neutral-700">
            {{ t('eshop.pricing.max_rate_age') }}
            <input v-model.number="profileForm.max_rate_age_days" :class="FIELD_CLASS" data-test="profile-max-age" type="number" min="0" max="3650" step="1" required>
          </label>
          <label class="flex items-center gap-2 text-sm text-neutral-700 sm:col-span-2">
            <input v-model="profileForm.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600">
            <span>{{ t('eshop.pricing.active_profile') }}</span>
          </label>
        </div>

        <div v-else-if="dialog === 'rule' && ruleForm" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <label class="block text-sm font-medium text-neutral-700">
            {{ t('eshop.pricing.profile') }}
            <select v-model.number="ruleForm.profile_id" :class="FIELD_CLASS" data-test="rule-profile" required>
              <option v-for="profile in profiles" :key="profile.id" :value="profile.id">{{ profile.name }}</option>
            </select>
          </label>
          <label class="block text-sm font-medium text-neutral-700">
            {{ t('eshop.pricing.match') }}
            <select v-model="ruleForm.match_type" :class="FIELD_CLASS" data-test="rule-match-type">
              <option v-for="type in MATCH_TYPES" :key="type" :value="type">{{ matchTypeLabel(type) }}</option>
            </select>
          </label>
          <div v-if="ruleForm.match_type !== 'default'" class="sm:col-span-2">
            <label class="block text-sm font-medium text-neutral-700" for="pricing-rule-target">{{ t('eshop.pricing.match_id') }}</label>
            <SearchableSelect
              :key="ruleForm.match_type"
              :remote="ruleForm.match_type === 'product' || ruleForm.match_type === 'vendor'"
              input-id="pricing-rule-target"
              :model-value="ruleForm.match_id"
              :options="currentTargetOptions"
              :selected-option="selectedTarget"
              :loading="targetLoading"
              :placeholder="t(`eshop.pricing.lookup_placeholder.${ruleForm.match_type}`)"
              :loading-label="t('eshop.pricing.lookup_loading')"
              :no-results-label="t('common.no_results')"
              :clear-label="t('eshop.pricing.clear_target')"
              required
              teleport
              @search="searchRuleTargets"
              @update:model-value="pickTarget"
            />
            <p v-if="targetLookupError" class="mt-1 text-xs text-danger-700" role="alert">{{ targetLookupError }}</p>
          </div>
          <label class="block text-sm font-medium text-neutral-700">
            {{ t('eshop.pricing.priority') }}
            <input v-model.number="ruleForm.priority" :class="FIELD_CLASS" data-test="rule-priority" type="number" min="-1000000" max="1000000" step="1" required>
          </label>
          <label class="flex items-center gap-2 self-end pb-2 text-sm text-neutral-700">
            <input v-model="ruleForm.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600">
            <span>{{ t('eshop.pricing.active_rule') }}</span>
          </label>
        </div>

        <div v-else-if="dialog === 'rate' && rateForm" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <label class="block text-sm font-medium text-neutral-700">
            {{ t('eshop.pricing.currency') }}
            <select v-model="rateForm.currency_code" :class="FIELD_CLASS" :disabled="!!rateForm.id" data-test="rate-currency" required>
              <option value="" disabled>{{ t('eshop.pricing.select_currency') }}</option>
              <option v-for="currency in currencyOptions(rateForm.currency_code).filter(item => item.code !== 'CZK')" :key="currency.code" :value="currency.code">{{ currency.code }} - {{ currency.name }}</option>
            </select>
          </label>
          <label class="block text-sm font-medium text-neutral-700">
            {{ t('eshop.pricing.date') }}
            <input v-model="rateForm.rate_date" :class="FIELD_CLASS" :disabled="!!rateForm.id" data-test="rate-date" type="date" required>
          </label>
          <label class="block text-sm font-medium text-neutral-700">
            {{ t('eshop.pricing.source') }}
            <input v-model="rateForm.source" :class="FIELD_CLASS" :disabled="!!rateForm.id" data-test="rate-source" type="text" maxlength="40" pattern="[A-Za-z][A-Za-z0-9_.-]{0,39}" required autocomplete="off">
          </label>
          <label class="block text-sm font-medium text-neutral-700">
            {{ t('eshop.pricing.rate') }}
            <input v-model="rateForm.rate" :class="FIELD_CLASS" data-test="rate-value" type="text" inputmode="decimal" required>
            <span class="mt-1 block text-xs font-normal text-neutral-500">{{ t('eshop.pricing.rate_hint') }}</span>
          </label>
        </div>
        </fieldset>
      </form>

      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" :disabled="saving" @click="closeDialog()">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button
            type="button"
            :class="btnFilled('success')"
            :disabled="saving || !formCanSave"
            :title="!formCanSave ? t('eshop.pricing.complete_required_fields') : undefined"
            data-test="save-pricing"
            @click="save"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
            {{ saving ? t('common.saving') : t('common.save') }}
          </button>
        </div>
      </template>
    </Modal>
  </div>
</template>
