<script setup lang="ts">
import { ref, reactive, onMounted, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import {
  logbookApi, type Car, type CarPayload, type FuelType,
  type CarUsageMode, type CarVatDeduction, type LogbookDriver,
} from '@/api/logbook'
import { useAuthStore } from '@/stores/auth'
import { ICONS, btnFilled } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import DateInput from '@/components/ui/DateInput.vue'

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const props = defineProps<{ resetToken?: number }>()

const cars = ref<Car[]>([])
const drivers = ref<LogbookDriver[]>([])
const loading = ref(false)
const showArchived = ref(false)
const fuelTypes: FuelType[] = ['diesel', 'petrol', 'lpg', 'cng', 'electric', 'hybrid', 'other']
const usageModes: CarUsageMode[] = ['business', 'mixed', 'private']

const open = ref(false)
const saving = ref(false)
const draft = reactive<CarPayload & { id: number }>({
  id: 0, registration: '', name: '', brand: '', model: '', vin: '',
  fuel_type: 'diesel', odometer_start: null, odometer_start_date: null,
  is_default: false, is_archived: false, note: '',
  driver_employee_id: null, usage_mode: 'business', vat_deduction_mode: 'full', vat_deduction_percent: 100,
})

// Režim odpočtu podle režimu užívání — zrcadlí VehicleVatPolicy na backendu:
// soukromé vozidlo bez odpočtu, smíšené užívání poměrně (§ 75).
const deductionOptions = computed<CarVatDeduction[]>(() => {
  if (draft.usage_mode === 'private') return ['none']
  if (draft.usage_mode === 'mixed') return ['proportional', 'none']
  return ['full', 'proportional', 'none']
})
watch(() => draft.usage_mode, () => {
  if (!deductionOptions.value.includes(draft.vat_deduction_mode ?? 'full')) draft.vat_deduction_mode = deductionOptions.value[0]
})

async function load() {
  loading.value = true
  try { cars.value = await logbookApi.listCars(showArchived.value) }
  finally { loading.value = false }
}
async function loadDrivers() {
  try { drivers.value = await logbookApi.listDrivers() } catch { drivers.value = [] }
}
onMounted(() => { load(); loadDrivers() })

watch(() => props.resetToken, () => { showArchived.value = false; load() })

function newCar() {
  Object.assign(draft, {
    id: 0, registration: '', name: '', brand: '', model: '', vin: '',
    fuel_type: 'diesel', odometer_start: null, odometer_start_date: null,
    is_default: cars.value.length === 0, is_archived: false, note: '',
    driver_employee_id: null, usage_mode: 'business', vat_deduction_mode: 'full', vat_deduction_percent: 100,
  })
  open.value = true
}

function editCar(c: Car) {
  Object.assign(draft, {
    id: c.id, registration: c.registration, name: c.name ?? '', brand: c.brand ?? '', model: c.model ?? '',
    vin: c.vin ?? '', fuel_type: c.fuel_type ?? 'diesel', odometer_start: c.odometer_start,
    odometer_start_date: c.odometer_start_date, is_default: c.is_default, is_archived: c.is_archived, note: c.note ?? '',
    driver_employee_id: c.driver_employee_id ?? null, usage_mode: c.usage_mode ?? 'business',
    vat_deduction_mode: c.vat_deduction_mode ?? 'full', vat_deduction_percent: c.vat_deduction_percent ?? 100,
  })
  open.value = true
}

async function save() {
  if (!draft.registration.trim()) { toast.error(t('logbook.car_reg_required')); return }
  saving.value = true
  try {
    const payload: CarPayload = {
      registration: draft.registration.trim(), name: draft.name || null, brand: draft.brand || null,
      model: draft.model || null, vin: draft.vin || null, fuel_type: draft.fuel_type || null,
      odometer_start: draft.odometer_start === null || draft.odometer_start === undefined ? null : Number(draft.odometer_start),
      odometer_start_date: draft.odometer_start_date || null,
      is_default: draft.is_default, is_archived: draft.is_archived, note: draft.note || null,
      driver_employee_id: draft.driver_employee_id ? Number(draft.driver_employee_id) : null,
      usage_mode: draft.usage_mode, vat_deduction_mode: draft.vat_deduction_mode,
      vat_deduction_percent: draft.vat_deduction_mode === 'proportional' ? Number(draft.vat_deduction_percent) : null,
    }
    if (draft.id) await logbookApi.updateCar(draft.id, payload)
    else await logbookApi.createCar(payload)
    open.value = false
    toast.success(t('common.saved'))
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message ?? t('common.error'))
  } finally { saving.value = false }
}

function carInUse(c: Car): boolean {
  return (c.trips_count ?? 0) > 0 || (c.fuelings_count ?? 0) > 0
}

async function removeCar(c: Car) {
  if (carInUse(c)) { toast.error(t('logbook.car_delete_blocked')); return }
  if (!confirm(t('logbook.confirm_delete_car', { reg: c.registration }))) return
  try {
    await logbookApi.deleteCar(c.id)
    toast.success(t('common.deleted'))
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message ?? t('common.error'))
  }
}

function fuelLabel(f: FuelType | null): string {
  return f ? t(`logbook.fuel_types.${f}`) : '—'
}

function usageLabel(c: Car): string {
  const usage = t(`logbook_fuel.usage_modes.${c.usage_mode ?? 'business'}`)
  if (c.vat_deduction_mode === 'proportional') return `${usage} · ${c.vat_deduction_percent ?? 0} %`
  return `${usage} · ${t(`logbook_fuel.vat_deductions.${c.vat_deduction_mode ?? 'full'}`)}`
}
</script>

<template>
  <section>
    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
      <label class="flex items-center gap-2 text-sm text-neutral-600">
        <input v-model="showArchived" type="checkbox" class="rounded border-neutral-300 text-primary-600" @change="load" />
        {{ t('logbook.show_archived') }}
      </label>
      <button v-if="auth.canWrite('logbook.write')" @click="newCar"
        :class="btnFilled('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('logbook.car_new') }}
      </button>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="cars.length === 0" icon="box" :title="t('logbook.no_cars')" dense boxed />

    <template v-else>
      <!-- Desktop -->
      <div class="hidden md:block bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('logbook.registration') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('logbook.car_label') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('logbook.fuel_type') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('logbook_fuel.driver') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('logbook_fuel.usage_mode') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('logbook.odometer_start') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('logbook.tab_trips') }}</th>
              <th class="px-3 py-2 w-28"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="c in cars" :key="c.id" class="hover:bg-neutral-50" :class="c.is_archived ? 'opacity-50' : ''">
              <td class="px-3 py-2 font-mono font-medium">
                {{ c.registration }}
                <span v-if="c.is_default" class="ml-1.5 text-xs px-1.5 py-0.5 rounded bg-primary-50 text-primary-700">{{ t('logbook.default_short') }}</span>
              </td>
              <td class="px-3 py-2">{{ [c.brand, c.model].filter(Boolean).join(' ') || c.name || '—' }}</td>
              <td class="px-3 py-2">{{ fuelLabel(c.fuel_type) }}</td>
              <td class="px-3 py-2">{{ c.driver_name || '—' }}</td>
              <td class="px-3 py-2 text-xs text-neutral-600">{{ usageLabel(c) }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ c.odometer_start != null ? c.odometer_start.toLocaleString('cs-CZ') : '—' }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ c.trips_count ?? 0 }}</td>
              <td class="px-3 py-2 text-right text-xs whitespace-nowrap">
                <template v-if="auth.canWrite('logbook.write')">
                  <button @click="editCar(c)" class="cursor-pointer text-primary-600 hover:text-primary-700 mr-3">{{ t('common.edit') }}</button>
                  <button @click="removeCar(c)" :disabled="carInUse(c)" :title="carInUse(c) ? t('logbook.car_delete_blocked') : ''" class="cursor-pointer text-danger-500 hover:text-danger-600 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:text-danger-500">{{ t('common.delete') }}</button>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Mobile karty -->
      <div class="md:hidden bg-surface border border-neutral-200 rounded-lg shadow-sm divide-y divide-neutral-100 overflow-hidden">
        <div v-for="c in cars" :key="`m-${c.id}`" class="px-4 py-3" :class="c.is_archived ? 'opacity-50' : ''">
          <div class="flex items-baseline justify-between gap-2">
            <div class="font-mono font-medium text-neutral-900 flex items-center gap-1.5 truncate">
              {{ c.registration }}
              <span v-if="c.is_default" class="text-xs px-1.5 py-0.5 rounded bg-primary-50 text-primary-700 shrink-0">{{ t('logbook.default_short') }}</span>
            </div>
            <span class="text-xs text-neutral-500 shrink-0">{{ fuelLabel(c.fuel_type) }}</span>
          </div>
          <div class="text-sm text-neutral-600 mt-0.5">{{ [c.brand, c.model].filter(Boolean).join(' ') || c.name || '—' }}</div>
          <div class="text-xs text-neutral-500 mt-0.5">
            {{ usageLabel(c) }}<span v-if="c.driver_name"> · {{ t('logbook_fuel.driver') }}: {{ c.driver_name }}</span>
          </div>
          <div class="flex items-baseline justify-between gap-2 mt-1 text-xs text-neutral-500">
            <span class="font-mono">{{ t('logbook.odometer_start') }}: {{ c.odometer_start != null ? c.odometer_start.toLocaleString('cs-CZ') : '—' }}</span>
            <span>{{ t('logbook.tab_trips') }}: {{ c.trips_count ?? 0 }}</span>
          </div>
          <div v-if="auth.canWrite('logbook.write')" class="flex gap-4 mt-2 text-xs">
            <button @click="editCar(c)" class="cursor-pointer text-primary-600 hover:text-primary-700">{{ t('common.edit') }}</button>
            <button @click="removeCar(c)" :disabled="carInUse(c)" :title="carInUse(c) ? t('logbook.car_delete_blocked') : ''" class="cursor-pointer ml-auto text-danger-500 hover:text-danger-600 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:text-danger-500">{{ t('common.delete') }}</button>
          </div>
        </div>
      </div>
    </template>

    <!-- Modal -->
    <div v-if="open" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <form @submit.prevent="save" class="p-5 space-y-4">
          <h2 class="text-lg font-semibold">{{ draft.id ? t('logbook.car_edit') : t('logbook.car_new') }}</h2>
          <div class="grid grid-cols-2 gap-3">
            <div class="col-span-2">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.registration') }} *</label>
              <input v-model="draft.registration" type="text" maxlength="20" required placeholder="1AB 2345"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.brand') }}</label>
              <input v-model="draft.brand" type="text" maxlength="100" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.model') }}</label>
              <input v-model="draft.model" type="text" maxlength="100" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.name') }}</label>
              <input v-model="draft.name" type="text" maxlength="100" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.fuel_type') }}</label>
              <select v-model="draft.fuel_type" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option v-for="f in fuelTypes" :key="f" :value="f">{{ t(`logbook.fuel_types.${f}`) }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.odometer_start') }}</label>
              <input v-model.number="draft.odometer_start" type="number" min="0" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.odometer_start_date') }}</label>
              <DateInput v-model="draft.odometer_start_date" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div class="col-span-2">
              <label class="block text-sm font-medium text-neutral-700 mb-1">VIN</label>
              <input v-model="draft.vin" type="text" maxlength="40" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div class="col-span-2">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook_fuel.driver') }}</label>
              <select v-model="draft.driver_employee_id" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option :value="null">{{ t('logbook_fuel.no_driver') }}</option>
                <option v-for="d in drivers" :key="d.id" :value="d.id" :disabled="!d.is_active && d.id !== draft.driver_employee_id">{{ d.full_name }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook_fuel.usage_mode') }}</label>
              <select v-model="draft.usage_mode" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option v-for="u in usageModes" :key="u" :value="u">{{ t(`logbook_fuel.usage_modes.${u}`) }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook_fuel.vat_deduction') }}</label>
              <select v-model="draft.vat_deduction_mode" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option v-for="d in deductionOptions" :key="d" :value="d">{{ t(`logbook_fuel.vat_deductions.${d}`) }}</option>
              </select>
            </div>
            <div v-if="draft.vat_deduction_mode === 'proportional'">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook_fuel.vat_deduction_percent') }} *</label>
              <input v-model.number="draft.vat_deduction_percent" type="number" min="0.01" max="99.99" step="0.01" required
                class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <p class="col-span-2 text-xs text-neutral-500">{{ t('logbook_fuel.vat_hint') }}</p>
            <div class="col-span-2">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.note') }}</label>
              <textarea v-model="draft.note" rows="2" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm"></textarea>
            </div>
          </div>
          <div class="flex flex-wrap gap-5">
            <label class="flex items-center gap-2 text-sm">
              <input v-model="draft.is_default" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('logbook.is_default') }}
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="draft.is_archived" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('logbook.archived') }}
            </label>
          </div>
          <div class="flex flex-wrap justify-end gap-2 pt-2">
            <button type="button" @click="open = false" class="cursor-pointer h-9 px-4 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 inline-flex items-center gap-1.5 whitespace-nowrap">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
              {{ t('common.cancel') }}
            </button>
            <button type="submit" :disabled="saving" class="cursor-pointer h-9 px-4 text-sm bg-primary-600 hover:bg-primary-700 text-white rounded-md disabled:opacity-50 inline-flex items-center gap-1.5 whitespace-nowrap">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
              {{ t('common.save') }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </section>
</template>
