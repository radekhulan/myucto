<script setup lang="ts">
/**
 * Inline odeslání jednoho podání z fronty Mobilním klíčem.
 *
 * Stejný start/confirm/poll cyklus jako v `DataBox.vue` (obecná fronta), jen
 * jako samostatná komponenta — aby "tlačítko rovnou tam" nemusela mzdová
 * obrazovka (JMHZ, PPZ, HOZ) znovu psát a nerozešla se s ním, až se jednou
 * změní. Překlady jsou schválně `databox.outbox.mobileKey.*`: je to POŘÁD
 * tentýž úkon (odeslání z obecné fronty), jen spuštěný odsud.
 */
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import { dataBoxApi, type IsdsMobileCredentialProfile } from '@/api/dataBox'
import { btnFilledSm, btnOutlineSm } from '@/components/ui/buttonStyles'

const props = defineProps<{
  outboxId: number
  environment: 'production' | 'test'
}>()
const emit = defineEmits<{ sent: [dispatched: boolean] }>()
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
    // Bez uloženého profilu se prostě zadá jméno a kód ručně.
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
    const started = await dataBoxApi.startMobileKeyOutbox(
      props.outboxId,
      props.environment,
      wantsSaved ? '' : username.value.trim(),
      wantsSaved ? '' : code.value,
      wantsSaved,
    )
    flowToken.value = started.flow_token
    status.value = started.description
    // Kód se v paměti nedrží déle, než je potřeba k jeho odeslání.
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
    const result = await dataBoxApi.mobileKeyOutboxConfirm(
      props.outboxId,
      flowToken.value,
      props.environment,
    )
    status.value = result.description
    if (result.result) {
      const dispatched = result.result.dispatched
      close()
      emit('sent', dispatched)
      return
    }
    timer = setTimeout(() => { void poll() }, 2000)
  } catch (exception) {
    // Vypršelá nebo zamítnutá relace se NEOBNOVUJE sama — nová by nebyla ta,
    // kterou člověk schválil. Musí potvrdit znovu vědomě.
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
      data-test="mobile-key-send-action"
      @click="openPanel"
    >
      {{ t('databox.outbox.mobileKey.action') }}
    </button>
    <div
      v-else
      class="mt-2 rounded-lg border border-primary-200 bg-primary-50/40 p-3"
      data-test="mobile-key-send-form"
    >
      <p class="text-sm text-neutral-700">{{ t('databox.outbox.mobileKey.intro') }}</p>
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
      <p v-if="status" class="mt-3 text-sm text-neutral-700" data-test="mobile-key-send-status">
        {{ status }}
      </p>
      <p v-if="error" class="mt-3 text-sm text-danger-700" role="alert" data-test="mobile-key-send-error">
        {{ error }}
      </p>
      <div class="mt-3 flex flex-wrap gap-2">
        <button
          v-if="flowToken === ''"
          type="button"
          :class="btnFilledSm('primary')"
          :disabled="busy"
          data-test="mobile-key-send-request"
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
