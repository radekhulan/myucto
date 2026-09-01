<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'

/**
 * „Seznam je zúžený na jednoho člověka."
 *
 * Why: odkaz z karty zaměstnance předává zúžení query stringem. Bez viditelné
 * lišty vypadá agenda tak, že o tom člověku ví jediný záznam — a zpátky na celý
 * přehled by se uživatel dostal jen ručním smazáním adresy. Proto je zúžení
 * vidět a jde ho jedním kliknutím zrušit.
 */
defineProps<{
  /**
   * Koho se zúžení týká — jméno, ne id. Když je zúžení slepé a jméno se nemá
   * odkud vzít, posílá volající id (viz `missing`).
   */
  name: string
  /**
   * Server zúžení uplatnil a nezbylo nic. Tichý prázdný seznam je horší než
   * chyba — vypadá jako „ten člověk tu nic nemá", i když je zúžení jen slepé
   * (cizí nebo zaniklý vztah). Lišta to proto řekne větou a nabídne zrušení.
   */
  missing?: boolean
  /**
   * `name` je lidský popis, ne id. Bez příznaku hláška o slepém zúžení uvede
   * `name` jako číslo („č. 42"), protože volající, který jméno nemá odkud
   * vzít, posílá id vztahu.
   */
  named?: boolean
}>()

defineEmits<{ clear: [] }>()

const { t } = useI18n()
</script>

<template>
  <div
    class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-payroll-500/30 bg-payroll-50 px-3 py-2 text-sm text-neutral-700"
    data-test="payroll-focus-notice"
  >
    <span class="min-w-0">
      {{ missing
        ? t(named
          ? 'payroll.agendas.focus.missing_named'
          : 'payroll.agendas.focus.missing', { name })
        : t('payroll.agendas.focus.title', { name }) }}
    </span>
    <button
      type="button"
      :class="btnOutlineSm('neutral')"
      data-test="payroll-focus-clear"
      @click="$emit('clear')"
    >
      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
      {{ t('payroll.agendas.focus.clear') }}
    </button>
  </div>
</template>
