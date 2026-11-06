<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useWorkspaceNavigation } from '@/composables/useWorkspaceNavigation'
import { useWorkspaceStore, type PaneCount } from '@/stores/workspace'

withDefaults(defineProps<{
  /** Jedno tlačítko s rozbalovacím výběrem 1/2/3 místo tří vedle sebe (úsporný režim lišty). */
  compact?: boolean
}>(), { compact: false })

const { t } = useI18n()
const workspace = useWorkspaceStore()
const navigation = useWorkspaceNavigation()
const open = ref(false)

function available(count: PaneCount): boolean {
  return count <= workspace.maximumPaneCount
}

function hint(count: PaneCount): string {
  return `${t(`workspace.layout_${count}`)} - ${t('workspace.layout_preserves_windows')}\n${t('workspace.layout_shift_click_hint')}`
}

function choose(count: PaneCount): void {
  open.value = false
  navigation.setPaneCount(count)
}
</script>

<template>
  <div v-if="!compact" class="hidden lg:flex h-8 items-center gap-0.5 rounded-lg bg-neutral-100/80 p-0.5 shadow-inner ring-1 ring-neutral-200/80"
       role="group" :aria-label="t('workspace.layout_label')">
    <button
      v-for="count in ([1, 2, 3] as PaneCount[])"
      :key="count"
      type="button"
      class="inline-flex h-7 w-8 cursor-pointer items-center justify-center rounded-md text-neutral-500 transition-all hover:bg-surface hover:text-neutral-800 disabled:cursor-not-allowed disabled:opacity-30"
      :class="workspace.paneCount === count ? 'bg-surface text-primary-700 shadow-sm ring-1 ring-primary-300/70' : ''"
      :aria-label="t(`workspace.layout_${count}`)"
      :title="hint(count)"
      :aria-pressed="workspace.paneCount === count"
      :disabled="!available(count)"
      @click="navigation.setPaneCount(count)"
    >
      <span class="flex h-3.5 w-5 overflow-hidden rounded-[2px] border border-current" aria-hidden="true">
        <span v-for="part in count" :key="part" class="min-w-0 flex-1 border-current not-first:border-l" />
      </span>
    </button>
  </div>

  <div v-else class="relative hidden lg:flex h-8 items-center rounded-lg bg-neutral-100/80 p-0.5 shadow-inner ring-1 ring-neutral-200/80"
       @keydown.esc="open = false">
    <button
      type="button"
      class="inline-flex h-7 cursor-pointer items-center justify-center gap-1 rounded-md bg-surface px-1.5 text-primary-700 shadow-sm ring-1 ring-primary-300/70"
      :aria-label="`${t('workspace.layout_label')}: ${t(`workspace.layout_${workspace.paneCount}`)}`"
      :title="`${t('workspace.layout_label')}: ${t(`workspace.layout_${workspace.paneCount}`)}`"
      :aria-expanded="open"
      aria-haspopup="menu"
      @click="open = !open"
    >
      <span class="flex h-3.5 w-5 overflow-hidden rounded-[2px] border border-current" aria-hidden="true">
        <span v-for="part in workspace.paneCount" :key="part" class="min-w-0 flex-1 border-current not-first:border-l" />
      </span>
      <svg class="h-3 w-3 shrink-0 transition-transform" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
      </svg>
    </button>
    <transition
      enter-active-class="transition duration-100 ease-out"
      enter-from-class="opacity-0 -translate-y-1"
      enter-to-class="opacity-100 translate-y-0"
      leave-active-class="transition duration-75 ease-in"
      leave-from-class="opacity-100 translate-y-0"
      leave-to-class="opacity-0 -translate-y-1"
    >
      <div
        v-if="open"
        class="absolute right-0 top-full z-40 mt-1 w-56 rounded-lg border border-neutral-200 bg-surface py-1 shadow-xl"
        role="menu"
        :aria-label="t('workspace.layout_label')"
      >
        <button
          v-for="count in ([1, 2, 3] as PaneCount[])"
          :key="count"
          type="button"
          class="flex w-full cursor-pointer items-center gap-2.5 px-3 py-2 text-left text-sm hover:bg-neutral-50 hover:text-primary-700 disabled:cursor-not-allowed disabled:opacity-40"
          :class="workspace.paneCount === count ? 'bg-primary-50 font-medium text-primary-700' : 'text-neutral-700'"
          role="menuitemradio"
          :aria-checked="workspace.paneCount === count"
          :title="hint(count)"
          :disabled="!available(count)"
          @click="choose(count)"
        >
          <span class="flex h-3.5 w-5 shrink-0 overflow-hidden rounded-[2px] border border-current" aria-hidden="true">
            <span v-for="part in count" :key="part" class="min-w-0 flex-1 border-current not-first:border-l" />
          </span>
          <span class="min-w-0 flex-1 truncate">{{ t(`workspace.layout_${count}`) }}</span>
          <span v-if="workspace.paneCount === count" aria-hidden="true">✓</span>
        </button>
      </div>
    </transition>
    <div v-if="open" class="fixed inset-0 z-10" aria-hidden="true" @click="open = false"></div>
  </div>
</template>
