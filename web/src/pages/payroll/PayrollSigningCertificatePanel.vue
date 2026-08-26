<script setup lang="ts">
/**
 * Kterým certifikátem se podepisují mzdová podání na ČSSZ.
 *
 * Certifikát se tu NENAHRÁVÁ — trezor je v aplikaci jeden (Systém →
 * Elektronické podpisy) a druhé úložiště by znamenalo týž klíč na dvou místech.
 * Tahle obrazovka drží jen VOLBU: který z už uložených certifikátů patří téhle
 * firmě a tomuhle prostředí.
 *
 * Prostředí je proto vidět jako první věc: testovací certifikát bývá jiný než
 * produkční a záměna se pozná až z protokolu ČSSZ, tedy typicky po termínu.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import {
  payrollApi,
  type PayrollSigningCertificate,
  type PayrollSigningEnvironment,
  type PayrollSigningProfile,
  type PayrollSigningWarning,
} from '@/api/payroll'
import { authApi } from '@/api/auth'
import { getCredential, isWebAuthnAvailable } from '@/security/webauthn'
import { useAuthStore } from '@/stores/auth'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const auth = useAuthStore()

const ENVIRONMENTS: PayrollSigningEnvironment[] = ['production', 'test']
/** Lhůta, od které má smysl obnovu připomínat dřív, než na ni upozorní termín. */
const EXPIRY_NOTICE_DAYS = 60

const environment = defineModel<PayrollSigningEnvironment>('environment', {
  default: 'production',
})
const loading = ref(false)
const saving = ref(false)
const removing = ref(false)

/**
 * Chyba načtení se drží ve stavu a NIKDY se nepřevádí na prázdný seznam.
 * Selhaný požadavek, který se vykreslí jako „trezor je prázdný", je horší než
 * chybová hláška: uživatel z něj usoudí, že certifikát nemá, a začne ho nahrávat
 * znovu — přesně tahle regrese se tu už jednou stala.
 */
const loadError = ref('')
const actionError = ref('')
const success = ref('')

const storageAvailable = ref(true)
const certificates = ref<PayrollSigningCertificate[]>([])
const profile = ref<PayrollSigningProfile | null>(null)
const warnings = ref<PayrollSigningWarning[]>([])
const selectedCredentialId = ref<number | null>(null)
const registeredSerial = ref('')

const stepPassword = ref('')
const stepTotpCode = ref('')
const stepPasskeyToken = ref('')
const passkeyBusy = ref(false)
const passkeySupported = isWebAuthnAvailable()

const canWrite = computed(() => auth.canWrite('payroll.submissions'))
const hasPasskey = computed(() =>
  auth.user?.mfa_methods?.includes('passkey') === true
  || (auth.user?.passkey_count ?? 0) > 0,
)
const hasTotp = computed(() => auth.user?.totp_enabled === true)

const busy = computed(() => loading.value || saving.value || removing.value)

const selectedCertificate = computed(() =>
  certificates.value.find(item => item.id === selectedCredentialId.value) ?? null,
)

/** Volba uložená jiným uživatelem: certifikát v trezoru nevidíme, ale víme, že tam je. */
const foreignProfile = computed(() =>
  profile.value !== null && !profile.value.certificate_accessible,
)

const certificateOptions = computed(() => certificates.value.map(certificate => ({
  value: certificate.id,
  label: certificate.label || certificate.subject,
  secondary: optionSecondary(certificate),
})))

function optionSecondary(certificate: PayrollSigningCertificate): string {
  if (certificate.expired) {
    return t('payroll.submissions.signing.picker.option_expired', {
      date: certificate.valid_to ?? '',
    })
  }
  if (certificate.not_yet_valid) {
    return t('payroll.submissions.signing.picker.option_not_yet_valid', {
      date: certificate.valid_from ?? '',
    })
  }
  if (!certificate.enabled_for_supplier) {
    return t('payroll.submissions.signing.picker.option_not_enabled')
  }
  return t('payroll.submissions.signing.picker.option_valid_until', {
    date: certificate.valid_to ?? '',
  })
}

interface Badge {
  key: string
  label: string
  tone: string
}

/**
 * Expirace se hlásí barevně a bez schovávání: certifikát přestane fungovat
 * v den vypršení a uživatel to má vidět před termínem, ne po něm.
 */
function certificateBadges(certificate: PayrollSigningCertificate): Badge[] {
  const badges: Badge[] = []
  if (certificate.expired) {
    badges.push({
      key: 'expired',
      label: t('payroll.submissions.signing.badge.expired', { date: certificate.valid_to ?? '' }),
      tone: 'bg-danger-100 text-danger-700',
    })
  } else if (certificate.not_yet_valid) {
    badges.push({
      key: 'not_yet_valid',
      label: t('payroll.submissions.signing.badge.not_yet_valid', {
        date: certificate.valid_from ?? '',
      }),
      tone: 'bg-warning-100 text-warning-800',
    })
  } else if (
    certificate.expires_in_days !== null
    && certificate.expires_in_days <= EXPIRY_NOTICE_DAYS
  ) {
    badges.push({
      key: 'expires_soon',
      label: t('payroll.submissions.signing.badge.expires_soon', {
        days: certificate.expires_in_days,
      }),
      tone: 'bg-warning-100 text-warning-800',
    })
  } else {
    badges.push({
      key: 'usable',
      label: t('payroll.submissions.signing.badge.usable'),
      tone: 'bg-success-100 text-success-700',
    })
  }
  if (!certificate.enabled_for_supplier) {
    badges.push({
      key: 'not_enabled',
      label: t('payroll.submissions.signing.badge.not_enabled'),
      tone: 'bg-danger-100 text-danger-700',
    })
  }
  return badges
}

/** Vada, která podpis rovnou zastaví, se nesmí vykreslit stejně jako doporučení. */
const BLOCKING_WARNINGS = new Set([
  'certificate_expired',
  'certificate_not_yet_valid',
  'certificate_not_enabled_for_supplier',
])

function warningTone(code: string): string {
  return BLOCKING_WARNINGS.has(code)
    ? 'border-danger-500/30 bg-danger-50 text-danger-700'
    : 'border-warning-500/30 bg-warning-50 text-warning-700'
}

function resetStepUp() {
  stepPassword.value = ''
  stepTotpCode.value = ''
  stepPasskeyToken.value = ''
}

const stepUpMissing = computed(() => {
  if (stepPasskeyToken.value) return ''
  if (!stepPassword.value) return t('payroll.submissions.signing.step_up.password_missing')
  if (hasTotp.value && !/^\d{6}$/.test(stepTotpCode.value.trim())) {
    return t('payroll.submissions.signing.step_up.totp_missing')
  }
  return ''
})

function proof() {
  return {
    password: stepPassword.value || undefined,
    totp_code: stepTotpCode.value.trim() || undefined,
    step_up_token: stepPasskeyToken.value || undefined,
  }
}

async function verifyPasskey() {
  if (!passkeySupported) return
  passkeyBusy.value = true
  actionError.value = ''
  try {
    // Operace se jmenuje `epo.certificate` i tady: backend proof konzumuje pod
    // jediným jménem pro celý trezor (viz EpoStepUpService), mzdový `purpose`
    // slouží jen k logování. Jiné jméno by proof zneplatnilo.
    const flow = await authApi.passkeyStepUpOptions('epo.certificate')
    const credential = await getCredential(flow.public_key)
    stepPasskeyToken.value = await authApi.passkeyStepUpVerify(
      flow.flow_token,
      'epo.certificate',
      credential,
    )
  } catch (exception: unknown) {
    actionError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.signing.step_up.passkey_failed'),
    )
  } finally {
    passkeyBusy.value = false
  }
}

async function load() {
  loading.value = true
  loadError.value = ''
  actionError.value = ''
  success.value = ''
  try {
    const view = await payrollApi.signingProfile(environment.value)
    storageAvailable.value = view.storage_available
    certificates.value = view.certificates
    profile.value = view.profile
    warnings.value = view.warnings
    selectedCredentialId.value = view.profile?.credential_id ?? null
    registeredSerial.value = view.profile?.cssz_registered_serial ?? ''
  } catch (exception: unknown) {
    // Stav zůstává NEZNÁMÝ, ne prázdný — šablona podle `loadError` skryje
    // všechno ostatní, aby se selhání nedalo přečíst jako „nic nastaveného".
    certificates.value = []
    profile.value = null
    warnings.value = []
    selectedCredentialId.value = null
    registeredSerial.value = ''
    loadError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.signing.load_failed'),
    )
  } finally {
    loading.value = false
  }
}

async function switchEnvironment(next: PayrollSigningEnvironment) {
  if (next === environment.value || busy.value) return
  environment.value = next
  resetStepUp()
  await load()
}

async function save() {
  if (!canWrite.value || selectedCredentialId.value === null) return
  if (stepUpMissing.value) {
    actionError.value = stepUpMissing.value
    return
  }
  saving.value = true
  actionError.value = ''
  success.value = ''
  try {
    const result = await payrollApi.saveSigningProfile({
      environment: environment.value,
      credential_id: selectedCredentialId.value,
      cssz_registered_serial: registeredSerial.value.trim(),
      row_version: profile.value?.row_version ?? null,
    }, proof())
    profile.value = result.profile
    warnings.value = result.warnings
    registeredSerial.value = result.profile.cssz_registered_serial ?? ''
    success.value = t('payroll.submissions.signing.saved')
    resetStepUp()
  } catch (exception: unknown) {
    actionError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.signing.save_failed'),
    )
    // Passkey proof je jednorázový a server ho spotřebuje dřív, než dojde na
    // kontrolu sériového čísla — po chybě už neplatí. Heslo se nechává, aby ho
    // uživatel nepsal znovu kvůli překlepu v sériovém čísle.
    stepPasskeyToken.value = ''
  } finally {
    saving.value = false
  }
}

async function remove() {
  if (!canWrite.value || profile.value === null) return
  if (stepUpMissing.value) {
    actionError.value = stepUpMissing.value
    return
  }
  removing.value = true
  actionError.value = ''
  success.value = ''
  try {
    await payrollApi.deleteSigningProfile(environment.value, proof())
    profile.value = null
    warnings.value = []
    selectedCredentialId.value = null
    registeredSerial.value = ''
    success.value = t('payroll.submissions.signing.removed')
    resetStepUp()
  } catch (exception: unknown) {
    actionError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.signing.remove_failed'),
    )
    stepPasskeyToken.value = ''
  } finally {
    removing.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="space-y-4" data-test="payroll-signing-certificate">
    <div class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
          <h2 class="text-lg font-semibold text-neutral-900">
            {{ t('payroll.submissions.signing.title') }}
          </h2>
          <p class="mt-1 text-sm text-neutral-500">
            {{ t('payroll.submissions.signing.description') }}
          </p>
        </div>
        <button
          type="button"
          data-test="signing-reload"
          :class="btnOutline('neutral')"
          :disabled="busy"
          @click="load"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.cycle" />
          </svg>
          {{ t('common.refresh') }}
        </button>
      </div>

      <div class="mt-5">
        <span class="mb-1 block text-sm font-medium text-neutral-700">
          {{ t('payroll.submissions.signing.environment.label') }}
        </span>
        <div
          class="inline-flex flex-wrap gap-1 rounded-lg border border-neutral-200 bg-neutral-50 p-1"
          role="group"
          :aria-label="t('payroll.submissions.signing.environment.label')"
        >
          <button
            v-for="option in ENVIRONMENTS"
            :key="option"
            type="button"
            :data-test="`signing-environment-${option}`"
            :aria-pressed="environment === option"
            class="cursor-pointer whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50"
            :class="environment === option
              ? (option === 'production'
                ? 'bg-warning-500 text-white shadow-sm'
                : 'bg-payroll-600 text-white shadow-sm')
              : 'text-neutral-600 hover:text-neutral-900'"
            :disabled="busy"
            @click="switchEnvironment(option)"
          >
            {{ t(`payroll.submissions.signing.environment.${option}`) }}
          </button>
        </div>
        <p
          class="mt-3 rounded-lg border p-3 text-sm"
          :class="environment === 'production'
            ? 'border-warning-500/40 bg-warning-50 text-warning-800'
            : 'border-payroll-500/30 bg-payroll-50 text-neutral-700'"
          data-test="signing-environment-note"
        >
          {{ t(`payroll.submissions.signing.environment.${environment}_note`) }}
        </p>
      </div>
    </div>

    <div
      v-if="loadError"
      data-test="signing-load-error"
      class="rounded-xl border border-danger-500/30 bg-danger-50 p-4 text-sm text-danger-700"
      role="alert"
    >
      <p class="font-medium">{{ loadError }}</p>
      <p class="mt-1">{{ t('payroll.submissions.signing.state_unknown') }}</p>
    </div>

    <div v-else-if="loading" class="h-64 animate-pulse rounded-xl bg-neutral-100" />

    <template v-else>
      <div
        v-if="!storageAvailable"
        data-test="signing-storage-unavailable"
        class="rounded-xl border border-warning-500/30 bg-warning-50 p-4 text-sm text-warning-800"
        role="alert"
      >
        {{ t('payroll.submissions.signing.storage_unavailable') }}
      </div>

      <div
        v-if="warnings.length"
        class="space-y-2"
        data-test="signing-warnings"
      >
        <p
          v-for="warning in warnings"
          :key="warning.code"
          class="rounded-xl border p-4 text-sm"
          :class="warningTone(warning.code)"
          role="alert"
        >
          {{ warning.message }}
        </p>
      </div>

      <p
        v-if="actionError"
        data-test="signing-error"
        class="rounded-xl border border-danger-500/30 bg-danger-50 p-4 text-sm text-danger-700"
        role="alert"
      >
        {{ actionError }}
      </p>
      <p
        v-if="success"
        data-test="signing-success"
        class="rounded-xl border border-success-500/30 bg-success-50 p-4 text-sm text-success-700"
        role="status"
      >
        {{ success }}
      </p>

      <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
        <h3 class="text-base font-semibold text-neutral-900">
          {{ t('payroll.submissions.signing.current.title') }}
        </h3>
        <p
          v-if="!profile"
          class="mt-2 rounded-lg border border-dashed border-neutral-300 p-4 text-sm text-neutral-600"
          data-test="signing-current-none"
        >
          {{ t('payroll.submissions.signing.current.none') }}
        </p>
        <div v-else class="mt-2 space-y-2 text-sm" data-test="signing-current">
          <p class="text-neutral-800">
            {{ t('payroll.submissions.signing.current.selected', {
              label: profile.certificate?.label || t('payroll.submissions.signing.current.unknown_label'),
            }) }}
          </p>
          <p v-if="foreignProfile" class="text-warning-700">
            {{ t('payroll.submissions.signing.current.foreign') }}
          </p>
          <p class="text-neutral-500">
            {{ t('payroll.submissions.signing.current.registered_serial', {
              serial: profile.cssz_registered_serial
                || t('payroll.submissions.signing.current.registered_serial_missing'),
            }) }}
          </p>
          <p v-if="profile.updated_at" class="text-xs text-neutral-500">
            {{ t('payroll.submissions.signing.current.updated_at', { at: profile.updated_at }) }}
          </p>
        </div>
      </section>

      <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
        <h3 class="text-base font-semibold text-neutral-900">
          {{ t('payroll.submissions.signing.picker.title') }}
        </h3>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">
          {{ t('payroll.submissions.signing.picker.description') }}
        </p>

        <div
          v-if="certificates.length === 0"
          class="mt-4 rounded-lg border border-dashed border-neutral-300 p-4 text-sm text-neutral-600"
          data-test="signing-vault-empty"
        >
          <p>{{ t('payroll.submissions.signing.vault.empty') }}</p>
          <RouterLink
            :to="{ name: 'admin-electronic-signatures' }"
            class="mt-3 inline-flex"
            :class="btnOutline('primary')"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.lock" />
            </svg>
            {{ t('payroll.submissions.signing.vault.open') }}
          </RouterLink>
        </div>

        <template v-else>
          <label class="mt-4 block max-w-xl">
            <span class="mb-1 block text-sm font-medium text-neutral-700">
              {{ t('payroll.submissions.signing.picker.label') }}
            </span>
            <SearchableSelect
              v-model="selectedCredentialId"
              data-test="signing-certificate"
              :options="certificateOptions"
              :placeholder="t('payroll.submissions.signing.picker.placeholder')"
              :no-results-label="t('payroll.submissions.signing.picker.no_results')"
              :disabled="!canWrite || busy"
              accent="payroll"
            />
          </label>

          <div
            v-if="selectedCertificate"
            class="mt-4 rounded-lg border border-neutral-200 p-4"
            data-test="signing-certificate-detail"
          >
            <div class="flex flex-wrap items-center gap-2">
              <span
                v-for="badge in certificateBadges(selectedCertificate)"
                :key="badge.key"
                class="rounded-full px-2.5 py-1 text-xs font-semibold"
                :class="badge.tone"
              >
                {{ badge.label }}
              </span>
            </div>
            <dl class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
              <div>
                <dt class="text-xs uppercase tracking-wide text-neutral-500">
                  {{ t('payroll.submissions.signing.certificate.subject') }}
                </dt>
                <dd class="mt-0.5 break-words text-neutral-800">{{ selectedCertificate.subject }}</dd>
              </div>
              <div>
                <dt class="text-xs uppercase tracking-wide text-neutral-500">
                  {{ t('payroll.submissions.signing.certificate.issuer') }}
                </dt>
                <dd class="mt-0.5 break-words text-neutral-800">{{ selectedCertificate.issuer }}</dd>
              </div>
              <div>
                <dt class="text-xs uppercase tracking-wide text-neutral-500">
                  {{ t('payroll.submissions.signing.certificate.validity') }}
                </dt>
                <dd class="mt-0.5 text-neutral-800">
                  {{ selectedCertificate.valid_from ?? '—' }} — {{ selectedCertificate.valid_to ?? '—' }}
                </dd>
              </div>
              <div>
                <dt class="text-xs uppercase tracking-wide text-neutral-500">
                  {{ t('payroll.submissions.signing.certificate.serial_hex') }}
                </dt>
                <dd class="mt-0.5 break-all font-mono text-xs text-neutral-800">
                  {{ selectedCertificate.serial_hex ?? t('payroll.submissions.signing.certificate.serial_unknown') }}
                </dd>
              </div>
              <div class="sm:col-span-2">
                <dt class="text-xs uppercase tracking-wide text-neutral-500">
                  {{ t('payroll.submissions.signing.certificate.serial_decimal') }}
                </dt>
                <dd
                  class="mt-0.5 break-all font-mono text-xs text-neutral-800"
                  data-test="signing-serial-decimal"
                >
                  {{ selectedCertificate.serial_decimal ?? t('payroll.submissions.signing.certificate.serial_unknown') }}
                </dd>
              </div>
            </dl>
          </div>

          <label class="mt-5 block max-w-xl">
            <span class="mb-1 block text-sm font-medium text-neutral-700">
              {{ t('payroll.submissions.signing.serial.label') }}
            </span>
            <input
              v-model="registeredSerial"
              data-test="signing-registered-serial"
              type="text"
              inputmode="text"
              autocomplete="off"
              :disabled="!canWrite || busy"
              :placeholder="t('payroll.submissions.signing.serial.placeholder')"
              class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 font-mono text-sm"
            >
            <span class="mt-1 block text-xs text-neutral-500">
              {{ t('payroll.submissions.signing.serial.hint') }}
            </span>
            <span class="mt-1 block text-xs text-neutral-500">
              {{ t('payroll.submissions.signing.serial.hint_optional') }}
            </span>
          </label>

          <div
            v-if="canWrite"
            class="mt-5 grid gap-3 rounded-lg border border-neutral-200 p-4 sm:grid-cols-2"
          >
            <p class="text-sm font-medium text-neutral-800 sm:col-span-2">
              {{ t('payroll.submissions.signing.step_up.title') }}
            </p>
            <div v-if="hasPasskey" class="flex flex-wrap items-center gap-3 sm:col-span-2">
              <p v-if="stepPasskeyToken" class="text-sm font-medium text-success-600">
                {{ t('payroll.submissions.signing.step_up.passkey_verified') }}
              </p>
              <template v-else-if="passkeySupported">
                <button
                  type="button"
                  data-test="signing-passkey"
                  :class="btnOutline('primary')"
                  :disabled="passkeyBusy || busy"
                  @click="verifyPasskey"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path :d="ICONS.lock" />
                  </svg>
                  {{ passkeyBusy
                    ? t('auth.passkey_verifying')
                    : t('payroll.submissions.signing.step_up.passkey_verify') }}
                </button>
                <span class="text-xs text-neutral-500">
                  {{ t('payroll.submissions.signing.step_up.or_password') }}
                </span>
              </template>
              <p v-else class="text-xs text-warning-700">
                {{ t('payroll.submissions.signing.step_up.passkey_unsupported') }}
              </p>
            </div>
            <template v-if="!stepPasskeyToken">
              <label class="block text-xs text-neutral-600">
                {{ t('payroll.submissions.signing.step_up.password') }}
                <input
                  v-model="stepPassword"
                  data-test="signing-password"
                  type="password"
                  autocomplete="current-password"
                  class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm"
                >
              </label>
              <label class="block text-xs text-neutral-600">
                {{ t('payroll.submissions.signing.step_up.totp') }}
                <input
                  v-model="stepTotpCode"
                  data-test="signing-totp"
                  type="text"
                  inputmode="numeric"
                  autocomplete="one-time-code"
                  maxlength="6"
                  class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm"
                >
              </label>
            </template>
            <p class="text-xs text-neutral-500 sm:col-span-2">
              {{ t('payroll.submissions.signing.step_up.hint') }}
            </p>
          </div>

          <div v-if="canWrite" class="mt-5 flex flex-wrap justify-end gap-2">
            <button
              v-if="profile"
              type="button"
              data-test="signing-remove"
              :class="btnOutline('danger')"
              :disabled="busy"
              @click="remove"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.trash" />
              </svg>
              {{ removing
                ? t('payroll.submissions.signing.removing')
                : t('payroll.submissions.signing.remove') }}
            </button>
            <button
              type="button"
              data-test="signing-save"
              :class="btnFilled('primary')"
              :disabled="busy || selectedCredentialId === null"
              @click="save"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.check" />
              </svg>
              {{ saving
                ? t('payroll.submissions.signing.saving')
                : t('payroll.submissions.signing.save') }}
            </button>
          </div>
          <p v-else class="mt-5 text-sm text-neutral-500">
            {{ t('payroll.submissions.signing.read_only') }}
          </p>
        </template>
      </section>
    </template>
  </section>
</template>
