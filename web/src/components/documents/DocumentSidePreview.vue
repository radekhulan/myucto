<script setup lang="ts">
/**
 * Přilepený sloupec s náhledem originálu vpravo od dokladu.
 *
 * Vykresluje se JEN nad prahem `useSidePreview` a jen když doklad originál má —
 * o obojím rozhoduje volající, aby po prázdném dokladu nezůstal prázdný sloupec
 * a formulář zabral celou šířku. Pod prahem stránka náhled rozbalí pod obsahem.
 *
 * `sticky` + vlastní výška podle výřezu: doklad bývá delší než náhled, takže při
 * scrollování řádků musí originál zůstat vidět, jinak je náhled vedle k ničemu.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'

const props = defineProps<{
  /** URL náhledu — musí být inline varianta, jinak prohlížeč embed zablokuje. */
  src: string
  /** Jméno souboru do hlavičky a do `title` iframu. */
  fileName?: string | null
}>()

defineEmits<{ (e: 'close'): void }>()

const { t } = useI18n()

/**
 * `#view=FitH` říká prohlížečové čtečce, ať stránku roztáhne na šířku rámu.
 *
 * Why: bez toho si čtečka drží výchozí zoom, takže se doklad vykreslil zhruba
 * na půl šířky sloupce a zbytek zabrala její šedá plocha — náhled vedle
 * dokladu pak byl menší než ten pod ním, přesně naopak, než k čemu je.
 *
 * Fragment se přidává jen když si ho URL nenese sama; vlastní fragment
 * (třeba `#page=3`) má přednost, protože ho tam někdo dal schválně.
 */
const framedSrc = computed(
  () => props.src.includes('#') ? props.src : `${props.src}#view=FitH`,
)
</script>

<template>
  <aside
    data-test="document-side-preview"
    :aria-label="t('common.preview_side_title')"
    class="shrink-0 grow-0 basis-[46%] min-w-[26rem] max-w-[56rem] flex flex-col overflow-hidden
           bg-surface border border-neutral-200 rounded-lg shadow-sm
           sticky top-[calc(var(--instance-alert-h,0px)+3.5rem)]
           h-[calc(100vh-var(--instance-alert-h,0px)-5rem)]"
  >
    <div class="shrink-0 flex items-center justify-between gap-2 px-4 py-2 border-b border-neutral-100">
      <div class="min-w-0">
        <div class="text-sm font-medium text-neutral-700">{{ t('common.preview_side_title') }}</div>
        <div v-if="fileName" class="text-xs text-neutral-500 truncate" :title="fileName">{{ fileName }}</div>
      </div>
      <button
        type="button"
        :class="btnOutlineSm('neutral')"
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
    <iframe
      :src="framedSrc"
      class="flex-1 w-full border-0 bg-neutral-100"
      :title="fileName || t('common.preview_side_title')"
    ></iframe>
  </aside>
</template>
