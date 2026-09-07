<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { documentRequestsApi, type DocumentRequest, type DocumentRequestStatus } from '@/api/documentRequests'
import { useToast } from '@/composables/useToast'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import { appIsoDate } from '@/utils/date'
import DateInput from '@/components/ui/DateInput.vue'

const { t } = useI18n()
const toast = useToast()

const items = ref<DocumentRequest[]>([])
const loading = ref(false)
const busy = ref(false)
const statusFilter = ref<DocumentRequestStatus | ''>('')

const creating = ref(false)
const form = ref({ description: '', amount: '' as number | '', context_date: '', deadline: '' })

async function load() {
  loading.value = true
  try {
    items.value = await documentRequestsApi.list(statusFilter.value || undefined)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
  }
}
onMounted(load)

const openCount = computed(() => items.value.filter(i => i.status !== 'resolved').length)

function startCreate() {
  creating.value = true
  form.value = { description: '', amount: '', context_date: '', deadline: '' }
}

async function submitCreate() {
  if (!form.value.description.trim() || busy.value) return
  busy.value = true
  try {
    await documentRequestsApi.create({
      description: form.value.description.trim(),
      amount: form.value.amount === '' ? null : Number(form.value.amount),
      context_date: form.value.context_date || null,
      deadline: form.value.deadline || null,
    })
    toast.success(t('document_requests.created'))
    creating.value = false
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

async function resolve(item: DocumentRequest) {
  busy.value = true
  try {
    await documentRequestsApi.resolve(item.id)
    toast.success(t('document_requests.resolved'))
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

async function reopen(item: DocumentRequest) {
  busy.value = true
  try {
    await documentRequestsApi.reopen(item.id)
    toast.success(t('document_requests.reopened'))
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

async function remove(item: DocumentRequest) {
  if (!window.confirm(t('document_requests.delete_confirm'))) return
  busy.value = true
  try {
    await documentRequestsApi.delete(item.id)
    toast.success(t('document_requests.deleted'))
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

function statusBadge(s: DocumentRequestStatus): string {
  if (s === 'uploaded') return 'bg-warning-50 text-warning-600'
  if (s === 'resolved') return 'bg-success-50 text-success-600'
  return 'bg-neutral-100 text-neutral-600'
}

function isOverdue(item: DocumentRequest): boolean {
  return item.status === 'requested' && !!item.deadline && item.deadline < appIsoDate()
}
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('document_requests.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('document_requests.subtitle') }}</p>
      </div>
      <button v-if="!creating" @click="startCreate" :class="btnFilled('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('document_requests.new') }}
      </button>
      <button v-else @click="creating = false" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
    </div>

    <!-- Nový požadavek -->
    <div v-if="creating" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 mb-6 space-y-3">
      <div>
        <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('document_requests.description') }}</label>
        <input v-model="form.description" type="text" maxlength="500"
          :placeholder="t('document_requests.description_placeholder')"
          class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('document_requests.amount') }}</label>
          <input v-model.number="form.amount" type="number" step="0.01" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm text-right" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('document_requests.context_date') }}</label>
          <DateInput v-model="form.context_date" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('document_requests.deadline') }}</label>
          <DateInput v-model="form.deadline" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
      </div>
      <div class="flex justify-end">
        <button :disabled="!form.description.trim() || busy" @click="submitCreate" :class="btnFilled('primary')">
          {{ t('document_requests.create_submit') }}
        </button>
      </div>
    </div>

    <!-- Filtr stavu -->
    <div class="flex flex-wrap items-center gap-2 mb-3">
      <select v-model="statusFilter" @change="load"
        class="h-8 px-2 text-xs border border-neutral-300 rounded-md text-neutral-700 bg-surface">
        <option value="">{{ t('document_requests.filter_all') }}</option>
        <option value="requested">{{ t('document_requests.status_requested') }}</option>
        <option value="uploaded">{{ t('document_requests.status_uploaded') }}</option>
        <option value="resolved">{{ t('document_requests.status_resolved') }}</option>
      </select>
      <span class="text-xs text-neutral-400">{{ t('document_requests.open_count', { n: openCount }) }}</span>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="items.length === 0" boxed icon="doc" :title="t('document_requests.empty')" />
    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th class="px-3 py-2 text-left font-medium">{{ t('document_requests.description') }}</th>
            <th class="px-3 py-2 text-right font-medium">{{ t('document_requests.amount') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('document_requests.deadline') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('document_requests.status') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('document_requests.document') }}</th>
            <th class="px-3 py-2 text-right font-medium">{{ t('document_requests.actions') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="item in items" :key="item.id" class="hover:bg-neutral-50">
            <td class="px-3 py-2">{{ item.description }}</td>
            <td class="px-3 py-2 text-right font-mono">{{ item.amount !== null ? formatMoney(item.amount, 'CZK') : '—' }}</td>
            <td class="px-3 py-2 whitespace-nowrap" :class="isOverdue(item) ? 'text-danger-500 font-medium' : ''">
              {{ item.deadline ? formatDate(item.deadline) : '—' }}
            </td>
            <td class="px-3 py-2">
              <span class="text-xs px-2 py-0.5 rounded font-medium" :class="statusBadge(item.status)">
                {{ t(`document_requests.status_${item.status}`) }}
              </span>
            </td>
            <td class="px-3 py-2">
              <RouterLink v-if="item.purchase_invoice_id" :to="`/purchase-invoices/${item.purchase_invoice_id}`"
                class="text-primary-600 hover:underline">
                {{ item.pi_vendor_invoice_number || `#${item.purchase_invoice_id}` }}
              </RouterLink>
              <span v-else class="text-neutral-400">—</span>
            </td>
            <td class="px-3 py-2">
              <div class="flex flex-wrap items-center justify-end gap-1">
                <button v-if="item.status !== 'resolved'" :disabled="busy" @click="resolve(item)" :class="btnOutline('success')">
                  {{ t('document_requests.resolve') }}
                </button>
                <button v-else :disabled="busy" @click="reopen(item)" :class="btnOutline('neutral')">
                  {{ t('document_requests.reopen') }}
                </button>
                <button :disabled="busy" @click="remove(item)" :class="btnOutline('danger')">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
