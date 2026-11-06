<script setup lang="ts">
/**
 * Odeslání víc zpráv z fronty datové schránky jedním přihlášením.
 *
 * Protějšek {@link IsdsSendMethods} pro dávku: jednotlivá zpráva nabízí pět
 * způsobů přihlášení, dávka dřív jen Mobilní klíč — účetní s účtem jen na
 * heslo pak přehledy pojišťovnám připravila a hromadně odeslat nemohla.
 * U jedné zprávy se rovnou použije {@link IsdsSendMethods}, ať je výběr
 * všude stejný.
 *
 * Odesílací brána se pro dávku nenabízí: ISDS schvaluje každý koncept zvlášť
 * s přesměrováním na svou stránku, takže „jedním přihlášením" to nejde.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import {
  dataBoxApi,
  type GatewayStart,
  type MobileKeyBatchItemResult,
  type MobileKeyReceiptSession,
} from '@/api/dataBox'
import { ICONS, btnFilled, btnOutlineSm } from '@/components/ui/buttonStyles'
import IsdsSendMethods from '@/components/submission/IsdsSendMethods.vue'
import MobileKeyBatchSendButton from '@/components/submission/MobileKeyBatchSendButton.vue'

type Method = 'gateway' | 'mobile_key' | 'password' | 'sms' | 'certificate'

const props = defineProps<{
  outboxIds: number[]
  environment: 'production' | 'test'
  /** Doporučení transportu ze zařazení do fronty; platí u jedné zprávy. */
  transport?: { automatic: boolean; channel: string; reason?: string | null } | null
  gatewayStart?: (id: number) => Promise<GatewayStart>
}>()
const emit = defineEmits<{
  sent: [results: MobileKeyBatchItemResult[], receiptSession: MobileKeyReceiptSession | null]
}>()
const { t } = useI18n()

const METHODS: Method[] = ['gateway', 'mobile_key', 'password', 'sms', 'certificate']
const ACCESS_LINK = '/admin/databox?tab=access'

const single = computed(() => props.outboxIds.length === 1)
const method = ref<Method>('mobile_key')
const certificateStored = ref(false)
const busy = ref(false)
const error = ref('')
const username = ref('')
const password = ref('')
const smsFlowToken = ref('')
const smsStatus = ref('')
const smsCode = ref('')
const certificateConfirmed = ref(false)

function available(option: Method): boolean {
  if (option === 'gateway') return false
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
  method.value = option
  error.value = ''
  smsFlowToken.value = ''
  smsStatus.value = ''
  resetSecrets()
}

function finish(results: MobileKeyBatchItemResult[], receiptSession: MobileKeyReceiptSession | null = null) {
  emit('sent', results, receiptSession)
}

function finishSingle(dispatched: boolean) {
  const id = props.outboxIds[0]
  if (id === undefined) return
  finish([{ id, dispatched, row: null, error_code: null, error_message: null }])
}

function credentialsFilled(): boolean {
  if (username.value.trim() !== '' && password.value !== '') return true
  error.value = t('databox.send.credentialsRequired')
  return false
}

async function run(action: () => Promise<void>) {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    await action()
  } catch (exception) {
    error.value = apiErrorMessage(exception, t('databox.send.batch.failed'))
  } finally {
    busy.value = false
  }
}

async function sendWithPassword() {
  if (!credentialsFilled()) return
  await run(async () => {
    try {
      finish(await dataBoxApi.passwordSendBatch(
        props.outboxIds,
        props.environment,
        username.value.trim(),
        password.value,
      ))
    } finally {
      resetSecrets()
    }
  })
}

async function requestSms() {
  if (!credentialsFilled()) return
  await run(async () => {
    try {
      const started = await dataBoxApi.smsSendStartBatch(props.environment, username.value.trim(), password.value)
      smsFlowToken.value = started.flow_token
      smsStatus.value = started.description
    } finally {
      // Heslo drží jen šifrovaný jednorázový flow na serveru, ne formulář.
      resetSecrets()
    }
  })
}

async function confirmSms() {
  if (smsFlowToken.value === '') return
  if (smsCode.value.trim() === '') {
    error.value = t('databox.send.sms.codeRequired')
    return
  }
  await run(async () => {
    try {
      const results = await dataBoxApi.smsSendCompleteBatch(
        props.outboxIds,
        props.environment,
        smsFlowToken.value,
        smsCode.value.trim(),
      )
      smsFlowToken.value = ''
      smsStatus.value = ''
      finish(results)
    } finally {
      smsCode.value = ''
    }
  })
}

function resetSms() {
  smsFlowToken.value = ''
  smsStatus.value = ''
  smsCode.value = ''
  error.value = ''
}

async function sendWithCertificate() {
  if (!certificateConfirmed.value) return
  await run(async () => {
    finish(await dataBoxApi.certificateSendBatch(props.outboxIds, props.environment))
  })
}

onMounted(async () => {
  if (single.value) return
  // Bez práva k nastavení schránky (403) o certifikátu nic nevíme; volba pak
  // zůstane nedostupná s vysvětlením, ne skrytá.
  const credentials = await Promise.resolve().then(() => dataBoxApi.credentials()).catch(() => [])
  certificateStored.value = credentials.some(c => c.environment === props.environment && !c.credential_missing)
})
onBeforeUnmount(resetSecrets)
</script>

<template>
  <IsdsSendMethods
    v-if="single"
    :outbox-id="outboxIds[0]!"
    :environment="environment"
    :transport="transport"
    :gateway-start="gatewayStart"
    @sent="finishSingle"
  />
  <div v-else class="space-y-3" data-test="isds-batch-send-methods">
    <p class="text-sm text-neutral-700">{{ t('databox.send.batch.intro', { count: outboxIds.length }) }}</p>
    <div class="grid min-w-0 gap-2 sm:grid-cols-2" role="radiogroup" :aria-label="t('databox.send.title')">
      <label
        v-for="option in METHODS"
        :key="option"
        class="flex min-w-0 gap-3 rounded-lg border p-3"
        :class="!available(option)
          ? 'cursor-not-allowed border-neutral-200 bg-neutral-50 opacity-75'
          : method === option
            ? 'cursor-pointer border-primary-500 bg-primary-50'
            : 'cursor-pointer border-neutral-200 bg-surface hover:border-primary-400'"
        :data-test="`isds-batch-method-${option}`"
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
          <span class="block text-sm font-medium text-neutral-900">{{ methodTitle(option) }}</span>
          <span class="mt-1 block text-xs text-neutral-500">{{ methodDescription(option) }}</span>
          <span
            v-if="option === 'gateway'"
            class="mt-1 block text-xs text-warning-700 dark:text-warning-300"
            data-test="isds-batch-unavailable-gateway"
          >
            {{ t('databox.send.batch.gatewayUnavailable') }}
          </span>
          <span
            v-else-if="option === 'certificate' && !available(option)"
            class="mt-1 block text-xs text-warning-700 dark:text-warning-300"
            data-test="isds-batch-unavailable-certificate"
          >
            {{ t('databox.send.certificate.unavailable') }}
            <a :href="ACCESS_LINK" class="font-medium underline">{{ t('databox.send.certificate.setUp') }}</a>
          </span>
        </span>
      </label>
    </div>

    <p
      v-if="environment === 'test'"
      class="rounded-md border border-warning-200 bg-warning-50 p-2 text-xs text-warning-800"
    >
      {{ t('databox.outbox.mobileKey.testEnvironmentWarning') }}
    </p>

    <div class="rounded-lg border border-neutral-200 p-3" :data-test="`isds-batch-panel-${method}`">
      <MobileKeyBatchSendButton
        v-if="method === 'mobile_key'"
        :outbox-ids="outboxIds"
        :environment="environment"
        @sent="finish"
      />

      <template v-else-if="method === 'password' || method === 'sms'">
        <p class="text-sm text-neutral-700">
          {{ method === 'sms' ? t('databox.send.sms.intro') : t('databox.outbox.passwordSend.intro') }}
        </p>
        <div v-if="smsFlowToken === ''" class="mt-3 grid gap-3 sm:grid-cols-2">
          <label class="block text-sm">
            <span class="font-medium">{{ t('databox.outbox.passwordSend.username') }}</span>
            <input v-model="username" type="text" maxlength="128" autocomplete="off" class="form-input mt-1 w-full" data-test="isds-batch-username">
          </label>
          <label class="block text-sm">
            <span class="font-medium">{{ t('databox.outbox.passwordSend.password') }}</span>
            <input v-model="password" type="password" maxlength="512" autocomplete="off" class="form-input mt-1 w-full" data-test="isds-batch-password">
          </label>
        </div>
        <template v-else>
          <p v-if="smsStatus" class="mt-3 text-sm text-neutral-700">{{ smsStatus }}</p>
          <label class="mt-3 block text-sm sm:w-64">
            <span class="font-medium">{{ t('databox.send.sms.code') }}</span>
            <input
              v-model="smsCode"
              type="text"
              inputmode="numeric"
              maxlength="12"
              autocomplete="one-time-code"
              class="form-input mt-1 w-full"
              data-test="isds-batch-sms-code"
            >
          </label>
        </template>
        <div class="mt-3 flex flex-wrap gap-2">
          <button
            v-if="method === 'password'"
            type="button"
            :class="[btnFilled('primary'), 'whitespace-nowrap']"
            :disabled="busy"
            data-test="isds-batch-password-submit"
            @click="sendWithPassword"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" />
            </svg>
            {{ busy ? t('common.loading') : t('databox.send.batch.submit', { count: outboxIds.length }) }}
          </button>
          <button
            v-else-if="smsFlowToken === ''"
            type="button"
            :class="[btnFilled('primary'), 'whitespace-nowrap']"
            :disabled="busy"
            data-test="isds-batch-sms-request"
            @click="requestSms"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.bell" />
            </svg>
            {{ busy ? t('common.loading') : t('databox.send.sms.request') }}
          </button>
          <template v-else>
            <button
              type="button"
              :class="[btnFilled('primary'), 'whitespace-nowrap']"
              :disabled="busy"
              data-test="isds-batch-sms-confirm"
              @click="confirmSms"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.send" />
              </svg>
              {{ busy ? t('common.loading') : t('databox.send.batch.submit', { count: outboxIds.length }) }}
            </button>
            <button type="button" :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']" :disabled="busy" @click="resetSms">
              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" />
              </svg>
              {{ t('databox.inbox.requestNewSms') }}
            </button>
          </template>
        </div>
      </template>

      <template v-else-if="method === 'certificate'">
        <p class="text-sm text-neutral-700">{{ t('databox.send.certificate.intro') }}</p>
        <label class="mt-3 flex items-start gap-2 text-sm font-medium">
          <input v-model="certificateConfirmed" type="checkbox" class="mt-0.5" data-test="isds-batch-certificate-confirm">
          <span>{{ t('databox.send.batch.certificateConfirm', { count: outboxIds.length }) }}</span>
        </label>
        <button
          type="button"
          :class="[btnFilled('primary'), 'mt-3 whitespace-nowrap']"
          :disabled="busy || !certificateConfirmed"
          data-test="isds-batch-certificate-submit"
          @click="sendWithCertificate"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.badgeCheck" />
          </svg>
          {{ busy ? t('common.loading') : t('databox.send.batch.submit', { count: outboxIds.length }) }}
        </button>
      </template>

      <div
        v-if="error"
        class="mt-3 rounded border border-danger-500/30 bg-danger-50 px-3 py-2 text-sm text-danger-700"
        role="alert"
        data-test="isds-batch-error"
      >
        {{ error }}
      </div>
    </div>
  </div>
</template>
