<script setup lang="ts">
import { computed, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useI18n } from 'vue-i18n'
import { bankApi, type NoticeConfirmationSummary as Summary } from '@/api/bank'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import Modal from '@/components/ui/Modal.vue'
import NoticeConfirmationSummary from './NoticeConfirmationSummary.vue'
import { btnOutline, btnFilled, ICONS } from '@/components/ui/buttonStyles'
const props = defineProps<{ statementId: number }>()
const emit = defineEmits<{ changed: [] }>()
const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const canConfirm = computed(() => !auth.isClientRole && auth.canWrite('bank.match'))
const open = ref(false)
const busy = ref(false)
const preview = ref<Summary | null>(null)
const processed = ref(0)
const ready = ref(0)
async function run(dryRun: boolean) {
  if (!canConfirm.value || busy.value) return
  open.value = true
  busy.value = true
  preview.value = null
  processed.value = 0
  ready.value = 0
  const summary: Summary = { pending: 0, review: 0, confirmed: 0, total: 0 }
  const statementId = props.statementId
  let after: number | undefined
  try {
    do {
      const batch = await bankApi.noticeConfirmations(statementId, { dry_run: dryRun, limit: 100, after_id: after })
      processed.value += batch.processed
      if (dryRun && batch.proposed_summary) {
        for (const key of ['pending', 'review', 'confirmed', 'total'] as const) summary[key] += batch.proposed_summary[key]
        ready.value += (batch.items ?? []).filter(item => item.proposed_reason === 'ready').length
      } else {
        Object.assign(summary, batch.confirmation_summary)
        ready.value = (batch.items ?? []).filter(item => item.confirmation_reason === 'ready').length
      }
      if (!batch.has_more) break
      if (batch.next_after_id === null || batch.next_after_id === after) throw new Error(t('bank.notice_confirmation.batch_stalled'))
      after = batch.next_after_id
    } while (true)
    preview.value = summary
    if (!dryRun) { emit('changed'); open.value = false; toast.success(t('bank.notice_confirmation.checked')) }
  } catch (error) { toast.error(apiErrorMessage(error)); if (!dryRun) emit('changed') }
  finally { busy.value = false }
}
</script>
<template>
  <button v-if="canConfirm" type="button" :class="btnOutline('primary')" :disabled="busy" @click="run(true)"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>{{ t('bank.notice_confirmation.check') }}</button>
  <Modal v-if="canConfirm && open" :title="t('bank.notice_confirmation.preview_title')" @close="!busy && (open = false)">
    <p class="text-sm mb-4">{{ t('bank.notice_confirmation.preview_hint') }}</p>
    <p v-if="busy" role="status" class="mb-3">{{ t('bank.notice_confirmation.progress', { count: processed }) }}</p>
    <NoticeConfirmationSummary v-if="preview" :summary="preview" />
    <p v-if="preview" class="mt-3 text-sm">{{ t('bank.notice_confirmation.ready_count', { count: ready }) }}</p>
    <template #footer><div class="flex flex-wrap justify-end gap-2">
      <button type="button" :class="btnOutline('neutral')" :disabled="busy" @click="open = false"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path :d="ICONS.x" /></svg>{{ t('common.cancel') }}</button>
      <button type="button" :class="btnFilled('success')" :disabled="busy || !preview" @click="run(false)"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path :d="ICONS.check" /></svg>{{ t('bank.notice_confirmation.apply') }}</button>
    </div></template>
  </Modal>
</template>
