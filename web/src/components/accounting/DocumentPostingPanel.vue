<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { formatDate } from '@/composables/useFormat'
import { accountingApi, type JournalDocumentSource, type JournalEntryWithLines } from '@/api/accounting'
import JournalLinesTable from '@/components/accounting/JournalLinesTable.vue'
import JournalRelatedPanel from '@/components/accounting/JournalRelatedPanel.vue'
import { journalEntryLink } from '@/utils/journalSourceLink'

/**
 * Sbalená sekce „Zaúčtování" na detailu faktury — kontace dokladu tak, jak ji
 * ukazuje rozbalený řádek deníku (tytéž komponenty, ať to účetní pozná jako
 * jednu a tutéž věc).
 *
 * Detail dosud uměl jen odskok do deníku; kvůli kontrole „na co to spadlo"
 * se muselo opustit doklad. Sekce se načítá na pozadí a zobrazí se jen tehdy,
 * když zaúčtování existuje — nezaúčtovaný doklad ani firma v daňové evidenci
 * o prázdnou lištu nezakopne. Default je sbalený stav: hlavní obsah detailu
 * jsou pořád položky.
 */
const props = withDefaults(defineProps<{
  source: JournalDocumentSource
  docId: number
  alwaysVisible?: boolean
}>(), {
  alwaysVisible: false,
})

const { t } = useI18n()

const entries = ref<JournalEntryWithLines[]>([])
const open = ref(false)
const failed = ref(false)

/** Sekce se běžně ukazuje jen když je co zaúčtovaného ukázat. Volitelný obsah
 * ji může zpřístupnit i bez zápisu, například pro klasifikaci přijaté faktury. */
const visible = computed(() => props.alwaysVisible || entries.value.length > 0)

async function load(docId: number): Promise<void> {
  entries.value = []
  failed.value = false
  if (!(docId > 0)) return
  try {
    entries.value = await accountingApi.journalForDocument(props.source, docId)
  } catch {
    // Doplňková informace — když se nenačte, nesmí shodit detail dokladu.
    failed.value = true
  }
}

watch(() => props.docId, id => { void load(id) }, { immediate: true })

/** Storno (protizápis) — účetní ho musí poznat na první pohled, ne až podle částek. */
function isReversal(entry: JournalEntryWithLines): boolean {
  return entries.value.some(e => e.reversed_by === entry.id)
}
</script>

<template>
  <div v-if="visible" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
    <button type="button" @click="open = !open"
      class="w-full px-5 py-3 flex items-center justify-between text-left hover:bg-neutral-50 cursor-pointer"
      :class="open ? 'border-b border-neutral-200' : ''">
      <span class="flex items-center gap-2">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('accounting.journal.document_posting.title') }}</h3>
        <span v-if="entries.length > 1" class="text-xs text-neutral-400">{{ entries.length }}</span>
      </span>
      <svg class="w-4 h-4 text-neutral-400 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
      </svg>
    </button>
    <div v-show="open" class="px-5 py-4 space-y-5">
      <slot />
      <div v-for="entry in entries" :key="entry.id">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
          <span class="flex items-center gap-2 text-xs text-neutral-500">
            <span class="font-medium text-neutral-700">{{ entry.document_no || `#${entry.id}` }}</span>
            <span>{{ formatDate(entry.entry_date) }}</span>
            <span v-if="isReversal(entry)" class="px-1.5 py-0.5 rounded bg-danger-50 text-danger-500 font-medium">
              {{ t('accounting.journal.document_posting.reversal') }}
            </span>
            <span v-if="entry.description" class="text-neutral-400 truncate max-w-[24rem]">{{ entry.description }}</span>
          </span>
          <RouterLink :to="journalEntryLink(entry.id)"
            class="text-xs text-primary-600 hover:text-primary-700 hover:underline whitespace-nowrap">
            {{ t('accounting.journal.document_posting.open_in_journal') }}
          </RouterLink>
        </div>
        <JournalLinesTable :lines="entry.lines" :context-date="entry.entry_date" />
        <!-- Souvisí: protějšky v grafu doklad ↔ úhrada. Panel si data tahá sám
             podle entry-id a když nic nenajde, nevykreslí se. -->
        <JournalRelatedPanel class="mt-3 block" :entry-id="entry.id" />
      </div>
    </div>
  </div>
</template>
