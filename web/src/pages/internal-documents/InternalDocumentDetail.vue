<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter, type RouteLocationRaw } from 'vue-router'
import { accountingApi, type ChartAccount } from '@/api/accounting'
import { internalDocumentsApi, type InternalDocument, type InternalDocumentLink } from '@/api/internalDocuments'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { useDemoMode } from '@/composables/useDemoMode'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { appIsoDate } from '@/utils/date'
import { journalAmount } from '@/utils/journalAmount'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import Modal from '@/components/ui/Modal.vue'
import DateInput from '@/components/ui/DateInput.vue'
import LinkedDocumentsPanel from '@/components/documents/LinkedDocumentsPanel.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toast = useToast()
const { blockDemoMutation } = useDemoMode()
const id = computed(() => Number(route.params.id))
const doc = ref<InternalDocument | null>(null)
const accounts = ref<ChartAccount[]>([])
const loading = ref(false)
const busy = ref(false)
const reverseOpen = ref(false)
const reverseReason = ref('')
const reverseDate = ref(appIsoDate())

const canWrite = computed(() => auth.canWrite('accounting.journal.write'))
const canPost = computed(() => auth.canWrite('accounting.journal.post'))
const accountNames = computed(() => Object.fromEntries(accounts.value.map(a => [a.account_code, a.name])))
const totals = computed(() => {
  let debit = 0
  let credit = 0
  for (const line of doc.value?.lines ?? []) {
    if (line.side === 'debit') debit += journalAmount(line)
    else credit += journalAmount(line)
  }
  return { debit, credit }
})
const documentFolderPath = computed(() => {
  const date = doc.value?.document_date || ''
  return /^\d{4}-\d{2}-\d{2}$/.test(date) ? `Interní doklady/${date.slice(0, 4)}/${date.slice(5, 7)}` : undefined
})

function statusClass(status: string): string {
  if (status === 'posted') return 'bg-success-50 text-success-700'
  if (status === 'reversed') return 'bg-neutral-100 text-neutral-500'
  return 'bg-warning-50 text-warning-700'
}

function docTypeLabel(type: string): string {
  const key = `accounting.journal.source.${type}`
  const value = t(key)
  return value === key ? type : value
}

function linkRoute(link: InternalDocumentLink): RouteLocationRaw | null {
  if (link.doc_type === 'invoice') return { name: 'invoice-detail', params: { id: link.doc_id } }
  if (link.doc_type === 'purchase_invoice') return { name: 'purchase-invoice-detail', params: { id: link.doc_id } }
  return null
}

function errorMessage(error: any): string {
  return error?.response?.data?.error?.message || t('common.error')
}

async function load() {
  loading.value = true
  try {
    const [detail] = await Promise.all([
      internalDocumentsApi.get(id.value),
      accounts.value.length ? Promise.resolve() : accountingApi.listAccounts().then(value => { accounts.value = value }).catch(() => undefined),
    ])
    doc.value = detail
  } catch (error: any) {
    doc.value = null
    toast.error(errorMessage(error))
  } finally {
    loading.value = false
  }
}

async function post() {
  if (!doc.value || busy.value || blockDemoMutation() || !window.confirm(t('internal_documents.confirm_post'))) return
  busy.value = true
  try {
    doc.value = await internalDocumentsApi.post(id.value)
    toast.success(t('internal_documents.posted_toast', { number: doc.value.document_no || '' }))
  } catch (error: any) {
    toast.error(errorMessage(error))
  } finally {
    busy.value = false
  }
}

async function reverse() {
  if (!doc.value || busy.value || blockDemoMutation()) return
  if (reverseReason.value.trim().length < 3) {
    toast.error(t('internal_documents.reason_required'))
    return
  }
  busy.value = true
  try {
    doc.value = await internalDocumentsApi.reverse(id.value, { reason: reverseReason.value.trim(), entry_date: reverseDate.value })
    reverseOpen.value = false
    toast.success(t('internal_documents.reversed_toast'))
  } catch (error: any) {
    toast.error(errorMessage(error))
  } finally {
    busy.value = false
  }
}

async function remove() {
  if (!doc.value || busy.value || blockDemoMutation() || !window.confirm(t('internal_documents.confirm_delete'))) return
  busy.value = true
  try {
    await internalDocumentsApi.remove(id.value)
    toast.success(t('common.deleted'))
    await router.push({ name: 'accounting-internal-documents' })
  } catch (error: any) {
    toast.error(errorMessage(error))
  } finally {
    busy.value = false
  }
}

const actions = computed<ActionItem[]>(() => {
  const current = doc.value
  if (!current) return []
  return [
    { key: 'post', label: t('internal_documents.post'), icon: 'check', tier: 'primary', variant: 'success',
      show: current.status === 'draft' && canPost.value, disabled: busy.value, run: () => void post() },
    { key: 'edit', label: t('common.edit'), icon: 'edit', tier: 'secondary', variant: 'neutral',
      show: current.status === 'draft' && canWrite.value, to: { name: 'accounting-internal-document-edit', params: { id: id.value } } },
    { key: 'journal', label: t('internal_documents.open_journal'), icon: 'doc', tier: 'secondary', variant: 'primary',
      show: !!current.journal_entry_id, to: { name: 'accounting-journal', query: { entry_id: String(current.journal_entry_id) } } },
    { key: 'reverse', label: t('internal_documents.reverse'), icon: 'uturn', tier: 'overflow', variant: 'warning',
      show: current.status === 'posted' && canPost.value, disabled: busy.value,
      run: () => { reverseReason.value = ''; reverseDate.value = appIsoDate(); reverseOpen.value = true } },
    { key: 'delete', label: t('common.delete'), icon: 'trash', tier: 'overflow', variant: 'danger',
      show: current.status === 'draft' && canWrite.value, disabled: busy.value, run: () => void remove() },
  ]
})

watch(id, () => void load())
onMounted(load)
</script>

<template>
  <div>
    <div v-if="loading && !doc" class="py-12 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <div v-else-if="!doc" class="py-12 text-center text-sm text-neutral-500">{{ t('internal_documents.not_found') }}</div>
    <template v-else>
      <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
          <RouterLink :to="{ name: 'accounting-internal-documents' }" class="text-sm text-primary-700 hover:underline">← {{ t('internal_documents.back') }}</RouterLink>
          <div class="mt-1 flex flex-wrap items-center gap-2">
            <h1 class="text-2xl font-semibold">{{ doc.document_no || t('internal_documents.no_number') }}</h1>
            <span class="rounded px-2 py-1 text-xs font-medium" :class="statusClass(doc.status)" data-test="status">{{ t(`internal_documents.status.${doc.status}`) }}</span>
          </div>
          <p class="mt-0.5 text-sm text-neutral-500">{{ doc.title }} · {{ t(`internal_documents.kind.${doc.kind}`) }}<span v-if="doc.partner_name"> · {{ doc.partner_name }}</span></p>
        </div>
        <ActionBar :actions="actions" />
      </div>

      <div class="grid gap-4 lg:grid-cols-3">
        <section class="rounded-lg border border-neutral-200 bg-surface p-4 lg:col-span-2">
          <h2 class="mb-3 font-semibold">{{ t('internal_documents.details') }}</h2>
          <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <div><dt class="text-neutral-500">{{ t('internal_documents.item') }}</dt><dd>{{ doc.title }}</dd></div>
            <div><dt class="text-neutral-500">{{ t('internal_documents.kind_label') }}</dt><dd>{{ t(`internal_documents.kind.${doc.kind}`) }}</dd></div>
            <div><dt class="text-neutral-500">{{ t('internal_documents.document_date') }}</dt><dd>{{ formatDate(doc.document_date) }}</dd></div>
            <div><dt class="text-neutral-500">{{ t('internal_documents.entry_date') }}</dt><dd>{{ formatDate(doc.entry_date) }}</dd></div>
            <div><dt class="text-neutral-500">{{ t('internal_documents.partner') }}</dt><dd>{{ doc.partner_name || '–' }}</dd></div>
            <div v-if="doc.posted_at"><dt class="text-neutral-500">{{ t('internal_documents.posted_at') }}</dt><dd>{{ formatDate(doc.posted_at) }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-neutral-500">{{ t('internal_documents.note') }}</dt><dd class="whitespace-pre-wrap">{{ doc.note || '–' }}</dd></div>
            <div v-if="doc.status === 'reversed'" class="sm:col-span-2">
              <dt class="text-neutral-500">{{ t('internal_documents.reversal_reason') }}</dt>
              <dd class="whitespace-pre-wrap">{{ doc.reversal_reason || '–' }}<span v-if="doc.reversed_at" class="text-neutral-500"> · {{ formatDate(doc.reversed_at) }}</span></dd>
            </div>
          </dl>
        </section>
        <section class="rounded-lg border border-neutral-200 bg-surface p-4">
          <h2 class="mb-3 font-semibold">{{ t('internal_documents.journal') }}</h2>
          <dl class="space-y-3 text-sm">
            <div class="flex justify-between gap-3"><dt class="text-neutral-500">{{ t('internal_documents.amount') }}</dt><dd class="font-semibold tabular-nums">{{ formatMoney(doc.amount) }}</dd></div>
            <div class="flex justify-between gap-3">
              <dt class="text-neutral-500">{{ t('internal_documents.journal_entry') }}</dt>
              <dd>
                <RouterLink v-if="doc.journal_entry_id" :to="{ name: 'accounting-journal', query: { entry_id: String(doc.journal_entry_id) } }"
                  class="font-medium text-primary-700 hover:underline" data-test="journal-link">#{{ doc.journal_entry_id }}</RouterLink>
                <span v-else class="text-neutral-500">{{ t('internal_documents.not_posted') }}</span>
              </dd>
            </div>
            <div v-if="doc.reversal_entry_id" class="flex justify-between gap-3">
              <dt class="text-neutral-500">{{ t('internal_documents.reversal_entry') }}</dt>
              <dd><RouterLink :to="{ name: 'accounting-journal', query: { entry_id: String(doc.reversal_entry_id) } }" class="font-medium text-primary-700 hover:underline">#{{ doc.reversal_entry_id }}</RouterLink></dd>
            </div>
          </dl>
          <p v-if="doc.status === 'draft'" class="mt-3 text-xs text-neutral-500">{{ t('internal_documents.draft_hint') }}</p>
        </section>
      </div>

      <section class="mt-4 rounded-lg border border-neutral-200 bg-surface p-4">
        <h2 class="mb-3 font-semibold">{{ t('internal_documents.lines') }}</h2>
        <div v-if="doc.lines?.length" class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="text-xs text-neutral-500">
              <tr>
                <th scope="col" class="px-2 py-1 text-left font-medium">{{ t('internal_documents.account') }}</th>
                <th scope="col" class="px-2 py-1 text-right font-medium">{{ t('accounting.journal.side.debit') }}</th>
                <th scope="col" class="px-2 py-1 text-right font-medium">{{ t('accounting.journal.side.credit') }}</th>
                <th scope="col" class="px-2 py-1 text-left font-medium">{{ t('accounting.manual.cost_center') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(line, index) in doc.lines" :key="index">
                <td class="px-2 py-1.5"><span class="font-mono">{{ line.account_code }}</span> <span class="text-neutral-500">{{ accountNames[line.account_code] || '' }}</span></td>
                <td class="px-2 py-1.5 text-right tabular-nums" :class="line.is_red_storno ? 'text-danger-600' : ''">{{ line.side === 'debit' ? formatMoney(journalAmount(line)) : '' }}</td>
                <td class="px-2 py-1.5 text-right tabular-nums" :class="line.is_red_storno ? 'text-danger-600' : ''">{{ line.side === 'credit' ? formatMoney(journalAmount(line)) : '' }}</td>
                <td class="px-2 py-1.5">{{ line.cost_center || '' }}</td>
              </tr>
            </tbody>
            <tfoot class="border-t border-neutral-200 font-semibold">
              <tr>
                <td class="px-2 py-1.5">{{ t('internal_documents.total') }}</td>
                <td class="px-2 py-1.5 text-right tabular-nums">{{ formatMoney(totals.debit) }}</td>
                <td class="px-2 py-1.5 text-right tabular-nums">{{ formatMoney(totals.credit) }}</td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>
        <p v-else class="text-sm text-neutral-500">{{ t('internal_documents.no_lines') }}</p>
      </section>

      <section v-if="doc.document_links.length" class="mt-4 rounded-lg border border-neutral-200 bg-surface p-4">
        <h2 class="mb-3 font-semibold">{{ t('internal_documents.links_title') }}</h2>
        <ul class="divide-y divide-neutral-100 text-sm">
          <li v-for="link in doc.document_links" :key="`${link.doc_type}:${link.doc_id}`" class="flex flex-wrap items-center gap-2 py-2">
            <span class="text-xs text-neutral-500">{{ docTypeLabel(link.doc_type) }}</span>
            <RouterLink v-if="linkRoute(link)" :to="linkRoute(link)!" class="font-medium text-primary-700 hover:underline" data-test="link-title">{{ link.doc_title || `#${link.doc_id}` }}</RouterLink>
            <span v-else class="font-medium" data-test="link-title">{{ link.doc_title || `#${link.doc_id}` }}</span>
            <span v-if="link.note" class="text-neutral-500">· {{ link.note }}</span>
          </li>
        </ul>
      </section>

      <LinkedDocumentsPanel v-if="auth.canRead('documents')" class="mt-4" entity-type="internal_document" :entity-id="id"
        :uploadable="canWrite" :readonly="!canWrite" :upload-folder-path="documentFolderPath" :title="t('internal_documents.attachments')" />
    </template>

    <Modal v-if="reverseOpen" :title="t('internal_documents.reverse')" width-class="max-w-lg" @close="reverseOpen = false">
      <form id="internal-document-reverse" class="space-y-4" @submit.prevent="reverse">
        <p class="text-sm text-neutral-600">{{ t('internal_documents.confirm_reverse') }}</p>
        <label class="block text-sm font-medium">{{ t('internal_documents.reverse_reason') }}
          <textarea v-model="reverseReason" required minlength="3" maxlength="500" rows="3" class="mt-1 block w-full rounded-md border border-neutral-300 bg-surface p-2" />
        </label>
        <label class="block text-sm font-medium">{{ t('internal_documents.reverse_date') }}
          <DateInput v-model="reverseDate" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 px-2" />
        </label>
      </form>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" @click="reverseOpen = false">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="submit" form="internal-document-reverse" :disabled="busy" :class="btnFilled('warning')">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
            {{ t('internal_documents.reverse') }}
          </button>
        </div>
      </template>
    </Modal>
  </div>
</template>
