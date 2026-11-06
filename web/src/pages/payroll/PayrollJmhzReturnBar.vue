<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { btnOutline } from '@/components/ui/buttonStyles'
import { useJmhzReturn } from './jmhzFreezeBlockers'

/**
 * Tlačítko zpět na přípravu JMHZ. Ukáže se jen na stránce otevřené odkazem
 * z blokace zmrazení hlášení (query `return`).
 */
const { t } = useI18n()
const { returnTarget } = useJmhzReturn()
</script>

<template>
  <div v-if="returnTarget" class="flex flex-wrap gap-2" data-test="jmhz-return-bar">
    <RouterLink
      :to="returnTarget"
      :class="[btnOutline('primary'), 'whitespace-nowrap']"
      data-test="jmhz-return"
    >
      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path d="M10 19l-7-7m0 0l7-7m-7 7h18" />
      </svg>
      {{ t('payroll.jmhz_gate.return_to_jmhz') }}
    </RouterLink>
  </div>
</template>
