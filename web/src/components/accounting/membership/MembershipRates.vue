<script setup lang="ts">
import { computed, onActivated, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { formInput as field, formLabel, formTextarea as textarea } from '@/components/ui/formStyles'
import { membershipApi, type MembershipRate, type MembershipRateInput } from '@/api/nonprofitMembership'
import { accountingApi, type ChartAccount } from '@/api/accounting'
import type { DimensionOverview } from '@/api/dimensions'
import { accountPickerOptions } from '@/utils/chartAccountOptions'
import { dimensionValueName } from '@/utils/dimensionValueLabel'
import { formatMoney } from '@/composables/useFormat'
import { amountDecimal, errorBox, errorMessage } from './membershipFormat'
import { notifyMembershipRatesChanged, onMembershipRatesChanged } from './membershipRateChanges'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'

const props = defineProps<{ supplier: number; canManage: boolean; selectable?: boolean; disabled?: boolean }>()
const emit = defineEmits<{ selected: [rate: MembershipRate | null] }>()
const { t, te } = useI18n()
const items = ref<MembershipRate[]>([]), next = ref<number | null>(null), selected = ref<number | null>(null)
const includeInactive = ref(false), loading = ref(false), saving = ref(false), editing = ref(false)
const error = ref(''), notice = ref(''), editor = ref<HTMLFormElement | null>(null)
const accounts = ref<ChartAccount[]>([]), dimensions = ref<DimensionOverview | null>(null)
const editId = ref<number | null>(null), withDimensions = ref(false)
const editorLoading = ref(false)
const editorError = ref(''), editorReady = ref(false)
const blank = (): MembershipRateInput => ({ name: '', amount: '', receivable_account: '', revenue_account: '', title_key: '', period_months: 0, title_reference: '', evidence: '', classification: null, is_active: true, row_version: 0 })
const form = reactive(blank())
const selection = reactive({ activity_value_id: null as number | null, activity_kind_value_id: null as number | null, tax_regime_value_id: null as number | null })
let alive = true, generation = 0, editorGeneration = 0, saveGeneration = 0
const chosen = computed(() => items.value.find(x => x.id === selected.value))
const rateOptions = computed(() => items.value.map(x => ({ value: x.id, label: `${x.name} · ${formatMoney(x.amount)}${x.is_active ? '' : ` · ${t('nonprofitMembership.rateInactive')}`}` })))
function chooseRate(value: unknown) {
  if (props.disabled || saving.value || loading.value) return
  selected.value = typeof value === 'number' && items.value.some(x => x.id === value) ? value : null
}
const accountOptions = (type: string) => accountPickerOptions(accounts.value, a => a.account_type === type && (type !== 'revenue' || a.account_code.startsWith('684'))).map(a => ({ value: a.account_code, label: `${a.account_code} ${a.name}` }))
const axes = ['activity', 'activity_kind', 'tax_regime'] as const
const axisOptions = (axis: typeof axes[number]) => {
  const types = dimensions.value?.types.filter(x => x.supplier_id === props.supplier && x.system_key === axis && x.is_active) ?? []
  if (types.length !== 1) return []
  return (dimensions.value?.values ?? []).filter(v => v.supplier_id === props.supplier && v.type_id === types[0]!.id && v.is_active
    && (axis === 'activity_kind' || (axis === 'activity' ? ['main', 'economic'] : ['subject', 'out_of_scope', 'exempt']).includes(v.semantic_key ?? '')))
    .map(v => ({ value: v.id, label: dimensionValueName({ t, te }, v, axis) }))
}

async function load(append = false) {
  const ticket = ++generation, supplier = props.supplier
  loading.value = true; error.value = ''
  try {
    const page = await membershipApi.rates(supplier, append ? next.value ?? 0 : 0, !includeInactive.value)
    const picked = selected.value
    const selectedRate = !append && picked && !page.items.some(x => x.id === picked) ? await membershipApi.rate(supplier, picked) : null
    if (!alive || ticket !== generation || supplier !== props.supplier) return
    items.value = append ? [...new Map([...items.value, ...page.items].map(row => [row.id, row])).values()] : page.items
    if (selectedRate && selected.value === picked) items.value.push(selectedRate)
    next.value = page.next_after_id
    if (!items.value.some(x => x.id === selected.value)) selected.value = null
  } catch (e) { if (alive && ticket === generation) error.value = errorMessage(e) || t('nonprofitMembership.error') }
  finally { if (alive && ticket === generation) loading.value = false }
}

async function openEditor(rate?: MembershipRate) {
  if (editing.value || saving.value || props.disabled) return
  editId.value = rate?.id ?? null
  Object.assign(form, blank(), rate ? { ...rate } : {})
  withDimensions.value = !!rate?.classification
  for (const axis of axes) selection[`${axis}_value_id`] = rate?.classification?.[`${axis}_value_id`] ?? null
  editing.value = true; error.value = ''; notice.value = ''
  await loadEditorOptions()
}

async function loadEditorOptions() {
  const ticket = ++editorGeneration, supplier = props.supplier
  editorLoading.value = true; editorReady.value = false; editorError.value = ''
  try {
    const [chart, overview] = await Promise.all([accountingApi.listAccounts({ supplierId: supplier }), membershipApi.classificationOptions(supplier)])
    if (!alive || ticket !== editorGeneration || supplier !== props.supplier) return
    accounts.value = chart; dimensions.value = overview; editorReady.value = true
  } catch (e) { if (alive && ticket === editorGeneration) editorError.value = errorMessage(e) || t('nonprofitMembership.error') }
  finally { if (alive && ticket === editorGeneration) editorLoading.value = false }
}

async function save() {
  if (!props.canManage || props.disabled || saving.value || !editorReady.value || editorLoading.value || !editor.value?.reportValidity()) return
  const amount = amountDecimal(form.amount)
  if (!amount || (withDimensions.value && axes.some(a => !axisOptions(a).some(o => o.value === selection[`${a}_value_id`])))) {
    error.value = t('nonprofitMembership.rateInvalid'); return
  }
  const supplier = props.supplier, ticket = ++saveGeneration
  const body: MembershipRateInput = {
    name: form.name.trim(), amount, receivable_account: form.receivable_account, revenue_account: form.revenue_account,
    title_key: form.title_key, period_months: form.period_months, title_reference: form.title_reference.trim(), evidence: form.evidence.trim(),
    classification: withDimensions.value ? { activity_value_id: selection.activity_value_id!, activity_kind_value_id: selection.activity_kind_value_id!, tax_regime_value_id: selection.tax_regime_value_id! } : null,
    is_active: form.is_active, row_version: form.row_version,
  }
  saving.value = true; error.value = ''
  try {
    const saved = editId.value === null ? await membershipApi.createRate(supplier, body) : await membershipApi.updateRate(supplier, editId.value, body)
    if (!alive || supplier !== props.supplier || ticket !== saveGeneration) return
    editing.value = false; selected.value = null
    // Uložení katalogu nesmí bez další volby přepsat rozepsaný předpis.
    notice.value = t('nonprofitMembership.rateSaved')
    await load()
    if (!alive || supplier !== props.supplier || ticket !== saveGeneration) return
    if (!items.value.some(x => x.id === saved.id) && (includeInactive.value || saved.is_active)) items.value.push(saved)
    selected.value = items.value.some(x => x.id === saved.id) ? saved.id : null
    notifyMembershipRatesChanged(supplier)
  } catch (e) { if (alive && supplier === props.supplier && ticket === saveGeneration) error.value = errorMessage(e) || t('nonprofitMembership.error') }
  finally { if (alive && supplier === props.supplier && ticket === saveGeneration) saving.value = false }
}

const actions = computed<ActionItem[]>(() => [
  { key: 'use-rate', label: t('nonprofitMembership.rateUse'), icon: 'check', tier: 'primary', variant: 'primary', show: props.selectable, disabled: props.disabled || loading.value || !!error.value || !chosen.value?.is_active, run: () => emit('selected', chosen.value ?? null) },
  { key: 'manual-rate', label: t('nonprofitMembership.rateManual'), icon: 'edit', show: props.selectable, disabled: props.disabled, run: () => { selected.value = null; emit('selected', null) } },
  { key: 'new-rate', label: t('nonprofitMembership.rateNew'), icon: 'plus', show: props.canManage && !editing.value, disabled: props.disabled || saving.value, run: () => { void openEditor() } },
  { key: 'edit-rate', label: t('nonprofitMembership.rateEdit'), icon: 'edit', show: props.canManage && !editing.value, disabled: props.disabled || saving.value || !chosen.value, run: () => { if (chosen.value) void openEditor(chosen.value) } },
  { key: 'reload-rates', label: t('nonprofitMembership.reload'), icon: 'cycle', disabled: loading.value || saving.value, run: () => { void load() } },
])
const editActions = computed<ActionItem[]>(() => [
  { key: 'save-rate', label: t('nonprofitMembership.rateSave'), icon: 'check', tier: 'primary', variant: 'success', disabled: saving.value || !editorReady.value || editorLoading.value || props.disabled, run: () => { void save() } },
  { key: 'reload-rate-options', label: t('nonprofitMembership.rateRetryOptions'), icon: 'cycle', show: !!editorError.value, disabled: editorLoading.value || saving.value, run: () => { void loadEditorOptions() } },
  { key: 'cancel-rate', label: t('common.cancel'), icon: 'x', disabled: saving.value, run: () => { editorGeneration++; editing.value = false; editorLoading.value = false } },
])
watch(includeInactive, () => { if (!saving.value) void load() })
watch(() => props.supplier, () => {
  generation++; editorGeneration++; saveGeneration++; selected.value = null; items.value = []; next.value = null; editing.value = false; saving.value = false; editorLoading.value = false
  accounts.value = []; dimensions.value = null; error.value = ''; notice.value = ''; editorError.value = ''; editorReady.value = false
  void load()
})
onMounted(() => { void load() })
let firstActivation = true
onActivated(() => {
  if (firstActivation) { firstActivation = false; return }
  void load()
})
onMembershipRatesChanged(supplier => { if (supplier === props.supplier && !saving.value) void load() })
onBeforeUnmount(() => { alive = false; generation++; editorGeneration++; saveGeneration++ })
</script>

<template>
  <section class="space-y-3 rounded-lg border bg-surface p-4" data-section="membership-rates">
    <h2 class="font-semibold">{{ t('nonprofitMembership.ratesTitle') }}</h2>
    <p class="text-sm text-neutral-500">{{ t('nonprofitMembership.ratesHelp') }}</p>
    <p v-if="error" role="alert" :class="errorBox">{{ error }}</p>
    <p v-if="notice" role="status" class="text-sm text-success-700">{{ notice }}</p>
    <p v-if="loading" role="status" class="text-sm text-neutral-500">{{ t('common.loading') }}</p>
    <label :class="formLabel">{{ t('nonprofitMembership.rateChoose') }}<SearchableSelect :key="loading ? 'loading' : 'ready'" :model-value="selected" :options="rateOptions" :disabled="disabled || saving || loading" data-field="rate-select" @update:model-value="chooseRate" /></label>
    <div v-if="next !== null" class="flex flex-wrap items-center gap-3 text-sm">
      <p class="text-neutral-500">{{ t('nonprofitMembership.ratePartialList') }}</p>
      <button type="button" :class="btnOutline('neutral')" :disabled="loading || saving" data-action="more-rates" @click="load(true)"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.chevron" /></svg>{{ t('nonprofitMembership.rateMore') }}</button>
    </div>
    <label class="flex items-center gap-2 text-sm"><input v-model="includeInactive" type="checkbox" :disabled="saving" />{{ t('nonprofitMembership.rateShowInactive') }}</label>
    <p v-if="!loading && !items.length" class="text-sm text-neutral-500">{{ t('nonprofitMembership.ratesEmpty') }}</p>
    <ActionBar :actions="actions" />
    <form v-if="editing" ref="editor" class="space-y-3 border-t pt-3" data-section="rate-editor" @submit.prevent="save">
      <p v-if="editorLoading" role="status" class="text-sm text-neutral-500">{{ t('common.loading') }}</p>
      <p v-if="editorError" role="alert" :class="errorBox" data-section="rate-editor-error">{{ editorError }}</p>
      <fieldset :disabled="saving || disabled || editorLoading" class="grid gap-3 sm:grid-cols-2">
        <label :class="formLabel">{{ t('nonprofitMembership.rateName') }}<input v-model="form.name" :class="field" required maxlength="160" data-field="rate-name" /></label>
        <label :class="formLabel">{{ t('nonprofitMembership.amount') }}<input v-model="form.amount" :class="field" required inputmode="decimal" data-field="rate-amount" /></label>
        <label :class="formLabel">{{ t('nonprofitMembership.receivableAccount') }}<SearchableSelect v-model="form.receivable_account" :options="accountOptions('asset')" data-field="rate-receivable" /></label>
        <label :class="formLabel">{{ t('nonprofitMembership.revenueAccount') }}<SearchableSelect v-model="form.revenue_account" :options="accountOptions('revenue')" data-field="rate-revenue" /></label>
        <label :class="formLabel">{{ t('nonprofitMembership.titleKey') }}<input v-model="form.title_key" :class="field" required maxlength="80" pattern="[A-Za-z0-9][A-Za-z0-9_.:\-]{0,79}" data-field="rate-title" /></label>
        <label :class="formLabel">{{ t('nonprofitMembership.ratePeriod') }}<select v-model="form.period_months" :class="field" data-field="rate-months"><option v-for="months in [0, 1, 3, 6, 12]" :key="months" :value="months">{{ t(`nonprofitMembership.rateMonths${months}`) }}</option></select></label>
        <p class="text-sm text-neutral-500 sm:col-span-2">{{ t('nonprofitMembership.ratePeriodHelp') }}</p>
        <label :class="formLabel">{{ t('nonprofitMembership.titleReference') }}<textarea v-model="form.title_reference" :class="textarea" required maxlength="2000" data-field="rate-reference" /></label>
        <label :class="formLabel">{{ t('nonprofitMembership.evidence') }}<textarea v-model="form.evidence" :class="textarea" required maxlength="2000" data-field="rate-evidence" /></label>
        <label class="flex items-center gap-2 text-sm sm:col-span-2"><input v-model="withDimensions" type="checkbox" data-field="rate-with-dimensions" />{{ t('nonprofitMembership.rateDimensions') }}</label>
        <template v-if="withDimensions"><label v-for="axis in axes" :key="axis" :class="formLabel">{{ t(`nonprofitMembership.${axis === 'activity' ? 'classificationActivity' : axis === 'activity_kind' ? 'classificationKind' : 'classificationTaxRegime'}`) }}<SearchableSelect v-model="selection[`${axis}_value_id`]" :options="axisOptions(axis)" :data-field="`rate-${axis}`" /></label></template>
        <label class="flex items-center gap-2 text-sm sm:col-span-2"><input v-model="form.is_active" type="checkbox" data-field="rate-active" />{{ t('nonprofitMembership.rateActive') }}</label>
      </fieldset>
      <ActionBar :actions="editActions" />
    </form>
  </section>
</template>
