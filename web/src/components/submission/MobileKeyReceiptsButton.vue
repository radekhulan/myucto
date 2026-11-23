<script setup lang="ts">
/**
 * „Načíst doručenky" jedním přihlášením Mobilním klíčem — pro všechny
 * odeslané zprávy, které ještě doručenku nemají.
 *
 * Stejný start/potvrzení jako {@link MobileKeyBatchSendButton}; po potvrzení
 * v mobilu server stáhne dodejky odeslaných zpráv a relaci hned ukončí.
 * Schránka doručených zpráv se tu nečte.
 */
import { onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import { dataBoxApi, type IsdsMobileCredentialProfile, type ReceiptBatchResult } from '@/api/dataBox'
import { btnFilledSm, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import IsdsSavedAccessPicker from './IsdsSavedAccessPicker.vue'
import { useIsdsSavedAccess } from './useIsdsSavedAccess'

const props = defineProps<{
  environment: 'production' | 'test'
  /** Kolik odeslaných zpráv čeká na doručenku — jen pro popisek. */
  pending: number
}>()
const emit = defineEmits<{ done: [result: ReceiptBatchResult] }>()
const { t } = useI18n()

const open = ref(false)
const method = ref<'mobile_key' | 'saved'>('mobile_key')
const access = useIsdsSavedAccess(() => props.environment, 'receipts', () => open.value)
const canUseSaved = access.canUseSelected
const username = ref('')
const code = ref('')
const useSaved = ref(false)
const savedProfile = ref<IsdsMobileCredentialProfile | null>(null)
const flowToken = ref('')
const status = ref('')
const busy = ref(false)
const error = ref('')
let timer: ReturnType<typeof setTimeout> | null = null

function clearTimer() {
  if (timer !== null) clearTimeout(timer)
  timer = null
}

async function openPanel() {
  method.value = 'mobile_key'
  open.value = true
  error.value = ''
  flowToken.value = ''
  status.value = ''
  code.value = ''
  try {
    savedProfile.value = await dataBoxApi.mobileKeyProfile(props.environment)
    useSaved.value = savedProfile.value.saved
    username.value = savedProfile.value.username ?? ''
  } catch {
    savedProfile.value = null
  }
}

function close() {
  clearTimer()
  open.value = false
  flowToken.value = ''
  status.value = ''
  code.value = ''
}

async function fetchSavedReceipts() {
  const selected = access.selected.value
  if (!canUseSaved.value || !selected || busy.value) return
  const isCurrent = access.captureScope()
  busy.value = true
  error.value = ''
  try {
    const result = await dataBoxApi.downloadReceiptsBatchWithPassword(
      props.environment, { access_id: selected.id, authorized: true },
    )
    if (!isCurrent()) return
    close()
    emit('done', result)
  } catch (exception) {
    if (isCurrent()) error.value = apiErrorMessage(exception, t('databox.outbox.receiptsBatch.action'))
  } finally {
    busy.value = false
  }
}

async function start() {
  const wantsSaved = useSaved.value && code.value === ''
  if (!wantsSaved && (username.value.trim() === '' || code.value === '')) {
    error.value = t('databox.outbox.mobileKey.credentialsRequired')
    return
  }
  busy.value = true
  error.value = ''
  try {
    const started = await dataBoxApi.startMobileKeyOutboxBatch(
      props.environment,
      wantsSaved ? '' : username.value.trim(),
      wantsSaved ? '' : code.value,
      wantsSaved,
    )
    flowToken.value = started.flow_token
    status.value = started.description
    code.value = ''
    timer = setTimeout(() => { void poll() }, 1500)
  } catch (exception) {
    error.value = apiErrorMessage(exception, t('payroll.submissions.monthly_checklist.send.load_receipts'))
  } finally {
    busy.value = false
  }
}

async function poll() {
  if (flowToken.value === '') return
  try {
    const result = await dataBoxApi.downloadReceiptsBatchWithMobileKey(flowToken.value, props.environment)
    status.value = result.description
    if (result.result) {
      const done = result.result
      close()
      emit('done', done)
      return
    }
    timer = setTimeout(() => { void poll() }, 2000)
  } catch (exception) {
    // Vypršelou relaci neobnovujeme sami — novou musí potvrdit člověk.
    clearTimer()
    flowToken.value = ''
    error.value = apiErrorMessage(exception, t('payroll.submissions.monthly_checklist.send.load_receipts'))
  }
}

onBeforeUnmount(clearTimer)
</script>

<template>
  <div>
    <button
      v-if="!open"
      type="button"
      :class="[btnFilledSm('success'), 'whitespace-nowrap']"
      data-test="mobile-key-receipts-action"
      @click="openPanel"
    >
      <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path :d="ICONS.download" />
      </svg>
      {{ t('payroll.submissions.monthly_checklist.send.load_receipts_count', { count: pending }) }}
    </button>
    <div
      v-else
      class="mt-2 rounded-lg border border-success-500/30 bg-surface p-3"
      data-test="mobile-key-receipts-form"
    >
      <p class="text-sm text-neutral-700">
        {{ t('payroll.submissions.monthly_checklist.send.receipts_intro') }}
      </p>
      <div v-if="flowToken === '' && access.state.choices.length" class="mt-3 flex flex-wrap gap-3">
        <label class="flex items-center gap-2 text-sm">
          <input v-model="method" type="radio" value="mobile_key" :disabled="busy">
          {{ t('databox.inbox.auth.mobile_key.title') }}
        </label>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="method" type="radio" value="saved" :disabled="busy" @change="access.reset()">
          {{ t('databox.savedAccess.title') }}
        </label>
      </div>
      <IsdsSavedAccessPicker v-if="method === 'saved'" class="mt-3" :access="access" :disabled="busy" />
      <div v-else-if="flowToken === ''" class="mt-3 grid gap-3 sm:grid-cols-2">
        <label class="block">
          <span class="text-sm font-medium">{{ t('databox.outbox.mobileKey.username') }}</span>
          <input v-model="username" type="text" maxlength="128" autocomplete="off" class="form-input mt-1 w-full">
        </label>
        <label class="block">
          <span class="text-sm font-medium">{{ t('databox.outbox.mobileKey.code') }}</span>
          <input v-model="code" type="password" maxlength="512" autocomplete="off" class="form-input mt-1 w-full">
          <span class="mt-1 block text-xs text-neutral-500">{{ t('databox.outbox.mobileKey.codeHint') }}</span>
        </label>
      </div>
      <label v-if="method === 'mobile_key' && flowToken === '' && savedProfile?.saved" class="mt-3 flex items-center gap-2 text-sm">
        <input v-model="useSaved" type="checkbox">
        {{ t('databox.outbox.mobileKey.useSaved') }}
      </label>
      <p v-if="status" class="mt-3 text-sm text-neutral-700" data-test="mobile-key-receipts-status">{{ status }}</p>
      <p v-if="error" class="mt-3 text-sm text-danger-700" role="alert" data-test="mobile-key-receipts-error">{{ error }}</p>
      <div class="mt-3 flex flex-wrap gap-2">
        <button
          v-if="method === 'saved'"
          type="button"
          :class="btnFilledSm('success')"
          :disabled="busy || !canUseSaved"
          data-test="payroll-saved-receipts-submit"
          @click="fetchSavedReceipts"
        >
          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.download" /></svg>
          {{ t('databox.outbox.receiptsBatch.action') }}
        </button>
        <button
          v-else-if="flowToken === ''"
          type="button"
          :class="[btnFilledSm('success'), 'whitespace-nowrap']"
          :disabled="busy"
          data-test="mobile-key-receipts-request"
          @click="start"
        >
          {{ t('databox.outbox.mobileKey.request') }}
        </button>
        <button type="button" :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']" @click="close">
          {{ t('common.cancel') }}
        </button>
      </div>
    </div>
  </div>
</template>
