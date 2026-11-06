<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatShortcut, useKeyboardShortcuts } from '@/composables/useKeyboardShortcuts'
import { useFavoritesStore } from '@/stores/favorites'
import { useSupplierStore } from '@/stores/supplier'
import { useWorkspaceStore } from '@/stores/workspace'

const { t } = useI18n()
const favorites = useFavoritesStore()
const workspace = useWorkspaceStore()
const supplier = useSupplierStore()
const shortcuts = useKeyboardShortcuts()

const isFavorite = computed(() => favorites.items.some(item => item.path === workspace.activeFullPath
  && (item.supplier_id === null || item.supplier_id === supplier.currentSupplierId)))

onMounted(() => { void favorites.load() })
</script>

<template>
  <button
    type="button"
    class="relative inline-flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-md text-neutral-600 transition-colors hover:bg-neutral-100 hover:text-primary-700 focus-visible:ring-2 focus-visible:ring-primary-400"
    :class="favorites.open ? 'bg-neutral-100 text-primary-700' : ''"
    :title="[t('favorites.title'), formatShortcut(shortcuts.comboFor('windows.favorites'))].filter(Boolean).join(' · ')"
    :aria-label="t('favorites.title')"
    :aria-expanded="favorites.open"
    aria-haspopup="dialog"
    data-favorites-button
    @click="favorites.open = !favorites.open"
  >
    <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" :fill="isFavorite ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" aria-hidden="true">
      <path d="M12 3.5l2.6 5.3 5.9.85-4.25 4.15 1 5.85L12 16.9l-5.25 2.75 1-5.85L3.5 9.65l5.9-.85L12 3.5z" />
    </svg>
  </button>
</template>
