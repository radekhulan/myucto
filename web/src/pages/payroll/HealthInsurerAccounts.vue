<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { isAxiosError } from 'axios'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import {
  payrollApi,
  type PayrollInstitutionAccount,
  type PayrollInstitutionAccountCreatePayload,
  type PayrollInstitutionAccountSource,
  type PayrollInstitutionAccountUpdatePayload,
  type PayrollInstitutionType,
} from '@/api/payroll'
import { useToast } from '@/composables/useToast'
import { btnFilled, btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { healthInsurerName, healthInsurerOptions } from '@/utils/healthInsurers'
import { codeFromName } from '@/utils/slugifyCode'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import RequiredMark from '@/components/ui/RequiredMark.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { usePaneDom } from '@/composables/usePaneDom'

defineProps<{ canWrite: boolean }>()

const { t } = useI18n()
const toast = useToast()
const route = useRoute()
const paneDom = usePaneDom()
const loading = ref(true)
const loadFailed = ref(false)
const saving = ref(false)
const showCreate = ref(false)
const showValidation = ref(false)
const editingId = ref<number | null>(null)
const deletingId = ref<number | null>(null)
const conflictId = ref<number | null>(null)
const accounts = ref<PayrollInstitutionAccount[]>([])

const sources: PayrollInstitutionAccountSource[] = [
  'official_registry',
  'official_document',
  'institution_notice',
  'user_verified',
  'imported',
]
const institutionTypes: PayrollInstitutionType[] = [
  'health_insurer',
  'social_security',
  'tax_office',
  'statutory_insurance',
  'other_recipient',
]
/**
 * Účet a variabilní symbol jsou to, kvůli čemu se přehled otevírá — proto stojí
 * hned za institucí a nejdou schovat (účet) ani nejsou skryté ve výchozím stavu
 * (VS). Typ instituce je odvoditelný z názvu, takže se posunul za ně.
 */
const COLUMNS: ColumnDef[] = [
  { key: 'institution', labelKey: 'payroll.employer.health_accounts.institution', required: true },
  { key: 'account', labelKey: 'payroll.employer.health_accounts.account', required: true },
  { key: 'variable_symbol', labelKey: 'payroll.employer.health_accounts.variable_symbol' },
  { key: 'institution_type', labelKey: 'payroll.employer.health_accounts.institution_type' },
  { key: 'validity', labelKey: 'payroll.employer.health_accounts.validity' },
  { key: 'verification', labelKey: 'payroll.employer.health_accounts.verification', defaultHidden: true },
  { key: 'actions', labelKey: 'common.actions', required: true },
]
const tbl = useTablePrefs('payroll-health-insurer-accounts', COLUMNS)
const insurerOptions = healthInsurerOptions()
/**
 * Escape hatch pro kód mimo číselník. Seznam pojišťoven je v kódu, backend
 * u účtů institucí kód proti číselníku nevaliduje — kdyby byl výběr jediná
 * cesta, zanikla by možnost založit účet nově vzniklé (nebo přejmenované)
 * pojišťovny až do dalšího releasu. Výchozí stav je vypnutý, takže běžná cesta
 * je pořád výběr ze seznamu.
 */
const manualInsurerCode = ref(false)
const sourceOptions = computed(() => sources.map(source => ({
  value: source,
  label: sourceLabel(source),
})))
const institutionTypeOptions = computed(() => institutionTypes.map(type => ({
  value: type,
  label: institutionTypeLabel(type),
})))

function localToday(): string {
  const now = new Date()
  const year = now.getFullYear()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function emptyCreate(): PayrollInstitutionAccountCreatePayload {
  return {
    institution_type: 'health_insurer',
    institution_code: '',
    institution_name: '',
    bank_account: '',
    currency_code: 'CZK',
    variable_symbol: null,
    specific_symbol: null,
    constant_symbol: null,
    valid_from: localToday(),
    valid_to: null,
    source_kind: 'official_document',
    source_reference: '',
    verified_on: localToday(),
  }
}

const createForm = reactive(emptyCreate())
const editForm = reactive<PayrollInstitutionAccountUpdatePayload>({
  row_version: 0,
  institution_name: '',
  variable_symbol: null,
  specific_symbol: null,
  constant_symbol: null,
  valid_to: null,
  source_kind: 'official_document',
  source_reference: '',
  verified_on: localToday(),
})

const insurerPickerActive = computed(() =>
  createForm.institution_type === 'health_insurer' && !manualInsurerCode.value)
/**
 * Vybraná pojišťovna pro `SearchableSelect`. `null` znamená „ještě nevybráno";
 * `selectedOption` níže drží popisek i pro kód, který v číselníku není, aby se
 * hodnota nikdy tiše neztratila.
 */
const selectedInsurerCode = computed(() => {
  const code = createForm.institution_code.trim()
  return code === '' ? null : code
})
const selectedInsurerOption = computed(() => {
  const code = selectedInsurerCode.value
  if (code === null) return null
  const known = healthInsurerName(code)
  return { value: code, label: known === null ? code : `${code} — ${known}` }
})

const institutionAccounts = computed(() =>
  [...accounts.value].sort((left, right) =>
    institutionTypes.indexOf(left.institution_type) - institutionTypes.indexOf(right.institution_type)
    || left.institution_name.localeCompare(right.institution_name, 'cs'),
  ),
)

function nullable(value: string | null): string | null {
  const normalized = value?.trim() ?? ''
  return normalized === '' ? null : normalized
}

function validDate(value: string | null, required = true): boolean {
  if (value === null || value === '') return !required
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false
  const parsed = new Date(`${value}T00:00:00Z`)
  return !Number.isNaN(parsed.getTime()) && parsed.toISOString().slice(0, 10) === value
}

function symbolValid(value: string | null, maxLength: number, exact = false): boolean {
  const normalized = value?.trim() ?? ''
  if (normalized === '') return true
  return /^\d+$/.test(normalized)
    && normalized.length <= maxLength
    && (!exact || normalized.length === maxLength)
}

/**
 * Reference zdroje je nepovinná dohledávka (číslo sdělení pojišťovny apod.).
 * Povinný zůstává druh zdroje a datum ověření — ty nesou informaci, odkud účet
 * pochází a kdy byl naposledy potvrzený. Kontroluje se tedy jen délka.
 */
function commonValid(form: {
  institution_name: string
  variable_symbol: string | null
  specific_symbol: string | null
  constant_symbol: string | null
  valid_to: string | null
  source_reference: string
  verified_on: string
}): boolean {
  return form.institution_name.trim().length > 0
    && form.institution_name.trim().length <= 190
    && symbolValid(form.variable_symbol, 10)
    && symbolValid(form.specific_symbol, 10)
    && symbolValid(form.constant_symbol, 4, true)
    && validDate(form.valid_to, false)
    && form.source_reference.trim().length <= 500
    && validDate(form.verified_on)
    && form.verified_on <= localToday()
}

/**
 * Číslo účtu instituce je veřejný údaj, takže se ukazuje celé. Maskovaná podoba
 * zbývá jen pro záznam, u kterého se plaintext nepodařilo přečíst.
 */
function accountNumber(account: PayrollInstitutionAccount): string {
  return account.bank_account ?? account.bank_account_masked
}

const createValid = computed(() =>
  /^[A-Z0-9][A-Z0-9._/-]{0,31}$/.test(createForm.institution_code.trim().toUpperCase())
  && createForm.bank_account.trim().length > 0
  && validDate(createForm.valid_from)
  && (createForm.valid_to === null
    || createForm.valid_to === ''
    || (validDate(createForm.valid_to) && createForm.valid_to >= createForm.valid_from))
  && commonValid(createForm),
)

const editValid = computed(() => {
  const original = accounts.value.find(account => account.id === editingId.value)
  return original !== undefined
    && (editForm.valid_to === null
      || editForm.valid_to === ''
      || (validDate(editForm.valid_to) && editForm.valid_to >= original.valid_from))
    && commonValid(editForm)
})

function errorCode(error: unknown): string | null {
  return isAxiosError<{ error?: { code?: string } }>(error)
    ? error.response?.data?.error?.code ?? null
    : null
}

/** Hláška ze serveru má přednost: u blokovaného mazání říká, co účet drží. */
function errorMessage(error: unknown): string | null {
  const message = isAxiosError<{ error?: { message?: string } }>(error)
    ? error.response?.data?.error?.message ?? null
    : null
  return message === null || message.trim() === '' ? null : message
}

function showSaveError(error: unknown, fallbackKey: string) {
  const code = errorCode(error)
  if (code === 'institution_account_interval_overlap') {
    toast.error(t('payroll.employer.health_accounts.interval_overlap'))
    return
  }
  if (code === 'validation_failed') {
    toast.error(t('payroll.employer.health_accounts.validation_failed'))
    return
  }
  toast.error(t(fallbackKey))
}

async function loadAccounts() {
  loading.value = true
  loadFailed.value = false
  try {
    accounts.value = await payrollApi.institutionAccounts()
    conflictId.value = null
  } catch {
    loadFailed.value = true
    toast.error(t('payroll.employer.health_accounts.load_failed'))
  } finally {
    loading.value = false
  }
}

/**
 * Smazání účtu, ze kterého se nikdy neplatilo.
 *
 * PROČ VŮBEC: účet zadaný pod špatným kódem instituce se při přípravě plateb
 * nikdy nenajde, takže je to mrtvý řádek — jenže vedle správného stojí se
 * stejným číslem a účetní z dvojice nepozná, který platí. Ukončit platnost
 * nestačí, ukončený řádek zůstává v přehledu stát dál.
 *
 * Rozhoduje server (`can_delete`): vazbu na závazky a položky příkazu nedrží
 * cizí klíč, ale text v `recipient_reference`, takže odsud ji spolehlivě
 * dovodit nejde. Když smazat nejde, akce se neschovává ani nezešedne — důvod
 * se řekne až při pokusu, stejně jako u osoby a pracovního vztahu.
 */
async function removeAccount(account: PayrollInstitutionAccount) {
  if (deletingId.value !== null) return
  if (!account.can_delete) {
    toast.error(
      account.delete_blocker?.message
      ?? t('payroll.employer.health_accounts.delete_blocked'),
    )
    return
  }
  const question = t('payroll.employer.health_accounts.delete_confirm', {
    institution: account.institution_name,
    account: accountNumber(account),
  })
  if (!window.confirm(question)) return

  deletingId.value = account.id
  try {
    await payrollApi.deleteInstitutionAccount(account.id, account.row_version)
    accounts.value = accounts.value.filter(item => item.id !== account.id)
    if (editingId.value === account.id) editingId.value = null
    toast.success(t('payroll.employer.health_accounts.deleted'))
  } catch (error) {
    toast.error(
      errorMessage(error) ?? t('payroll.employer.health_accounts.delete_failed'),
    )
  } finally {
    deletingId.value = null
  }
}

function openCreate() {
  Object.assign(createForm, emptyCreate())
  manualInsurerCode.value = false
  showValidation.value = false
  showCreate.value = true
  editingId.value = null
}

function cancelCreate() {
  showCreate.value = false
  showValidation.value = false
}

function startEdit(account: PayrollInstitutionAccount) {
  Object.assign(editForm, {
    row_version: account.row_version,
    institution_name: account.institution_name,
    variable_symbol: account.variable_symbol,
    specific_symbol: account.specific_symbol,
    constant_symbol: account.constant_symbol,
    valid_to: account.valid_to,
    source_kind: account.source_kind,
    source_reference: account.source_reference,
    verified_on: account.verified_on,
  })
  editingId.value = account.id
  conflictId.value = null
  showValidation.value = false
  showCreate.value = false
}

function cancelEdit() {
  editingId.value = null
  conflictId.value = null
  showValidation.value = false
}

async function createAccount() {
  showValidation.value = true
  if (!createValid.value || saving.value) return
  saving.value = true
  try {
    const created = await payrollApi.createInstitutionAccount({
      ...createForm,
      institution_code: createForm.institution_code.trim().toUpperCase(),
      institution_name: createForm.institution_name.trim(),
      bank_account: createForm.bank_account.trim(),
      variable_symbol: nullable(createForm.variable_symbol),
      specific_symbol: nullable(createForm.specific_symbol),
      constant_symbol: nullable(createForm.constant_symbol),
      valid_to: nullable(createForm.valid_to),
      source_reference: createForm.source_reference.trim(),
    })
    accounts.value.push(created)
    showCreate.value = false
    showValidation.value = false
    Object.assign(createForm, emptyCreate())
    manualInsurerCode.value = false
    toast.success(t('payroll.employer.health_accounts.created'))
  } catch (error: unknown) {
    showSaveError(error, 'payroll.employer.health_accounts.create_failed')
  } finally {
    saving.value = false
  }
}

async function updateAccount() {
  showValidation.value = true
  const id = editingId.value
  if (id === null || !editValid.value || saving.value) return
  saving.value = true
  conflictId.value = null
  try {
    const updated = await payrollApi.updateInstitutionAccount(id, {
      ...editForm,
      institution_name: editForm.institution_name.trim(),
      variable_symbol: nullable(editForm.variable_symbol),
      specific_symbol: nullable(editForm.specific_symbol),
      constant_symbol: nullable(editForm.constant_symbol),
      valid_to: nullable(editForm.valid_to),
      source_reference: editForm.source_reference.trim(),
    })
    const index = accounts.value.findIndex(account => account.id === id)
    if (index !== -1) accounts.value[index] = updated
    editingId.value = null
    showValidation.value = false
    toast.success(t('payroll.employer.health_accounts.updated'))
  } catch (error: unknown) {
    if (errorCode(error) === 'row_version_conflict') {
      conflictId.value = id
    } else {
      showSaveError(error, 'payroll.employer.health_accounts.update_failed')
    }
  } finally {
    saving.value = false
  }
}

function sourceLabel(source: PayrollInstitutionAccountSource): string {
  return t(`payroll.employer.health_accounts.sources.${source}`)
}

function institutionTypeLabel(type: PayrollInstitutionType): string {
  return t(`payroll.employer.health_accounts.types.${type}`)
}

/**
 * Změna typu instituce mění i způsob zadání kódu, takže starý pár kód+název už
 * nedává smysl — u zdravotní pojišťovny by v poli zůstal kód finančního úřadu
 * a naopak. Proto se pár vyprázdní a ruční režim vrátí na výchozí výběr.
 */
function setCreateInstitutionType(value: PayrollInstitutionType | null) {
  if (value === null || value === createForm.institution_type) return
  createForm.institution_type = value
  createForm.institution_code = ''
  createForm.institution_name = ''
  manualInsurerCode.value = false
}

/** Výběr z číselníku doplní kód i název naráz — o to tady jde. */
function setCreateInsurer(value: string | null) {
  createForm.institution_code = value ?? ''
  createForm.institution_name = value === null ? '' : healthInsurerName(value) ?? ''
}

function enableManualInsurerCode() {
  manualInsurerCode.value = true
  manualCodeTouched.value = createForm.institution_code.trim() !== ''
}

/** true = uživatel do kódu instituce sáhl ručně, přestaň ho odvozovat z názvu. */
const manualCodeTouched = ref(false)

/**
 * Ruční větev (instituce mimo číselník): hlavní pole je NÁZEV, kód se z něj
 * odvodí sám, dokud ho uživatel nepřepíše. Kolizi s už zadanými účty řeší
 * suffix `_2`, ať to nespadne až na serverovou unikátní validaci.
 */
function onManualInstitutionName() {
  if (manualCodeTouched.value) return
  const taken = accounts.value.map(account => account.institution_code)
  createForm.institution_code = codeFromName(createForm.institution_name, taken, 32)
}

function onManualInstitutionCode() {
  manualCodeTouched.value = createForm.institution_code.trim() !== ''
}

function setCreateSource(value: PayrollInstitutionAccountSource | null) {
  if (value !== null) createForm.source_kind = value
}

function setEditSource(value: PayrollInstitutionAccountSource | null) {
  if (value !== null) editForm.source_kind = value
}

onMounted(async () => {
  await loadAccounts()
  await nextTick()
  if (route.hash === '#health-insurer-accounts') {
    paneDom.querySelector('#health-insurer-accounts')
      ?.scrollIntoView({ block: 'start' })
  }
})
</script>

<template>
  <section id="health-insurer-accounts" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6" style="scroll-margin-top: 6rem">
    <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.employer.health_accounts.title') }}</h2>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.employer.health_accounts.hint') }}</p>
      </div>
      <button v-if="canWrite && !showCreate" type="button" :class="btnFilled('primary')" @click="openCreate">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.plus" />
        </svg>
        {{ t('payroll.employer.health_accounts.add') }}
      </button>
    </div>

    <div v-if="loading" class="h-28 animate-pulse rounded-lg bg-neutral-100" />

    <div v-else-if="loadFailed" class="rounded-lg border border-danger-500/30 bg-danger-50 p-4">
      <p class="text-sm text-danger-700">{{ t('payroll.employer.health_accounts.load_failed') }}</p>
      <button type="button" :class="`${btnOutline('danger')} mt-3`" @click="loadAccounts">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
        {{ t('payroll.employer.retry') }}
      </button>
    </div>

    <template v-else>
      <div v-if="institutionAccounts.length === 0 && !showCreate" class="rounded-lg border border-dashed border-neutral-300 px-4 py-8 text-center">
        <p class="text-sm text-neutral-500">{{ t('payroll.employer.health_accounts.empty') }}</p>
      </div>

      <div v-if="institutionAccounts.length > 0" class="hidden md:block">
        <div class="mb-2 flex flex-wrap items-center justify-end gap-2">
          <ColumnPicker :ctrl="tbl" />
          <DensityToggle :ctrl="tbl" />
        </div>
        <div class="overflow-x-auto">
          <table class="min-w-[1080px] divide-y divide-neutral-200 text-sm" :class="tbl.densityClass.value">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                <th v-if="tbl.isVisible('institution')" class="px-3 py-2">{{ t('payroll.employer.health_accounts.institution') }}</th>
                <th v-if="tbl.isVisible('account')" class="px-3 py-2">{{ t('payroll.employer.health_accounts.account') }}</th>
                <th v-if="tbl.isVisible('variable_symbol')" class="px-3 py-2">{{ t('payroll.employer.health_accounts.variable_symbol') }}</th>
                <th v-if="tbl.isVisible('institution_type')" class="px-3 py-2">{{ t('payroll.employer.health_accounts.institution_type') }}</th>
                <th v-if="tbl.isVisible('validity')" class="px-3 py-2">{{ t('payroll.employer.health_accounts.validity') }}</th>
                <th v-if="tbl.isVisible('verification')" class="px-3 py-2">{{ t('payroll.employer.health_accounts.verification') }}</th>
                <th v-if="tbl.isVisible('actions')" class="px-3 py-2"><span class="sr-only">{{ t('common.actions') }}</span></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="account in institutionAccounts" :key="account.id">
                <td v-if="tbl.isVisible('institution')" class="px-3 py-3">
                  <p class="font-medium text-neutral-900">{{ account.institution_name }}</p>
                  <p class="font-mono text-xs text-neutral-500">{{ account.institution_code }}</p>
                </td>
                <td v-if="tbl.isVisible('account')" class="px-3 py-3">
                  <p class="font-mono text-base font-semibold text-neutral-900" data-testid="account-number">{{ accountNumber(account) }}</p>
                  <p class="text-xs text-neutral-500">{{ account.currency_code }}</p>
                </td>
                <td v-if="tbl.isVisible('variable_symbol')" class="px-3 py-3 font-mono font-medium text-neutral-900" data-testid="account-vs">{{ account.variable_symbol || '—' }}</td>
                <td v-if="tbl.isVisible('institution_type')" class="px-3 py-3 text-neutral-700">{{ institutionTypeLabel(account.institution_type) }}</td>
                <td v-if="tbl.isVisible('validity')" class="px-3 py-3 text-neutral-700">{{ account.valid_from }} – {{ account.valid_to || t('payroll.employer.health_accounts.open_ended') }}</td>
                <td v-if="tbl.isVisible('verification')" class="px-3 py-3">
                  <p class="text-neutral-700">{{ sourceLabel(account.source_kind) }}</p>
                  <p class="text-xs text-neutral-500">{{ account.verified_on }}<template v-if="account.source_reference"> · {{ account.source_reference }}</template></p>
                </td>
                <td v-if="tbl.isVisible('actions')" class="px-3 py-3">
                  <div v-if="canWrite" class="flex items-center gap-2">
                    <button type="button" :class="btnOutlineSm('neutral')" @click="startEdit(account)">
                      <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
                      {{ t('common.edit') }}
                    </button>
                    <button
                      type="button"
                      :class="btnOutlineSm('danger')"
                      :disabled="deletingId === account.id"
                      :title="t('payroll.employer.health_accounts.delete')"
                      :aria-label="t('payroll.employer.health_accounts.delete')"
                      data-test="delete-institution-account"
                      @click="removeAccount(account)"
                    >
                      <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="institutionAccounts.length > 0" class="grid grid-cols-1 gap-3 md:hidden">
        <article v-for="account in institutionAccounts" :key="`mobile-${account.id}`" class="rounded-lg border border-neutral-200 p-4">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="mb-1 text-xs font-medium uppercase tracking-wide text-payroll-700">{{ institutionTypeLabel(account.institution_type) }}</p>
              <h3 class="font-medium text-neutral-900">{{ account.institution_name }}</h3>
              <p class="font-mono text-xs text-neutral-500">{{ account.institution_code }}</p>
            </div>
            <div v-if="canWrite" class="flex items-center gap-2">
              <button type="button" :class="btnOutlineSm('neutral')" @click="startEdit(account)">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
                {{ t('common.edit') }}
              </button>
              <button
                type="button"
                :class="btnOutlineSm('danger')"
                :disabled="deletingId === account.id"
                :title="t('payroll.employer.health_accounts.delete')"
                :aria-label="t('payroll.employer.health_accounts.delete')"
                @click="removeAccount(account)"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>
              </button>
            </div>
          </div>
          <dl class="mt-3 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
            <div>
              <dt class="text-xs text-neutral-500">{{ t('payroll.employer.health_accounts.account') }}</dt>
              <dd class="font-mono text-base font-semibold text-neutral-900" data-testid="account-number-mobile">{{ accountNumber(account) }} <span class="text-xs font-normal text-neutral-500">{{ account.currency_code }}</span></dd>
            </div>
            <div>
              <dt class="text-xs text-neutral-500">{{ t('payroll.employer.health_accounts.variable_symbol') }}</dt>
              <dd class="font-mono font-medium text-neutral-900">{{ account.variable_symbol || '—' }}</dd>
            </div>
            <div>
              <dt class="text-xs text-neutral-500">{{ t('payroll.employer.health_accounts.validity') }}</dt>
              <dd class="text-neutral-700">{{ account.valid_from }} – {{ account.valid_to || t('payroll.employer.health_accounts.open_ended') }}</dd>
            </div>
            <div>
              <dt class="text-xs text-neutral-500">{{ t('payroll.employer.health_accounts.verification') }}</dt>
              <dd class="text-neutral-700">{{ sourceLabel(account.source_kind) }} · {{ account.verified_on }}</dd>
            </div>
          </dl>
          <p v-if="account.source_reference" class="mt-2 break-words text-xs text-neutral-500">{{ account.source_reference }}</p>
        </article>
      </div>

      <div v-if="showCreate" data-testid="health-account-create" class="mt-5 rounded-lg border border-payroll-500/30 bg-payroll-50/40 p-4 sm:p-5">
        <div class="mb-4">
          <h3 class="font-semibold text-neutral-900">{{ t('payroll.employer.health_accounts.create_title') }}</h3>
          <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.employer.health_accounts.create_hint') }}</p>
          <p class="mt-1 text-xs text-neutral-500">{{ t('payroll.employer.health_accounts.required_legend') }}</p>
        </div>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.institution_type') }}<RequiredMark /></span>
            <SearchableSelect
              data-testid="institution-create-type"
              :model-value="createForm.institution_type"
              :options="institutionTypeOptions"
              :clearable="false"
              :aria-label="t('payroll.employer.health_accounts.institution_type')"
              accent="payroll"
              @update:model-value="setCreateInstitutionType"
            />
          </label>
          <!-- Ne <label>: nese vlastní tlačítko a klik na něj by přes label
               zároveň otevřel nabídku výběru. Přístupný název dává aria-label. -->
          <div v-if="insurerPickerActive" class="block md:col-span-2 xl:col-span-2">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.insurer') }}<RequiredMark /></span>
            <SearchableSelect
              data-testid="health-create-insurer"
              :model-value="selectedInsurerCode"
              :options="insurerOptions"
              :selected-option="selectedInsurerOption"
              :placeholder="t('payroll.employer.health_accounts.select_insurer')"
              :no-results-label="t('payroll.employer.account_no_results')"
              :invalid="showValidation && selectedInsurerCode === null"
              :aria-label="t('payroll.employer.health_accounts.insurer')"
              accent="payroll"
              @update:model-value="setCreateInsurer"
            />
            <span class="mt-1 block text-xs text-neutral-500">
              {{ t('payroll.employer.health_accounts.insurer_hint') }}
              <button type="button" class="cursor-pointer font-medium text-payroll-700 underline" data-testid="health-create-manual-code" @click="enableManualInsurerCode">
                {{ t('payroll.employer.health_accounts.insurer_manual') }}
              </button>
            </span>
          </div>
          <template v-else>
            <label class="block">
              <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.institution_name') }}<RequiredMark /></span>
              <input v-model="createForm.institution_name" data-testid="health-create-name" type="text" maxlength="190" autocomplete="off" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20" @input="onManualInstitutionName">
            </label>
            <label class="block">
              <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.institution_code') }}<RequiredMark /></span>
              <input v-model="createForm.institution_code" data-testid="health-create-code" type="text" maxlength="32" autocomplete="off" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm uppercase outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20" @input="onManualInstitutionCode">
              <span class="mt-1 block text-xs text-neutral-500">{{ t('payroll.employer.health_accounts.institution_code_hint') }}</span>
            </label>
          </template>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.bank_account') }}<RequiredMark /></span>
            <input v-model="createForm.bank_account" data-testid="health-create-account" type="text" maxlength="191" autocomplete="off" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
            <span class="mt-1 block text-xs text-neutral-500">{{ t('payroll.employer.health_accounts.bank_account_hint') }}</span>
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.variable_symbol') }}</span>
            <input v-model="createForm.variable_symbol" data-testid="health-create-vs" type="text" inputmode="numeric" maxlength="10" autocomplete="off" :aria-invalid="showValidation && !symbolValid(createForm.variable_symbol, 10)" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.specific_symbol') }}</span>
            <input v-model="createForm.specific_symbol" type="text" inputmode="numeric" maxlength="10" autocomplete="off" :aria-invalid="showValidation && !symbolValid(createForm.specific_symbol, 10)" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.constant_symbol') }}</span>
            <input v-model="createForm.constant_symbol" type="text" inputmode="numeric" maxlength="4" autocomplete="off" :aria-invalid="showValidation && !symbolValid(createForm.constant_symbol, 4, true)" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.valid_from') }}<RequiredMark /></span>
            <input v-model="createForm.valid_from" type="date" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.valid_to') }}</span>
            <input v-model="createForm.valid_to" type="date" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.source') }}<RequiredMark /></span>
            <SearchableSelect
              :model-value="createForm.source_kind"
              :options="sourceOptions"
              :clearable="false"
              :aria-label="t('payroll.employer.health_accounts.source')"
              accent="payroll"
              @update:model-value="setCreateSource"
            />
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.source_reference') }}</span>
            <input v-model="createForm.source_reference" data-testid="health-create-source-reference" type="text" maxlength="500" autocomplete="off" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
            <span class="mt-1 block text-xs text-neutral-500">{{ t('payroll.employer.health_accounts.source_reference_hint') }}</span>
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.verified_on') }}<RequiredMark /></span>
            <input v-model="createForm.verified_on" type="date" :max="localToday()" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
          </label>
        </div>
        <p v-if="showValidation && !createValid" class="mt-3 text-sm text-danger-600" role="alert">{{ t('payroll.employer.health_accounts.validation') }}</p>
        <div class="mt-4 flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" :disabled="saving" @click="cancelCreate">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="button" :class="btnFilled('primary')" :disabled="saving" @click="createAccount">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
            {{ saving ? t('common.saving') : t('payroll.employer.health_accounts.create') }}
          </button>
        </div>
      </div>

      <div v-if="editingId !== null" data-testid="health-account-edit" class="mt-5 rounded-lg border border-neutral-300 bg-neutral-50 p-4 sm:p-5">
        <div class="mb-4">
          <h3 class="font-semibold text-neutral-900">{{ t('payroll.employer.health_accounts.edit_title') }}</h3>
          <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.employer.health_accounts.edit_hint') }}</p>
          <p class="mt-1 text-xs text-neutral-500">{{ t('payroll.employer.health_accounts.required_legend') }}</p>
        </div>
        <div v-if="conflictId === editingId" class="mb-4 rounded-md border border-warning-500/40 bg-warning-50 p-3 text-sm text-warning-700" role="alert">
          <p>{{ t('payroll.employer.health_accounts.conflict') }}</p>
          <button type="button" :class="`${btnOutline('warning')} mt-3`" @click="loadAccounts().then(cancelEdit)">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
            {{ t('payroll.employer.reload') }}
          </button>
        </div>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.institution_name') }}<RequiredMark /></span>
            <input v-model="editForm.institution_name" data-testid="health-edit-name" type="text" maxlength="190" autocomplete="off" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.variable_symbol') }}</span>
            <input v-model="editForm.variable_symbol" data-testid="health-edit-vs" type="text" inputmode="numeric" maxlength="10" autocomplete="off" :aria-invalid="showValidation && !symbolValid(editForm.variable_symbol, 10)" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.specific_symbol') }}</span>
            <input v-model="editForm.specific_symbol" type="text" inputmode="numeric" maxlength="10" autocomplete="off" :aria-invalid="showValidation && !symbolValid(editForm.specific_symbol, 10)" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.constant_symbol') }}</span>
            <input v-model="editForm.constant_symbol" type="text" inputmode="numeric" maxlength="4" autocomplete="off" :aria-invalid="showValidation && !symbolValid(editForm.constant_symbol, 4, true)" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.valid_to') }}</span>
            <input v-model="editForm.valid_to" type="date" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.source') }}<RequiredMark /></span>
            <SearchableSelect
              :model-value="editForm.source_kind"
              :options="sourceOptions"
              :clearable="false"
              :aria-label="t('payroll.employer.health_accounts.source')"
              accent="payroll"
              @update:model-value="setEditSource"
            />
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.source_reference') }}</span>
            <input v-model="editForm.source_reference" data-testid="health-edit-source-reference" type="text" maxlength="500" autocomplete="off" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
            <span class="mt-1 block text-xs text-neutral-500">{{ t('payroll.employer.health_accounts.source_reference_hint') }}</span>
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.health_accounts.verified_on') }}<RequiredMark /></span>
            <input v-model="editForm.verified_on" type="date" :max="localToday()" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20">
          </label>
        </div>
        <p v-if="showValidation && !editValid" class="mt-3 text-sm text-danger-600" role="alert">{{ t('payroll.employer.health_accounts.validation') }}</p>
        <div class="mt-4 flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" :disabled="saving" @click="cancelEdit">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="button" :class="btnFilled('primary')" :disabled="saving || conflictId === editingId" @click="updateAccount">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
            {{ saving ? t('common.saving') : t('common.save') }}
          </button>
        </div>
      </div>
    </template>
  </section>
</template>
