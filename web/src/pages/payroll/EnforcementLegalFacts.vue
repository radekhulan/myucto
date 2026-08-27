<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { documentsApi, type DocItem } from '@/api/documents'
import type { PayrollInstitutionAccount } from '@/api/payroll'
import {
  payrollEnforcementApi,
  type EnforcementCaseParty,
  type EnforcementCaseStatus,
  type EnforcementClaim,
  type EnforcementClaimBreakdown,
  type EnforcementRecipientInstruction,
} from '@/api/payrollEnforcement'
import { btnFilled, btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { formatMoneyMinor as money } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'

const props = defineProps<{
  caseId: number
  caseStatus: EnforcementCaseStatus
  claims: EnforcementClaim[]
  canWrite: boolean
  canReadDocuments: boolean
  recipientAccounts: PayrollInstitutionAccount[]
}>()

const { t } = useI18n()
const toast = useToast()
const loading = ref(true)
const loadFailed = ref(false)
const saving = ref(false)
const parties = ref<EnforcementCaseParty[]>([])
const breakdowns = ref<Record<number, EnforcementClaimBreakdown[]>>({})
const recipientInstructions = ref<EnforcementRecipientInstruction[]>([])
const showPartyForm = ref(false)
const showRecipientInstructionForm = ref(false)
const breakdownClaimId = ref<number | null>(null)
const documentQuery = ref('')
const documentCandidates = ref<DocItem[]>([])
const selectedDocument = ref<DocItem | null>(null)
let searchTimer: ReturnType<typeof setTimeout> | null = null
let searchSequence = 0

const partyRoles = ['court', 'executor', 'beneficiary'] as const
const party = ref({
  party_role: 'executor' as EnforcementCaseParty['party_role'],
  effective_from: new Date().toISOString().slice(0, 10),
  party_name: '',
  party_reference: '',
})
const recipientInstruction = ref({
  effective_from: new Date().toISOString().slice(0, 10),
  recipient_party_id: null as number | null,
  payment_account_id: null as number | null,
  change_reason: '',
})
const breakdown = ref({
  principal: '',
  interest: '0',
  costs: '0',
  maintenance: '0',
  change_reason: '',
})

const currentDate = new Date().toISOString().slice(0, 10)

const latestParties = computed(() => partyRoles.map((role) => ({
  role,
  value: [...parties.value]
    .filter(item => item.party_role === role && item.effective_from <= currentDate)
    .sort((left, right) => right.revision_no - left.revision_no)[0] ?? null,
})))

const recipientPartyOptions = computed(() => (['executor', 'beneficiary'] as const)
  .map((role) => [...parties.value]
    .filter(item => item.party_role === role
      && item.effective_from <= recipientInstruction.value.effective_from)
    .sort((left, right) => right.revision_no - left.revision_no)[0] ?? null)
  .filter((party): party is EnforcementCaseParty => party !== null))

const effectiveRecipientAccounts = computed(() => props.recipientAccounts.filter((account) =>
  account.institution_type === 'other_recipient'
    && account.currency_code === 'CZK'
    && account.verified_on <= recipientInstruction.value.effective_from
    && account.valid_from <= recipientInstruction.value.effective_from
    && (account.valid_to === null || account.valid_to >= recipientInstruction.value.effective_from),
))

function recipientAccountLabel(account: PayrollInstitutionAccount): string {
  const symbols = [
    account.variable_symbol ? `VS ${account.variable_symbol}` : null,
    account.specific_symbol ? `SS ${account.specific_symbol}` : null,
    account.constant_symbol ? `KS ${account.constant_symbol}` : null,
  ].filter((value): value is string => value !== null)
  return [account.institution_name, account.bank_account_masked, ...symbols].join(' · ')
}

const selectedClaim = computed(() => props.claims.find(
  claim => claim.id === breakdownClaimId.value,
) ?? null)

function parseMinor(value: string): number | null {
  const normalized = value.trim().replace(/\s/g, '').replace(',', '.')
  if (!/^\d+(?:\.\d{1,2})?$/.test(normalized)) return null
  const amount = Math.round(Number(normalized) * 100)
  return Number.isSafeInteger(amount) && amount >= 0 ? amount : null
}

const breakdownValues = computed(() => ({
  principal_minor_units: parseMinor(breakdown.value.principal),
  interest_minor_units: parseMinor(breakdown.value.interest),
  costs_minor_units: parseMinor(breakdown.value.costs),
  maintenance_minor_units: parseMinor(breakdown.value.maintenance),
}))

const breakdownTotal = computed(() => Object.values(breakdownValues.value)
  .reduce<number | null>((sum, value) => sum === null || value === null ? null : sum + value, 0))

const breakdownMatches = computed(() => selectedClaim.value !== null
  && breakdownTotal.value === selectedClaim.value.outstanding_minor_units)

const canSubmitParty = computed(() => party.value.party_name.trim() !== ''
  && party.value.effective_from !== ''
  && selectedDocument.value !== null)

const canSubmitBreakdown = computed(() => breakdownMatches.value
  && selectedDocument.value !== null)

const canSubmitRecipientInstruction = computed(() =>
  recipientInstruction.value.effective_from !== ''
  && recipientInstruction.value.recipient_party_id !== null
  && recipientInstruction.value.payment_account_id !== null
  && selectedDocument.value !== null,
)

watch(documentQuery, (query) => {
  if (searchTimer) clearTimeout(searchTimer)
  if (query.trim().length < 2 || selectedDocument.value) {
    documentCandidates.value = []
    return
  }
  searchTimer = setTimeout(async () => {
    const sequence = ++searchSequence
    try {
      const results = await documentsApi.search(query.trim())
      if (sequence === searchSequence && query === documentQuery.value) {
        documentCandidates.value = results
      }
    } catch {
      if (sequence === searchSequence) documentCandidates.value = []
    }
  }, 250)
})

onUnmounted(() => {
  if (searchTimer) clearTimeout(searchTimer)
})

async function load() {
  loading.value = true
  loadFailed.value = false
  try {
    const [loadedParties, loadedBreakdowns, loadedInstructions] = await Promise.all([
      payrollEnforcementApi.parties(props.caseId),
      Promise.all(props.claims.map(async claim => [
        claim.id,
        await payrollEnforcementApi.claimBreakdowns(props.caseId, claim.id),
      ] as const)),
      payrollEnforcementApi.recipientInstructions(props.caseId),
    ])
    parties.value = loadedParties
    breakdowns.value = Object.fromEntries(loadedBreakdowns)
    recipientInstructions.value = loadedInstructions
  } catch {
    loadFailed.value = true
  } finally {
    loading.value = false
  }
}

function resetDocument() {
  ++searchSequence
  documentQuery.value = ''
  documentCandidates.value = []
  selectedDocument.value = null
}

function selectDocument(document: DocItem) {
  selectedDocument.value = document
  documentQuery.value = document.title
  documentCandidates.value = []
}

function closeForms() {
  showPartyForm.value = false
  showRecipientInstructionForm.value = false
  breakdownClaimId.value = null
  resetDocument()
}

function openPartyForm(role: EnforcementCaseParty['party_role'] = 'executor') {
  showRecipientInstructionForm.value = false
  breakdownClaimId.value = null
  showPartyForm.value = true
  party.value = {
    party_role: role,
    effective_from: new Date().toISOString().slice(0, 10),
    party_name: '',
    party_reference: '',
  }
  resetDocument()
}

function openBreakdownForm(claim: EnforcementClaim) {
  showPartyForm.value = false
  showRecipientInstructionForm.value = false
  breakdownClaimId.value = claim.id
  breakdown.value = {
    principal: String(claim.outstanding_minor_units / 100),
    interest: '0',
    costs: '0',
    maintenance: '0',
    change_reason: '',
  }
  resetDocument()
}

function openRecipientInstructionForm() {
  showPartyForm.value = false
  breakdownClaimId.value = null
  const defaultParty = recipientPartyOptions.value[0] ?? null
  recipientInstruction.value = {
    effective_from: new Date().toISOString().slice(0, 10),
    recipient_party_id: defaultParty?.id ?? null,
    payment_account_id: null,
    change_reason: '',
  }
  showRecipientInstructionForm.value = true
  resetDocument()
}

async function appendParty() {
  if (!canSubmitParty.value || !selectedDocument.value) return
  saving.value = true
  try {
    await payrollEnforcementApi.appendParty(props.caseId, {
      party_role: party.value.party_role,
      effective_from: party.value.effective_from,
      party_name: party.value.party_name.trim(),
      party_reference: party.value.party_reference.trim() || null,
      source_document_id: selectedDocument.value.id,
    })
    parties.value = await payrollEnforcementApi.parties(props.caseId)
    closeForms()
    toast.success(t('payroll.enforcement.legal_facts.party_saved'))
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll.enforcement.legal_facts.save_failed'))
  } finally {
    saving.value = false
  }
}

async function appendBreakdown() {
  const claim = selectedClaim.value
  const document = selectedDocument.value
  const values = breakdownValues.value
  if (!claim || !document || !canSubmitBreakdown.value
    || Object.values(values).some(value => value === null)) return
  saving.value = true
  try {
    await payrollEnforcementApi.appendClaimBreakdown(props.caseId, claim.id, {
      principal_minor_units: values.principal_minor_units!,
      interest_minor_units: values.interest_minor_units!,
      costs_minor_units: values.costs_minor_units!,
      maintenance_minor_units: values.maintenance_minor_units!,
      source_document_id: document.id,
      change_reason: breakdown.value.change_reason.trim() || null,
    })
    breakdowns.value[claim.id] = await payrollEnforcementApi.claimBreakdowns(
      props.caseId,
      claim.id,
    )
    closeForms()
    toast.success(t('payroll.enforcement.legal_facts.breakdown_saved'))
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll.enforcement.legal_facts.save_failed'))
  } finally {
    saving.value = false
  }
}

async function appendRecipientInstruction() {
  const document = selectedDocument.value
  const value = recipientInstruction.value
  if (!document || !canSubmitRecipientInstruction.value
    || value.recipient_party_id === null || value.payment_account_id === null) return
  saving.value = true
  try {
    await payrollEnforcementApi.appendRecipientInstruction(props.caseId, {
      effective_from: value.effective_from,
      recipient_party_id: value.recipient_party_id,
      payment_account_id: value.payment_account_id,
      source_document_id: document.id,
      change_reason: value.change_reason.trim() || null,
    })
    recipientInstructions.value = await payrollEnforcementApi.recipientInstructions(props.caseId)
    closeForms()
    toast.success(t('payroll.enforcement.legal_facts.recipient_instruction_saved'))
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll.enforcement.legal_facts.save_failed'))
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <section data-test="enforcement-legal-facts" class="rounded-lg border border-neutral-200 bg-surface p-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="max-w-3xl">
        <h3 class="font-medium text-neutral-900">{{ t('payroll.enforcement.legal_facts.title') }}</h3>
        <p class="mt-1 text-xs text-neutral-500">{{ t('payroll.enforcement.legal_facts.hint') }}</p>
      </div>
      <button
        v-if="canWrite && canReadDocuments && !showPartyForm"
        type="button"
        :class="btnOutline('primary')"
        data-test="add-enforcement-party"
        @click="openPartyForm()"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
        {{ t('payroll.enforcement.legal_facts.add_party') }}
      </button>
    </div>

    <div v-if="loading" class="mt-4 h-20 animate-pulse rounded-md bg-neutral-100" />
    <div v-else-if="loadFailed" class="mt-4 rounded-md border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700">
      <p>{{ t('payroll.enforcement.legal_facts.load_failed') }}</p>
      <button type="button" :class="[btnOutlineSm('danger'), 'mt-2']" @click="load">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
        {{ t('payroll.enforcement.legal_facts.retry') }}
      </button>
    </div>
    <template v-else>
      <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
        <article v-for="item in latestParties" :key="item.role" class="rounded-md border border-neutral-200 bg-neutral-50 p-3">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <h4 class="text-sm font-medium text-neutral-900">{{ t(`payroll.enforcement.legal_facts.roles.${item.role}`) }}</h4>
            <button v-if="canWrite && canReadDocuments" type="button" :class="btnOutlineSm('neutral')" @click="openPartyForm(item.role)">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="item.value ? ICONS.edit : ICONS.plus" /></svg>
              {{ t(item.value ? 'payroll.enforcement.legal_facts.new_revision' : 'common.add') }}
            </button>
          </div>
          <template v-if="item.value">
            <p class="mt-2 text-sm font-medium text-neutral-800">{{ item.value.party_name }}</p>
            <p v-if="item.value.party_reference" class="mt-1 break-words text-xs text-neutral-600">{{ item.value.party_reference }}</p>
            <p class="mt-2 text-xs text-neutral-500">{{ item.value.effective_from }} · {{ t('payroll.enforcement.legal_facts.revision', { number: item.value.revision_no }) }}</p>
            <RouterLink :to="{ name: 'document-detail', params: { id: item.value.source_document_id } }" class="mt-2 inline-flex items-center gap-1 text-xs text-primary-600 hover:text-primary-700">
              <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.doc" /></svg>
              {{ t('payroll.enforcement.legal_facts.source_document') }}
            </RouterLink>
          </template>
          <p v-else class="mt-2 text-sm text-neutral-500">{{ t('payroll.enforcement.legal_facts.party_missing') }}</p>
        </article>
      </div>

      <form v-if="showPartyForm" data-test="enforcement-party-form" class="mt-4 rounded-md border border-primary-200 bg-primary-50 p-4" @submit.prevent="appendParty">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <label class="text-xs font-medium text-neutral-600">{{ t('payroll.enforcement.legal_facts.role') }}<select v-model="party.party_role" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"><option v-for="role in partyRoles" :key="role" :value="role">{{ t(`payroll.enforcement.legal_facts.roles.${role}`) }}</option></select></label>
          <label class="text-xs font-medium text-neutral-600">{{ t('payroll.enforcement.legal_facts.effective_from') }}<input v-model="party.effective_from" required type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
          <label class="text-xs font-medium text-neutral-600 sm:col-span-2">{{ t('payroll.enforcement.legal_facts.party_name') }}<input v-model="party.party_name" required maxlength="255" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
          <label class="text-xs font-medium text-neutral-600 sm:col-span-2">{{ t('payroll.enforcement.legal_facts.party_reference') }}<input v-model="party.party_reference" maxlength="128" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
          <label class="relative text-xs font-medium text-neutral-600 sm:col-span-2">{{ t('payroll.enforcement.legal_facts.source_document') }}<input v-model="documentQuery" :readonly="selectedDocument !== null" required type="search" autocomplete="off" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 pr-9 text-sm" :placeholder="t('payroll.enforcement.legal_facts.document_search')"><button v-if="selectedDocument" type="button" class="absolute right-2 top-7 rounded p-1 text-neutral-400 hover:text-danger-600" :title="t('common.remove')" @click="resetDocument"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg></button><ul v-if="documentCandidates.length" class="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-md border border-neutral-200 bg-surface shadow-lg"><li v-for="document in documentCandidates" :key="document.id"><button type="button" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-payroll-50" @click="selectDocument(document)"><span class="truncate">{{ document.title }}</span><span class="shrink-0 text-xs uppercase text-neutral-400">{{ document.doc_type }}</span></button></li></ul></label>
        </div>
        <div class="mt-4 flex flex-wrap justify-end gap-2"><button type="button" :class="btnOutline('neutral')" @click="closeForms"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>{{ t('common.cancel') }}</button><button type="submit" :class="btnFilled('primary')" :disabled="saving || !canSubmitParty"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ t('common.save') }}</button></div>
      </form>

      <div class="mt-5 border-t border-neutral-200 pt-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h4 class="text-sm font-medium text-neutral-900">{{ t('payroll.enforcement.legal_facts.recipient_instructions_title') }}</h4>
            <p class="mt-1 max-w-3xl text-xs text-neutral-500">{{ t('payroll.enforcement.legal_facts.recipient_instructions_hint') }}</p>
          </div>
          <button
            v-if="canWrite && canReadDocuments && !showRecipientInstructionForm"
            type="button"
            :class="btnOutline('primary')"
            data-test="add-recipient-instruction"
            :disabled="recipientPartyOptions.length === 0 || recipientAccounts.length === 0"
            @click="openRecipientInstructionForm"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
            {{ t('payroll.enforcement.legal_facts.add_recipient_instruction') }}
          </button>
        </div>
        <p v-if="recipientPartyOptions.length === 0" class="mt-3 rounded-md border border-warning-200 bg-warning-50 p-3 text-xs text-warning-700">{{ t('payroll.enforcement.legal_facts.recipient_party_missing') }}</p>
        <p v-else-if="recipientAccounts.length === 0" class="mt-3 rounded-md border border-warning-200 bg-warning-50 p-3 text-xs text-warning-700">{{ t('payroll.enforcement.legal_facts.recipient_account_missing') }}</p>
        <ol v-if="recipientInstructions.length" class="mt-3 space-y-2" data-test="recipient-instruction-history">
          <li v-for="instruction in recipientInstructions" :key="instruction.id" class="rounded-md border border-neutral-200 p-3 text-sm">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div>
                <p class="font-medium text-neutral-900">{{ instruction.party_name }} · {{ t(`payroll.enforcement.legal_facts.roles.${instruction.party_role}`) }}</p>
                <p class="mt-1 text-xs text-neutral-500">{{ instruction.effective_from }} · {{ t('payroll.enforcement.legal_facts.revision', { number: instruction.revision_no }) }}</p>
              </div>
              <RouterLink :to="{ name: 'document-detail', params: { id: instruction.source_document_id } }" class="inline-flex items-center gap-1 text-xs text-primary-600 hover:text-primary-700">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.doc" /></svg>
                {{ t('payroll.enforcement.legal_facts.source_document') }}
              </RouterLink>
            </div>
            <p v-if="instruction.change_reason" class="mt-2 text-xs text-neutral-600">{{ instruction.change_reason }}</p>
          </li>
        </ol>
        <p v-else class="mt-3 text-xs text-neutral-500">{{ t('payroll.enforcement.legal_facts.recipient_instruction_missing') }}</p>
      </div>

      <form v-if="showRecipientInstructionForm" data-test="recipient-instruction-form" class="mt-4 rounded-md border border-primary-200 bg-primary-50 p-4" @submit.prevent="appendRecipientInstruction">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <label class="text-xs font-medium text-neutral-600">{{ t('payroll.enforcement.legal_facts.effective_from') }}<input v-model="recipientInstruction.effective_from" required type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
          <label class="text-xs font-medium text-neutral-600">{{ t('payroll.enforcement.legal_facts.recipient_party') }}<select v-model="recipientInstruction.recipient_party_id" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" data-test="recipient-instruction-party"><option :value="null" disabled>{{ t('payroll.enforcement.legal_facts.recipient_party_placeholder') }}</option><option v-for="partyOption in recipientPartyOptions" :key="partyOption.id" :value="partyOption.id">{{ partyOption.party_name }} · {{ t(`payroll.enforcement.legal_facts.roles.${partyOption.party_role}`) }}</option></select></label>
          <label class="text-xs font-medium text-neutral-600 sm:col-span-2">{{ t('payroll.enforcement.legal_facts.recipient_account') }}<select v-model="recipientInstruction.payment_account_id" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" data-test="recipient-instruction-account"><option :value="null" disabled>{{ t('payroll.enforcement.legal_facts.recipient_account_placeholder') }}</option><option v-for="account in effectiveRecipientAccounts" :key="account.id" :value="account.id">{{ recipientAccountLabel(account) }}</option></select><span v-if="effectiveRecipientAccounts.length === 0" class="mt-1 block text-xs font-normal text-warning-700">{{ t('payroll.enforcement.legal_facts.recipient_account_not_effective') }}</span></label>
          <label class="relative text-xs font-medium text-neutral-600 sm:col-span-2">{{ t('payroll.enforcement.legal_facts.source_document') }}<input v-model="documentQuery" :readonly="selectedDocument !== null" required type="search" autocomplete="off" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 pr-9 text-sm" :placeholder="t('payroll.enforcement.legal_facts.document_search')" data-test="recipient-instruction-document"><button v-if="selectedDocument" type="button" class="absolute right-2 top-7 rounded p-1 text-neutral-400 hover:text-danger-600" :title="t('common.remove')" @click="resetDocument"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg></button><ul v-if="documentCandidates.length" class="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-md border border-neutral-200 bg-surface shadow-lg"><li v-for="document in documentCandidates" :key="document.id"><button type="button" :data-test="`recipient-instruction-document-${document.id}`" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-payroll-50" @click="selectDocument(document)"><span class="truncate">{{ document.title }}</span><span class="shrink-0 text-xs uppercase text-neutral-400">{{ document.doc_type }}</span></button></li></ul></label>
          <label class="text-xs font-medium text-neutral-600 sm:col-span-2">{{ t('payroll.enforcement.legal_facts.change_reason') }}<textarea v-model="recipientInstruction.change_reason" rows="2" maxlength="500" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" data-test="recipient-instruction-reason" /></label>
        </div>
        <div class="mt-4 flex flex-wrap justify-end gap-2"><button type="button" :class="btnOutline('neutral')" @click="closeForms"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>{{ t('common.cancel') }}</button><button type="submit" :class="btnFilled('primary')" :disabled="saving || !canSubmitRecipientInstruction"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ t('common.save') }}</button></div>
      </form>

      <div class="mt-5 border-t border-neutral-200 pt-4">
        <h4 class="text-sm font-medium text-neutral-900">{{ t('payroll.enforcement.legal_facts.breakdowns_title') }}</h4>
        <p class="mt-1 text-xs text-neutral-500">{{ t('payroll.enforcement.legal_facts.breakdowns_hint') }}</p>
        <div v-if="claims.length" class="mt-3 space-y-3">
          <article v-for="claim in claims" :key="claim.id" class="rounded-md border border-neutral-200 p-3">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div><p class="text-sm font-medium text-neutral-900">{{ t(`payroll.enforcement.categories.${claim.category}`) }}</p><p class="mt-1 text-xs text-neutral-500">{{ t('payroll.enforcement.legal_facts.claim_total') }}: {{ money(claim.outstanding_minor_units) }}</p></div>
              <button v-if="canWrite && canReadDocuments && caseStatus === 'received'" type="button" :class="btnOutlineSm('neutral')" :data-test="`add-breakdown-${claim.id}`" @click="openBreakdownForm(claim)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="(breakdowns[claim.id]?.length ?? 0) > 0 ? ICONS.edit : ICONS.plus" /></svg>{{ t((breakdowns[claim.id]?.length ?? 0) > 0 ? 'payroll.enforcement.legal_facts.new_revision' : 'payroll.enforcement.legal_facts.add_breakdown') }}</button>
            </div>
            <dl v-if="breakdowns[claim.id]?.length" class="mt-3 grid grid-cols-2 gap-2 text-xs sm:grid-cols-4">
              <template v-for="item in [breakdowns[claim.id][breakdowns[claim.id].length - 1]]" :key="item.id">
                <div><dt class="text-neutral-500">{{ t('payroll.enforcement.legal_facts.principal') }}</dt><dd class="font-medium">{{ money(item.principal_minor_units) }}</dd></div><div><dt class="text-neutral-500">{{ t('payroll.enforcement.legal_facts.interest') }}</dt><dd class="font-medium">{{ money(item.interest_minor_units) }}</dd></div><div><dt class="text-neutral-500">{{ t('payroll.enforcement.legal_facts.costs') }}</dt><dd class="font-medium">{{ money(item.costs_minor_units) }}</dd></div><div><dt class="text-neutral-500">{{ t('payroll.enforcement.legal_facts.maintenance') }}</dt><dd class="font-medium">{{ money(item.maintenance_minor_units) }}</dd></div>
                <div class="col-span-2 sm:col-span-4 flex flex-wrap items-center justify-between gap-2 border-t border-neutral-100 pt-2"><span class="text-neutral-500">{{ t('payroll.enforcement.legal_facts.revision', { number: item.revision_no }) }}<span v-if="item.change_reason"> · {{ item.change_reason }}</span></span><RouterLink :to="{ name: 'document-detail', params: { id: item.source_document_id } }" class="inline-flex items-center gap-1 text-primary-600"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.doc" /></svg>{{ t('payroll.enforcement.legal_facts.source_document') }}</RouterLink></div>
              </template>
            </dl>
            <p v-else class="mt-2 text-xs text-neutral-500">{{ t('payroll.enforcement.legal_facts.breakdown_missing') }}</p>
          </article>
        </div>
        <p v-else class="mt-3 text-sm text-neutral-500">{{ t('payroll.enforcement.no_claims') }}</p>
      </div>

      <form v-if="selectedClaim" data-test="enforcement-breakdown-form" class="mt-4 rounded-md border border-primary-200 bg-primary-50 p-4" @submit.prevent="appendBreakdown">
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4"><label v-for="field in (['principal','interest','costs','maintenance'] as const)" :key="field" class="text-xs font-medium text-neutral-600">{{ t(`payroll.enforcement.legal_facts.${field}`) }}<input v-model="breakdown[field]" required inputmode="decimal" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label></div>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm" :class="breakdownMatches ? 'border-success-200 bg-success-50 text-success-700' : 'border-warning-200 bg-warning-50 text-warning-700'"><span>{{ t('payroll.enforcement.legal_facts.entered_total') }}: {{ breakdownTotal === null ? '—' : money(breakdownTotal) }}</span><span>{{ t('payroll.enforcement.legal_facts.required_total') }}: {{ money(selectedClaim.outstanding_minor_units) }}</span></div>
        <div class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-2"><label class="text-xs font-medium text-neutral-600">{{ t('payroll.enforcement.legal_facts.change_reason') }}<textarea v-model="breakdown.change_reason" maxlength="500" rows="2" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" /></label><label class="relative text-xs font-medium text-neutral-600">{{ t('payroll.enforcement.legal_facts.source_document') }}<input v-model="documentQuery" :readonly="selectedDocument !== null" required type="search" autocomplete="off" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 pr-9 text-sm" :placeholder="t('payroll.enforcement.legal_facts.document_search')"><button v-if="selectedDocument" type="button" class="absolute right-2 top-7 rounded p-1 text-neutral-400 hover:text-danger-600" :title="t('common.remove')" @click="resetDocument"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg></button><ul v-if="documentCandidates.length" class="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-md border border-neutral-200 bg-surface shadow-lg"><li v-for="document in documentCandidates" :key="document.id"><button type="button" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-payroll-50" @click="selectDocument(document)"><span class="truncate">{{ document.title }}</span><span class="shrink-0 text-xs uppercase text-neutral-400">{{ document.doc_type }}</span></button></li></ul></label></div>
        <p v-if="!breakdownMatches" class="mt-2 text-xs text-warning-700">{{ t('payroll.enforcement.legal_facts.total_mismatch') }}</p>
        <div class="mt-4 flex flex-wrap justify-end gap-2"><button type="button" :class="btnOutline('neutral')" @click="closeForms"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>{{ t('common.cancel') }}</button><button type="submit" :class="btnFilled('primary')" :disabled="saving || !canSubmitBreakdown"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ t('common.save') }}</button></div>
      </form>

      <p v-if="canWrite && !canReadDocuments" class="mt-4 rounded-md border border-warning-200 bg-warning-50 p-3 text-xs text-warning-700">{{ t('payroll.enforcement.document_permission_required') }}</p>
    </template>
  </section>
</template>
