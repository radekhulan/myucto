<script setup lang="ts">
import { Comment, Fragment, onBeforeUnmount, ref, useSlots, watch, type VNode } from 'vue'
import { useI18n } from 'vue-i18n'
import WorkspaceTeleport from '@/components/workspace/WorkspaceTeleport.vue'
import { useIsPhone } from '@/composables/useIsPhone'

/**
 * Plovoucí lišta hromadných akcí.
 *
 * Why: akce nad výběrem byly v hlavičce stránky vedle nadpisu. Objevovaly se a
 * mizely podle počtu vybraných řádků, takže při každém zaškrtnutí poskočilo
 * tlačítko „Nová faktura" jinam — a hlavně: uživatel vybírá řádky dole v tabulce,
 * zatímco akce k nim byly úplně nahoře mimo zorné pole.
 *
 * Lišta je proto u spodní hrany, nad patičkou aplikace, a nese i počet vybraných
 * a způsob, jak výběr zrušit. Teleport na body, aby ji neořízl žádný wrapper
 * s overflow.
 *
 * Telefon: zalomená tlačítka by zakryla většinu seznamu, proto lišta drží jeden
 * řádek (počet, Akce, zrušit výběr) a akce stránky se otevřou v nabídce zespodu.
 *
 * Desktop: méně časté akce dá stránka do slotu `more`. Lišta je pak schová pod
 * tlačítko „Další", které otevře nabídku nad lištou, aby se lišta nezalomila do
 * dvou řádků přes tabulku. Na telefonu jsou v nabídce zespodu pod hlavními akcemi.
 */
const props = defineProps<{
  /** Počet vybraných položek; při nule se lišta nevykresluje. */
  count: number
}>()

const emit = defineEmits<{ clear: [] }>()
const { t } = useI18n()
const isPhone = useIsPhone()
const slots = useSlots()

/** Vykreslí slot `more` aspoň jednu akci? Tlačítka s nesplněným `v-if` zůstanou jen jako komentáře. */
function hasContent(nodes: VNode[]): boolean {
  return nodes.some(node => {
    if (node.type === Comment) return false
    if (node.type === Fragment) return Array.isArray(node.children) && hasContent(node.children as VNode[])
    return true
  })
}

function hasMore(): boolean {
  return !!slots.more && hasContent(slots.more())
}
const sheetOpen = ref(false)
const moreOpen = ref(false)
const moreRoot = ref<HTMLElement | null>(null)

watch(() => props.count, count => {
  if (count === 0) {
    sheetOpen.value = false
    moreOpen.value = false
  }
})

/** Klik na akci nabídku zavře; tlačítko, které otevírá vlastní podnabídku, ji nechá. */
function closesMenu(event: MouseEvent): boolean {
  const target = event.target instanceof Element ? event.target.closest('button, a') : null
  return !!target && !target.hasAttribute('aria-haspopup') && target.getAttribute('aria-expanded') === null
}

function onSheetClick(event: MouseEvent): void {
  if (closesMenu(event)) sheetOpen.value = false
}

function onMoreClick(event: MouseEvent): void {
  if (closesMenu(event)) moreOpen.value = false
}

function onDocumentPointer(event: PointerEvent): void {
  if (moreRoot.value && event.target instanceof Node && !moreRoot.value.contains(event.target)) moreOpen.value = false
}

function onDocumentKey(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    event.stopPropagation()
    moreOpen.value = false
  }
}

watch(moreOpen, open => {
  if (open) {
    document.addEventListener('pointerdown', onDocumentPointer, true)
    document.addEventListener('keydown', onDocumentKey, true)
  } else {
    document.removeEventListener('pointerdown', onDocumentPointer, true)
    document.removeEventListener('keydown', onDocumentKey, true)
  }
})

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocumentPointer, true)
  document.removeEventListener('keydown', onDocumentKey, true)
})
</script>

<template>
  <WorkspaceTeleport>
    <Transition
      enter-active-class="transition duration-300 ease-[cubic-bezier(0.22,1.2,0.36,1)]"
      enter-from-class="opacity-0 translate-y-4"
      leave-active-class="transition duration-150 ease-in"
      leave-to-class="opacity-0 translate-y-2"
    >
      <div v-if="count > 0 && isPhone"
        class="nav-inverted fixed inset-x-0 bottom-3 z-40 flex justify-center px-3 pointer-events-none"
        data-test="bulk-bar-phone">
        <div class="pointer-events-auto flex w-full items-center gap-2 rounded-xl bg-surface-raised px-3 py-2 shadow-2xl ring-1 ring-neutral-300">
          <span class="min-w-0 flex-1 truncate px-1 text-sm font-medium text-neutral-900 tabular-nums">
            {{ t('common.bulk_selected', { n: count }) }}
          </span>
          <button type="button"
            class="cursor-pointer inline-flex h-10 items-center gap-1.5 rounded-lg bg-primary-400 px-4 text-sm font-semibold text-[#211A4B]"
            :aria-expanded="sheetOpen" data-test="bulk-bar-actions"
            @click="sheetOpen = !sheetOpen">
            {{ t('common.bulk_actions') }}
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7" />
            </svg>
          </button>
          <button type="button"
            class="cursor-pointer inline-flex h-10 w-10 items-center justify-center rounded-lg text-neutral-500 transition-colors hover:text-neutral-900"
            :aria-label="t('common.bulk_clear')" :title="t('common.bulk_clear')"
            @click="emit('clear')">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </div>
      <div v-else-if="count > 0"
        class="nav-inverted fixed inset-x-0 bottom-3 lg:bottom-14 z-40 flex justify-center px-4 pointer-events-none">
        <div class="pointer-events-auto flex flex-wrap items-center gap-2 rounded-xl bg-surface-raised px-3 py-2 shadow-2xl ring-1 ring-neutral-300">
          <span class="px-1.5 text-sm font-medium text-neutral-900 tabular-nums whitespace-nowrap">
            {{ t('common.bulk_selected', { n: count }) }}
          </span>
          <span class="mx-1 h-5 w-px bg-neutral-300" aria-hidden="true"></span>

          <slot />

          <div v-if="hasMore()" ref="moreRoot" class="relative" data-test="bulk-bar-more">
            <button type="button"
              class="cursor-pointer inline-flex h-8 items-center gap-1.5 rounded-md px-3 text-sm font-medium text-neutral-700 ring-1 ring-neutral-300 transition-colors hover:text-neutral-900 hover:bg-neutral-100"
              :aria-expanded="moreOpen" data-test="bulk-bar-more-toggle"
              @click="moreOpen = !moreOpen">
              {{ t('common.bulk_more') }}
              <svg class="h-4 w-4 transition-transform" :class="moreOpen ? '' : 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7" />
              </svg>
            </button>
            <div v-show="moreOpen"
              class="bulk-more-menu absolute bottom-full right-0 mb-2 flex min-w-56 flex-col gap-1.5 rounded-xl bg-surface-raised p-2 shadow-2xl ring-1 ring-neutral-300"
              role="menu" data-test="bulk-bar-more-menu"
              @click="onMoreClick">
              <slot name="more" />
            </div>
          </div>

          <button type="button"
            class="cursor-pointer ml-1 inline-flex h-8 items-center gap-1 rounded-md px-2 text-xs text-neutral-500 transition-colors hover:text-neutral-900"
            @click="emit('clear')">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
            {{ t('common.bulk_clear') }}
          </button>
        </div>
      </div>
    </Transition>
    <template v-if="isPhone && sheetOpen && count > 0">
      <div class="fixed inset-0 z-[45] bg-black/40" aria-hidden="true" @click="sheetOpen = false"></div>
      <div class="nav-inverted bulk-action-sheet fixed inset-x-0 bottom-0 z-[46] max-h-[80dvh] overflow-y-auto overscroll-contain rounded-t-2xl bg-surface-raised px-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-3 shadow-2xl"
        role="dialog" :aria-label="t('common.bulk_actions')" data-test="bulk-bar-sheet"
        @click="onSheetClick">
        <div class="mb-2 px-1 text-sm font-medium text-neutral-700 tabular-nums">{{ t('common.bulk_selected', { n: count }) }}</div>
        <div class="flex flex-col gap-1.5">
          <slot />
          <slot name="more" />
        </div>
      </div>
    </template>
  </WorkspaceTeleport>
</template>

<style scoped>
/* Akce stránky jsou tlačítka do řádku; v nabídce zespodu je roztáhneme na celou šířku a na palec. */
.bulk-action-sheet :deep(:is(button, a)) {
  width: 100%;
  min-height: 2.75rem;
  justify-content: flex-start;
}

/* V nabídce Další jsou akce pod sebou přes celou šířku nabídky. */
.bulk-more-menu :deep(:is(button, a)) {
  width: 100%;
  justify-content: flex-start;
  white-space: nowrap;
}
</style>
