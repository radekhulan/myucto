<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import { btnFilled, btnOutline, btnOutlineSm, disabledTitle, BTN_DISABLED_NOTE, ICONS } from '@/components/ui/buttonStyles'
// Formátování je sdílené (useFormat) — místní kopie se rozcházely v locale i tvaru.
import { formatMoneyMinor as money } from '@/composables/useFormat'
import { localPayrollPeriod } from './payrollComponentsUi'
import { payrollWallTimeToIso } from './payrollTime'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import PayrollPersonSearchSelect from '@/components/payroll/PayrollPersonSearchSelect.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import PayrollFocusNotice from '@/components/payroll/PayrollFocusNotice.vue'
import { payrollQueryId } from './payrollAgendaLinks'
import { payrollAbsenceApi, type PayrollAbsenceEmployment } from '@/api/payrollAbsences'
import {
  payrollTravelApi,
  type TravelCalculation,
  type TravelFuelKind,
  type TravelItemKind,
  type TravelTransportMode,
  type TravelTrip,
  type TravelTripItemPayload,
  type TravelTripPayload,
  type TravelVehicleKind,
} from '@/api/payrollTravel'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'

interface ItemForm {
  item_kind: TravelItemKind
  spent_on: string
  description: string
  amount: string
  is_documented: boolean
  document_reference: string
  vehicle_kind: TravelVehicleKind
  fuel_kind: TravelFuelKind
  distance_km: string
  consumption_per_100km: string
  documented_fuel_price: string
}

interface MealForm {
  meal_date: string
  meal_count: number
}

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const route = useRoute()
const router = useRouter()

const transportModes: TravelTransportMode[] =
  ['public_transport', 'company_vehicle', 'private_vehicle', 'other']
const itemKinds: TravelItemKind[] = ['transport', 'accommodation', 'incidental', 'private_vehicle']
const vehicleKinds: TravelVehicleKind[] = ['car', 'single_track']
const fuelKinds: TravelFuelKind[] = ['petrol_95', 'petrol_98', 'diesel', 'electricity']

const fieldClass = 'mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20'
const labelClass = 'mb-1 block text-xs font-medium text-neutral-600'

const loading = ref(true)
/*
 * Selhalo načtení? Pak o obsahu nevíme NIC — a to je něco jiného než „nic tu
 * není". Toast s chybou za pár vteřin zmizí a bez tohohle příznaku by na
 * obrazovce zůstal prázdný stav, který lže.
 */
const loadFailed = ref(false)
const saving = ref(false)
const previewing = ref(false)
const trips = ref<TravelTrip[]>([])
const total = ref(0)
const pageSize = 20
const offset = ref(0)
const currentPage = computed(() => Math.floor(offset.value / pageSize) + 1)
const employments = ref<PayrollAbsenceEmployment[]>([])
const period = ref(localPayrollPeriod())
const editorOpen = ref(false)
const editingTrip = ref<TravelTrip | null>(null)
const formError = ref('')
const preview = ref<TravelCalculation | null>(null)

const canWrite = computed(() => auth.canWrite('payroll.inputs.write'))
const canApprove = computed(() => auth.canWrite('payroll.approve'))

const COLUMNS: ColumnDef[] = [
  { key: 'employee', labelKey: 'payroll_travel.table.employee', required: true },
  { key: 'route', labelKey: 'payroll_travel.table.route' },
  { key: 'interval', labelKey: 'payroll_travel.table.interval' },
  { key: 'entitlement', labelKey: 'payroll_travel.table.entitlement' },
  { key: 'exempt', labelKey: 'payroll_travel.table.exempt', defaultHidden: true },
  { key: 'taxable', labelKey: 'payroll_travel.table.taxable' },
  { key: 'status', labelKey: 'payroll_travel.table.status' },
  { key: 'actions', labelKey: 'common.detail', required: true },
]
const tbl = useTablePrefs('payroll-travel', COLUMNS)

/**
 * Zóna, ve které uživatel odjezd a příjezd zadává. Nová cesta ji bere z
 * prohlížeče (stejně jako editor docházky), rozeditovaná z uložené cesty —
 * jinak by se čas zapsaný v jiné zóně při první úpravě posunul.
 */
const timezone = ref(Intl.DateTimeFormat().resolvedOptions().timeZone || 'Europe/Prague')

const form = reactive({
  employee_id: null as number | null,
  employment_id: null as number | null,
  country_code: 'CZ',
  departure_at: `${period.value}-01T08:00`,
  arrival_at: `${period.value}-01T16:00`,
  origin_place: '',
  destination_place: '',
  purpose: '',
  transport_mode: 'public_transport' as TravelTransportMode,
  meal_rate_band_1: '',
  meal_rate_band_2: '',
  meal_rate_band_3: '',
  advance: '',
  settlement_period: period.value,
})
const items = ref<ItemForm[]>([])
const meals = ref<MealForm[]>([])

const personOptions = computed(() => Array.from(
  new Map(employments.value.map(item => [item.employee_id, {
    value: item.employee_id,
    label: item.full_name,
  }])).values(),
))
const employmentOptions = computed(() => employments.value
  .filter(item => item.employee_id === form.employee_id)
  .map(item => ({
    value: item.id,
    label: t(`payroll.people.relations.${item.relation_type}`),
    secondary: item.code,
  })))

/**
 * Zúžení na jeden vztah z odkazu na kartě zaměstnance (`?employment=12`).
 *
 * Zužuje SERVER (`travel/trips?employment_id=`) v témže dotazu jako stránkování.
 * Dokud filtroval prohlížeč nad načtenou dávkou, cesta z jiné strany se tiše
 * neprojevila a prázdný výpis tvrdil „žádné cesty".
 */
const focusEmploymentId = ref<number | null>(payrollQueryId(route.query, 'employment'))
const focusName = computed(() => {
  const id = focusEmploymentId.value
  if (id === null) return null
  const employment = employments.value.find(item => item.id === id)
  return employment
    ? `${employment.full_name} · ${employment.code}`
    : t('payroll.agendas.focus.unknown_person')
})
/**
 * Server zúžení uplatnil a nezbylo nic. Tichá prázdná tabulka by vypadala
 * stejně jako období bez cest — prázdno se proto pojmenuje větou.
 */
const focusMissing = computed(() =>
  focusEmploymentId.value !== null && !loading.value && !loadFailed.value
  && trips.value.length === 0)
function clearFocus() {
  focusEmploymentId.value = null
  const query = { ...route.query }
  delete query.employment
  void router.replace({ query })
  // Zrušení zúžení mění obsah seznamu, takže stránka musí zpět na začátek.
  offset.value = 0
  void load()
}
const transportOptions = computed(() => transportModes.map(mode => ({
  value: mode,
  label: t(`payroll_travel.transport.${mode}`),
})))
const itemKindOptions = computed(() => itemKinds.map(kind => ({
  value: kind,
  label: t(`payroll_travel.item_kinds.${kind}`),
})))
const vehicleOptions = computed(() => vehicleKinds.map(kind => ({
  value: kind,
  label: t(`payroll_travel.vehicles.${kind}`),
})))
const fuelOptions = computed(() => fuelKinds.map(kind => ({
  value: kind,
  label: t(`payroll_travel.fuels.${kind}`),
})))

function hours(minutes: number) {
  return `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, '0')}`
}

/**
 * Místní čas z formuláře → ISO 8601 s UTC offsetem, stejně jako u směn.
 *
 * Bez offsetu by server hodinu přechodu letního času neuměl jednoznačně
 * zařadit; `payrollWallTimeToIso` je tentýž převod, jaký používá docházka,
 * a vrátí prázdný řetězec pro čas, který v zóně neexistuje.
 */
function moment(value: string) {
  return payrollWallTimeToIso(value, timezone.value)
}

function newItem(): ItemForm {
  return {
    item_kind: 'transport',
    spent_on: `${form.settlement_period}-01`,
    description: '',
    amount: '',
    is_documented: true,
    document_reference: '',
    vehicle_kind: 'car',
    fuel_kind: 'petrol_95',
    distance_km: '',
    consumption_per_100km: '',
    documented_fuel_price: '',
  }
}

function resetForm(trip: TravelTrip | null) {
  editingTrip.value = trip
  formError.value = ''
  preview.value = null
  if (trip === null) {
    // Prázdný formulář si drží zúžení z odkazu — kdo přišel „zadat cestu Novákovi",
    // ho nechce vybírat po každém zavření editoru znovu.
    form.employee_id = null
    form.employment_id = null
    if (focusEmploymentId.value !== null) selectEmployment(focusEmploymentId.value)
    form.country_code = 'CZ'
    timezone.value = Intl.DateTimeFormat().resolvedOptions().timeZone || 'Europe/Prague'
    form.departure_at = `${period.value}-01T08:00`
    form.arrival_at = `${period.value}-01T16:00`
    form.origin_place = ''
    form.destination_place = ''
    form.purpose = ''
    form.transport_mode = 'public_transport'
    form.meal_rate_band_1 = ''
    form.meal_rate_band_2 = ''
    form.meal_rate_band_3 = ''
    form.advance = ''
    form.settlement_period = period.value
    items.value = []
    meals.value = []
    return
  }
  timezone.value = trip.timezone_name
  form.employee_id = trip.employee_id
  form.employment_id = trip.employment_id
  form.country_code = trip.country_code
  form.departure_at = trip.departure_at_local.replace(' ', 'T').slice(0, 16)
  form.arrival_at = trip.arrival_at_local.replace(' ', 'T').slice(0, 16)
  form.origin_place = trip.origin_place
  form.destination_place = trip.destination_place
  form.purpose = trip.purpose
  form.transport_mode = trip.transport_mode
  form.meal_rate_band_1 = minorToInput(trip.meal_rate_band_1_minor)
  form.meal_rate_band_2 = minorToInput(trip.meal_rate_band_2_minor)
  form.meal_rate_band_3 = minorToInput(trip.meal_rate_band_3_minor)
  form.advance = trip.advance_minor > 0 ? minorToInput(trip.advance_minor) : ''
  form.settlement_period = trip.settlement_period_start.slice(0, 7)
  items.value = trip.items.map(item => ({
    item_kind: item.item_kind,
    spent_on: item.spent_on.slice(0, 10),
    description: item.description,
    amount: minorToInput(item.amount_minor),
    is_documented: item.is_documented,
    document_reference: item.document_reference ?? '',
    vehicle_kind: item.vehicle_kind ?? 'car',
    fuel_kind: item.fuel_kind ?? 'petrol_95',
    distance_km: item.distance_m === null ? '' : String(item.distance_m / 1000),
    consumption_per_100km: item.consumption_ml_per_100km === null
      ? ''
      : String(item.consumption_ml_per_100km / 1000),
    documented_fuel_price: minorToInput(item.documented_fuel_price_minor),
  }))
  meals.value = Object.entries(trip.free_meals)
    .map(([meal_date, meal_count]) => ({ meal_date, meal_count }))
}

function minorToInput(minor: number | null) {
  return minor === null ? '' : String(minor / 100)
}

function payload(): TravelTripPayload {
  return {
    employee_id: form.employee_id,
    employment_id: form.employment_id,
    country_code: form.country_code.toUpperCase(),
    departure_at: moment(form.departure_at),
    arrival_at: moment(form.arrival_at),
    timezone: timezone.value,
    origin_place: form.origin_place,
    destination_place: form.destination_place,
    purpose: form.purpose,
    transport_mode: form.transport_mode,
    meal_rate_band_1: form.meal_rate_band_1 || null,
    meal_rate_band_2: form.meal_rate_band_2 || null,
    meal_rate_band_3: form.meal_rate_band_3 || null,
    advance: form.advance || null,
    settlement_period: form.settlement_period,
    items: items.value.map(itemPayload),
    free_meals: meals.value.map(meal => ({
      meal_date: meal.meal_date,
      meal_count: meal.meal_count,
    })),
  }
}

function itemPayload(item: ItemForm): TravelTripItemPayload {
  if (item.item_kind === 'private_vehicle') {
    return {
      item_kind: item.item_kind,
      spent_on: item.spent_on,
      description: item.description,
      is_documented: item.is_documented,
      document_reference: item.document_reference || null,
      vehicle_kind: item.vehicle_kind,
      fuel_kind: item.fuel_kind,
      distance_km: item.distance_km,
      consumption_per_100km: item.consumption_per_100km,
      documented_fuel_price: item.documented_fuel_price || null,
    }
  }
  return {
    item_kind: item.item_kind,
    spent_on: item.spent_on,
    description: item.description,
    amount: item.amount,
    is_documented: item.is_documented,
    document_reference: item.document_reference || null,
  }
}

async function load() {
  loading.value = true
  loadFailed.value = false
  try {
    const page = { limit: pageSize, offset: offset.value }
    const [tripPage, context] = await Promise.all([
      payrollTravelApi.listPage(period.value, page, focusEmploymentId.value ?? undefined),
      employments.value.length === 0
        ? payrollAbsenceApi.context()
        : Promise.resolve(employments.value),
    ])
    trips.value = tripPage.trips
    total.value = tripPage.total
    employments.value = context
    // Předvybraný vztah se nabídne i v editoru nové cesty — jinak by uživatel
    // po kliknutí na „Pracovní cesty" u konkrétního člověka vybíral znovu.
    if (focusEmploymentId.value !== null && form.employment_id === null) {
      selectEmployment(focusEmploymentId.value)
    }
  } catch (error: unknown) {
    loadFailed.value = true
    toast.error(apiErrorMessage(error, t('payroll_travel.messages.load_failed')))
  } finally {
    loading.value = false
  }
}

// Stránkuje sdílená `PaginationBar` (číslo stránky od jedné); server zná offset.
function goToPage(nextPage: number) {
  offset.value = Math.max(0, (nextPage - 1) * pageSize)
  void load()
}

function openEditor(trip: TravelTrip | null) {
  resetForm(trip)
  editorOpen.value = true
}

function closeEditor() {
  editorOpen.value = false
  resetForm(null)
}

function selectEmployment(id: number | null) {
  form.employment_id = id
  const found = employments.value.find(item => item.id === id)
  form.employee_id = found ? found.employee_id : null
}

function selectEmployee(id: number | null) {
  form.employee_id = id
  const available = employments.value.filter(item => item.employee_id === id)
  if (!available.some(item => item.id === form.employment_id)) {
    form.employment_id = available[0]?.id ?? null
  }
}

/**
 * Odjezd i příjezd musí v zadané zóně existovat. Neexistují jen v hodině, která
 * se na jaře přeskakuje — bez téhle kontroly by na server odešel prázdný řetězec
 * a uživatel dostal hlášku o ISO 8601, kterou nikdy nepsal.
 */
function momentsValid(): boolean {
  if (moment(form.departure_at) === '' || moment(form.arrival_at) === '') {
    formError.value = t('payroll_travel.messages.invalid_moment')
    return false
  }
  return true
}

async function runPreview() {
  formError.value = ''
  if (!momentsValid()) return
  previewing.value = true
  try {
    preview.value = await payrollTravelApi.preview(payload())
  } catch (error: unknown) {
    preview.value = null
    formError.value = apiErrorMessage(error, t('payroll_travel.messages.preview_failed'))
  } finally {
    previewing.value = false
  }
}

async function save() {
  formError.value = ''
  if (!momentsValid()) return
  saving.value = true
  try {
    if (editingTrip.value === null) {
      await payrollTravelApi.create(payload())
      toast.success(t('payroll_travel.messages.created'))
    } else {
      await payrollTravelApi.update(editingTrip.value.id, {
        ...payload(),
        row_version: editingTrip.value.row_version,
      })
      toast.success(t('payroll_travel.messages.updated'))
    }
    closeEditor()
    await load()
  } catch (error: unknown) {
    formError.value = apiErrorMessage(error, t('payroll_travel.messages.save_failed'))
  } finally {
    saving.value = false
  }
}

async function approve(trip: TravelTrip) {
  saving.value = true
  try {
    await payrollTravelApi.approve(trip.id, trip.row_version)
    toast.success(t('payroll_travel.messages.approved'))
    await load()
  } catch (error: unknown) {
    toast.error(apiErrorMessage(error, t('payroll_travel.messages.approve_failed')))
  } finally {
    saving.value = false
  }
}

async function materialize(trip: TravelTrip) {
  saving.value = true
  try {
    const result = await payrollTravelApi.materialize(trip.id)
    toast.success(t('payroll_travel.messages.materialized', {
      created: result.created_count,
      replayed: result.replayed_count,
    }))
    await load()
  } catch (error: unknown) {
    toast.error(apiErrorMessage(error, t('payroll_travel.messages.materialize_failed')))
  } finally {
    saving.value = false
  }
}

function addItem() {
  items.value.push(newItem())
}

function removeItem(index: number) {
  items.value.splice(index, 1)
}

function addMeal() {
  meals.value.push({ meal_date: `${form.settlement_period}-01`, meal_count: 1 })
}

function removeMeal(index: number) {
  meals.value.splice(index, 1)
}

watch(period, () => {
  // Jiné období = jiná množina cest; zůstat na třetí stránce by ukázalo prázdno.
  offset.value = 0
  void load()
})
onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll_travel.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll_travel.subtitle') }}</p>
      </div>
      <button
        v-if="canWrite"
        data-test="travel-new"
        :class="btnFilled('primary')"
        @click="openEditor(null)"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path :d="ICONS.plus" />
        </svg>
        {{ t('payroll_travel.actions.new') }}
      </button>
    </header>

    <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
      <div class="flex flex-wrap items-end gap-4">
        <label class="min-w-40 flex-1">
          <span :class="labelClass">{{ t('payroll_travel.period') }}</span>
          <input v-model="period" data-test="travel-period" type="month" :class="fieldClass">
        </label>
        <button :class="btnOutline('neutral')" :disabled="loading" @click="load">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path :d="ICONS.cycle" />
          </svg>
          {{ t('common.refresh') }}
        </button>
      </div>
    </section>

    <div v-if="loading" class="grid gap-4 md:grid-cols-2">
      <div v-for="index in 4" :key="index" class="h-32 animate-pulse rounded-xl bg-neutral-100" />
    </div>

    <EmptyState
      v-else-if="loadFailed"
      variant="failed"
      boxed
      data-test="load-failed"
      :message="t('payroll_travel.messages.load_failed_hint')"
      @action="load"
    />

    <template v-else>
      <PayrollFocusNotice
        v-if="focusMissing"
        :name="String(focusEmploymentId)"
        missing
        class="mb-4"
        @clear="clearFocus"
      />
      <PayrollFocusNotice
        v-else-if="focusName"
        :name="focusName"
        class="mb-4"
        @clear="clearFocus"
      />

      <!--
        Prázdno po zúžení pojmenovává už lišta nad seznamem. Generická věta pod
        ní by totéž řekla podruhé, a ještě obecněji.
      -->
      <template v-if="trips.length === 0">
        <p
          v-if="!focusMissing"
          class="rounded-xl border border-dashed border-neutral-300 p-8 text-center text-sm text-neutral-500"
        >
          {{ t('payroll_travel.empty') }}
        </p>
      </template>

      <template v-else>
        <!-- Desktopová tabulka -->
        <section class="hidden rounded-xl border border-neutral-200 bg-surface shadow-sm md:block">
          <div class="flex flex-wrap items-center justify-end gap-2 border-b border-neutral-200 px-4 py-2">
            <ColumnPicker class="hidden md:block" :ctrl="tbl" />
            <DensityToggle class="hidden md:block" :ctrl="tbl" />
          </div>
          <div class="overflow-x-auto">
          <table class="w-full text-sm" :class="tbl.densityClass.value">
            <thead class="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
              <tr>
                <th v-if="tbl.isVisible('employee')" class="px-4 py-3">{{ t('payroll_travel.table.employee') }}</th>
                <th v-if="tbl.isVisible('route')" class="px-4 py-3">{{ t('payroll_travel.table.route') }}</th>
                <th v-if="tbl.isVisible('interval')" class="px-4 py-3">{{ t('payroll_travel.table.interval') }}</th>
                <th v-if="tbl.isVisible('entitlement')" class="px-4 py-3 text-right">{{ t('payroll_travel.table.entitlement') }}</th>
                <th v-if="tbl.isVisible('exempt')" class="px-4 py-3 text-right">{{ t('payroll_travel.table.exempt') }}</th>
                <th v-if="tbl.isVisible('taxable')" class="px-4 py-3 text-right">{{ t('payroll_travel.table.taxable') }}</th>
                <th v-if="tbl.isVisible('status')" class="px-4 py-3">{{ t('payroll_travel.table.status') }}</th>
                <th v-if="tbl.isVisible('actions')" class="px-4 py-3" />
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="trip in trips" :key="trip.id" data-test="travel-row">
                <td v-if="tbl.isVisible('employee')" class="px-4 py-3">
                  <div class="font-medium text-neutral-900">{{ trip.employee_name }}</div>
                  <div class="text-xs text-neutral-500">{{ trip.employment_code }}</div>
                </td>
                <td v-if="tbl.isVisible('route')" class="px-4 py-3">
                  <div class="text-neutral-900">{{ trip.origin_place }} → {{ trip.destination_place }}</div>
                  <div class="text-xs text-neutral-500">{{ trip.purpose }}</div>
                </td>
                <td v-if="tbl.isVisible('interval')" class="px-4 py-3 text-neutral-700">
                  <div>{{ trip.departure_at_local.slice(0, 16) }}</div>
                  <div>{{ trip.arrival_at_local.slice(0, 16) }}</div>
                </td>
                <td v-if="tbl.isVisible('entitlement')" class="px-4 py-3 text-right font-medium">{{ money(trip.entitlement_total_minor) }}</td>
                <td v-if="tbl.isVisible('exempt')" class="px-4 py-3 text-right text-success-700">{{ money(trip.exempt_total_minor) }}</td>
                <td v-if="tbl.isVisible('taxable')" class="px-4 py-3 text-right text-warning-700">{{ money(trip.taxable_total_minor) }}</td>
                <td v-if="tbl.isVisible('status')" class="px-4 py-3">
                  <span
                    class="rounded-full px-2 py-1 text-xs font-medium"
                    :class="{
                      'bg-neutral-100 text-neutral-600': trip.status === 'draft',
                      'bg-success-50 text-success-700': trip.status === 'approved',
                      'bg-primary-50 text-primary-700': trip.status === 'settled',
                      'bg-danger-50 text-danger-700': trip.status === 'cancelled',
                    }"
                  >
                    {{ t(`payroll_travel.status.${trip.status}`) }}
                  </span>
                </td>
                <td v-if="tbl.isVisible('actions')" class="px-4 py-3">
                  <div class="flex flex-wrap justify-end gap-2">
                    <button
                      v-if="canWrite && trip.status === 'draft'"
                      :class="btnOutlineSm('primary')"
                      class="whitespace-nowrap"
                      @click="openEditor(trip)"
                    >
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>
                      {{ t('common.edit') }}
                    </button>
                    <button
                      v-if="canApprove && trip.status === 'draft'"
                      :class="btnOutlineSm('success')"
                      class="whitespace-nowrap"
                      :disabled="saving"
                      @click="approve(trip)"
                    >
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
                      {{ t('payroll_travel.actions.approve') }}
                    </button>
                    <button
                      v-if="canApprove && trip.status === 'approved'"
                      data-test="travel-materialize"
                      :class="btnOutlineSm('success')"
                      class="whitespace-nowrap"
                      :disabled="saving"
                      @click="materialize(trip)"
                    >
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.send" /></svg>
                      {{ t('payroll_travel.actions.materialize') }}
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
          </div>
        </section>

        <!-- Mobilní karty -->
        <section class="grid gap-4 md:hidden">
          <article
            v-for="trip in trips"
            :key="trip.id"
            data-test="travel-card"
            class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm"
          >
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div class="min-w-0">
                <h2 class="truncate font-semibold text-neutral-900">{{ trip.employee_name }}</h2>
                <p class="truncate text-xs text-neutral-500">{{ trip.employment_code }}</p>
              </div>
              <span
                class="rounded-full px-2 py-1 text-xs font-medium"
                :class="{
                  'bg-neutral-100 text-neutral-600': trip.status === 'draft',
                  'bg-success-50 text-success-700': trip.status === 'approved',
                  'bg-primary-50 text-primary-700': trip.status === 'settled',
                  'bg-danger-50 text-danger-700': trip.status === 'cancelled',
                }"
              >
                {{ t(`payroll_travel.status.${trip.status}`) }}
              </span>
            </div>
            <p class="mt-2 break-words text-sm text-neutral-700">
              {{ trip.origin_place }} → {{ trip.destination_place }}
            </p>
            <p class="mt-1 break-words text-xs text-neutral-500">{{ trip.purpose }}</p>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
              <div>
                <dt class="text-neutral-500">{{ t('payroll_travel.table.interval') }}</dt>
                <dd class="break-words font-medium text-neutral-900">
                  {{ trip.departure_at_local.slice(0, 16) }} – {{ trip.arrival_at_local.slice(0, 16) }}
                </dd>
              </div>
              <div>
                <dt class="text-neutral-500">{{ t('payroll_travel.table.entitlement') }}</dt>
                <dd class="font-medium text-neutral-900">{{ money(trip.entitlement_total_minor) }}</dd>
              </div>
              <div>
                <dt class="text-neutral-500">{{ t('payroll_travel.table.exempt') }}</dt>
                <dd class="font-medium text-success-700">{{ money(trip.exempt_total_minor) }}</dd>
              </div>
              <div>
                <dt class="text-neutral-500">{{ t('payroll_travel.table.taxable') }}</dt>
                <dd class="font-medium text-warning-700">{{ money(trip.taxable_total_minor) }}</dd>
              </div>
            </dl>
            <div class="mt-4 flex flex-wrap gap-2">
              <button
                v-if="canWrite && trip.status === 'draft'"
                :class="btnOutlineSm('primary')"
                class="whitespace-nowrap"
                @click="openEditor(trip)"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>
                {{ t('common.edit') }}
              </button>
              <button
                v-if="canApprove && trip.status === 'draft'"
                :class="btnOutlineSm('success')"
                class="whitespace-nowrap"
                :disabled="saving"
                @click="approve(trip)"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
                {{ t('payroll_travel.actions.approve') }}
              </button>
              <button
                v-if="canApprove && trip.status === 'approved'"
                :class="btnOutlineSm('success')"
                class="whitespace-nowrap"
                :disabled="saving"
                @click="materialize(trip)"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.send" /></svg>
                {{ t('payroll_travel.actions.materialize') }}
              </button>
            </div>
          </article>
        </section>

        <!-- Zúžení mění i `total`, takže pager mluví o zúženém seznamu. -->
        <PaginationBar
          data-test="travel-pagination"
          :page="currentPage"
          :per-page="pageSize"
          :total="total"
          @update:page="goToPage"
        />
      </template>
    </template>

    <!-- Editor cesty — jedno společné Uložit ve spodní liště -->
    <section
      v-if="editorOpen"
      data-test="travel-editor"
      class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6"
    >
      <h2 class="text-lg font-semibold text-neutral-900">
        {{ editingTrip === null ? t('payroll_travel.editor.new') : t('payroll_travel.editor.edit') }}
      </h2>

      <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div>
          <span :class="labelClass">{{ t('payroll_travel.form.employee') }}</span>
          <PayrollPersonSearchSelect
            :model-value="form.employee_id"
            data-test="travel-person"
            :candidates="personOptions"
            :label="t('payroll_travel.form.employee')"
            :clearable="false"
            @update:model-value="selectEmployee"
          />
        </div>
        <div>
          <span :class="labelClass">{{ t('payroll_travel.form.employment') }}</span>
          <SearchableSelect
            :model-value="form.employment_id"
            :options="employmentOptions"
            accent="payroll"
            data-test="travel-employment"
            :placeholder="t('payroll_travel.form.select')"
            :aria-label="t('payroll_travel.form.employment')"
            @update:model-value="value => selectEmployment(value as number | null)"
          />
        </div>
        <label>
          <span :class="labelClass">{{ t('payroll_travel.form.settlement_period') }}</span>
          <input v-model="form.settlement_period" type="month" :class="fieldClass">
        </label>
        <label>
          <span :class="labelClass">{{ t('payroll_travel.form.country') }}</span>
          <input v-model="form.country_code" maxlength="2" type="text" :class="fieldClass">
        </label>
        <label>
          <span :class="labelClass">{{ t('payroll_travel.form.departure_at') }}</span>
          <input v-model="form.departure_at" data-test="travel-departure" type="datetime-local" :class="fieldClass">
        </label>
        <label>
          <span :class="labelClass">{{ t('payroll_travel.form.arrival_at') }}</span>
          <input v-model="form.arrival_at" data-test="travel-arrival" type="datetime-local" :class="fieldClass">
        </label>
        <div>
          <span :class="labelClass">{{ t('payroll_travel.form.transport_mode') }}</span>
          <SearchableSelect
            v-model="form.transport_mode"
            :options="transportOptions"
            :clearable="false"
            accent="payroll"
            :aria-label="t('payroll_travel.form.transport_mode')"
          />
        </div>
        <label>
          <span :class="labelClass">{{ t('payroll_travel.form.origin_place') }}</span>
          <input v-model="form.origin_place" maxlength="190" type="text" :class="fieldClass">
        </label>
        <label>
          <span :class="labelClass">{{ t('payroll_travel.form.destination_place') }}</span>
          <input v-model="form.destination_place" maxlength="190" type="text" :class="fieldClass">
        </label>
        <label>
          <span :class="labelClass">{{ t('payroll_travel.form.purpose') }}</span>
          <input v-model="form.purpose" maxlength="255" type="text" :class="fieldClass">
        </label>
        <label>
          <span :class="labelClass">{{ t('payroll_travel.form.meal_rate_band_1') }}</span>
          <input v-model="form.meal_rate_band_1" inputmode="decimal" type="text" :class="fieldClass" :placeholder="t('payroll_travel.form.statutory_minimum')">
        </label>
        <label>
          <span :class="labelClass">{{ t('payroll_travel.form.meal_rate_band_2') }}</span>
          <input v-model="form.meal_rate_band_2" inputmode="decimal" type="text" :class="fieldClass" :placeholder="t('payroll_travel.form.statutory_minimum')">
        </label>
        <label>
          <span :class="labelClass">{{ t('payroll_travel.form.meal_rate_band_3') }}</span>
          <input v-model="form.meal_rate_band_3" inputmode="decimal" type="text" :class="fieldClass" :placeholder="t('payroll_travel.form.statutory_minimum')">
        </label>
        <label>
          <span :class="labelClass">{{ t('payroll_travel.form.advance') }}</span>
          <input v-model="form.advance" inputmode="decimal" type="text" :class="fieldClass">
        </label>
      </div>

      <!-- Položky vyúčtování -->
      <div class="mt-6">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h3 class="font-semibold text-neutral-900">{{ t('payroll_travel.items.title') }}</h3>
          <button :class="btnOutlineSm('primary')" class="whitespace-nowrap" @click="addItem">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>
            {{ t('payroll_travel.items.add') }}
          </button>
        </div>
        <p v-if="items.length === 0" class="mt-2 text-sm text-neutral-500">
          {{ t('payroll_travel.items.empty') }}
        </p>
        <div
          v-for="(item, index) in items"
          :key="index"
          data-test="travel-item"
          class="mt-3 rounded-lg border border-neutral-200 p-3"
        >
          <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
              <span :class="labelClass">{{ t('payroll_travel.items.kind') }}</span>
              <SearchableSelect
                v-model="item.item_kind"
                :options="itemKindOptions"
                :clearable="false"
                accent="payroll"
                :aria-label="t('payroll_travel.items.kind')"
              />
            </div>
            <label>
              <span :class="labelClass">{{ t('payroll_travel.items.spent_on') }}</span>
              <input v-model="item.spent_on" type="date" :class="fieldClass">
            </label>
            <label class="sm:col-span-2">
              <span :class="labelClass">{{ t('payroll_travel.items.description') }}</span>
              <input v-model="item.description" maxlength="190" type="text" :class="fieldClass">
            </label>
            <template v-if="item.item_kind === 'private_vehicle'">
              <div>
                <span :class="labelClass">{{ t('payroll_travel.items.vehicle_kind') }}</span>
                <SearchableSelect
                  v-model="item.vehicle_kind"
                  :options="vehicleOptions"
                  :clearable="false"
                  accent="payroll"
                  :aria-label="t('payroll_travel.items.vehicle_kind')"
                />
              </div>
              <div>
                <span :class="labelClass">{{ t('payroll_travel.items.fuel_kind') }}</span>
                <SearchableSelect
                  v-model="item.fuel_kind"
                  :options="fuelOptions"
                  :clearable="false"
                  accent="payroll"
                  :aria-label="t('payroll_travel.items.fuel_kind')"
                />
              </div>
              <label>
                <span :class="labelClass">{{ t('payroll_travel.items.distance_km') }}</span>
                <input v-model="item.distance_km" data-test="travel-distance" inputmode="decimal" type="text" :class="fieldClass">
              </label>
              <label>
                <span :class="labelClass">{{ t('payroll_travel.items.consumption') }}</span>
                <input v-model="item.consumption_per_100km" inputmode="decimal" type="text" :class="fieldClass">
              </label>
              <label>
                <span :class="labelClass">{{ t('payroll_travel.items.documented_fuel_price') }}</span>
                <input v-model="item.documented_fuel_price" inputmode="decimal" type="text" :class="fieldClass">
              </label>
            </template>
            <label v-else>
              <span :class="labelClass">{{ t('payroll_travel.items.amount') }}</span>
              <input v-model="item.amount" data-test="travel-amount" inputmode="decimal" type="text" :class="fieldClass">
            </label>
            <label>
              <span :class="labelClass">{{ t('payroll_travel.items.document_reference') }}</span>
              <input v-model="item.document_reference" maxlength="190" type="text" :class="fieldClass">
            </label>
          </div>
          <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
            <label class="flex items-center gap-2 text-sm text-neutral-700">
              <input v-model="item.is_documented" type="checkbox">
              {{ t('payroll_travel.items.documented') }}
            </label>
            <button :class="btnOutlineSm('danger')" class="whitespace-nowrap" @click="removeItem(index)">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>
              {{ t('common.delete') }}
            </button>
          </div>
        </div>
      </div>

      <!-- Bezplatná jídla -->
      <div class="mt-6">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h3 class="font-semibold text-neutral-900">{{ t('payroll_travel.meals.title') }}</h3>
          <button :class="btnOutlineSm('primary')" class="whitespace-nowrap" @click="addMeal">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>
            {{ t('payroll_travel.meals.add') }}
          </button>
        </div>
        <p v-if="meals.length === 0" class="mt-2 text-sm text-neutral-500">
          {{ t('payroll_travel.meals.empty') }}
        </p>
        <div
          v-for="(meal, index) in meals"
          :key="index"
          class="mt-3 grid gap-3 rounded-lg border border-neutral-200 p-3 sm:grid-cols-3"
        >
          <label>
            <span :class="labelClass">{{ t('payroll_travel.meals.date') }}</span>
            <input v-model="meal.meal_date" type="date" :class="fieldClass">
          </label>
          <label>
            <span :class="labelClass">{{ t('payroll_travel.meals.count') }}</span>
            <input v-model.number="meal.meal_count" min="1" max="3" type="number" :class="fieldClass">
          </label>
          <div class="flex items-end">
            <button :class="btnOutlineSm('danger')" class="whitespace-nowrap" @click="removeMeal(index)">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>
              {{ t('common.delete') }}
            </button>
          </div>
        </div>
      </div>

      <!-- Náhled rozpadu -->
      <div v-if="preview" data-test="travel-preview" class="mt-6 rounded-lg border border-neutral-200 bg-neutral-50 p-4">
        <p
          v-if="preview.status !== 'supported'"
          class="rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800"
        >
          {{ t('payroll_travel.preview.manual_review') }}
          <span class="block break-words">{{ preview.blockers.join(' · ') }}</span>
        </p>
        <template v-else>
          <dl class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div>
              <dt class="text-xs text-neutral-500">{{ t('payroll_travel.table.entitlement') }}</dt>
              <dd class="text-lg font-semibold text-neutral-900">{{ money(preview.entitlement_total_minor) }}</dd>
            </div>
            <div>
              <dt class="text-xs text-neutral-500">{{ t('payroll_travel.table.exempt') }}</dt>
              <dd class="text-lg font-semibold text-success-700">{{ money(preview.exempt_total_minor) }}</dd>
            </div>
            <div>
              <dt class="text-xs text-neutral-500">{{ t('payroll_travel.table.taxable') }}</dt>
              <dd class="text-lg font-semibold text-warning-700">{{ money(preview.taxable_total_minor) }}</dd>
            </div>
          </dl>
          <ul class="mt-3 space-y-1 text-sm text-neutral-700">
            <li v-for="day in preview.meal_days" :key="day.date" class="break-words">
              {{ day.date }} · {{ hours(day.minutes) }} ·
              {{ t('payroll_travel.preview.band', { band: day.band }) }} ·
              {{ money(day.entitlement_minor) }}
            </li>
            <li v-for="(item, index) in preview.items" :key="`item-${index}`" class="break-words">
              {{ t(`payroll_travel.item_kinds.${item.kind}`) }} · {{ item.description }} ·
              {{ money(item.entitlement_minor) }}
            </li>
          </ul>
          <p class="mt-3 text-sm text-neutral-600">
            {{ t('payroll_travel.preview.settlement', { amount: money(preview.settlement_difference_minor) }) }}
          </p>
        </template>
      </div>

      <p
        v-if="formError"
        data-test="travel-error"
        role="alert"
        class="mt-4 rounded-lg border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700"
      >
        {{ formError }}
      </p>

      <div class="sticky bottom-0 mt-6 flex flex-wrap justify-end gap-2 border-t border-neutral-200 bg-surface py-3">
        <button :class="btnOutline('neutral')" class="whitespace-nowrap" @click="closeEditor">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
        <button
          data-test="travel-preview-button"
          :class="btnOutline('primary')"
          class="whitespace-nowrap"
          :disabled="previewing"
          @click="runPreview"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.chart" /></svg>
          {{ t('payroll_travel.actions.preview') }}
        </button>
        <div class="flex flex-col items-end gap-1.5">
          <button
            data-test="travel-save"
            :class="btnFilled('primary')"
            class="whitespace-nowrap"
            :disabled="saving || !canWrite"
            :title="disabledTitle(!canWrite, t('payroll_travel.messages.save_blocked_readonly'))"
            @click="save"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
            {{ t('common.save') }}
          </button>
          <p v-if="!canWrite" :class="BTN_DISABLED_NOTE" data-test="travel-save-blocked">
            {{ t('payroll_travel.messages.save_blocked_readonly') }}
          </p>
        </div>
      </div>
    </section>
  </div>
</template>
