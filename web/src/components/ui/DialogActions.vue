<script setup lang="ts">
import { computed } from 'vue'
import type { ActionItem } from './ActionBar.vue'
import { btnFilled, btnOutline, ICONS } from './buttonStyles'
import { useModalFooter } from './modalFooter'

/**
 * Tlačítka formuláře v okně ve tvaru patičky dialogů aplikace (Journal, AssetDetail): vpravo, nejdřív
 * vedlejší akce (Zrušit, Zavřít) jako obrys, hlavní akce poslední a plná. V okně se přesunou do jeho
 * patičky (provideModalFooter v Modal), mimo okno nebo s `inline` zůstanou na místě.
 * Akce popisuje stejný ActionItem jako ActionBar, takže formulář jen mění komponentu, ne svůj stav.
 */
const props = defineProps<{ actions: ActionItem[]; inline?: boolean }>()
const footer = useModalFooter()
const visible = computed(() => props.actions.filter(a => a.show === undefined || !!a.show))
const ordered = computed(() => [...visible.value.filter(a => a.tier !== 'primary'), ...visible.value.filter(a => a.tier === 'primary')])
/** Důvod zašednutí je vidět i bez najetí myší (dotyk, čtečka), stejně jako u ActionBar. */
const notes = computed(() => [...new Set(visible.value.filter(a => a.disabled && a.disabledReason).map(a => a.disabledReason as string))])
const classOf = (a: ActionItem) => [a.tier === 'primary' ? btnFilled(a.variant ?? 'primary') : btnOutline(a.variant ?? 'neutral'), 'whitespace-nowrap']
const target = computed(() => (props.inline ? null : footer.target.value))
</script>

<template>
  <Teleport :to="target" :disabled="!target" defer>
    <div class="flex flex-col items-end gap-1.5" data-section="dialog-actions">
      <div class="flex flex-wrap justify-end gap-2">
        <button v-for="a in ordered" :key="a.key" type="button" :class="classOf(a)" :disabled="a.disabled" :title="a.title || (a.disabled ? a.disabledReason : undefined)"
          :data-action="a.key" @click="a.run && a.run()">
          <svg v-if="a.icon" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS[a.icon]" />
          </svg>
          {{ a.loading ? '…' : a.label }}
        </button>
      </div>
      <p v-for="note in notes" :key="note" class="text-right text-xs text-neutral-500" data-test="action-disabled-reason">{{ note }}</p>
    </div>
  </Teleport>
</template>
