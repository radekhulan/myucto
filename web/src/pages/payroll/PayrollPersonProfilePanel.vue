<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollPayoutAllocationKind,
  type PayrollPayoutDestinationKind,
  type PayrollPayoutMethod,
  type PayrollPayoutRule,
  type PayrollPayoutRuleProposal,
  type PayrollPayoutRuleProposalRule,
  type PayrollPayoutRulePayload,
  type PayrollPayoutRulesResponse,
  type PayrollPersonAccountVerificationSource,
  type PayrollPersonAddressType,
  type PayrollPersonContactType,
  type PayrollPersonEditableProfileStatus,
  type PayrollPersonIdentifierType,
  type PayrollPersonProfile,
  type PayrollPersonProfilePayload,
  type PayrollPersonSex,
  type PayrollRelationType,
  type PayrollSecureDeliveryChannel,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { btnFilled, btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import CountrySelect from '@/components/ui/CountrySelect.vue'
import RequiredMark from '@/components/ui/RequiredMark.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { useToast } from '@/composables/useToast'
import { usePaneDom } from '@/composables/usePaneDom'
import { todayIso } from './employmentLifecycleUi'

const props = defineProps<{
  personId: number
  canWrite: boolean
  relationTypes?: PayrollRelationType[]
}>()

const emit = defineEmits<{
  saved: [profile: PayrollPersonProfile]
}>()

type Tab = 'identity' | 'contacts' | 'payout'
type SelectOption<T extends string> = { value: T; label: string }

interface IdentityFormRow {
  id?: number
  full_name: string
  first_name: string
  last_name: string
  title_prefix: string
  title_suffix: string
  birth_surname_masked: string | null
  birth_surname: string
  birth_date: string
  birth_place: string
  birth_country_code: string
  citizenship_country_code: string
  sex: PayrollPersonSex | null
  effective_from: string
  effective_to: string
}

interface AddressFormRow {
  id?: number
  address_type: PayrollPersonAddressType
  address_masked: string
  street_line: string
  city: string
  postal_code: string
  country_code: string
  effective_from: string
  effective_to: string
}

interface ContactFormRow {
  id?: number
  contact_type: PayrollPersonContactType
  value_masked: string
  value: string
  is_primary: boolean
  is_active: boolean
}

interface IdentifierFormRow {
  id?: number
  identifier_type: PayrollPersonIdentifierType
  value_masked: string
  value: string
}

interface AccountFormRow {
  id?: number
  label: string
  bank_account_masked: string
  bank_account: string
  allocation_basis_points: number
  effective_from: string
  effective_to: string
  is_active: boolean
  verification_source: PayrollPersonAccountVerificationSource | null
  verified_on: string | null
  verified_by: number | null
  row_version: number
}

interface ProfileForm {
  profile_status: PayrollPersonEditableProfileStatus
  payout_method: PayrollPayoutMethod
  partner_settlement_account_code: string
  cash_allocation_basis_points: number
  payout_effective_on: string
  secure_delivery_channel: PayrollSecureDeliveryChannel
  row_version: number
  identity_history: IdentityFormRow[]
  addresses: AddressFormRow[]
  contacts: ContactFormRow[]
  identifiers: IdentifierFormRow[]
  accounts: AccountFormRow[]
}

interface VerificationForm {
  verification_source: PayrollPersonAccountVerificationSource
  verified_on: string
}

/**
 * Editovatelný řádek výplatního pravidla.
 *
 * Částka i procenta se drží v uživatelských jednotkách (Kč, %), do haléřů a
 * basis points se převádějí až v `payoutRulePayload()` — jinak by uživatel
 * v poli viděl 250000 místo 2 500 Kč.
 */
interface PayoutRuleFormRow {
  id?: number
  destination_kind: PayrollPayoutDestinationKind
  bank_account_id: number | null
  settlement_account_code: string
  allocation_kind: PayrollPayoutAllocationKind
  amount_czk: number | null
  percentage: number | null
  priority_no: number
  is_active: boolean
  /** `null` u hotovosti a zápočtu — ověření tam nedává smysl. */
  destination_verified: boolean | null
  row_version: number
}

const { t, locale } = useI18n()
const toast = useToast()
const paneDom = usePaneDom()
const loading = ref(true)
const saving = ref(false)
const profile = ref<PayrollPersonProfile | null>(null)
const tab = ref<Tab>('identity')
const verifyingAccountId = ref<number | null>(null)
const verificationForms = reactive<Record<number, VerificationForm>>({})
const form = reactive<ProfileForm>({
  profile_status: 'setup',
  payout_method: 'cash',
  partner_settlement_account_code: '',
  cash_allocation_basis_points: 10000,
  payout_effective_on: todayIso(),
  secure_delivery_channel: 'portal',
  row_version: 0,
  identity_history: [],
  addresses: [],
  contacts: [],
  identifiers: [],
  accounts: [],
})
// Pravidla nejsou součástí `form`: karta se ukládá jedním PUT, kdežto pravidla
// mají vlastní endpointy i vlastní row_version, a `hydrate()` po uložení karty
// celý `form` přepíše. Server verze slouží jako referenční stav pro diff.
const payoutRules = ref<PayrollPayoutRule[]>([])
const payoutProposal = ref<PayrollPayoutRuleProposal | null>(null)
const payoutRuleRows = ref<PayoutRuleFormRow[]>([])
const applyingPayoutDefaults = ref(false)

const inputClass = 'mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 focus:border-payroll-500 focus:outline-none focus:ring-2 focus:ring-payroll-500/20 disabled:bg-neutral-100 disabled:text-neutral-500'
const labelClass = 'block text-xs font-medium text-neutral-600'
const cardClass = 'rounded-lg border border-neutral-200 bg-surface p-3 sm:p-4'

const tabs: Tab[] = ['identity', 'contacts', 'payout']
// „Převzatá evidence" (`legacy`) si uživatel vybrat nemůže: je to značka
// jednorázového převodu ze starší agendy, ne stav, do kterého se dá přepnout.
// U profilů, které tu hodnotu v databázi mají, ji ale ukázat musíme — jinak by
// se v nabídce nic nevybralo a uložením by se ztratila.
const statusOptions = computed<SelectOption<PayrollPersonEditableProfileStatus>[]>(() => [
  ...(form.profile_status === 'legacy'
    ? [{ value: 'legacy' as const, label: t('payroll.people.profile.status.legacy') }]
    : []),
  { value: 'setup', label: t('payroll.people.profile.status.setup') },
  { value: 'ready', label: t('payroll.people.profile.status.ready') },
])
const deliveryOptions = computed<SelectOption<PayrollSecureDeliveryChannel>[]>(() => [
  { value: 'portal', label: t('payroll.people.profile.delivery.portal') },
  { value: 'paper', label: t('payroll.people.profile.delivery.paper') },
])
// Zápočet na účet společníka dává smysl jen u příjmu společníka a odměny za
// výkon funkce; běžnému zaměstnanci ho vůbec nenabízíme. Backend si to hlídá sám
// (PayrollPartnerSettlement), tohle je jen UI, aby volba nesvítila naprázdno.
const PARTNER_SETTLEMENT_RELATIONS: PayrollRelationType[] = ['partner_dependent', 'statutory_body']
const partnerSettlementAvailable = computed(() =>
  (props.relationTypes ?? []).some(type => PARTNER_SETTLEMENT_RELATIONS.includes(type)),
)
const isPartnerSettlement = computed(() => form.payout_method === 'partner_settlement')
const payoutOptions = computed<SelectOption<PayrollPayoutMethod>[]>(() => [
  { value: 'cash', label: t('payroll.people.profile.payout.cash') },
  { value: 'bank', label: t('payroll.people.profile.payout.bank') },
  { value: 'mixed', label: t('payroll.people.profile.payout.mixed') },
  // Už uloženou volbu necháme viditelnou i kdyby se vztahy mezitím změnily,
  // jinak by se karta nedala uložit bez tichého přepnutí způsobu výplaty.
  ...(partnerSettlementAvailable.value || form.payout_method === 'partner_settlement'
    ? [{
        value: 'partner_settlement' as const,
        label: t('payroll.people.profile.payout.partner_settlement'),
      }]
    : []),
])
const addressTypeOptions = computed<SelectOption<PayrollPersonAddressType>[]>(() => [
  { value: 'residence', label: t('payroll.people.profile.address_type.residence') },
  { value: 'mailing', label: t('payroll.people.profile.address_type.mailing') },
])
const contactTypeOptions = computed<SelectOption<PayrollPersonContactType>[]>(() => [
  { value: 'email', label: t('payroll.people.profile.contact_type.email') },
  { value: 'phone', label: t('payroll.people.profile.contact_type.phone') },
])
const identifierTypeOptions = computed<SelectOption<PayrollPersonIdentifierType>[]>(() => [
  { value: 'birth_number', label: t('payroll.people.profile.identifier_type.birth_number') },
  { value: 'ecp', label: t('payroll.people.profile.identifier_type.ecp') },
  { value: 'vcp', label: t('payroll.people.profile.identifier_type.vcp') },
  { value: 'foreign_tax_identifier', label: t('payroll.people.profile.identifier_type.foreign_tax_identifier') },
])
const sexOptions = computed<SelectOption<PayrollPersonSex>[]>(() => [
  { value: 'female', label: t('payroll.people.profile.sex.female') },
  { value: 'male', label: t('payroll.people.profile.sex.male') },
  { value: 'unspecified', label: t('payroll.people.profile.sex.unspecified') },
])
const verificationSourceOptions = computed<SelectOption<PayrollPersonAccountVerificationSource>[]>(() => [
  { value: 'employee_confirmation', label: t('payroll.people.profile.verification_source.employee_confirmation') },
  { value: 'bank_document', label: t('payroll.people.profile.verification_source.bank_document') },
  { value: 'user_verified', label: t('payroll.people.profile.verification_source.user_verified') },
])

const payoutDestinationOptions = computed<SelectOption<PayrollPayoutDestinationKind>[]>(() => [
  { value: 'bank', label: t('payroll.people.profile.payout_rules.destination_kind.bank') },
  { value: 'cash', label: t('payroll.people.profile.payout_rules.destination_kind.cash') },
  // Zápočet nabízíme za stejné podmínky jako u způsobu výplaty; už uložené
  // pravidlo necháme viditelné, aby nešlo tiše přepnout jinam.
  ...(partnerSettlementAvailable.value
    || payoutRuleRows.value.some(row => row.destination_kind === 'partner_settlement')
    ? [{
        value: 'partner_settlement' as const,
        label: t('payroll.people.profile.payout_rules.destination_kind.partner_settlement'),
      }]
    : []),
])
const payoutAllocationOptions = computed<SelectOption<PayrollPayoutAllocationKind>[]>(() => [
  { value: 'remainder', label: t('payroll.people.profile.payout_rules.allocation_kind.remainder') },
  { value: 'percentage', label: t('payroll.people.profile.payout_rules.allocation_kind.percentage') },
  { value: 'fixed', label: t('payroll.people.profile.payout_rules.allocation_kind.fixed') },
])
// Cílem smí být jen už uložený účet — `account:<id>` musí existovat v době
// zápisu pravidla, nový řádek účtu id dostane teprve uložením karty.
const payoutAccountOptions = computed<{ value: number; label: string; secondary?: string }[]>(() =>
  form.accounts
    .filter(account => account.id !== undefined && account.is_active)
    .map(account => ({
      value: account.id as number,
      label: account.label || account.bank_account_masked,
      secondary: account.bank_account_masked,
    })),
)
const hasActivePayoutRule = computed(() => payoutRuleRows.value.some(row => row.is_active))
const payoutProposalSummary = computed(() => {
  const proposed = payoutProposal.value?.rules[0]
  return proposed === undefined ? '' : payoutRuleSummary(proposalRuleRow(proposed))
})

function bankAccountIdFromReference(reference: string | null): number | null {
  const match = /^account:([1-9][0-9]*)$/.exec(reference ?? '')

  return match === null ? null : Number(match[1])
}

function toPayoutRuleRow(rule: PayrollPayoutRule): PayoutRuleFormRow {
  return {
    id: rule.id,
    destination_kind: rule.destination_kind,
    bank_account_id: bankAccountIdFromReference(rule.destination_reference),
    settlement_account_code: rule.destination_kind === 'partner_settlement'
      ? rule.destination_reference ?? ''
      : '',
    allocation_kind: rule.allocation_kind,
    amount_czk: rule.amount_minor === null ? null : rule.amount_minor / 100,
    percentage: rule.basis_points === null ? null : rule.basis_points / 100,
    priority_no: rule.priority_no,
    is_active: rule.is_active,
    destination_verified: rule.destination_verified,
    row_version: rule.row_version,
  }
}

/**
 * Varuje se jen u aktivního bankovního pravidla na neověřený účet.
 *
 * Server tentýž stav vrací i strojově v `warnings`, ale ta zpráva je česky;
 * panel je dvojjazyčný, takže si větu skládá sám z i18n nad `destination_verified`.
 * Zdroj pravdy je jeden — příznak z API.
 */
function payoutRuleNeedsVerification(row: PayoutRuleFormRow): boolean {
  return row.is_active && row.destination_verified === false
}

/**
 * Proklik na existující akci „Ověřit účet".
 *
 * Účty i pravidla jsou ve stejné záložce, takže stačí doscrollovat ke kartě
 * účtu a zaostřit na jeho tlačítko ověření — uživatel nemusí hledat, kde se
 * to dělá.
 */
function focusAccountVerification(accountId: number | null) {
  if (accountId === null) return
  const card = paneDom.querySelector(`#payout-account-${accountId}`)
  if (card === null) return
  card.scrollIntoView({ behavior: 'smooth', block: 'center' })
  const verify = card.querySelector<HTMLButtonElement>('[data-test="verify-account"]')
  verify?.focus({ preventScroll: true })
}

function proposalRuleRow(rule: PayrollPayoutRuleProposalRule): PayoutRuleFormRow {
  return toPayoutRuleRow({
    ...rule,
    id: 0,
    supplier_id: 0,
    employee_id: props.personId,
    allocation_reference: '',
    is_active: true,
    // Návrh se odvozuje jen z OVĚŘENÉHO účtu (server jinak vrátí blokující
    // důvod), takže varování u náhledu nikdy nedává smysl.
    destination_verified: null,
    row_version: 0,
    created_at: null,
    updated_at: null,
  })
}

function hydratePayoutRules(response: PayrollPayoutRulesResponse) {
  payoutRules.value = response.rules
  payoutProposal.value = response.proposal
  payoutRuleRows.value = response.rules.map(toPayoutRuleRow)
}

async function loadPayoutRules() {
  try {
    hydratePayoutRules(await payrollApi.personPayoutRules(props.personId))
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.people.profile.payout_rules.load_failed')))
  }
}

function formatCzk(value: number): string {
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'cs-CZ', {
    style: 'currency',
    currency: 'CZK',
    maximumFractionDigits: 2,
  }).format(value)
}

function formatPercent(value: number): string {
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'cs-CZ', {
    maximumFractionDigits: 2,
  }).format(value)
}

function payoutAccountLabel(row: PayoutRuleFormRow): string {
  if (row.bank_account_id === null) {
    return t('payroll.people.profile.payout_rules.account_missing')
  }
  const account = form.accounts.find(item => item.id === row.bank_account_id)

  return account === undefined
    ? t('payroll.people.profile.payout_rules.account_unknown', { id: row.bank_account_id })
    : `${account.label} · ${account.bank_account_masked}`
}

function payoutDestinationSummary(row: PayoutRuleFormRow): string {
  if (row.destination_kind === 'cash') {
    return t('payroll.people.profile.payout_rules.destination_kind.cash')
  }
  if (row.destination_kind === 'partner_settlement') {
    const code = row.settlement_account_code.trim()

    return `${t('payroll.people.profile.payout_rules.destination_kind.partner_settlement')} · `
      + (code === '' ? t('payroll.people.profile.payout_rules.account_missing') : code)
  }

  return `${t('payroll.people.profile.payout_rules.destination_kind.bank')} · ${payoutAccountLabel(row)}`
}

function payoutAllocationSummary(row: PayoutRuleFormRow): string {
  if (row.allocation_kind === 'percentage') {
    return t('payroll.people.profile.payout_rules.summary.percentage', {
      percent: formatPercent(row.percentage ?? 0),
    })
  }
  if (row.allocation_kind === 'fixed') {
    return t('payroll.people.profile.payout_rules.summary.fixed', {
      amount: formatCzk(row.amount_czk ?? 0),
    })
  }

  return t('payroll.people.profile.payout_rules.summary.remainder')
}

function payoutRuleSummary(row: PayoutRuleFormRow): string {
  return `${payoutAllocationSummary(row)} → ${payoutDestinationSummary(row)}`
}

function payoutRuleDestinationReference(row: PayoutRuleFormRow): string | null {
  if (row.destination_kind === 'cash') return null
  if (row.destination_kind === 'partner_settlement') {
    return row.settlement_account_code.trim().toUpperCase() || null
  }

  return row.bank_account_id === null ? null : `account:${row.bank_account_id}`
}

function payoutRulePayload(row: PayoutRuleFormRow, isActive: boolean): PayrollPayoutRulePayload {
  return {
    destination_kind: row.destination_kind,
    destination_reference: payoutRuleDestinationReference(row),
    allocation_kind: row.allocation_kind,
    // Server odmítne částku u procent i procenta u pevné částky, proto se
    // posílá vždycky jen ta hodnota, která k druhu alokace patří.
    amount_minor: row.allocation_kind === 'fixed'
      ? Math.round(Number(row.amount_czk ?? 0) * 100)
      : null,
    basis_points: row.allocation_kind === 'percentage'
      ? Math.round(Number(row.percentage ?? 0) * 100)
      : null,
    priority_no: Number(row.priority_no),
    is_active: isActive,
  }
}

function payoutRuleFieldsChanged(row: PayoutRuleFormRow, stored: PayrollPayoutRule): boolean {
  const next = payoutRulePayload(row, true)

  return next.destination_kind !== stored.destination_kind
    || next.destination_reference !== stored.destination_reference
    || next.allocation_kind !== stored.allocation_kind
    || (next.amount_minor ?? null) !== stored.amount_minor
    || (next.basis_points ?? null) !== stored.basis_points
    || next.priority_no !== stored.priority_no
}

function payoutRuleIsPendingDeactivation(row: PayoutRuleFormRow): boolean {
  return !row.is_active
    && payoutRules.value.some(rule => rule.id === row.id && rule.is_active)
}

function addPayoutRule() {
  const highestPriority = payoutRuleRows.value.reduce(
    (maximum, row) => Math.max(maximum, Number(row.priority_no)),
    0,
  )
  payoutRuleRows.value.push({
    destination_kind: payoutAccountOptions.value.length > 0 ? 'bank' : 'cash',
    bank_account_id: payoutAccountOptions.value[0]?.value ?? null,
    settlement_account_code: form.partner_settlement_account_code,
    allocation_kind: 'remainder',
    amount_czk: null,
    percentage: null,
    priority_no: highestPriority === 0 ? 100 : highestPriority + 10,
    is_active: true,
    // Ověření zná jen server; nový řádek ho dostane po uložení a přenačtení.
    destination_verified: null,
    row_version: 0,
  })
}

function removeUnsavedPayoutRule(index: number) {
  if (payoutRuleRows.value[index]?.id === undefined) payoutRuleRows.value.splice(index, 1)
}

/**
 * Odeslání změn pravidel — volá se z `save()`, ne z vlastního tlačítka.
 *
 * Pravidla mají vlastní endpointy a vlastní row_version, ale panel drží
 * konvenci jednoho společného „Uložit" (viz hlavička), takže se řádky editují
 * lokálně a odešlou se až tady. Deaktivace jde přes DELETE (server pravidlo
 * jen zneaktivní), případná souběžná změna ostatních polí přes PUT před ním.
 */
async function syncPayoutRules() {
  let changed = false
  try {
    for (const row of payoutRuleRows.value) {
      if (row.id === undefined) {
        await payrollApi.createPersonPayoutRule(
          props.personId,
          payoutRulePayload(row, row.is_active),
        )
        changed = true
        continue
      }
      const stored = payoutRules.value.find(rule => rule.id === row.id)
      if (stored === undefined) continue
      let rowVersion = stored.row_version
      if (payoutRuleFieldsChanged(row, stored) || (row.is_active && !stored.is_active)) {
        rowVersion = (await payrollApi.updatePersonPayoutRule(props.personId, row.id, {
          ...payoutRulePayload(row, true),
          row_version: rowVersion,
        })).rule.row_version
        changed = true
      }
      if (!row.is_active && stored.is_active) {
        await payrollApi.deactivatePersonPayoutRule(props.personId, row.id, rowVersion)
        changed = true
      }
    }
    if (changed) toast.success(t('payroll.people.profile.payout_rules.saved'))
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.people.profile.payout_rules.save_failed')))
  } finally {
    // Vždycky přenačíst: po částečně odeslané sadě by lokální row_version
    // neodpovídaly serveru a další uložení by spadlo na konflikt verzí.
    await loadPayoutRules()
  }
}

async function applyPayoutDefaults() {
  if (applyingPayoutDefaults.value || !props.canWrite) return
  applyingPayoutDefaults.value = true
  try {
    hydratePayoutRules(await payrollApi.applyPersonPayoutRuleDefaults(props.personId))
    toast.success(t('payroll.people.profile.payout_rules.defaults_applied'))
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.people.profile.payout_rules.defaults_failed')))
  } finally {
    applyingPayoutDefaults.value = false
  }
}

function hydrate(value: PayrollPersonProfile) {
  profile.value = value
  form.profile_status = value.profile_status === 'missing' ? 'setup' : value.profile_status
  form.payout_method = value.payout_method
  form.partner_settlement_account_code = value.partner_settlement_account_code ?? ''
  form.cash_allocation_basis_points = value.cash_allocation_basis_points
  form.payout_effective_on = value.payout_effective_on ?? todayIso()
  form.secure_delivery_channel = value.secure_delivery_channel
  form.row_version = value.row_version
  form.identity_history = value.identity_history.map(row => ({
    id: row.id,
    full_name: row.full_name,
    first_name: row.first_name ?? '',
    last_name: row.last_name ?? '',
    title_prefix: row.title_prefix ?? '',
    title_suffix: row.title_suffix ?? '',
    birth_surname_masked: row.birth_surname_masked,
    birth_surname: '',
    birth_date: row.birth_date ?? '',
    birth_place: row.birth_place ?? '',
    birth_country_code: row.birth_country_code ?? '',
    citizenship_country_code: row.citizenship_country_code ?? '',
    sex: row.sex ?? null,
    effective_from: row.effective_from,
    effective_to: row.effective_to ?? '',
  }))
  form.addresses = value.addresses.map(row => ({
    id: row.id,
    address_type: row.address_type,
    address_masked: row.address_masked,
    street_line: '',
    city: '',
    postal_code: '',
    country_code: '',
    effective_from: row.effective_from,
    effective_to: row.effective_to ?? '',
  }))
  form.contacts = value.contacts.map(row => ({
    id: row.id,
    contact_type: row.contact_type,
    value_masked: row.value_masked,
    value: '',
    is_primary: row.is_primary,
    is_active: row.is_active,
  }))
  form.identifiers = value.identifiers.map(row => ({
    id: row.id,
    identifier_type: row.identifier_type,
    value_masked: row.value_masked,
    value: '',
  }))
  form.accounts = value.accounts.map(row => ({
    id: row.id,
    label: row.label,
    bank_account_masked: row.bank_account_masked,
    bank_account: '',
    allocation_basis_points: row.allocation_basis_points,
    effective_from: row.effective_from,
    effective_to: row.effective_to ?? '',
    is_active: row.is_active,
    verification_source: row.verification_source ?? null,
    verified_on: row.verified_on ?? null,
    verified_by: row.verified_by ?? null,
    row_version: row.row_version,
  }))
  for (const key of Object.keys(verificationForms)) delete verificationForms[Number(key)]
  for (const account of form.accounts) {
    if (account.id) {
      verificationForms[account.id] = {
        verification_source: account.verification_source ?? 'user_verified',
        verified_on: account.verified_on ?? todayIso(),
      }
    }
  }
}

function clearPlaintextInputs() {
  for (const row of form.identity_history) row.birth_surname = ''
  for (const row of form.addresses) {
    row.street_line = ''
    row.city = ''
    row.postal_code = ''
    row.country_code = ''
  }
  for (const row of form.contacts) row.value = ''
  for (const row of form.identifiers) row.value = ''
  for (const row of form.accounts) row.bank_account = ''
}

async function load() {
  loading.value = true
  try {
    const [loaded] = await Promise.all([
      payrollApi.personProfile(props.personId),
      loadPayoutRules(),
    ])
    hydrate(loaded)
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.people.profile.load_failed')))
  } finally {
    loading.value = false
  }
}

function optionalValue(value: string): string | undefined {
  const trimmed = value.trim()
  return trimmed === '' ? undefined : trimmed
}

function payload(): PayrollPersonProfilePayload {
  return {
    row_version: form.row_version,
    profile_status: form.profile_status,
    payout_method: form.payout_method,
    partner_settlement_account_code: isPartnerSettlement.value
      ? form.partner_settlement_account_code.trim().toUpperCase()
      : null,
    cash_allocation_basis_points: Number(form.cash_allocation_basis_points),
    payout_effective_on: form.payout_effective_on,
    secure_delivery_channel: form.secure_delivery_channel,
    identity_history: form.identity_history.map(row => ({
      ...(row.id ? { id: row.id } : {}),
      full_name: row.full_name,
      first_name: row.first_name,
      last_name: row.last_name,
      title_prefix: optionalValue(row.title_prefix) ?? null,
      title_suffix: optionalValue(row.title_suffix) ?? null,
      ...(optionalValue(row.birth_surname) !== undefined
        ? { birth_surname: optionalValue(row.birth_surname) }
        : {}),
      birth_date: optionalValue(row.birth_date) ?? null,
      birth_place: optionalValue(row.birth_place) ?? null,
      birth_country_code: optionalValue(row.birth_country_code) ?? null,
      citizenship_country_code: optionalValue(row.citizenship_country_code) ?? null,
      sex: row.sex,
      effective_from: row.effective_from,
      effective_to: optionalValue(row.effective_to) ?? null,
    })),
    addresses: form.addresses.map(row => {
      const replacesAddress = [
        row.street_line,
        row.city,
        row.postal_code,
        row.country_code,
      ].some(value => optionalValue(value) !== undefined)
      return {
        ...(row.id ? { id: row.id } : {}),
        address_type: row.address_type,
        ...(replacesAddress
          ? {
              street_line: row.street_line.trim(),
              city: row.city.trim(),
              postal_code: row.postal_code.trim(),
              country_code: row.country_code.trim(),
            }
          : {}),
        effective_from: row.effective_from,
        effective_to: optionalValue(row.effective_to) ?? null,
      }
    }),
    contacts: form.contacts.map(row => ({
      ...(row.id ? { id: row.id } : {}),
      contact_type: row.contact_type,
      ...(optionalValue(row.value) !== undefined ? { value: row.value.trim() } : {}),
      is_primary: row.is_primary,
      is_active: row.is_active,
    })),
    identifiers: form.identifiers.map(row => ({
      ...(row.id ? { id: row.id } : {}),
      identifier_type: row.identifier_type,
      ...(optionalValue(row.value) !== undefined ? { value: row.value.trim() } : {}),
    })),
    accounts: form.accounts.map(row => ({
      ...(row.id ? { id: row.id } : {}),
      label: row.label,
      ...(optionalValue(row.bank_account) !== undefined
        ? { bank_account: row.bank_account.trim() }
        : {}),
      allocation_basis_points: Number(row.allocation_basis_points),
      effective_from: row.effective_from,
      effective_to: optionalValue(row.effective_to) ?? null,
      is_active: row.is_active,
    })),
  }
}

async function save() {
  if (saving.value) return
  saving.value = true
  try {
    const saved = await payrollApi.savePersonProfile(props.personId, payload())
    hydrate(saved)
    emit('saved', saved)
    toast.success(t('payroll.people.profile.saved'))
    // Výplatní pravidla se odesílají AŽ TEĎ, jedním společným „Uložit" v hlavičce
    // panelu — vlastní tlačítko u každého řádku by rozbilo konvenci vícesekčního
    // editoru. Pořadí je navíc věcné: nově přidaný výplatní účet dostane id
    // teprve uložením karty a teprve pak na něj může pravidlo `account:<id>`
    // ukázat.
    await syncPayoutRules()
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.people.profile.save_failed')))
  } finally {
    clearPlaintextInputs()
    saving.value = false
  }
}

async function verifyAccount(account: AccountFormRow) {
  if (!account.id
    || verifyingAccountId.value !== null
    || accountHasUnsavedChanges(account)
  ) return
  const verification = verificationForms[account.id]
  if (!verification) return
  verifyingAccountId.value = account.id
  try {
    const saved = await payrollApi.verifyPersonAccount(props.personId, account.id, {
      ...verification,
      row_version: account.row_version,
    })
    account.verification_source = saved.verification_source ?? verification.verification_source
    account.verified_on = saved.verified_on ?? verification.verified_on
    account.verified_by = saved.verified_by ?? null
    account.row_version = saved.row_version
    toast.success(t('payroll.people.profile.account_verified'))
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.people.profile.verification_failed')))
  } finally {
    verifyingAccountId.value = null
  }
}

function accountHasUnsavedChanges(account: AccountFormRow): boolean {
  if (account.bank_account.trim() !== '' || !account.id) return true
  const stored = profile.value?.accounts.find(
    item => item.id === account.id,
  )
  if (!stored) return true

  return account.label !== stored.label
    || Number(account.allocation_basis_points)
      !== stored.allocation_basis_points
    || account.effective_from !== stored.effective_from
    || (account.effective_to || null) !== stored.effective_to
    || account.is_active !== stored.is_active
}

function hasRegistrationIdentity(row: IdentityFormRow): boolean {
  return [
    row.title_prefix,
    row.title_suffix,
    row.birth_date,
    row.birth_place,
    row.birth_country_code,
    row.citizenship_country_code,
    row.sex,
  ].some(value => value !== null && value.trim() !== '')
}

function addIdentity() {
  form.identity_history.unshift({
    full_name: profile.value?.full_name ?? '',
    first_name: '',
    last_name: '',
    title_prefix: '',
    title_suffix: '',
    birth_surname_masked: null,
    birth_surname: '',
    birth_date: '',
    birth_place: '',
    birth_country_code: '',
    citizenship_country_code: '',
    sex: null,
    effective_from: todayIso(),
    effective_to: '',
  })
}

function addAddress() {
  form.addresses.unshift({
    address_type: 'residence',
    address_masked: '',
    street_line: '',
    city: '',
    postal_code: '',
    country_code: 'CZ',
    effective_from: todayIso(),
    effective_to: '',
  })
}

function addContact() {
  form.contacts.unshift({
    contact_type: 'email',
    value_masked: '',
    value: '',
    is_primary: false,
    is_active: true,
  })
}

function addIdentifier() {
  form.identifiers.unshift({
    identifier_type: 'birth_number',
    value_masked: '',
    value: '',
  })
}

function addAccount() {
  form.accounts.unshift({
    label: '',
    bank_account_masked: '',
    bank_account: '',
    allocation_basis_points: 10000,
    effective_from: todayIso(),
    effective_to: '',
    is_active: true,
    verification_source: null,
    verified_on: null,
    verified_by: null,
    row_version: 0,
  })
}

function removeUnsaved<T extends { id?: number }>(rows: T[], index: number) {
  if (!rows[index]?.id) rows.splice(index, 1)
}

watch(() => props.personId, load)
onMounted(load)
</script>

<template>
  <section class="rounded-xl border border-neutral-200 bg-surface shadow-sm" data-test="person-profile">
    <header class="flex flex-wrap items-start justify-between gap-3 border-b border-neutral-200 px-4 py-4 sm:px-6">
      <div>
        <h2 class="text-base font-semibold text-neutral-900">{{ t('payroll.people.profile.title') }}</h2>
        <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.people.profile.subtitle') }}</p>
      </div>
      <button
        v-if="canWrite && !loading && profile"
        type="button"
        :class="btnFilled('primary')"
        :disabled="saving"
        data-test="save-profile"
        @click="save"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.check" />
        </svg>
        {{ t('common.save') }}
      </button>
    </header>

    <div v-if="loading" class="space-y-3 p-4 sm:p-6">
      <div v-for="index in 3" :key="index" class="h-20 animate-pulse rounded-lg bg-neutral-100" />
    </div>
    <div v-else-if="!profile" class="p-6 text-sm text-neutral-500">
      {{ t('payroll.people.profile.load_failed') }}
    </div>
    <form v-else class="p-4 sm:p-6" @submit.prevent="save">
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <label :class="labelClass">
          {{ t('payroll.people.profile.profile_status') }}
          <SearchableSelect
            v-model="form.profile_status"
            class="mt-1"
            :options="statusOptions"
            :clearable="false"
            :disabled="!canWrite"
            accent="payroll"
          />
        </label>
        <label :class="labelClass">
          {{ t('payroll.people.profile.delivery_channel') }}
          <SearchableSelect
            v-model="form.secure_delivery_channel"
            class="mt-1"
            :options="deliveryOptions"
            :clearable="false"
            :disabled="!canWrite"
            accent="payroll"
          />
        </label>
      </div>

      <nav class="mb-5 mt-6 flex flex-wrap gap-1 border-b border-neutral-200" :aria-label="t('payroll.people.profile.tabs.label')">
        <button
          v-for="name in tabs"
          :key="name"
          type="button"
          class="-mb-px cursor-pointer whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition-colors"
          :class="tab === name
            ? 'border-payroll-600 text-payroll-600'
            : 'border-transparent text-neutral-600 hover:border-neutral-300 hover:text-neutral-900'"
          :aria-selected="tab === name"
          role="tab"
          @click="tab = name"
        >
          {{ t(`payroll.people.profile.tabs.${name}`) }}
        </button>
      </nav>

      <div v-if="tab === 'identity'" class="space-y-6">
        <section>
          <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <div>
              <h3 class="font-semibold text-neutral-900">{{ t('payroll.people.profile.identity_title') }}</h3>
              <p class="text-xs text-neutral-500">{{ t('payroll.people.profile.mask_hint') }}</p>
            </div>
            <button v-if="canWrite" type="button" :class="btnFilled('primary')" @click="addIdentity">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
              {{ t('payroll.people.profile.add_identity') }}
            </button>
          </div>
          <div class="space-y-3">
            <article v-for="(row, index) in form.identity_history" :key="row.id ?? `new-identity-${index}`" :class="cardClass">
              <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-4">
                <label :class="labelClass">{{ t('payroll.people.profile.first_name') }} <RequiredMark /><input v-model="row.first_name" required autocomplete="given-name" :disabled="!canWrite" :class="inputClass"></label>
                <label :class="labelClass">{{ t('payroll.people.profile.last_name') }} <RequiredMark /><input v-model="row.last_name" required autocomplete="family-name" :disabled="!canWrite" :class="inputClass"></label>
                <label :class="[labelClass, 'lg:col-span-2']">{{ t('payroll.people.profile.full_name') }} <RequiredMark /><input v-model="row.full_name" required autocomplete="name" :disabled="!canWrite" :class="inputClass"></label>
                <label :class="labelClass">{{ t('payroll.people.profile.effective_from') }} <RequiredMark /><input v-model="row.effective_from" required type="date" :disabled="!canWrite" :class="inputClass"></label>
                <label :class="labelClass">{{ t('payroll.people.profile.effective_to') }}<input v-model="row.effective_to" type="date" :disabled="!canWrite" :class="inputClass"></label>
                <div v-if="row.birth_surname_masked" class="lg:col-span-2">
                  <span class="text-xs text-neutral-500">{{ t('payroll.people.profile.current_masked') }}</span>
                  <p class="mt-1 font-mono text-sm text-neutral-800">{{ row.birth_surname_masked }}</p>
                </div>
                <label v-if="canWrite" :class="[labelClass, 'lg:col-span-2']">{{ t('payroll.people.profile.new_birth_surname') }}<input v-model="row.birth_surname" autocomplete="off" :class="inputClass" :placeholder="t('payroll.people.profile.keep_masked')"></label>
              </div>
              <details
                class="group mt-3 rounded-md border border-payroll-200 bg-neutral-50"
                data-test="registration-identity-details"
              >
                <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-2 px-3 py-2">
                  <span class="flex min-w-0 items-start gap-2">
                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-neutral-500 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
                    <span class="min-w-0">
                      <span class="block text-xs font-semibold text-neutral-900">{{ t('payroll.people.profile.registration_identity_title') }}</span>
                      <span class="mt-0.5 block text-xs text-neutral-500">{{ t('payroll.people.profile.registration_identity_hint') }}</span>
                    </span>
                  </span>
                  <span
                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="hasRegistrationIdentity(row) ? 'bg-success-50 text-success-700' : 'bg-neutral-100 text-neutral-600'"
                  >{{ t(hasRegistrationIdentity(row)
                    ? 'payroll.people.profile.registration_identity_filled'
                    : 'payroll.people.profile.registration_identity_empty') }}</span>
                </summary>
                <div class="grid grid-cols-1 gap-3 border-t border-neutral-200 p-3 md:grid-cols-2 lg:grid-cols-4">
                  <label :class="labelClass">
                    {{ t('payroll.people.profile.title_prefix') }}
                    <input v-model="row.title_prefix" autocomplete="honorific-prefix" maxlength="64" :disabled="!canWrite" :class="inputClass">
                  </label>
                  <label :class="labelClass">
                    {{ t('payroll.people.profile.title_suffix') }}
                    <input v-model="row.title_suffix" autocomplete="honorific-suffix" maxlength="64" :disabled="!canWrite" :class="inputClass">
                  </label>
                  <label :class="labelClass">
                    {{ t('payroll.people.profile.birth_date') }}
                    <input v-model="row.birth_date" type="date" :max="todayIso()" :disabled="!canWrite" :class="inputClass" data-test="identity-birth-date">
                  </label>
                  <label :class="labelClass">
                    {{ t('payroll.people.profile.sex_label') }}
                    <SearchableSelect
                      v-model="row.sex"
                      class="mt-1"
                      :options="sexOptions"
                      :clearable="true"
                      :disabled="!canWrite"
                      accent="payroll"
                      data-test="identity-sex"
                    />
                  </label>
                  <label :class="labelClass">
                    {{ t('payroll.people.profile.birth_place') }}
                    <input v-model="row.birth_place" maxlength="128" :disabled="!canWrite" :class="inputClass">
                  </label>
                  <label :class="labelClass">
                    {{ t('payroll.people.profile.birth_country') }}
                    <CountrySelect
                      v-model="row.birth_country_code"
                      class="mt-1"
                      :disabled="!canWrite"
                      accent="payroll"
                      data-test="identity-birth-country"
                    />
                  </label>
                  <label :class="labelClass">
                    {{ t('payroll.people.profile.citizenship_country') }}
                    <CountrySelect
                      v-model="row.citizenship_country_code"
                      class="mt-1"
                      :disabled="!canWrite"
                      accent="payroll"
                      data-test="identity-citizenship-country"
                    />
                  </label>
                  <p class="self-end text-xs text-neutral-500 lg:col-span-1">
                    {{ t('payroll.people.profile.registration_identity_effective_hint') }}
                  </p>
                </div>
              </details>
              <div v-if="canWrite && !row.id" class="mt-3 flex justify-end">
                <button type="button" :class="btnOutlineSm('danger')" @click="removeUnsaved(form.identity_history, index)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>{{ t('common.remove') }}</button>
              </div>
            </article>
          </div>
        </section>

        <section>
          <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-semibold text-neutral-900">{{ t('payroll.people.profile.addresses_title') }}</h3>
            <button v-if="canWrite" type="button" :class="btnFilled('primary')" @click="addAddress">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
              {{ t('payroll.people.profile.add_address') }}
            </button>
          </div>
          <div class="space-y-3">
            <article v-for="(row, index) in form.addresses" :key="row.id ?? `new-address-${index}`" :class="cardClass">
              <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-4">
                <label :class="labelClass">{{ t('payroll.people.profile.address_type_label') }}<SearchableSelect v-model="row.address_type" class="mt-1" :options="addressTypeOptions" :clearable="false" :disabled="!canWrite" accent="payroll" /></label>
                <label :class="labelClass">{{ t('payroll.people.profile.effective_from') }} <RequiredMark /><input v-model="row.effective_from" required type="date" :disabled="!canWrite" :class="inputClass"></label>
                <label :class="labelClass">{{ t('payroll.people.profile.effective_to') }}<input v-model="row.effective_to" type="date" :disabled="!canWrite" :class="inputClass"></label>
                <div v-if="row.address_masked"><span class="text-xs text-neutral-500">{{ t('payroll.people.profile.current_masked') }}</span><p class="mt-1 text-sm text-neutral-800">{{ row.address_masked }}</p></div>
                <template v-if="canWrite">
                  <label :class="labelClass">{{ t('payroll.people.profile.street') }} <RequiredMark v-if="!row.id" /><input v-model="row.street_line" :required="!row.id" autocomplete="off" :class="inputClass"></label>
                  <label :class="labelClass">{{ t('payroll.people.profile.city') }} <RequiredMark v-if="!row.id" /><input v-model="row.city" :required="!row.id" autocomplete="off" :class="inputClass"></label>
                  <label :class="labelClass">{{ t('payroll.people.profile.postal_code') }} <RequiredMark v-if="!row.id" /><input v-model="row.postal_code" :required="!row.id" autocomplete="off" :class="inputClass"></label>
                  <label :class="labelClass">
                    {{ t('payroll.people.profile.country_code') }} <RequiredMark v-if="!row.id" />
                    <CountrySelect
                      v-model="row.country_code"
                      class="mt-1"
                      :required="!row.id"
                      :disabled="!canWrite"
                      accent="payroll"
                      data-test="profile-country-code"
                    />
                  </label>
                </template>
              </div>
              <p v-if="canWrite && row.id" class="mt-2 text-xs text-neutral-500">{{ t('payroll.people.profile.address_replace_hint') }}</p>
              <div v-if="canWrite && !row.id" class="mt-3 flex justify-end"><button type="button" :class="btnOutlineSm('danger')" @click="removeUnsaved(form.addresses, index)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>{{ t('common.remove') }}</button></div>
            </article>
          </div>
        </section>
      </div>

      <div v-else-if="tab === 'contacts'" class="space-y-6">
        <section>
          <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-semibold text-neutral-900">{{ t('payroll.people.profile.contacts_title') }}</h3>
            <button v-if="canWrite" type="button" :class="btnFilled('primary')" @click="addContact"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>{{ t('payroll.people.profile.add_contact') }}</button>
          </div>
          <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
            <article v-for="(row, index) in form.contacts" :key="row.id ?? `new-contact-${index}`" :class="cardClass">
              <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <label :class="labelClass">{{ t('payroll.people.profile.contact_type_label') }}<SearchableSelect v-model="row.contact_type" class="mt-1" :options="contactTypeOptions" :clearable="false" :disabled="!canWrite || Boolean(row.id)" accent="payroll" /></label>
                <div v-if="row.value_masked"><span class="text-xs text-neutral-500">{{ t('payroll.people.profile.current_masked') }}</span><p class="mt-2 font-mono text-sm text-neutral-800">{{ row.value_masked }}</p></div>
                <label v-if="canWrite" :class="[labelClass, 'sm:col-span-2']">{{ t('payroll.people.profile.new_value') }} <RequiredMark v-if="!row.id" /><input v-model="row.value" :required="!row.id" autocomplete="off" :class="inputClass" :placeholder="row.id ? t('payroll.people.profile.keep_masked') : ''"></label>
                <label class="flex items-center gap-2 text-sm text-neutral-700"><input v-model="row.is_primary" type="checkbox" :disabled="!canWrite" class="rounded border-neutral-300 text-payroll-600">{{ t('payroll.people.profile.primary_contact') }}</label>
                <label class="flex items-center gap-2 text-sm text-neutral-700"><input v-model="row.is_active" type="checkbox" :disabled="!canWrite" class="rounded border-neutral-300 text-payroll-600">{{ t('payroll.people.profile.active') }}</label>
              </div>
              <div v-if="canWrite && !row.id" class="mt-3 flex justify-end"><button type="button" :class="btnOutlineSm('danger')" @click="removeUnsaved(form.contacts, index)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>{{ t('common.remove') }}</button></div>
            </article>
          </div>
        </section>

        <section>
          <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-semibold text-neutral-900">{{ t('payroll.people.profile.identifiers_title') }}</h3>
            <button v-if="canWrite" type="button" :class="btnFilled('primary')" @click="addIdentifier"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>{{ t('payroll.people.profile.add_identifier') }}</button>
          </div>
          <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
            <article v-for="(row, index) in form.identifiers" :key="row.id ?? `new-identifier-${index}`" :class="cardClass">
              <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <label :class="labelClass">{{ t('payroll.people.profile.identifier_type_label') }}<SearchableSelect v-model="row.identifier_type" class="mt-1" :options="identifierTypeOptions" :clearable="false" :disabled="!canWrite || Boolean(row.id)" accent="payroll" /></label>
                <div v-if="row.value_masked"><span class="text-xs text-neutral-500">{{ t('payroll.people.profile.current_masked') }}</span><p class="mt-2 font-mono text-sm text-neutral-800">{{ row.value_masked }}</p></div>
                <label v-if="canWrite" :class="[labelClass, 'sm:col-span-2']">{{ t('payroll.people.profile.new_value') }} <RequiredMark v-if="!row.id" /><input v-model="row.value" :required="!row.id" autocomplete="off" :class="inputClass" :placeholder="row.id ? t('payroll.people.profile.keep_masked') : ''"></label>
              </div>
              <div v-if="canWrite && !row.id" class="mt-3 flex justify-end"><button type="button" :class="btnOutlineSm('danger')" @click="removeUnsaved(form.identifiers, index)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>{{ t('common.remove') }}</button></div>
            </article>
          </div>
        </section>
      </div>

      <div v-else class="space-y-6">
        <section class="grid grid-cols-1 gap-4 md:grid-cols-3">
          <label :class="labelClass">{{ t('payroll.people.profile.payout_method') }}<SearchableSelect v-model="form.payout_method" class="mt-1" :options="payoutOptions" :clearable="false" :disabled="!canWrite" accent="payroll" /></label>
          <label :class="labelClass">{{ t('payroll.people.profile.cash_allocation') }} <RequiredMark /><input v-model.number="form.cash_allocation_basis_points" required type="number" min="0" max="10000" :disabled="!canWrite" :class="inputClass"></label>
          <label :class="labelClass">{{ t('payroll.people.profile.payout_effective_on') }} <RequiredMark /><input v-model="form.payout_effective_on" required type="date" :disabled="!canWrite" :class="inputClass"></label>
        </section>

        <section v-if="isPartnerSettlement" class="rounded-lg border border-payroll-200 bg-payroll-50/40 p-3 sm:p-4">
          <p class="text-xs text-neutral-600">{{ t('payroll.people.profile.partner_settlement_hint') }}</p>
          <label :class="[labelClass, 'mt-3 block max-w-xs']">{{ t('payroll.people.profile.partner_settlement_account') }} <RequiredMark /><input v-model="form.partner_settlement_account_code" required type="text" maxlength="10" :placeholder="t('payroll.people.profile.partner_settlement_account_placeholder')" :disabled="!canWrite" :class="[inputClass, 'font-mono uppercase']"></label>
          <p class="mt-1 text-xs text-neutral-500">{{ t('payroll.people.profile.partner_settlement_account_hint') }}</p>
        </section>
        <p v-else-if="!partnerSettlementAvailable" class="text-xs text-neutral-500">{{ t('payroll.people.profile.partner_settlement_unavailable') }}</p>

        <section>
          <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <div><h3 class="font-semibold text-neutral-900">{{ t('payroll.people.profile.accounts_title') }}</h3><p class="text-xs text-neutral-500">{{ t('payroll.people.profile.account_hint') }}</p></div>
            <button v-if="canWrite" type="button" :class="btnFilled('primary')" @click="addAccount"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>{{ t('payroll.people.profile.add_account') }}</button>
          </div>
          <div class="space-y-3">
            <article
              v-for="(row, index) in form.accounts"
              :id="row.id ? `payout-account-${row.id}` : undefined"
              :key="row.id ?? `new-account-${index}`"
              :class="cardClass"
            >
              <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-4">
                <label :class="labelClass">{{ t('payroll.people.profile.account_label') }} <RequiredMark /><input v-model="row.label" required :disabled="!canWrite" :class="inputClass"></label>
                <label :class="labelClass">{{ t('payroll.people.profile.account_allocation') }} <RequiredMark /><input v-model.number="row.allocation_basis_points" required type="number" min="0" max="10000" :disabled="!canWrite" :class="inputClass"></label>
                <label :class="labelClass">{{ t('payroll.people.profile.effective_from') }} <RequiredMark /><input v-model="row.effective_from" required type="date" :disabled="!canWrite" :class="inputClass"></label>
                <label :class="labelClass">{{ t('payroll.people.profile.effective_to') }}<input v-model="row.effective_to" type="date" :disabled="!canWrite" :class="inputClass"></label>
                <div v-if="row.bank_account_masked"><span class="text-xs text-neutral-500">{{ t('payroll.people.profile.current_masked') }}</span><p class="mt-2 font-mono text-sm text-neutral-800">{{ row.bank_account_masked }}</p></div>
                <label v-if="canWrite" :class="[labelClass, 'lg:col-span-2']">{{ t('payroll.people.profile.new_bank_account') }} <RequiredMark v-if="!row.id" /><input v-model="row.bank_account" :required="!row.id" autocomplete="off" :class="inputClass" :placeholder="row.id ? t('payroll.people.profile.keep_masked') : t('payroll.people.profile.bank_account_placeholder')" data-test="bank-account-plaintext"></label>
                <label class="flex items-center gap-2 text-sm text-neutral-700"><input v-model="row.is_active" type="checkbox" :disabled="!canWrite" class="rounded border-neutral-300 text-payroll-600">{{ t('payroll.people.profile.active') }}</label>
              </div>

              <div v-if="row.id" class="mt-4 rounded-lg border border-neutral-200 bg-neutral-50 p-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <div>
                    <p class="text-sm font-medium text-neutral-800">{{ t('payroll.people.profile.account_verification') }}</p>
                    <p v-if="row.verified_on && row.verification_source" class="mt-1 text-xs text-success-600" data-test="verification-status">
                      {{ t('payroll.people.profile.verified_summary', {
                        date: row.verified_on,
                        source: t(`payroll.people.profile.verification_source.${row.verification_source}`),
                      }) }}
                    </p>
                    <p v-else class="mt-1 text-xs text-warning-700">{{ t('payroll.people.profile.not_verified') }}</p>
                  </div>
                </div>
                <div v-if="canWrite && row.is_active && verificationForms[row.id]" class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-[minmax(0,1fr)_minmax(0,12rem)_auto] md:items-end">
                  <label :class="labelClass">{{ t('payroll.people.profile.verification_source_label') }}<SearchableSelect v-model="verificationForms[row.id].verification_source" class="mt-1" :options="verificationSourceOptions" :clearable="false" accent="payroll" /></label>
                  <label :class="labelClass">{{ t('payroll.people.profile.verified_on') }} <RequiredMark /><input v-model="verificationForms[row.id].verified_on" required type="date" :class="inputClass"></label>
                  <button type="button" :class="btnOutline('success')" :disabled="verifyingAccountId !== null || accountHasUnsavedChanges(row)" data-test="verify-account" @click="verifyAccount(row)">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.badgeCheck" /></svg>
                    {{ t('payroll.people.profile.verify_account') }}
                  </button>
                  <p v-if="accountHasUnsavedChanges(row)" class="text-xs text-warning-700 md:col-span-3">
                    {{ t('payroll.people.profile.save_before_verify') }}
                  </p>
                </div>
              </div>
              <div v-if="canWrite && !row.id" class="mt-3 flex justify-end"><button type="button" :class="btnOutlineSm('danger')" @click="removeUnsaved(form.accounts, index)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>{{ t('common.remove') }}</button></div>
            </article>
          </div>
        </section>

        <section data-test="payout-rules">
          <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <div>
              <h3 class="font-semibold text-neutral-900">{{ t('payroll.people.profile.payout_rules.title') }}</h3>
              <p class="text-xs text-neutral-500">{{ t('payroll.people.profile.payout_rules.hint') }}</p>
            </div>
            <button v-if="canWrite" type="button" :class="btnFilled('primary')" data-test="add-payout-rule" @click="addPayoutRule">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
              {{ t('payroll.people.profile.payout_rules.add') }}
            </button>
          </div>

          <div
            v-if="!hasActivePayoutRule"
            class="mb-3 rounded-lg border border-warning-200 bg-warning-50 px-3 py-2 text-sm text-warning-700 dark:bg-warning-500/[0.06]"
            data-test="payout-rules-missing"
          >
            <p class="font-semibold">{{ t('payroll.people.profile.payout_rules.missing_title') }}</p>
            <p class="mt-1">{{ t('payroll.people.profile.payout_rules.missing_hint') }}</p>
          </div>

          <div v-if="payoutProposal" class="mb-3 rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div>
                <p class="text-sm font-medium text-neutral-800">{{ t('payroll.people.profile.payout_rules.defaults_title') }}</p>
                <p v-if="payoutProposal.applicable" class="mt-1 text-xs text-neutral-600" data-test="payout-defaults-preview">
                  {{ t('payroll.people.profile.payout_rules.defaults_preview', { summary: payoutProposalSummary }) }}
                </p>
                <p v-else-if="payoutProposal.blocked_reason" class="mt-1 text-xs text-neutral-600" data-test="payout-defaults-blocked">
                  {{ payoutProposal.blocked_reason }}
                </p>
              </div>
              <button
                v-if="canWrite && payoutProposal.applicable"
                type="button"
                :class="btnOutline('primary')"
                :disabled="applyingPayoutDefaults || saving"
                data-test="apply-payout-defaults"
                @click="applyPayoutDefaults"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.checkCircle" /></svg>
                {{ t('payroll.people.profile.payout_rules.apply_defaults') }}
              </button>
            </div>
          </div>

          <p v-if="payoutRuleRows.length === 0" class="text-sm text-neutral-500">{{ t('payroll.people.profile.payout_rules.empty') }}</p>
          <div v-else class="space-y-3">
            <article
              v-for="(row, index) in payoutRuleRows"
              :key="row.id ?? `new-payout-rule-${index}`"
              :class="[cardClass, row.is_active ? '' : 'border-dashed bg-neutral-50']"
              data-test="payout-rule"
            >
              <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
                <div>
                  <p class="text-sm font-medium text-neutral-800" data-test="payout-rule-summary">{{ payoutRuleSummary(row) }}</p>
                  <p class="mt-0.5 text-xs text-neutral-500">{{ t('payroll.people.profile.payout_rules.priority_summary', { priority: row.priority_no }) }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                  <span
                    class="rounded px-1.5 py-0.5 text-xs font-medium"
                    :class="row.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'"
                  >
                    {{ row.is_active ? t('payroll.people.profile.payout_rules.state_active') : t('payroll.people.profile.payout_rules.state_inactive') }}
                  </span>
                  <button
                    v-if="canWrite && row.id !== undefined && row.is_active"
                    type="button"
                    :class="btnOutlineSm('danger')"
                    data-test="deactivate-payout-rule"
                    @click="row.is_active = false"
                  >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
                    {{ t('payroll.people.profile.payout_rules.deactivate') }}
                  </button>
                  <button
                    v-else-if="canWrite && row.id !== undefined"
                    type="button"
                    :class="btnOutlineSm('neutral')"
                    data-test="reactivate-payout-rule"
                    @click="row.is_active = true"
                  >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
                    {{ t('payroll.people.profile.payout_rules.reactivate') }}
                  </button>
                  <button
                    v-else-if="canWrite"
                    type="button"
                    :class="btnOutlineSm('danger')"
                    @click="removeUnsavedPayoutRule(index)"
                  >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
                    {{ t('common.remove') }}
                  </button>
                </div>
              </div>
              <p v-if="payoutRuleIsPendingDeactivation(row)" class="mb-3 text-xs text-warning-700" data-test="payout-rule-pending-deactivation">
                {{ t('payroll.people.profile.payout_rules.pending_deactivation') }}
              </p>

              <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-4">
                <label :class="labelClass">
                  {{ t('payroll.people.profile.payout_rules.destination') }}
                  <SearchableSelect v-model="row.destination_kind" class="mt-1" :options="payoutDestinationOptions" :clearable="false" :disabled="!canWrite" accent="payroll" />
                </label>
                <label v-if="row.destination_kind === 'bank'" :class="[labelClass, 'lg:col-span-2']">
                  {{ t('payroll.people.profile.payout_rules.account') }}
                  <SearchableSelect
                    v-model="row.bank_account_id"
                    class="mt-1"
                    :options="payoutAccountOptions"
                    :clearable="false"
                    :disabled="!canWrite"
                    :placeholder="t('payroll.people.profile.payout_rules.account_placeholder')"
                    accent="payroll"
                    data-test="payout-rule-account"
                  />
                </label>
                <label v-else-if="row.destination_kind === 'partner_settlement'" :class="[labelClass, 'lg:col-span-2']">
                  {{ t('payroll.people.profile.payout_rules.settlement_account') }}
                  <input v-model="row.settlement_account_code" required type="text" maxlength="10" :disabled="!canWrite" :class="[inputClass, 'font-mono uppercase']">
                </label>
                <label :class="labelClass">
                  {{ t('payroll.people.profile.payout_rules.allocation') }}
                  <SearchableSelect v-model="row.allocation_kind" class="mt-1" :options="payoutAllocationOptions" :clearable="false" :disabled="!canWrite" accent="payroll" />
                </label>
                <label v-if="row.allocation_kind === 'fixed'" :class="labelClass">
                  {{ t('payroll.people.profile.payout_rules.amount') }}
                  <input v-model.number="row.amount_czk" required type="number" min="0" step="0.01" :disabled="!canWrite" :class="inputClass" data-test="payout-rule-amount">
                </label>
                <label v-else-if="row.allocation_kind === 'percentage'" :class="labelClass">
                  {{ t('payroll.people.profile.payout_rules.percentage') }}
                  <input v-model.number="row.percentage" required type="number" min="0" max="100" step="0.01" :disabled="!canWrite" :class="inputClass" data-test="payout-rule-percentage">
                </label>
                <label :class="labelClass">
                  {{ t('payroll.people.profile.payout_rules.priority') }}
                  <input v-model.number="row.priority_no" required type="number" min="0" :disabled="!canWrite" :class="inputClass">
                </label>
              </div>
              <p v-if="row.destination_kind === 'bank' && payoutAccountOptions.length === 0" class="mt-2 text-xs text-warning-700">
                {{ t('payroll.people.profile.payout_rules.account_required') }}
              </p>
              <div
                v-if="payoutRuleNeedsVerification(row)"
                class="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-warning-200 bg-warning-50 px-3 py-2 dark:bg-warning-500/[0.06]"
                data-test="payout-rule-unverified"
              >
                <p class="text-xs text-warning-700">{{ t('payroll.people.profile.payout_rules.unverified_account') }}</p>
                <button
                  v-if="canWrite && row.bank_account_id !== null"
                  type="button"
                  :class="btnOutlineSm('warning')"
                  data-test="payout-rule-verify-account"
                  @click="focusAccountVerification(row.bank_account_id)"
                >
                  <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.badgeCheck" /></svg>
                  {{ t('payroll.people.profile.payout_rules.verify_account') }}
                </button>
              </div>
            </article>
          </div>
          <p v-if="canWrite" class="mt-3 text-xs text-neutral-500">{{ t('payroll.people.profile.payout_rules.save_hint') }}</p>
        </section>
      </div>
    </form>
  </section>
</template>
