<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { apiErrorCode } from '@/api/errors'
import { kbPlusCredentialFields, kbPlusOnboardingApi, type KbPlusCredentials, type KbPlusOnboardingStatus } from '@/api/kbPlusOnboarding'
import { useDemoMode } from '@/composables/useDemoMode'
import { formatDateTime } from '@/composables/useFormat'
import { kbPlusErrorKey, navigateToKbPlus } from '@/utils/kbPlusOnboarding'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const props = defineProps<{ currencyId: number; canWrite: boolean }>()
const emit = defineEmits<{ changed: [] }>()
const { t } = useI18n()
const route = useRoute()
const { blockDemoMutation } = useDemoMode()
const state = ref<KbPlusOnboardingStatus | null>(null)
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const submitted = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)
const certificateName = ref('')
const certificateReading = ref(false)
const fields = ref<KbPlusCredentials>(emptyFields())
const apiKeyFields = kbPlusCredentialFields.filter(field => field.endsWith('_api_key'))
let requestVersion = 0
let certificateVersion = 0
const required = computed(() => state.value?.required_fields ?? [])
const needsCertificate = computed(() => required.value.includes('certificate_p12'))
const pending = computed(() => ['registration_pending', 'authorization_pending'].includes(state.value?.status ?? ''))
const knownRequirements = computed(() => required.value.every(field => kbPlusCredentialFields.includes(field)))
const canStart = computed(() => props.canWrite && !loading.value && !busy.value && !submitted.value && !certificateReading.value
  && state.value?.server_ready === true && state.value.blockers.length === 0 && knownRequirements.value
  && required.value.every(field => field === 'certificate_password' || !!fields.value[field]?.trim()))
const callbackResult = computed(() => String(route.query.currency_id ?? '') === String(props.currencyId)
  && ['connected', 'error'].includes(String(route.query.kb_plus ?? '')) ? String(route.query.kb_plus) : '')
const callbackError = computed(() => callbackResult.value === 'error' ? t(kbPlusErrorKey(String(route.query.code ?? ''))) : '')
const statusKey = computed(() => {
  const status = state.value?.status ?? 'not_registered'
  return `kb_plus.status_${['not_registered', 'registered', 'registration_pending', 'authorization_pending', 'connected', 'expired'].includes(status) ? status : 'not_registered'}`
})

function emptyFields(): KbPlusCredentials {
  return { client_registration_api_key: '', oauth_api_key: '', adaa_api_key: '', batchda_api_key: '', certificate_p12: '', certificate_password: '' }
}
function clearCredentials() {
  certificateVersion++
  fields.value = emptyFields()
  certificateName.value = ''
  certificateReading.value = false
  if (fileInput.value) fileInput.value.value = ''
}
async function load(notify = false) {
  const version = ++requestVersion
  loading.value = true
  error.value = ''
  try {
    const value = await kbPlusOnboardingApi.status(props.currencyId)
    if (version !== requestVersion) return
    if (value.provider !== 'kb_plus' || typeof value.server_ready !== 'boolean'
      || !['not_registered', 'registered', 'registration_pending', 'authorization_pending', 'connected', 'expired'].includes(value.status)
      || !Array.isArray(value.required_fields) || !value.required_fields.every(field => typeof field === 'string')
      || !Array.isArray(value.blockers) || !value.blockers.every(code => typeof code === 'string')) throw new Error()
    state.value = value
    if (notify) emit('changed')
  } catch (e) {
    if (version === requestVersion) error.value = t(kbPlusErrorKey(apiErrorCode(e)))
  } finally {
    if (version === requestVersion) loading.value = false
  }
}
async function readCertificate(event: Event) {
  const version = ++certificateVersion
  fields.value.certificate_p12 = ''
  certificateName.value = ''
  certificateReading.value = false
  error.value = ''
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return
  if (file.size === 0 || file.size > 24576) {
    error.value = t('kb_plus.error_certificate')
    return
  }
  certificateReading.value = true
  try {
    const bytes = await file.arrayBuffer()
    if (version !== certificateVersion) return
    fields.value.certificate_p12 = btoa(String.fromCharCode(...new Uint8Array(bytes)))
    certificateName.value = file.name
  } catch {
    if (version === certificateVersion) error.value = t('kb_plus.error_certificate')
  } finally {
    if (version === certificateVersion) certificateReading.value = false
  }
}
async function start() {
  if (!canStart.value || blockDemoMutation()) return
  if ((pending.value || state.value?.status === 'connected') && !window.confirm(t('kb_plus.restart_confirm'))) return
  busy.value = true
  error.value = ''
  const version = requestVersion
  const credentials: Partial<KbPlusCredentials> = {}
  for (const field of required.value) credentials[field] = field === 'certificate_password' ? fields.value[field] : fields.value[field].trim()
  try {
    const result = await kbPlusOnboardingApi.start(props.currencyId, credentials)
    if (version !== requestVersion || !props.canWrite) return
    clearCredentials()
    submitted.value = true
    if (!navigateToKbPlus(result.redirect_url)) error.value = t('kb_plus.error_redirect')
  } catch (e) {
    if (version === requestVersion) error.value = t(kbPlusErrorKey(apiErrorCode(e)))
  } finally {
    if (version === requestVersion) {
      clearCredentials()
      busy.value = false
    }
  }
}
watch(() => props.currencyId, () => {
  clearCredentials()
  state.value = null
  busy.value = false
  submitted.value = false
  void load()
}, { immediate: true })
watch(() => props.canWrite, value => { if (!value) clearCredentials() })
onBeforeUnmount(() => { requestVersion++; clearCredentials() })
</script>

<template>
  <section class="space-y-3" :aria-label="t('kb_plus.title')">
    <div class="rounded-md border border-neutral-200 bg-neutral-50 p-3 text-sm space-y-2">
      <p class="font-medium">{{ t('kb_plus.prerequisites_title') }}</p>
      <p>{{ t('kb_plus.intro') }}</p>
      <p>{{ t('kb_plus.prerequisite_bank') }}</p>
      <p>{{ t('kb_plus.prerequisite_plan') }}</p>
      <p>{{ t('kb_plus.prerequisite_activation') }}</p>
      <div class="flex flex-wrap gap-3">
        <a href="https://www.kb.cz/cs/kbapi/extra-sluzba-api-business" target="_blank" rel="noopener noreferrer" class="underline">{{ t('kb_plus.official_service') }}</a>
        <a href="https://www.kb.cz/cs/kbapi/caste-dotazy-rozcestnik/caste-dotazy-extra-sluzba-api-business" target="_blank" rel="noopener noreferrer" class="underline">{{ t('kb_plus.official_faq') }}</a>
      </div>
      <p>{{ t('kb_plus.prerequisite_subscriptions') }}</p>
      <p>{{ t('kb_plus.prerequisite_certificate') }}</p>
      <p class="text-xs text-neutral-600">{{ t('kb_plus.prerequisite_server') }}</p>
      <p class="text-xs text-neutral-600">{{ t('kb_plus.authorization_hint') }}</p>
    </div>
    <p role="note" class="rounded-md border border-warning-500/40 bg-warning-50 px-3 py-2 text-sm text-warning-700">{{ t('kb_plus.logging_warning') }}</p>
    <p v-if="loading" class="text-sm text-neutral-500" role="status">{{ t('common.loading') }}</p>
    <div v-if="state" class="space-y-2">
      <p class="text-sm font-medium" :class="state.status === 'connected' ? 'text-success-600' : 'text-neutral-700'" role="status">{{ t(statusKey) }}</p>
      <p v-if="state.expires_at && pending" class="text-xs text-neutral-500">{{ t('kb_plus.expires', { date: formatDateTime(state.expires_at) }) }}</p>
      <p v-if="callbackResult === 'connected' && state.status === 'connected'" class="text-sm text-success-600">{{ t('kb_plus.connected') }}</p>
      <p v-if="callbackError" class="text-sm text-danger-600" role="alert">{{ callbackError }}</p>
      <div v-if="!state.server_ready || state.blockers.length" class="rounded-md border border-warning-500/40 bg-warning-50 p-3 text-sm space-y-1" role="alert">
        <p class="font-medium">{{ t('kb_plus.server_not_ready') }}</p>
        <p v-for="(blocker, index) in state.blockers" :key="index">{{ t(kbPlusErrorKey(blocker)) }}</p>
      </div>
      <p v-if="!knownRequirements" class="text-sm text-danger-600" role="alert">{{ t('kb_plus.error_generic') }}</p>
      <p v-if="pending" class="text-sm text-warning-700">{{ t('kb_plus.pending_hint') }}</p>
      <form v-if="canWrite && state.server_ready && !state.blockers.length && knownRequirements && !submitted" class="space-y-3" @submit.prevent="start">
        <div v-if="required.length" class="grid sm:grid-cols-2 gap-3">
          <label v-for="field in apiKeyFields.filter(value => required.includes(value))" :key="field" class="text-sm">
            {{ t(`kb_plus.field_${field}`) }}
            <input v-model="fields[field]" :name="field" type="password" autocomplete="new-password" spellcheck="false" autocapitalize="none" maxlength="4096" class="w-full h-9 px-3 mt-1 border border-neutral-300 rounded-md bg-surface" :disabled="busy" />
          </label>
          <div v-if="needsCertificate" class="text-sm min-w-0">
            <p>{{ t('kb_plus.field_certificate_p12') }}</p>
            <input ref="fileInput" type="file" accept=".p12,.pfx" class="hidden" tabindex="-1" :aria-label="t('kb_plus.field_certificate_p12')" :disabled="busy" @change="readCertificate" />
            <div class="flex flex-wrap items-center gap-2 mt-1">
              <button type="button" :class="btnOutline('neutral')" :disabled="busy" @click="fileInput?.click()">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.upload" /></svg>
                {{ t('kb_plus.choose_certificate') }}
              </button>
              <span class="text-neutral-600 break-all">{{ certificateName || t('kb_plus.no_certificate') }}</span>
            </div>
          </div>
          <label v-if="required.includes('certificate_password')" class="text-sm">{{ t('kb_plus.field_certificate_password') }}
            <input v-model="fields.certificate_password" name="certificate_password" type="password" autocomplete="new-password" maxlength="1024" class="w-full h-9 px-3 mt-1 border border-neutral-300 rounded-md bg-surface" :disabled="busy" />
          </label>
          <p class="text-xs text-neutral-500 sm:col-span-2">{{ t('kb_plus.credentials_hint') }}</p>
        </div>
        <p v-else class="text-sm text-neutral-600">{{ t('kb_plus.existing_client_hint') }}</p>
        <button type="submit" :class="btnFilled('primary')" :disabled="!canStart">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.link" /></svg>
          {{ busy ? t('common.loading') : t(pending || state.status === 'connected' ? 'kb_plus.restart' : 'kb_plus.start') }}
        </button>
      </form>
    </div>
    <p v-if="submitted && !error" class="text-sm text-neutral-600" role="status">{{ t('kb_plus.redirecting') }}</p>
    <p v-if="error" class="text-sm text-danger-600" role="alert">{{ error }}</p>
    <div class="flex flex-wrap gap-2">
      <button type="button" :class="btnOutline('neutral')" :disabled="busy || loading" @click="load(true)">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>
        {{ t('kb_plus.refresh') }}
      </button>
    </div>
  </section>
</template>
