<script setup lang="ts">
/**
 * Výběr cesty, jak odeslat jednu zprávu z fronty datové schránky.
 *
 * Jedna komponenta pro všechna místa, odkud se odesílá (odchozí podání,
 * daňová podání, mzdová podání): čtení schránky nabízí čtyři způsoby
 * přihlášení a odeslání musí nabízet tytéž, jinak účetní s účtem jen na heslo
 * nebo SMS zprávu připraví a odeslat ji nemůže. Brána a certifikát se ukazují
 * i nedostupné, s důvodem — schovaná volba vypadá jako chybějící funkce.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import { dataBoxApi, type GatewayStart } from '@/api/dataBox'
import { ICONS, btnFilled, btnOutlineSm } from '@/components/ui/buttonStyles'
import MobileKeySendButton from '@/components/submission/MobileKeySendButton.vue'
import { navigateFullPage } from '@/workspace/fullPageNavigation'

type Method = 'gateway' | 'mobile_key' | 'password' | 'sms' | 'certificate'

const props = defineProps<{
  outboxId: number
  environment: 'production' | 'test'
  /** Doporučení transportu ze zařazení do fronty (`automatic` = zapnutá brána). */
  transport?: { automatic: boolean; channel: string; reason?: string | null } | null
  /** Zahájení brány s oprávněním volajícího (obecné, mzdové, daňové). */
  gatewayStart?: (id: number) => Promise<GatewayStart>
  /** Klient smí použít jen daňovou bránu, bez přístupu do obecné schránky. */
  gatewayOnly?: boolean
}>()
const emit = defineEmits<{ sent: [dispatched: boolean] }>()
const { t } = useI18n()

const methods = computed<Method[]>(() => props.gatewayOnly
  ? ['gateway']
  : ['gateway', 'mobile_key', 'password', 'sms', 'certificate'])
const ACCESS_LINK = '/admin/databox?tab=access'

const gatewayRegistered = ref(false)
const certificateStored = ref(false)
const method = ref<Method>('mobile_key')
const touched = ref(false)
const busy = ref(false)
const error = ref('')
const forbidden = ref(false)
const gateway = ref<GatewayStart | null>(null)
const username = ref('')
const password = ref('')
const smsFlowToken = ref('')
const smsStatus = ref('')
const smsCode = ref('')
const certificateConfirmed = ref(false)

const outboxLink = computed(() => `/admin/databox?tab=outbox&outbox=${props.outboxId}`)

const gatewayAvailable = computed(() =>
  gatewayRegistered.value
  || props.transport?.automatic === true
  || props.transport?.channel === 'gateway',
)

const recommended = computed<Method>(() => (props.gatewayOnly || gatewayAvailable.value ? 'gateway' : 'mobile_key'))

function available(option: Method): boolean {
  if (option === 'gateway') return gatewayAvailable.value
  if (option === 'certificate') return certificateStored.value
  return true
}

function methodTitle(option: Method): string {
  switch (option) {
    case 'gateway': return t('databox.send.gateway.title')
    case 'mobile_key': return t('databox.send.mobile_key.title')
    case 'password': return t('databox.send.password.title')
    case 'sms': return t('databox.send.sms.title')
    default: return t('databox.send.certificate.title')
  }
}

function methodDescription(option: Method): string {
  switch (option) {
    case 'gateway': return t('databox.send.gateway.description')
    case 'mobile_key': return t('databox.send.mobile_key.description')
    case 'password': return t('databox.send.password.description')
    case 'sms': return t('databox.send.sms.description')
    default: return t('databox.send.certificate.description')
  }
}

function resetSecrets() {
  password.value = ''
  smsCode.value = ''
}

function select(option: Method) {
  if (!available(option) || busy.value) return
  touched.value = true
  method.value = option
  error.value = ''
  forbidden.value = false
  smsFlowToken.value = ''
  smsStatus.value = ''
  resetSecrets()
}

function handleError(exception: unknown, fallback: string) {
  forbidden.value = (exception as { response?: { status?: number } })?.response?.status === 403
  error.value = forbidden.value
    ? t('databox.send.forbidden')
    : apiErrorMessage(exception, fallback)
}

function finish(dispatched: boolean) {
  if (!dispatched) error.value = t('databox.send.notDispatched')
  emit('sent', dispatched)
}

async function startGateway() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    gateway.value = await (props.gatewayStart ?? dataBoxApi.gatewayStart)(props.outboxId)
  } catch (exception) {
    handleError(exception, t('databox.send.gateway.failed'))
  } finally {
    busy.value = false
  }
}

function continueGateway() {
  // Plná navigace: cíl je v perimetru ISDS a vrací se na návratovou adresu.
  if (gateway.value) navigateFullPage(gateway.value.redirect_url)
}

function credentialsFilled(): boolean {
  if (username.value.trim() !== '' && password.value !== '') return true
  error.value = t('databox.send.credentialsRequired')
  return false
}

async function sendWithPassword() {
  if (busy.value || !credentialsFilled()) return
  busy.value = true
  error.value = ''
  try {
    const result = await dataBoxApi.passwordSend(
      props.outboxId,
      props.environment,
      username.value.trim(),
      password.value,
    )
    finish(result.dispatched)
  } catch (exception) {
    handleError(exception, t('databox.outbox.passwordSend.failed'))
  } finally {
    resetSecrets()
    busy.value = false
  }
}

async function requestSms() {
  if (busy.value || !credentialsFilled()) return
  busy.value = true
  error.value = ''
  try {
    const started = await dataBoxApi.smsSendStart(
      props.outboxId,
      props.environment,
      username.value.trim(),
      password.value,
    )
    smsFlowToken.value = started.flow_token
    smsStatus.value = started.description
  } catch (exception) {
    handleError(exception, t('databox.send.sms.failed'))
  } finally {
    // Heslo drží jen šifrovaný jednorázový flow na serveru, ne formulář.
    resetSecrets()
    busy.value = false
  }
}

async function confirmSms() {
  if (busy.value || smsFlowToken.value === '') return
  if (smsCode.value.trim() === '') {
    error.value = t('databox.send.sms.codeRequired')
    return
  }
  busy.value = true
  error.value = ''
  try {
    const result = await dataBoxApi.smsSendComplete(
      props.outboxId,
      props.environment,
      smsFlowToken.value,
      smsCode.value.trim(),
    )
    smsFlowToken.value = ''
    smsStatus.value = ''
    finish(result.dispatched)
  } catch (exception) {
    handleError(exception, t('databox.send.sms.failed'))
  } finally {
    smsCode.value = ''
    busy.value = false
  }
}

function resetSms() {
  smsFlowToken.value = ''
  smsStatus.value = ''
  smsCode.value = ''
  error.value = ''
}

async function sendWithCertificate() {
  if (busy.value || !certificateConfirmed.value) return
  busy.value = true
  error.value = ''
  try {
    const result = await dataBoxApi.certificateSend(props.outboxId, props.environment)
    finish(result.dispatched)
  } catch (exception) {
    handleError(exception, t('databox.outbox.certificateSend.failed'))
  } finally {
    busy.value = false
  }
}

async function loadAvailability() {
  if (props.gatewayOnly) return
  // Bez práva k nastavení schránky (403) o bráně ani certifikátu nic nevíme;
  // volba pak zůstane nedostupná s vysvětlením, ne skrytá.
  const [capabilities, credentials] = await Promise.all([
    Promise.resolve().then(() => dataBoxApi.gatewayCapabilities()).catch(() => []),
    Promise.resolve().then(() => dataBoxApi.credentials()).catch(() => []),
  ])
  gatewayRegistered.value = capabilities.some(r => r.environment === props.environment && r.available)
  certificateStored.value = credentials.some(c => c.environment === props.environment && !c.credential_missing)
}

onMounted(async () => {
  method.value = recommended.value
  await loadAvailability()
  if (!touched.value || !available(method.value)) method.value = recommended.value
})
onBeforeUnmount(resetSecrets)
</script>

<template>
  <div class="space-y-3" data-test="isds-send-methods">
    <div class="grid min-w-0 gap-2 sm:grid-cols-2" role="radiogroup" :aria-label="t('databox.send.title')">
      <label
        v-for="option in methods"
        :key="option"
        class="flex min-w-0 gap-3 rounded-lg border p-3"
        :class="!available(option)
          ? 'cursor-not-allowed border-neutral-200 bg-neutral-50 opacity-75'
          : method === option
            ? 'cursor-pointer border-primary-500 bg-primary-50'
            : 'cursor-pointer border-neutral-200 bg-surface hover:border-primary-400'"
        :data-test="`isds-send-method-${option}`"
      >
        <input
          type="radio"
          class="mt-0.5"
          :value="option"
          :checked="method === option"
          :disabled="!available(option) || busy"
          @change="select(option)"
        >
        <span class="min-w-0 break-words">
          <span class="flex flex-wrap items-center gap-2 text-sm font-medium text-neutral-900">
            {{ methodTitle(option) }}
            <span
              v-if="recommended === option && available(option)"
              class="inline-flex rounded-full border border-success-500/30 bg-success-50 px-2 py-0.5 text-[11px] font-medium text-success-700"
            >
              {{ t('databox.send.recommended') }}
            </span>
          </span>
          <span class="mt-1 block text-xs text-neutral-500">{{ methodDescription(option) }}</span>
          <span
            v-if="option === 'gateway' && !available(option)"
            class="mt-1 block text-xs text-warning-700 dark:text-warning-300"
            :data-test="`isds-send-unavailable-${option}`"
          >
            {{ t('databox.send.gateway.unavailable') }}
          </span>
          <span
            v-else-if="option === 'certificate' && !available(option)"
            class="mt-1 block text-xs text-warning-700 dark:text-warning-300"
            :data-test="`isds-send-unavailable-${option}`"
          >
            {{ t('databox.send.certificate.unavailable') }}
            <a :href="ACCESS_LINK" class="font-medium underline" data-test="isds-send-access-link">
              {{ t('databox.send.certificate.setUp') }}
            </a>
          </span>
        </span>
      </label>
    </div>

    <p
      v-if="environment === 'test'"
      class="rounded-md border border-warning-200 bg-warning-50 p-2 text-xs text-warning-800"
      data-test="isds-send-test-warning"
    >
      {{ t('databox.outbox.mobileKey.testEnvironmentWarning') }}
    </p>

    <div class="rounded-lg border border-neutral-200 p-3" :data-test="`isds-send-panel-${method}`">
      <template v-if="method === 'gateway'">
        <template v-if="gateway">
          <p class="text-sm font-medium">{{ t('databox.gateway.preflightTitle') }}</p>
          <p class="mt-1 text-sm text-neutral-700">{{ gateway.login_guidance }}</p>
          <p class="mt-2 text-xs text-neutral-600">{{ t('databox.gateway.credentialsStayInIsds') }}</p>
          <p v-if="!gateway.login_policy_documented" class="mt-2 text-xs text-warning-800">
            {{ t('databox.gateway.methodsByIsds') }}
          </p>
          <p class="mt-2 text-xs text-neutral-600">{{ t('databox.gateway.expiresAt', { value: gateway.expires_at }) }}</p>
          <button type="button" :class="[btnFilled('primary'), 'mt-3']" data-test="isds-send-gateway-continue" @click="continueGateway">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.send" />
            </svg>
            {{ t('databox.gateway.continueToIsds') }}
          </button>
        </template>
        <template v-else>
          <p class="text-sm text-neutral-700">{{ t('databox.send.gateway.intro') }}</p>
          <button type="button" :class="[btnFilled('primary'), 'mt-3']" :disabled="busy" data-test="isds-send-gateway-start" @click="startGateway">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.send" />
            </svg>
            {{ busy ? t('common.loading') : t('databox.gateway.prepare') }}
          </button>
        </template>
      </template>

      <MobileKeySendButton
        v-else-if="method === 'mobile_key'"
        :outbox-id="outboxId"
        :environment="environment"
        auto-open
        @sent="finish"
      />

      <template v-else-if="method === 'password' || method === 'sms'">
        <p class="text-sm text-neutral-700">
          {{ method === 'sms' ? t('databox.send.sms.intro') : t('databox.outbox.passwordSend.intro') }}
        </p>
        <div v-if="smsFlowToken === ''" class="mt-3 grid gap-3 sm:grid-cols-2">
          <label class="block text-sm">
            <span class="font-medium">{{ t('databox.outbox.passwordSend.username') }}</span>
            <input v-model="username" type="text" maxlength="128" autocomplete="off" class="form-input mt-1 w-full" data-test="isds-send-username">
          </label>
          <label class="block text-sm">
            <span class="font-medium">{{ t('databox.outbox.passwordSend.password') }}</span>
            <input v-model="password" type="password" maxlength="512" autocomplete="off" class="form-input mt-1 w-full" data-test="isds-send-password">
          </label>
        </div>
        <template v-else>
          <p v-if="smsStatus" class="mt-3 text-sm text-neutral-700" data-test="isds-send-sms-status">{{ smsStatus }}</p>
          <label class="mt-3 block text-sm sm:w-64">
            <span class="font-medium">{{ t('databox.send.sms.code') }}</span>
            <input
              v-model="smsCode"
              type="text"
              inputmode="numeric"
              maxlength="12"
              autocomplete="one-time-code"
              class="form-input mt-1 w-full"
              data-test="isds-send-sms-code"
            >
          </label>
        </template>
        <div class="mt-3 flex flex-wrap gap-2">
          <button
            v-if="method === 'password'"
            type="button"
            :class="btnFilled('primary')"
            :disabled="busy"
            data-test="isds-send-password-submit"
            @click="sendWithPassword"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" />
            </svg>
            {{ busy ? t('common.loading') : t('databox.outbox.passwordSend.submit') }}
          </button>
          <button
            v-else-if="smsFlowToken === ''"
            type="button"
            :class="btnFilled('primary')"
            :disabled="busy"
            data-test="isds-send-sms-request"
            @click="requestSms"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.bell" />
            </svg>
            {{ busy ? t('common.loading') : t('databox.send.sms.request') }}
          </button>
          <template v-else>
            <button
              type="button"
              :class="btnFilled('primary')"
              :disabled="busy"
              data-test="isds-send-sms-confirm"
              @click="confirmSms"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.send" />
              </svg>
              {{ busy ? t('common.loading') : t('databox.send.sms.confirm') }}
            </button>
            <button type="button" :class="btnOutlineSm('neutral')" :disabled="busy" @click="resetSms">
              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" />
              </svg>
              {{ t('databox.inbox.requestNewSms') }}
            </button>
          </template>
        </div>
      </template>

      <template v-else>
        <p class="text-sm text-neutral-700">{{ t('databox.send.certificate.intro') }}</p>
        <label class="mt-3 flex items-start gap-2 text-sm font-medium">
          <input v-model="certificateConfirmed" type="checkbox" class="mt-0.5" data-test="isds-send-certificate-confirm">
          <span>{{ t('databox.send.certificate.confirm') }}</span>
        </label>
        <button
          type="button"
          :class="[btnFilled('primary'), 'mt-3']"
          :disabled="busy || !certificateConfirmed"
          data-test="isds-send-certificate-submit"
          @click="sendWithCertificate"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.badgeCheck" />
          </svg>
          {{ busy ? t('common.loading') : t('databox.outbox.certificateSend.action') }}
        </button>
      </template>

      <div
        v-if="error"
        class="mt-3 rounded border border-danger-500/30 bg-danger-50 px-3 py-2 text-sm text-danger-700"
        role="alert"
        data-test="isds-send-error"
      >
        {{ error }}
        <a v-if="forbidden && !gatewayOnly" :href="outboxLink" class="mt-1 block font-medium underline" data-test="isds-send-open-outbox">
          {{ t('databox.send.openOutbox') }}
        </a>
      </div>
    </div>
  </div>
</template>
