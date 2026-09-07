<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { api } from '@/api/client'
import { useDemoMode } from '@/composables/useDemoMode'
import { ICONS, btnFilled } from '@/components/ui/buttonStyles'

const props = defineProps<{ currencyId: number; canWrite: boolean }>()
const { t } = useI18n()
const route = useRoute()
const { blockDemoMutation } = useDemoMode()
const fields = ref({ api_key: '', client_id: '', client_secret: '' })
const fieldNames = ['api_key', 'client_id', 'client_secret'] as const
const callback = ref('')
const sandbox = ref(false)
const ready = ref(false)
const busy = ref(false)
const error = ref(false)
let disposed = false
const canStart = computed(() => props.canWrite && ready.value && !busy.value && fieldNames.every(key => fields.value[key].trim()))
onMounted(async () => {
  try {
    const { data } = await api.get<{ callback_url: string; server_ready: boolean; environment: 'sandbox' | 'production' }>(`/settings/bank-connections/${props.currencyId}/csas/onboarding`)
    if (disposed) return
    callback.value = data.callback_url
    sandbox.value = data.environment === 'sandbox'
    ready.value = data.server_ready === true
  } catch { if (!disposed) error.value = true }
})
onBeforeUnmount(() => { disposed = true; fields.value = { api_key: '', client_id: '', client_secret: '' } })
async function start() {
  if (!canStart.value || blockDemoMutation()) return
  busy.value = true
  error.value = false
  try {
    const { data } = await api.post<{ redirect_url: string }>(`/settings/bank-connections/${props.currencyId}/csas/onboarding`, { ...fields.value })
    fields.value = { api_key: '', client_id: '', client_secret: '' }
    if (disposed) return
    const url = new URL(data.redirect_url)
    const expectedOrigin = sandbox.value ? 'https://webapi.developers.erstegroup.com' : 'https://bezpecnost.csas.cz'
    const expectedPath = sandbox.value ? '/api/csas/sandbox/v1/sandbox-idp/auth' : '/api/psd2/fl/oidc/v1/auth'
    if (url.origin !== expectedOrigin || url.pathname !== expectedPath || url.username || url.password || url.hash) throw new Error()
    window.location.assign(url.href)
  } catch { if (!disposed) error.value = true }
  finally { if (!disposed) busy.value = false }
}
</script>

<template>
  <div class="space-y-3">
    <div class="rounded-md border border-neutral-200 bg-neutral-50 p-3 text-sm space-y-2">
      <p class="font-medium">{{ t('bank_connection.csas_title') }}</p>
      <p v-if="sandbox" class="font-medium text-warning-700">{{ t('bank_connection.csas_sandbox_label') }}</p>
      <p>{{ t(sandbox ? 'bank_connection.csas_sandbox_hint' : 'bank_connection.csas_hint') }}</p>
      <a href="https://developers.erstegroup.com" target="_blank" rel="noopener noreferrer" class="underline">Erste Developer Portal</a>
      <p>{{ t('bank_connection.csas_read_only') }}</p>
      <p class="text-xs text-neutral-600">{{ t('bank_connection.csas_logging') }}</p>
      <p class="text-xs text-neutral-600">{{ t('bank_connection.credentials_storage_hint') }}</p>
    </div>
    <p v-if="callback" class="text-sm break-all">{{ t('bank_connection.csas_callback') }}: <code>{{ callback }}</code></p>
    <form v-if="canWrite" class="space-y-3" @submit.prevent="start">
      <div class="grid sm:grid-cols-2 gap-3">
        <label v-for="key in fieldNames" :key="key" class="text-sm">{{ t(`bank_connection.csas_${key}`) }}
          <input v-model="fields[key]" type="password" autocomplete="new-password" spellcheck="false" maxlength="2048" :disabled="busy" class="w-full h-9 px-3 mt-1 border border-neutral-300 rounded-md bg-surface" />
        </label>
      </div>
      <button type="submit" :class="btnFilled('primary')" :disabled="!canStart"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.link" /></svg>{{ t('bank_connection.csas_connect') }}</button>
    </form>
    <p v-if="callback && !ready" class="text-sm text-warning-700">{{ t('bank_connection.csas_server') }}</p>
    <p v-if="error || route.query.csas === 'error'" role="alert" class="text-sm text-danger-600">{{ t('bank_connection.csas_error') }}</p>
  </div>
</template>
