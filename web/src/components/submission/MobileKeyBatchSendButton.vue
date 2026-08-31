<script setup lang="ts">
/**
 * Dávkové odeslání víc podání v JEDNÉ relaci Mobilního klíče.
 *
 * Bez tohohle by účetní musela potvrzovat v mobilu zvlášť pro ČSSZ a pro
 * každou ze sedmi zdravotních pojišťoven — osm potvrzení měsíčně je
 * nepoužitelné. Stejný start/confirm/poll cyklus jako
 * {@link MobileKeySendButton}, jen nese seznam `outboxIds` místo jednoho id.
 */
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import { dataBoxApi, type IsdsMobileCredentialProfile, type MobileKeyBatchItemResult } from '@/api/dataBox'
import { btnFilledSm, btnOutlineSm } from '@/components/ui/buttonStyles'

const props = defineProps<{
  outboxIds: number[]
  environment: 'production' | 'test'
}>()
const emit = defineEmits<{ sent: [results: MobileKeyBatchItemResult[]] }>()
const { t } = useI18n()

const open = ref(false)
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
    void poll()
  } catch (exception) {
    error.value = apiErrorMessage(exception, t('databox.outbox.mobileKey.action'))
  } finally {
    busy.value = false
  }
}

async function poll() {
  if (flowToken.value === '') return
  try {
    const result = await dataBoxApi.mobileKeyOutboxConfirmBatch(
      props.outboxIds,
      flowToken.value,
      props.environment,
    )
    status.value = result.description
    if (result.results) {
      const results = result.results
      close()
      emit('sent', results)
      return
    }
    timer = setTimeout(() => { void poll() }, 2000)
  } catch (exception) {
    clearTimer()
    flowToken.value = ''
    error.value = apiErrorMessage(exception, t('databox.outbox.mobileKey.action'))
  }
}
</script>

<template>
  <div>
    <button
      v-if="!open"
      type="button"
      :class="btnFilledSm('primary')"
      :disabled="outboxIds.length === 0"
      data-test="mobile-key-batch-send-action"
      @click="openPanel"
    >
      {{ t('payroll.submissions.overview.mobile_key_batch.action', { count: outboxIds.length }) }}
    </button>
    <div
      v-else
      class="mt-2 rounded-lg border border-primary-200 bg-primary-50/40 p-3"
      data-test="mobile-key-batch-send-form"
    >
      <p class="text-sm text-neutral-700">
        {{ t('payroll.submissions.overview.mobile_key_batch.intro', { count: outboxIds.length }) }}
      </p>
      <div v-if="flowToken === ''" class="mt-3 grid gap-3 sm:grid-cols-2">
        <label class="block">
          <span class="text-sm font-medium">{{ t('databox.outbox.mobileKey.username') }}</span>
          <input
            v-model="username"
            type="text"
            maxlength="128"
            autocomplete="off"
            class="form-input mt-1 w-full"
          >
        </label>
        <label class="block">
          <span class="text-sm font-medium">{{ t('databox.outbox.mobileKey.code') }}</span>
          <input
            v-model="code"
            type="password"
            maxlength="512"
            autocomplete="off"
            class="form-input mt-1 w-full"
          >
          <span class="mt-1 block text-xs text-neutral-500">{{ t('databox.outbox.mobileKey.codeHint') }}</span>
        </label>
      </div>
      <label
        v-if="flowToken === '' && savedProfile?.saved"
        class="mt-3 flex items-center gap-2 text-sm"
      >
        <input v-model="useSaved" type="checkbox">
        {{ t('databox.outbox.mobileKey.useSaved') }}
      </label>
      <p v-if="status" class="mt-3 text-sm text-neutral-700" data-test="mobile-key-batch-send-status">
        {{ status }}
      </p>
      <p v-if="error" class="mt-3 text-sm text-danger-700" role="alert" data-test="mobile-key-batch-send-error">
        {{ error }}
      </p>
      <div class="mt-3 flex flex-wrap gap-2">
        <button
          v-if="flowToken === ''"
          type="button"
          :class="btnFilledSm('primary')"
          :disabled="busy"
          data-test="mobile-key-batch-send-request"
          @click="start"
        >
          {{ t('databox.outbox.mobileKey.request') }}
        </button>
        <button type="button" :class="btnOutlineSm('neutral')" @click="close">
          {{ t('common.cancel') }}
        </button>
      </div>
    </div>
  </div>
</template>
