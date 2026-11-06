<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { internalDocumentsApi } from '@/api/internalDocuments'
import { documentsApi } from '@/api/documents'
import { aiErrorMessage, internalDocumentPayload, type AiInternalDocument } from '@/api/aiAssistant'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { useDemoMode } from '@/composables/useDemoMode'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline, disabledTitle, BTN_DISABLED_NOTE } from '@/components/ui/buttonStyles'

/**
 * Návrh interního dokladu od AI. Zakládá se existující cestou ručního interního dokladu
 * (POST /accounting/internal-documents, zaúčtování POST …/{id}/post) se stejnými právy
 * a kontrolami; návrh nemá vlastní endpoint. Nálezy serverové kontroly založení blokují.
 */
const props = withDefaults(defineProps<{
  doc: AiInternalDocument
  linkDocumentIds?: number[]
  /** Id dokladu, který z návrhu vznikl dřív (historie konverzace). */
  appliedId?: number | null
}>(), { linkDocumentIds: () => [], appliedId: null })

const emit = defineEmits<{ created: [documentId: number] }>()

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const { blockDemoMutation } = useDemoMode()

const busy = ref(false)
const createdId = ref<number | null>(props.appliedId ?? null)
watch(() => props.appliedId, v => { if (v) createdId.value = v })

const canWrite = computed(() => auth.canWrite('accounting.journal.write'))
const canPost = computed(() => canWrite.value && auth.canWrite('accounting.journal.post'))
const problems = computed(() => props.doc.problems ?? [])
const unknown = computed(() => props.doc.unknown_accounts ?? [])
const kindLabel = computed(() => {
  const key = `internal_documents.kind.${props.doc.kind}`
  const v = t(key)
  return v === key ? props.doc.kind : v
})

const blockReason = computed<string | null>(() => {
  if (!canWrite.value) return t('accounting.ai_assistant.internal_document.no_permission')
  if (!props.doc.postable || problems.value.length) return t('accounting.ai_assistant.internal_document.problems_reason')
  return null
})

async function create(post: boolean) {
  if (blockReason.value || busy.value || createdId.value) return
  if (blockDemoMutation()) return
  const confirmKey = post ? 'confirm_post' : 'confirm_draft'
  if (!window.confirm(t(`accounting.ai_assistant.internal_document.${confirmKey}`))) return
  busy.value = true
  try {
    const created = await internalDocumentsApi.create(internalDocumentPayload(props.doc))
    const newId = Number(created.id)
    createdId.value = newId
    emit('created', newId)
    const links = await Promise.allSettled(props.linkDocumentIds.map(id => documentsApi.addLink(id, 'internal_document', newId)))
    if (links.some(r => r.status === 'rejected')) toast.warning(t('accounting.ai_assistant.internal_document.link_failed'))
    if (!post) {
      toast.success(t('accounting.ai_assistant.internal_document.created'))
      return
    }
    try {
      const posted = await internalDocumentsApi.post(newId)
      toast.success(t('accounting.ai_assistant.internal_document.posted', { no: posted.document_no ?? `#${newId}` }))
    } catch (e) {
      toast.warning(t('accounting.ai_assistant.internal_document.post_failed', { message: aiErrorMessage(e, t('common.error')) }))
    }
  } catch (e) {
    toast.error(aiErrorMessage(e, t('common.error')))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="border border-neutral-200 rounded-lg bg-surface overflow-hidden" data-test="ai-internal-document-proposal">
    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 px-3 py-2 bg-neutral-50 border-b border-neutral-200">
      <span class="text-xs font-semibold uppercase tracking-wide whitespace-nowrap text-primary-700">{{ kindLabel }}</span>
      <span class="text-sm text-neutral-800 font-medium min-w-0 break-words">{{ doc.title || '—' }}</span>
      <span class="ml-auto text-sm font-semibold tabular-nums whitespace-nowrap">{{ formatMoney(doc.total_debit) }}</span>
    </div>

    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1.5 px-3 py-2 text-sm">
      <div>
        <dt class="text-neutral-400 text-xs">{{ t('accounting.ai_assistant.internal_document.entry_date') }}</dt>
        <dd class="text-neutral-800">{{ formatDate(doc.entry_date) }}</dd>
      </div>
      <div>
        <dt class="text-neutral-400 text-xs">{{ t('accounting.ai_assistant.internal_document.document_date') }}</dt>
        <dd class="text-neutral-800">{{ formatDate(doc.document_date) }}</dd>
      </div>
      <div v-if="doc.partner_name" class="sm:col-span-2">
        <dt class="text-neutral-400 text-xs">{{ t('accounting.ai_assistant.internal_document.partner') }}</dt>
        <dd class="text-neutral-800 break-words">
          {{ doc.partner_name }}<span v-if="doc.partner_ico" class="text-neutral-500"> · {{ t('accounting.ai_assistant.other_item.ico') }} {{ doc.partner_ico }}</span>
          <span v-if="doc.partner_id" class="ml-1 text-xs text-success-700 whitespace-nowrap">{{ t('accounting.ai_assistant.internal_document.partner_matched') }}</span>
        </dd>
      </div>
      <div v-if="doc.note" class="sm:col-span-2">
        <dt class="text-neutral-400 text-xs">{{ t('accounting.ai_assistant.internal_document.note') }}</dt>
        <dd class="text-neutral-800 whitespace-pre-wrap break-words">{{ doc.note }}</dd>
      </div>
    </dl>

    <div class="overflow-x-auto border-t border-neutral-100">
      <table class="w-full text-sm">
        <thead class="text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th class="px-3 py-1.5 text-left font-medium">{{ t('accounting.ai_assistant.proposal.account') }}</th>
            <th class="px-3 py-1.5 text-right font-medium whitespace-nowrap">{{ t('accounting.ai_assistant.proposal.debit') }}</th>
            <th class="px-3 py-1.5 text-right font-medium whitespace-nowrap">{{ t('accounting.ai_assistant.proposal.credit') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(l, i) in doc.lines" :key="i" class="border-t border-neutral-100">
            <td class="px-3 py-1.5">
              <span class="font-mono" :class="unknown.includes(l.account_code) ? 'text-danger-600 font-semibold' : 'text-neutral-800'">{{ l.account_code }}</span>
              <span v-if="l.account_name" class="text-neutral-600"> · {{ l.account_name }}</span>
              <span v-if="l.dimension_labels?.length" class="block text-xs text-neutral-400">{{ l.dimension_labels.join(', ') }}</span>
            </td>
            <td class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap">{{ l.side === 'debit' ? formatMoney(l.amount) : '' }}</td>
            <td class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap">{{ l.side === 'credit' ? formatMoney(l.amount) : '' }}</td>
          </tr>
        </tbody>
        <tfoot>
          <tr class="border-t border-neutral-200 font-medium">
            <td class="px-3 py-1.5 text-neutral-500">{{ t('accounting.ai_assistant.proposal.total') }}</td>
            <td class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap">{{ formatMoney(doc.total_debit) }}</td>
            <td class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap">{{ formatMoney(doc.total_credit) }}</td>
          </tr>
        </tfoot>
      </table>
    </div>

    <ul v-if="problems.length" class="px-3 py-2 space-y-1 border-t border-neutral-100" data-test="ai-internal-document-problems">
      <li v-for="(p, pi) in problems" :key="pi" class="text-xs text-danger-600">{{ p }}</li>
    </ul>

    <div class="px-3 py-2 border-t border-neutral-100 space-y-1.5">
      <div class="flex flex-wrap items-center gap-2">
        <template v-if="createdId">
          <span class="inline-flex items-center gap-1.5 text-sm text-success-700 whitespace-nowrap">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.checkCircle" /></svg>
            {{ t('accounting.ai_assistant.internal_document.created_label') }}
          </span>
          <RouterLink :to="{ name: 'accounting-internal-document-detail', params: { id: createdId } }" :class="btnOutline('primary')" data-test="ai-internal-document-open">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg>
            {{ t('accounting.ai_assistant.internal_document.open') }}
          </RouterLink>
        </template>
        <template v-else>
          <button v-if="canPost" type="button" :class="btnFilled('success')" :disabled="!!blockReason || busy"
            :title="disabledTitle(!!blockReason, blockReason)" data-test="ai-internal-document-post" @click="create(true)">
            <svg v-if="busy" class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" /><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
            <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ t('accounting.ai_assistant.internal_document.save_and_post') }}
          </button>
          <button type="button" :class="canPost ? btnOutline('primary') : btnFilled('primary')" :disabled="!!blockReason || busy"
            :title="disabledTitle(!!blockReason, blockReason)" data-test="ai-internal-document-draft" @click="create(false)">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
            {{ t('accounting.ai_assistant.internal_document.save_draft') }}
          </button>
        </template>
      </div>
      <p v-if="blockReason && !createdId" :class="BTN_DISABLED_NOTE">{{ blockReason }}</p>
      <p v-else-if="!createdId" class="text-xs text-neutral-400">{{ t('accounting.ai_assistant.internal_document.draft_hint') }}</p>
    </div>
  </div>
</template>
