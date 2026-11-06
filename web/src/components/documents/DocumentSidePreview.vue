<script setup lang="ts">
/**
 * Panel s náhledem originálu: hlavička s přepínačem umístění a samotný dokument.
 * Rozměry a umístění panelu určuje `DocumentPreviewSplit`, jediný jeho uživatel.
 *
 * `embedded` = panel nenese `data-side-preview`, podle kterého `Modal` odsazuje backdrop
 * od náhledu na stránce. Platí pro náhled uvnitř modálu i pro náhled pod formulářem.
 * Prochází-li modál frontu dokladů, může mít doklad bez originálu, proto `src: null`
 * ukáže prázdný stav místo rámu.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { btnIconSm, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import type { SidePreviewPlacement } from '@/composables/useSidePreview'
import PdfFrame from './PdfFrame.vue'

const props = defineProps<{
  /** URL náhledu — musí být inline varianta, jinak prohlížeč embed zablokuje. */
  src: string | null
  /** Jméno souboru do hlavičky a do `title` iframu. */
  fileName?: string | null
  /** Druh souboru; bez uvedení se bere PDF. Co nejde zobrazit, ukáže `emptyText`. */
  docType?: string | null
  embedded?: boolean
  /** Text prázdného stavu, když doklad originál nemá. */
  emptyText?: string
  placement?: SidePreviewPlacement
  /** Vejde se náhled vedle formuláře? Jinak přepínač umístění nemá co nabídnout. */
  canPlaceSide?: boolean
  /** Při tažení rozhraní nesmí rám polykat události ukazatele. */
  resizing?: boolean
}>()

defineEmits<{
  (e: 'close'): void
  (e: 'update:placement', next: SidePreviewPlacement): void
}>()

const { t } = useI18n()

const PLACEMENTS: Array<{ value: SidePreviewPlacement; label: string; icon: string }> = [
  { value: 'side', label: 'common.preview_place_side', icon: 'M3 5h18v14H3V5zm11 0v14' },
  { value: 'below', label: 'common.preview_place_below', icon: 'M3 5h18v14H3V5zm0 8h18' },
]

const kind = computed<'pdf' | 'image' | 'none'>(() => {
  if (!props.src) return 'none'
  const type = props.docType ?? 'pdf'
  return type === 'pdf' || type === 'image' ? type : 'none'
})
</script>

<template>
  <aside
    data-test="document-side-preview"
    :data-side-preview="embedded ? undefined : ''"
    :aria-label="t('common.preview_side_title')"
    class="flex flex-col overflow-hidden bg-surface border border-neutral-200 rounded-lg shadow-sm"
  >
    <div class="shrink-0 flex flex-wrap items-center justify-between gap-2 px-4 py-2 border-b border-neutral-100">
      <div class="min-w-0">
        <div class="text-sm font-medium text-neutral-700">{{ t('common.preview_side_title') }}</div>
        <div v-if="fileName" class="text-xs text-neutral-500 truncate" :title="fileName">{{ fileName }}</div>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <div v-if="canPlaceSide" role="group" :aria-label="t('common.preview_placement')" class="inline-flex gap-1">
          <button
            v-for="option in PLACEMENTS"
            :key="option.value"
            type="button"
            :class="btnIconSm(placement === option.value ? 'primary' : 'neutral')"
            :title="t(option.label)"
            :aria-label="t(option.label)"
            :aria-pressed="placement === option.value"
            :data-test="`document-preview-placement-${option.value}`"
            @click="$emit('update:placement', option.value)"
          >
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="option.icon" />
            </svg>
          </button>
        </div>
        <button
          type="button"
          :class="btnOutlineSm('neutral')"
          class="whitespace-nowrap"
          :title="t('common.preview_side_close')"
          data-test="document-side-preview-close"
          @click="$emit('close')"
        >
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" />
          </svg>
          {{ t('common.preview_side_close') }}
        </button>
      </div>
    </div>
    <PdfFrame
      v-if="kind === 'pdf'"
      :src="src!"
      class="flex-1 min-h-0 w-full border-0 bg-neutral-100"
      :class="{ 'pointer-events-none': resizing }"
      :title="fileName || t('common.preview_side_title')"
    />
    <div v-else-if="kind === 'image'" class="flex-1 min-h-0 overflow-auto bg-neutral-100 p-2">
      <img :key="src!" :src="src!" :alt="fileName || t('common.preview_side_title')" class="w-full object-contain" />
    </div>
    <div
      v-else
      data-test="document-side-preview-empty"
      class="flex-1 flex flex-col items-center justify-center gap-2 p-6 text-center text-sm text-neutral-500 bg-neutral-50"
    >
      <svg class="w-8 h-8 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
      </svg>
      {{ emptyText || t('common.preview_side_empty') }}
    </div>
  </aside>
</template>
