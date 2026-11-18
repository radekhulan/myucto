<script setup lang="ts">
// Výběr dokladu k propojení záloha ↔ konečná faktura. Sdílí ho detail přijaté faktury
// (oba směry párování) i editor konečné faktury; kandidáty načte volající funkcí `load`,
// výběr vrací událostí `pick`.
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import { formatDate, formatMoney } from '@/composables/useFormat'
import type { PurchaseInvoiceBrief } from '@/api/purchaseInvoices'

const props = defineProps<{
  open: boolean
  title: string
  emptyText: string
  load: () => Promise<PurchaseInvoiceBrief[]>
  busy?: boolean
}>()
const emit = defineEmits<{ pick: [id: number, candidate: PurchaseInvoiceBrief]; close: [] }>()

const { t } = useI18n()
const toast = useToast()
const candidates = ref<PurchaseInvoiceBrief[]>([])
const loading = ref(false)

watch(() => props.open, async (open) => {
  if (!open) return
  loading.value = true
  candidates.value = []
  try {
    candidates.value = await props.load()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    loading.value = false
  }
}, { immediate: true })
</script>

<template>
  <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="emit('close')">
    <div class="bg-surface rounded-lg shadow-xl max-w-lg w-full max-h-[80vh] overflow-hidden flex flex-col">
      <div class="px-5 py-3 border-b border-neutral-100 flex items-center justify-between">
        <h3 class="font-medium">{{ title }}</h3>
        <button type="button" @click="emit('close')" class="cursor-pointer text-neutral-400 hover:text-neutral-600" :aria-label="t('common.close')">✕</button>
      </div>
      <div class="p-4 overflow-y-auto">
        <div v-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>
        <div v-else-if="candidates.length === 0" class="text-sm text-neutral-500">{{ emptyText }}</div>
        <ul v-else class="space-y-2">
          <li v-for="cand in candidates" :key="cand.id">
            <button type="button" @click="emit('pick', cand.id, cand)" :disabled="busy"
              class="cursor-pointer w-full text-left px-3 py-2 border border-neutral-200 rounded-md hover:border-primary-400 hover:bg-primary-50 disabled:opacity-50 flex justify-between items-center gap-3">
              <span class="font-mono text-sm">{{ cand.varsymbol || cand.vendor_invoice_number || ('#' + cand.id) }}</span>
              <span class="text-sm text-neutral-500">{{ cand.issue_date ? formatDate(cand.issue_date) : '' }} · {{ formatMoney(cand.total_with_vat, cand.currency) }}</span>
            </button>
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>
