<script setup lang="ts">
import { ref, computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { formatDate } from '@/composables/useFormat'
import type { JournalEntryDetail } from '@/api/accounting'
import type { DimensionSplits } from '@/api/dimensions'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import JournalLinesTable from './JournalLinesTable.vue'
import JournalRelatedPanel from './JournalRelatedPanel.vue'
import JournalEntryExtras from './JournalEntryExtras.vue'
import WhyPanel from '@/components/automation/WhyPanel.vue'
import LinkedDocumentsPanel from '@/components/documents/LinkedDocumentsPanel.vue'
import CollapsibleSection from '@/components/ui/CollapsibleSection.vue'
import JournalLineDimensionsEditor from '@/components/dimensions/JournalLineDimensionsEditor.vue'
import OtherItemSourceContext from './OtherItemSourceContext.vue'
import InternalDocumentJournalContext from './InternalDocumentJournalContext.vue'
import RepostModal from './RepostModal.vue'
import WorkspaceTeleport from '@/components/workspace/WorkspaceTeleport.vue'
import type { JournalPostingSource } from '@/api/accounting'
import type { EntityType } from '@/api/documents'

/**
 * Obsah rozbaleného zápisu deníku (rozpad na účty, Souvisí, přílohy, akce).
 *
 * Vytažené ze stránky deníku, protože týž detail ukazuje desktopový akordeon
 * v buňce tabulky i mobilní karta. Dvě kopie šedesáti řádků markupu by se
 * rozešly hned při první změně.
 */
const props = defineProps<{
  detail: JournalEntryDetail
  /** Verze vazeb — bump překreslí panel „Souvisí" po přidání/zrušení vazby. */
  relatedKey: number
  canWrite: boolean
  canDelete: boolean
  /** Lze smazat celou storno dvojici (zápis i jeho protizápis) — otevřené období. */
  canDeletePair: boolean
  dateFrom: string
  dateTo: string
}>()

const emit = defineEmits<{
  preview: [entryId: number]
  'focus-entry': [entryId: number]
  'description-updated': [entryId: number, description: string, rowVersion: number]
  'links-changed': [entryId: number]
  reverse: [entry: JournalEntryDetail]
  remove: [entry: JournalEntryDetail]
  'remove-pair': [entry: JournalEntryDetail]
  'open-reversal': [entryId: number]
  reposted: [entryId: number]
}>()

const { t } = useI18n()

// Přeúčtovat jde i z deníku, ne jen z detailu dokladu: účetní opravu kontace hledá
// u zápisu, a bez tohohle tlačítka jí deník nabízel jen smazání. Jen živý zápis
// dokladu nebo bankovního pohybu, plán i provedení rozhoduje server.
const REPOST_SOURCES: Record<string, JournalPostingSource> = {
  invoice: 'invoices',
  purchase_invoice: 'purchase-invoices',
  bank: 'bank-transactions',
}
const repostSource = computed(() => REPOST_SOURCES[props.detail.source_type] ?? null)
const canRepost = computed(() => props.canWrite && repostSource.value !== null && !!props.detail.source_id
  && !props.detail.reversed_by && !props.detail.reverses_entry_id)
const repostOpen = ref(false)

// Přílohy, poznámky, vazby, dokumenty: u drtivé většiny zápisů je pod hlavní
// věcí prázdno, takže celá ta část stojí sbalená pod jedním řádkem. Počty
// hlásí potomci, jakmile si data dotáhnou — sekce se pak otevře sama.
const extrasCount = ref(0)
const documentCount = ref(0)
const sourceDocumentCount = ref(0)
const extrasTotal = computed(() => extrasCount.value + documentCount.value + sourceDocumentCount.value)

// Dokument připojený k dokladu nebo pohybu (účtenka k platbě kartou) patří i k jeho
// zápisu. Deník ho ukáže jen ke čtení, připojuje a odpojuje se u zdroje.
const SOURCE_ENTITY_TYPES: Record<string, EntityType> = {
  invoice: 'invoice',
  purchase_invoice: 'purchase_invoice',
  bank: 'bank_transaction',
  cash: 'cash_document',
}
const sourceEntityType = computed(() => props.detail.source_id ? SOURCE_ENTITY_TYPES[props.detail.source_type] ?? null : null)

// Uložené dimenze řádků se propíšou do načteného detailu, ať štítky v rozpadu sedí.
function onLineDimensionsSaved(byLine: Record<number, Record<number, number>>, splits: Record<number, DimensionSplits> = {}) {
  for (const line of props.detail.lines) {
    line.dimensions = { ...(byLine[line.id] ?? {}) }
    line.dimension_splits = { ...(splits[line.id] ?? {}) }
  }
}
</script>

<template>
  <div>
    <!-- Rozpad na účty — sdílená karta, tutéž ukazuje panel Souvisí
         u protějšku, aby je účetní poznal jako stejnou věc. -->
    <JournalLinesTable class="mb-3" :lines="detail.lines" :date-from="dateFrom" :date-to="dateTo" />
    <JournalLineDimensionsEditor v-if="canWrite" class="mb-3" :entry-id="detail.id" :lines="detail.lines" @saved="onLineDimensionsSaved" />
    <!-- Souvisí hned za kontacemi: protějšek zápisu (doklad ↔ úhrada)
         je to první, co účetní po rozpadu na účty hledá. -->
    <JournalRelatedPanel class="mt-3 block"
      :key="`related-${detail.id}-${relatedKey}`"
      :entry-id="detail.id" show-preview
      @preview="id => emit('preview', id)" @focus-entry="id => emit('focus-entry', id)" />
    <OtherItemSourceContext v-if="detail.source_type === 'other_item' && detail.source_id"
      :key="detail.id" :entry-id="detail.id" />
    <InternalDocumentJournalContext :key="`internal-document-${detail.id}`" :detail="detail" />
    <WhyPanel v-if="detail.automation" class="mt-3" :provenance="detail.automation" />
    <!-- Epic F7: inline editace description (§35) + přílohy §33a -->
    <CollapsibleSection class="mt-4" :title="t('accounting.journal.extras_title')"
      :icon="ICONS.doc" :count="extrasTotal">
      <JournalEntryExtras :entry="detail"
        @description-updated="(desc, rv) => emit('description-updated', detail.id, desc, rv)"
        @links-changed="emit('links-changed', detail.id)"
        @count="n => extrasCount = n" />
      <LinkedDocumentsPanel entity-type="journal_entry" :entity-id="detail.id" collapsible
        @count="n => documentCount = n" />
      <LinkedDocumentsPanel v-if="sourceEntityType" :key="`source-docs-${detail.id}`"
        :entity-type="sourceEntityType" :entity-id="Number(detail.source_id)" collapsible readonly
        :title="t('accounting.journal.source_documents')" @count="n => sourceDocumentCount = n" />
    </CollapsibleSection>
    <div class="flex flex-wrap items-center justify-between gap-3 mt-4 pt-3 border-t border-neutral-200">
      <div class="text-xs text-neutral-500">
        <span v-if="detail.created_at">{{ t('accounting.journal.created_at') }}: {{ formatDate(detail.created_at) }}</span>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <RouterLink v-if="canWrite" :to="{ path: '/accounting/journal/new', query: { copy_from: String(detail.id) } }" :class="btnOutline('neutral')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
          <span class="whitespace-nowrap">{{ t('accounting.journal.copy_as_new') }}</span>
        </RouterLink>
        <button v-if="canRepost" type="button" @click="repostOpen = true" :class="btnOutline('warning')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
          <span class="whitespace-nowrap">{{ t('accounting.repost.action') }}</span>
        </button>
        <!-- Zúčtování DPH se nestornuje, přepočítává se a maže na místě (VatClearingService).
             Zápis interního dokladu se stornuje v detailu dokladu. -->
        <button v-if="canWrite && !detail.reversed_by && detail.source_type !== 'vat_clearing' && !detail.internal_document" @click="emit('reverse', detail)" :class="btnOutline('danger')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
          {{ t('accounting.journal.reverse') }}
        </button>
        <button v-if="canWrite && canDelete" @click="emit('remove', detail)" :class="btnOutline('danger')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
          {{ t('accounting.journal.delete') }}
        </button>
        <button v-if="canWrite && canDeletePair && detail.reversed_by" @click="emit('remove-pair', detail)" :class="btnOutline('danger')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
          <span class="whitespace-nowrap">{{ t('accounting.journal.delete_pair') }}</span>
        </button>
        <button v-if="detail.reversed_by" type="button" @click="emit('open-reversal', detail.reversed_by)"
          class="cursor-pointer text-xs text-primary-600 hover:text-primary-700 hover:underline">
          {{ t('accounting.journal.reversal_entry') }} #{{ detail.reversed_by }}
        </button>
        <button v-if="detail.reverses_entry_id" type="button" @click="emit('open-reversal', detail.reverses_entry_id)"
          class="cursor-pointer text-xs text-primary-600 hover:text-primary-700 hover:underline">
          {{ t('accounting.journal.reversed_entry') }} #{{ detail.reverses_entry_id }}
        </button>
      </div>
    </div>
    <WorkspaceTeleport>
      <RepostModal v-if="repostOpen && repostSource" :open="repostOpen" :source="repostSource"
        :doc-id="Number(detail.source_id)" :doc-label="detail.document_no || null"
        @close="repostOpen = false"
        @reposted="repostOpen = false; emit('reposted', detail.id)"
        @dimensions-saved="emit('reposted', detail.id)" />
    </WorkspaceTeleport>
  </div>
</template>
