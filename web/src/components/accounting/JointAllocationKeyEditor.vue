<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import DateInput from '@/components/ui/DateInput.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import type { DimensionType, DimensionValue } from '@/api/dimensions'
import { dimensionValueName } from '@/utils/dimensionValueLabel'
import { jointAllocationsApi, jointAxes, type JointCell, type JointVersion, type JointVersionInput, type RevenueProposal } from '@/api/jointAllocations'

const props = defineProps<{ types: DimensionType[]; values: DimensionValue[]; versions: JointVersion[]; busy: boolean; supplierId: number }>()
const emit = defineEmits<{ save: [input: { keyId: number; code: string; name: string; version: JointVersionInput }]; close: [] }>()
const { t, te } = useI18n()
const form = ref<HTMLFormElement | null>(null)
const keyId = ref(0)
const code = ref('')
const name = ref('')
const additional = ref<number[]>([])
const emptyCell = (): JointCell => ({ activity: 0, activity_kind: 0, tax_regime: 0, weight: '1', additional_dimensions: {} })
const version = ref<JointVersionInput>({ valid_from: '', valid_to: '', basis: 'fixed', explanation: '', allocation_mode: 'weighted', cells: [emptyCell()] })
const keys = computed(() => props.versions.filter((v, i, list) => list.findIndex(k => k.key_id === v.key_id) === i))
const customTypes = computed(() => props.types.filter(type => type.is_active && !jointAxes.includes(type.system_key as typeof jointAxes[number])))
const axisTypes = computed(() => jointAxes.map(axis => ({ axis, type: props.types.find(type => type.system_key === axis) })))
const choices = (typeId?: number) => props.values.filter(value => value.type_id === typeId && value.is_active)
function addCell() {
  const cell = emptyCell()
  cell.additional_dimensions = Object.fromEntries(additional.value.map(id => [id, 0]))
  version.value.cells.push(cell)
}
function setAxes() {
  for (const cell of version.value.cells) cell.additional_dimensions = Object.fromEntries(additional.value.map(id => [id, cell.additional_dimensions?.[id] ?? 0]))
}
function copy(source: JointVersion) {
  keyId.value = 0
  code.value = ''
  name.value = source.name
  version.value = { valid_from: source.valid_from, valid_to: source.valid_to, basis: source.basis, explanation: source.explanation,
    allocation_mode: source.allocation_mode, cells: JSON.parse(JSON.stringify(source.cells)) as JointCell[] }
  additional.value = Object.keys(source.cells[0]?.additional_dimensions ?? {}).map(Number)
}
const isoDate = /^(\d{4})-(\d{2})-(\d{2})$/
function validIsoDate(value: string) {
  const match = isoDate.exec(value)
  if (!match) return false
  const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])))
  return date.toISOString().slice(0, 10) === value
}
const sourceFrom = ref('')
const sourceTo = ref('')
const proposal = ref<RevenueProposal | null>(null)
const proposalLoading = ref(false)
const proposalError = ref('')
const proposalForm = ref<HTMLFormElement | null>(null)
let proposalSeq = 0
const rangeValid = computed(() => validIsoDate(sourceFrom.value) && validIsoDate(sourceTo.value) && sourceFrom.value <= sourceTo.value)
const canFetchProposal = computed(() => !props.busy && !proposalLoading.value && rangeValid.value)
const canApplyProposal = computed(() => !props.busy && !proposalLoading.value && proposal.value?.can_propose === true
  && proposal.value.cells.length > 0 && version.value.allocation_mode === 'weighted' && additional.value.length === 0)
const applyBlocked = computed(() => {
  if (!proposal.value?.can_propose) return ''
  if (version.value.allocation_mode !== 'weighted') return 'applyNeedsWeighted'
  if (additional.value.length > 0) return 'applyNoAdditionalAxes'
  return ''
})
const knownReasons = ['no_revenue', 'unclassified_revenue', 'split_dimensions', 'negative_cell', 'too_many_cells', 'system_dimensions_missing']
const reasonKeys = computed(() => (proposal.value?.reason_codes ?? []).map(code => knownReasons.includes(code) ? code : 'unknown'))
function formatCents(cents: string) {
  if (!/^-?\d+$/.test(cents)) return cents
  const value = BigInt(cents)
  const abs = value < 0n ? -value : value
  const whole = (abs / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ')
  return `${value < 0n ? '-' : ''}${whole},${(abs % 100n).toString().padStart(2, '0')}`
}
function valueName(id: number) {
  const value = props.values.find(row => row.id === id)
  return value ? dimensionValueName({ t, te }, value, props.types.find(type => type.id === value.type_id)?.system_key) : t('jointAllocations.missingValue')
}
function resetProposal() {
  proposalSeq++
  proposal.value = null
  proposalError.value = ''
  proposalLoading.value = false
}
watch([sourceFrom, sourceTo, () => props.supplierId], resetProposal)
onBeforeUnmount(() => { proposalSeq++ })
async function fetchProposal() {
  if (!canFetchProposal.value || proposalForm.value?.reportValidity() === false) return
  const seq = ++proposalSeq
  const supplierId = props.supplierId
  const params = { from: sourceFrom.value, to: sourceTo.value }
  proposal.value = null
  proposalError.value = ''
  proposalLoading.value = true
  try {
    const result = await jointAllocationsApi.revenueProposal(supplierId, params)
    if (seq !== proposalSeq || supplierId !== props.supplierId) return
    if (result.basis !== 'revenue' || result.date_from !== params.from || result.date_to !== params.to) proposalError.value = 'failed'
    else proposal.value = result
  } catch (error) {
    if (seq !== proposalSeq || supplierId !== props.supplierId) return
    proposalError.value = (error as { response?: { status?: number } })?.response?.status === 422 ? 'rejected' : 'failed'
  } finally {
    if (seq === proposalSeq) proposalLoading.value = false
  }
}
function applyProposal() {
  if (!canApplyProposal.value || !proposal.value || proposalForm.value?.reportValidity() === false) return
  version.value.cells = proposal.value.cells.map(cell => ({ activity: cell.activity, activity_kind: cell.activity_kind, tax_regime: cell.tax_regime, weight: cell.weight, additional_dimensions: {} }))
  version.value.basis = 'revenue'
}
function validate() { return !props.busy && form.value?.reportValidity() === true }
function save() {
  if (!validate()) return
  emit('save', { keyId: keyId.value, code: code.value, name: name.value, version: JSON.parse(JSON.stringify(version.value)) as JointVersionInput })
}
defineExpose({ copy, validate })
</script>

<template>
  <div class="space-y-4">
  <form ref="form" class="rounded-lg border border-neutral-200 bg-surface p-4 space-y-4" @submit.prevent="save">
    <h2 class="font-semibold">{{ t('jointAllocations.keyEditor') }}</h2>
    <p class="text-sm text-neutral-500">{{ t('jointAllocations.immutableKey') }}</p>
    <div class="flex flex-wrap gap-3">
      <label class="flex flex-col gap-1 text-sm">{{ t('jointAllocations.key') }}
        <select v-model="keyId" class="h-9 rounded border border-neutral-300 bg-surface px-2" :disabled="busy">
          <option :value="0">{{ t('jointAllocations.newKey') }}</option>
          <option v-for="key in keys" :key="key.key_id" :value="key.key_id">{{ key.name }}</option>
        </select>
      </label>
      <template v-if="!keyId">
        <label class="flex flex-col gap-1 text-sm">{{ t('jointAllocations.code') }}<input v-model="code" required maxlength="50" pattern="[A-Za-z0-9_.-]+" class="h-9 rounded border border-neutral-300 px-2 bg-surface" :disabled="busy" /></label>
        <label class="flex flex-col gap-1 text-sm">{{ t('jointAllocations.name') }}<input v-model="name" required maxlength="190" class="h-9 rounded border border-neutral-300 px-2 bg-surface" :disabled="busy" /></label>
      </template>
      <label class="flex flex-col gap-1 text-sm">{{ t('jointAllocations.validFrom') }}<DateInput v-model="version.valid_from" required :disabled="busy" class="h-9 rounded border border-neutral-300 px-2" /></label>
      <label class="flex flex-col gap-1 text-sm">{{ t('jointAllocations.validTo') }}<DateInput v-model="version.valid_to" required :disabled="busy" class="h-9 rounded border border-neutral-300 px-2" /></label>
      <label class="flex flex-col gap-1 text-sm">{{ t('jointAllocations.basis') }}
        <select v-model="version.basis" class="h-9 rounded border border-neutral-300 bg-surface px-2" :disabled="busy">
          <option v-for="basis in ['hours', 'area', 'revenue', 'fixed']" :key="basis" :value="basis">{{ t(`jointAllocations.bases.${basis}`) }}</option>
        </select>
      </label>
      <label class="flex flex-col gap-1 text-sm">{{ t('jointAllocations.mode') }}
        <select v-model="version.allocation_mode" class="h-9 rounded border border-neutral-300 bg-surface px-2" :disabled="busy">
          <option value="weighted">{{ t('jointAllocations.weighted') }}</option><option value="explicit_amounts">{{ t('jointAllocations.explicit') }}</option>
        </select>
      </label>
    </div>
    <label class="block text-sm">{{ t('jointAllocations.explanation') }}<textarea v-model="version.explanation" required maxlength="4000" rows="2" class="mt-1 w-full rounded border border-neutral-300 bg-surface p-2" :disabled="busy" /></label>
    <fieldset class="flex flex-wrap gap-3 text-sm" :disabled="busy">
      <legend class="mb-2 font-medium">{{ t('jointAllocations.additionalAxes') }}</legend>
      <label v-for="type in customTypes" :key="type.id" class="flex items-center gap-2"><input v-model="additional" type="checkbox" :value="type.id" @change="setAxes" />{{ type.name }}</label>
    </fieldset>
    <p class="text-sm text-neutral-500">{{ t('jointAllocations.fullTupleHelp') }}</p>
    <div v-for="(cell, index) in version.cells" :key="index" class="flex flex-wrap items-end gap-3 border-t border-neutral-100 pt-3">
      <label v-for="axis in axisTypes" :key="axis.axis" class="flex flex-col gap-1 text-sm">{{ axis.type?.name ?? t(`jointAllocations.axes.${axis.axis}`) }}
        <select v-model="cell[axis.axis]" required :disabled="busy" class="h-9 rounded border border-neutral-300 bg-surface px-2">
          <option :value="0" disabled>{{ t('jointAllocations.choose') }}</option>
          <option v-for="value in choices(axis.type?.id)" :key="value.id" :value="value.id">{{ dimensionValueName({ t, te }, value, axis.type?.system_key) }}</option>
        </select>
      </label>
      <template v-if="cell.additional_dimensions">
        <label v-for="typeId in additional" :key="typeId" class="flex flex-col gap-1 text-sm">{{ types.find(type => type.id === typeId)?.name }}
          <select v-model="cell.additional_dimensions[typeId]" required :disabled="busy" class="h-9 rounded border border-neutral-300 bg-surface px-2">
            <option :value="0" disabled>{{ t('jointAllocations.choose') }}</option>
            <option v-for="value in choices(typeId)" :key="value.id" :value="value.id">{{ dimensionValueName({ t, te }, value, types.find(type => type.id === typeId)?.system_key) }}</option>
          </select>
        </label>
      </template>
      <label v-if="version.allocation_mode === 'weighted'" class="flex flex-col gap-1 text-sm">{{ t('jointAllocations.weight') }}<input v-model="cell.weight" required inputmode="decimal" pattern="[0-9]+([.][0-9]{1,6})?" :disabled="busy" class="h-9 w-24 rounded border border-neutral-300 bg-surface px-2" /></label>
      <button type="button" :class="btnOutline('danger')" :disabled="busy || version.cells.length === 1" @click="version.cells.splice(index, 1)"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.trash" /></svg>{{ t('jointAllocations.removeCell') }}</button>
    </div>
    <div class="flex flex-wrap gap-2">
      <button type="button" :class="btnOutline('primary')" :disabled="busy || version.cells.length >= 200" @click="addCell"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.plus" /></svg>{{ t('jointAllocations.addCell') }}</button>
      <button type="submit" :class="btnFilled('success')" :disabled="busy"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.check" /></svg>{{ t('jointAllocations.saveVersion') }}</button>
      <button type="button" :class="btnOutline('neutral')" :disabled="busy" @click="emit('close')"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.x" /></svg>{{ t('jointAllocations.close') }}</button>
    </div>
  </form>
  <form ref="proposalForm" class="rounded-lg border border-neutral-200 bg-surface p-4 space-y-3" data-test="revenue-proposal" @submit.prevent="fetchProposal">
    <h2 class="font-semibold">{{ t('jointAllocations.revenueProposal.title') }}</h2>
    <p class="text-sm text-neutral-500">{{ t('jointAllocations.revenueProposal.help') }}</p>
    <div class="flex flex-wrap items-end gap-3">
      <label class="flex flex-col gap-1 text-sm">{{ t('jointAllocations.revenueProposal.from') }}<DateInput v-model="sourceFrom" :disabled="busy" class="h-9 rounded border border-neutral-300 px-2" /></label>
      <label class="flex flex-col gap-1 text-sm">{{ t('jointAllocations.revenueProposal.to') }}<DateInput v-model="sourceTo" :disabled="busy" class="h-9 rounded border border-neutral-300 px-2" /></label>
      <button type="button" data-test="fetch-proposal" :class="btnOutline('neutral')" :disabled="!canFetchProposal" @click="fetchProposal"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.chart" /></svg>{{ t('jointAllocations.revenueProposal.fetch') }}</button>
    </div>
    <p v-if="(sourceFrom || sourceTo) && !rangeValid" role="alert" class="text-sm text-danger-600">{{ t('jointAllocations.revenueProposal.invalidRange') }}</p>
    <p v-if="proposalLoading" role="status" class="text-sm text-neutral-500">{{ t('jointAllocations.loading') }}</p>
    <p v-if="proposalError" role="alert" class="text-sm text-danger-600">{{ t(`jointAllocations.revenueProposal.errors.${proposalError}`) }}</p>
    <div v-if="proposal" class="space-y-3" data-test="proposal-result">
      <dl class="flex flex-wrap gap-x-6 gap-y-1 text-sm">
        <div><dt class="text-neutral-500">{{ t('jointAllocations.revenueProposal.netRevenue') }}</dt><dd>{{ t('jointAllocations.revenueProposal.amountCzk', { amount: formatCents(proposal.net_revenue_cents) }) }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('jointAllocations.revenueProposal.sourceLines') }}</dt><dd>{{ proposal.source_lines }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('jointAllocations.revenueProposal.unclassifiedLines') }}</dt><dd>{{ proposal.unclassified_lines }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('jointAllocations.revenueProposal.unclassifiedAmount') }}</dt><dd>{{ t('jointAllocations.revenueProposal.amountCzk', { amount: formatCents(proposal.unclassified_amount_cents) }) }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('jointAllocations.revenueProposal.cellCount') }}</dt><dd>{{ proposal.cells.length }}</dd></div>
      </dl>
      <ul v-if="reasonKeys.length" class="list-disc pl-5 text-sm text-warning-700" data-test="proposal-reasons"><li v-for="reason in reasonKeys" :key="reason">{{ t(`jointAllocations.revenueProposal.reasons.${reason}`) }}</li></ul>
      <ul v-if="proposal.cells.length" class="text-sm space-y-1" data-test="proposal-cells">
        <li v-for="(cell, index) in proposal.cells" :key="index">{{ valueName(cell.activity) }} · {{ valueName(cell.activity_kind) }} · {{ valueName(cell.tax_regime) }} · {{ t('jointAllocations.weight') }} {{ cell.weight }}</li>
      </ul>
      <p class="text-sm text-neutral-500">{{ t('jointAllocations.revenueProposal.manualReview') }}</p>
      <p v-if="applyBlocked" role="status" class="text-sm text-warning-700">{{ t(`jointAllocations.revenueProposal.${applyBlocked}`) }}</p>
      <button type="button" data-test="apply-proposal" :class="btnFilled('primary')" :disabled="!canApplyProposal" @click="applyProposal"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.check" /></svg>{{ t('jointAllocations.revenueProposal.apply') }}</button>
    </div>
  </form>
  </div>
</template>
