<script setup lang="ts">
/**
 * Porovnání dokladu s vytěženými přílohami a potvrzení „v pořádku" s důvodem.
 *
 * Potvrzení platí k aktuálním hodnotám dokladu i vytěžení — když se kterákoli
 * změní, rozdíl se znovu ukáže (s dřívějším důvodem jako kontextem).
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import Modal from '@/components/ui/Modal.vue'
import { btnFilledSm, ICONS } from '@/components/ui/buttonStyles'
import {
  attachmentChecksApi,
  type AttachmentCheckResult,
  type AttachmentCheckRow,
  type AttachmentEntityType,
} from '@/api/attachmentChecks'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'

const props = withDefaults(defineProps<{
  entityType: AttachmentEntityType
  entityId: number
  initial?: AttachmentCheckResult | null
  canAcknowledge?: boolean
}>(), { initial: null, canAcknowledge: true })

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'changed', result: AttachmentCheckResult): void
}>()

const { t, locale } = useI18n()
const toast = useToast()

const result = ref<AttachmentCheckResult | null>(props.initial)
const loading = ref(false)
const error = ref('')
const reasons = ref<Record<string, string>>({})
const saving = ref<string | null>(null)

const rows = computed(() => result.value?.rows ?? [])
const hasVatWarning = computed(() => rows.value.some(r => r.open && r.severity === 'warning'))

async function load() {
  loading.value = true
  error.value = ''
  try {
    result.value = await attachmentChecksApi.forEntity(props.entityType, props.entityId)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  if (!props.initial) void load()
})

function fmt(v: unknown, field: string): string {
  if (v === null || v === undefined || v === '') return '—'
  const loc = locale.value === 'en' ? 'en-GB' : 'cs-CZ'
  if (field === 'amount' && typeof v === 'number') {
    return new Intl.NumberFormat(loc, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v)
  }
  if (typeof v === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(v)) {
    const d = new Date(v + 'T00:00:00')
    return Number.isNaN(d.getTime()) ? v : d.toLocaleDateString(loc)
  }
  return String(v)
}

async function acknowledge(row: AttachmentCheckRow) {
  const reason = (reasons.value[row.sha256] ?? '').trim()
  if (reason.length < 3) {
    toast.error(t('attachment_check.reason_required'))
    return
  }
  saving.value = row.sha256
  try {
    result.value = await attachmentChecksApi.acknowledge(props.entityType, props.entityId, row.sha256, reason)
    toast.success(t('attachment_check.acknowledged'))
    emit('changed', result.value)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    saving.value = null
  }
}
</script>

<template>
  <Modal :title="t('attachment_check.modal_title')" width-class="max-w-3xl" @close="emit('close')">
    <div class="space-y-3" data-testid="attachment-check-review">
      <p v-if="loading" class="text-sm text-neutral-500">{{ t('attachment_check.loading') }}</p>
      <div v-else-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm">{{ error }}</div>

      <div v-if="hasVatWarning" class="rounded-md border border-warning-500/40 bg-warning-50 px-3 py-2 text-sm text-warning-700">
        {{ t('attachment_check.vat_hint') }}
      </div>

      <div v-for="row in rows" :key="row.sha256" class="rounded-md border border-neutral-200 p-3 space-y-2">
        <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
          <div class="min-w-0">
            <span class="text-neutral-500 mr-1">{{ t('attachment_check.attachment') }}:</span>
            <RouterLink v-if="row.document_id" :to="`/documents/${row.document_id}`" class="text-primary-600 hover:underline break-all">
              {{ row.document_name || '#' + row.document_id }}
            </RouterLink>
            <span v-else>{{ t('attachment_check.pdf_slot') }}</span>
          </div>
          <span class="text-xs px-2 py-0.5 rounded whitespace-nowrap"
            :class="{
              'bg-success-50 text-success-600': row.status === 'match',
              'bg-neutral-100 text-neutral-600': row.status === 'skipped' || row.acknowledged,
              'bg-warning-50 text-warning-700': row.open && row.severity === 'warning',
              'bg-primary-50 text-primary-700': row.open && row.severity === 'info',
            }">
            {{ row.acknowledged ? t('attachment_check.badge_acknowledged') : t('attachment_check.status.' + row.status) }}
          </span>
        </div>

        <p v-if="row.status === 'match'" class="text-sm text-neutral-600">{{ t('attachment_check.match_text') }}</p>
        <p v-else-if="row.status === 'skipped'" class="text-sm text-neutral-500">{{ t('attachment_check.skipped_text') }}</p>

        <div v-if="row.findings.length" class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-neutral-500 border-b border-neutral-100">
                <th class="py-1.5 pr-3">{{ t('attachment_check.col_field') }}</th>
                <th class="py-1.5 pr-3">{{ t('attachment_check.col_doc') }}</th>
                <th class="py-1.5 pr-3">{{ t('attachment_check.col_attachment') }}</th>
                <th class="py-1.5"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="f in row.findings" :key="f.field" class="border-b border-neutral-50 last:border-0">
                <td class="py-1.5 pr-3">{{ t('attachment_check.field.' + f.field) }}</td>
                <td class="py-1.5 pr-3 font-mono whitespace-nowrap">{{ fmt(f.doc, f.field) }}</td>
                <td class="py-1.5 pr-3 font-mono whitespace-nowrap">{{ fmt(f.attachment, f.field) }}</td>
                <td class="py-1.5 text-right">
                  <span class="text-xs px-2 py-0.5 rounded whitespace-nowrap"
                    :class="f.severity === 'warning' ? 'bg-warning-50 text-warning-700' : 'bg-neutral-100 text-neutral-600'">
                    {{ t('attachment_check.severity.' + f.severity) }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <p v-if="row.acknowledged && row.ack_reason" class="text-sm text-neutral-600">
          {{ t('attachment_check.acknowledged_note', { reason: row.ack_reason }) }}
        </p>
        <p v-else-if="row.open && row.ack_reason" class="text-sm text-warning-700">
          {{ t('attachment_check.previous_ack', { reason: row.ack_reason }) }}
        </p>

        <div v-if="row.open && canAcknowledge" class="space-y-2 pt-1">
          <label class="block text-sm text-neutral-700" :for="`ack-${row.sha256}`">{{ t('attachment_check.reason_label') }}</label>
          <textarea :id="`ack-${row.sha256}`" v-model="reasons[row.sha256]" rows="2" maxlength="500"
            class="w-full border border-neutral-300 rounded-md px-2 py-1.5 text-sm"
            :placeholder="t('attachment_check.reason_placeholder')" data-testid="attachment-check-reason"></textarea>
          <div class="flex flex-wrap justify-end gap-2">
            <button type="button" :class="btnFilledSm('success')" class="whitespace-nowrap"
              :disabled="saving !== null" data-testid="attachment-check-acknowledge" @click="acknowledge(row)">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
              {{ t('attachment_check.acknowledge') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </Modal>
</template>
