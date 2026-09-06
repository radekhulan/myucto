<script setup lang="ts">
import ExpenseRules from '@/pages/accounting/ExpenseRules.vue'

/**
 * Tenký obal nad stránkou Pravidla nákladů, aby se z detailu dokladu otevřel TÝŽ
 * formulář, jaký je na /templates — druhý formulář by se s ním rozešel při první
 * změně kritérií. S `ruleId` se komponenta chová jako čistý editační modal nad
 * konkrétním pravidlem (načte si ho sama), bez něj zakládá nové z dodavatele.
 */
defineProps<{
  vendorId: number
  vendorName?: string | null
  initialDescription?: string | null
  ruleId?: number | null
}>()
const emit = defineEmits<{ close: []; saved: [] }>()
</script>

<template>
  <ExpenseRules
    :initial-draft="{ vendorId, vendorName, description: initialDescription, ruleId: ruleId ?? undefined }"
    @close="emit('close')" @saved="emit('saved')" />
</template>
