<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import {
  payrollApi,
  type PayrollDependant,
  type PayrollDependantClaim,
  type PayrollDependantClaimPayload,
  type PayrollDependantClaimReason,
  type PayrollDependantPayload,
  type PayrollDependantRelation,
  type PayrollDependantsResponse,
} from '@/api/payroll'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import RequiredMark from '@/components/ui/RequiredMark.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { useToast } from '@/composables/useToast'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'

const props = defineProps<{ personId: number; canWrite: boolean }>()

const { t } = useI18n()
const toast = useToast()

const RELATIONS: PayrollDependantRelation[] = [
  'child_own',
  'child_adopted',
  'child_in_care',
  'child_of_spouse',
  'grandchild',
  'spouse',
  'partner',
]
const CLAIM_REASONS: PayrollDependantClaimReason[] = [
  'own_household',
  'shared_custody',
  'adoption',
  'foster_care',
  'study_continues',
  'other',
]

const loading = ref(true)
const saving = ref(false)
const errorMessage = ref('')
const data = ref<PayrollDependantsResponse | null>(null)
const expandedId = ref<number | null>(null)
const editor = ref<'dependant' | 'claim' | null>(null)
const editingDependantId = ref<number | null>(null)
const editingClaimId = ref<number | null>(null)

const dependantForm = reactive({
  relation: 'child_own' as PayrollDependantRelation,
  full_name: '',
  given_name: '',
  family_name: '',
  birth_date: '',
  birth_number: '',
  ztp_p: false,
  student: false,
  existence_from: '',
  existence_to: '',
  note: '',
  row_version: 0,
})

const claimForm = reactive({
  child_order: 1,
  claim_reason: 'own_household' as PayrollDependantClaimReason,
  evidence_status: 'verified' as 'verified' | 'unverified',
  evidence_reference: '',
  shared_household_confirmed: true,
  other_claimant_excluded: true,
  ztp_p: false,
  effective_from: '',
  effective_to: '',
  row_version: 0,
})

const dependants = computed(() => data.value?.dependants ?? [])
const frozenThrough = computed(() => data.value?.frozen_through ?? null)
const relationOptions = computed(() => RELATIONS.map(value => ({
  value,
  label: t(`payroll.people.dependants.relations.${value}`),
})))
const reasonOptions = computed(() => CLAIM_REASONS.map(value => ({
  value,
  label: t(`payroll.people.dependants.reasons.${value}`),
})))
const evidenceOptions = computed<{ value: 'verified' | 'unverified'; label: string }[]>(() => ([
  { value: 'verified', label: t('payroll.people.dependants.evidence.verified') },
  { value: 'unverified', label: t('payroll.people.dependants.evidence.unverified') },
]))
const editingDependant = computed(() =>
  dependants.value.find(item => item.id === editingDependantId.value) ?? null)

const listActions = computed<ActionItem[]>(() => [{
  key: 'add-dependant',
  label: t('payroll.people.dependants.add'),
  icon: 'plus',
  tier: 'primary',
  variant: 'primary',
  show: props.canWrite,
  disabled: saving.value,
  run: () => openDependantEditor(null),
}])

const labelClass = 'block text-xs text-neutral-600'
const inputClass = 'mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm'

const COLUMNS: ColumnDef[] = [
  { key: 'person', labelKey: 'payroll.people.dependants.columns.person', required: true },
  { key: 'relation', labelKey: 'payroll.people.dependants.columns.relation' },
  { key: 'birth_number', labelKey: 'payroll.people.dependants.columns.birth_number', defaultHidden: true },
  { key: 'existence', labelKey: 'payroll.people.dependants.columns.existence' },
  { key: 'claims', labelKey: 'payroll.people.dependants.columns.claims' },
  { key: 'actions', labelKey: 'common.actions', required: true },
]
const tbl = useTablePrefs('payroll-person-dependants', COLUMNS)
const detailColspan = computed(() => COLUMNS.filter(column => tbl.isVisible(column.key)).length)

onMounted(load)
watch(() => props.personId, load)

async function load(): Promise<void> {
  loading.value = true
  errorMessage.value = ''
  try {
    data.value = await payrollApi.personDependants(props.personId)
  } catch (error) {
    data.value = null
    errorMessage.value = apiErrorMessage(error, t('payroll.people.dependants.load_failed'))
  } finally {
    loading.value = false
  }
}

function toggleDetail(id: number): void {
  expandedId.value = expandedId.value === id ? null : id
}

function openDependantEditor(dependant: PayrollDependant | null): void {
  errorMessage.value = ''
  editor.value = 'dependant'
  editingClaimId.value = null
  editingDependantId.value = dependant?.id ?? null
  dependantForm.relation = dependant?.relation ?? 'child_own'
  dependantForm.full_name = dependant?.full_name ?? ''
  dependantForm.given_name = dependant?.given_name ?? ''
  dependantForm.family_name = dependant?.family_name ?? ''
  dependantForm.birth_date = dependant?.birth_date ?? ''
  dependantForm.birth_number = ''
  dependantForm.ztp_p = dependant?.ztp_p ?? false
  dependantForm.student = dependant?.student ?? false
  dependantForm.existence_from = dependant?.existence_from ?? ''
  dependantForm.existence_to = dependant?.existence_to ?? ''
  dependantForm.note = dependant?.note ?? ''
  dependantForm.row_version = dependant?.row_version ?? 0
}

function openClaimEditor(dependant: PayrollDependant, claim: PayrollDependantClaim | null): void {
  errorMessage.value = ''
  editor.value = 'claim'
  editingDependantId.value = dependant.id
  editingClaimId.value = claim?.id ?? null
  claimForm.child_order = claim?.child_order ?? nextOrder()
  claimForm.claim_reason = claim?.claim_reason ?? 'own_household'
  claimForm.evidence_status = claim?.evidence_status ?? 'verified'
  claimForm.evidence_reference = claim?.evidence_reference ?? ''
  claimForm.shared_household_confirmed = claim?.shared_household_confirmed ?? true
  claimForm.other_claimant_excluded = claim?.other_claimant_excluded ?? true
  claimForm.ztp_p = claim?.ztp_p ?? dependant.ztp_p
  claimForm.effective_from = claim?.effective_from ?? monthStart(dependant.existence_from)
  claimForm.effective_to = claim?.effective_to ?? ''
  claimForm.row_version = claim?.row_version ?? 0
}

function closeEditor(): void {
  editor.value = null
  editingDependantId.value = null
  editingClaimId.value = null
  errorMessage.value = ''
}

function nextOrder(): number {
  const used = new Set<number>()
  for (const dependant of dependants.value) {
    for (const claim of dependant.claims) {
      if (claim.superseded_by_id === null && claim.effective_to === null) {
        used.add(claim.child_order)
      }
    }
  }
  let order = 1
  while (used.has(order)) order += 1
  return order
}

function monthStart(date: string): string {
  return date === '' ? '' : `${date.slice(0, 7)}-01`
}

function dependantPayload(): PayrollDependantPayload {
  const payload: PayrollDependantPayload = {
    relation: dependantForm.relation,
    full_name: dependantForm.full_name.trim(),
    given_name: dependantForm.given_name.trim() || null,
    family_name: dependantForm.family_name.trim() || null,
    birth_date: dependantForm.birth_date,
    ztp_p: dependantForm.ztp_p,
    student: dependantForm.student,
    existence_from: dependantForm.existence_from,
    existence_to: dependantForm.existence_to === '' ? null : dependantForm.existence_to,
    note: dependantForm.note.trim() === '' ? null : dependantForm.note.trim(),
  }
  if (dependantForm.birth_number.trim() !== '') {
    payload.birth_number = dependantForm.birth_number.trim()
  }
  if (editingDependantId.value !== null) {
    payload.row_version = dependantForm.row_version
  }
  return payload
}

function claimPayload(): PayrollDependantClaimPayload {
  const payload: PayrollDependantClaimPayload = {
    child_order: Number(claimForm.child_order),
    claim_reason: claimForm.claim_reason,
    evidence_status: claimForm.evidence_status,
    evidence_reference: claimForm.evidence_status === 'verified'
      ? claimForm.evidence_reference.trim() || null
      : null,
    shared_household_confirmed: claimForm.shared_household_confirmed,
    other_claimant_excluded: claimForm.other_claimant_excluded,
    ztp_p: claimForm.ztp_p,
    effective_from: claimForm.effective_from,
    effective_to: claimForm.effective_to === '' ? null : claimForm.effective_to,
  }
  if (editingClaimId.value !== null) {
    payload.row_version = claimForm.row_version
  }
  return payload
}

async function save(): Promise<void> {
  if (!props.canWrite || saving.value) return
  saving.value = true
  errorMessage.value = ''
  try {
    if (editor.value === 'dependant') {
      data.value = editingDependantId.value === null
        ? await payrollApi.createPersonDependant(props.personId, dependantPayload())
        : await payrollApi.savePersonDependant(
          props.personId,
          editingDependantId.value,
          dependantPayload(),
        )
    } else if (editor.value === 'claim' && editingDependantId.value !== null) {
      data.value = editingClaimId.value === null
        ? await payrollApi.createPersonDependantClaim(
          props.personId,
          editingDependantId.value,
          claimPayload(),
        )
        : await payrollApi.savePersonDependantClaim(
          props.personId,
          editingDependantId.value,
          editingClaimId.value,
          claimPayload(),
        )
    }
    toast.success(t('payroll.people.dependants.saved'))
    closeEditor()
  } catch (error) {
    errorMessage.value = apiErrorMessage(error, t('payroll.people.dependants.save_failed'))
  } finally {
    saving.value = false
  }
}

function relationLabel(relation: PayrollDependantRelation): string {
  return t(`payroll.people.dependants.relations.${relation}`)
}

function blockerLabel(blocker: string): string {
  return t(`payroll.people.dependants.blockers.${blocker}`)
}

function periodLabel(from: string, to: string | null): string {
  return to === null
    ? t('payroll.people.dependants.period_open', { from })
    : t('payroll.people.dependants.period_closed', { from, to })
}

function creditLabel(claim: PayrollDependantClaim): string {
  if (claim.credit.status !== 'calculated' || claim.credit.monthly_credit_minor_units === null) {
    return t('payroll.people.dependants.credit_manual_review')
  }
  return t('payroll.people.dependants.credit_monthly', {
    amount: (claim.credit.monthly_credit_minor_units / 100).toLocaleString('cs-CZ', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }),
  })
}
</script>

<template>
  <section class="rounded-xl border border-neutral-200 bg-surface shadow-sm" data-test="person-dependants">
    <header class="border-b border-neutral-200 px-4 py-4 sm:px-6">
      <h2 class="text-base font-semibold text-neutral-900">
        {{ t('payroll.people.dependants.title') }}
      </h2>
      <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.people.dependants.subtitle') }}</p>
      <p v-if="frozenThrough" class="mt-2 text-xs text-neutral-500">
        {{ t('payroll.people.dependants.frozen_hint', { date: frozenThrough }) }}
      </p>
      <ActionBar v-if="listActions.some(action => action.show)" :actions="listActions" class="mt-3" />
    </header>

    <div v-if="loading" class="space-y-3 p-4 sm:p-6">
      <div v-for="index in 2" :key="index" class="h-16 animate-pulse rounded-lg bg-neutral-100" />
    </div>

    <p
      v-else-if="dependants.length === 0 && editor === null"
      class="p-6 text-sm text-neutral-500"
      data-test="dependants-empty"
    >
      {{ t('payroll.people.dependants.empty') }}
    </p>

    <template v-else-if="dependants.length > 0">
      <div data-layout="desktop" class="hidden md:block">
        <div class="mb-2 flex flex-wrap items-center justify-end gap-2">
          <ColumnPicker :ctrl="tbl" />
          <DensityToggle :ctrl="tbl" />
        </div>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-neutral-200 text-sm" :class="tbl.densityClass.value">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                <th v-if="tbl.isVisible('person')" class="px-4 py-3">{{ t('payroll.people.dependants.columns.person') }}</th>
                <th v-if="tbl.isVisible('relation')" class="px-4 py-3">{{ t('payroll.people.dependants.columns.relation') }}</th>
                <th v-if="tbl.isVisible('birth_number')" class="px-4 py-3">{{ t('payroll.people.dependants.columns.birth_number') }}</th>
                <th v-if="tbl.isVisible('existence')" class="px-4 py-3">{{ t('payroll.people.dependants.columns.existence') }}</th>
                <th v-if="tbl.isVisible('claims')" class="px-4 py-3 text-right">{{ t('payroll.people.dependants.columns.claims') }}</th>
                <th v-if="tbl.isVisible('actions')" class="px-4 py-3"><span class="sr-only">{{ t('common.actions') }}</span></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <template v-for="dependant in dependants" :key="dependant.id">
                <tr class="align-top">
                  <td v-if="tbl.isVisible('person')" class="px-4 py-3 font-medium text-neutral-900">
                    {{ dependant.full_name }}
                    <span v-if="dependant.ztp_p" class="ml-2 rounded-full bg-warning-50 px-2 py-0.5 text-xs font-medium text-warning-700">ZTP/P</span>
                    <span v-if="dependant.student" class="ml-2 rounded-full bg-neutral-100 px-2 py-0.5 text-xs text-neutral-600">{{ t('payroll.people.dependants.student') }}</span>
                  </td>
                  <td v-if="tbl.isVisible('relation')" class="px-4 py-3 text-neutral-600">{{ relationLabel(dependant.relation) }}</td>
                  <td v-if="tbl.isVisible('birth_number')" class="px-4 py-3 text-neutral-600">{{ dependant.birth_number_masked ?? '—' }}</td>
                  <td v-if="tbl.isVisible('existence')" class="px-4 py-3 text-neutral-600">{{ periodLabel(dependant.existence_from, dependant.existence_to) }}</td>
                  <td v-if="tbl.isVisible('claims')" class="px-4 py-3 text-right text-neutral-700">{{ dependant.claims.length }}</td>
                  <td v-if="tbl.isVisible('actions')" class="px-4 py-3 text-right">
                    <div class="flex flex-wrap justify-end gap-2">
                      <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" :aria-expanded="expandedId === dependant.id" @click="toggleDetail(dependant.id)">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.user" /></svg>
                        {{ t(expandedId === dependant.id ? 'payroll.people.dependants.hide' : 'payroll.people.dependants.show') }}
                      </button>
                    </div>
                  </td>
                </tr>
                <tr v-if="expandedId === dependant.id">
                  <td :colspan="detailColspan" class="bg-neutral-50 px-4 py-4">
                  <div class="space-y-3">
                    <div class="flex flex-wrap gap-2">
                      <button v-if="canWrite" type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" :data-test="`edit-dependant-${dependant.id}`" @click="openDependantEditor(dependant)">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
                        {{ t('payroll.people.dependants.edit_person') }}
                      </button>
                      <button v-if="canWrite && dependant.can_claim_monthly" type="button" :class="btnFilled('primary')" class="whitespace-nowrap" :data-test="`add-claim-${dependant.id}`" @click="openClaimEditor(dependant, null)">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
                        {{ t('payroll.people.dependants.add_claim') }}
                      </button>
                    </div>
                    <p v-if="!dependant.can_claim_monthly" class="rounded-lg border border-neutral-200 bg-surface p-3 text-sm text-neutral-600">
                      {{ t('payroll.people.dependants.spouse_annual_only') }}
                    </p>
                    <article
                      v-for="claim in dependant.claims"
                      :key="claim.id"
                      class="rounded-lg border border-neutral-200 bg-surface p-3"
                      :data-test="`claim-${claim.id}`"
                    >
                      <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                          <p class="text-sm font-medium text-neutral-900">
                            {{ t('payroll.people.dependants.order', { order: claim.child_order }) }}
                            <span v-if="claim.ztp_p" class="ml-2 rounded-full bg-warning-50 px-2 py-0.5 text-xs font-medium text-warning-700">ZTP/P</span>
                          </p>
                          <p class="mt-0.5 text-xs text-neutral-500">{{ periodLabel(claim.effective_from, claim.effective_to) }}</p>
                          <p class="mt-0.5 text-xs text-neutral-600">{{ creditLabel(claim) }}</p>
                        </div>
                        <div class="flex flex-wrap justify-end gap-2">
                          <span v-if="claim.is_frozen" class="rounded-full bg-neutral-100 px-2 py-1 text-xs text-neutral-600">{{ t('payroll.people.dependants.frozen') }}</span>
                          <button v-if="canWrite && claim.superseded_by_id === null" type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="openClaimEditor(dependant, claim)">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
                            {{ t('payroll.people.dependants.edit_claim') }}
                          </button>
                        </div>
                      </div>
                      <ul v-if="claim.blockers.length > 0" class="mt-2 space-y-1" :data-test="`claim-blockers-${claim.id}`">
                        <li v-for="blocker in claim.blockers" :key="blocker" class="rounded-md bg-warning-50 px-2 py-1 text-xs text-warning-700">
                          {{ blockerLabel(blocker) }}
                        </li>
                      </ul>
                      <p v-else class="mt-2 rounded-md bg-success-50 px-2 py-1 text-xs text-success-600">
                        {{ t('payroll.people.dependants.claim_ok') }}
                      </p>
                    </article>
                  </div>
                </td>
              </tr>
            </template>
            </tbody>
          </table>
        </div>
      </div>

      <div data-layout="mobile" class="space-y-3 p-4 md:hidden">
        <article
          v-for="dependant in dependants"
          :key="dependant.id"
          class="min-w-0 overflow-hidden rounded-lg border border-neutral-200 p-4"
        >
          <div class="flex flex-wrap items-start justify-between gap-2">
            <h3 class="min-w-0 break-words font-semibold text-neutral-900">{{ dependant.full_name }}</h3>
            <span v-if="dependant.ztp_p" class="rounded-full bg-warning-50 px-2 py-1 text-xs font-medium text-warning-700">ZTP/P</span>
          </div>
          <dl class="mt-3 space-y-2 text-sm">
            <div>
              <dt class="text-xs text-neutral-500">{{ t('payroll.people.dependants.columns.relation') }}</dt>
              <dd class="mt-0.5 break-words text-neutral-800">{{ relationLabel(dependant.relation) }}</dd>
            </div>
            <div>
              <dt class="text-xs text-neutral-500">{{ t('payroll.people.dependants.columns.birth_number') }}</dt>
              <dd class="mt-0.5 break-words text-neutral-800">{{ dependant.birth_number_masked ?? '—' }}</dd>
            </div>
            <div>
              <dt class="text-xs text-neutral-500">{{ t('payroll.people.dependants.columns.existence') }}</dt>
              <dd class="mt-0.5 break-words text-neutral-800">{{ periodLabel(dependant.existence_from, dependant.existence_to) }}</dd>
            </div>
          </dl>
          <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="toggleDetail(dependant.id)">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.user" /></svg>
              {{ t(expandedId === dependant.id ? 'payroll.people.dependants.hide' : 'payroll.people.dependants.show') }}
            </button>
            <button v-if="canWrite" type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="openDependantEditor(dependant)">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
              {{ t('payroll.people.dependants.edit_person') }}
            </button>
            <button v-if="canWrite && dependant.can_claim_monthly" type="button" :class="btnFilled('primary')" class="whitespace-nowrap" @click="openClaimEditor(dependant, null)">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
              {{ t('payroll.people.dependants.add_claim') }}
            </button>
          </div>
          <div v-if="expandedId === dependant.id" class="mt-3 space-y-3 border-t border-neutral-200 pt-3">
            <p v-if="!dependant.can_claim_monthly" class="text-sm text-neutral-600">
              {{ t('payroll.people.dependants.spouse_annual_only') }}
            </p>
            <article v-for="claim in dependant.claims" :key="claim.id" class="min-w-0 rounded-lg border border-neutral-200 p-3">
              <p class="text-sm font-medium text-neutral-900">{{ t('payroll.people.dependants.order', { order: claim.child_order }) }}</p>
              <p class="mt-0.5 break-words text-xs text-neutral-500">{{ periodLabel(claim.effective_from, claim.effective_to) }}</p>
              <p class="mt-0.5 break-words text-xs text-neutral-600">{{ creditLabel(claim) }}</p>
              <ul v-if="claim.blockers.length > 0" class="mt-2 space-y-1">
                <li v-for="blocker in claim.blockers" :key="blocker" class="break-words rounded-md bg-warning-50 px-2 py-1 text-xs text-warning-700">
                  {{ blockerLabel(blocker) }}
                </li>
              </ul>
              <button v-if="canWrite && claim.superseded_by_id === null" type="button" :class="btnOutline('neutral')" class="mt-2 whitespace-nowrap" @click="openClaimEditor(dependant, claim)">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
                {{ t('payroll.people.dependants.edit_claim') }}
              </button>
            </article>
          </div>
        </article>
      </div>
    </template>

    <form
      v-if="editor !== null"
      class="border-t border-neutral-200 p-4 sm:p-6"
      data-test="dependant-editor"
      @submit.prevent="save"
    >
      <h3 class="text-sm font-semibold text-neutral-900">
        {{ editor === 'dependant'
          ? t('payroll.people.dependants.form.person_title')
          : t('payroll.people.dependants.form.claim_title', { name: editingDependant?.full_name ?? '' }) }}
      </h3>

      <div v-if="editor === 'dependant'" class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <label :class="labelClass">
          {{ t('payroll.people.dependants.form.relation') }}
          <SearchableSelect v-model="dependantForm.relation" class="mt-1" :options="relationOptions" :clearable="false" accent="payroll" />
        </label>
        <label :class="labelClass">
          {{ t('payroll.people.dependants.form.full_name') }} <RequiredMark />
          <input v-model="dependantForm.full_name" required :class="inputClass" data-test="dependant-full-name">
        </label>
        <label :class="labelClass">
          {{ t('payroll.people.dependants.form.given_name') }}
          <input v-model="dependantForm.given_name" :class="inputClass" maxlength="100" data-test="dependant-given-name">
          <span class="mt-1 block text-xs text-neutral-500">{{ t('payroll.people.dependants.form.jmhz_name_hint') }}</span>
        </label>
        <label :class="labelClass">
          {{ t('payroll.people.dependants.form.family_name') }}
          <input v-model="dependantForm.family_name" :class="inputClass" maxlength="100" data-test="dependant-family-name">
        </label>
        <label :class="labelClass">
          {{ t('payroll.people.dependants.form.birth_date') }} <RequiredMark />
          <input v-model="dependantForm.birth_date" required type="date" :class="inputClass">
        </label>
        <label :class="labelClass">
          {{ t('payroll.people.dependants.form.birth_number') }}
          <input v-model="dependantForm.birth_number" :class="inputClass" :placeholder="t('payroll.people.dependants.form.birth_number_placeholder')" data-test="dependant-birth-number">
          <span class="mt-1 block text-xs text-neutral-500">{{ t('payroll.people.dependants.form.birth_number_hint') }}</span>
        </label>
        <label :class="labelClass">
          {{ t('payroll.people.dependants.form.existence_from') }} <RequiredMark />
          <input v-model="dependantForm.existence_from" required type="date" :class="inputClass">
        </label>
        <label :class="labelClass">
          {{ t('payroll.people.dependants.form.existence_to') }}
          <input v-model="dependantForm.existence_to" type="date" :class="inputClass">
        </label>
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="dependantForm.ztp_p" type="checkbox" class="rounded border-neutral-300 text-payroll-600">
          {{ t('payroll.people.dependants.form.ztp_p') }}
        </label>
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="dependantForm.student" type="checkbox" class="rounded border-neutral-300 text-payroll-600">
          {{ t('payroll.people.dependants.form.student') }}
        </label>
        <label :class="labelClass" class="sm:col-span-2 lg:col-span-3">
          {{ t('payroll.people.dependants.form.note') }}
          <input v-model="dependantForm.note" :class="inputClass">
        </label>
      </div>

      <div v-else class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <label :class="labelClass">
          {{ t('payroll.people.dependants.form.child_order') }} <RequiredMark />
          <input v-model.number="claimForm.child_order" required type="number" min="1" max="20" step="1" :class="inputClass" data-test="claim-order">
          <span class="mt-1 block text-xs text-neutral-500">{{ t('payroll.people.dependants.form.child_order_hint') }}</span>
        </label>
        <label :class="labelClass">
          {{ t('payroll.people.dependants.form.reason') }}
          <SearchableSelect v-model="claimForm.claim_reason" class="mt-1" :options="reasonOptions" :clearable="false" accent="payroll" />
        </label>
        <label :class="labelClass">
          {{ t('payroll.people.dependants.form.evidence_status') }}
          <SearchableSelect v-model="claimForm.evidence_status" class="mt-1" :options="evidenceOptions" :clearable="false" accent="payroll" />
        </label>
        <label :class="labelClass" class="sm:col-span-2">
          {{ t('payroll.people.dependants.form.evidence_reference') }}
          <input
            v-model="claimForm.evidence_reference"
            :class="inputClass"
            :disabled="claimForm.evidence_status !== 'verified'"
            :placeholder="t('payroll.people.dependants.form.evidence_reference_placeholder')"
            data-test="claim-evidence-reference"
          >
          <span class="mt-1 block text-xs text-neutral-500">{{ t('payroll.people.dependants.form.evidence_reference_hint') }}</span>
        </label>
        <label :class="labelClass">
          {{ t('payroll.people.dependants.form.effective_from') }} <RequiredMark />
          <input v-model="claimForm.effective_from" required type="date" :class="inputClass" data-test="claim-effective-from">
        </label>
        <label :class="labelClass">
          {{ t('payroll.people.dependants.form.effective_to') }}
          <input v-model="claimForm.effective_to" type="date" :class="inputClass" data-test="claim-effective-to">
          <span class="mt-1 block text-xs text-neutral-500">{{ t('payroll.people.dependants.form.effective_to_hint') }}</span>
        </label>
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="claimForm.ztp_p" type="checkbox" class="rounded border-neutral-300 text-payroll-600">
          {{ t('payroll.people.dependants.form.claim_ztp_p') }}
        </label>
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="claimForm.shared_household_confirmed" type="checkbox" class="rounded border-neutral-300 text-payroll-600">
          {{ t('payroll.people.dependants.form.shared_household') }}
        </label>
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="claimForm.other_claimant_excluded" type="checkbox" class="rounded border-neutral-300 text-payroll-600">
          {{ t('payroll.people.dependants.form.other_claimant_excluded') }}
        </label>
        <p class="text-xs text-neutral-500 sm:col-span-2 lg:col-span-3">
          {{ t('payroll.people.dependants.form.supersede_hint') }}
        </p>
      </div>

      <p
        v-if="errorMessage"
        class="mt-3 break-words rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
        role="alert"
        data-test="dependant-error"
      >
        {{ errorMessage }}
      </p>

      <div class="sticky bottom-0 -mx-4 mt-4 flex flex-wrap justify-end gap-2 border-t border-neutral-200 bg-surface/95 px-4 py-3 sm:-mx-6 sm:px-6">
        <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="closeEditor">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
        <button type="submit" :class="btnFilled('primary')" class="whitespace-nowrap" :disabled="saving || !canWrite" data-test="save-dependant">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
          {{ t('common.save') }}
        </button>
      </div>
    </form>

    <p
      v-else-if="errorMessage"
      class="break-words border-t border-neutral-200 p-4 text-sm text-danger-700 sm:p-6"
      role="alert"
    >
      {{ errorMessage }}
    </p>
  </section>
</template>
