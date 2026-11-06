<script setup lang="ts">
import { computed, onActivated, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import J6AssessmentWorkspace from '@/components/accounting/nonprofitTax/J6AssessmentWorkspace.vue'

const route = useRoute()
const auth = useAuthStore()
const supplier = useSupplierStore()
const periodId = computed(() => Number(route.params.id))
const supplierId = computed(() => supplier.currentSupplierId)
const reloadKey = ref(0)
const canManage = computed(() => auth.can('accounting.periods.close', 'write'))
let activationCount = 0

watch([periodId, supplierId], () => { reloadKey.value += 1 })
onActivated(() => { if (activationCount++ > 0) reloadKey.value += 1 })
</script>

<template>
  <J6AssessmentWorkspace
    v-if="periodId > 0 && supplierId > 0"
    :period-id="periodId"
    :supplier-id="supplierId"
    :can-manage="canManage"
    :reload-key="reloadKey"
  />
</template>
