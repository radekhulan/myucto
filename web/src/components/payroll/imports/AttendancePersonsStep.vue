<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import type {
  AttendanceEmploymentOption,
  AttendanceMatchStatus,
  AttendancePerson,
  AttendancePersonCreate,
  AttendancePersonsResult,
  RegistrationRelationType,
} from '@/api/payrollImports'
import DateInput from '@/components/ui/DateInput.vue'
import { formatDate, formatMoneyMinor } from '@/composables/useFormat'
import { BTN_DISABLED_NOTE, btnFilled, btnIconSm, btnOutlineSm, disabledTitle, ICONS } from '@/components/ui/buttonStyles'
import {
  buildPersonsPayload,
  effectiveEmploymentId,
  firstDayOfPeriod,
  guessRelationType,
  personCanBeCreated,
  personCreateDetails,
  type PersonCreateDetailKey,
  splitDisplayName,
  type ManualLinks,
  type PersonDraft,
} from './importHelpers'

const props = withDefaults(defineProps<{
  persons: AttendancePerson[]
  options: AttendanceEmploymentOption[]
  manualLinks: ManualLinks
  period: string
  canCreate: boolean
  disabled?: boolean
  createResults?: AttendancePersonsResult | null
}>(), {
  disabled: false,
  createResults: null,
})

const emit = defineEmits<{
  'set-link': [personKey: string, employmentId: number | null]
  create: [persons: AttendancePersonCreate[]]
}>()

const { t } = useI18n()

type Filter = 'all' | 'attention' | AttendanceMatchStatus
const FILTERS: Filter[] = ['all', 'attention', 'linked', 'matched', 'ambiguous', 'not_found']
const RELATION_TYPES: RegistrationRelationType[] = ['employment', 'small_scale_employment', 'dpp', 'dpc', 'statutory_body']

const filter = ref<Filter>('all')
const createKeys = ref<string[]>([])
const drafts = ref<Record<string, PersonDraft>>({})
const createForm = ref({
  relation_type: 'employment' as RegistrationRelationType,
  weekly_hours: '40',
  planned_start_on: firstDayOfPeriod(props.period),
  activate: true,
})

watch(() => props.period, value => { createForm.value.planned_start_on = firstDayOfPeriod(value) })

const optionById = computed(() => new Map(props.options.map(option => [option.employment_id, option])))
const counts = computed(() => {
  const result: Record<Filter, number> = { all: 0, attention: 0, linked: 0, matched: 0, ambiguous: 0, not_found: 0 }
  for (const person of props.persons) {
    result.all += 1
    result[person.match.status] += 1
    if (needsAttention(person)) result.attention += 1
  }
  return result
})
const visiblePersons = computed(() => props.persons.filter(person => {
  if (filter.value === 'all') return true
  if (filter.value === 'attention') return needsAttention(person)
  return person.match.status === filter.value
}))
const creatable = computed(() => props.persons.filter(canBeCreated))
const createPersons = computed(() => props.persons.filter(person => createKeys.value.includes(person.key)))
const resultByKey = computed(() => new Map((props.createResults?.results ?? []).map(item => [item.person_key, item])))

const createBlockedReason = computed(() => {
  if (!props.canCreate) return t('payroll_imports.attendance.persons.create_no_permission')
  if (createPersons.value.length === 0) return t('payroll_imports.attendance.persons.create_none')
  if (!createForm.value.planned_start_on) return t('payroll_imports.attendance.persons.create_no_start')
  if (createPersons.value.some(person => draftFor(person).last_name.trim() === '' || draftFor(person).first_name.trim() === '')) {
    return t('payroll_imports.attendance.persons.create_names_missing')
  }
  return ''
})

// Po novém náhledu mohou osoby zmizet nebo dostat shodu — výběr k založení se tomu přizpůsobí.
watch(() => props.persons, persons => {
  const allowed = new Set(persons.filter(canBeCreated).map(person => person.key))
  createKeys.value = createKeys.value.filter(key => allowed.has(key))
})

function needsAttention(person: AttendancePerson): boolean {
  return effectiveEmploymentId(person, props.manualLinks) === null || person.match.status === 'ambiguous'
}

function canBeCreated(person: AttendancePerson): boolean {
  return personCanBeCreated(person, props.manualLinks)
}

function statusClass(status: AttendanceMatchStatus): string {
  return {
    linked: 'bg-success-50 text-success-700',
    matched: 'bg-primary-50 text-primary-700',
    ambiguous: 'bg-warning-50 text-warning-700',
    not_found: 'bg-danger-50 text-danger-600',
  }[status]
}

function selectValue(person: AttendancePerson): string {
  const id = effectiveEmploymentId(person, props.manualLinks)
  return id === null ? '' : String(id)
}

function onSelect(person: AttendancePerson, value: string) {
  emit('set-link', person.key, value === '' ? null : Number(value))
}

/** Prázdná volba selectu se liší podle toho, jestli osobu lze rovnou založit. */
function noEmploymentLabel(person: AttendancePerson): string {
  return canBeCreated(person)
    ? t('payroll_imports.attendance.persons.no_employment_create')
    : t('payroll_imports.attendance.persons.no_employment')
}

function candidateOptions(person: AttendancePerson): AttendanceEmploymentOption[] {
  return person.match.candidates
    .map(candidate => optionById.value.get(candidate.employment_id))
    .filter((option): option is AttendanceEmploymentOption => option !== undefined)
}

function isManual(person: AttendancePerson): boolean {
  return Object.prototype.hasOwnProperty.call(props.manualLinks, person.key)
}

/** Čtení bez zápisu — volá se z computed, kde se stav měnit nesmí. */
function draftFor(person: AttendancePerson): PersonDraft {
  return drafts.value[person.key] ?? splitDisplayName(person.display_name)
}

function ensureDraft(person: AttendancePerson) {
  if (!drafts.value[person.key]) drafts.value[person.key] = splitDisplayName(person.display_name)
}

function swapNames(person: AttendancePerson) {
  const draft = draftFor(person)
  drafts.value[person.key] = { first_name: draft.last_name, last_name: draft.first_name }
}

function setDraftField(person: AttendancePerson, field: keyof PersonDraft, value: string) {
  drafts.value[person.key] = { ...draftFor(person), [field]: value }
}

function toggleCreate(person: AttendancePerson) {
  if (createKeys.value.includes(person.key)) {
    createKeys.value = createKeys.value.filter(key => key !== person.key)
    return
  }
  ensureDraft(person)
  if (createKeys.value.length === 0) createForm.value.relation_type = guessRelationType(person.relation_label)
  createKeys.value = [...createKeys.value, person.key]
}

function selectAllCreatable() {
  for (const person of creatable.value) ensureDraft(person)
  createKeys.value = creatable.value.map(person => person.key)
}

function detailValue(key: PersonCreateDetailKey, value: string, invalid: boolean): string {
  if (invalid) return t('payroll_imports.attendance.persons.details.unreadable', { value })
  if (key === 'start_on' || key === 'agreed_end_on') return formatDate(value)
  if (key === 'hourly_rate' || key === 'monthly_wage') return formatMoneyMinor(Math.round(Number(value) * 100))
  return value
}

function submitCreate() {
  if (createBlockedReason.value !== '') return
  emit('create', buildPersonsPayload(createPersons.value, drafts.value, createForm.value))
}

const SELECT_CLASS = 'h-8 w-full min-w-48 rounded-md border border-neutral-300 bg-surface px-2 text-sm disabled:bg-neutral-100'
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center gap-2" role="group" :aria-label="t('payroll_imports.attendance.persons.filter_label')">
      <button v-for="item in FILTERS" :key="item" type="button"
        class="inline-flex cursor-pointer items-center gap-1.5 whitespace-nowrap rounded-full border px-3 py-1 text-xs font-medium transition-colors"
        :class="filter === item ? 'border-payroll-500 bg-payroll-50 text-payroll-700' : 'border-neutral-200 text-neutral-600 hover:bg-neutral-50'"
        :aria-pressed="filter === item" @click="filter = item">
        {{ t(`payroll_imports.attendance.persons.filters.${item}`) }}
        <span class="rounded-full bg-surface px-1.5 text-[11px] text-neutral-500">{{ counts[item] }}</span>
      </button>
    </div>

    <section class="rounded-xl border border-neutral-200 bg-surface shadow-sm">
      <div class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-100 px-4 py-3">
        <p class="text-sm text-neutral-600">{{ t('payroll_imports.attendance.persons.hint') }}</p>
        <button v-if="canCreate && creatable.length" type="button" :class="btnOutlineSm('primary')" :disabled="disabled" @click="selectAllCreatable">
          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
          {{ t('payroll_imports.attendance.persons.select_all_creatable', { count: creatable.length }) }}
        </button>
      </div>

      <p v-if="creatable.length" class="border-b border-neutral-100 bg-neutral-50 px-4 py-2 text-xs text-neutral-600">{{ t('payroll_imports.attendance.persons.create_guidance') }}</p>

      <p v-if="visiblePersons.length === 0" class="px-4 py-6 text-sm text-neutral-500">{{ t('payroll_imports.attendance.persons.empty') }}</p>

      <div v-else class="hidden max-h-[65vh] overflow-auto md:block">
        <table class="min-w-full divide-y divide-neutral-200 text-sm">
          <thead>
            <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
              <th class="sticky top-0 z-10 bg-surface px-3 py-2">{{ t('payroll_imports.attendance.persons.columns.person') }}</th>
              <th class="sticky top-0 z-10 bg-surface px-3 py-2">{{ t('payroll_imports.attendance.persons.columns.status') }}</th>
              <th class="sticky top-0 z-10 bg-surface px-3 py-2">{{ t('payroll_imports.attendance.persons.columns.employment') }}</th>
              <th class="sticky top-0 z-10 bg-surface px-3 py-2 text-center">{{ t('payroll_imports.attendance.persons.columns.create') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="person in visiblePersons" :key="person.key" class="align-top">
              <td class="px-3 py-2">
                <p class="font-medium text-neutral-900">{{ person.display_name }}</p>
                <p class="text-xs text-neutral-500">
                  <span v-if="person.personal_number" class="font-mono">{{ person.personal_number }}</span>
                  <span v-if="person.personal_number && person.birth_number_masked"> · </span>
                  <span v-if="person.birth_number_masked" class="font-mono">{{ person.birth_number_masked }}</span>
                </p>
                <p v-if="person.relation_label || person.department" class="text-xs text-neutral-500">{{ [person.relation_label, person.department].filter(Boolean).join(' · ') }}</p>
                <ul v-if="person.warnings.length" class="mt-1 space-y-0.5 text-xs text-warning-700">
                  <li v-for="warning in person.warnings" :key="warning">{{ warning }}</li>
                </ul>
                <p v-if="resultByKey.get(person.key)?.status === 'failed'" class="mt-1 text-xs font-medium text-danger-600">{{ resultByKey.get(person.key)?.message }}</p>
                <p v-else-if="resultByKey.get(person.key)?.message" class="mt-1 text-xs text-warning-700">{{ resultByKey.get(person.key)?.message }}</p>
              </td>
              <td class="px-3 py-2">
                <span class="whitespace-nowrap rounded-full px-2 py-1 text-xs font-medium" :class="statusClass(person.match.status)">{{ t(`payroll_imports.attendance.persons.status.${person.match.status}`) }}</span>
                <p v-if="person.match.matched_by" class="mt-1 text-[11px] text-neutral-500">{{ t(`payroll_imports.attendance.persons.matched_by.${person.match.matched_by}`) }}</p>
                <p v-if="isManual(person)" class="mt-1 text-[11px] font-medium text-payroll-600">{{ t('payroll_imports.attendance.persons.manual') }}</p>
              </td>
              <td class="px-3 py-2">
                <select :class="SELECT_CLASS" :value="selectValue(person)" :disabled="disabled || createKeys.includes(person.key)"
                  :aria-label="t('payroll_imports.attendance.persons.employment_for', { name: person.display_name })"
                  @change="onSelect(person, ($event.target as HTMLSelectElement).value)">
                  <option value="">{{ noEmploymentLabel(person) }}</option>
                  <optgroup v-if="candidateOptions(person).length" :label="t('payroll_imports.attendance.persons.candidates')">
                    <option v-for="option in candidateOptions(person)" :key="`c-${option.employment_id}`" :value="String(option.employment_id)">{{ option.label }}</option>
                  </optgroup>
                  <optgroup :label="t('payroll_imports.attendance.persons.all_employments')">
                    <option v-for="option in options" :key="option.employment_id" :value="String(option.employment_id)">{{ option.label }}</option>
                  </optgroup>
                </select>
                <RouterLink v-if="person.match.employee_id && selectValue(person) === String(person.match.employment_id)"
                  :to="{ name: 'payroll-person', params: { id: person.match.employee_id } }" class="mt-1 inline-block text-xs text-payroll-600 hover:underline">
                  {{ t('payroll_imports.common.open_person') }}
                </RouterLink>
              </td>
              <td class="px-3 py-2 text-center">
                <input v-if="canCreate && canBeCreated(person)" type="checkbox" :checked="createKeys.includes(person.key)" :disabled="disabled"
                  :aria-label="t('payroll_imports.attendance.persons.create_for', { name: person.display_name })" @change="toggleCreate(person)">
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="visiblePersons.length" class="space-y-2 p-3 md:hidden">
        <article v-for="person in visiblePersons" :key="person.key" class="rounded-lg bg-neutral-50 p-3 text-sm">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="font-medium text-neutral-900">{{ person.display_name }}</p>
              <p v-if="person.personal_number" class="font-mono text-xs text-neutral-500">{{ person.personal_number }}</p>
            </div>
            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(person.match.status)">{{ t(`payroll_imports.attendance.persons.status.${person.match.status}`) }}</span>
          </div>
          <select :class="`${SELECT_CLASS} mt-2`" :value="selectValue(person)" :disabled="disabled || createKeys.includes(person.key)"
            :aria-label="t('payroll_imports.attendance.persons.employment_for', { name: person.display_name })"
            @change="onSelect(person, ($event.target as HTMLSelectElement).value)">
            <option value="">{{ noEmploymentLabel(person) }}</option>
            <option v-for="option in options" :key="option.employment_id" :value="String(option.employment_id)">{{ option.label }}</option>
          </select>
          <ul v-if="person.warnings.length" class="mt-2 space-y-0.5 text-xs text-warning-700">
            <li v-for="warning in person.warnings" :key="warning">{{ warning }}</li>
          </ul>
          <label v-if="canCreate && canBeCreated(person)" class="mt-2 inline-flex items-center gap-2 text-xs text-neutral-700">
            <input type="checkbox" :checked="createKeys.includes(person.key)" :disabled="disabled" @change="toggleCreate(person)">
            {{ t('payroll_imports.attendance.persons.create_label') }}
          </label>
        </article>
      </div>
    </section>

    <section v-if="createPersons.length" class="rounded-xl border border-payroll-500/30 bg-payroll-50 p-4 sm:p-6" data-testid="attendance-create-persons">
      <h3 class="font-semibold text-neutral-900">{{ t('payroll_imports.attendance.persons.create_title', { count: createPersons.length }) }}</h3>
      <p class="mt-1 max-w-3xl text-sm text-neutral-600">{{ t('payroll_imports.attendance.persons.create_hint') }}</p>

      <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_imports.attendance.persons.relation_type') }}</span>
          <select v-model="createForm.relation_type" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" :disabled="disabled">
            <option v-for="type in RELATION_TYPES" :key="type" :value="type">{{ t(`payroll_imports.relation_types.${type}`) }}</option>
          </select>
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_imports.attendance.persons.weekly_hours') }}</span>
          <input v-model="createForm.weekly_hours" inputmode="decimal" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" :disabled="disabled">
          <span class="mt-1 block text-[11px] text-neutral-500">{{ t('payroll_imports.attendance.persons.weekly_hours_hint') }}</span>
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_imports.attendance.persons.planned_start_on') }}</span>
          <DateInput v-model="createForm.planned_start_on" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" :disabled="disabled" />
        </label>
        <label class="inline-flex items-center gap-2 self-end pb-2 text-sm text-neutral-700">
          <input v-model="createForm.activate" type="checkbox" class="rounded border-neutral-300 text-payroll-600" :disabled="disabled">
          {{ t('payroll_imports.attendance.persons.activate') }}
        </label>
      </div>

      <ul class="mt-4 divide-y divide-payroll-500/10 rounded-lg border border-payroll-500/20 bg-surface">
        <li v-for="person in createPersons" :key="person.key" class="flex flex-wrap items-end gap-2 px-3 py-2">
          <p class="w-full text-xs text-neutral-500 sm:w-48 sm:self-center">{{ person.display_name }}</p>
          <dl class="order-last flex w-full flex-wrap gap-x-4 gap-y-0.5 text-[11px]" data-testid="attendance-create-details">
            <div v-for="detail in personCreateDetails(person, createForm.planned_start_on)" :key="detail.key" class="flex gap-1">
              <dt class="text-neutral-500">{{ t(`payroll_imports.attendance.persons.details.${detail.key}`) }}:</dt>
              <dd :class="detail.invalid ? 'text-warning-700' : 'text-neutral-800'">{{ detailValue(detail.key, detail.value, detail.invalid) }}</dd>
            </div>
          </dl>
          <label class="block min-w-36 flex-1">
            <span class="mb-0.5 block text-[11px] text-neutral-500">{{ t('payroll_imports.attendance.persons.first_name') }}</span>
            <input :value="draftFor(person).first_name" class="h-8 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm" :disabled="disabled"
              @input="setDraftField(person, 'first_name', ($event.target as HTMLInputElement).value)">
          </label>
          <label class="block min-w-36 flex-1">
            <span class="mb-0.5 block text-[11px] text-neutral-500">{{ t('payroll_imports.attendance.persons.last_name') }}</span>
            <input :value="draftFor(person).last_name" class="h-8 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm" :disabled="disabled"
              @input="setDraftField(person, 'last_name', ($event.target as HTMLInputElement).value)">
          </label>
          <button type="button" :class="btnIconSm('neutral')" :disabled="disabled"
            :title="t('payroll_imports.attendance.persons.swap_names')" :aria-label="t('payroll_imports.attendance.persons.swap_names')" @click="swapNames(person)">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.swap" /></svg>
          </button>
          <button type="button" :class="btnIconSm('danger')" :disabled="disabled"
            :title="t('payroll_imports.attendance.persons.remove_from_create')" :aria-label="t('payroll_imports.attendance.persons.remove_from_create')" @click="toggleCreate(person)">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
          </button>
        </li>
      </ul>

      <div class="mt-4 flex flex-col items-start gap-1.5">
        <button type="button" data-testid="attendance-create-submit" :class="btnFilled('primary')" :disabled="disabled || createBlockedReason !== ''"
          :title="disabledTitle(createBlockedReason !== '', createBlockedReason)" @click="submitCreate">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.user" /></svg>
          {{ t('payroll_imports.attendance.persons.create_submit', { count: createPersons.length }) }}
        </button>
        <p v-if="createBlockedReason" :class="BTN_DISABLED_NOTE">{{ createBlockedReason }}</p>
      </div>
    </section>
  </div>
</template>
