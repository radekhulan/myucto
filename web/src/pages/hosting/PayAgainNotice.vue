<script setup lang="ts">
/**
 * Nabídka doplacení, když doplatek nešlo strhnout z uložené karty.
 *
 * ⚠️ Odkaz vede na platbu TÉŽE objednávky na myucto.cz — proto se opakovaným
 * placením nic nezdvojí. Bez téhle nabídky by obrazovka po odmítnuté kartě
 * uměla poradit jen „zkuste to prosím znovu", což se stejnou kartou dopadne
 * pokaždé stejně; zákazník by se o doplatku dozvěděl jen z e-mailu.
 */
import { useI18n } from 'vue-i18n'
import { ICONS, btnFilled } from '@/components/ui/buttonStyles'

defineProps<{ href: string }>()

const { t } = useI18n()
</script>

<template>
  <div class="mt-3" data-hosting-pay-again>
    <a :href="href" target="_blank" rel="noopener" :class="btnFilled('success')">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.coin" /></svg>
      {{ t('hosting.pay_again') }}
    </a>
    <p class="mt-2 text-xs text-neutral-600">{{ t('hosting.pay_again_hint') }}</p>
  </div>
</template>
