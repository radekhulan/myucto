<script setup lang="ts">
import { computed, onActivated, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { eetApi, type EetRecord } from '@/api/eet'
import { useAuthStore } from '@/stores/auth'
import { formatDateTime, formatMoney } from '@/composables/useFormat'
import EetRecordBadge from './EetRecordBadge.vue'

/**
 * Box „EET" na detailu pokladního dokladu: stav tržby z příjmového dokladu a její
 * případné opravy (storno dokladu). Bez práva `eet` nebo bez záznamu se nezobrazí.
 */
const props = defineProps<{ cashDocumentId: number; status: string }>()

const { t } = useI18n()
const auth = useAuthStore()
const records = ref<EetRecord[]>([])
const loaded = ref(false)
let generation = 0

async function load() {
  if (!auth.canRead('eet')) {
    loaded.value = true
    return
  }
  const gen = ++generation
  try {
    const r = await eetApi.records({ cash_document_id: props.cashDocumentId, limit: 20 })
    if (gen === generation) records.value = r.items
  } catch {
    if (gen === generation) records.value = []
  } finally {
    if (gen === generation) loaded.value = true
  }
}
onMounted(() => { void load() })
onActivated(() => { if (loaded.value) void load() })
watch(() => [props.cashDocumentId, props.status], () => { void load() })

// Od nejstaršího: tržba, pak její oprava.
const ordered = computed(() => [...records.value].sort((a, b) => a.record_id - b.record_id))
</script>

<template>
  <div v-if="ordered.length > 0" class="mt-3 pt-3 border-t border-neutral-200" data-test="cash-eet-box" @click.stop>
    <div class="text-neutral-500 text-xs uppercase tracking-wide mb-1">{{ t('eet.cash.title') }}</div>
    <ul class="space-y-1.5">
      <li v-for="r in ordered" :key="r.record_id" class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
        <span class="font-medium">{{ t(r.kind === 'correction' ? 'eet.cash.correction' : 'eet.cash.sale') }}</span>
        <span class="font-mono">{{ formatMoney(Number(r.total_amount ?? 0)) }}</span>
        <span v-if="r.sale_at" class="text-xs text-neutral-500">{{ formatDateTime(r.sale_at) }}</span>
        <EetRecordBadge :status="r.status" />
        <RouterLink :to="{ name: 'eet-record-detail', params: { id: r.record_id } }"
          class="text-primary-600 hover:text-primary-700 hover:underline whitespace-nowrap">
          {{ t('eet.cash.open_record') }}
        </RouterLink>
      </li>
    </ul>
    <p v-if="status === 'reversed' && ordered.every(r => r.kind !== 'correction')" class="mt-1 text-xs text-warning-700">
      {{ t('eet.cash.reversed_without_correction') }}
    </p>
  </div>
</template>
