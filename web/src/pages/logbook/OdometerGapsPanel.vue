<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import { formatDate } from '@/composables/useFormat'
import {
  logbookApi, type Car, type OdometerGap, type OdometerReading, type TripCategory, type TripTemplate,
} from '@/api/logbook'
import { useAuthStore } from '@/stores/auth'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import DateInput from '@/components/ui/DateInput.vue'
import { appIsoDate } from '@/utils/date'
import { useTripTemplates } from '@/composables/useTripTemplates'

const { t, te } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const props = defineProps<{ cars: Car[]; categories: TripCategory[] }>()
const emit = defineEmits<{ changed: [] }>()

const gaps = ref<OdometerGap[]>([])
const canWrite = computed(() => auth.canWrite('logbook.write'))
const canDelete = computed(() => auth.canWrite('logbook.delete'))

async function loadGaps() {
  try { gaps.value = await logbookApi.listGaps() } catch { gaps.value = [] }
}
const { templates, loadTemplates } = useTripTemplates(loadGaps)
onMounted(() => { loadGaps(); loadTemplates() })

const fmtKm = (n: number) => n.toLocaleString('cs-CZ', { maximumFractionDigits: 1 })
const carLabel = (g: { car_registration: string | null; car_name: string | null }) => g.car_name || g.car_registration || ''
function gapPeriod(g: OdometerGap): string {
  if (!g.date_from || g.date_from === g.date_to) return formatDate(g.date_to)
  return `${formatDate(g.date_from)} – ${formatDate(g.date_to)}`
}

// ── Doplnění km ─────────────────────────────────────────────────
interface Row {
  key: number
  template_id: number | null
  label: string
  trip_date: string
  distance_km: number | null
  origin: string
  destination: string
  purpose: string
  category_id: number | null
  time_start: string | null
  time_end: string | null
}
const fillGap = ref<OdometerGap | null>(null)
const rows = ref<Row[]>([])
const remainderPrivate = ref(true)
const filling = ref(false)
let rowSeq = 0

const gapTemplates = computed(() => templates.value.filter(tt => !fillGap.value || tt.car_id === null || tt.car_id === fillGap.value.car_id))
const rowsKm = computed(() => rows.value.reduce((s, r) => s + (Number(r.distance_km) || 0), 0))
const remainderKm = computed(() => fillGap.value ? Math.round((fillGap.value.km - rowsKm.value) * 10) / 10 : 0)
const hasPrivateCategory = computed(() => props.categories.some(c => c.is_private && !c.is_archived))

function openFill(g: OdometerGap) {
  fillGap.value = g
  rows.value = []
  remainderPrivate.value = hasPrivateCategory.value
}
function addTemplate(tt: TripTemplate) {
  rows.value.push({
    key: ++rowSeq, template_id: tt.id, label: tt.label, trip_date: fillGap.value!.date_to, distance_km: tt.distance_km,
    origin: tt.origin ?? '', destination: tt.destination ?? '', purpose: tt.purpose ?? '', category_id: tt.category_id,
    time_start: tt.time_start, time_end: tt.time_end,
  })
}
function addCustom() {
  const business = props.categories.find(c => c.is_default && !c.is_private) ?? props.categories.find(c => !c.is_private)
  rows.value.push({
    key: ++rowSeq, template_id: null, label: '', trip_date: fillGap.value!.date_to, distance_km: null,
    origin: '', destination: '', purpose: '', category_id: business?.id ?? null, time_start: null, time_end: null,
  })
}
function removeRow(key: number) { rows.value = rows.value.filter(r => r.key !== key) }

async function submitFill() {
  const g = fillGap.value
  if (!g) return
  if (remainderKm.value < 0) { toast.error(t('logbook.gap_exceeds')); return }
  if (rows.value.some(r => !r.distance_km || Number(r.distance_km) <= 0)) { toast.error(t('logbook.gap_row_km_required')); return }
  if (rows.value.length === 0 && !(remainderPrivate.value && remainderKm.value > 0)) { toast.error(t('logbook.gap_nothing')); return }
  filling.value = true
  try {
    await logbookApi.fillGap({
      reading_id: g.reading_id,
      trips: rows.value.map(r => ({
        template_id: r.template_id, trip_date: r.trip_date, distance_km: Number(r.distance_km),
        origin: r.origin || null, destination: r.destination || null, purpose: r.purpose || null,
        category_id: r.category_id, time_start: r.time_start || null, time_end: r.time_end || null,
      })),
      remainder_private: remainderPrivate.value,
      remainder_purpose: t('logbook.gap_private_purpose'),
    })
    toast.success(t('logbook.gap_filled'))
    fillGap.value = null
    emit('changed')
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message ?? t('common.error'))
  } finally { filling.value = false }
}

// ── Stavy tachometru ────────────────────────────────────────────
const readingsOpen = ref(false)
const readings = ref<OdometerReading[]>([])
const readingCar = ref<number>(0)
const savingReading = ref(false)
const readingDraft = reactive<{ reading_date: string; odometer: number | null }>({ reading_date: appIsoDate(), odometer: null })

async function loadReadings() {
  readings.value = readingCar.value ? await logbookApi.listReadings(readingCar.value) : []
}
async function openReadings(carId?: number | null) {
  readingCar.value = carId || (props.cars.find(c => c.is_default) ?? props.cars[0])?.id || 0
  Object.assign(readingDraft, { reading_date: appIsoDate(), odometer: null })
  readingsOpen.value = true
  await loadReadings()
}
async function saveReading() {
  if (!readingCar.value || readingDraft.odometer == null || (readingDraft.odometer as unknown) === '') {
    toast.error(t('logbook.reading_required')); return
  }
  savingReading.value = true
  try {
    await logbookApi.createReading({ car_id: readingCar.value, reading_date: readingDraft.reading_date, odometer: Number(readingDraft.odometer) })
    toast.success(t('common.saved'))
    readingDraft.odometer = null
    await Promise.all([loadReadings(), loadGaps()])
  } catch (e: any) {
    const err = e?.response?.data?.error
    toast.error(err?.code === 'odometer_regression'
      ? t('logbook.reading_regression', { before: err.before ?? '—', after: err.after ?? '—' })
      : err?.message ?? t('common.error'))
  } finally { savingReading.value = false }
}
async function removeReading(r: OdometerReading) {
  if (!confirm(t('logbook.reading_confirm_delete', { date: formatDate(r.reading_date), km: fmtKm(r.odometer) }))) return
  try {
    await logbookApi.deleteReading(r.id)
    toast.success(t('common.deleted'))
    await Promise.all([loadReadings(), loadGaps()])
  } catch (e: any) { toast.error(e?.response?.data?.error?.message ?? t('common.error')) }
}

defineExpose({ openReadings, reload: () => { loadGaps(); loadTemplates() } })
</script>

<template>
  <div>
    <!-- Km k doplnění -->
    <div v-if="gaps.length > 0" class="mb-4 rounded-lg border border-warning-500/40 bg-warning-50 px-4 py-3 text-sm text-warning-700">
      <div class="font-medium mb-1">{{ t('logbook.gaps_title') }}</div>
      <p class="text-xs mb-2">{{ t('logbook.gaps_hint') }}</p>
      <ul class="divide-y divide-warning-500/20">
        <li v-for="g in gaps" :key="g.reading_id" class="flex flex-wrap items-center justify-between gap-2 py-1.5">
          <span>
            <span class="font-medium">{{ gapPeriod(g) }}</span>
            · {{ carLabel(g) }}
            · <span class="font-mono font-semibold">{{ fmtKm(g.km) }} km</span>
            <span class="font-mono text-xs opacity-80"> ({{ fmtKm(g.odometer_from) }} → {{ fmtKm(g.odometer_to) }})</span>
          </span>
          <button v-if="canWrite" @click="openFill(g)" :class="btnOutlineSm('warning')" class="whitespace-nowrap">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
            {{ t('logbook.gap_fill') }}
          </button>
        </li>
      </ul>
    </div>

    <!-- Modal: doplnit km -->
    <div v-if="fillGap" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <form @submit.prevent="submitFill" class="p-5 space-y-4">
          <div>
            <h2 class="text-lg font-semibold">{{ t('logbook.gap_fill_title') }}</h2>
            <p class="text-sm text-neutral-500 mt-0.5">
              {{ gapPeriod(fillGap) }} · {{ carLabel(fillGap) }} ·
              <span class="font-mono">{{ fmtKm(fillGap.odometer_from) }} → {{ fmtKm(fillGap.odometer_to) }}</span>
            </p>
          </div>

          <div>
            <div class="text-sm font-medium text-neutral-700 mb-1.5">{{ t('logbook.gap_add_template') }}</div>
            <div class="flex flex-wrap gap-2">
              <button v-for="tt in gapTemplates" :key="tt.id" type="button" @click="addTemplate(tt)" :class="btnOutlineSm('primary')" class="whitespace-nowrap">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.pin" /></svg>
                {{ tt.label }} <span class="font-mono opacity-70">{{ fmtKm(tt.distance_km) }}</span>
              </button>
              <button type="button" @click="addCustom" :class="btnOutlineSm('neutral')" class="whitespace-nowrap">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
                {{ t('logbook.gap_add_custom') }}
              </button>
            </div>
            <p v-if="gapTemplates.length === 0" class="text-xs text-neutral-500 mt-1.5">{{ t('logbook.gap_no_templates') }}</p>
          </div>

          <div v-if="rows.length" class="space-y-2">
            <div v-for="r in rows" :key="r.key" class="rounded-md border border-neutral-200 p-3 space-y-2">
              <div class="flex flex-wrap items-end gap-2">
                <div>
                  <label class="block text-xs text-neutral-500 mb-0.5">{{ t('logbook.date') }}</label>
                  <DateInput v-model="r.trip_date" required :min="fillGap.date_from ?? undefined" :max="fillGap.date_to" class="h-9 px-2 border border-neutral-300 rounded-md text-sm" />
                </div>
                <div class="w-28">
                  <label class="block text-xs text-neutral-500 mb-0.5">{{ t('logbook.distance_km') }}</label>
                  <input v-model.number="r.distance_km" type="number" min="0.1" step="0.1" required class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono" />
                </div>
                <div class="flex-1 min-w-[10rem]">
                  <label class="block text-xs text-neutral-500 mb-0.5">{{ t('logbook.category') }}</label>
                  <select v-model="r.category_id" class="w-full h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm">
                    <option :value="null">—</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.label }}</option>
                  </select>
                </div>
                <button type="button" @click="removeRow(r.key)" :title="t('common.delete')" :class="btnOutlineSm('danger')">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                </button>
              </div>
              <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                <input v-model="r.origin" type="text" maxlength="255" :placeholder="t('logbook.origin')" class="h-9 px-2 border border-neutral-300 rounded-md text-sm" />
                <input v-model="r.destination" type="text" maxlength="255" :placeholder="t('logbook.destination')" class="h-9 px-2 border border-neutral-300 rounded-md text-sm" />
                <input v-model="r.purpose" type="text" maxlength="255" :placeholder="t('logbook.purpose')" class="h-9 px-2 border border-neutral-300 rounded-md text-sm" />
              </div>
            </div>
          </div>

          <div class="rounded-md bg-neutral-50 px-3 py-2 text-sm space-y-1.5">
            <div class="flex flex-wrap justify-between gap-2">
              <span>{{ t('logbook.gap_sum', { rows: fmtKm(rowsKm), gap: fmtKm(fillGap.km) }) }}</span>
              <span class="font-mono" :class="remainderKm < 0 ? 'text-danger-600 font-semibold' : 'text-neutral-700'">
                {{ t('logbook.gap_remainder', { km: fmtKm(remainderKm) }) }}
              </span>
            </div>
            <label class="flex items-center gap-2" :class="hasPrivateCategory ? '' : 'opacity-50'">
              <input v-model="remainderPrivate" type="checkbox" :disabled="!hasPrivateCategory" class="rounded border-neutral-300 text-primary-600" />
              {{ t('logbook.gap_remainder_private') }}
            </label>
            <p v-if="!hasPrivateCategory" class="text-xs text-warning-700">{{ t('logbook.gap_no_private_category') }}</p>
            <p v-else-if="!remainderPrivate && remainderKm > 0" class="text-xs text-neutral-500">{{ t('logbook.gap_partial_hint') }}</p>
          </div>

          <div class="flex flex-wrap justify-end gap-2 pt-2 border-t border-neutral-100">
            <button type="button" @click="fillGap = null" :class="btnOutline('neutral')" class="whitespace-nowrap">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
              {{ t('common.cancel') }}
            </button>
            <button type="submit" :disabled="filling || remainderKm < 0" :class="btnFilled('primary')" class="whitespace-nowrap">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
              {{ t('logbook.gap_save') }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Modal: stavy tachometru -->
    <div v-if="readingsOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="p-5 space-y-4">
          <div>
            <h2 class="text-lg font-semibold">{{ t('logbook.readings_title') }}</h2>
            <p class="text-sm text-neutral-500 mt-0.5">{{ t('logbook.readings_hint') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.car') }}</label>
            <select v-model.number="readingCar" @change="loadReadings" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
              <option v-for="c in cars" :key="c.id" :value="c.id">{{ c.registration }}{{ c.name ? ` — ${c.name}` : '' }}</option>
            </select>
          </div>
          <form v-if="canWrite" @submit.prevent="saveReading" class="flex flex-wrap items-end gap-2">
            <div>
              <label class="block text-xs text-neutral-500 mb-0.5">{{ t('logbook.date') }}</label>
              <DateInput v-model="readingDraft.reading_date" required class="h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div class="flex-1 min-w-[8rem]">
              <label class="block text-xs text-neutral-500 mb-0.5">{{ t('logbook.reading_odometer') }}</label>
              <input v-model.number="readingDraft.odometer" type="number" min="0" required class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <button type="submit" :disabled="savingReading" :class="btnFilled('primary')" class="whitespace-nowrap">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.gauge" /></svg>
              {{ t('logbook.reading_save') }}
            </button>
          </form>
          <div v-if="readings.length" class="border border-neutral-200 rounded-md divide-y divide-neutral-100 text-sm">
            <div v-for="r in readings" :key="r.id" class="flex items-center justify-between gap-2 px-3 py-2">
              <span>{{ formatDate(r.reading_date) }}<span v-if="r.reading_time" class="text-neutral-500"> {{ r.reading_time }}</span></span>
              <span v-if="r.source !== 'manual'" class="text-xs px-1.5 py-0.5 rounded bg-primary-50 text-primary-700">{{ te(`logbook.odosrc_providers.${r.source}.short`) ? t(`logbook.odosrc_providers.${r.source}.short`) : r.source }}</span>
              <span class="font-mono ml-auto">{{ fmtKm(r.odometer) }} km</span>
              <button v-if="canDelete" @click="removeReading(r)" :title="t('common.delete')" class="cursor-pointer text-danger-500 hover:text-danger-600">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
              </button>
            </div>
          </div>
          <p v-else class="text-sm text-neutral-500">{{ t('logbook.readings_empty') }}</p>
          <div class="flex justify-end pt-2 border-t border-neutral-100">
            <button type="button" @click="readingsOpen = false" :class="btnOutline('neutral')" class="whitespace-nowrap">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
              {{ t('common.close') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
