<script setup lang="ts">
/**
 * Spodní lišta telefonu: Přehled, Prodej, Nákup, Peníze a Menu. Agendy otevírají
 * nabídku s velkými dlaždicemi ({@link MobileNavSheet}), Menu celé postranní menu.
 * Lišta jen hlásí volbu, co se otevře, řídí AppLayout.
 */
export interface MobileTab {
  key: string
  label: string
  icon: string
  /** Uživatel je právě v agendě této záložky. */
  current: boolean
  /** Nabídka záložky je otevřená. */
  open: boolean
  badge?: number
}

defineProps<{ tabs: MobileTab[]; label: string }>()
const emit = defineEmits<{ select: [key: string] }>()
</script>

<template>
  <nav class="grid h-16 auto-cols-fr grid-flow-col px-1" :aria-label="label" data-testid="mobile-tab-bar">
    <button
      v-for="tab in tabs"
      :key="tab.key"
      type="button"
      class="group relative flex min-w-0 cursor-pointer flex-col items-center justify-center gap-1 rounded-lg text-[11px] font-medium leading-none transition-colors active:bg-neutral-100"
      :class="tab.open || tab.current ? 'text-primary-700' : 'text-neutral-500'"
      :aria-current="tab.current ? 'page' : undefined"
      :aria-expanded="tab.key === 'home' ? undefined : tab.open"
      :data-tab="tab.key"
      @click="emit('select', tab.key)"
    >
      <span
        class="relative inline-flex h-8 w-14 items-center justify-center rounded-full transition-colors"
        :class="tab.open ? 'bg-primary-100' : tab.current ? 'bg-primary-50' : ''"
      >
        <svg class="h-[22px] w-[22px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" :d="tab.icon" />
        </svg>
        <span
          v-if="tab.badge"
          class="absolute -right-0.5 -top-1 min-w-[18px] rounded-full bg-danger-500 px-1 py-0.5 text-center text-[10px] font-semibold leading-none text-white"
        >{{ tab.badge > 99 ? '99+' : tab.badge }}</span>
      </span>
      <span class="max-w-full truncate px-0.5">{{ tab.label }}</span>
    </button>
  </nav>
</template>
