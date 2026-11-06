<script setup lang="ts">
import { computed, onActivated, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Modal from '@/components/ui/Modal.vue'
import DateInput from '@/components/ui/DateInput.vue'
import ToggleSwitch from '@/components/ui/ToggleSwitch.vue'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import { formInput, formLabel, formBox } from '@/components/ui/formStyles'
import {
  eetApi,
  type EetActivationObstacle,
  type EetCertificate,
  type EetDelegationMode,
  type EetDevice,
  type EetDiagnosticsResult,
  type EetEnvironment,
  type EetRegime,
  type EetSettings,
  type EetUnit,
  type EetUnitKind,
} from '@/api/eet'
import { cashApi, type CashRegister } from '@/api/cash'
import { authApi } from '@/api/auth'
import { apiErrorMessage } from '@/api/errors'
import { getCredential, isWebAuthnAvailable } from '@/security/webauthn'
import { useToast } from '@/composables/useToast'
import { formatDate, formatDateTime } from '@/composables/useFormat'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const FS_URL = 'https://www.mojedane.cz'
const EET_START_URL = 'https://eet.gov.cz/cs/zacinam-s-eet/jak-zacit-evidovat'
const UNIT_KINDS: EetUnitKind[] = ['premises', 'online', 'vehicle', 'mobile', 'other']
const REGIMES: EetRegime[] = ['standard', 'exempt', 'eet_off']

const canWrite = computed(() => auth.canWrite('eet.settings'))
const canReadCerts = computed(() => auth.canRead('eet.certificates'))
const canWriteCerts = computed(() => auth.canWrite('eet.certificates'))

const loading = ref(false)
const settings = ref<EetSettings | null>(null)
const obstacles = ref<EetActivationObstacle[]>([])
const units = ref<EetUnit[]>([])
const devices = ref<EetDevice[]>([])
const certificates = ref<EetCertificate[]>([])
const registers = ref<CashRegister[]>([])

interface ProfileDraft {
  environment: EetEnvironment
  eic: string
  regime: EetRegime
  exemption_reason: string
  active_from: string
  response_timeout_ms: number
  default_device_id: number | null
}
const draft = reactive<ProfileDraft>({
  environment: 'playground', eic: '', regime: 'standard', exemption_reason: '',
  active_from: '', response_timeout_ms: 3000, default_device_id: null,
})
const saving = ref(false)

function fillDraft(s: EetSettings) {
  draft.environment = s.environment
  draft.eic = s.eic ?? ''
  draft.regime = s.regime
  draft.exemption_reason = s.exemption_reason ?? ''
  draft.active_from = s.active_from ?? ''
  draft.response_timeout_ms = s.response_timeout_ms
  draft.default_device_id = s.default_device_id
}

const dirty = computed(() => {
  const s = settings.value
  if (!s) return false
  return draft.environment !== s.environment
    || draft.eic.trim() !== (s.eic ?? '')
    || draft.regime !== s.regime
    || draft.exemption_reason.trim() !== (s.exemption_reason ?? '')
    || draft.active_from !== (s.active_from ?? '')
    || Number(draft.response_timeout_ms) !== s.response_timeout_ms
    || draft.default_device_id !== s.default_device_id
})

async function load(silent = false) {
  if (!silent) loading.value = true
  try {
    const [s, u, d] = await Promise.all([eetApi.settings(), eetApi.units(), eetApi.devices()])
    // Rozpracovaný draft se posuzuje proti dosavadnímu stavu, ne proti právě načtenému.
    const keepDraft = dirty.value
    settings.value = s.settings
    obstacles.value = s.activation_obstacles
    units.value = u
    devices.value = d
    if (!keepDraft) fillDraft(s.settings)
    certificates.value = canReadCerts.value ? await eetApi.certificates() : []
    // Pokladny jsou jen volitelná vazba zařízení; bez práva na pokladnu se výběr nenabídne.
    registers.value = await cashApi.listRegisters().catch(() => [])
  } catch (e) {
    toast.error(apiErrorMessage(e, t('eet.load_failed')))
  } finally {
    loading.value = false
  }
}
onMounted(() => { void load() })
onActivated(() => { if (settings.value) void load(true) })

const today = () => new Date().toISOString().slice(0, 10)
const activeDevices = computed(() => devices.value.filter(d => d.active))
const validUnits = computed(() => units.value.filter(u => u.valid_from <= today() && (!u.valid_to || u.valid_to >= today())))
const activeCertificate = computed(() =>
  certificates.value.find(c => c.status === 'active' && c.valid_now
    && c.environment === (settings.value?.environment ?? 'playground')
    && (!settings.value?.eic || c.eic === settings.value.eic)) ?? null)
const diagnostics = ref<EetDiagnosticsResult | null>(null)
const diagnosticsBusy = ref(false)
const diagnosticsDeviceId = ref<number | null>(null)

interface Step { key: string; done: boolean }
const steps = computed<Step[]>(() => [
  { key: 'eic', done: !!settings.value?.eic },
  { key: 'units', done: validUnits.value.length > 0 },
  { key: 'devices', done: activeDevices.value.length > 0 },
  { key: 'certificate', done: activeCertificate.value !== null },
  { key: 'diagnostics', done: diagnostics.value?.ok === true },
  { key: 'activation', done: settings.value?.enabled === true },
])

const isProduction = computed(() => (settings.value?.environment ?? draft.environment) === 'production')

// ---- Profil ----
async function saveProfile() {
  if (!settings.value || !canWrite.value) return
  if (draft.regime === 'exempt' && draft.exemption_reason.trim() === '') {
    toast.error(t('eet.profile.exemption_reason_required'))
    return
  }
  if (draft.environment === 'production' && settings.value.environment !== 'production') {
    askConfirm({
      title: t('eet.profile.production_confirm_title'),
      message: t('eet.profile.production_confirm'),
      confirm: t('eet.profile.production_confirm_action'),
      variant: 'warning',
      run: doSaveProfile,
    })
    return
  }
  await doSaveProfile()
}

async function doSaveProfile() {
  if (!settings.value) return
  saving.value = true
  try {
    const res = await eetApi.saveSettings({
      environment: draft.environment,
      eic: draft.eic.trim() === '' ? null : draft.eic.trim().toUpperCase(),
      regime: draft.regime,
      exemption_reason: draft.regime === 'exempt' ? draft.exemption_reason.trim() : null,
      active_from: draft.active_from === '' ? null : draft.active_from,
      response_timeout_ms: Number(draft.response_timeout_ms),
      default_device_id: draft.default_device_id,
      row_version: settings.value.row_version,
    })
    settings.value = res.settings
    obstacles.value = res.activation_obstacles
    fillDraft(res.settings)
    diagnostics.value = null
    toast.success(t('eet.profile.saved'))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('eet.profile.save_failed')))
  } finally {
    saving.value = false
  }
}

function resetProfile() {
  if (settings.value) fillDraft(settings.value)
}

// ---- Potvrzovací dialog ----
interface ConfirmState {
  title: string
  message: string
  confirm: string
  variant: 'danger' | 'warning' | 'primary'
  run: () => Promise<void>
}
const confirmState = ref<ConfirmState | null>(null)
const confirmBusy = ref(false)
function askConfirm(state: ConfirmState) { confirmState.value = state }
async function runConfirm() {
  if (!confirmState.value) return
  confirmBusy.value = true
  try {
    await confirmState.value.run()
  } finally {
    confirmBusy.value = false
    confirmState.value = null
  }
}

// ---- Jednotky ----
interface UnitForm { id: number | null; unit_number: string; name: string; kind: EetUnitKind; valid_from: string; valid_to: string; notified_to_fs: boolean }
const unitForm = ref<UnitForm | null>(null)
const unitBusy = ref(false)
function openUnit(u?: EetUnit) {
  unitForm.value = u
    ? { id: u.id, unit_number: String(u.unit_number), name: u.name, kind: u.kind, valid_from: u.valid_from, valid_to: u.valid_to ?? '', notified_to_fs: u.notified_to_fs }
    : { id: null, unit_number: '', name: '', kind: 'premises', valid_from: today(), valid_to: '', notified_to_fs: true }
}
async function saveUnit() {
  const f = unitForm.value
  if (!f) return
  unitBusy.value = true
  try {
    const payload = {
      unit_number: Number(f.unit_number),
      name: f.name.trim(),
      kind: f.kind,
      valid_from: f.valid_from,
      valid_to: f.valid_to === '' ? null : f.valid_to,
      notified_to_fs: f.notified_to_fs,
    }
    if (f.id === null) await eetApi.createUnit(payload)
    else await eetApi.updateUnit(f.id, payload)
    unitForm.value = null
    toast.success(t('eet.units.saved'))
    await load(true)
  } catch (e) {
    toast.error(apiErrorMessage(e, t('eet.units.save_failed')))
  } finally {
    unitBusy.value = false
  }
}
function endUnit(u: EetUnit) {
  askConfirm({
    title: t('eet.units.end_title'),
    message: t('eet.units.end_confirm', { number: u.unit_number, name: u.name }),
    confirm: t('eet.units.end'),
    variant: 'danger',
    run: async () => {
      try {
        await eetApi.endUnit(u.id)
        toast.success(t('eet.units.ended'))
        await load(true)
      } catch (e) {
        toast.error(apiErrorMessage(e, t('eet.units.end_failed')))
      }
    },
  })
}
function unitEnded(u: EetUnit): boolean { return !!u.valid_to && u.valid_to < today() }

// ---- Zařízení ----
interface DeviceForm {
  id: number | null; unit_id: number | null; code: string; name: string; cash_register_id: number | null
  delegation: EetDelegationMode; delegating_eic: string; active: boolean
}
const deviceForm = ref<DeviceForm | null>(null)
const deviceBusy = ref(false)
const DELEGATION_MODES: EetDelegationMode[] = ['none', 'single', 'multiple']
function openDevice(d?: EetDevice) {
  deviceForm.value = d
    ? {
        id: d.id, unit_id: d.unit_id, code: d.code, name: d.name ?? '', cash_register_id: d.cash_register_id,
        delegation: d.delegation ?? 'none', delegating_eic: d.delegating_eic ?? '', active: d.active,
      }
    : { id: null, unit_id: validUnits.value[0]?.id ?? null, code: '', name: '', cash_register_id: null, delegation: 'none', delegating_eic: '', active: true }
}
// Situace 4 vyžaduje EIČ pověřujícího, situace 5 ho má nepovinně, bez pověření se neuvádí.
const deviceDelegationIncomplete = computed(() =>
  deviceForm.value?.delegation === 'single' && deviceForm.value.delegating_eic.trim() === '')
function registerLabel(id: number | null): string {
  if (id === null) return ''
  const r = registers.value.find(x => x.id === id)
  return r ? `${r.name} (${r.account_code})` : `#${id}`
}
// Do nabídky patří aktivní pokladny a ta, která je k zařízení už přiřazená (i kdyby byla neaktivní).
const registerOptions = computed(() =>
  registers.value.filter(r => r.is_active || r.id === deviceForm.value?.cash_register_id))
async function saveDevice() {
  const f = deviceForm.value
  if (!f || f.unit_id === null) return
  deviceBusy.value = true
  try {
    const payload = {
      unit_id: f.unit_id, code: f.code.trim(), name: f.name.trim() === '' ? null : f.name.trim(),
      cash_register_id: f.delegation === 'none' ? f.cash_register_id : null, active: f.active,
      delegation: f.delegation,
      delegating_eic: f.delegation === 'none' || f.delegating_eic.trim() === '' ? null : f.delegating_eic.trim().toUpperCase(),
    }
    if (f.id === null) await eetApi.createDevice(payload)
    else await eetApi.updateDevice(f.id, payload)
    deviceForm.value = null
    toast.success(t('eet.devices.saved'))
    await load(true)
  } catch (e) {
    toast.error(apiErrorMessage(e, t('eet.devices.save_failed')))
  } finally {
    deviceBusy.value = false
  }
}
function deactivateDevice(d: EetDevice) {
  askConfirm({
    title: t('eet.devices.deactivate_title'),
    message: t('eet.devices.deactivate_confirm', { code: d.code }),
    confirm: t('eet.devices.deactivate'),
    variant: 'danger',
    run: async () => {
      try {
        await eetApi.deactivateDevice(d.id)
        toast.success(t('eet.devices.deactivated'))
        await load(true)
      } catch (e) {
        toast.error(apiErrorMessage(e, t('eet.devices.deactivate_failed')))
      }
    },
  })
}
async function setDefaultDevice(d: EetDevice) {
  if (!settings.value) return
  try {
    const res = await eetApi.saveSettings({ default_device_id: d.id, row_version: settings.value.row_version })
    settings.value = res.settings
    obstacles.value = res.activation_obstacles
    draft.default_device_id = res.settings.default_device_id
    toast.success(t('eet.devices.default_set'))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('eet.devices.save_failed')))
  }
}

// ---- Certifikáty ----
const certForm = ref<{ environment: EetEnvironment; label: string; password: string } | null>(null)
const certFile = ref<File | null>(null)
const certFileInput = ref<HTMLInputElement | null>(null)
const certBusy = ref(false)
const stepPassword = ref('')
const stepTotp = ref('')
const stepPasskeyToken = ref('')
const passkeyBusy = ref(false)
const passkeySupported = isWebAuthnAvailable()
const hasPasskey = computed(() =>
  auth.user?.mfa_methods?.includes('passkey') === true || (auth.user?.passkey_count ?? 0) > 0)
const hasTotp = computed(() => auth.user?.totp_enabled === true)

const stepUpMissing = computed(() => {
  if (stepPasskeyToken.value) return ''
  if (!stepPassword.value) return t('eet.certificates.step_up_password_missing')
  if (hasTotp.value && !/^\d{6}$/.test(stepTotp.value.trim())) return t('eet.certificates.step_up_totp_missing')
  return ''
})
const canUpload = computed(() =>
  !certBusy.value && certFile.value !== null && !!certForm.value && certForm.value.password !== '' && stepUpMissing.value === '')

function openCertUpload() {
  certForm.value = { environment: settings.value?.environment ?? 'playground', label: '', password: '' }
  certFile.value = null
  resetStepUp()
}
function closeCertUpload() {
  certForm.value = null
  certFile.value = null
  resetStepUp()
}
function resetStepUp() {
  stepPassword.value = ''
  stepTotp.value = ''
  stepPasskeyToken.value = ''
}
function onCertFile(event: Event) {
  certFile.value = (event.target as HTMLInputElement).files?.[0] ?? null
}
async function verifyPasskey() {
  if (!passkeySupported) return
  passkeyBusy.value = true
  try {
    const flow = await authApi.passkeyStepUpOptions('epo.certificate')
    const credential = await getCredential(flow.public_key)
    stepPasskeyToken.value = await authApi.passkeyStepUpVerify(flow.flow_token, 'epo.certificate', credential)
  } catch (e) {
    toast.error(apiErrorMessage(e, t('eet.certificates.passkey_failed')))
  } finally {
    passkeyBusy.value = false
  }
}
async function uploadCertificate() {
  if (!certForm.value || !certFile.value) return
  if (stepUpMissing.value !== '') {
    toast.error(stepUpMissing.value)
    return
  }
  certBusy.value = true
  try {
    await eetApi.uploadCertificate({
      file: certFile.value,
      password: certForm.value.password,
      environment: certForm.value.environment,
      label: certForm.value.label.trim() || undefined,
      proof: {
        password: stepPassword.value || undefined,
        totp_code: stepTotp.value.trim() || undefined,
        step_up_token: stepPasskeyToken.value || undefined,
      },
    })
    toast.success(t('eet.certificates.uploaded'))
    closeCertUpload()
    await load(true)
  } catch (e) {
    stepPasskeyToken.value = ''
    toast.error(apiErrorMessage(e, t('eet.certificates.upload_failed')))
  } finally {
    certBusy.value = false
  }
}
// Deaktivace vyžaduje stejné opětovné ověření jako nahrání.
const deactivateTarget = ref<EetCertificate | null>(null)
function deactivateCertificate(c: EetCertificate) {
  resetStepUp()
  deactivateTarget.value = c
}
function closeDeactivate() {
  deactivateTarget.value = null
  resetStepUp()
}
async function runDeactivate() {
  const c = deactivateTarget.value
  if (!c) return
  if (stepUpMissing.value !== '') {
    toast.error(stepUpMissing.value)
    return
  }
  certBusy.value = true
  try {
    await eetApi.deactivateCertificate(c.id, {
      password: stepPassword.value || undefined,
      totp_code: stepTotp.value.trim() || undefined,
      step_up_token: stepPasskeyToken.value || undefined,
    })
    toast.success(t('eet.certificates.deactivated'))
    closeDeactivate()
    await load(true)
  } catch (e) {
    stepPasskeyToken.value = ''
    toast.error(apiErrorMessage(e, t('eet.certificates.deactivate_failed')))
  } finally {
    certBusy.value = false
  }
}
function expiryBadge(c: EetCertificate): 'expired' | 'critical' | 'warning' | null {
  if (c.status !== 'active') return null
  if (c.expiry_warning) return c.expiry_warning
  return c.expires_in_days <= 30 ? 'warning' : null
}
const BADGE = 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset whitespace-nowrap'
const BADGE_CLASS = {
  ok: 'bg-success-50 text-success-700 ring-success-600/20',
  warn: 'bg-warning-50 text-warning-700 ring-warning-600/20',
  bad: 'bg-danger-50 text-danger-700 ring-danger-600/20',
  off: 'bg-neutral-100 text-neutral-600 ring-neutral-500/20',
  info: 'bg-primary-50 text-primary-700 ring-primary-600/20',
}
function delegationBadge(d: EetDevice): string {
  return d.delegation === 'single' && d.delegating_eic
    ? t('eet.devices.delegation_badge_single', { eic: d.delegating_eic })
    : t(`eet.devices.delegation_modes.${d.delegation}`)
}
function expiryClass(w: 'expired' | 'critical' | 'warning'): string {
  return w === 'warning' ? BADGE_CLASS.warn : BADGE_CLASS.bad
}

// ---- Diagnostika ----
async function runDiagnostics() {
  diagnosticsBusy.value = true
  diagnostics.value = null
  try {
    diagnostics.value = await eetApi.diagnostics(diagnosticsDeviceId.value)
  } catch (e) {
    toast.error(apiErrorMessage(e, t('eet.diagnostics.failed')))
  } finally {
    diagnosticsBusy.value = false
  }
}

// ---- Aktivace ----
const activationBusy = ref(false)
async function setEnabled(enabled: boolean) {
  if (!settings.value) return
  activationBusy.value = true
  try {
    const res = await eetApi.saveSettings({ enabled, row_version: settings.value.row_version })
    settings.value = res.settings
    obstacles.value = res.activation_obstacles
    toast.success(t(enabled ? 'eet.activation.activated' : 'eet.activation.deactivated'))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('eet.activation.failed')))
  } finally {
    activationBusy.value = false
  }
}
function toggleActivation() {
  if (!settings.value) return
  if (settings.value.enabled) {
    askConfirm({
      title: t('eet.activation.deactivate_title'),
      message: t('eet.activation.deactivate_confirm'),
      confirm: t('eet.activation.deactivate'),
      variant: 'danger',
      run: () => setEnabled(false),
    })
  } else if (settings.value.environment === 'production') {
    askConfirm({
      title: t('eet.activation.production_title'),
      message: t('eet.activation.production_confirm'),
      confirm: t('eet.activation.activate'),
      variant: 'warning',
      run: () => setEnabled(true),
    })
  } else {
    void setEnabled(true)
  }
}

const headerActions = computed<ActionItem[]>(() => [
  { key: 'reload', label: t('eet.reload'), icon: 'cycle', tier: 'secondary', variant: 'neutral', loading: loading.value, run: () => { void load() } },
])

const timeoutInvalid = computed(() => {
  const v = Number(draft.response_timeout_ms)
  return !Number.isFinite(v) || v < 2000 || v > 10000
})
const divider = 'divide-y divide-neutral-100'
const th = 'px-3 py-2 text-left font-medium'
</script>

<template>
  <div class="pb-24">
    <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('eet.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('eet.subtitle') }}</p>
      </div>
      <ActionBar :actions="headerActions" />
    </div>

    <div v-if="loading && !settings" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <template v-else-if="settings">
      <div v-if="isProduction"
        class="mb-4 rounded-lg border border-danger-500/40 bg-danger-50 px-4 py-3 text-sm text-danger-700" data-test="eet-env-production">
        <strong>{{ t('eet.environment.production_banner_title') }}</strong>
        {{ t('eet.environment.production_banner') }}
      </div>
      <div v-else class="mb-4 rounded-lg border border-warning-500/40 bg-warning-50 px-4 py-3 text-sm text-warning-700" data-test="eet-env-playground">
        <strong>{{ t('eet.environment.playground_banner_title') }}</strong>
        {{ t('eet.environment.playground_banner') }}
      </div>

      <!-- Průvodce -->
      <section :class="[formBox, 'mb-4']" data-test="eet-checklist">
        <h2 class="text-base font-semibold">{{ t('eet.wizard.title') }}</h2>
        <p class="text-sm text-neutral-600">{{ t('eet.wizard.intro') }}</p>
        <ol class="space-y-2">
          <li v-for="(s, i) in steps" :key="s.key" class="flex items-start gap-3 text-sm">
            <span class="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold ring-1 ring-inset"
              :class="s.done ? BADGE_CLASS.ok : BADGE_CLASS.off">
              <svg v-if="s.done" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
              <template v-else>{{ i + 1 }}</template>
            </span>
            <span class="min-w-0">
              <span class="font-medium">{{ t(`eet.wizard.step_${s.key}`) }}</span>
              <span class="block text-xs text-neutral-500">{{ t(`eet.wizard.step_${s.key}_hint`) }}</span>
            </span>
          </li>
        </ol>
        <div class="flex flex-wrap gap-2 pt-1">
          <a :href="FS_URL" target="_blank" rel="noopener noreferrer" :class="btnOutline('neutral')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
            {{ t('eet.wizard.link_fs') }}
          </a>
          <a :href="EET_START_URL" target="_blank" rel="noopener noreferrer" :class="btnOutline('neutral')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.help" /></svg>
            {{ t('eet.wizard.link_start') }}
          </a>
        </div>
      </section>

      <!-- Profil -->
      <section :class="[formBox, 'mb-4']" data-test="eet-profile">
        <h2 class="text-base font-semibold">{{ t('eet.profile.title') }}</h2>
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
          <label :class="formLabel">
            <span>{{ t('eet.profile.environment') }}</span>
            <select v-model="draft.environment" :class="formInput" :disabled="!canWrite || settings.enabled">
              <option value="playground">{{ t('eet.environment.playground') }}</option>
              <option value="production">{{ t('eet.environment.production') }}</option>
            </select>
            <span class="text-xs text-neutral-500">{{ settings.enabled ? t('eet.profile.environment_locked') : t('eet.profile.environment_hint') }}</span>
          </label>
          <label :class="formLabel">
            <span>{{ t('eet.profile.eic') }}</span>
            <input v-model="draft.eic" type="text" maxlength="12" placeholder="CZ12345678" :class="formInput" :disabled="!canWrite" />
            <span class="text-xs text-neutral-500">{{ t('eet.profile.eic_hint') }}</span>
          </label>
          <label :class="formLabel">
            <span>{{ t('eet.profile.regime') }}</span>
            <select v-model="draft.regime" :class="formInput" :disabled="!canWrite">
              <option v-for="r in REGIMES" :key="r" :value="r">{{ t(`eet.regime.${r}`) }}</option>
            </select>
            <span class="text-xs text-neutral-500">{{ t(`eet.regime.${draft.regime}_hint`) }}</span>
          </label>
          <label v-if="draft.regime === 'exempt'" :class="formLabel">
            <span>{{ t('eet.profile.exemption_reason') }}</span>
            <input v-model="draft.exemption_reason" type="text" maxlength="255" :class="formInput" :disabled="!canWrite" />
          </label>
          <div :class="formLabel">
            <span>{{ t('eet.profile.active_from') }}</span>
            <DateInput v-model="draft.active_from" :class="formInput" :disabled="!canWrite" />
            <span class="text-xs text-neutral-500">{{ t('eet.profile.active_from_hint') }}</span>
          </div>
          <label :class="formLabel">
            <span>{{ t('eet.profile.timeout') }}</span>
            <input v-model.number="draft.response_timeout_ms" type="number" min="2000" max="10000" step="100"
              :class="[formInput, timeoutInvalid ? 'border-danger-500' : '']" :disabled="!canWrite" />
            <span class="text-xs" :class="timeoutInvalid ? 'text-danger-600' : 'text-neutral-500'">{{ t('eet.profile.timeout_hint') }}</span>
          </label>
        </div>
      </section>

      <!-- Jednotky -->
      <section :class="[formBox, 'mb-4']" data-test="eet-units">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div>
            <h2 class="text-base font-semibold">{{ t('eet.units.title') }}</h2>
            <p class="text-xs text-neutral-500">{{ t('eet.units.hint') }}</p>
          </div>
          <button v-if="canWrite" type="button" :class="btnFilled('primary')" @click="openUnit()">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
            {{ t('eet.units.add') }}
          </button>
        </div>
        <EmptyState v-if="units.length === 0" dense icon="warehouse" :title="t('eet.units.empty')" :message="t('eet.units.empty_hint')" />
        <div v-else class="rounded-lg border border-neutral-200 overflow-hidden">
          <div class="hidden md:block overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th :class="th">{{ t('eet.units.col_number') }}</th>
                  <th :class="th">{{ t('eet.units.col_name') }}</th>
                  <th :class="th">{{ t('eet.units.col_kind') }}</th>
                  <th :class="th">{{ t('eet.units.col_validity') }}</th>
                  <th :class="th">{{ t('eet.units.col_status') }}</th>
                  <th v-if="canWrite" :class="[th, 'text-right']">{{ t('eet.col_actions') }}</th>
                </tr>
              </thead>
              <tbody :class="divider">
                <tr v-for="u in units" :key="u.id">
                  <td class="px-3 py-2 font-mono whitespace-nowrap">{{ u.unit_number }}</td>
                  <td class="px-3 py-2">{{ u.name }}</td>
                  <td class="px-3 py-2 whitespace-nowrap">{{ t(`eet.units.kind.${u.kind}`) }}</td>
                  <td class="px-3 py-2 text-xs text-neutral-600 whitespace-nowrap">
                    {{ formatDate(u.valid_from) }} - {{ u.valid_to ? formatDate(u.valid_to) : t('eet.units.open_ended') }}
                  </td>
                  <td class="px-3 py-2">
                    <span :class="[BADGE, unitEnded(u) ? BADGE_CLASS.off : BADGE_CLASS.ok]">{{ t(unitEnded(u) ? 'eet.units.status_ended' : 'eet.units.status_valid') }}</span>
                    <span v-if="!u.notified_to_fs" :class="[BADGE, BADGE_CLASS.warn, 'ml-1']">{{ t('eet.units.not_notified') }}</span>
                  </td>
                  <td v-if="canWrite" class="px-3 py-2">
                    <div class="flex flex-wrap justify-end gap-1">
                      <button type="button" :class="btnOutlineSm('primary')" @click="openUnit(u)">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                        {{ t('common.edit') }}
                      </button>
                      <button v-if="!unitEnded(u)" type="button" :class="btnOutlineSm('danger')" @click="endUnit(u)">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                        {{ t('eet.units.end') }}
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="md:hidden" :class="divider">
            <div v-for="u in units" :key="`m-${u.id}`" class="px-4 py-3 space-y-2">
              <div class="flex items-center justify-between gap-2">
                <span class="font-medium">{{ u.unit_number }} - {{ u.name }}</span>
                <span :class="[BADGE, unitEnded(u) ? BADGE_CLASS.off : BADGE_CLASS.ok]">{{ t(unitEnded(u) ? 'eet.units.status_ended' : 'eet.units.status_valid') }}</span>
              </div>
              <div class="text-xs text-neutral-600">
                {{ t(`eet.units.kind.${u.kind}`) }} · {{ formatDate(u.valid_from) }} - {{ u.valid_to ? formatDate(u.valid_to) : t('eet.units.open_ended') }}
              </div>
              <div v-if="canWrite" class="flex flex-wrap gap-1">
                <button type="button" :class="btnOutlineSm('primary')" @click="openUnit(u)">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                  {{ t('common.edit') }}
                </button>
                <button v-if="!unitEnded(u)" type="button" :class="btnOutlineSm('danger')" @click="endUnit(u)">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                  {{ t('eet.units.end') }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Zařízení -->
      <section :class="[formBox, 'mb-4']" data-test="eet-devices">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div>
            <h2 class="text-base font-semibold">{{ t('eet.devices.title') }}</h2>
            <p class="text-xs text-neutral-500">{{ t('eet.devices.hint') }}</p>
          </div>
          <button v-if="canWrite" type="button" :class="btnFilled('primary')" :disabled="units.length === 0"
            :title="units.length === 0 ? t('eet.devices.need_unit') : undefined" @click="openDevice()">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
            {{ t('eet.devices.add') }}
          </button>
        </div>
        <EmptyState v-if="devices.length === 0" dense icon="coin" :title="t('eet.devices.empty')" :message="t('eet.devices.empty_hint')" />
        <div v-else class="rounded-lg border border-neutral-200 overflow-hidden">
          <div class="hidden md:block overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th :class="th">{{ t('eet.devices.col_code') }}</th>
                  <th :class="th">{{ t('eet.devices.col_name') }}</th>
                  <th :class="th">{{ t('eet.devices.col_unit') }}</th>
                  <th :class="th">{{ t('eet.devices.col_register') }}</th>
                  <th :class="th">{{ t('eet.devices.col_status') }}</th>
                  <th v-if="canWrite" :class="[th, 'text-right']">{{ t('eet.col_actions') }}</th>
                </tr>
              </thead>
              <tbody :class="divider">
                <tr v-for="d in devices" :key="d.id">
                  <td class="px-3 py-2 font-mono whitespace-nowrap">{{ d.code }}</td>
                  <td class="px-3 py-2">
                    {{ d.name || '-' }}
                    <span v-if="d.delegation !== 'none'" :class="[BADGE, BADGE_CLASS.info, 'ml-1']" data-test="eet-device-delegation-badge">{{ delegationBadge(d) }}</span>
                  </td>
                  <td class="px-3 py-2 whitespace-nowrap">{{ d.unit_number }} - {{ d.unit_name }}</td>
                  <td class="px-3 py-2 whitespace-nowrap">{{ registerLabel(d.cash_register_id) || '-' }}</td>
                  <td class="px-3 py-2">
                    <span :class="[BADGE, d.active ? BADGE_CLASS.ok : BADGE_CLASS.off]">{{ t(d.active ? 'eet.devices.status_active' : 'eet.devices.status_inactive') }}</span>
                    <span v-if="settings.default_device_id === d.id" :class="[BADGE, BADGE_CLASS.ok, 'ml-1']">{{ t('eet.devices.default') }}</span>
                  </td>
                  <td v-if="canWrite" class="px-3 py-2">
                    <div class="flex flex-wrap justify-end gap-1">
                      <button v-if="d.active && d.delegation === 'none' && settings.default_device_id !== d.id" type="button" :class="btnOutlineSm('success')" @click="setDefaultDevice(d)">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                        {{ t('eet.devices.make_default') }}
                      </button>
                      <button type="button" :class="btnOutlineSm('primary')" @click="openDevice(d)">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                        {{ t('common.edit') }}
                      </button>
                      <button v-if="d.active" type="button" :class="btnOutlineSm('danger')" @click="deactivateDevice(d)">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.pause" /></svg>
                        {{ t('eet.devices.deactivate') }}
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="md:hidden" :class="divider">
            <div v-for="d in devices" :key="`m-${d.id}`" class="px-4 py-3 space-y-2">
              <div class="flex items-center justify-between gap-2">
                <span class="font-medium font-mono">{{ d.code }}</span>
                <span class="flex flex-wrap gap-1 justify-end">
                  <span :class="[BADGE, d.active ? BADGE_CLASS.ok : BADGE_CLASS.off]">{{ t(d.active ? 'eet.devices.status_active' : 'eet.devices.status_inactive') }}</span>
                  <span v-if="settings.default_device_id === d.id" :class="[BADGE, BADGE_CLASS.ok]">{{ t('eet.devices.default') }}</span>
                </span>
              </div>
              <div class="text-xs text-neutral-600">{{ d.name || '-' }} · {{ d.unit_number }} - {{ d.unit_name }}</div>
              <div v-if="d.cash_register_id !== null" class="text-xs text-neutral-600">{{ t('eet.devices.register') }}: {{ registerLabel(d.cash_register_id) }}</div>
              <div v-if="d.delegation !== 'none'"><span :class="[BADGE, BADGE_CLASS.info]">{{ delegationBadge(d) }}</span></div>
              <div v-if="canWrite" class="flex flex-wrap gap-1">
                <button v-if="d.active && d.delegation === 'none' && settings.default_device_id !== d.id" type="button" :class="btnOutlineSm('success')" @click="setDefaultDevice(d)">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                  {{ t('eet.devices.make_default') }}
                </button>
                <button type="button" :class="btnOutlineSm('primary')" @click="openDevice(d)">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                  {{ t('common.edit') }}
                </button>
                <button v-if="d.active" type="button" :class="btnOutlineSm('danger')" @click="deactivateDevice(d)">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.pause" /></svg>
                  {{ t('eet.devices.deactivate') }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Certifikáty -->
      <section v-if="canReadCerts" :class="[formBox, 'mb-4']" data-test="eet-certificates">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div>
            <h2 class="text-base font-semibold">{{ t('eet.certificates.title') }}</h2>
            <p class="text-xs text-neutral-500">{{ t('eet.certificates.hint') }}</p>
          </div>
          <button v-if="canWriteCerts" type="button" :class="btnFilled('primary')" @click="openCertUpload">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
            {{ t('eet.certificates.upload') }}
          </button>
        </div>
        <EmptyState v-if="certificates.length === 0" dense icon="lock" :title="t('eet.certificates.empty')" :message="t('eet.certificates.empty_hint')" />
        <div v-else class="rounded-lg border border-neutral-200 overflow-hidden">
          <div class="hidden md:block overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th :class="th">{{ t('eet.certificates.col_environment') }}</th>
                  <th :class="th">{{ t('eet.certificates.col_eic') }}</th>
                  <th :class="th">{{ t('eet.certificates.col_label') }}</th>
                  <th :class="th">{{ t('eet.certificates.col_valid_to') }}</th>
                  <th :class="th">{{ t('eet.certificates.col_status') }}</th>
                  <th v-if="canWriteCerts" :class="[th, 'text-right']">{{ t('eet.col_actions') }}</th>
                </tr>
              </thead>
              <tbody :class="divider">
                <tr v-for="c in certificates" :key="c.id">
                  <td class="px-3 py-2 whitespace-nowrap">{{ t(`eet.environment.${c.environment}`) }}</td>
                  <td class="px-3 py-2 font-mono whitespace-nowrap">{{ c.eic }}</td>
                  <td class="px-3 py-2">{{ c.label || c.subject_cn || '-' }}</td>
                  <td class="px-3 py-2 whitespace-nowrap">
                    {{ formatDateTime(c.not_after) }}
                    <span v-if="c.status === 'active'" class="block text-xs text-neutral-500">{{ t('eet.certificates.days_left', { n: Math.max(0, c.expires_in_days) }) }}</span>
                  </td>
                  <td class="px-3 py-2">
                    <span :class="[BADGE, c.status === 'active' && c.valid_now ? BADGE_CLASS.ok : BADGE_CLASS.off]">
                      {{ t(c.status === 'active' ? (c.valid_now ? 'eet.certificates.status_active' : 'eet.certificates.status_invalid') : 'eet.certificates.status_retired') }}
                    </span>
                    <span v-if="expiryBadge(c)" :class="[BADGE, expiryClass(expiryBadge(c)!), 'ml-1']">{{ t(`eet.certificates.expiry_${expiryBadge(c)}`) }}</span>
                  </td>
                  <td v-if="canWriteCerts" class="px-3 py-2">
                    <div class="flex flex-wrap justify-end gap-1">
                      <button v-if="c.status === 'active'" type="button" :class="btnOutlineSm('danger')" @click="deactivateCertificate(c)">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.pause" /></svg>
                        {{ t('eet.certificates.deactivate') }}
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="md:hidden" :class="divider">
            <div v-for="c in certificates" :key="`m-${c.id}`" class="px-4 py-3 space-y-2">
              <div class="flex items-center justify-between gap-2">
                <span class="font-medium">{{ c.label || c.subject_cn || c.eic }}</span>
                <span class="flex flex-wrap gap-1 justify-end">
                  <span :class="[BADGE, c.status === 'active' && c.valid_now ? BADGE_CLASS.ok : BADGE_CLASS.off]">
                    {{ t(c.status === 'active' ? (c.valid_now ? 'eet.certificates.status_active' : 'eet.certificates.status_invalid') : 'eet.certificates.status_retired') }}
                  </span>
                  <span v-if="expiryBadge(c)" :class="[BADGE, expiryClass(expiryBadge(c)!)]">{{ t(`eet.certificates.expiry_${expiryBadge(c)}`) }}</span>
                </span>
              </div>
              <div class="text-xs text-neutral-600 flex flex-wrap gap-x-3">
                <span>{{ t(`eet.environment.${c.environment}`) }}</span>
                <span class="font-mono">{{ c.eic }}</span>
                <span>{{ t('eet.certificates.valid_to_short', { date: formatDateTime(c.not_after) }) }}</span>
              </div>
              <div v-if="canWriteCerts && c.status === 'active'" class="flex flex-wrap gap-1">
                <button type="button" :class="btnOutlineSm('danger')" @click="deactivateCertificate(c)">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.pause" /></svg>
                  {{ t('eet.certificates.deactivate') }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Diagnostika -->
      <section v-if="canWrite" :class="[formBox, 'mb-4']" data-test="eet-diagnostics">
        <h2 class="text-base font-semibold">{{ t('eet.diagnostics.title') }}</h2>
        <p class="text-sm text-neutral-600">{{ t('eet.diagnostics.note') }}</p>
        <div class="flex flex-wrap items-end gap-3">
          <label v-if="activeDevices.length > 1" :class="[formLabel, 'w-64']">
            <span>{{ t('eet.diagnostics.device') }}</span>
            <select v-model="diagnosticsDeviceId" :class="formInput">
              <option :value="null">{{ t('eet.diagnostics.device_default') }}</option>
              <option v-for="d in activeDevices" :key="d.id" :value="d.id">{{ d.code }} ({{ d.unit_number }})</option>
            </select>
          </label>
          <button type="button" :class="btnFilled('primary')" :disabled="diagnosticsBusy || dirty"
            :title="dirty ? t('eet.diagnostics.save_first') : undefined" @click="runDiagnostics">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.play" /></svg>
            {{ diagnosticsBusy ? t('common.loading') : t('eet.diagnostics.run') }}
          </button>
        </div>
        <p v-if="dirty" class="text-xs text-warning-700">{{ t('eet.diagnostics.save_first') }}</p>
        <div v-if="diagnostics" class="rounded-lg border px-4 py-3 text-sm"
          :class="diagnostics.ok ? 'border-success-500/40 bg-success-50 text-success-700' : 'border-danger-500/40 bg-danger-50 text-danger-700'"
          data-test="eet-diagnostics-result">
          <div class="font-medium">{{ diagnostics.ok ? t('eet.diagnostics.ok') : t('eet.diagnostics.not_ok') }}</div>
          <div class="mt-1 text-xs">
            {{ t('eet.diagnostics.detail', { env: t(`eet.environment.${diagnostics.environment}`), ms: diagnostics.duration_ms, outcome: diagnostics.outcome }) }}
            <template v-if="diagnostics.error_code !== null && diagnostics.error_code !== 0"> · {{ t('eet.diagnostics.error_code', { code: diagnostics.error_code }) }}</template>
          </div>
          <div v-if="diagnostics.message" class="mt-1 text-xs">{{ diagnostics.message }}</div>
          <div class="mt-2 text-xs opacity-80">{{ t('eet.diagnostics.note_short') }}</div>
        </div>
      </section>

      <!-- Aktivace -->
      <section :class="[formBox, 'mb-4']" data-test="eet-activation">
        <h2 class="text-base font-semibold">{{ t('eet.activation.title') }}</h2>
        <div class="flex flex-wrap items-center gap-2 text-sm">
          <span>{{ t('eet.activation.state') }}</span>
          <span :class="[BADGE, settings.enabled ? BADGE_CLASS.ok : BADGE_CLASS.off]">{{ t(settings.enabled ? 'eet.activation.on' : 'eet.activation.off') }}</span>
          <span v-if="settings.dispatch_blocked" :class="[BADGE, BADGE_CLASS.bad]">{{ t('eet.activation.blocked') }}</span>
        </div>
        <div v-if="obstacles.length > 0 && !settings.enabled" class="rounded-lg border border-warning-500/40 bg-warning-50 px-4 py-3 text-sm text-warning-700">
          <div class="font-medium">{{ t('eet.activation.obstacles') }}</div>
          <ul class="mt-1 list-disc pl-5 space-y-0.5">
            <li v-for="o in obstacles" :key="o.code">{{ o.message }}</li>
          </ul>
        </div>
        <div v-if="canWrite" class="flex flex-wrap items-center gap-2">
          <button v-if="!settings.enabled" type="button" :class="btnFilled('success')"
            :disabled="activationBusy || obstacles.length > 0 || dirty" @click="toggleActivation">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.play" /></svg>
            {{ t('eet.activation.activate') }}
          </button>
          <button v-else type="button" :class="btnOutline('danger')" :disabled="activationBusy" @click="toggleActivation">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.pause" /></svg>
            {{ t('eet.activation.deactivate') }}
          </button>
          <span v-if="dirty && !settings.enabled" class="text-xs text-warning-700">{{ t('eet.activation.save_first') }}</span>
        </div>
      </section>

      <!-- Jedno společné Uložit pro profil -->
      <div v-if="canWrite && dirty"
        class="sticky bottom-[var(--app-footer-height,0px)] z-10 -mx-1 flex flex-wrap items-center justify-end gap-2 rounded-lg border border-neutral-200 bg-surface px-4 py-3 shadow-[0_-2px_10px_rgba(21,19,29,0.10)]"
        data-test="eet-save-bar">
        <span class="mr-auto text-sm text-neutral-600">{{ t('eet.profile.unsaved') }}</span>
        <button type="button" :class="btnOutline('neutral')" :disabled="saving" @click="resetProfile">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
          {{ t('common.cancel') }}
        </button>
        <button type="button" :class="btnFilled('primary')" :disabled="saving || timeoutInvalid" @click="saveProfile">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ saving ? t('common.loading') : t('common.save') }}
        </button>
      </div>
    </template>

    <!-- Dialog jednotky -->
    <Modal v-if="unitForm" :title="unitForm.id === null ? t('eet.units.add') : t('eet.units.edit')" width-class="max-w-xl" @close="unitForm = null">
      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <label :class="formLabel">
          <span>{{ t('eet.units.number') }}</span>
          <input v-model="unitForm.unit_number" type="text" inputmode="numeric" maxlength="6" :class="formInput" />
          <span class="text-xs text-neutral-500">{{ t('eet.units.number_hint') }}</span>
        </label>
        <label :class="formLabel">
          <span>{{ t('eet.units.name') }}</span>
          <input v-model="unitForm.name" type="text" maxlength="120" :class="formInput" />
        </label>
        <label :class="formLabel">
          <span>{{ t('eet.units.kind_label') }}</span>
          <select v-model="unitForm.kind" :class="formInput">
            <option v-for="k in UNIT_KINDS" :key="k" :value="k">{{ t(`eet.units.kind.${k}`) }}</option>
          </select>
        </label>
        <div :class="formLabel">
          <span>{{ t('eet.units.valid_from') }}</span>
          <DateInput v-model="unitForm.valid_from" :class="formInput" />
        </div>
        <div :class="formLabel">
          <span>{{ t('eet.units.valid_to') }}</span>
          <DateInput v-model="unitForm.valid_to" :class="formInput" />
        </div>
        <label class="flex items-center gap-2 text-sm sm:col-span-2">
          <ToggleSwitch v-model="unitForm.notified_to_fs" :label="t('eet.units.notified')" />
          {{ t('eet.units.notified') }}
        </label>
      </div>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2 px-5 py-3">
          <button type="button" :class="btnOutline('neutral')" @click="unitForm = null">{{ t('common.cancel') }}</button>
          <button type="button" :class="btnFilled('primary')" :disabled="unitBusy || !unitForm.unit_number || !unitForm.name.trim() || !unitForm.valid_from" @click="saveUnit">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ t('common.save') }}
          </button>
        </div>
      </template>
    </Modal>

    <!-- Dialog zařízení -->
    <Modal v-if="deviceForm" :title="deviceForm.id === null ? t('eet.devices.add') : t('eet.devices.edit')" width-class="max-w-xl" @close="deviceForm = null">
      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <label :class="formLabel">
          <span>{{ t('eet.devices.code') }}</span>
          <input v-model="deviceForm.code" type="text" maxlength="20" :class="formInput" />
          <span class="text-xs text-neutral-500">{{ t('eet.devices.code_hint') }}</span>
        </label>
        <label :class="formLabel">
          <span>{{ t('eet.devices.unit') }}</span>
          <select v-model="deviceForm.unit_id" :class="formInput">
            <option v-for="u in units" :key="u.id" :value="u.id">{{ u.unit_number }} - {{ u.name }}</option>
          </select>
        </label>
        <label :class="[formLabel, 'sm:col-span-2']">
          <span>{{ t('eet.devices.name') }}</span>
          <input v-model="deviceForm.name" type="text" maxlength="120" :class="formInput" />
        </label>
        <label v-if="deviceForm.delegation === 'none' && (registers.length > 0 || deviceForm.cash_register_id !== null)" :class="[formLabel, 'sm:col-span-2']">
          <span>{{ t('eet.devices.register') }}</span>
          <select v-model="deviceForm.cash_register_id" :class="formInput" data-test="eet-device-register">
            <option :value="null">{{ t('eet.devices.register_none') }}</option>
            <option v-for="r in registerOptions" :key="r.id" :value="r.id">{{ r.name }} ({{ r.account_code }})</option>
          </select>
          <span class="text-xs text-neutral-500">{{ t('eet.devices.register_hint') }}</span>
        </label>
        <label :class="[formLabel, 'sm:col-span-2']">
          <span>{{ t('eet.devices.delegation') }}</span>
          <select v-model="deviceForm.delegation" :class="formInput" data-test="eet-device-delegation">
            <option v-for="mode in DELEGATION_MODES" :key="mode" :value="mode">{{ t(`eet.devices.delegation_modes.${mode}`) }}</option>
          </select>
          <span class="text-xs text-neutral-500">{{ t(`eet.devices.delegation_hints.${deviceForm.delegation}`) }}</span>
          <span v-if="deviceForm.delegation !== 'none'" class="text-xs text-neutral-500">{{ t('eet.devices.delegation_scope_note') }}</span>
        </label>
        <label v-if="deviceForm.delegation !== 'none'" :class="[formLabel, 'sm:col-span-2']">
          <span>{{ t(deviceForm.delegation === 'single' ? 'eet.devices.delegating_eic' : 'eet.devices.delegating_eic_optional') }}</span>
          <input v-model="deviceForm.delegating_eic" type="text" maxlength="12" placeholder="CZ00000019" :class="formInput" data-test="eet-device-delegating-eic" />
          <span class="text-xs text-neutral-500">{{ t('eet.devices.delegating_eic_hint') }}</span>
        </label>
        <label class="flex items-center gap-2 text-sm sm:col-span-2">
          <ToggleSwitch v-model="deviceForm.active" :label="t('eet.devices.active')" />
          {{ t('eet.devices.active') }}
        </label>
      </div>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2 px-5 py-3">
          <button type="button" :class="btnOutline('neutral')" @click="deviceForm = null">{{ t('common.cancel') }}</button>
          <button type="button" :class="btnFilled('primary')" :disabled="deviceBusy || !deviceForm.code.trim() || deviceForm.unit_id === null || deviceDelegationIncomplete" @click="saveDevice">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ t('common.save') }}
          </button>
        </div>
      </template>
    </Modal>

    <!-- Dialog nahrání certifikátu -->
    <Modal v-if="certForm" :title="t('eet.certificates.upload')" width-class="max-w-2xl" @close="closeCertUpload">
      <div class="space-y-4">
        <div class="rounded-md border border-warning-500/30 bg-warning-50 p-3 text-xs text-warning-800">{{ t('eet.certificates.security') }}</div>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <label :class="formLabel">
            <span>{{ t('eet.certificates.environment') }}</span>
            <select v-model="certForm.environment" :class="formInput">
              <option value="playground">{{ t('eet.environment.playground') }}</option>
              <option value="production">{{ t('eet.environment.production') }}</option>
            </select>
          </label>
          <label :class="formLabel">
            <span>{{ t('eet.certificates.label') }}</span>
            <input v-model="certForm.label" type="text" maxlength="120" :class="formInput" />
          </label>
          <div :class="[formLabel, 'sm:col-span-2']">
            <span>{{ t('eet.certificates.file') }}</span>
            <div class="flex flex-wrap items-center gap-2">
              <input ref="certFileInput" type="file" accept=".p12,.pfx,application/x-pkcs12" class="hidden" @change="onCertFile" />
              <button type="button" :class="btnOutline('primary')" @click="certFileInput?.click()">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
                {{ t('eet.certificates.choose_file') }}
              </button>
              <span class="text-sm text-neutral-600 break-all">{{ certFile ? certFile.name : t('eet.certificates.no_file') }}</span>
            </div>
          </div>
          <label :class="[formLabel, 'sm:col-span-2']">
            <span>{{ t('eet.certificates.file_password') }}</span>
            <input v-model="certForm.password" type="password" autocomplete="off" :class="formInput" />
          </label>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 rounded-lg border border-neutral-200 p-4">
          <div v-if="hasPasskey" class="sm:col-span-2 flex flex-wrap items-center gap-3">
            <div v-if="stepPasskeyToken" class="text-sm font-medium text-success-600">{{ t('eet.certificates.passkey_verified') }}</div>
            <template v-else-if="passkeySupported">
              <button type="button" :class="btnOutline('primary')" :disabled="passkeyBusy || certBusy" @click="verifyPasskey">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" /></svg>
                {{ passkeyBusy ? t('common.loading') : t('eet.certificates.verify_passkey') }}
              </button>
              <span class="text-xs text-neutral-500">{{ t('eet.certificates.step_up_or_password') }}</span>
            </template>
            <p v-else class="text-xs text-warning-700">{{ t('eet.certificates.passkey_unsupported') }}</p>
          </div>
          <template v-if="!stepPasskeyToken">
            <label class="text-xs text-neutral-600">
              {{ t('eet.certificates.step_up_password') }}
              <input v-model="stepPassword" type="password" autocomplete="current-password" :class="[formInput, 'mt-1']" />
            </label>
            <label v-if="hasTotp" class="text-xs text-neutral-600">
              {{ t('eet.certificates.step_up_totp') }}
              <input v-model="stepTotp" type="text" inputmode="numeric" maxlength="6" autocomplete="one-time-code" :class="[formInput, 'mt-1']" />
            </label>
          </template>
          <p class="sm:col-span-2 text-xs text-neutral-500">{{ t('eet.certificates.step_up_hint') }}</p>
        </div>
      </div>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2 px-5 py-3">
          <button type="button" :class="btnOutline('neutral')" @click="closeCertUpload">{{ t('common.cancel') }}</button>
          <button type="button" :class="btnFilled('primary')" :disabled="!canUpload" @click="uploadCertificate">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
            {{ certBusy ? t('common.loading') : t('eet.certificates.upload_action') }}
          </button>
        </div>
      </template>
    </Modal>

    <!-- Dialog deaktivace certifikátu -->
    <Modal v-if="deactivateTarget" :title="t('eet.certificates.deactivate_title')" width-class="max-w-lg" @close="closeDeactivate">
      <div class="space-y-4">
        <p class="text-sm text-neutral-700">
          {{ t('eet.certificates.deactivate_confirm', { eic: deactivateTarget.eic, env: t(`eet.environment.${deactivateTarget.environment}`) }) }}
        </p>
        <div class="grid gap-3 sm:grid-cols-2 rounded-lg border border-neutral-200 p-4">
          <div v-if="hasPasskey" class="sm:col-span-2 flex flex-wrap items-center gap-3">
            <div v-if="stepPasskeyToken" class="text-sm font-medium text-success-600">{{ t('eet.certificates.passkey_verified') }}</div>
            <template v-else-if="passkeySupported">
              <button type="button" :class="btnOutline('primary')" :disabled="passkeyBusy || certBusy" @click="verifyPasskey">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" /></svg>
                {{ passkeyBusy ? t('common.loading') : t('eet.certificates.verify_passkey') }}
              </button>
              <span class="text-xs text-neutral-500">{{ t('eet.certificates.step_up_or_password') }}</span>
            </template>
            <p v-else class="text-xs text-warning-700">{{ t('eet.certificates.passkey_unsupported') }}</p>
          </div>
          <template v-if="!stepPasskeyToken">
            <label class="text-xs text-neutral-600">
              {{ t('eet.certificates.step_up_password') }}
              <input v-model="stepPassword" type="password" autocomplete="current-password" :class="[formInput, 'mt-1']" />
            </label>
            <label v-if="hasTotp" class="text-xs text-neutral-600">
              {{ t('eet.certificates.step_up_totp') }}
              <input v-model="stepTotp" type="text" inputmode="numeric" maxlength="6" autocomplete="one-time-code" :class="[formInput, 'mt-1']" />
            </label>
          </template>
          <p class="sm:col-span-2 text-xs text-neutral-500">{{ t('eet.certificates.step_up_hint') }}</p>
        </div>
      </div>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2 px-5 py-3">
          <button type="button" :class="btnOutline('neutral')" @click="closeDeactivate">{{ t('common.cancel') }}</button>
          <button type="button" :class="btnFilled('danger')" :disabled="certBusy || stepUpMissing !== ''" @click="runDeactivate">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.pause" /></svg>
            {{ certBusy ? t('common.loading') : t('eet.certificates.deactivate') }}
          </button>
        </div>
      </template>
    </Modal>

    <!-- Potvrzení -->
    <Modal v-if="confirmState" :title="confirmState.title" width-class="max-w-lg" @close="confirmState = null">
      <p class="text-sm text-neutral-700">{{ confirmState.message }}</p>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2 px-5 py-3">
          <button type="button" :class="btnOutline('neutral')" @click="confirmState = null">{{ t('common.cancel') }}</button>
          <button type="button" :class="btnFilled(confirmState.variant)" :disabled="confirmBusy" @click="runConfirm">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ confirmState.confirm }}
          </button>
        </div>
      </template>
    </Modal>
  </div>
</template>
