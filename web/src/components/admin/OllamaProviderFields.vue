<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { integrationsApi, type OllamaModel, type AiCredentialsPayload } from '@/api/integrations'
import { apiErrorMessage } from '@/api/errors'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'

const props = defineProps<{ hasApiKey: boolean }>()
const baseUrl = defineModel<string>('baseUrl', { required: true })
const model = defineModel<string>('model', { required: true })
const apiKey = defineModel<string>('apiKey', { required: true })
const clearApiKey = defineModel<boolean>('clearApiKey', { default: false })

const { t } = useI18n()
const models = ref<OllamaModel[] | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const showKey = ref(false)
const uid = useId()
let requestSeq = 0

const selected = computed(() => models.value?.find(m => m.name === model.value) ?? null)
const savedModelMissing = computed(() => models.value !== null && model.value !== '' && selected.value === null)

async function loadModels() {
  const url = baseUrl.value.trim()
  if (!url) return
  const seq = ++requestSeq
  loading.value = true
  error.value = null
  try {
    const key = apiKey.value.trim()
    const payload: Pick<AiCredentialsPayload, 'api_key' | 'base_url' | 'clear_api_key'> = { base_url: url }
    if (clearApiKey.value) payload.clear_api_key = true
    else if (key) payload.api_key = key
    const res = await integrationsApi.listOllamaModels(payload)
    if (seq !== requestSeq || url !== baseUrl.value.trim()) return
    models.value = res.models
  } catch (e) {
    if (seq !== requestSeq || url !== baseUrl.value.trim()) return
    models.value = null
    error.value = apiErrorMessage(e)
  } finally {
    if (seq === requestSeq) loading.value = false
  }
}

// Změna adresy po úvodním načtení: seznam i vybraný model patřily staré adrese.
watch([baseUrl, apiKey, clearApiKey], ([url], [previousUrl]) => {
  requestSeq++
  loading.value = false
  models.value = null
  error.value = null
  if (url !== previousUrl) model.value = ''
})
</script>

<template>
  <div class="space-y-3">
    <div>
      <label :for="`${uid}-url`" class="block text-sm text-neutral-700 mb-1">{{ t('aiGateway.ollama_base_url') }} *</label>
      <div class="flex flex-wrap gap-2">
        <input :id="`${uid}-url`" v-model="baseUrl" type="text" maxlength="255" autocomplete="off"
               class="flex-1 min-w-0 h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono"
               placeholder="http://localhost:11434" @keydown.enter.prevent="loadModels" />
        <button type="button" :class="[btnOutline('primary'), 'whitespace-nowrap']"
                :disabled="loading || !baseUrl.trim()" data-test="ollama-load-models" @click="loadModels">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
          {{ loading ? t('aiGateway.ollama_loading_models') : t('aiGateway.ollama_load_models') }}
        </button>
      </div>
      <p class="text-xs text-neutral-500 mt-1">{{ t('aiGateway.ollama_base_url_hint') }}</p>
      <p v-if="error" class="text-xs text-danger-500 mt-1">{{ error }}</p>
    </div>

    <div>
      <label :for="`${uid}-model`" class="block text-sm text-neutral-700 mb-1">{{ t('aiGateway.model') }} *</label>
      <select v-if="models?.length" :id="`${uid}-model`" v-model="model" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm" data-test="ollama-model">
        <option value="" disabled>{{ t('aiGateway.ollama_model_placeholder') }}</option>
        <option v-for="m in models" :key="m.name" :value="m.name">
          {{ m.name }}{{ m.vision ? ` · ${t('aiGateway.ollama_vision_badge')}` : '' }}
        </option>
      </select>
      <input v-else :id="`${uid}-model`" v-model="model" type="text" maxlength="128" autocomplete="off"
             class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
      <p v-if="models?.length === 0" class="text-xs text-warning-600">{{ t('aiGateway.ollama_models_empty') }}</p>
      <p v-if="savedModelMissing" class="text-xs text-danger-500 mt-1">{{ t('aiGateway.ollama_model_missing', { model }) }}</p>
      <p v-else-if="selected && selected.vision === false" class="text-xs text-warning-600 mt-1">{{ t('aiGateway.ollama_no_vision_warning') }}</p>
    </div>

    <div>
      <label :for="`${uid}-key`" class="block text-sm text-neutral-700 mb-1">{{ t('aiGateway.ollama_api_key') }}</label>
      <div class="flex flex-wrap gap-2">
        <input :id="`${uid}-key`" v-model="apiKey" :type="showKey ? 'text' : 'password'" maxlength="512" autocomplete="new-password"
               class="flex-1 min-w-0 h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono"
               :placeholder="props.hasApiKey ? t('aiGateway.ollama_api_key_saved') : ''" />
        <button type="button" class="cursor-pointer h-10 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 text-sm"
                :aria-label="showKey ? t('aiGateway.ollama_hide_key') : t('aiGateway.ollama_show_key')"
                @click="showKey = !showKey">{{ showKey ? '🙈' : '👁' }}</button>
      </div>
      <p class="text-xs text-neutral-500 mt-1">{{ t('aiGateway.ollama_api_key_hint') }}</p>
      <label v-if="props.hasApiKey" class="mt-1 flex items-center gap-2 text-xs text-neutral-600">
        <input v-model="clearApiKey" type="checkbox" class="rounded border-neutral-300" />
        {{ t('aiGateway.ollama_clear_api_key') }}
      </label>
    </div>
  </div>
</template>
