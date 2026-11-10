<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useWorkspaceStore, type WorkspaceWindowState } from '@/stores/workspace'
import { useSupplierStore } from '@/stores/supplier'
import { getWindowManager } from '@/workspace/windowRegistry'
import { NEW_TAB_PATH, visibleWindowTabs, windowTabCapacity, windowTabGroups, type WindowTab } from '@/workspace/windowTabs'
import { formatShortcut, useKeyboardShortcuts } from '@/composables/useKeyboardShortcuts'
import { useToast } from '@/composables/useToast'

const props = defineProps<{
  accentFor?: (fullPath: string) => string
  /** Telefon na šířku s lištou ikon: na záložky tam není výška. */
  disabled?: boolean
}>()

const { t } = useI18n()
const workspace = useWorkspaceStore()
const supplier = useSupplierStore()
const shortcuts = useKeyboardShortcuts()
const toast = useToast()
const root = ref<HTMLElement | null>(null)
const nav = ref<HTMLElement | null>(null)
const width = ref(0)
let observer: ResizeObserver | null = null

const shown = computed(() => !props.disabled && !workspace.windowTabsHidden && workspace.windows.length > 0)
const tabs = computed(() => visibleWindowTabs(workspace.windows, windowTabCapacity(width.value), workspace.activePaneId))
const hiddenCount = computed(() => windowTabGroups(workspace.windows).length - tabs.value.length)
const multiPane = computed(() => workspace.paneCount > 1)
const switcherLabel = computed(() => [t('workspace.tabs_all_windows', { count: workspace.windows.length }),
  formatShortcut(shortcuts.comboFor('windows.switcher'))].filter(Boolean).join(' · '))

function isActive(tab: WindowTab): boolean {
  return tab.windows.some(window => window.paneId !== null && window.paneId === workspace.activePaneId)
}

function visible(tab: WindowTab): boolean {
  return tab.windows.some(window => window.paneId !== null)
}

function otherCompany(window: WorkspaceWindowState): boolean {
  return supplier.hasMultiple && !!window.supplierId && window.supplierId !== supplier.currentSupplierId
}

function label(window: WorkspaceWindowState): string {
  if (window.title && window.title !== window.fullPath) return window.title
  if (window.fullPath === NEW_TAB_PATH) return t('workspace.new_tab_title')
  return window.fullPath === '/' ? t('workspace.homepage') : window.fullPath
}

function tooltip(tab: WindowTab): string {
  const window = tab.window
  const location = window.subtitle || (window.fullPath === '/' ? t('workspace.homepage') : window.fullPath)
  return [label(window), location !== label(window) ? location : '', window.supplierName ?? '',
    tab.windows.length > 1 ? t('workspace.tab_window_count', { count: tab.windows.length }) : ''].filter(Boolean).join('\n')
}

function panelNumbers(tab: WindowTab): number[] {
  return workspace.panes.flatMap((pane, index) => tab.windows.some(window => window.paneId === pane.id) ? [index + 1] : [])
}

function closeLabel(tab: WindowTab): string {
  return tab.windows.length > 1
    ? t('workspace.close_tab_named', { title: label(tab.window), count: tab.windows.length })
    : t('workspace.close_window_named', { title: label(tab.window) })
}

function select(tab: WindowTab): void {
  void getWindowManager()?.select(tab.window.id)
}

function openTab(): void {
  void getWindowManager()?.openTab()
}

const menu = ref<{ tab: WindowTab, x: number, y: number } | null>(null)
const menuElement = ref<HTMLElement | null>(null)
const groupCount = computed(() => windowTabGroups(workspace.windows).length)
let menuReturnFocus: HTMLElement | null = null

function tabButtons(): HTMLElement[] {
  return [...(nav.value?.querySelectorAll<HTMLElement>('[data-window-tab]') ?? [])]
}

async function closeTab(tab: WindowTab): Promise<void> {
  const buttons = tabButtons()
  const focused = buttons.findIndex(button => button.closest('[data-window-tab-item]')?.contains(document.activeElement))
  await getWindowManager()?.closeWindows(tab.windows.map(window => window.id))
  if (focused < 0) return
  await nextTick()
  const remaining = tabButtons()
  remaining[Math.min(focused, remaining.length - 1)]?.focus()
}

function onAuxClick(event: MouseEvent, tab: WindowTab): void {
  if (event.button !== 1) return
  event.preventDefault()
  void closeTab(tab)
}

function openMenu(tab: WindowTab, x: number, y: number): void {
  menuReturnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null
  menu.value = { tab, x: Math.max(8, Math.min(x, window.innerWidth - 232)), y: Math.max(8, Math.min(y, window.innerHeight - 140)) }
  void nextTick(() => menuElement.value?.querySelector<HTMLElement>('[role="menuitem"]:not(:disabled)')?.focus())
}

function onContextMenu(event: MouseEvent, tab: WindowTab): void {
  event.preventDefault()
  openMenu(tab, event.clientX, event.clientY)
}

function closeMenu(restoreFocus = true): void {
  menu.value = null
  if (restoreFocus && menuReturnFocus?.isConnected) menuReturnFocus.focus({ preventScroll: true })
  menuReturnFocus = null
}

function menuAction(action: (tab: WindowTab) => unknown): void {
  const tab = menu.value?.tab
  closeMenu(false)
  if (tab) void action(tab)
}

function duplicateTab(tab: WindowTab): void {
  void getWindowManager()?.openTab(tab.window.id)
}

function closeOtherTabs(tab: WindowTab): void {
  const keep = new Set(tab.windows.map(window => window.id))
  void getWindowManager()?.closeWindows(workspace.windows.filter(window => !keep.has(window.id)).map(window => window.id))
}

function onMenuKeydown(event: KeyboardEvent): void {
  const items = [...(menuElement.value?.querySelectorAll<HTMLElement>('[role="menuitem"]:not(:disabled)') ?? [])]
  const index = items.indexOf(document.activeElement as HTMLElement)
  if (event.key === 'Escape') {
    event.preventDefault()
    event.stopPropagation()
    closeMenu()
  } else if (event.key === 'Tab') {
    closeMenu()
  } else if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key) && items.length) {
    event.preventDefault()
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1
      : (index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length
    items[next]?.focus()
  }
}

function onKeydown(event: KeyboardEvent): void {
  const buttons = tabButtons()
  const index = buttons.indexOf(document.activeElement as HTMLElement)
  if (index < 0) return
  if (event.key === 'ContextMenu' || (event.shiftKey && event.key === 'F10')) {
    const tab = tabs.value[index]
    const rect = buttons[index]!.getBoundingClientRect()
    event.preventDefault()
    if (tab) openMenu(tab, rect.left, rect.bottom + 2)
    return
  }
  if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return
  if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
    event.preventDefault()
    buttons[(index + (event.key === 'ArrowRight' ? 1 : -1) + buttons.length) % buttons.length]?.focus()
  } else if (event.key === 'Home' || event.key === 'End') {
    event.preventDefault()
    buttons[event.key === 'Home' ? 0 : buttons.length - 1]?.focus()
  } else if (event.key === 'Delete') {
    const tab = tabs.value[index]
    if (!tab) return
    event.preventDefault()
    void closeTab(tab)
  }
}

function hideTabs(): void {
  workspace.setWindowTabsHidden(true)
  const combo = shortcuts.comboFor('windows.switcher')
  toast.info(combo
    ? t('workspace.tabs_hidden_toast_shortcut', { shortcut: formatShortcut(combo) })
    : t('workspace.tabs_hidden_toast'))
}

onMounted(() => {
  if (!root.value) return
  width.value = root.value.clientWidth
  observer = new ResizeObserver(entries => {
    const measured = entries[0]?.contentRect.width
    if (measured !== undefined && Math.round(measured) !== width.value) width.value = Math.round(measured)
  })
  observer.observe(root.value)
})

const hint = ref<{ text: string, x: number, y: number } | null>(null)
let hintElement: HTMLElement | null = null
let hintTimer: ReturnType<typeof setTimeout> | undefined
/** Šířka tooltipu je nejvýš 16rem, střed proto držíme dál od okraje okna. */
const HINT_EDGE = 136

function hintTarget(event: Event): HTMLElement | null {
  return event.target instanceof Element ? event.target.closest<HTMLElement>('[data-hint]') : null
}

function hideHint(): void {
  clearTimeout(hintTimer)
  hint.value = null
  hintElement = null
}

function scheduleHint(event: Event): void {
  const target = hintTarget(event)
  if (!target || target === hintElement) return
  if (event.type === 'focusin' && !target.matches(':focus-visible')) return
  const shown = hint.value !== null
  hideHint()
  hintElement = target
  // Při přejezdu mezi prvky lišty se další popisek ukáže hned, jako v nabídkách prohlížeče.
  hintTimer = setTimeout(() => {
    if (!target.isConnected || !target.dataset.hint) return
    const rect = target.getBoundingClientRect()
    const x = Math.max(HINT_EDGE, Math.min(rect.left + rect.width / 2, window.innerWidth - HINT_EDGE))
    hint.value = { text: target.dataset.hint, x, y: rect.bottom + 6 }
  }, shown ? 0 : 350)
}

function leaveHint(event: MouseEvent): void {
  if (hintElement && event.relatedTarget instanceof Node && hintElement.contains(event.relatedTarget)) return
  const next = event.relatedTarget instanceof Element ? event.relatedTarget.closest<HTMLElement>('[data-hint]') : null
  if (next) return
  hideHint()
}

watch(tabs, () => { if (hintElement && !hintElement.isConnected) hideHint() })

onBeforeUnmount(() => {
  observer?.disconnect()
  clearTimeout(hintTimer)
})
</script>

<template>
  <div ref="root" data-workspace-tabs class="print:hidden" :class="shown ? 'hidden md:block' : 'hidden'">
    <nav
      v-if="shown"
      ref="nav"
      class="workspace-window-tabs flex h-9 items-end gap-0.5 border-b border-neutral-200 px-2"
      :inert="workspace.companySwitching"
      :aria-label="t('workspace.tabs_label')"
      @keydown="onKeydown"
      @mouseover="scheduleHint"
      @mouseout="leaveHint"
      @focusin="scheduleHint"
      @focusout="hideHint"
      @pointerdown="hideHint"
    >
      <div
        v-for="tab in tabs"
        :key="tab.key"
        data-window-tab-item
        class="workspace-window-tab group relative flex h-[1.875rem] min-w-0 flex-[0_1_14rem] items-center transition-colors"
        :class="isActive(tab)
          ? ['workspace-window-tab-active z-[1] -mb-px h-[calc(1.875rem+1px)] border border-b-0 border-neutral-200 text-neutral-900', multiPane ? 'bg-surface' : 'bg-neutral-50']
          : visible(tab) ? 'text-neutral-800 hover:bg-neutral-200/60' : 'text-neutral-600 hover:bg-neutral-200/60 hover:text-neutral-900'"
        :data-accent="accentFor?.(tab.window.fullPath) ?? 'primary'"
        @mousedown.middle.prevent
        @auxclick="onAuxClick($event, tab)"
        @contextmenu="onContextMenu($event, tab)"
      >
        <button
          type="button"
          data-window-tab
          class="flex h-full min-w-0 flex-1 cursor-pointer items-center gap-1.5 pl-3 pr-1 text-left text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-400"
          :class="isActive(tab) ? 'font-semibold' : 'font-medium'"
          :data-hint="tooltip(tab)"
          :aria-current="isActive(tab) ? 'page' : undefined"
          aria-haspopup="menu"
          @click="select(tab)"
        >
          <svg v-if="otherCompany(tab.window)" class="h-3.5 w-3.5 shrink-0 text-neutral-400" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
            <path d="M3.5 17V4.5a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1V17M11.5 8h4a1 1 0 0 1 1 1v8M2 17h16M6.5 7h2M6.5 10h2M6.5 13h2" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
          <span class="min-w-0 flex-1 truncate">{{ label(tab.window) }}</span>
          <span v-if="otherCompany(tab.window)" class="sr-only">{{ t('workspace.tab_other_company', { name: tab.window.supplierName ?? '' }) }}</span>
          <template v-if="multiPane">
            <span
              v-for="number in panelNumbers(tab)"
              :key="number"
              class="inline-flex h-4 min-w-4 shrink-0 items-center justify-center px-1 text-[10px] font-semibold leading-none"
              :class="workspace.panes[number - 1]?.id === workspace.activePaneId ? 'bg-success-600 text-white' : 'bg-neutral-200 text-neutral-600'"
              :data-hint="t('workspace.panel_number', { number })"
            >
              <span class="sr-only">{{ t('workspace.panel_number', { number }) }}</span>
              <span aria-hidden="true">{{ number }}</span>
            </span>
          </template>
        </button>
        <button
          v-if="isActive(tab)"
          type="button"
          class="inline-flex h-5 w-5 shrink-0 cursor-pointer items-center justify-center text-neutral-400 transition hover:bg-primary-100 hover:text-primary-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-400"
          :data-hint="t('workspace.tab_duplicate_hint')"
          :aria-label="t('workspace.tab_duplicate')"
          tabindex="-1"
          data-duplicate-window-tab
          @click.stop="duplicateTab(tab)"
        >
          <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M13.5 6V3.5h-10v10H6" stroke-linejoin="round" /><rect x="6.5" y="6.5" width="10" height="10" /><path d="M11.5 9v5M9 11.5h5" stroke-linecap="round" /></svg>
        </button>
        <button
          type="button"
          class="mr-1 inline-flex h-5 w-5 shrink-0 cursor-pointer items-center justify-center text-neutral-400 transition hover:bg-danger-100 hover:text-danger-700 focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger-400 pointer-coarse:opacity-100"
          :class="isActive(tab) ? 'opacity-100' : 'opacity-0 group-hover:opacity-100'"
          :data-hint="closeLabel(tab)"
          :aria-label="closeLabel(tab)"
          tabindex="-1"
          data-close-window-tab
          @click.stop="closeTab(tab)"
        >
          <svg class="h-3 w-3" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15" stroke-linecap="round" /></svg>
        </button>
      </div>
      <button
        type="button"
        class="ml-0.5 inline-flex h-7 w-7 shrink-0 cursor-pointer items-center justify-center self-center text-neutral-500 transition-colors hover:bg-neutral-200/70 hover:text-primary-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-400"
        :data-hint="t('workspace.tab_new_hint')"
        :aria-label="t('workspace.tab_new')"
        data-window-tab-new
        @click="openTab"
      >
        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M10 4.5v11M4.5 10h11" stroke-linecap="round" /></svg>
      </button>

      <div class="ml-auto flex shrink-0 items-center gap-0.5 self-center pl-2">
        <button
          type="button"
          class="relative inline-flex h-7 min-w-7 cursor-pointer items-center justify-center gap-1 px-1.5 text-neutral-600 transition-colors hover:bg-neutral-200/70 hover:text-primary-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-400"
          :class="workspace.windowSwitcherOpen ? 'bg-neutral-200/70 text-primary-700' : ''"
          :data-hint="switcherLabel"
          :aria-label="t('workspace.tabs_all_windows', { count: workspace.windows.length })"
          :aria-expanded="workspace.windowSwitcherOpen"
          aria-haspopup="dialog"
          data-window-tabs-switcher
          @click="workspace.windowSwitcherOpen = !workspace.windowSwitcherOpen"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
            <rect x="3" y="7" width="14" height="13" rx="2" />
            <path d="M7 7V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2M3 11h14" />
          </svg>
          <span v-if="hiddenCount > 0" class="text-[11px] font-semibold tabular-nums" aria-hidden="true">+{{ hiddenCount }}</span>
        </button>
        <button
          type="button"
          class="inline-flex h-7 w-7 cursor-pointer items-center justify-center text-neutral-400 transition-colors hover:bg-neutral-200/70 hover:text-neutral-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-400"
          :data-hint="t('workspace.tabs_hide')"
          :aria-label="t('workspace.tabs_hide')"
          data-window-tabs-hide
          @click="hideTabs"
        >
          <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15" stroke-linecap="round" /></svg>
        </button>
      </div>
    </nav>
    <Teleport to="body">
      <div
        v-if="hint"
        class="pointer-events-none fixed z-[80] w-max max-w-64 -translate-x-1/2 bg-neutral-900 px-2 py-1 text-xs leading-snug whitespace-pre-line text-white shadow-lg dark:bg-surface-raised dark:ring-1 dark:ring-neutral-300"
        :style="{ left: `${hint.x}px`, top: `${hint.y}px` }"
        role="tooltip"
        data-window-tabs-hint
      >{{ hint.text }}</div>
      <div v-if="menu" class="fixed inset-0 z-[75]" data-window-tab-menu-backdrop @pointerdown="closeMenu()" @contextmenu.prevent="closeMenu()" />
      <div
        v-if="menu"
        ref="menuElement"
        class="fixed z-[76] w-56 border border-neutral-200 bg-surface-raised py-1 text-sm shadow-xl"
        :style="{ left: `${menu.x}px`, top: `${menu.y}px` }"
        role="menu"
        :aria-label="label(menu.tab.window)"
        data-window-tab-menu
        @keydown="onMenuKeydown"
      >
        <button type="button" role="menuitem" class="flex w-full cursor-pointer items-center gap-2.5 px-3 py-2 text-left text-neutral-700 hover:bg-primary-50 hover:text-primary-700 focus:bg-primary-50 focus:text-primary-700 focus:outline-none"
                data-tab-menu-duplicate @click="menuAction(duplicateTab)">
          <svg class="h-4 w-4 shrink-0 text-primary-600" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M13.5 6V3.5h-10v10H6" stroke-linejoin="round" /><rect x="6.5" y="6.5" width="10" height="10" /><path d="M11.5 9v5M9 11.5h5" stroke-linecap="round" /></svg>
          {{ t('workspace.tab_duplicate') }}
        </button>
        <div class="my-1 border-t border-neutral-200" role="separator" />
        <button type="button" role="menuitem" class="flex w-full cursor-pointer items-center gap-2.5 px-3 py-2 text-left text-neutral-700 hover:bg-danger-50 hover:text-danger-700 focus:bg-danger-50 focus:text-danger-700 focus:outline-none"
                data-tab-menu-close @click="menuAction(closeTab)">
          <svg class="h-4 w-4 shrink-0 text-danger-600" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15" stroke-linecap="round" /></svg>
          {{ t('workspace.tab_close') }}
        </button>
        <button type="button" role="menuitem" class="flex w-full cursor-pointer items-center gap-2.5 px-3 py-2 text-left text-neutral-700 hover:bg-danger-50 hover:text-danger-700 focus:bg-danger-50 focus:text-danger-700 focus:outline-none disabled:cursor-default disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-neutral-700"
                :disabled="groupCount < 2" data-tab-menu-close-others @click="menuAction(closeOtherTabs)">
          <svg class="h-4 w-4 shrink-0 text-danger-600" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="3" width="6" height="14" /><path d="m12 7 5 6M17 7l-5 6" stroke-linecap="round" /></svg>
          {{ t('workspace.tab_close_others') }}
        </button>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.workspace-window-tabs {
  background: color-mix(in oklab, var(--color-neutral-100) 75%, var(--color-neutral-50));
}

/* Barva modulu nahoře na aktivní záložce navazuje na proužek nad obsahem. */
.workspace-window-tab-active {
  box-shadow: inset 0 2px 0 var(--module-accent, var(--color-primary-500));
}

/* Tenký předěl jen mezi dvěma neaktivními záložkami, jako v prohlížeči. */
.workspace-window-tab:not(.workspace-window-tab-active) + .workspace-window-tab:not(.workspace-window-tab-active)::before {
  content: "";
  position: absolute;
  top: 0.5rem;
  bottom: 0.5rem;
  left: -2px;
  width: 1px;
  background: var(--color-neutral-300);
  pointer-events: none;
}

.workspace-window-tab:hover::before,
.workspace-window-tab:hover + .workspace-window-tab::before {
  opacity: 0;
}
</style>
