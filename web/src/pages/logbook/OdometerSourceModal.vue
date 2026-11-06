<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import { formatDateTime } from '@/composables/useFormat'
import {
  logbookApi, type Car, type OdometerSourceAccount, type OdometerSourceDefinition, type OdometerSourceStatus,
  type OdometerSourceStep, type OdometerSourceVehicle,
} from '@/api/logbook'
import { useAuthStore } from '@/stores/auth'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const { t, te, tm, rt } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const props = defineProps<{ car: Car }>()
const emit = defineEmits<{ close: []; changed: [] }>()

const canWrite = computed(() => auth.canWrite('logbook.write'))
const canDelete = computed(() => auth.canWrite('logbook.delete'))

const loading = ref(true)
const busy = ref(false)
const sources = ref<OdometerSourceDefinition[]>([])
const status = ref<OdometerSourceStatus | null>(null)
const providerCode = ref('')
/** Účet, ke kterému se uživatel přihlašuje znovu (propadlý přístup). */
const reauthAccount = ref<OdometerSourceAccount | null>(null)
const input = ref<Record<string, string>>({})
const pendingStep = ref<Extract<OdometerSourceStep, { state: 'device_code' | 'redirect' }> | null>(null)
const vehicles = ref<OdometerSourceVehicle[]>([])
const vehicleAccountId = ref(0)
const selectedVehicle = ref('')

const assigned = computed(() => status.value?.car ?? null)
const account = computed(() => status.value?.account ?? null)
const accounts = computed(() => status.value?.accounts ?? [])
const source = computed(() => sources.value.find(s => s.code === providerCode.value) ?? null)
const sourceLabel = computed(() => labelOf(providerCode.value))
const step = computed<'status' | 'vehicle' | 'pending' | 'pick' | 'form'>(() => {
  if (vehicles.value.length) return 'vehicle'
  if (pendingStep.value) return 'pending'
  if (source.value) return 'form'
  if (assigned.value && account.value) return 'status'
  return 'pick'
})
const formValid = computed(() => !!source.value && source.value.fields.every(f => (input.value[f.key] ?? '').trim() !== ''))
/** Další auta stejného účtu — účet je jeden pro všechna, sdílí přístup i denní limit. */
const siblingCars = computed(() => (account.value?.cars ?? []).filter(c => c.car_id !== props.car.id).map(c => c.registration))

function labelOf(code: string): string {
  return sources.value.find(s => s.code === code)?.label ?? code
}
function providerKey(suffix: string, code = providerCode.value): string {
  return `logbook.odosrc_providers.${code}.${suffix}`
}
/** `te()` vrací pro pole false — návod se proto čte přes `tm()`. */
function providerList(suffix: string, code = providerCode.value): string[] {
  const list = tm(providerKey(suffix, code))
  return Array.isArray(list) ? list.map(s => rt(s)) : []
}
const steps = computed(() => providerList('steps'))
function sourceDescription(code: string): string {
  return te(providerKey('description', code)) ? t(providerKey('description', code)) : ''
}
function fieldLabel(key: string): string {
  return te(providerKey(`field_${key}`)) ? t(providerKey(`field_${key}`)) : key
}
function accountCars(a: OdometerSourceAccount): string {
  return a.cars.map(c => c.registration).join(', ')
}

let pollTimer: ReturnType<typeof setTimeout> | null = null
let deadline = 0
function stopPolling() {
  if (pollTimer) clearTimeout(pollTimer)
  pollTimer = null
}
onBeforeUnmount(stopPolling)

/** Hláška chyby: nejdřív text konkrétního zdroje, pak obecný, nakonec zpráva serveru. */
function errorFor(code: string | null | undefined, fallback?: string, provider = providerCode.value): string {
  if (code && te(providerKey(`err.${code}`, provider))) return t(providerKey(`err.${code}`, provider))
  if (code && te(`logbook.odosrc_err.${code}`)) return t(`logbook.odosrc_err.${code}`)
  return fallback ?? code ?? t('common.error')
}
function errorText(e: any): string {
  return errorFor(e?.response?.data?.error?.code, e?.response?.data?.error?.message ?? t('common.error'))
}

function pickSource(code: string) {
  providerCode.value = code
  input.value = Object.fromEntries((source.value?.fields ?? []).map(f => [f.key, '']))
}
function backToPick() {
  providerCode.value = ''
  reauthAccount.value = null
}
function reconnect() {
  if (!account.value) return
  reauthAccount.value = account.value
  pickSource(account.value.provider)
}

function applyStatus(s: OdometerSourceStatus) {
  status.value = { car: s.car, account: s.account, accounts: s.accounts }
}

async function load() {
  loading.value = true
  try {
    const [catalog, current] = await Promise.all([
      sources.value.length ? Promise.resolve(sources.value) : logbookApi.odometerSources(),
      logbookApi.odometerSourceStatus(props.car.id),
    ])
    sources.value = catalog
    applyStatus(current)
  } catch (e) {
    toast.error(errorText(e))
  } finally {
    loading.value = false
  }
}
onMounted(load)

function connected(result: OdometerSourceStatus) {
  const label = labelOf(result.account?.provider ?? providerCode.value)
  vehicles.value = []
  pendingStep.value = null
  backToPick()
  applyStatus(result)
  toast.success(t('logbook.odosrc_connected', { label }))
  emit('changed')
}

function handleStep(result: OdometerSourceStep) {
  if (result.state === 'device_code' || result.state === 'redirect') {
    pendingStep.value = result
    deadline = Date.now() + result.expires_in * 1000
    schedulePoll(result.state === 'device_code' ? result.interval : 5)
    return
  }
  if (result.state === 'waiting') { schedulePoll(result.interval); return }
  pendingStep.value = null
  if (result.state === 'select_vehicle') {
    vehicles.value = result.vehicles
    vehicleAccountId.value = result.account_id
    selectedVehicle.value = result.vehicles[0]?.ref ?? ''
    return
  }
  connected(result)
}

async function start() {
  busy.value = true
  try {
    const trimmed = Object.fromEntries(Object.entries(input.value).map(([k, v]) => [k, v.trim()]))
    handleStep(await logbookApi.odometerSourceConnect(props.car.id, providerCode.value, trimmed, reauthAccount.value?.id))
  } catch (e) {
    toast.error(errorText(e))
  } finally {
    busy.value = false
  }
}

async function attach(a: OdometerSourceAccount) {
  busy.value = true
  providerCode.value = ''
  try {
    const result = await logbookApi.odometerSourceAttach(props.car.id, a.id)
    if (result.state === 'select_vehicle') providerCode.value = a.provider
    handleStep(result)
  } catch (e) {
    toast.error(errorFor((e as any)?.response?.data?.error?.code, (e as any)?.response?.data?.error?.message, a.provider))
  } finally {
    busy.value = false
  }
}

function schedulePoll(seconds: number) {
  stopPolling()
  pollTimer = setTimeout(poll, Math.max(1, seconds) * 1000)
}

async function poll() {
  pollTimer = null
  if (Date.now() > deadline) {
    pendingStep.value = null
    toast.error(errorFor('expired_token'))
    return
  }
  try {
    handleStep(await logbookApi.odometerSourceContinue(props.car.id))
  } catch (e) {
    pendingStep.value = null
    toast.error(errorText(e))
  }
}

async function chooseVehicle() {
  if (!selectedVehicle.value) return
  busy.value = true
  try {
    handleStep(await logbookApi.odometerSourceSelectVehicle(props.car.id, vehicleAccountId.value, selectedVehicle.value))
  } catch (e) {
    toast.error(errorText(e))
  } finally {
    busy.value = false
  }
}

async function syncNow() {
  busy.value = true
  const provider = account.value?.provider
  try {
    const result = await logbookApi.odometerSourceSync(props.car.id)
    applyStatus(result)
    toast.success(t('logbook.odosrc_synced', { km: result.reading.odometer.toLocaleString('cs-CZ') }))
    emit('changed')
  } catch (e) {
    toast.error(errorFor((e as any)?.response?.data?.error?.code, (e as any)?.response?.data?.error?.message, provider))
    await load()
    emit('changed')
  } finally {
    busy.value = false
  }
}

async function disconnect() {
  const label = labelOf(account.value?.provider ?? '')
  const message = siblingCars.value.length
    ? t('logbook.odosrc_disconnect_confirm', { label })
    : t('logbook.odosrc_disconnect_last_confirm', { label })
  if (!confirm(message)) return
  busy.value = true
  try {
    await logbookApi.odometerSourceDisconnect(props.car.id)
    await load()
    emit('changed')
  } catch (e) {
    toast.error(errorText(e))
  } finally {
    busy.value = false
  }
}

function close() {
  stopPolling()
  emit('close')
}
</script>

<template>
  <div class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-surface rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
      <div class="p-5 space-y-4">
        <div>
          <h2 class="text-lg font-semibold">{{ t('logbook.odosrc_title') }}</h2>
          <p class="text-sm text-neutral-500 mt-0.5">{{ car.registration }}{{ car.name ? ` — ${car.name}` : '' }} · {{ t('logbook.odosrc_hint') }}</p>
        </div>

        <div v-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>

        <!-- Připojeno -->
        <div v-else-if="step === 'status' && assigned && account" class="space-y-3">
          <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
            <dt class="text-neutral-500">{{ t('logbook.odosrc_source') }}</dt><dd>{{ labelOf(account.provider) }}</dd>
            <dt class="text-neutral-500">{{ t('logbook.odosrc_vehicle') }}</dt><dd class="font-mono">{{ assigned.vehicle_ref }}</dd>
            <dt class="text-neutral-500">{{ t('logbook.odosrc_last_odometer') }}</dt>
            <dd class="font-mono">{{ assigned.last_odometer != null ? `${assigned.last_odometer.toLocaleString('cs-CZ')} km` : '—' }}
              <span v-if="assigned.last_value_at" class="text-neutral-500 font-sans"> · {{ formatDateTime(assigned.last_value_at) }}</span></dd>
            <dt class="text-neutral-500">{{ t('logbook.odosrc_last_sync') }}</dt>
            <dd>{{ assigned.last_sync_at ? formatDateTime(assigned.last_sync_at) : '—' }}</dd>
          </dl>
          <div v-if="account.status === 'error'" class="rounded-md border border-danger-500/40 bg-danger-50 px-3 py-2 text-sm text-danger-700">
            {{ errorFor(account.last_sync_error_code, undefined, account.provider) }}
          </div>
          <div v-else-if="assigned.last_sync_status === 'error'" class="rounded-md border border-danger-500/40 bg-danger-50 px-3 py-2 text-sm text-danger-700">
            {{ errorFor(assigned.last_sync_error_code, undefined, account.provider) }}
          </div>
          <div v-else-if="assigned.last_sync_status === 'warning'" class="rounded-md border border-warning-500/40 bg-warning-50 px-3 py-2 text-sm text-warning-700">
            {{ errorFor(assigned.last_sync_error_code, undefined, account.provider) }}
            <template v-if="assigned.last_sync_error_code === 'odometer_regression'">{{ t('logbook.odosrc_warning_hint') }}</template>
          </div>
          <p v-if="siblingCars.length" class="text-sm text-neutral-600">{{ t('logbook.odosrc_shared', { cars: siblingCars.join(', ') }) }}</p>
          <p class="text-xs text-neutral-500">
            {{ t('logbook.odosrc_daily_hint') }}
            <template v-if="account.daily_call_limit">{{ t('logbook.odosrc_daily_limit', { label: labelOf(account.provider), n: account.daily_call_limit, used: account.calls_today }) }}</template>
          </p>
        </div>

        <!-- Výběr auta z účtu služby -->
        <form v-else-if="step === 'vehicle'" @submit.prevent="chooseVehicle" class="space-y-3">
          <p class="text-sm">{{ t('logbook.odosrc_select_vehicle') }}</p>
          <label v-for="v in vehicles" :key="v.ref" class="flex items-center gap-2 text-sm">
            <input v-model="selectedVehicle" type="radio" :value="v.ref" class="text-primary-600" />
            <span class="font-mono">{{ v.vin ?? v.ref }}</span><span v-if="v.label" class="text-neutral-500">{{ v.label }}</span>
          </label>
          <div class="flex justify-end">
            <button type="submit" :disabled="busy || !selectedVehicle" :class="btnFilled('primary')" class="whitespace-nowrap">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
              {{ t('logbook.odosrc_use_vehicle') }}
            </button>
          </div>
        </form>

        <!-- Čeká na přihlášení u služby -->
        <div v-else-if="step === 'pending' && pendingStep" class="space-y-3 text-sm">
          <template v-if="pendingStep.state === 'device_code'">
            <p>{{ t('logbook.odosrc_code_hint', { label: sourceLabel }) }}</p>
            <div class="text-center font-mono text-2xl tracking-widest py-2 bg-neutral-50 rounded-md select-all">{{ pendingStep.user_code }}</div>
          </template>
          <p v-else>{{ t('logbook.odosrc_redirect_hint', { label: sourceLabel }) }}</p>
          <div class="flex flex-wrap justify-center gap-2">
            <a :href="pendingStep.url" target="_blank" rel="noopener noreferrer" :class="btnFilled('primary')" class="whitespace-nowrap">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
              {{ t('logbook.odosrc_open', { label: sourceLabel }) }}
            </a>
          </div>
          <p class="text-xs text-neutral-500 text-center">{{ t('logbook.odosrc_waiting', { label: sourceLabel }) }}</p>
        </div>

        <!-- Výběr připojeného účtu nebo zdroje -->
        <div v-else-if="step === 'pick' && canWrite" class="space-y-3">
          <template v-if="accounts.length">
            <p class="text-sm">{{ t('logbook.odosrc_pick_account') }}</p>
            <ul class="space-y-2">
              <li v-for="a in accounts" :key="a.id">
                <button type="button" :disabled="busy" @click="attach(a)"
                  class="w-full flex items-center gap-3 rounded-md border border-neutral-200 px-3 py-2.5 text-left hover:border-primary-400 hover:bg-primary-50">
                  <svg class="w-5 h-5 shrink-0 text-success-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
                  <span class="flex-1 min-w-0">
                    <span class="block text-sm font-medium">{{ labelOf(a.provider) }}</span>
                    <span class="block text-xs text-neutral-500">{{ a.cars.length ? t('logbook.odosrc_account_cars', { cars: accountCars(a) }) : t('logbook.odosrc_account_no_cars') }}</span>
                    <span v-if="a.status === 'error'" class="block text-xs text-danger-700">{{ errorFor(a.last_sync_error_code, undefined, a.provider) }}</span>
                  </span>
                  <svg class="w-4 h-4 shrink-0 -rotate-90 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg>
                </button>
              </li>
            </ul>
            <p class="text-sm pt-1">{{ t('logbook.odosrc_pick_new') }}</p>
          </template>
          <p v-else class="text-sm">{{ t('logbook.odosrc_pick') }}</p>
          <ul class="space-y-2">
            <li v-for="s in sources" :key="s.code">
              <button type="button" :disabled="busy" @click="pickSource(s.code)"
                class="w-full flex items-center gap-3 rounded-md border border-neutral-200 px-3 py-2.5 text-left hover:border-primary-400 hover:bg-primary-50">
                <svg class="w-5 h-5 shrink-0 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.gauge" /></svg>
                <span class="flex-1 min-w-0">
                  <span class="block text-sm font-medium">{{ s.label }}</span>
                  <span v-if="sourceDescription(s.code)" class="block text-xs text-neutral-500">{{ sourceDescription(s.code) }}</span>
                </span>
                <svg class="w-4 h-4 shrink-0 -rotate-90 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg>
              </button>
            </li>
          </ul>
          <p v-if="!sources.length" class="text-sm text-neutral-500">{{ t('logbook.odosrc_none') }}</p>
          <p class="text-xs text-neutral-500">{{ t('logbook.odosrc_missing') }}</p>
        </div>

        <!-- Přihlášení ke zdroji (nový účet nebo znovu k propadlému) -->
        <form v-else-if="step === 'form' && canWrite" @submit.prevent="start" class="space-y-3">
          <p v-if="source" class="text-sm font-medium">{{ source.label }}</p>
          <p v-if="reauthAccount" class="text-sm text-neutral-600">{{ t('logbook.odosrc_reauth_hint', { cars: accountCars(reauthAccount) }) }}</p>
          <ol v-if="steps.length" class="list-decimal pl-5 text-sm space-y-1 text-neutral-700">
            <li v-for="(s, i) in steps" :key="i">{{ s }}</li>
          </ol>
          <div v-for="f in source?.fields ?? []" :key="f.key">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ fieldLabel(f.key) }}</label>
            <input v-model="input[f.key]" required :placeholder="f.placeholder" :pattern="f.pattern"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <p class="text-xs text-neutral-500">{{ t('logbook.odosrc_privacy') }}</p>
          <div class="flex flex-wrap justify-end gap-2">
            <button type="button" @click="backToPick" :disabled="busy" :class="btnOutline('neutral')" class="whitespace-nowrap">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="reauthAccount ? ICONS.x : ICONS.uturn" /></svg>
              {{ reauthAccount ? t('common.cancel') : t('logbook.odosrc_other_source') }}
            </button>
            <button type="submit" :disabled="busy || !formValid" :class="btnFilled('primary')" class="whitespace-nowrap">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
              {{ t('logbook.odosrc_connect') }}
            </button>
          </div>
        </form>

        <div class="flex flex-wrap justify-end gap-2 pt-2 border-t border-neutral-100">
          <template v-if="step === 'status' && canWrite">
            <button v-if="canDelete" type="button" @click="disconnect" :disabled="busy" :class="btnOutline('danger')" class="whitespace-nowrap">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
              {{ t('logbook.odosrc_disconnect') }}
            </button>
            <button type="button" @click="reconnect" :disabled="busy" :class="btnOutline('neutral')" class="whitespace-nowrap">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
              {{ t('logbook.odosrc_reconnect') }}
            </button>
            <button type="button" @click="syncNow" :disabled="busy" :class="btnFilled('success')" class="whitespace-nowrap">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.gauge" /></svg>
              {{ t('logbook.odosrc_sync') }}
            </button>
          </template>
          <button type="button" @click="close" :class="btnOutline('neutral')" class="whitespace-nowrap">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.close') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
