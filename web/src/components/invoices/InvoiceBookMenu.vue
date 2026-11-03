<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import { useToast } from '@/composables/useToast'
import { downloadApiFile } from '@/utils/downloadFile'

type BookFormat = 'pdf' | 'xlsx'

const props = defineProps<{
  label: string
  /** URL knihy pro aktuální filtr seznamu. */
  urlFor: (format: BookFormat) => string
  fallbackFilename: string
}>()

const { t } = useI18n()
const toast = useToast()
const open = ref(false)
const busy = ref<BookFormat | null>(null)

async function download(format: BookFormat) {
  open.value = false
  busy.value = format
  try {
    await downloadApiFile(props.urlFor(format), `${props.fallbackFilename}.${format}`)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('invoice.book_failed'))
  } finally {
    busy.value = null
  }
}
</script>

<template>
  <div class="relative">
    <button
      type="button"
      data-test="invoice-book-toggle"
      :class="[btnOutline('neutral'), 'whitespace-nowrap']"
      aria-haspopup="menu"
      :aria-expanded="open"
      :aria-busy="busy !== null"
      :disabled="busy !== null"
      :title="t('invoice.book_hint')"
      @click="open = !open"
    >
      <svg v-if="busy !== null" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z" /></svg>
      <svg v-else class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.download" /></svg>
      {{ label }}
      <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.chevron" /></svg>
    </button>
    <template v-if="open">
      <div class="fixed inset-0 z-[60]" aria-hidden="true" @click="open = false" />
      <div
        role="menu"
        data-test="invoice-book-menu"
        class="absolute right-0 z-[61] mt-1 w-64 max-w-[calc(100vw-2rem)] rounded-lg border border-neutral-200 bg-surface py-1 text-sm shadow-xl"
        @keydown.esc="open = false"
      >
        <p class="border-b border-neutral-100 px-3 py-2 text-xs text-neutral-500">{{ t('invoice.book_scope') }}</p>
        <button type="button" role="menuitem" data-test="invoice-book-pdf" class="flex w-full cursor-pointer items-center gap-2.5 px-3 py-2 text-left text-neutral-700 hover:bg-neutral-50" @click="download('pdf')">
          <svg class="h-4 w-4 shrink-0 text-neutral-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.download" /></svg>
          {{ t('invoice.book_pdf') }}
        </button>
        <button type="button" role="menuitem" data-test="invoice-book-xlsx" class="flex w-full cursor-pointer items-center gap-2.5 px-3 py-2 text-left text-neutral-700 hover:bg-neutral-50" @click="download('xlsx')">
          <svg class="h-4 w-4 shrink-0 text-neutral-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.table" /></svg>
          {{ t('invoice.book_xlsx') }}
        </button>
      </div>
    </template>
  </div>
</template>
