<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { MAX_WORKSPACE_WINDOWS, useWorkspaceStore } from '@/stores/workspace'
import { getWindowManager } from '@/workspace/windowRegistry'
import { formatShortcut, useKeyboardShortcuts } from '@/composables/useKeyboardShortcuts'
import { lockBodyScroll, unlockBodyScroll } from '@/utils/bodyScrollLock'

const { t } = useI18n()
const workspace = useWorkspaceStore()
const shortcuts = useKeyboardShortcuts()
const search = ref('')
const input = ref<HTMLInputElement | null>(null)
const dialog = ref<HTMLElement | null>(null)
const selected = ref(0)
let returnFocus: HTMLElement | null = null
let locked = false
const windows = computed(() => {
  const query = search.value.trim().toLocaleLowerCase()
  return workspace.windows.filter(window => `${window.title} ${window.subtitle ?? ''} ${window.supplierName ?? ''} ${window.fullPath === '/' ? t('workspace.homepage') : window.fullPath}`.toLocaleLowerCase().includes(query))
})

function close(): void { workspace.windowSwitcherOpen = false }

/** Na dotykovém zařízení by fokus do hledání vysunul klávesnici přes půl seznamu. */
function focusSearch(): void {
  if (window.matchMedia?.('(pointer: coarse)').matches) return
  input.value?.focus()
}

async function select(id: string): Promise<void> {
  returnFocus = null
  close()
  await getWindowManager()?.select(id)
}

async function closeWindow(id: string): Promise<void> {
  const index = windows.value.findIndex(window => window.id === id)
  await getWindowManager()?.closeWindow(id)
  selected.value = Math.max(0, Math.min(index, windows.value.length - 1))
  await nextTick()
  focusSearch()
}

function isCurrent(window: { paneId: string | null }): boolean {
  return window.paneId !== null && window.paneId === workspace.activePaneId
}

function panelNumber(paneId: string): number {
  return workspace.panes.findIndex(pane => pane.id === paneId) + 1
}

function onKey(event: KeyboardEvent): void {
  if (event.isComposing) return
  if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key) && !event.altKey && !event.ctrlKey && !event.metaKey) {
    event.preventDefault()
    event.stopPropagation()
    const count = windows.value.length
    if (!count) return
    if (event.key === 'Home') selected.value = 0
    else if (event.key === 'End') selected.value = count - 1
    else selected.value = (selected.value + (event.key === 'ArrowDown' ? 1 : -1) + count) % count
    void nextTick(() => dialog.value?.querySelector('[data-selected="true"]')?.scrollIntoView({ block: 'nearest' }))
  } else if (event.key === 'Enter') {
    event.preventDefault()
    event.stopPropagation()
    const window = windows.value[selected.value]
    if (window) void select(window.id)
  } else if (event.key === 'Delete' && !event.altKey && !event.ctrlKey && !event.metaKey) {
    const window = windows.value[selected.value]
    if (!window) return
    event.preventDefault()
    event.stopPropagation()
    void closeWindow(window.id)
  } else if (event.key === 'Escape') {
    event.preventDefault()
    event.stopPropagation()
    close()
  } else if (event.key === 'Tab') {
    const elements = [...(dialog.value?.querySelectorAll<HTMLElement>('input, button') ?? [])]
    const first = elements[0]
    const last = elements.at(-1)
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault()
      last?.focus()
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault()
      first?.focus()
    }
  }
}

watch(search, () => { selected.value = 0 })
watch(() => workspace.windowSwitcherOpen, async open => {
  if (open) {
    returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null
    search.value = ''
    selected.value = workspace.windows.length > 1 ? 1 : 0
    lockBodyScroll()
    locked = true
    await nextTick()
    focusSearch()
  } else {
    if (locked) unlockBodyScroll()
    locked = false
    if (returnFocus?.isConnected) returnFocus.focus({ preventScroll: true })
  }
})
onBeforeUnmount(() => { if (locked) unlockBodyScroll() })
</script>

<template>
  <Teleport to="body">
    <div v-if="workspace.windowSwitcherOpen" class="fixed inset-0 z-[70] flex items-start justify-center bg-neutral-900/45 px-4 pt-[12vh] backdrop-blur-[3px]" @click.self="close">
      <section ref="dialog" class="flex max-h-[76dvh] w-full max-w-xl flex-col overflow-hidden rounded-xl border border-neutral-200 bg-surface-raised shadow-2xl"
               role="dialog" aria-modal="true" :aria-label="t('workspace.windows')" @keydown="onKey">
        <header class="flex items-center justify-between gap-3 border-b border-neutral-200 px-5 py-3">
          <h2 class="font-semibold text-neutral-900">{{ t('workspace.windows') }} <span class="ml-1 text-xs font-normal text-neutral-500">{{ workspace.windows.length }}/{{ MAX_WORKSPACE_WINDOWS }}</span></h2>
          <button type="button" class="inline-flex h-7 w-7 cursor-pointer items-center justify-center rounded-md text-neutral-500 hover:bg-neutral-100" :aria-label="t('common.close')" @click="close">
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15" /></svg>
          </button>
        </header>
        <div class="px-4 py-3">
          <input ref="input" v-model="search" class="w-full rounded-lg border border-neutral-200 bg-surface px-3 py-2 text-sm outline-none focus:border-primary-400 focus:ring-2 focus:ring-primary-100"
                 :placeholder="t('workspace.find_window')" :aria-label="t('workspace.find_window')" autocomplete="off"
                 aria-controls="workspace-window-list" :aria-activedescendant="windows[selected] ? `workspace-option-${windows[selected]!.id}` : undefined" role="combobox" aria-expanded="true" aria-autocomplete="list">
        </div>
        <div id="workspace-window-list" class="min-h-0 overflow-y-auto px-2 pb-2" role="listbox" :aria-label="t('workspace.windows')">
          <div v-for="(window, index) in windows" :key="window.id" class="group relative">
            <button :id="`workspace-option-${window.id}`" type="button"
                    class="flex w-full cursor-pointer items-center gap-3 rounded-lg py-2.5 pl-3 pr-12 text-left transition-colors hover:bg-primary-50"
                    :class="index === selected ? 'bg-primary-50 ring-1 ring-inset ring-primary-200' : ''"
                    :data-selected="index === selected" role="option" :aria-selected="index === selected" @focus="selected = index" @click="select(window.id)">
              <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-xs font-semibold" :class="isCurrent(window) ? 'bg-success-600 text-white' : 'bg-neutral-100 text-neutral-500'" aria-hidden="true">{{ index + 1 }}</span>
              <span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium text-neutral-900">{{ window.title }}</span><span class="block truncate text-xs text-neutral-500">{{ window.subtitle || (window.fullPath === '/' ? t('workspace.homepage') : window.fullPath) }}</span><span v-if="window.supplierName" class="block truncate text-xs text-primary-600">{{ window.supplierName }}</span></span>
              <span v-if="window.paneId" class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2 py-1 text-xs font-medium" :class="isCurrent(window) ? 'bg-success-50 text-success-700 ring-1 ring-inset ring-success-200' : 'bg-neutral-100 text-neutral-600'" :data-current="isCurrent(window) || undefined">
                <span v-if="isCurrent(window)" class="h-1.5 w-1.5 rounded-full bg-success-600" aria-hidden="true"></span>{{ t(window.paneId === workspace.activePaneId ? 'workspace.active_panel_number' : 'workspace.panel_number', { number: panelNumber(window.paneId) }) }}
              </span>
            </button>
            <button type="button" class="absolute right-2 top-1/2 inline-flex h-7 w-7 -translate-y-1/2 cursor-pointer items-center justify-center rounded-md text-neutral-400 hover:bg-danger-50 hover:text-danger-600 focus-visible:ring-2 focus-visible:ring-primary-400"
                    :title="t('workspace.close_window')" :aria-label="t('workspace.close_window_named', { title: window.title })" data-close-window @click.stop="closeWindow(window.id)">
              <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15" /></svg>
            </button>
          </div>
          <p v-if="!windows.length" class="px-3 py-6 text-center text-sm text-neutral-500">{{ t('workspace.no_windows') }}</p>
        </div>
        <footer class="space-y-1.5 border-t pointer-coarse:hidden border-neutral-200 bg-neutral-50 px-5 py-3 text-xs text-neutral-500">
          <p>{{ t('workspace.window_keyboard_hint') }}</p>
          <p v-if="shortcuts.comboFor('windows.recent')">{{ formatShortcut(shortcuts.comboFor('windows.recent')) }}: {{ t('workspace.recent_windows') }}</p>
          <p>{{ t('workspace.window_memory_hint') }}</p>
        </footer>
      </section>
    </div>
  </Teleport>
</template>
