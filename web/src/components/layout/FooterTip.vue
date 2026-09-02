<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { defaultShortcutFor, formatShortcut } from '@/composables/useKeyboardShortcuts'
import { isApplePlatform } from '@/utils/clientPlatform'

/**
 * Tip v patičce.
 *
 * Why: nová funkce, na kterou nevede tlačítko (klávesová zkratka, přetahování
 * menu), je pro uživatele neviditelná — nemá se jak dozvědět, že existuje.
 *
 * Tipy se ale musí umět odmlčet, jinak z nich je šum:
 *  - tip zmizí ze seznamu, jakmile uživatel funkci OPRAVDU použil
 *    (`markTipUsed()` volá kód té funkce, ne časovač),
 *  - v rámci jedné návštěvy se tip nemění, aby patička neblikala,
 *  - křížkem se dají vypnout natrvalo.
 */

const STORAGE_USED = 'myinvoice.tips.used'
const STORAGE_OFF = 'myinvoice.tips.off'

/** Klíče musí sedět s `command.tip_*` v i18n. */
const TIPS = [
  'palette',
  'search',
  'filters',
  'columns',
  'saved_filters',
  'theme',
  'menu_layout',
  'menu_drag',
  'quick_new',
  'row_click',
] as const

const { t } = useI18n()
const applePlatform = isApplePlatform()

const dismissed = ref(false)
const index = ref(0)
const used = ref<string[]>([])

const available = computed(() => TIPS.filter(k => !(applePlatform && k === 'search') && !used.value.includes(k)))
const current = computed(() => available.value[index.value % Math.max(1, available.value.length)] ?? null)
const visible = computed(() => !dismissed.value && available.value.length > 0)
const currentText = computed(() => {
  if (!current.value) return ''
  if (current.value === 'palette') {
    return t('command.tip_palette', { shortcut: formatShortcut('ctrl+k') })
  }
  if (current.value === 'search') {
    return t('command.tip_search', { shortcut: formatShortcut(defaultShortcutFor('search.global')) })
  }
  return t(`command.tip_${current.value}`)
})

function readUsed(): string[] {
  try {
    const raw = localStorage.getItem(STORAGE_USED)
    return raw ? (JSON.parse(raw) as string[]) : []
  } catch {
    return []
  }
}

function next() {
  if (available.value.length > 1) index.value = (index.value + 1) % available.value.length
}

function dismiss() {
  dismissed.value = true
  try { localStorage.setItem(STORAGE_OFF, '1') } catch { /* soukromý režim — jen se tip příště ukáže znovu */ }
}

onMounted(() => {
  try { dismissed.value = localStorage.getItem(STORAGE_OFF) === '1' } catch { /* viz výš */ }
  used.value = readUsed()
  // Náhodný start, ale v rámci návštěvy stabilní — jinak by se tip měnil při
  // každém překreslení patičky.
  index.value = Math.floor(Math.random() * Math.max(1, available.value.length))
})
</script>

<template>
  <div v-if="visible && current" class="flex min-w-0 items-center gap-1.5 text-[11px] leading-none text-neutral-500">
    <svg class="h-3.5 w-3.5 shrink-0 text-warning-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
      <path stroke-linecap="round" stroke-linejoin="round" d="M9 18h6m-5 3h4M12 3a6 6 0 0 0-3.6 10.8c.4.3.6.8.6 1.2h6c0-.4.2-.9.6-1.2A6 6 0 0 0 12 3z" />
    </svg>
    <button type="button" class="cursor-pointer truncate hover:text-neutral-700 transition-colors" :title="t('command.tip_next')" @click="next">
      {{ currentText }}
    </button>
    <button type="button" class="cursor-pointer shrink-0 rounded p-0.5 opacity-50 hover:opacity-100 transition-opacity" :title="t('command.tip_dismiss')" @click="dismiss">
      <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
      </svg>
    </button>
  </div>
</template>
