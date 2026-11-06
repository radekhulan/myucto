<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import DateInput from '@/components/ui/DateInput.vue'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import type { SecuritiesAssessment, SecuritiesEvidence, SecuritiesValuation, SecuritiesMonetaryMember } from '@/api/securities504'

const props = defineProps<{ assessment: SecuritiesAssessment; disabled: boolean; dimensionLabel: (dimensions: Record<string, number> | []) => string }>()
const emit = defineEmits<{ change: [value: SecuritiesEvidence | null, valid: boolean] }>()
const { t } = useI18n()
const form = ref<HTMLFormElement | null>(null)
const draft = ref<SecuritiesEvidence | null>(null)
const clone = <T,>(value: T): T => JSON.parse(JSON.stringify(value)) as T
const exceptions = ['none', 'securities_dealer', 'foreign_securities_dealer_branch', 'payment_institution', 'foreign_payment_institution_branch', 'electronic_money_institution', 'foreign_electronic_money_institution_branch', 'investment_company', 'investment_fund', 'pension_fund']
let generation = 0
onBeforeUnmount(() => { generation++ })
const dateFields = new Set(['valuation_date', 'acquisition_date', 'recoverability_date', 'last_trading_date', 'market_calendar_as_of'])
function facts(valuation: SecuritiesValuation): string[] {
  const common = ['reference', 'valuation_date', 'quantity']
  if (valuation.instrument.method === 'cost_equity') return [...common, 'acquisition_date', 'acquisition_cost_czk', 'acquired_quantity', 'unit_cost_foreign', 'recoverability_date', 'recoverability_reference', 'recoverable_unit_value_foreign']
  if (valuation.instrument.method === 'equity_method') return [...common, 'participation_fraction', 'issuer_equity_foreign', 'issuer_statement_reference']
  if (valuation.instrument.method === 'fair_value_market') return [...common, 'market', 'market_type', 'market_reference', 'quote_currency', 'market_calendar_reference', 'market_calendar_as_of', 'last_trading_date', 'unit_price_foreign', ...(valuation.instrument.classification === 'available_for_sale' ? ['acquisition_cost_czk'] : [])]
  return common
}
function begin() {
  if (props.disabled) return
  draft.value = { profile: { scope: '', category: '', micro_exception: '', as_of: props.assessment.as_of, reference: '', scope_reference: '', inception_reference: '', first_period_start: props.assessment.profile_periods[0]?.starts_on ?? '', category_facts: props.assessment.profile_periods.map(period => {
    const saved = props.assessment.profile_history.find(row => row.period_id === period.id)
    return { period_id: period.id, starts_on: period.starts_on, ends_on: period.ends_on, assets_net: saved?.assets_net ?? '', net_turnover: saved?.net_turnover ?? '', average_employees: saved ? String(saved.avg_employees) : '', statement_reference: '', employees_reference: '' }
  }) }, valuations: [], inventory_account_ids: [], non_security_members: [] }
}
function addValuation() {
  if (!draft.value || props.disabled) return
  draft.value.valuations.push({ instrument: { kind: '', identity: '', classification: '', method: '', reference: '', classification_reference: '', designated_on: '' }, as_of: props.assessment.as_of, evidence: {}, members: [], posted_proof: [], target_czk: '' })
}
function chooseCell(valuation: SecuritiesValuation, index: number) {
  const member = valuation.members[index]
  if (!member) return
  const cell = props.assessment.cells.find(row => row.cell_key === member.source_cell_hash)
  member.book_czk = cell?.book_czk ?? ''
  member.book_foreign = cell?.book_foreign ?? ''
}
function addMonetary() {
  if (!draft.value || props.disabled) return
  ;(draft.value.non_security_members ??= []).push({ source_cell_hash: '', book_czk: '', book_foreign: '', nature: 'monetary', contract_reference: '', case_origin: { kind: 'declared_title', origin_line_id: 0, reference: '' } })
}
function chooseMonetaryCell(member: SecuritiesMonetaryMember) {
  const cell = props.assessment.cells.find(row => row.cell_key === member.source_cell_hash)
  member.book_czk = cell?.book_czk ?? ''
  member.book_foreign = cell?.book_foreign ?? ''
  member.case_origin = cell?.case_origins?.length === 1 ? clone(cell.case_origins[0]!) : { kind: 'declared_title', origin_line_id: cell?.line_id ?? 0, reference: '' }
}
async function publish() {
  const token = ++generation
  await nextTick()
  if (token !== generation) return
  const value = draft.value
  const pending = value !== null && JSON.stringify(value.inventory_account_ids ?? []) !== JSON.stringify(props.assessment.evidence?.inventory_account_ids ?? [])
  const valid = value === null ? props.assessment.cells.length === 0 : !!form.value?.checkValidity()
    && (pending || (props.assessment.cells.length === 0 && value.valuations.length === 0 && (value.non_security_members?.length ?? 0) === 0) || (value.valuations.length > 0 || (value.non_security_members?.length ?? 0) > 0)
      && value.valuations.every(row => row.members.length > 0 && row.members.every(member => !!member.source_cell_hash)
        && row.posted_proof.every(proof => proof.entry_id > 0 && proof.asset_line_id > 0 && proof.counterpart_line_id > 0))
      && (value.non_security_members ?? []).every(member => !!member.source_cell_hash && !!member.contract_reference))
  emit('change', value === null ? null : clone(value), valid)
}
watch(() => props.assessment, value => { generation++; draft.value = value.evidence === null ? null : clone(value.evidence) }, { immediate: true })
watch(draft, () => { void publish() }, { deep: true })
</script>

<template>
  <section v-if="assessment.cells.length || assessment.evidence || assessment.available_accounts.length" class="space-y-3 rounded-lg border border-neutral-200 p-4" data-testid="securities504">
    <h3 class="font-semibold">{{ t('securities504.title') }}</h3>
    <p class="text-sm text-neutral-600">{{ t('securities504.description') }}</p>
    <p class="text-sm text-neutral-600">{{ t('securities504.legal_window', { from: assessment.legal_basis.period_start_from, endFrom: assessment.legal_basis.period_end_from, to: assessment.legal_basis.period_end_to }) }}</p>
    <ul v-if="assessment.findings.length" class="list-disc pl-5 text-sm text-warning-700"><li v-for="(finding, index) in assessment.findings" :key="index">{{ t(`securities504.findings.${finding.code}`) }}</li></ul>
    <div class="space-y-2">
      <article v-for="cell in assessment.cells" :key="cell.cell_key" class="rounded border border-neutral-200 p-2 text-sm">
        <strong>{{ cell.account_code }} · {{ cell.currency_code }}</strong> · {{ cell.entry_date }} · {{ t('securities504.line') }} {{ cell.line_id }}
        <p>{{ cell.book_czk }} CZK · {{ cell.book_foreign }} {{ cell.currency_code }}</p>
        <p>{{ dimensionLabel(cell.dimensions) }}<span v-if="cell.cost_center"> · {{ cell.cost_center }}</span><span v-if="cell.project_id"> · {{ t('securities504.project') }} {{ cell.project_id }}</span></p>
      </article>
    </div>
    <button v-if="!draft" type="button" :disabled="disabled" :class="[btnOutline('primary'), 'whitespace-nowrap']" @click="begin"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.plus" /></svg>{{ t('securities504.begin') }}</button>
    <form v-if="draft" ref="form" class="space-y-5" @submit.prevent @input.capture="publish" @change.capture="publish">
      <fieldset :disabled="disabled" class="space-y-2">
        <legend class="font-medium">{{ t('securities504.inventory_accounts') }}</legend>
        <p class="text-sm">{{ t('securities504.inventory_accounts_help') }}</p>
        <select v-model="draft.inventory_account_ids" multiple class="w-full rounded border p-2" data-testid="inventory-accounts"><option v-for="account in assessment.available_accounts" :key="account.id" :value="account.id">{{ account.account_code }}</option></select>
      </fieldset>
      <fieldset v-if="draft.valuations.length" :disabled="disabled" class="space-y-3">
        <legend class="font-medium">{{ t('securities504.profile') }}</legend>
        <div class="flex flex-wrap items-end gap-3">
          <label class="text-sm">{{ t('securities504.scope') }}<select v-model="draft.profile.scope" required class="block h-9 rounded border px-2"><option value="">{{ t('securities504.choose') }}</option><option v-for="value in ['full', 'simplified']" :key="value" :value="value">{{ t(`securities504.options.${value}`) }}</option></select></label>
          <label class="text-sm">{{ t('securities504.category') }}<select v-model="draft.profile.category" required class="block h-9 rounded border px-2"><option value="">{{ t('securities504.choose') }}</option><option v-for="value in ['micro', 'small', 'medium', 'large']" :key="value" :value="value">{{ t(`securities504.options.${value}`) }}</option></select></label>
          <label class="text-sm">{{ t('securities504.micro_exception') }}<select v-model="draft.profile.micro_exception" required class="block h-9 max-w-full rounded border px-2"><option value="">{{ t('securities504.choose') }}</option><option v-for="value in exceptions" :key="value" :value="value">{{ t(`securities504.options.${value}`) }}</option></select></label>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <label v-for="field in (['reference', 'scope_reference', 'inception_reference'] as const)" :key="field" class="text-sm">{{ t(`securities504.${field}`) }}<input v-model="draft.profile[field]" required maxlength="4000" class="block h-9 w-full rounded border px-2" /></label>
          <label class="text-sm">{{ t('securities504.first_period_start') }}<DateInput v-model="draft.profile.first_period_start" required :disabled="disabled" :max="assessment.as_of" class="h-9 w-full rounded border px-2" /></label>
        </div>
        <div v-if="draft.profile.micro_exception && draft.profile.micro_exception !== 'none'" class="flex flex-wrap items-end gap-3">
          <label class="grow text-sm">{{ t('securities504.registration_reference') }}<input v-model="draft.profile.registration_reference" required maxlength="4000" class="block h-9 w-full rounded border px-2" /></label>
          <label class="text-sm">{{ t('securities504.registration_from') }}<DateInput v-model="draft.profile.registration_from" required :disabled="disabled" :max="assessment.as_of" class="h-9 rounded border px-2" /></label>
        </div>
        <article v-for="fact in draft.profile.category_facts" :key="fact.period_id" class="space-y-2 rounded border p-3">
          <h4 class="text-sm font-medium">{{ fact.starts_on }} - {{ fact.ends_on }}</h4>
          <div class="grid gap-3 sm:grid-cols-2">
            <label v-for="field in (['assets_net', 'net_turnover', 'average_employees', 'statement_reference', 'employees_reference'] as const)" :key="field" class="text-sm">{{ t(`securities504.${field}`) }}<input v-model="fact[field]" required class="block h-9 w-full rounded border px-2" /></label>
          </div>
        </article>
      </fieldset>
      <fieldset :disabled="disabled" class="space-y-3">
        <legend class="font-medium">{{ t('securities504.monetary_members') }}</legend>
        <p class="text-sm">{{ t('securities504.monetary_help') }}</p>
        <article v-for="(member, index) in draft.non_security_members ?? []" :key="index" class="space-y-2 rounded border p-3">
          <div class="flex flex-wrap items-end gap-3">
            <label class="min-w-0 grow text-sm">{{ t('securities504.source') }}<select v-model="member.source_cell_hash" required class="block h-9 w-full rounded border px-2" @change="chooseMonetaryCell(member)"><option value="">{{ t('securities504.choose') }}</option><option v-for="cell in assessment.cells" :key="cell.cell_key" :value="cell.cell_key">{{ cell.account_code }} · {{ cell.line_id }} · {{ cell.book_czk }} CZK · {{ dimensionLabel(cell.dimensions) }}</option></select></label>
            <label v-for="field in (['book_czk', 'book_foreign'] as const)" :key="field" class="text-sm">{{ t(`securities504.${field}`) }}<input v-model="member[field]" required pattern="-?[0-9]+\.[0-9]{2}" inputmode="decimal" class="block h-9 w-36 rounded border px-2" /></label>
          </div>
          <label class="block text-sm">{{ t('securities504.contract_reference') }}<input v-model="member.contract_reference" required maxlength="4000" class="block h-9 w-full rounded border px-2" /></label>
          <label v-if="member.case_origin.kind === 'declared_title'" class="block text-sm">{{ t('securities504.title_reference') }}<input v-model="member.case_origin.reference" required maxlength="4000" class="block h-9 w-full rounded border px-2" /></label>
          <p v-else class="text-sm">{{ t('securities504.native_origin') }}: {{ member.case_origin.kind === 'document' ? `${member.case_origin.doc_type} #${member.case_origin.doc_id}` : `${member.case_origin.account_id} ${member.case_origin.currency_code}` }}</p>
          <button type="button" :class="[btnOutline('danger'), 'whitespace-nowrap']" @click="draft.non_security_members?.splice(index, 1)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.trash" /></svg>{{ t('securities504.remove') }}</button>
        </article>
        <button type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" @click="addMonetary"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.plus" /></svg>{{ t('securities504.add_monetary') }}</button>
      </fieldset>
      <article v-for="(valuation, index) in draft.valuations" :key="index" class="space-y-3 rounded border p-3">
        <fieldset :disabled="disabled" class="space-y-3">
          <legend class="font-medium">{{ t('securities504.holding') }} {{ index + 1 }}</legend>
          <div class="flex flex-wrap items-end gap-3">
            <label v-for="field in (['kind', 'classification', 'method'] as const)" :key="field" class="text-sm">{{ t(`securities504.${field}`) }}<select v-model="valuation.instrument[field]" required class="block h-9 rounded border px-2"><option value="">{{ t('securities504.choose') }}</option><option v-for="value in field === 'kind' ? ['equity', 'debt'] : field === 'classification' ? ['trading', 'available_for_sale', 'held_to_maturity', 'participation'] : ['cost_equity', 'fair_value_market', 'equity_method']" :key="value" :value="value">{{ t(`securities504.options.${value}`) }}</option></select></label>
          </div>
          <div class="grid gap-3 sm:grid-cols-2">
            <label v-for="field in (['identity', 'reference', 'classification_reference'] as const)" :key="field" class="text-sm">{{ t(`securities504.instrument_${field}`) }}<input v-model="valuation.instrument[field]" required maxlength="4000" class="block h-9 w-full rounded border px-2" /></label>
            <label class="text-sm">{{ t('securities504.designated_on') }}<DateInput v-model="valuation.instrument.designated_on" required :disabled="disabled" :max="assessment.as_of" class="h-9 w-full rounded border px-2" /></label>
          </div>
          <div v-if="valuation.instrument.classification === 'participation'" class="flex flex-wrap gap-3">
            <label class="text-sm">{{ t('securities504.influence') }}<select v-model="valuation.instrument.influence" required class="block h-9 rounded border px-2"><option value="">{{ t('securities504.choose') }}</option><option v-for="value in ['control', 'significant']" :key="value" :value="value">{{ t(`securities504.options.${value}`) }}</option></select></label>
            <label class="grow text-sm">{{ t('securities504.influence_reference') }}<input v-model="valuation.instrument.influence_reference" required class="block h-9 w-full rounded border px-2" /></label>
          </div>
          <p v-if="valuation.instrument.kind === 'debt'" class="text-sm text-warning-700">{{ t('securities504.debt_workflow_pending') }}</p>
          <label v-if="valuation.instrument.kind === 'debt'" class="block text-sm">{{ t('securities504.debt_type') }}<select v-model="valuation.instrument.debt_type" required class="block h-9 rounded border px-2"><option value="">{{ t('securities504.choose') }}</option><option value="coupon">{{ t('securities504.options.coupon') }}</option><option value="discount">{{ t('securities504.options.discount') }}</option></select></label>
          <div class="grid gap-3 sm:grid-cols-2">
            <label v-for="field in facts(valuation)" :key="field" class="text-sm">{{ t(`securities504.facts.${field}`) }}<DateInput v-if="dateFields.has(field)" v-model="valuation.evidence[field]" required :disabled="disabled" :max="assessment.as_of" class="h-9 w-full rounded border px-2" /><select v-else-if="field === 'market_type'" v-model="valuation.evidence[field]" required class="block h-9 w-full rounded border px-2"><option value="">{{ t('securities504.choose') }}</option><option value="regulated">{{ t('securities504.regulated_market') }}</option></select><input v-else v-model="valuation.evidence[field]" required maxlength="4000" class="block h-9 w-full rounded border px-2" /></label>
            <label class="text-sm">{{ t('securities504.target_czk') }}<input v-model="valuation.target_czk" required pattern="-?[0-9]+\.[0-9]{2}" inputmode="decimal" class="block h-9 w-full rounded border px-2" /></label>
          </div>
          <h4 class="text-sm font-medium">{{ t('securities504.members') }}</h4>
          <div v-for="(member, memberIndex) in valuation.members" :key="memberIndex" class="flex flex-wrap items-end gap-2">
            <label class="min-w-0 grow text-sm">{{ t('securities504.source') }}<select v-model="member.source_cell_hash" required class="block h-9 w-full rounded border px-2" @change="chooseCell(valuation, memberIndex)"><option value="">{{ t('securities504.choose') }}</option><option v-for="cell in assessment.cells" :key="cell.cell_key" :value="cell.cell_key">{{ cell.account_code }} · {{ cell.entry_date }} · {{ cell.line_id }} · {{ cell.book_czk }} CZK · {{ dimensionLabel(cell.dimensions) }}</option></select></label>
            <label v-for="field in (['book_czk', 'book_foreign'] as const)" :key="field" class="text-sm">{{ t(`securities504.${field}`) }}<input v-model="member[field]" required pattern="-?[0-9]+\.[0-9]{2}" inputmode="decimal" class="block h-9 w-36 rounded border px-2" /></label>
            <button type="button" :class="[btnOutline('danger'), 'whitespace-nowrap']" @click="valuation.members.splice(memberIndex, 1)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.trash" /></svg>{{ t('securities504.remove') }}</button>
          </div>
          <button type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" @click="valuation.members.push({ source_cell_hash: '', book_czk: '', book_foreign: '' })"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.plus" /></svg>{{ t('securities504.add_member') }}</button>
          <h4 class="text-sm font-medium">{{ t('securities504.posted_proof') }}</h4>
          <div v-for="(proof, proofIndex) in valuation.posted_proof" :key="proofIndex" class="flex flex-wrap items-end gap-2">
            <label class="text-sm">{{ t('securities504.entry') }}<select v-model="proof.entry_id" required class="block h-9 rounded border px-2" @change="proof.asset_line_id = 0; proof.counterpart_line_id = 0"><option :value="0">{{ t('securities504.choose') }}</option><option v-for="entry in assessment.posted_entries" :key="entry.id" :value="entry.id">{{ entry.document_no ?? entry.id }} · {{ entry.entry_date }}</option></select></label>
            <label v-for="field in (['asset_line_id', 'counterpart_line_id'] as const)" :key="field" class="text-sm">{{ t(`securities504.${field}`) }}<select v-model="proof[field]" required class="block h-9 rounded border px-2"><option :value="0">{{ t('securities504.choose') }}</option><option v-for="line in assessment.posted_entries.find(entry => entry.id === proof.entry_id)?.lines ?? []" :key="line.id" :value="line.id">{{ line.account_code }} · {{ line.signed_czk }} CZK · {{ dimensionLabel(line.dimensions) }}</option></select></label>
            <label class="text-sm">{{ t('securities504.component') }}<select v-model="proof.component" required class="block h-9 rounded border px-2"><option value="">{{ t('securities504.choose') }}</option><option v-for="value in ['fx', 'equity', 'price_fx', 'interest']" :key="value" :value="value">{{ t(`securities504.options.${value}`) }}</option></select></label>
            <label class="text-sm">{{ t('securities504.amount_czk') }}<input v-model="proof.amount_czk" required pattern="-?[0-9]+\.[0-9]{2}" inputmode="decimal" class="block h-9 w-36 rounded border px-2" /></label>
            <button type="button" :class="[btnOutline('danger'), 'whitespace-nowrap']" @click="valuation.posted_proof.splice(proofIndex, 1)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.trash" /></svg>{{ t('securities504.remove') }}</button>
          </div>
          <div class="flex flex-wrap gap-2">
            <button type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" @click="valuation.posted_proof.push({ entry_id: 0, asset_line_id: 0, counterpart_line_id: 0, component: '', amount_czk: '' })"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.plus" /></svg>{{ t('securities504.add_posting') }}</button>
            <button type="button" :class="[btnOutline('danger'), 'whitespace-nowrap']" @click="draft.valuations.splice(index, 1)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.trash" /></svg>{{ t('securities504.remove_holding') }}</button>
          </div>
        </fieldset>
      </article>
      <button type="button" :disabled="disabled" :class="[btnOutline('primary'), 'whitespace-nowrap']" @click="addValuation"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.plus" /></svg>{{ t('securities504.add_holding') }}</button>
    </form>
    <div v-for="(valuation, index) in assessment.valuations" :key="index" class="rounded bg-neutral-50 p-3 text-sm">
      <strong>{{ valuation.identity }}</strong>
      <p>{{ t('securities504.book_czk') }}: {{ valuation.book_czk }} CZK · {{ t('securities504.target_czk') }}: {{ valuation.target_czk }} CZK · {{ t('securities504.change_czk') }}: {{ valuation.change_czk }} CZK</p>
    </div>
  </section>
</template>
