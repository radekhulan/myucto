<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { invoicesApi, type Invoice, type InvoiceOtherItemCandidate } from '@/api/invoices'
import { apiErrorMessage } from '@/api/errors'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { btnOutlineSm } from '@/components/ui/buttonStyles'

/**
 * Faktura krytá ostatní pohledávkou: prodej zaúčtovaný už smlouvou (311 / 641) a úhrada
 * spárovaná na pohledávku. Faktura pak vlastní zápis nemá a úhrady přebírá z pohledávky.
 * Komponenta ukazuje navázanou pohledávku, jednoznačný návrh vazby a výběr pohledávky.
 */
const props = defineProps<{
  invoice: Invoice
  /** Uživatel smí vazbu měnit (zaúčtování + zápis faktur, podvojné účetnictví). */
  canLink: boolean
}>()

const emit = defineEmits<{ updated: [invoice: Invoice] }>()

const { t } = useI18n()
const toast = useToast()

const pickerOpen = ref(false)
const loading = ref(false)
const busy = ref(false)
const search = ref('')
const candidates = ref<InvoiceOtherItemCandidate[]>([])

const linked = computed(() => props.invoice.other_item_link ?? null)
const suggestion = computed(() => (props.canLink && !linked.value ? props.invoice.other_item_suggestion ?? null : null))

async function loadCandidates(): Promise<void> {
  loading.value = true
  try {
    candidates.value = await invoicesApi.otherItemCandidates(props.invoice.id, search.value.trim())
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    loading.value = false
  }
}

async function open(): Promise<void> {
  pickerOpen.value = true
  search.value = ''
  await loadCandidates()
}

async function link(otherItemId: number): Promise<void> {
  if (busy.value) return
  busy.value = true
  try {
    const updated = await invoicesApi.linkOtherItem(props.invoice.id, otherItemId)
    pickerOpen.value = false
    toast.success(t('invoice.other_item_link.linked_toast'))
    emit('updated', updated)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

async function unlink(): Promise<void> {
  if (busy.value || !confirm(t('invoice.other_item_link.unlink_confirm'))) return
  busy.value = true
  try {
    const updated = await invoicesApi.unlinkOtherItem(props.invoice.id)
    toast.success(t('invoice.other_item_link.unlinked_toast'))
    emit('updated', updated)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}

function itemLabel(item: { id: number; document_no: string | null }): string {
  return item.document_no || `#${item.id}`
}

function paidState(item: { paid_amount: number; remaining_amount: number }): string {
  if (item.remaining_amount <= 0.005 && item.paid_amount > 0) return t('invoice.other_item_link.paid')
  if (item.paid_amount > 0) return t('invoice.other_item_link.partially_paid')
  return t('invoice.other_item_link.unpaid')
}

defineExpose({ open })
</script>

<template>
  <div v-if="linked" data-test="other-item-linked"
    class="flex flex-wrap items-center justify-between gap-3 bg-primary-50 border border-primary-200 rounded-lg px-4 py-2.5 text-sm mb-4">
    <span class="text-primary-700 min-w-0">
      <span class="px-1.5 py-0.5 rounded bg-primary-100 text-primary-700 text-xs font-medium mr-2">{{ t('invoice.other_item_link.badge') }}</span>
      {{ t('invoice.other_item_link.linked') }}
      <RouterLink :to="{ name: 'other-item-detail', params: { id: linked.id } }" class="font-mono font-medium hover:underline">
        {{ itemLabel(linked) }}
      </RouterLink>
      <span class="text-primary-600">· {{ paidState(linked) }}</span>
    </span>
    <button v-if="canLink" type="button" data-test="other-item-unlink" :disabled="busy" @click="unlink"
      :class="[btnOutlineSm('warning'), 'whitespace-nowrap shrink-0 bg-surface']">
      {{ t('invoice.other_item_link.unlink') }}
    </button>
  </div>

  <div v-else-if="suggestion" data-test="other-item-suggestion"
    class="flex flex-wrap items-center justify-between gap-3 bg-warning-50 border border-warning-200 rounded-lg px-4 py-2.5 text-sm mb-4">
    <span class="text-neutral-700 min-w-0">
      {{ t('invoice.other_item_link.suggestion', { item: itemLabel(suggestion), amount: formatMoney(suggestion.amount, suggestion.currency) }) }}
    </span>
    <span class="flex flex-wrap items-center gap-2">
      <button type="button" data-test="other-item-suggestion-link" :disabled="busy" @click="link(suggestion.id)"
        :class="[btnOutlineSm('primary'), 'whitespace-nowrap bg-surface']">
        {{ t('invoice.other_item_link.link_suggested') }}
      </button>
      <button type="button" :disabled="busy" @click="open"
        :class="[btnOutlineSm('neutral'), 'whitespace-nowrap bg-surface']">
        {{ t('invoice.other_item_link.choose_other') }}
      </button>
    </span>
  </div>

  <div v-if="pickerOpen" data-test="other-item-picker"
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="pickerOpen = false">
    <div class="bg-surface rounded-lg shadow-xl max-w-2xl w-full max-h-[80vh] overflow-hidden flex flex-col">
      <div class="px-5 py-3 border-b border-neutral-100 flex items-center justify-between">
        <h3 class="font-medium">{{ t('invoice.other_item_link.modal_title') }}</h3>
        <button type="button" @click="pickerOpen = false" class="cursor-pointer text-neutral-400 hover:text-neutral-600">✕</button>
      </div>
      <div class="p-4 overflow-y-auto space-y-3">
        <p class="text-sm text-neutral-500">{{ t('invoice.other_item_link.modal_hint') }}</p>
        <input v-model="search" type="search" :placeholder="t('invoice.other_item_link.search')"
          class="w-full border border-neutral-300 rounded-md px-3 py-1.5 text-sm" @keyup.enter="loadCandidates" />
        <div v-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>
        <div v-else-if="candidates.length === 0" class="text-sm text-neutral-500">{{ t('invoice.other_item_link.no_candidates') }}</div>
        <ul v-else class="space-y-2">
          <li v-for="cand in candidates" :key="cand.id">
            <button type="button" data-test="other-item-candidate" :disabled="busy || !cand.amount_match" @click="link(cand.id)"
              :title="cand.amount_match ? '' : t('invoice.other_item_link.amount_mismatch')"
              class="cursor-pointer w-full text-left px-3 py-2 border border-neutral-200 rounded-md hover:border-primary-400 hover:bg-primary-50 disabled:opacity-50 disabled:cursor-not-allowed flex flex-wrap justify-between items-center gap-3">
              <span class="min-w-0">
                <span class="font-mono text-sm">{{ itemLabel(cand) }}</span>
                <span v-if="cand.partner_name" class="text-sm text-neutral-600"> · {{ cand.partner_name }}</span>
                <span v-if="cand.vs_match" class="ml-2 px-1.5 py-0.5 rounded bg-success-50 text-success-700 text-xs font-medium">{{ t('invoice.other_item_link.vs_match') }}</span>
                <span class="block text-xs text-neutral-500">
                  {{ t('invoice.other_item_link.vs') }} {{ cand.variable_symbol || '—' }} · {{ formatDate(cand.issued_on) }} · {{ paidState(cand) }}
                </span>
              </span>
              <span class="text-sm whitespace-nowrap" :class="cand.amount_match ? 'text-neutral-700' : 'text-danger-500'">
                {{ formatMoney(cand.amount, cand.currency) }}
              </span>
            </button>
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>
