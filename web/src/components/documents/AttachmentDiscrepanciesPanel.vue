<script setup lang="ts">
/**
 * Rozpory dokladů s přílohami za celou firmu — včetně dokladů, které vznikly AI
 * importem PDF a v žádné dávce skenů nejsou. Čte uložené výsledky; tlačítko
 * Přepočítat je obnoví nad aktuálními doklady a vytěženími.
 */
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AttachmentDiscrepancyTable from './AttachmentDiscrepancyTable.vue'
import AttachmentCheckReview from './AttachmentCheckReview.vue'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import {
  attachmentChecksApi,
  type AttachmentCheckRow,
  type AttachmentListState,
} from '@/api/attachmentChecks'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'

const props = withDefaults(defineProps<{ canRecheck?: boolean }>(), { canRecheck: false })

const { t } = useI18n()
const toast = useToast()

const FILTERS: AttachmentListState[] = ['open', 'acknowledged', 'all']
const state = ref<AttachmentListState>('open')
const rows = ref<AttachmentCheckRow[]>([])
const total = ref(0)
const loading = ref(false)
const rechecking = ref(false)
const error = ref('')
const review = ref<AttachmentCheckRow | null>(null)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const r = await attachmentChecksApi.list(state.value)
    rows.value = r.rows
    total.value = r.total
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

async function recheck() {
  rechecking.value = true
  try {
    const r = await attachmentChecksApi.recheck()
    toast.success(t('attachment_check.recheck_done', { checked: r.checked, open: r.open }))
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    rechecking.value = false
  }
}

function setState(s: AttachmentListState) {
  state.value = s
  void load()
}

onMounted(load)
defineExpose({ reload: load })
</script>

<template>
  <div id="attachment-discrepancies" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5" data-testid="attachment-discrepancies-panel">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-2">
      <h2 class="font-medium text-neutral-900">{{ t('attachment_check.panel_title') }}</h2>
      <div class="flex flex-wrap items-center gap-2">
        <div class="inline-flex flex-wrap rounded-md border border-neutral-200 overflow-hidden text-sm">
          <button v-for="f in FILTERS" :key="f" type="button" class="px-3 py-1 whitespace-nowrap cursor-pointer"
            :class="state === f ? 'bg-primary-50 text-primary-700 font-medium' : 'text-neutral-600 hover:bg-neutral-50'"
            @click="setState(f)">{{ t('attachment_check.filter.' + f) }}</button>
        </div>
        <button v-if="props.canRecheck" type="button" :class="btnOutlineSm('neutral')" class="whitespace-nowrap"
          :disabled="rechecking" :title="t('attachment_check.recheck_hint')" @click="recheck">
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
          {{ t('attachment_check.recheck') }}
        </button>
      </div>
    </div>
    <p class="text-sm text-neutral-500 mb-3">{{ t('attachment_check.panel_hint') }}</p>

    <div v-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm mb-3">{{ error }}</div>
    <p v-if="loading" class="text-sm text-neutral-400">{{ t('attachment_check.loading') }}</p>
    <p v-else-if="rows.length === 0" class="text-sm text-neutral-500">{{ state === 'open' ? t('attachment_check.empty_open') : t('attachment_check.empty') }}</p>
    <template v-else>
      <p v-if="total > rows.length" class="text-xs text-neutral-500 mb-2">{{ t('attachment_check.list_limited', { n: rows.length, total }) }}</p>
      <AttachmentDiscrepancyTable :rows="rows" @review="review = $event" />
    </template>

    <AttachmentCheckReview v-if="review" :entity-type="review.entity_type" :entity-id="review.entity_id"
      @close="review = null" @changed="load" />
  </div>
</template>
