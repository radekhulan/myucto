<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollEmployment,
  type PayrollEmploymentTermsPayload,
  type PayrollPerson,
  type PayrollPersonProfile,
  type PayrollPersonProfilePayload,
  type PayrollPersonIdentifierType,
  type PayrollPersonQuickEditPayload,
  type PayrollPersonQuickEditResponse,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import type { PayrollPersonSensitiveReveal } from '@/api/payroll'
import RequiredMark from '@/components/ui/RequiredMark.vue'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { useToast } from '@/composables/useToast'
import { todayIso } from './employmentLifecycleUi'
import PayrollPersonContactQuickFields from './PayrollPersonContactQuickFields.vue'
import PayrollPersonIdentityQuickFields from './PayrollPersonIdentityQuickFields.vue'
import { addDaysIso } from '@/utils/date'

const props = defineProps<{
  personId: number
  canWrite: boolean
  // Oprávnění chodí propem stejně jako `canWrite` — komponenta o store nic neví.
  canReadSensitive?: boolean
}>()

const emit = defineEmits<{
  saved: [result: PayrollPersonQuickEditResponse]
}>()

interface QuickEditForm {
  first_name: string
  last_name: string
  birth_number: string
  ecp: string
  vcp: string
  foreign_tax_identifier: string
  street_line: string
  city: string
  postal_code: string
  country_code: string
  email: string
  phone: string
  weekly_hours: string
  monthly_gross: string
  employment_effective_from: string
}

const { t } = useI18n()
const toast = useToast()
const loading = ref(true)
const saving = ref(false)
/**
 * Karta se čte podstatně častěji, než se upravuje. Dřív byla trvale formulářem
 * se čtyřmi podsekcemi a odstavcem nápovědy u každé, takže i „kolik ten člověk
 * bere" znamenalo číst přes dvacet vstupních polí.
 */
const editing = ref(false)
const revealing = ref(false)
const revealed = ref<PayrollPersonSensitiveReveal | null>(null)
const loadError = ref('')
const saveError = ref('')
const profile = ref<PayrollPersonProfile | null>(null)
const person = ref<PayrollPerson | null>(null)
const primaryEmployment = ref<PayrollEmployment | null>(null)
const originalWeeklyHours = ref('')
const originalMonthlyGrossMinor = ref<number | null>(null)

const form = reactive<QuickEditForm>({
  first_name: '',
  last_name: '',
  birth_number: '',
  ecp: '',
  vcp: '',
  foreign_tax_identifier: '',
  street_line: '',
  city: '',
  postal_code: '',
  country_code: '',
  email: '',
  phone: '',
  weekly_hours: '',
  monthly_gross: '',
  employment_effective_from: todayIso(),
})

const inputClass = 'mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 placeholder:text-neutral-400 focus:border-payroll-500 focus:outline-none focus:ring-2 focus:ring-payroll-500/20 disabled:bg-neutral-100 disabled:text-neutral-500'
const labelClass = 'block text-xs font-medium text-neutral-600'
const writableEmployment = computed(() =>
  primaryEmployment.value !== null
  && ['planned', 'preregistered', 'active', 'suspended'].includes(
    primaryEmployment.value.status,
  ),
)
const employmentChanged = computed(() => {
  if (!writableEmployment.value) return false
  return normalizeHours(form.weekly_hours) !== normalizeHours(originalWeeklyHours.value)
    || amountMinor(form.monthly_gross) !== originalMonthlyGrossMinor.value
})

const currentIdentity = computed(() => {
  const rows = profile.value?.identity_history ?? []
  return rows.find(row => row.effective_to === null) ?? null
})
const residenceAddress = computed(() => {
  const rows = profile.value?.addresses.filter(row => row.address_type === 'residence') ?? []
  return rows.find(row => row.effective_to === null) ?? null
})
const primaryEmail = computed(() => preferredContact('email'))
const primaryPhone = computed(() => preferredContact('phone'))

/** Zobrazovaná hodnota: odkrytá, jinak maskovaná, jinak pomlčka. */
function identifierValue(type: PayrollPersonIdentifierType): string {
  const plain = revealed.value?.identifiers.find(row => row.identifier_type === type)
  if (plain) return plain.value
  const masked = profile.value?.identifiers.find(row => row.identifier_type === type)
  return masked?.value_masked ?? '—'
}

function contactValue(type: 'email' | 'phone'): string {
  const contact = preferredContact(type)
  if (contact === null) return '—'
  const plain = revealed.value?.contacts.find(row => row.id === contact.id)
  return plain?.value ?? contact.value_masked
}

const residenceValue = computed(() => {
  const address = residenceAddress.value
  if (address === null) return null
  const plain = revealed.value?.addresses.find(row => row.id === address.id)
  return plain?.address ?? address.address_masked
})

async function toggleReveal() {
  if (revealed.value !== null) {
    revealed.value = null
    return
  }
  if (revealing.value) return
  revealing.value = true
  try {
    revealed.value = await payrollApi.revealPersonSensitive(props.personId)
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.people.quick_edit.reveal_failed')))
  } finally {
    revealing.value = false
  }
}

function startEdit() {
  if (profile.value) hydrate(profile.value, person.value ?? undefined, primaryEmployment.value)
  editing.value = true
}

function cancelEdit() {
  if (profile.value) hydrate(profile.value, person.value ?? undefined, primaryEmployment.value)
  saveError.value = ''
  editing.value = false
}

function preferredContact(type: 'email' | 'phone') {
  const rows = profile.value?.contacts.filter(row =>
    row.contact_type === type && row.is_active,
  ) ?? []
  return rows.find(row => row.is_primary) ?? rows[0] ?? null
}

function primaryFrom(value: PayrollPerson): PayrollEmployment | null {
  const primary = value.employments.filter(item => item.is_primary)
  return primary.find(item =>
    ['planned', 'preregistered', 'active', 'suspended'].includes(item.status),
  ) ?? primary[0] ?? null
}

function nextTermsDate(employment: PayrollEmployment | null): string {
  const today = todayIso()
  const latest = employment?.terms[0]?.effective_from
  if (!latest || latest < today) return today
  return addDaysIso(latest, 1)
}

function minorToInput(value: number | null): string {
  if (value === null) return ''
  const whole = Math.trunc(value / 100)
  const fraction = Math.abs(value % 100)
  return fraction === 0 ? String(whole) : `${whole}.${String(fraction).padStart(2, '0')}`
}

function amountMinor(value: string): number | null {
  const normalized = value.trim().replace(',', '.')
  if (normalized === '') return null
  const match = /^(\d{1,10})(?:\.(\d{1,2}))?$/.exec(normalized)
  if (!match) return Number.NaN
  return Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'))
}

function normalizeHours(value: string): string {
  const normalized = value.trim().replace(',', '.')
  if (normalized === '') return ''
  const number = Number(normalized)
  return Number.isFinite(number) ? number.toFixed(2) : normalized
}

function hydrate(
  profileValue: PayrollPersonProfile,
  personValue?: PayrollPerson,
  employmentValue?: PayrollEmployment | null,
) {
  profile.value = profileValue
  if (personValue) person.value = personValue
  const employment = employmentValue === undefined
    ? (person.value ? primaryFrom(person.value) : null)
    : employmentValue
  primaryEmployment.value = employment

  const identity = profileValue.identity_history.find(row => row.effective_to === null)
  form.first_name = identity?.first_name ?? ''
  form.last_name = identity?.last_name ?? ''
  form.birth_number = ''
  form.ecp = ''
  form.vcp = ''
  form.foreign_tax_identifier = ''
  form.street_line = ''
  form.city = ''
  form.postal_code = ''
  form.country_code = ''
  form.email = ''
  form.phone = ''
  form.weekly_hours = employment?.terms[0]?.weekly_hours ?? ''
  form.monthly_gross = minorToInput(employment?.monthly_gross_minor ?? null)
  form.employment_effective_from = nextTermsDate(employment)
  originalWeeklyHours.value = form.weekly_hours
  originalMonthlyGrossMinor.value = employment?.monthly_gross_minor ?? null
}

async function load() {
  loading.value = true
  loadError.value = ''
  saveError.value = ''
  try {
    const [personValue, profileValue] = await Promise.all([
      payrollApi.person(props.personId),
      payrollApi.personProfile(props.personId),
    ])
    hydrate(profileValue, personValue)
  } catch (error) {
    loadError.value = apiErrorMessage(error, t('payroll.people.quick_edit.load_failed'))
  } finally {
    loading.value = false
  }
}

function optionalText(value: string): string | undefined {
  const trimmed = value.trim()
  return trimmed === '' ? undefined : trimmed
}

function previousDayIso(value: string): string {
  const date = new Date(`${value}T12:00:00Z`)
  date.setUTCDate(date.getUTCDate() - 1)
  return date.toISOString().slice(0, 10)
}

function profilePayload(): PayrollPersonProfilePayload {
  const value = profile.value
  if (!value) throw new Error('profile_missing')
  const fullName = `${form.first_name.trim()} ${form.last_name.trim()}`.trim()
  const identity = currentIdentity.value
  const address = residenceAddress.value
  const email = primaryEmail.value
  const phone = primaryPhone.value
  const hasAddressReplacement = [
    form.street_line,
    form.city,
    form.postal_code,
    form.country_code,
  ].some(item => item.trim() !== '')
  const changeDate = todayIso()
  const appendAddressVersion = address !== null
    && hasAddressReplacement
    && address.effective_from < changeDate
  const emailReplacement = optionalText(form.email)
  const phoneReplacement = optionalText(form.phone)
  const identityNameChanged = identity === null
    || form.first_name.trim() !== (identity.first_name ?? '').trim()
    || form.last_name.trim() !== (identity.last_name ?? '').trim()
  const appendIdentityVersion = identity !== null
    && identityNameChanged
    && identity.first_name !== null
    && identity.last_name !== null
    && identity.effective_from < changeDate
  const createIdentityVersion = identity === null || appendIdentityVersion

  return {
    row_version: value.row_version,
    profile_status: value.profile_status === 'missing' ? 'setup' : value.profile_status,
    payout_method: value.payout_method,
    partner_settlement_account_code: value.partner_settlement_account_code,
    cash_allocation_basis_points: value.cash_allocation_basis_points,
    payout_effective_on: value.payout_effective_on ?? todayIso(),
    secure_delivery_channel: value.secure_delivery_channel,
    identity_history: [
      ...value.identity_history.map(row => ({
          id: row.id,
          full_name: row.id === identity?.id && !appendIdentityVersion
            ? fullName
            : row.full_name,
          first_name: row.id === identity?.id && !appendIdentityVersion
            ? form.first_name.trim()
            : (row.first_name ?? ''),
          last_name: row.id === identity?.id && !appendIdentityVersion
            ? form.last_name.trim()
            : (row.last_name ?? ''),
          title_prefix: row.title_prefix,
          title_suffix: row.title_suffix,
          birth_date: row.birth_date,
          birth_place: row.birth_place,
          birth_country_code: row.birth_country_code,
          citizenship_country_code: row.citizenship_country_code,
          sex: row.sex,
          effective_from: row.effective_from,
          effective_to: row.id === identity?.id && appendIdentityVersion
            ? previousDayIso(changeDate)
            : row.effective_to,
        })),
      ...(createIdentityVersion
        ? [{
          full_name: fullName,
          first_name: form.first_name.trim(),
          last_name: form.last_name.trim(),
          title_prefix: identity?.title_prefix ?? null,
          title_suffix: identity?.title_suffix ?? null,
          ...(identity?.birth_surname_masked
            ? { birth_surname_source_id: identity.id }
            : {}),
          birth_date: identity?.birth_date ?? null,
          birth_place: identity?.birth_place ?? null,
          birth_country_code: identity?.birth_country_code ?? null,
          citizenship_country_code: identity?.citizenship_country_code ?? null,
          sex: identity?.sex ?? null,
          effective_from: changeDate,
          effective_to: null,
        }]
        : []),
    ],
    addresses: [
      ...value.addresses.map(row => ({
        id: row.id,
        address_type: row.address_type,
        ...(row.id === address?.id
          && hasAddressReplacement
          && !appendAddressVersion
          ? {
              street_line: form.street_line.trim(),
              city: form.city.trim(),
              postal_code: form.postal_code.trim(),
              country_code: form.country_code.trim().toUpperCase(),
            }
          : {}),
        effective_from: row.effective_from,
        effective_to: row.id === address?.id && appendAddressVersion
          ? previousDayIso(changeDate)
          : row.effective_to,
      })),
      ...((!address || appendAddressVersion) && hasAddressReplacement
        ? [{
            address_type: 'residence' as const,
            street_line: form.street_line.trim(),
            city: form.city.trim(),
            postal_code: form.postal_code.trim(),
            country_code: form.country_code.trim().toUpperCase(),
            effective_from: changeDate,
            effective_to: null,
          }]
        : []),
    ],
    contacts: [
      ...value.contacts.map(row => ({
        id: row.id,
        contact_type: row.contact_type,
        is_primary: (row.id === email?.id && emailReplacement !== undefined)
          || (row.id === phone?.id && phoneReplacement !== undefined)
          ? false
          : row.is_primary,
        is_active: (row.id === email?.id && emailReplacement !== undefined)
          || (row.id === phone?.id && phoneReplacement !== undefined)
          ? false
          : row.is_active,
      })),
      ...(emailReplacement !== undefined
        ? [{
            contact_type: 'email' as const,
            value: emailReplacement,
            is_primary: true,
            is_active: true,
          }]
        : []),
      ...(phoneReplacement !== undefined
        ? [{
            contact_type: 'phone' as const,
            value: phoneReplacement,
            is_primary: true,
            is_active: true,
          }]
        : []),
    ],
    identifiers: identifierPayloads(value),
    accounts: value.accounts.map(row => ({
      id: row.id,
      label: row.label,
      allocation_basis_points: row.allocation_basis_points,
      effective_from: row.effective_from,
      effective_to: row.effective_to,
      is_active: row.is_active,
    })),
  }
}

function identifierPayloads(value: PayrollPersonProfile) {
  const inputs: Record<PayrollPersonIdentifierType, string> = {
    birth_number: form.birth_number,
    ecp: form.ecp,
    vcp: form.vcp,
    foreign_tax_identifier: form.foreign_tax_identifier,
  }
  const existing = new Set(value.identifiers.map(row => row.identifier_type))

  return [
    ...value.identifiers.map(row => ({
      id: row.id,
      identifier_type: row.identifier_type,
      ...(optionalText(inputs[row.identifier_type]) !== undefined
        ? { value: inputs[row.identifier_type].trim() }
        : {}),
    })),
    ...Object.entries(inputs)
      .filter(([type, input]) =>
        !existing.has(type as PayrollPersonIdentifierType)
        && optionalText(input) !== undefined,
      )
      .map(([type, input]) => ({
        identifier_type: type as PayrollPersonIdentifierType,
        value: input.trim(),
      })),
  ]
}

function termsPayload(employment: PayrollEmployment): PayrollEmploymentTermsPayload {
  const terms = employment.terms[0]
  if (!terms) throw new Error('employment_terms_missing')
  return {
    office_id: terms.office_id,
    effective_from: form.employment_effective_from,
    contract_signed_on: terms.contract_signed_on,
    planned_start_on: terms.planned_start_on,
    actual_start_on: terms.actual_start_on,
    fixed_term_end_on: terms.fixed_term_end_on,
    weekly_hours: optionalText(form.weekly_hours) ?? null,
    workload_basis_points: terms.workload_basis_points,
    work_place: terms.work_place,
    regular_workplace: terms.regular_workplace,
    jmhz_workplace_municipality_code: terms.jmhz_workplace_municipality_code,
    jmhz_workplace_country_code: terms.jmhz_workplace_country_code,
    jmhz_apz_contribution_status: terms.jmhz_apz_contribution_status,
    jmhz_apz_instrument_code: terms.jmhz_apz_instrument_code,
    jmhz_functional_benefits_status: terms.jmhz_functional_benefits_status,
    jmhz_temporary_assignment_status: terms.jmhz_temporary_assignment_status,
    cz_isco_code: terms.cz_isco_code,
    activity_code: terms.activity_code,
    jmhz_relationship_detail_code: terms.jmhz_relationship_detail_code,
    social_insurance_participation: terms.social_insurance_participation,
    health_insurance_participation: terms.health_insurance_participation,
    tax_regime: terms.tax_regime,
    foreign_legislation_country_code: terms.foreign_legislation_country_code,
    a1_certificate_until: terms.a1_certificate_until,
    risky_work: terms.risky_work,
    tax_declaration_signed: terms.tax_declaration_signed,
    is_primary: terms.is_primary,
    change_reason: t('payroll.people.quick_edit.change_reason_default'),
  }
}

function validate(): boolean {
  if (form.first_name.trim() === '' || form.last_name.trim() === '') {
    saveError.value = t('payroll.people.quick_edit.name_required')
    return false
  }
  const selectedIdentity = currentIdentity.value
  const incompleteHistory = profile.value?.identity_history.some(row =>
    row.id !== selectedIdentity?.id
    && (!row.first_name?.trim() || !row.last_name?.trim()),
  ) ?? false
  if (incompleteHistory) {
    saveError.value = t('payroll.people.quick_edit.structured_history_required')
    return false
  }
  // Stát u české adresy nikdo needituje a prázdné pole vracelo „Vyplňte ulici,
  // obec, PSČ i stát." — čtvrtou povinnou položkou kvůli hodnotě, která je
  // ve výchozím nastavení vždy stejná. Dosadí se, místo aby blokovala uložení.
  const addressWithoutCountry = [
    form.street_line,
    form.city,
    form.postal_code,
  ].filter(item => item.trim() !== '').length
  if (addressWithoutCountry === 3 && form.country_code.trim() === '') {
    form.country_code = 'CZ'
  }
  const addressParts = addressWithoutCountry
    + (form.country_code.trim() === '' ? 0 : 1)
  if (addressParts !== 0 && addressParts !== 4) {
    saveError.value = t('payroll.people.quick_edit.address_complete_required')
    return false
  }
  const gross = amountMinor(form.monthly_gross)
  if (Number.isNaN(gross)) {
    saveError.value = t('payroll.people.quick_edit.gross_invalid')
    return false
  }
  if (employmentChanged.value
    && (!writableEmployment.value
      || !primaryEmployment.value?.terms[0]
      || form.employment_effective_from === '')
  ) {
    saveError.value = t('payroll.people.quick_edit.employment_unavailable')
    return false
  }

  return true
}

async function save() {
  if (saving.value || !profile.value || !validate()) return
  saveError.value = ''
  saving.value = true
  try {
    const employment = primaryEmployment.value
    const payload: PayrollPersonQuickEditPayload = {
      profile: profilePayload(),
      employment: employmentChanged.value && employment
        ? {
            id: employment.id,
            row_version: employment.row_version,
            monthly_gross_minor: amountMinor(form.monthly_gross),
            terms: termsPayload(employment),
          }
        : null,
    }
    const result = await payrollApi.savePersonQuickEdit(props.personId, payload)
    hydrate(result.profile, undefined, result.employment)
    // Uložené údaje se čtou, ne dál upravují — a odkrytá hodnota po zápisu
    // nemusí platit, takže se zahodí a případně načte znovu.
    revealed.value = null
    editing.value = false
    emit('saved', result)
    toast.success(t('payroll.people.quick_edit.saved'))
  } catch (error) {
    saveError.value = apiErrorMessage(error, t('payroll.people.quick_edit.save_failed'))
  } finally {
    saving.value = false
  }
}

watch(() => props.personId, load)
onMounted(load)
</script>

<template>
  <section class="rounded-xl border border-neutral-200 bg-surface shadow-sm" data-test="person-quick-edit">
    <header class="flex flex-wrap items-start justify-between gap-3 border-b border-neutral-200 px-4 py-4 sm:px-6">
      <div class="min-w-0">
        <h2 class="text-base font-semibold text-neutral-900">{{ t('payroll.people.quick_edit.title') }}</h2>
        <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.people.quick_edit.subtitle') }}</p>
      </div>
      <div v-if="!loading && profile && !editing" class="flex flex-wrap gap-2">
        <button
          v-if="canReadSensitive === true"
          type="button"
          :class="btnOutline('neutral')"
          :disabled="revealing"
          data-test="reveal-sensitive"
          @click="toggleReveal"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.eye" /></svg>
          {{ revealed ? t('payroll.people.quick_edit.hide_values') : t('payroll.people.quick_edit.reveal_values') }}
        </button>
        <button
          v-if="canWrite"
          type="button"
          :class="btnOutline('primary')"
          data-test="start-quick-edit"
          @click="startEdit"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
          {{ t('common.edit') }}
        </button>
      </div>
    </header>

    <div v-if="loading" class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2 sm:p-6 lg:grid-cols-4">
      <div v-for="index in 8" :key="index" class="h-16 animate-pulse rounded-lg bg-neutral-100" />
    </div>

    <div v-else-if="loadError || !profile" class="p-4 sm:p-6">
      <div class="rounded-lg border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700" role="alert">
        {{ loadError || t('payroll.people.quick_edit.load_failed') }}
      </div>
    </div>

    <!--
      Čtecí pohled. Maskovaná hodnota se dá odkrýt tlačítkem v hlavičce — dřív
      se odkrýt nedala vůbec, přestože backend to od začátku uměl.
    -->
    <dl v-else-if="!editing" class="grid grid-cols-1 gap-x-6 gap-y-4 p-4 sm:grid-cols-2 sm:p-6" data-test="quick-edit-read">
      <div>
        <dt class="text-xs font-medium text-neutral-500">{{ t('payroll.people.quick_edit.name') }}</dt>
        <dd class="mt-0.5 text-sm text-neutral-900">{{ profile.full_name || '—' }}</dd>
      </div>
      <div>
        <dt class="text-xs font-medium text-neutral-500">{{ t('payroll.people.quick_edit.birth_number') }}</dt>
        <dd class="mt-0.5 text-sm text-neutral-900" data-test="read-birth-number">{{ identifierValue('birth_number') }}</dd>
      </div>
      <div class="sm:col-span-2">
        <dt class="text-xs font-medium text-neutral-500">{{ t('payroll.people.quick_edit.residence') }}</dt>
        <dd class="mt-0.5 text-sm" :class="residenceValue ? 'text-neutral-900' : 'text-warning-700'" data-test="read-residence">
          {{ residenceValue ?? t('payroll.people.quick_edit.not_filled') }}
        </dd>
      </div>
      <div>
        <dt class="text-xs font-medium text-neutral-500">{{ t('payroll.people.quick_edit.email') }}</dt>
        <dd class="mt-0.5 break-all text-sm text-neutral-900" data-test="read-email">{{ contactValue('email') }}</dd>
      </div>
      <div>
        <dt class="text-xs font-medium text-neutral-500">{{ t('payroll.people.quick_edit.phone') }}</dt>
        <dd class="mt-0.5 text-sm text-neutral-900">{{ contactValue('phone') }}</dd>
      </div>
      <div v-if="primaryEmployment" class="sm:col-span-2 border-t border-neutral-200 pt-4">
        <dt class="text-xs font-medium text-neutral-500">{{ t('payroll.people.quick_edit.employment_title') }}</dt>
        <dd class="mt-0.5 text-sm text-neutral-900">
          {{ t(`payroll.people.relations.${primaryEmployment.relation_type}`) }}
          <template v-if="form.weekly_hours"> · {{ form.weekly_hours }} {{ t('payroll.people.quick_edit.hours_unit') }}</template>
          <template v-if="form.monthly_gross"> · {{ form.monthly_gross }} Kč</template>
        </dd>
      </div>
    </dl>

    <form v-else class="space-y-6 p-4 sm:p-6" @submit.prevent="save">
      <div
        v-if="saveError"
        class="rounded-lg border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700"
        data-test="quick-edit-error"
        role="alert"
      >
        {{ saveError }}
      </div>

      <!--
        Jedna věta místo hádání: hvězdička je jen u toho, bez čeho zápis
        neprojde. Zbytek karty (rodné číslo, bydliště, kontakty, úvazek, mzda)
        jde doplnit později a uložení to nedrží.
      -->
      <p class="text-xs text-neutral-500" data-test="quick-edit-required-hint">
        {{ t('payroll.people.quick_edit.required_hint') }}
      </p>

      <PayrollPersonIdentityQuickFields
        v-model:first-name="form.first_name"
        v-model:last-name="form.last_name"
        v-model:birth-number="form.birth_number"
        v-model:ecp="form.ecp"
        v-model:vcp="form.vcp"
        v-model:foreign-tax-identifier="form.foreign_tax_identifier"
        :identifiers="profile.identifiers"
        :disabled="!canWrite || saving"
      />

      <PayrollPersonContactQuickFields
        v-model:street-line="form.street_line"
        v-model:city="form.city"
        v-model:postal-code="form.postal_code"
        v-model:country-code="form.country_code"
        v-model:email="form.email"
        v-model:phone="form.phone"
        :current-address-masked="residenceAddress?.address_masked ?? null"
        :current-email-masked="primaryEmail?.value_masked ?? null"
        :current-phone-masked="primaryPhone?.value_masked ?? null"
        :disabled="!canWrite || saving"
      />

      <fieldset :disabled="!canWrite || saving || !writableEmployment" class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div>
            <legend class="text-sm font-semibold text-neutral-900">{{ t('payroll.people.quick_edit.employment_title') }}</legend>
            <p v-if="primaryEmployment" class="mt-1 text-xs text-neutral-500">
              {{ t(`payroll.people.relations.${primaryEmployment.relation_type}`) }} · {{ primaryEmployment.code }}
            </p>
          </div>
          <span v-if="primaryEmployment" class="rounded-full bg-payroll-50 px-2 py-1 text-xs font-medium text-payroll-700">
            {{ t(`payroll.people.employment_status.${primaryEmployment.status}`) }}
          </span>
        </div>
        <div
          v-if="!writableEmployment"
          class="rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-700"
        >
          {{ t('payroll.people.quick_edit.employment_unavailable') }}
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <label :class="labelClass">
            {{ t('payroll.people.quick_edit.weekly_hours') }}
            <input
              v-model="form.weekly_hours"
              inputmode="decimal"
              :class="inputClass"
              data-test="weekly-hours"
            >
          </label>
          <label :class="labelClass">
            {{ t('payroll.people.quick_edit.monthly_gross') }}
            <div class="relative">
              <input
                v-model="form.monthly_gross"
                inputmode="decimal"
                min="0"
                :class="[inputClass, 'pr-10']"
                data-test="monthly-gross"
              >
              <span class="pointer-events-none absolute right-3 top-1/2 mt-0.5 -translate-y-1/2 text-sm text-neutral-500">Kč</span>
            </div>
          </label>
          <!--
            Datum účinnosti řeší jen ten, kdo úvazek nebo mzdu opravdu mění.
            Trvale viditelné pole nutilo přemýšlet o verzování podmínek i toho,
            kdo si přišel opravit překlep v telefonu.
          -->
          <label v-if="employmentChanged" :class="labelClass" data-test="employment-effective-from-field">
            {{ t('payroll.people.quick_edit.effective_from') }} <RequiredMark />
            <input
              v-model="form.employment_effective_from"
              required
              type="date"
              :class="inputClass"
              data-test="employment-effective-from"
            >
            <span class="mt-1 block text-xs font-normal text-neutral-500">
              {{ t('payroll.people.quick_edit.employment_history_hint') }}
            </span>
          </label>
        </div>
      </fieldset>

      <div v-if="canWrite" class="flex flex-wrap justify-end gap-2 border-t border-neutral-200 pt-4">
        <button
          type="button"
          :class="btnOutline('neutral')"
          :disabled="saving"
          data-test="cancel-quick-edit"
          @click="cancelEdit"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.x" />
          </svg>
          {{ t('common.cancel') }}
        </button>
        <button
          type="submit"
          :class="btnFilled('primary')"
          :disabled="saving"
          data-test="save-quick-edit"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.check" />
          </svg>
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
      </div>
    </form>
  </section>
</template>
