<script setup lang="ts">
import ExpenseRules from '@/pages/accounting/ExpenseRules.vue'

defineProps<{ vendorId: number; vendorName?: string | null; initialDescription?: string | null }>()
const emit = defineEmits<{ close: []; saved: [] }>()
</script>

<template>
  <ExpenseRules :initial-draft="{ vendorId, vendorName, description: initialDescription }"
    @close="emit('close')" @saved="emit('saved')" />
</template>
