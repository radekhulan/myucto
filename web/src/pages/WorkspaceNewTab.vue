<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import GlobalSearch from '@/components/layout/GlobalSearch.vue'
import { formatShortcut, useKeyboardShortcuts } from '@/composables/useKeyboardShortcuts'
import { useFavoritesStore } from '@/stores/favorites'
import { useSupplierStore } from '@/stores/supplier'
import { selectOpenTab, workspaceMenuItems, workspaceOpenTabs } from '@/workspace/newTabData'

const { t } = useI18n()
const shortcuts = useKeyboardShortcuts()
const favorites = useFavoritesStore()
const supplier = useSupplierStore()
const search = ref<InstanceType<typeof GlobalSearch> | null>(null)

/** Oblíbené jiné firmy by záložku přepnuly do jiné firmy; tady nabízíme jen ty aktuální. */
const companyFavorites = computed(() => favorites.items
  .filter(item => item.supplier_id === null || item.supplier_id === supplier.currentSupplierId)
  .slice(0, 12))

const hints = computed(() => [
  { keys: '↑ ↓', label: t('workspace.new_tab_hint_select') },
  { keys: 'Enter', label: t('workspace.new_tab_hint_open') },
  { keys: formatShortcut(shortcuts.comboFor('windows.switcher')), label: t('workspace.new_tab_windows') },
].filter(hint => hint.keys))

let focusTimer: ReturnType<typeof setTimeout> | undefined

/**
 * Kurzor patří do hledání i po návratu do záložky. Při otevření je okno chvíli
 * skryté a fokus nepřijme, proto se pokus krátce opakuje.
 */
function focusSearch(attempt = 0): void {
  clearTimeout(focusTimer)
  if (document.activeElement && document.activeElement !== document.body) return
  search.value?.focus()
  if ((!document.activeElement || document.activeElement === document.body) && attempt < 20) {
    focusTimer = setTimeout(() => focusSearch(attempt + 1), 50)
  }
}

function onWindowFocus(): void { focusSearch() }

onMounted(() => {
  void favorites.load()
  window.addEventListener('focus', onWindowFocus)
  focusSearch()
})
onBeforeUnmount(() => {
  clearTimeout(focusTimer)
  window.removeEventListener('focus', onWindowFocus)
})
</script>

<template>
  <div class="flex justify-center px-4 pb-12 pt-[12vh] sm:px-6" data-workspace-new-tab>
    <div class="w-full max-w-2xl">
      <div class="mb-6 flex items-center gap-3">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center bg-primary-50 text-primary-600 ring-1 ring-inset ring-primary-100" aria-hidden="true">
          <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path d="M3 20V7h7l2 3h9v10z" stroke-linejoin="round" />
            <path d="M12 12.5v5M9.5 15h5" stroke-linecap="round" />
          </svg>
        </span>
        <div class="min-w-0">
          <h1 class="text-xl font-semibold text-neutral-900">{{ t('workspace.new_tab_title') }}</h1>
          <p class="text-sm text-neutral-500">{{ t('workspace.new_tab_hint') }}</p>
        </div>
      </div>

      <GlobalSearch ref="search" :menu-items="workspaceMenuItems" large autofocus />

      <ul v-if="hints.length" class="mt-3 flex flex-wrap gap-x-5 gap-y-1.5 text-xs text-neutral-500 pointer-coarse:hidden">
        <li v-for="hint in hints" :key="hint.label" class="inline-flex items-center gap-1.5">
          <kbd class="border border-neutral-200 bg-surface px-1.5 py-0.5 font-sans text-[11px] font-medium text-neutral-600">{{ hint.keys }}</kbd>
          {{ hint.label }}
        </li>
      </ul>

      <section v-if="companyFavorites.length" class="mt-10" :aria-label="t('workspace.new_tab_favorites')">
        <h2 class="mb-2.5 text-[11px] font-semibold uppercase tracking-wider text-neutral-400">{{ t('workspace.new_tab_favorites') }}</h2>
        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
          <a
            v-for="item in companyFavorites"
            :key="item.id"
            :href="item.path"
            class="group flex min-w-0 items-center gap-2.5 border border-neutral-200 bg-surface px-3 py-2.5 text-sm text-neutral-700 transition-colors hover:border-primary-300 hover:bg-primary-50/50 hover:text-primary-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-400"
            data-new-tab-favorite
          >
            <svg class="h-4 w-4 shrink-0 text-warning-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
              <path d="M10 2.5l2.3 4.7 5.2.8-3.8 3.6.9 5.2L10 14.4l-4.6 2.4.9-5.2L2.5 8l5.2-.8z" />
            </svg>
            <span class="min-w-0 flex-1 truncate">{{ item.title }}</span>
          </a>
        </div>
      </section>

      <section v-if="workspaceOpenTabs.length" class="mt-8" :aria-label="t('workspace.new_tab_open_tabs')">
        <h2 class="mb-2.5 text-[11px] font-semibold uppercase tracking-wider text-neutral-400">{{ t('workspace.new_tab_open_tabs') }}</h2>
        <div class="divide-y divide-neutral-100 border border-neutral-200 bg-surface">
          <button
            v-for="tab in workspaceOpenTabs"
            :key="tab.windowId"
            type="button"
            class="flex w-full min-w-0 cursor-pointer items-center gap-3 px-3 py-2.5 text-left transition-colors hover:bg-primary-50/50 focus-visible:bg-primary-50 focus-visible:outline-none"
            data-new-tab-open-tab
            @click="selectOpenTab(tab.windowId)"
          >
            <svg class="h-4 w-4 shrink-0 text-neutral-400" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
              <path d="M2.5 16.5V5.5h5l1.5 2h8.5v9z" stroke-linejoin="round" />
            </svg>
            <span class="min-w-0 flex-1">
              <span class="block truncate text-sm font-medium text-neutral-800">{{ tab.title }}</span>
              <span v-if="tab.subtitle || tab.supplierName" class="block truncate text-xs text-neutral-500">{{ [tab.subtitle, tab.supplierName].filter(Boolean).join(' · ') }}</span>
            </span>
            <span v-if="tab.windows > 1" class="shrink-0 bg-neutral-100 px-1.5 py-0.5 text-[11px] font-medium text-neutral-600">{{ t('workspace.new_tab_windows_count', { count: tab.windows }) }}</span>
            <svg class="h-4 w-4 shrink-0 text-neutral-300" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m8 5 5 5-5 5" stroke-linecap="round" stroke-linejoin="round" /></svg>
          </button>
        </div>
      </section>
    </div>
  </div>
</template>
