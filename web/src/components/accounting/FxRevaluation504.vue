<script setup lang="ts">
import Fx504CaseEditor from './Fx504CaseEditor.vue'
import SecuritiesValuation504Editor from './SecuritiesValuation504Editor.vue'
import type { SecuritiesEvidence } from '@/api/securities504'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { fx504Api, type Fx504AccountDecision, type Fx504Decision, type Fx504Position, type Fx504Preview, type Fx504Map, type Fx504CaseDecision } from '@/api/fx504'
import { apiErrorMessage } from '@/api/errors'
import { useSupplierStore } from '@/stores/supplier'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import { dimensionValueLabel, dimensionValueName } from '@/utils/dimensionValueLabel'

const props = defineProps<{ supplierId: number; periodId: number; rowVersion: number; endsOn: string; canWrite: boolean; canRevert: boolean }>()
const emit = defineEmits<{ updated: [] }>()
const { t, te } = useI18n()
const supplier = useSupplierStore()
const preview = ref<Fx504Preview | null>(null)
const accounts = ref<Record<string, Fx504AccountDecision>>({})
const decisions = ref<Record<string, Fx504Decision>>({})
const busy = ref(false)
const error = ref('')
const assessedAccounts = ref('')
const cases = ref<Fx504CaseDecision[]>([])
const casesValid = ref(true)
const assessedCases = ref('')
const securities = ref<SecuritiesEvidence | null>(null)
const securitiesValid = ref(true)
const assessedSecurities = ref('null')
const securitiesChanged = computed(() => assessedSecurities.value !== JSON.stringify(securities.value))
function securitiesChange(value: SecuritiesEvidence | null, valid: boolean) { securities.value = value; securitiesValid.value = valid }
const missingRateCurrencies = computed(() => [...new Set([...(preview.value?.positions.filter(row => row.finding === 'rate_required').map(row => row.currency_code) ?? []), ...(preview.value?.securities.findings.some(row => row.code === 'securities_rate_required') ? preview.value.securities.cells.filter(row => row.currency_code !== 'CZK').map(row => row.currency_code) : [])])])
const casesChanged = computed(() => assessedCases.value !== JSON.stringify(cases.value))
function caseChange(value: Fx504CaseDecision[], valid: boolean) { cases.value = value; casesValid.value = valid }
let generation = 0
const sameSupplier = computed(() => supplier.currentSupplierId === props.supplierId)
const accountFingerprint = () => JSON.stringify(accounts.value)
const accountsChanged = computed(() => assessedAccounts.value !== accountFingerprint())
const canEdit = computed(() => props.canWrite && sameSupplier.value && !busy.value)
function map<T>(value: Fx504Map<T>): Record<string, T> { return Array.isArray(value) ? {} : { ...value } }
const absolute = (value: string) => value.startsWith('-') ? value.slice(1) : value
function cents(value: string): bigint | null {
  if (!/^\d+(?:\.\d{1,2})?$/.test(value)) return null
  const [whole, fraction = ''] = value.split('.')
  return BigInt(whole!) * 100n + BigInt(fraction.padEnd(2, '0'))
}
const missingTypes = (position: Fx504Position) => (preview.value?.required_types ?? []).filter(id => !map(position.dimensions)[String(id)])
const valid = computed(() => {
  if (!preview.value || !canEdit.value || accountsChanged.value || casesChanged.value || !casesValid.value || !securitiesValid.value || securitiesChanged.value || !preview.value.securities.complete || preview.value.case_findings.length > 0 || preview.value.case_unresolved.length > 0) return false
  return preview.value.positions.every(position => {
    if (position.finding !== null && position.finding !== 'profit_dimensions_required') return false
    if (position.finding !== 'profit_dimensions_required') return true
    const decision = decisions.value[position.key]
    if (!decision?.reason.trim() || !decision.cells.length || position.diff === null) return false
    const target = cents(absolute(position.diff))
    let total = 0n
    for (const cell of decision.cells) {
      const amount = cents(cell.amount)
      if (amount === null || amount <= 0n || missingTypes(position).some(id => !cell.dimensions[String(id)])) return false
      total += amount
    }
    return total === target
  })
})
function dimensionsLabel(position: Fx504Position): string { return dimensionMapLabel(position.dimensions) }
function dimensionMapLabel(dimensions: Fx504Map<number>): string {
  return Object.entries(dimensions).map(([type, value]) => {
    const dimensionType = preview.value?.dimension_types.find(row => row.id === Number(type))
    const dimensionValue = preview.value?.dimension_values.find(row => row.id === value)
    return `${dimensionType?.name ?? type}: ${dimensionValue ? dimensionValueName({ t, te }, dimensionValue, dimensionType?.system_key) : value}`
  }).join(' · ') || t('fx504.no_dimensions')
}
function addCell(position: Fx504Position) {
  if (!canEdit.value) return
  decisions.value[position.key] ??= { reason: '', cells: [] }
  decisions.value[position.key]!.cells.push({ amount: decisions.value[position.key]!.cells.length ? '' : absolute(position.diff ?? ''), dimensions: map(position.dimensions) })
}
function classify(position: Fx504Position, value: string) {
  if (!canEdit.value) return
  if (!value) { delete accounts.value[position.account_code]; return }
  accounts.value[position.account_code] = { classification: value as Fx504AccountDecision['classification'], reason: accounts.value[position.account_code]?.reason ?? '' }
}
async function act(work: (current: () => boolean) => Promise<void>) {
  if (busy.value || !sameSupplier.value) return
  const ticket = generation
  const sid = props.supplierId
  const current = () => ticket === generation && sid === props.supplierId && sid === supplier.currentSupplierId
  busy.value = true
  error.value = ''
  try { await work(current) } catch (failure) { if (current()) error.value = apiErrorMessage(failure, t('common.error')) }
  finally { if (current()) busy.value = false }
}
async function refresh(initial = false) {
  await act(async current => {
    const result = initial ? await fx504Api.preview(props.supplierId, props.periodId) : await fx504Api.casePreview(props.supplierId, props.periodId, { account_decisions: accounts.value, case_decisions: cases.value, ...(securities.value === null ? {} : { securities_evidence: securities.value }) })
    if (!current()) return
    if (result.accounting_decree !== '504') throw new Error(t('fx504.framework_changed'))
    const previousHash = preview.value?.snapshot_hash
    preview.value = result
    securities.value = result.securities.evidence
    assessedSecurities.value = JSON.stringify(securities.value)
    securitiesValid.value = securities.value !== null || result.securities.cells.length === 0
    cases.value = result.case_decisions
    casesValid.value = result.case_unresolved.length === 0
    assessedCases.value = JSON.stringify(cases.value)
    if (initial) accounts.value = map(result.account_decisions)
    if (initial || previousHash !== result.snapshot_hash) decisions.value = map(result.saved_decisions)
    const keys = new Set(result.positions.filter(row => row.finding === 'profit_dimensions_required').map(row => row.key))
    decisions.value = Object.fromEntries(Object.entries(decisions.value).filter(([key]) => keys.has(key)))
    for (const position of result.positions) {
      if (position.finding === 'profit_dimensions_required' && !decisions.value[position.key]) decisions.value[position.key] = { reason: '', cells: [{ amount: absolute(position.diff ?? ''), dimensions: map(position.dimensions) }] }
    }
    assessedAccounts.value = accountFingerprint()
  })
}
async function fetchRates() {
  await act(async current => {
    for (const currency of missingRateCurrencies.value) {
      await fx504Api.rate(props.supplierId, currency, props.endsOn)
      if (!current()) return
    }
  })
  if (!error.value && sameSupplier.value) await refresh()
}
async function save() {
  if (!valid.value || !preview.value) return
  const body = { row_version: props.rowVersion, snapshot_hash: preview.value.snapshot_hash,
    case_decisions: JSON.parse(JSON.stringify(cases.value)), account_decisions: JSON.parse(JSON.stringify(accounts.value)), decisions: JSON.parse(JSON.stringify(decisions.value)), ...(securities.value === null ? {} : { securities_evidence: JSON.parse(JSON.stringify(securities.value)) as SecuritiesEvidence }) }
  await act(async current => {
    await fx504Api.run(props.supplierId, props.periodId, body)
    if (current()) emit('updated')
  })
}
async function revert() {
  if (!canEdit.value || !props.canRevert || !confirm(t('fx504.revert_confirm'))) return
  await act(async current => {
    await fx504Api.revert(props.supplierId, props.periodId, props.rowVersion)
    if (current()) emit('updated')
  })
}
watch(() => [props.supplierId, props.periodId, props.rowVersion, supplier.currentSupplierId], () => {
  ++generation
  preview.value = null
  accounts.value = {}
  decisions.value = {}
  busy.value = false
  error.value = ''
  assessedAccounts.value = ''
  cases.value = []
  casesValid.value = true
  assessedCases.value = ''
  securities.value = null
  securitiesValid.value = true
  assessedSecurities.value = 'null'
  if (sameSupplier.value) void refresh(true)
}, { immediate: true })
onBeforeUnmount(() => { ++generation })
</script>

<template>
  <section class="space-y-4" data-testid="fx-504">
    <p class="text-sm text-neutral-600">{{ t('fx504.description') }}</p>
    <p v-if="!sameSupplier" role="alert" class="text-warning-700">{{ t('fx504.scope_changed') }}</p>
    <p v-if="error" role="alert" class="text-danger-600">{{ error }}</p>
    <p v-if="busy" role="status" class="text-sm">{{ t('common.loading') }}</p>
    <template v-if="preview">
      <p v-if="!preview.complete" class="rounded border border-warning-300 bg-warning-50 p-3 text-sm">{{ t('fx504.incomplete') }}</p>
      <p v-for="(finding, index) in preview.case_findings" :key="index" role="alert" class="text-sm text-warning-700">{{ t(finding.code === 'case_nominal_unproven' ? 'fx504.case_nominal_unproven' : 'fx504.case_source_changed') }} <span v-if="finding.entry_id">{{ t('fx504.case_source', { entry: finding.entry_id, line: finding.line_id ?? '' }) }}</span></p>
      <p v-if="accountsChanged || casesChanged || securitiesChanged" class="text-sm text-warning-700">{{ t('fx504.refresh_required') }}</p>
      <div class="flex flex-wrap gap-3 text-sm">
        <span>{{ t('fx504.known_loss') }}: <strong>{{ preview.totals.loss }} CZK</strong></span>
        <span>{{ t('fx504.known_gain') }}: <strong>{{ preview.totals.gain }} CZK</strong></span>
        <span v-for="rate in preview.rate_info" :key="rate.currency">{{ rate.currency }}: {{ rate.rate }} ({{ rate.rate_date }})</span>
      </div>
      <SecuritiesValuation504Editor :assessment="preview.securities" :disabled="!canEdit" :dimension-label="dimensionMapLabel" @change="securitiesChange" />
      <Fx504CaseEditor :cells="preview.case_source_cells" :decisions="preview.case_decisions" :unresolved="preview.case_unresolved" :locked="preview.case_locked_cell_keys" :disabled="!canEdit" @change="caseChange" />
      <p v-if="!preview.positions.length" class="text-sm text-neutral-500">{{ t('fx504.empty') }}</p>
      <article v-for="position in preview.positions" :key="position.key" class="rounded-lg border border-neutral-200 p-4 space-y-3">
        <h3 class="font-semibold">{{ position.account_code }} · {{ position.currency_code }}</h3>
        <p class="text-sm">{{ dimensionsLabel(position) }}</p>
        <div class="flex flex-wrap gap-4 text-sm">
          <span>{{ t('fx504.foreign') }}: {{ position.foreign_balance }} {{ position.currency_code }}</span>
          <span>{{ t('fx504.booked') }}: {{ position.booked_czk }} CZK</span>
          <span>{{ t('fx504.difference') }}: {{ position.diff ?? t('fx504.unresolved') }}</span>
        </div>
        <p v-if="position.finding" class="text-sm text-warning-700">{{ t(`fx504.finding.${position.finding}`) }}</p>
        <p v-if="position.classification === 'nonmonetary'" class="text-sm">{{ t('fx504.nonmonetary_record') }}</p>
        <fieldset v-if="position.finding !== 'securities_valuation_required'" :disabled="!canEdit" class="flex flex-wrap items-end gap-3">
          <label class="text-sm">{{ t('fx504.classification') }}
            <select :value="accounts[position.account_code]?.classification ?? ''" class="block h-9 rounded border border-neutral-300 px-2" @change="classify(position, ($event.target as HTMLSelectElement).value)">
              <option value="">{{ t('fx504.source_classification') }}</option><option value="monetary">{{ t('fx504.monetary') }}</option><option value="nonmonetary">{{ t('fx504.nonmonetary') }}</option>
            </select>
          </label>
          <label v-if="accounts[position.account_code]" class="text-sm grow">{{ t('fx504.evidence') }}<input v-model="accounts[position.account_code]!.reason" maxlength="2000" class="block w-full h-9 rounded border border-neutral-300 px-2" /></label>
        </fieldset>
        <fieldset v-if="decisions[position.key]" :disabled="!canEdit" class="space-y-3">
          <label class="block text-sm">{{ t('fx504.joint_reason') }}<input v-model="decisions[position.key]!.reason" maxlength="2000" class="block w-full h-9 rounded border border-neutral-300 px-2" /></label>
          <div v-for="(cell, index) in decisions[position.key]!.cells" :key="index" class="flex flex-wrap items-end gap-2">
            <label class="text-sm">{{ t('fx504.amount') }}<input v-model="cell.amount" inputmode="decimal" class="block w-32 h-9 rounded border border-neutral-300 px-2" /></label>
            <label v-for="typeId in missingTypes(position)" :key="typeId" class="text-sm">{{ preview.dimension_types.find(type => type.id === typeId)?.name }}
              <select v-model="cell.dimensions[String(typeId)]" class="block h-9 rounded border border-neutral-300 px-2"><option :value="undefined">{{ t('fx504.choose') }}</option><option v-for="value in preview.dimension_values.filter(row => row.type_id === typeId && row.is_active)" :key="value.id" :value="value.id">{{ dimensionValueLabel({ t, te }, value, preview.dimension_types.find(type => type.id === typeId)?.system_key) }}</option></select>
            </label>
            <button type="button" :class="btnOutline('danger')" @click="decisions[position.key]!.cells.splice(index, 1)"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.trash" /></svg>{{ t('fx504.remove_cell') }}</button>
          </div>
          <button type="button" :class="btnOutline('neutral')" @click="addCell(position)"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.plus" /></svg>{{ t('fx504.add_cell') }}</button>
        </fieldset>
      </article>
    </template>
    <div class="flex flex-wrap gap-2">
      <button type="button" :disabled="busy || !sameSupplier || !casesValid || !securitiesValid" :class="btnOutline('neutral')" @click="refresh()"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.cycle" /></svg>{{ t('fx504.refresh') }}</button>
      <button type="button" :disabled="busy || !sameSupplier" :class="btnOutline('neutral')" @click="refresh(true)"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.cycle" /></svg>{{ t('fx504.reload_sources') }}</button>
      <button v-if="missingRateCurrencies.length" type="button" :disabled="busy || !sameSupplier" :class="btnOutline('neutral')" @click="fetchRates"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.cycle" /></svg>{{ t('fx504.fetch_rates') }}</button>
      <button v-if="canWrite" type="button" :disabled="!valid" :class="btnFilled('success')" data-testid="fx504-save" @click="save"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.check" /></svg>{{ t('fx504.book') }}</button>
      <button v-if="canRevert && canWrite" type="button" :disabled="!canEdit" :class="btnOutline('danger')" @click="revert"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.uturn" /></svg>{{ t('fx504.revert') }}</button>
    </div>
  </section>
</template>
