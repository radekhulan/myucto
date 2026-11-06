<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { PropertyObject } from '@/api/propertyRegistry'
import OpsTabBar from './OpsTabBar.vue'
import DebtsPanel from './DebtsPanel.vue'
import DebtRegistrationForm from './DebtRegistrationForm.vue'
import CreditsPanel from './CreditsPanel.vue'
import ReservationsPanel from './ReservationsPanel.vue'
import { useBusyScope } from './busy'
import { propertyUnitLabel } from './format'
import { inputClass } from './ui'
const props = defineProps<{ supplier: number; units: PropertyObject[]; canManage: boolean; canPost: boolean }>()
const { t } = useI18n()
const busy = useBusyScope().busy
const tab = ref('debts')
const unitId = ref(0)
const reloadKey = ref(0)
const notice = ref(false)
watch(() => props.supplier, () => { unitId.value = 0; tab.value = 'debts' })
function changed() { reloadKey.value++; notice.value = true }
const tabs = () => ['debts', ...(props.canManage ? ['register'] : []), 'credits', 'reservations'].map(key => ({ key, label: t(`propertyPayments.tabs.${key}`) }))
</script>

<template>
  <section class="space-y-3" data-section="debts-credits">
    <div class="flex flex-wrap items-end gap-3">
      <OpsTabBar v-model="tab" :tabs="tabs()" :disabled="busy" />
      <label class="min-w-48 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.alloc.unitFilter') }}<select v-model="unitId" :disabled="busy" :class="inputClass" data-field="ops-unit"><option :value="0">{{ t('propertyPayments.alloc.allUnits') }}</option><option v-for="u in units" :key="u.id" :value="u.id">{{ propertyUnitLabel(u, number => t('propertyPayments.unitNumber', { number })) }}</option></select></label>
    </div>
    <p v-if="notice" role="status" class="text-success-600">{{ t('propertyPayments.actionDone') }}</p>
    <DebtsPanel v-if="tab === 'debts'" :supplier="supplier" :unit-id="unitId" :reload-key="reloadKey" :can-manage="canManage" />
    <DebtRegistrationForm v-else-if="tab === 'register' && canManage" :supplier="supplier" :unit-id="unitId" :can-manage="canManage" @changed="changed" />
    <CreditsPanel v-else-if="tab === 'credits'" :supplier="supplier" :unit-id="unitId" :reload-key="reloadKey" :can-manage="canManage" :can-post="canPost" @changed="changed" />
    <ReservationsPanel v-else-if="tab === 'reservations'" :supplier="supplier" :unit-id="unitId" :reload-key="reloadKey" :can-manage="canManage" :can-post="canPost" @changed="changed" />
  </section>
</template>
