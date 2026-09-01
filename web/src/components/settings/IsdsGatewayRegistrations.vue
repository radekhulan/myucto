<script setup lang="ts">
/**
 * Registrace odesílací brány ISDS (SetConcept).
 *
 * ── Je to nastavení PROVOZOVATELE, ne zákazníka ─────────────────────────────
 * Komerční certifikát platí provozovatel a je jeden pro celou službu. Zákazník
 * k odesílání přes bránu nenastavuje nic — proto je celá sekce dostupná jen
 * s právem `settings.signing` a nadřazená stránka ji vůbec nezobrazí, pokud
 * výpis registrací skončil 403.
 *
 * ── Tři věci, na kterých tahle obrazovka stojí ──────────────────────────────
 * 1. **Certifikát se jen nahrává.** Soukromý klíč ani heslo se z API nikdy
 *    nevrací; jediné, co je o něm vidět, je otisk a platnost. Formulář proto
 *    heslo nikdy nepředvyplňuje a nenapovídá ho ani prohlížeč.
 * 2. **Uložení registraci NEZAPNE.** Backend ji po každém uložení vypne, takže
 *    překlep v `atsId` nemůže poslat uživatele na cizí bránu. Zapnutí je
 *    samostatný, potvrzený krok.
 * 3. **Návratová adresa je frontendová.** Do Portálu datových schránek patří
 *    autentizovaná callback stránka (`/isds-gateway/callback`), ne endpoint API.
 *    Není svázaná s právem na správu této globální registrace, takže se z ní
 *    bezpečně vrátí i mzdová role.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  dataBoxApi,
  type IsdsGatewayHosts,
  type IsdsGatewayRegistration,
} from '@/api/dataBox'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import EnvironmentSwitch from '@/components/ui/EnvironmentSwitch.vue'

const emit = defineEmits<{ (e: 'changed'): void }>()

const { t } = useI18n()
const toast = useToast()

type Environment = 'production' | 'test'
type LoginPolicy = IsdsGatewayRegistration['user_login_policy']

const POLICIES: LoginPolicy[] = ['unknown', 'password_required', 'portal_sso_or_password']

const loading = ref(true)
const saving = ref(false)
const busyEnv = ref<Environment | null>(null)
const items = ref<IsdsGatewayRegistration[]>([])
const defaultHosts = ref<Record<Environment, IsdsGatewayHosts>>({
  production: { portal: '', service: '' },
  test: { portal: '', service: '' },
})

/**
 * Přesná hodnota do Portálu datových schránek. Návrat obsluhuje autentizovaná
 * frontendová callback stránka; oprávnění a vlastnictví relace ověří API.
 */
const returnUrl = computed(() => `${window.location.origin}/isds-gateway/callback`)

const form = ref({
  environment: 'test' as Environment,
  label: '',
  ats_id: '',
  return_url: '',
  error_url: '',
  concept_ttl_seconds: 900,
  portal_host: '',
  service_host: '',
  user_login_policy: 'unknown' as LoginPolicy,
  certificate_password: '',
})
const certificate = ref<File | null>(null)
const certificateInput = ref<HTMLInputElement | null>(null)
const formOpen = ref(false)

/** Potvrzení výrazných kroků. `confirm()` tady nemá co dělat. */
const pendingActivate = ref<{ row: IsdsGatewayRegistration; active: boolean } | null>(null)
const pendingDelete = ref<IsdsGatewayRegistration | null>(null)

const copied = ref(false)

function hostsFor(environment: Environment): IsdsGatewayHosts {
  return defaultHosts.value[environment] ?? { portal: '', service: '' }
}

/** Prošlý certifikát bránu nezapne — backend to odmítne a UI to musí říct dřív. */
function isExpired(row: IsdsGatewayRegistration): boolean {
  if (!row.certificate_valid_to) return false
  const validTo = Date.parse(row.certificate_valid_to.replace(' ', 'T'))
  return Number.isFinite(validTo) && validTo < Date.now()
}

/** Otisk je dlouhý a k ničemu celý — stačí, aby šel porovnat s tím v ISDS. */
function shortFingerprint(value: string | null): string {
  if (!value) return '—'
  return value.length <= 24 ? value : `${value.slice(0, 12)}…${value.slice(-12)}`
}

async function load() {
  loading.value = true
  try {
    const settings = await dataBoxApi.gatewaySettings()
    items.value = settings.items
    defaultHosts.value = settings.default_hosts
    if (!formOpen.value) resetForm()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    loading.value = false
  }
}

function resetForm(environment: Environment = form.value.environment) {
  const hosts = hostsFor(environment)
  form.value = {
    environment,
    label: '',
    ats_id: '',
    return_url: returnUrl.value,
    error_url: '',
    concept_ttl_seconds: 900,
    portal_host: hosts.portal,
    service_host: hosts.service,
    user_login_policy: 'unknown',
    certificate_password: '',
  }
  certificate.value = null
  if (certificateInput.value) certificateInput.value.value = ''
}

function onEnvironmentChange() {
  const hosts = hostsFor(form.value.environment)
  form.value.portal_host = hosts.portal
  form.value.service_host = hosts.service
}

function startNew() {
  formOpen.value = true
  resetForm()
}

/**
 * Úprava existující registrace. Certifikát se NEPŘEDVYPLŇUJE — z API se
 * nevrací a vrátit by se ani neměl; nahrává se pokaždé znovu.
 */
function startEdit(row: IsdsGatewayRegistration) {
  formOpen.value = true
  form.value = {
    environment: row.environment,
    label: row.label,
    ats_id: row.ats_id,
    return_url: row.return_url,
    error_url: row.error_url ?? '',
    concept_ttl_seconds: row.concept_ttl_seconds,
    portal_host: row.portal_host,
    service_host: row.service_host,
    user_login_policy: row.user_login_policy,
    certificate_password: '',
  }
  certificate.value = null
  if (certificateInput.value) certificateInput.value.value = ''
}

function closeForm() {
  formOpen.value = false
  resetForm()
}

async function save() {
  if (!certificate.value) {
    toast.error(t('databox.gateway.registrations.certificateRequired'))
    return
  }
  saving.value = true
  try {
    await dataBoxApi.saveGatewayRegistration({
      environment: form.value.environment,
      ats_id: form.value.ats_id.trim(),
      label: form.value.label.trim(),
      return_url: form.value.return_url.trim(),
      error_url: form.value.error_url.trim() === '' ? null : form.value.error_url.trim(),
      concept_ttl_seconds: form.value.concept_ttl_seconds,
      portal_host: form.value.portal_host.trim(),
      service_host: form.value.service_host.trim(),
      user_login_policy: form.value.user_login_policy,
      certificate: certificate.value,
      certificate_password: form.value.certificate_password,
    })
    // Heslo se v paměti stránky nedrží ani o vteřinu déle, než musí.
    form.value.certificate_password = ''
    formOpen.value = false
    toast.success(t('databox.gateway.registrations.saved'))
    await load()
    emit('changed')
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

async function confirmActivate() {
  const pending = pendingActivate.value
  if (!pending) return
  busyEnv.value = pending.row.environment
  try {
    await dataBoxApi.setGatewayActive(pending.row.environment, pending.active)
    toast.success(pending.active
      ? t('databox.gateway.registrations.activated')
      : t('databox.gateway.registrations.deactivated'))
    pendingActivate.value = null
    await load()
    emit('changed')
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busyEnv.value = null
  }
}

async function confirmDelete() {
  const row = pendingDelete.value
  if (!row) return
  busyEnv.value = row.environment
  try {
    await dataBoxApi.deleteGatewayRegistration(row.environment)
    toast.success(t('databox.gateway.registrations.deleted'))
    pendingDelete.value = null
    await load()
    emit('changed')
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busyEnv.value = null
  }
}

async function copyReturnUrl() {
  try {
    await navigator.clipboard.writeText(returnUrl.value)
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
  } catch {
    // Bez schránky prohlížeče se nic neztrácí — hodnota je vypsaná vedle.
    toast.info(t('databox.gateway.registrations.copyFailed'))
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-4">
    <div class="rounded-lg border border-neutral-200 bg-surface p-4 text-sm">
      <h2 class="mb-1 font-medium">{{ t('databox.gateway.registrations.title') }}</h2>
      <p class="text-neutral-500">{{ t('databox.gateway.registrations.intro') }}</p>
      <p class="mt-2 text-neutral-500">{{ t('databox.gateway.registrations.operatorOnly') }}</p>
    </div>

    <!-- Přesná hodnota do Portálu datových schránek. Nikdo ji nemá hádat. -->
    <div class="rounded-lg border border-primary-500/40 bg-primary-50 p-4 text-sm">
      <h3 class="font-medium">{{ t('databox.gateway.registrations.returnUrlTitle') }}</h3>
      <p class="mt-1 text-neutral-600">{{ t('databox.gateway.registrations.returnUrlHint') }}</p>
      <div class="mt-2 flex flex-wrap items-center gap-2">
        <code class="rounded bg-surface px-2 py-1">{{ returnUrl }}</code>
        <button type="button" :class="btnOutlineSm('neutral')" @click="copyReturnUrl">
          <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.copy" />
          </svg>
          {{ copied ? t('databox.gateway.registrations.copied') : t('databox.gateway.registrations.copy') }}
        </button>
      </div>
    </div>

    <EmptyState
      v-if="!loading && items.length === 0"
      icon="lock"
      :title="t('databox.gateway.registrations.empty')"
    />

    <div
      v-for="row in items"
      :key="row.id"
      class="rounded-lg border border-neutral-200 bg-surface p-4"
    >
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
          <div class="font-medium">{{ row.label }}</div>
          <div class="text-sm text-neutral-500">
            {{ t(`databox.env.${row.environment}`) }} ·
            {{ t('databox.gateway.registrations.atsId') }}: <code>{{ row.ats_id }}</code>
          </div>
          <div class="mt-1 text-xs text-neutral-500">
            <code>{{ row.portal_host }}</code> · <code>{{ row.service_host }}</code> ·
            {{ t('databox.gateway.registrations.ttl') }}: {{ row.concept_ttl_seconds }} s
          </div>
          <div class="mt-1 text-xs text-neutral-500">
            {{ t('databox.gateway.registrations.returnUrl') }}: <code>{{ row.return_url }}</code>
          </div>
          <!-- O certifikátu jen otisk a platnost. Nic víc o něm vědět nejde
               a nemá — klíč ani heslo API nevrací. -->
          <div class="mt-1 text-xs text-neutral-500">
            {{ t('databox.gateway.registrations.fingerprint') }}:
            <code>{{ shortFingerprint(row.certificate_fingerprint) }}</code>
            <span v-if="row.certificate_valid_to">
              · {{ t('databox.gateway.registrations.validTo') }}: {{ row.certificate_valid_to }}
            </span>
          </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <span
            class="rounded-full px-2 py-0.5 text-xs font-medium"
            :class="row.is_active
              ? 'bg-success-50 text-success-700 dark:bg-success-900/30 dark:text-success-200'
              : 'bg-neutral-100 text-neutral-600'"
          >
            {{ row.is_active
              ? t('databox.gateway.registrations.active')
              : t('databox.gateway.registrations.inactive') }}
          </span>
          <span class="rounded-full bg-neutral-100 px-2 py-0.5 text-xs text-neutral-600">
            {{ t(`databox.gateway.registrations.policies.${row.user_login_policy}`) }}
          </span>
        </div>
      </div>

      <!--
        `unknown` není porucha ani chyba nastavení: je to pojmenovaná nejistota,
        kterou uzavře až živý pokus proti zaregistrované bráně. UI podle ní nic
        nerozhoduje, jen o ní musí mluvit nahlas.
      -->
      <p
        v-if="row.user_login_policy === 'unknown'"
        class="mt-3 rounded-md bg-neutral-50 p-2 text-sm text-neutral-600"
      >
        {{ t('databox.gateway.registrations.policyUnknownHint') }}
      </p>

      <p
        v-if="isExpired(row)"
        class="mt-3 rounded-md bg-danger-50 p-2 text-sm text-danger-700 dark:bg-danger-900/20 dark:text-danger-200"
      >
        {{ t('databox.gateway.registrations.expired') }}
      </p>

      <div class="mt-3 flex flex-wrap gap-2">
        <button
          v-if="!row.is_active"
          type="button"
          :class="btnFilled('success')"
          :disabled="busyEnv === row.environment || isExpired(row)"
          @click="pendingActivate = { row, active: true }"
        >
          <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.play" />
          </svg>
          {{ t('databox.gateway.registrations.activate') }}
        </button>
        <button
          v-else
          type="button"
          :class="btnOutline('warning')"
          :disabled="busyEnv === row.environment"
          @click="pendingActivate = { row, active: false }"
        >
          <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.pause" />
          </svg>
          {{ t('databox.gateway.registrations.deactivate') }}
        </button>
        <button type="button" :class="btnOutline('neutral')" @click="startEdit(row)">
          <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" />
          </svg>
          {{ t('databox.gateway.registrations.edit') }}
        </button>
        <button
          type="button"
          :class="btnOutline('danger')"
          :disabled="busyEnv === row.environment"
          @click="pendingDelete = row"
        >
          <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" />
          </svg>
          {{ t('common.delete') }}
        </button>
      </div>
    </div>

    <div v-if="!formOpen" class="flex flex-wrap gap-2">
      <button type="button" :class="btnOutline('primary')" @click="startNew">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" />
        </svg>
        {{ t('databox.gateway.registrations.add') }}
      </button>
    </div>

    <!-- ─────────────── Formulář registrace ─────────────── -->
    <template v-if="formOpen">
      <div class="rounded-lg border border-neutral-200 bg-surface p-4">
        <h3 class="mb-1 font-medium">{{ t('databox.gateway.registrations.formTitle') }}</h3>
        <p class="mb-4 text-sm text-neutral-500">
          {{ t('databox.gateway.registrations.formHint') }}
        </p>

        <div class="grid gap-3 sm:grid-cols-2">
          <div class="block">
            <span class="text-sm font-medium">{{ t('databox.gateway.registrations.environment') }}</span>
            <div class="mt-1">
              <EnvironmentSwitch
                v-model="form.environment"
                :aria-label="t('databox.gateway.registrations.environment')"
                :production-label="t('databox.env.production')"
                :test-label="t('databox.env.test')"
                @update:model-value="onEnvironmentChange"
              />
            </div>
          </div>
          <label class="block">
            <span class="text-sm font-medium">{{ t('databox.gateway.registrations.label') }}</span>
            <input v-model="form.label" type="text" maxlength="120" class="form-input mt-1 w-full" data-test="gw-label" />
          </label>
          <label class="block">
            <span class="text-sm font-medium">{{ t('databox.gateway.registrations.atsId') }}</span>
            <input v-model="form.ats_id" type="text" maxlength="64" class="form-input mt-1 w-full" data-test="gw-ats-id" />
            <span class="mt-1 block text-xs text-neutral-500">
              {{ t('databox.gateway.registrations.atsIdHint') }}
            </span>
          </label>
          <label class="block">
            <span class="text-sm font-medium">{{ t('databox.gateway.registrations.ttl') }}</span>
            <input
              v-model.number="form.concept_ttl_seconds"
              type="number"
              min="60"
              max="7200"
              class="form-input mt-1 w-full"
              data-test="gw-ttl"
            />
            <span class="mt-1 block text-xs text-neutral-500">
              {{ t('databox.gateway.registrations.ttlHint') }}
            </span>
          </label>
          <label class="block sm:col-span-2">
            <span class="text-sm font-medium">{{ t('databox.gateway.registrations.returnUrl') }}</span>
            <input v-model="form.return_url" type="url" class="form-input mt-1 w-full" />
            <span class="mt-1 block text-xs text-neutral-500">
              {{ t('databox.gateway.registrations.returnUrlField') }}
            </span>
          </label>
          <label class="block sm:col-span-2">
            <span class="text-sm font-medium">{{ t('databox.gateway.registrations.errorUrl') }}</span>
            <input v-model="form.error_url" type="url" class="form-input mt-1 w-full" />
            <span class="mt-1 block text-xs text-neutral-500">
              {{ t('databox.gateway.registrations.errorUrlHint') }}
            </span>
          </label>
          <label class="block">
            <span class="text-sm font-medium">{{ t('databox.gateway.registrations.portalHost') }}</span>
            <input v-model="form.portal_host" type="text" class="form-input mt-1 w-full" />
          </label>
          <label class="block">
            <span class="text-sm font-medium">{{ t('databox.gateway.registrations.serviceHost') }}</span>
            <input v-model="form.service_host" type="text" class="form-input mt-1 w-full" />
          </label>
          <label class="block sm:col-span-2">
            <span class="text-sm font-medium">{{ t('databox.gateway.registrations.policy') }}</span>
            <select v-model="form.user_login_policy" class="form-select mt-1 w-full">
              <option v-for="policy in POLICIES" :key="policy" :value="policy">
                {{ t(`databox.gateway.registrations.policies.${policy}`) }}
              </option>
            </select>
            <span class="mt-1 block text-xs text-neutral-500">
              {{ t('databox.gateway.registrations.policyHint') }}
            </span>
          </label>
          <label class="block">
            <span class="text-sm font-medium">{{ t('databox.gateway.registrations.certificate') }}</span>
            <input
              ref="certificateInput"
              type="file"
              accept=".pfx,.p12"
              class="form-input mt-1 w-full"
              data-test="gw-certificate"
              @change="certificate = ($event.target as HTMLInputElement).files?.[0] ?? null"
            />
            <span class="mt-1 block text-xs text-neutral-500">
              {{ t('databox.gateway.registrations.certificateHint') }}
            </span>
          </label>
          <label class="block">
            <span class="text-sm font-medium">{{ t('databox.gateway.registrations.certificatePassword') }}</span>
            <!-- Heslo se nikdy nepředvyplňuje a prohlížeč ho nemá napovídat. -->
            <input
              v-model="form.certificate_password"
              type="password"
              autocomplete="new-password"
              class="form-input mt-1 w-full"
              data-test="gw-password"
            />
            <span class="mt-1 block text-xs text-neutral-500">
              {{ t('databox.gateway.registrations.certificatePasswordHint') }}
            </span>
          </label>
        </div>

        <p class="mt-3 rounded-md bg-warning-50 p-2 text-sm text-warning-800 dark:bg-warning-900/20 dark:text-warning-200">
          {{ t('databox.gateway.registrations.savedInactiveHint') }}
        </p>
      </div>

      <!-- Jedno společné Uložit pro celou sekci -->
      <div class="sticky bottom-0 flex flex-wrap justify-end gap-2 border-t border-neutral-200 bg-surface/95 py-3">
        <button type="button" :class="btnOutline('neutral')" :disabled="saving" @click="closeForm">
          {{ t('common.cancel') }}
        </button>
        <button type="button" :class="btnFilled('primary')" :disabled="saving" @click="save">
          <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" />
          </svg>
          {{ t('common.save') }}
        </button>
      </div>
    </template>

    <!-- Potvrzení zapnutí/vypnutí brány -->
    <div v-if="pendingActivate" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="w-full max-w-md rounded-xl bg-surface p-5 shadow-lg">
        <h3 class="mb-1 text-lg font-semibold">
          {{ pendingActivate.active
            ? t('databox.gateway.registrations.activateTitle')
            : t('databox.gateway.registrations.deactivateTitle') }}
        </h3>
        <p class="mb-1 text-sm text-neutral-600">
          {{ pendingActivate.row.label }} — {{ t(`databox.env.${pendingActivate.row.environment}`) }}
        </p>
        <p class="mb-4 text-sm text-neutral-500">
          {{ pendingActivate.active
            ? t('databox.gateway.registrations.activateHint')
            : t('databox.gateway.registrations.deactivateHint') }}
        </p>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" @click="pendingActivate = null">
            {{ t('common.cancel') }}
          </button>
          <button
            type="button"
            :class="pendingActivate.active ? btnFilled('success') : btnFilled('warning')"
            :disabled="busyEnv !== null"
            @click="confirmActivate"
          >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                :d="pendingActivate.active ? ICONS.play : ICONS.pause"
              />
            </svg>
            {{ pendingActivate.active
              ? t('databox.gateway.registrations.activate')
              : t('databox.gateway.registrations.deactivate') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Potvrzení smazání registrace -->
    <div v-if="pendingDelete" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="w-full max-w-md rounded-xl bg-surface p-5 shadow-lg">
        <h3 class="mb-1 text-lg font-semibold">{{ t('databox.gateway.registrations.deleteTitle') }}</h3>
        <p class="mb-1 text-sm text-neutral-600">
          {{ pendingDelete.label }} — {{ t(`databox.env.${pendingDelete.environment}`) }}
        </p>
        <p class="mb-4 text-sm text-neutral-500">{{ t('databox.gateway.registrations.deleteHint') }}</p>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" @click="pendingDelete = null">
            {{ t('common.cancel') }}
          </button>
          <button type="button" :class="btnFilled('danger')" :disabled="busyEnv !== null" @click="confirmDelete">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" />
            </svg>
            {{ t('common.delete') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
