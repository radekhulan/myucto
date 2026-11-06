<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { MAX_FAVORITES, useFavoritesStore } from '@/stores/favorites'
import { useSupplierStore } from '@/stores/supplier'
import { useWorkspaceStore } from '@/stores/workspace'
import { useWorkspaceNavigation } from '@/composables/useWorkspaceNavigation'
import { useToast } from '@/composables/useToast'
import { getWindowManager } from '@/workspace/windowRegistry'
import { withoutRecordParams } from '@/workspace/favoritePaths'
import type { Favorite } from '@/api/favorites'
import { lockBodyScroll, unlockBodyScroll } from '@/utils/bodyScrollLock'

const { t } = useI18n()
const router = useRouter()
const favorites = useFavoritesStore()
const supplier = useSupplierStore()
const workspace = useWorkspaceStore()
const navigation = useWorkspaceNavigation()
const toast = useToast()

const dialog = ref<HTMLElement | null>(null)
const list = ref<HTMLElement | null>(null)
const renameInput = ref<HTMLInputElement | null>(null)
const selected = ref(0)
const editingId = ref<number | null>(null)
const editValue = ref('')
const busy = ref(false)
let returnFocus: HTMLElement | null = null
let locked = false

const currentPath = computed(() => workspace.activePane?.fullPath ?? null)
const currentWindow = computed(() => workspace.windows.find(window => window.paneId === workspace.activePaneId))
const currentTitle = computed(() => {
  const window = currentWindow.value
  const base = window?.title || workspace.activePane?.title || currentPath.value || ''
  const full = window?.subtitle ? `${base} - ${window.subtitle}` : base
  return full.length > 190 ? full.slice(0, 190) : full
})
const currentFavorite = computed(() => favorites.items.find(item => item.path === currentPath.value
  && (item.supplier_id === null || item.supplier_id === supplier.currentSupplierId)))
const limitReached = computed(() => favorites.items.length >= MAX_FAVORITES)

function close(): void { favorites.open = false }

function companyName(item: Favorite): string {
  if (item.supplier_id === null) return ''
  return supplier.availableSuppliers.find(candidate => candidate.id === item.supplier_id)?.company_name ?? ''
}

function isAvailable(item: Favorite): boolean {
  if (item.supplier_id === null || item.supplier_id === supplier.currentSupplierId) return true
  return !supplier.domainLocked && supplier.availableSuppliers.some(candidate => candidate.id === item.supplier_id)
}

function isCurrent(item: Favorite): boolean {
  return item.path === currentPath.value && (item.supplier_id === null || item.supplier_id === supplier.currentSupplierId)
}

async function toggleCurrent(): Promise<void> {
  if (busy.value) return
  busy.value = true
  try {
    const existing = currentFavorite.value
    if (existing) await favorites.remove(existing.id)
    else if (currentPath.value && !limitReached.value) await favorites.add(currentPath.value, currentTitle.value || currentPath.value)
  } catch {
    toast.error(t('favorites.save_failed'))
  } finally {
    busy.value = false
  }
}

async function openItem(item: Favorite, keepCompany: boolean): Promise<void> {
  returnFocus = null
  close()
  const sameCompany = item.supplier_id === null || item.supplier_id === supplier.currentSupplierId
  if (!keepCompany && isAvailable(item)) {
    const manager = getWindowManager()
    if (!sameCompany && manager) await manager.switchCompany(item.supplier_id!, item.path)
    else await navigation.navigate(item.path)
    return
  }
  let target = item.path
  if (!sameCompany) {
    const adjusted = withoutRecordParams(router, item.path)
    if (adjusted.changed) {
      target = adjusted.path
      toast.warning(t('favorites.record_in_other_company'))
    }
  }
  await navigation.navigate(target)
}

async function removeItem(item: Favorite): Promise<void> {
  const index = favorites.items.findIndex(candidate => candidate.id === item.id)
  try {
    await favorites.remove(item.id)
  } catch {
    toast.error(t('favorites.save_failed'))
    return
  }
  selected.value = Math.max(0, Math.min(index, favorites.items.length - 1))
  await nextTick()
  list.value?.focus()
}

async function startRename(item: Favorite): Promise<void> {
  editingId.value = item.id
  editValue.value = item.title
  await nextTick()
  renameInput.value?.focus()
  renameInput.value?.select()
}

async function commitRename(): Promise<void> {
  const id = editingId.value
  if (id === null) return
  editingId.value = null
  const title = editValue.value.trim()
  const item = favorites.items.find(candidate => candidate.id === id)
  if (item && title && title !== item.title) {
    try { await favorites.rename(id, title.slice(0, 190)) } catch { toast.error(t('favorites.save_failed')) }
  }
  await nextTick()
  list.value?.focus()
}

function cancelRename(): void {
  editingId.value = null
  void nextTick(() => list.value?.focus())
}

function onKey(event: KeyboardEvent): void {
  if (event.isComposing) return
  if (event.key === 'Escape') {
    event.preventDefault()
    event.stopPropagation()
    if (editingId.value !== null) cancelRename()
    else close()
    return
  }
  if (event.key === 'Tab') {
    const elements = [...(dialog.value?.querySelectorAll<HTMLElement>('button, input, [tabindex="0"]') ?? [])]
    const first = elements[0]
    const last = elements.at(-1)
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault()
      last?.focus()
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault()
      first?.focus()
    }
    return
  }
  if (editingId.value !== null || event.target instanceof HTMLInputElement) return
  const count = favorites.items.length
  if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key) && !event.altKey && !event.ctrlKey && !event.metaKey) {
    event.preventDefault()
    event.stopPropagation()
    if (!count) return
    if (event.key === 'Home') selected.value = 0
    else if (event.key === 'End') selected.value = count - 1
    else selected.value = (selected.value + (event.key === 'ArrowDown' ? 1 : -1) + count) % count
    void nextTick(() => dialog.value?.querySelector('[data-selected="true"]')?.scrollIntoView({ block: 'nearest' }))
  } else if (event.key === 'Enter' && event.target === list.value) {
    const item = favorites.items[selected.value]
    if (!item) return
    event.preventDefault()
    event.stopPropagation()
    void openItem(item, event.shiftKey)
  } else if (event.key === 'Delete' && !event.altKey && !event.ctrlKey && !event.metaKey) {
    const item = favorites.items[selected.value]
    if (!item) return
    event.preventDefault()
    event.stopPropagation()
    void removeItem(item)
  } else if (event.key === 'F2') {
    const item = favorites.items[selected.value]
    if (!item) return
    event.preventDefault()
    event.stopPropagation()
    void startRename(item)
  }
}

watch(() => favorites.open, async open => {
  if (open) {
    workspace.windowSwitcherOpen = false
    returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null
    selected.value = 0
    editingId.value = null
    lockBodyScroll()
    locked = true
    void favorites.load()
    await nextTick()
    // Na dotyku fokus nepotřebuje nikdo a zvýrazněný seznam jen ruší.
    if (!window.matchMedia?.('(pointer: coarse)').matches) list.value?.focus()
  } else {
    if (locked) unlockBodyScroll()
    locked = false
    if (returnFocus?.isConnected) returnFocus.focus({ preventScroll: true })
  }
})
watch(() => workspace.windowSwitcherOpen, open => { if (open) favorites.open = false })
onBeforeUnmount(() => { if (locked) unlockBodyScroll() })
</script>

<template>
  <Teleport to="body">
    <div v-if="favorites.open" class="fixed inset-0 z-[70] flex items-start justify-center bg-neutral-900/45 px-4 pt-[12vh] backdrop-blur-[3px]" @click.self="close">
      <section ref="dialog" class="flex max-h-[76dvh] w-full max-w-xl flex-col overflow-hidden rounded-xl border border-neutral-200 bg-surface-raised shadow-2xl"
               role="dialog" aria-modal="true" :aria-label="t('favorites.title')" @keydown="onKey">
        <header class="flex items-center justify-between gap-3 border-b border-neutral-200 px-5 py-3">
          <h2 class="font-semibold text-neutral-900">{{ t('favorites.title') }} <span class="ml-1 text-xs font-normal text-neutral-500">{{ favorites.items.length }}/{{ MAX_FAVORITES }}</span></h2>
          <button type="button" class="inline-flex h-7 w-7 cursor-pointer items-center justify-center rounded-md text-neutral-500 hover:bg-neutral-100" :aria-label="t('common.close')" @click="close">
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15" /></svg>
          </button>
        </header>
        <div class="flex flex-wrap items-center gap-2 px-4 py-3">
          <button type="button" data-favorite-toggle
                  class="inline-flex cursor-pointer items-center gap-2 whitespace-nowrap rounded-lg border px-3 py-2 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-primary-400 disabled:cursor-not-allowed disabled:opacity-50"
                  :class="currentFavorite ? 'border-warning-500 bg-warning-50 text-warning-700 hover:bg-warning-100' : 'border-primary-600 bg-primary-600 text-white hover:bg-primary-700'"
                  :disabled="busy || !currentPath || (!currentFavorite && limitReached)" @click="toggleCurrent">
            <svg class="h-4 w-4" viewBox="0 0 24 24" :fill="currentFavorite ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true">
              <path d="M12 3.5l2.6 5.3 5.9.85-4.25 4.15 1 5.85L12 16.9l-5.25 2.75 1-5.85L3.5 9.65l5.9-.85L12 3.5z" />
            </svg>
            {{ currentFavorite ? t('favorites.remove_current') : t('favorites.add_current') }}
          </button>
          <span v-if="!currentFavorite && limitReached" class="text-xs text-neutral-500">{{ t('favorites.limit_reached', { max: MAX_FAVORITES }) }}</span>
        </div>
        <div ref="list" id="workspace-favorites-list" class="min-h-0 overflow-y-auto px-2 pb-2 outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-300" role="listbox" tabindex="0"
             :aria-label="t('favorites.title')" :aria-activedescendant="favorites.items[selected] ? `favorite-option-${favorites.items[selected]!.id}` : undefined">
          <div v-for="(item, index) in favorites.items" :key="item.id" class="group relative">
            <template v-if="editingId === item.id">
              <div class="flex items-center gap-2 rounded-lg bg-primary-50 px-3 py-2.5 ring-1 ring-inset ring-primary-200">
                <input :ref="(el) => { renameInput = el as HTMLInputElement | null }" v-model="editValue" maxlength="190" :aria-label="t('favorites.rename')" autocomplete="off"
                       class="w-full rounded-md border border-neutral-200 bg-surface px-2 py-1.5 text-sm outline-none focus:border-primary-400 focus:ring-2 focus:ring-primary-100"
                       @keydown.enter.prevent.stop="commitRename" @blur="commitRename">
              </div>
            </template>
            <template v-else>
              <button :id="`favorite-option-${item.id}`" type="button" tabindex="-1"
                      class="flex w-full cursor-pointer items-center gap-3 rounded-lg py-2.5 pl-3 pr-20 text-left transition-colors hover:bg-primary-50"
                      :class="index === selected ? 'bg-primary-50 ring-1 ring-inset ring-primary-200' : ''"
                      :data-selected="index === selected" role="option" :aria-selected="index === selected"
                      @mouseenter="selected = index" @click="openItem(item, $event.shiftKey)">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg" :class="isCurrent(item) ? 'bg-warning-100 text-warning-600' : 'bg-neutral-100 text-neutral-500'" aria-hidden="true">
                  <svg class="h-4 w-4" viewBox="0 0 24 24" :fill="isCurrent(item) ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"><path d="M12 3.5l2.6 5.3 5.9.85-4.25 4.15 1 5.85L12 16.9l-5.25 2.75 1-5.85L3.5 9.65l5.9-.85L12 3.5z" /></svg>
                </span>
                <span class="min-w-0 flex-1">
                  <span class="block truncate text-sm font-medium text-neutral-900">{{ item.title }}</span>
                  <span class="block truncate text-xs text-neutral-500">{{ item.path === '/' ? t('workspace.homepage') : item.path }}</span>
                  <span v-if="item.supplier_id !== null" class="block truncate text-xs" :class="isAvailable(item) ? 'text-primary-600' : 'text-danger-600'">{{ isAvailable(item) ? companyName(item) : t('favorites.company_unavailable') }}</span>
                </span>
              </button>
              <div class="absolute right-2 top-1/2 flex -translate-y-1/2 items-center gap-0.5">
                <button type="button" class="inline-flex h-7 w-7 cursor-pointer items-center justify-center rounded-md text-neutral-400 hover:bg-neutral-100 hover:text-primary-700 focus-visible:ring-2 focus-visible:ring-primary-400"
                        :title="t('favorites.rename')" :aria-label="t('favorites.rename_named', { title: item.title })" data-rename-favorite @click.stop="startRename(item)">
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L19 9a2.1 2.1 0 0 0-4-4L4 16v4zM13.5 6.5l4 4" /></svg>
                </button>
                <button type="button" class="inline-flex h-7 w-7 cursor-pointer items-center justify-center rounded-md text-neutral-400 hover:bg-danger-50 hover:text-danger-600 focus-visible:ring-2 focus-visible:ring-primary-400"
                        :title="t('favorites.remove')" :aria-label="t('favorites.remove_named', { title: item.title })" data-remove-favorite @click.stop="removeItem(item)">
                  <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15" /></svg>
                </button>
              </div>
            </template>
          </div>
          <p v-if="!favorites.items.length" class="px-3 py-6 text-center text-sm text-neutral-500">{{ t('favorites.empty') }}</p>
        </div>
        <footer class="space-y-1.5 border-t pointer-coarse:hidden border-neutral-200 bg-neutral-50 px-5 py-3 text-xs text-neutral-500">
          <p data-favorites-company-hint>{{ t('favorites.company_hint') }}</p>
          <p>{{ t('favorites.keyboard_hint') }}</p>
        </footer>
      </section>
    </div>
  </Teleport>
</template>
