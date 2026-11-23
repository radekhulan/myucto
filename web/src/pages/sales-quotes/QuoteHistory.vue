<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { salesQuotesApi, quoteHistoryKinds, type QuoteHistory, type QuoteHistoryKind, type SalesQuote } from '@/api/salesQuotes'
import { apiErrorMessage } from '@/api/errors'
import { useDemoMode } from '@/composables/useDemoMode'
import { formatDateTime } from '@/composables/useFormat'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'

const props = defineProps<{ quote: SalesQuote; canWrite: boolean; disabled: boolean }>()
const emit = defineEmits<{ updated: [quote: SalesQuote]; busy: [value: boolean] }>()
const { t } = useI18n()
const { blockDemoMutation } = useDemoMode()
const kind = ref<QuoteHistoryKind>('note')
const text = ref('')
const loading = ref(false)
const error = ref('')

function detail(entry: QuoteHistory, key: string): string {
  const value = entry.data?.[key]
  if (typeof value === 'string') return value
  if (Array.isArray(value)) return value.filter(item => typeof item === 'string').join(', ')
  return ''
}

function attachments(entry: QuoteHistory): { id: number; name: string }[] {
  if (!Array.isArray(entry.data?.files)) return []
  return entry.data.files.flatMap((file: unknown) => {
    if (!file || typeof file !== 'object') return []
    const candidate = file as { id?: unknown; name?: unknown }
    return typeof candidate.name === 'string' && Number(candidate.id) > 0 ? [{ id: Number(candidate.id), name: candidate.name }] : []
  })
}

async function save() {
  if (!text.value.trim() || loading.value || props.disabled || blockDemoMutation()) return
  loading.value = true
  error.value = ''
  emit('busy', true)
  try {
    emit('updated', await salesQuotesApi.addHistory(props.quote.id, kind.value, text.value.trim()))
    text.value = ''
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
    emit('busy', false)
  }
}
</script>

<template>
  <section class="space-y-3 rounded-lg border border-neutral-200 bg-surface p-3 sm:p-4">
    <h2 class="text-lg font-semibold">{{ t('sales_quotes.history') }}</h2>
    <p v-if="!quote.history.length" class="text-sm text-neutral-500">{{ t('sales_quotes.no_history') }}</p>
    <ol v-else class="divide-y divide-neutral-200 text-sm">
      <li v-for="entry in quote.history" :key="entry.id" class="space-y-1 py-2">
        <div class="flex flex-wrap items-baseline gap-2">
          <strong class="font-medium">{{ t(`sales_quotes.events.${entry.event}`, entry.event) }}</strong>
          <time class="text-xs text-neutral-600">{{ formatDateTime(entry.created_at) }}</time>
          <span v-if="entry.user_name" class="text-xs text-neutral-600">{{ entry.user_name }}</span>
          <span v-if="entry.delivery_status" class="rounded bg-neutral-100 px-2 py-0.5 text-xs">{{ t(`sales_quotes.delivery.${entry.delivery_status}`, entry.delivery_status) }}</span>
        </div>
        <p v-if="detail(entry, 'to')" class="break-all text-neutral-600">{{ t('sales_quotes.email_to') }} {{ detail(entry, 'to') }}</p>
        <p v-if="detail(entry, 'subject')" class="font-medium">{{ detail(entry, 'subject') }}</p>
        <p v-if="detail(entry, 'text') || detail(entry, 'message') || detail(entry, 'note')" class="whitespace-pre-wrap break-words">{{ detail(entry, 'text') || detail(entry, 'message') || detail(entry, 'note') }}</p>
        <p v-if="detail(entry, 'error')" class="break-words text-danger-700">{{ detail(entry, 'error') }}</p>
        <a v-if="Number(entry.has_pdf) > 0" :href="salesQuotesApi.historyPdfUrl(quote.id, entry.id)" target="_blank" rel="noopener" class="inline-flex items-center gap-2 font-medium text-primary-700 hover:underline">
          <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.doc" /></svg>
          {{ t('sales_quotes.sent_pdf') }}
        </a>
        <ul v-if="attachments(entry).length" class="flex flex-wrap gap-2 text-xs">
          <li v-for="file in attachments(entry)" :key="file.id">
            <a :href="salesQuotesApi.historyFileUrl(quote.id, entry.id, file.id)" target="_blank" rel="noopener" class="text-primary-700 hover:underline">{{ file.name }}</a>
          </li>
        </ul>
      </li>
    </ol>

    <form v-if="canWrite" class="space-y-2 border-t border-neutral-200 pt-3" @submit.prevent="save">
      <p v-if="error" role="alert" class="text-sm text-danger-700">{{ error }}</p>
      <div class="flex flex-wrap items-end gap-2">
        <label class="text-sm">
          <span class="mb-1 block font-medium">{{ t('sales_quotes.record_kind') }}</span>
          <select v-model="kind" :disabled="disabled || loading" class="h-9 rounded-md border border-neutral-300 bg-surface px-2">
            <option v-for="value in quoteHistoryKinds" :key="value" :value="value">{{ t(`sales_quotes.events.${value}`) }}</option>
          </select>
        </label>
        <label class="min-w-52 flex-1 text-sm">
          <span class="mb-1 block font-medium">{{ t('sales_quotes.internal_note') }}</span>
          <textarea v-model="text" required maxlength="6000" rows="2" :disabled="disabled || loading"
            class="w-full resize-y rounded-md border border-neutral-300 bg-surface px-2 py-1" />
        </label>
        <button type="submit" :class="[btnOutline('primary'), 'whitespace-nowrap']" :disabled="disabled || loading || !text.trim()">
          <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
          {{ t('sales_quotes.add_record') }}
        </button>
      </div>
    </form>
  </section>
</template>
