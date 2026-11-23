<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { salesQuotesApi, quoteFileModes, type QuoteFileMode, type SalesQuote } from '@/api/salesQuotes'
import { apiErrorMessage } from '@/api/errors'
import { useDemoMode } from '@/composables/useDemoMode'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'

const props = defineProps<{ quote: SalesQuote; editable: boolean; dirty: boolean; disabled: boolean }>()
const emit = defineEmits<{ updated: [quote: SalesQuote]; busy: [value: boolean] }>()
const { t } = useI18n()
const { blockDemoMutation } = useDemoMode()
const mode = ref<QuoteFileMode>('append')
const input = ref<HTMLInputElement | null>(null)
const loading = ref(false)
const error = ref('')
const blockActions = computed(() => props.dirty || props.disabled || loading.value)
const accept = computed(() => {
  if (mode.value === 'inline') return 'image/png,image/jpeg,image/gif'
  if (mode.value === 'append') return 'application/pdf'
  return undefined
})

async function upload() {
  const files = Array.from(input.value?.files ?? [])
  if (files.length === 0 || blockActions.value || blockDemoMutation()) return
  loading.value = true
  error.value = ''
  emit('busy', true)
  try {
    for (const file of files) emit('updated', await salesQuotesApi.upload(props.quote.id, file, mode.value))
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    if (input.value) input.value.value = ''
    loading.value = false
    emit('busy', false)
  }
}

async function remove(id: number) {
  if (blockActions.value || blockDemoMutation()) return
  loading.value = true
  error.value = ''
  emit('busy', true)
  try {
    emit('updated', await salesQuotesApi.removeFile(props.quote.id, id))
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
    emit('busy', false)
  }
}
</script>

<template>
  <section class="space-y-2 rounded-lg border border-neutral-200 bg-surface p-3 sm:p-4">
    <h2 class="text-lg font-semibold">{{ t('sales_quotes.files') }}</h2>
    <p v-if="error" role="alert" class="rounded bg-danger-50 p-2 text-sm text-danger-700">{{ error }}</p>
    <p v-if="!quote.files.length && !editable" class="text-sm text-neutral-500">{{ t('sales_quotes.no_files') }}</p>
    <ul v-if="quote.files.length" class="divide-y divide-neutral-100 text-sm">
      <li v-for="file in quote.files" :key="file.id" class="flex flex-wrap items-center gap-2 py-2">
        <a :href="salesQuotesApi.fileUrl(quote.id, file.id, quote.version_id, file.mode !== 'inline')" target="_blank" rel="noopener"
          class="inline-flex min-w-0 items-center gap-2 font-medium text-primary-700 hover:underline">
          <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.doc" /></svg>
          <span class="break-all">{{ file.name }}</span>
        </a>
        <span class="mr-auto rounded bg-neutral-100 px-2 py-0.5 text-xs text-neutral-700">{{ t(`sales_quotes.file_modes.${file.mode}`) }}</span>
        <button v-if="editable" type="button" :class="btnOutlineSm('danger')" :disabled="blockActions" :aria-label="t('sales_quotes.remove_file', { name: file.name })"
          :title="t('sales_quotes.remove_file', { name: file.name })" @click="remove(file.id)">
          <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>
        </button>
      </li>
    </ul>

    <div v-if="editable" class="flex flex-wrap items-end gap-2">
      <label class="text-sm">
        <span class="mb-1 block font-medium">{{ t('sales_quotes.file_mode') }}</span>
        <select v-model="mode" :disabled="blockActions" class="h-9 rounded-md border border-neutral-300 bg-surface px-2">
          <option v-for="value in quoteFileModes" :key="value" :value="value">{{ t(`sales_quotes.file_modes.${value}`) }}</option>
        </select>
      </label>
      <input ref="input" type="file" multiple :accept="accept" class="hidden" :disabled="blockActions" @change="upload" />
      <button type="button" :class="[btnOutlineSm('primary'), 'whitespace-nowrap']" :disabled="blockActions" @click="input?.click()">
        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.upload" /></svg>
        {{ t('sales_quotes.upload') }}
      </button>
    </div>
    <p v-if="editable && dirty" class="text-sm text-neutral-600">{{ t('sales_quotes.save_before_files') }}</p>
  </section>
</template>
