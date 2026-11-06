<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { closingApi, type DeferredTax504Decision, type DeferredTax504ReviewedDecision, type DeferredTax504Title, type DeferredTax504Preview } from '@/api/closing'
import { dimensionsApi, type DimensionOverview } from '@/api/dimensions'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { dimensionValueName } from '@/utils/dimensionValueLabel'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'

const props = defineProps<{ periodId: number; rowVersion: number; canWrite: boolean }>()
const emit = defineEmits<{ updated: [] }>()
const { t, te } = useI18n()
const form = ref<HTMLFormElement>()
const enabled = ref(false)
const existing = ref<DeferredTax504Preview | null>(null)
const reviewed = ref<{ result: DeferredTax504Preview; body: DeferredTax504ReviewedDecision } | null>(null)
const dimensions = ref<DimensionOverview | null>(null)
const busy = ref(false)
const error = ref('')
const decision = ref<DeferredTax504Decision>(empty())
let scope = 0
const textFields = ['reference', 'faithful_image_reason', 'standard_departure_reason', 'presentation_reason'] as const
const groups = computed(() => decision.value.method.first_adoption ? ['opening_titles', 'titles'] as const : ['titles'] as const)
const types = computed(() => dimensions.value?.types.filter(type => type.is_active) ?? [])
function empty(): DeferredTax504Decision {
  return { method: { enabled: true, version: '504-deferred-own-v1', first_adoption: false, reference: '', faithful_image_reason: '', standard_departure_reason: '', presentation_reason: '' }, titles: [], prepare_accounts: false }
}
function cellLabel(cell: Record<number, number> | undefined) {
  return Object.values(cell ?? {}).map((id) => {
    const value = dimensions.value?.values.find(row => row.id === id)
    return value ? dimensionValueName({ t, te }, value, dimensions.value?.types.find(type => type.id === value.type_id)?.system_key, siblings(value.type_id)) : `#${id}`
  }).join(' · ')
}
function siblings(typeId: number) {
  return dimensions.value?.values.filter(row => row.type_id === typeId)
}
function clone<T>(value: T): T { return JSON.parse(JSON.stringify(value)) as T }
function add(group: 'opening_titles' | 'titles') {
  const row: DeferredTax504Title = { reference: '', evidence: '', kind: 'taxable', difference: '', rate_percent: '', rate_basis: '', temporary_confirmed: false, dimensions: {} }
  ;(decision.value[group] ??= []).push(row)
}
function setDimension(row: DeferredTax504Title, type: number, value: string) {
  if (value) row.dimensions[type] = Number(value)
  else delete row.dimensions[type]
}
watch([decision, enabled], () => { reviewed.value = null }, { deep: true })
watch(() => props.rowVersion, () => { reviewed.value = null })
async function load() {
  const current = ++scope
  busy.value = true
  reviewed.value = null
  error.value = ''
  try {
    const [result, overview] = await Promise.all([closingApi.deferredTax504Preview(props.periodId), dimensionsApi.overview()])
    if (current !== scope) return
    if (result.accounting_framework !== 'cz_504') throw new Error(t('accounting.deferred504.changed'))
    existing.value = result
    dimensions.value = overview
    enabled.value = result.enabled
    decision.value = result.decision ? clone(result.decision) : empty()
    decision.value.method.first_adoption = false
    delete decision.value.method.first_adoption_reason
    delete decision.value.opening_titles
    decision.value.prepare_accounts = false
  } catch (e) { if (current === scope) error.value = apiErrorMessage(e, t('common.error')) } finally { if (current === scope) busy.value = false }
}
watch(() => props.periodId, () => { void load() }, { immediate: true })
function valid(): boolean {
  return Boolean(form.value?.reportValidity()) && enabled.value && groups.value.every(group => (decision.value[group] ?? []).every(row =>
    [row.difference, row.rate_percent, ...(row.kind === 'deductible' ? [row.recognized_difference] : [])].every(amount => /^\d+(?:\.\d{1,2})?$/.test(String(amount)))))
}
async function preview() {
  if (busy.value || !valid()) return
  const current = scope
  const body = clone(decision.value)
  if (!body.method.first_adoption) { delete body.opening_titles; delete body.method.first_adoption_reason }
  busy.value = true
  error.value = ''
  reviewed.value = null
  try {
    const result = await closingApi.deferredTax504Preview(props.periodId, body)
    if (current !== scope) return
    if (result.row_version !== props.rowVersion || result.accounting_framework !== 'cz_504' || !result.source_hash) throw new Error(t('accounting.deferred504.changed'))
    reviewed.value = { result, body: { ...body, source_hash: result.source_hash } }
  } catch (e) { if (current === scope) error.value = apiErrorMessage(e, t('common.error')) } finally { if (current === scope) busy.value = false }
}
async function post() {
  if (busy.value || !props.canWrite || !reviewed.value || !valid()) return
  const current = scope
  const confirmed = reviewed.value
  if (confirmed.result.row_version !== props.rowVersion) { reviewed.value = null; return }
  busy.value = true
  error.value = ''
  try {
    await closingApi.deferredTax504Run(props.periodId, confirmed.result.row_version, confirmed.body)
    if (current !== scope) return
    reviewed.value = null
    emit('updated')
    await load()
  } catch (e) { if (current === scope) error.value = apiErrorMessage(e, t('common.error')) } finally { if (current === scope) busy.value = false }
}
</script>

<template>
  <section class="space-y-4" data-test="deferred-tax-504">
    <div class="flex flex-wrap items-center gap-3">
      <h3 class="font-medium mr-auto">{{ t('accounting.deferred504.title') }}</h3>
      <span v-if="existing" class="text-sm">{{ formatDate(existing.as_of) }}</span>
      <button type="button" :class="btnOutline('neutral')" :disabled="busy" @click="load"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.cycle" /></svg>{{ t('accounting.deferred504.reload') }}</button>
    </div>
    <p class="text-sm text-neutral-600">{{ t('accounting.deferred504.method_hint') }}</p>
    <p v-if="existing?.assessment_current === false" data-test="deferred-stale" role="status" class="rounded-md border border-warning-500/30 bg-warning-50 p-3 text-sm text-warning-700">{{ t('accounting.deferred504.stale') }}</p>
    <p v-if="error" role="alert" class="text-sm text-danger-600">{{ error }}</p>
    <form v-if="existing" ref="form" class="space-y-4" @submit.prevent="preview">
      <fieldset :disabled="busy" class="space-y-4">
        <label class="flex gap-2 text-sm"><input v-model="enabled" data-test="deferred-enabled" type="checkbox" :disabled="existing.enabled" />{{ t('accounting.deferred504.opt_in') }}</label>
        <template v-if="enabled">
          <label v-for="key in textFields" :key="key" class="block text-sm">{{ t(`accounting.deferred504.${key}`) }}<textarea v-model="decision.method[key]" required maxlength="2000" rows="2" class="block w-full border border-neutral-300 rounded-md p-2" /></label>
          <label v-if="!existing.enabled" class="flex gap-2 text-sm"><input v-model="decision.method.first_adoption" type="checkbox" />{{ t('accounting.deferred504.first_adoption') }}</label>
          <label v-if="decision.method.first_adoption" class="block text-sm">{{ t('accounting.deferred504.first_adoption_reason') }}<textarea v-model="decision.method.first_adoption_reason" required maxlength="2000" rows="2" class="block w-full border border-neutral-300 rounded-md p-2" /></label>
          <section v-for="group in groups" :key="group" class="space-y-3">
            <h4 class="font-medium">{{ t(`accounting.deferred504.${group}`) }}</h4>
            <p class="text-sm text-neutral-600">{{ t('accounting.deferred504.target_hint') }}</p>
            <div v-for="(row, index) in decision[group]" :key="index" class="rounded-md border border-neutral-200 p-3 space-y-3">
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label class="text-sm">{{ t('accounting.deferred504.title_reference') }}<input v-model="row.reference" required maxlength="2000" class="block w-full h-9 border border-neutral-300 rounded-md px-2" /></label>
                <label class="text-sm">{{ t('accounting.deferred504.kind') }}<select v-model="row.kind" class="block w-full h-9 border border-neutral-300 rounded-md px-2 bg-surface"><option value="taxable">{{ t('accounting.deferred504.taxable') }}</option><option value="deductible">{{ t('accounting.deferred504.deductible') }}</option></select></label>
                <label class="text-sm">{{ t('accounting.deferred504.difference') }}<input v-model="row.difference" data-test="deferred-difference" type="text" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,2})?" required class="block w-full h-9 border border-neutral-300 rounded-md px-2" /></label>
                <label class="text-sm">{{ t('accounting.deferred504.rate') }}<input v-model="row.rate_percent" type="text" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,2})?" required class="block w-full h-9 border border-neutral-300 rounded-md px-2" /></label>
              </div>
              <label class="block text-sm">{{ t('accounting.deferred504.evidence') }}<textarea v-model="row.evidence" required maxlength="2000" rows="2" class="block w-full border border-neutral-300 rounded-md p-2" /></label>
              <label class="block text-sm">{{ t('accounting.deferred504.rate_basis') }}<input v-model="row.rate_basis" required maxlength="2000" class="block w-full h-9 border border-neutral-300 rounded-md px-2" /></label>
              <template v-if="row.kind === 'deductible'">
                <label class="block text-sm">{{ t('accounting.deferred504.recognized') }}<input v-model="row.recognized_difference" type="text" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,2})?" required class="block w-full h-9 border border-neutral-300 rounded-md px-2" /></label>
                <label class="block text-sm">{{ t('accounting.deferred504.recoverability') }}<textarea v-model="row.recoverability_evidence" :required="Number(row.recognized_difference) > 0" maxlength="2000" rows="2" class="block w-full border border-neutral-300 rounded-md p-2" /></label>
              </template>
              <div class="flex flex-wrap gap-3">
                <label v-for="type in types" :key="type.id" class="text-sm">{{ type.name }}<select :value="row.dimensions[type.id] ?? ''" :required="type.system_key === 'activity'" class="block h-9 border border-neutral-300 rounded-md px-2 bg-surface" @change="setDimension(row, type.id, ($event.target as HTMLSelectElement).value)"><option value="">{{ t('accounting.deferred504.choose') }}</option><option v-for="value in dimensions?.values.filter(value => value.type_id === type.id && (value.is_active || row.dimensions[type.id] === value.id))" :key="value.id" :value="value.id">{{ dimensionValueName({ t, te }, value, type.system_key, siblings(type.id)) }}</option></select></label>
              </div>
              <label class="flex gap-2 text-sm"><input v-model="row.temporary_confirmed" type="checkbox" required />{{ t('accounting.deferred504.temporary') }}</label>
              <button type="button" :class="btnOutline('danger')" @click="decision[group]?.splice(index, 1)"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.trash" /></svg>{{ t('accounting.deferred504.remove') }}</button>
            </div>
            <button type="button" :class="btnOutline('neutral')" @click="add(group)"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.plus" /></svg>{{ t('accounting.deferred504.add') }}</button>
          </section>
          <label class="flex gap-2 text-sm"><input v-model="decision.prepare_accounts" type="checkbox" />{{ t('accounting.deferred504.prepare_accounts') }}</label>
          <button type="submit" :class="btnOutline('neutral')"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.eye" /></svg>{{ t('accounting.deferred504.preview') }}</button>
        </template>
      </fieldset>
    </form>
    <section v-if="reviewed" class="space-y-3 border border-neutral-200 rounded-md p-3">
      <dl data-test="deferred-totals" class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div><dt>{{ t('accounting.deferred504.liability') }}</dt><dd class="font-mono">{{ formatMoney((reviewed.result.assessment.enabled ? reviewed.result.assessment.closing.liability_cents : 0) / 100) }}</dd></div>
        <div><dt>{{ t('accounting.deferred504.asset') }}</dt><dd class="font-mono">{{ formatMoney((reviewed.result.assessment.enabled ? reviewed.result.assessment.closing.asset_cents : 0) / 100) }}</dd></div>
        <div><dt>{{ t('accounting.deferred504.unrecognized') }}</dt><dd class="font-mono">{{ formatMoney((reviewed.result.assessment.enabled ? reviewed.result.assessment.closing.unrecognized_asset_cents : 0) / 100) }}</dd></div>
      </dl>
      <div v-for="phase in (['opening', 'closing'] as const)" :key="phase" class="space-y-1">
        <h4 v-if="reviewed.result.movements[phase].length" class="font-medium">{{ t(`accounting.deferred504.${phase}_movement`) }}</h4>
        <p v-for="(line, index) in reviewed.result.movements[phase]" :key="index" class="text-sm font-mono">{{ line.account_code }} · {{ t(`accounting.deferred504.${line.side}`) }} · {{ formatMoney(line.amount) }} · {{ cellLabel(line.dimensions) }}</p>
      </div>
      <button v-if="canWrite" data-test="deferred-post" type="button" :disabled="busy" :class="btnFilled('primary')" @click="post"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.check" /></svg>{{ t('accounting.deferred504.post') }}</button>
    </section>
  </section>
</template>
