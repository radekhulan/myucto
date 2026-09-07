<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  accountingApi,
  type OffsetAgreement,
  type OffsetPartner,
  type OffsetOpenResult,
  type OffsetOpenItem,
} from '@/api/accounting'
import { useToast } from '@/composables/useToast'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import { appIsoDate } from '@/utils/date'
import DateInput from '@/components/ui/DateInput.vue'

const { t } = useI18n()
const toast = useToast()

const agreements = ref<OffsetAgreement[]>([])
const partners = ref<OffsetPartner[]>([])
const open = ref<OffsetOpenResult | null>(null)
const loading = ref(false)
const building = ref(false)
const busy = ref(false)

const todayStr = appIsoDate()
const form = reactive({
  partner_id: '' as number | '',
  agreement_date: todayStr,
  note: '',
})

// Výběr per doklad: klíč = "doc_type:doc_id" → { selected, amount }.
const sel = reactive<Record<string, { selected: boolean; amount: number }>>({})
function key(it: OffsetOpenItem) {
  return `${it.doc_type}:${it.doc_id}`
}

async function loadList() {
  loading.value = true
  try {
    agreements.value = await accountingApi.listOffsets()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
  }
}

async function startBuild() {
  building.value = true
  open.value = null
  form.partner_id = ''
  form.note = ''
  try {
    partners.value = await accountingApi.offsetPartners()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

async function onPartnerChange() {
  open.value = null
  Object.keys(sel).forEach(k => delete sel[k])
  if (!form.partner_id) return
  try {
    open.value = await accountingApi.offsetOpen(Number(form.partner_id))
    for (const it of [...open.value.receivables, ...open.value.payables]) {
      sel[key(it)] = { selected: false, amount: it.remaining }
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

const receivableSum = computed(() =>
  (open.value?.receivables ?? [])
    .filter(it => sel[key(it)]?.selected)
    .reduce((s, it) => s + (Number(sel[key(it)]?.amount) || 0), 0))
const payableSum = computed(() =>
  (open.value?.payables ?? [])
    .filter(it => sel[key(it)]?.selected)
    .reduce((s, it) => s + (Number(sel[key(it)]?.amount) || 0), 0))

const balanced = computed(() =>
  receivableSum.value > 0 &&
  Math.round(receivableSum.value * 100) === Math.round(payableSum.value * 100))

async function submit() {
  if (!form.partner_id || !balanced.value || !open.value) return
  const items = [...open.value.receivables, ...open.value.payables]
    .filter(it => sel[key(it)]?.selected)
    .map(it => ({ doc_type: it.doc_type, doc_id: it.doc_id, amount: Number(sel[key(it)].amount) }))
  busy.value = true
  try {
    await accountingApi.createOffset({
      partner_id: Number(form.partner_id),
      agreement_date: form.agreement_date,
      note: form.note || null,
      items,
    })
    toast.success(t('accounting.offsets.created'))
    building.value = false
    await loadList()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

async function confirmAgreement(a: OffsetAgreement) {
  busy.value = true
  try {
    await accountingApi.confirmOffset(a.id)
    toast.success(t('accounting.offsets.confirmed'))
    await loadList()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

async function cancelAgreement(a: OffsetAgreement) {
  if (!window.confirm(t('accounting.offsets.cancel_confirm'))) return
  busy.value = true
  try {
    await accountingApi.cancelOffset(a.id)
    toast.success(t('accounting.offsets.cancelled'))
    await loadList()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

async function downloadPdf(a: OffsetAgreement) {
  try {
    const r = await accountingApi.offsetPdf(a.id)
    const url = URL.createObjectURL(r.data as unknown as Blob)
    const el = document.createElement('a')
    el.href = url
    el.download = `dohoda-o-zapoctu-${a.document_no}.pdf`
    document.body.appendChild(el); el.click(); el.remove()
    URL.revokeObjectURL(url)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

function statusClass(s: string) {
  if (s === 'confirmed') return 'text-success-600'
  if (s === 'cancelled') return 'text-neutral-400 line-through'
  return 'text-warning-600'
}

onMounted(loadList)
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('accounting.offsets.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.offsets.subtitle') }}</p>
      </div>
      <button v-if="!building" @click="startBuild" :class="btnFilled('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('accounting.offsets.new') }}
      </button>
      <button v-else @click="building = false" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
    </div>

    <!-- Builder -->
    <div v-if="building" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 mb-6 space-y-4">
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.offsets.partner') }}</label>
          <select v-model="form.partner_id" @change="onPartnerChange"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="">{{ t('accounting.offsets.choose_partner') }}</option>
            <option v-for="p in partners" :key="p.partner_id" :value="p.partner_id">{{ p.partner_name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.offsets.date') }}</label>
          <DateInput v-model="form.agreement_date" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.offsets.note') }}</label>
          <input v-model="form.note" type="text" maxlength="500" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
      </div>

      <EmptyState v-if="form.partner_id && open && open.receivables.length === 0 && open.payables.length === 0"
        dense accent="success" icon="checkCircle" :title="t('accounting.offsets.no_open')" />

      <div v-if="open && (open.receivables.length || open.payables.length)" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Pohledávky (FV) -->
        <div>
          <h3 class="text-sm font-semibold mb-2">{{ t('accounting.offsets.receivables') }}</h3>
          <table class="w-full text-sm">
            <thead class="text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-2 py-1 text-left w-6"></th>
                <th class="px-2 py-1 text-left">{{ t('accounting.offsets.col_doc') }}</th>
                <th class="px-2 py-1 text-right">{{ t('accounting.offsets.col_remaining') }}</th>
                <th class="px-2 py-1 text-right">{{ t('accounting.offsets.col_offset') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="it in open.receivables" :key="key(it)">
                <td class="px-2 py-1"><input type="checkbox" v-model="sel[key(it)].selected" /></td>
                <td class="px-2 py-1 font-mono">{{ it.doc_no }}</td>
                <td class="px-2 py-1 text-right font-mono">{{ formatMoney(it.remaining) }}</td>
                <td class="px-2 py-1 text-right">
                  <input type="number" step="0.01" min="0" :max="it.remaining" v-model.number="sel[key(it)].amount"
                    :disabled="!sel[key(it)].selected"
                    class="w-28 h-8 px-2 border border-neutral-300 rounded-md text-sm text-right disabled:bg-neutral-100" />
                </td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="font-semibold border-t border-neutral-300">
                <td colspan="3" class="px-2 py-1 text-right">{{ t('accounting.offsets.sum_receivables') }}</td>
                <td class="px-2 py-1 text-right font-mono">{{ formatMoney(receivableSum) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
        <!-- Závazky (PF) -->
        <div>
          <h3 class="text-sm font-semibold mb-2">{{ t('accounting.offsets.payables') }}</h3>
          <table class="w-full text-sm">
            <thead class="text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-2 py-1 text-left w-6"></th>
                <th class="px-2 py-1 text-left">{{ t('accounting.offsets.col_doc') }}</th>
                <th class="px-2 py-1 text-right">{{ t('accounting.offsets.col_remaining') }}</th>
                <th class="px-2 py-1 text-right">{{ t('accounting.offsets.col_offset') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="it in open.payables" :key="key(it)">
                <td class="px-2 py-1"><input type="checkbox" v-model="sel[key(it)].selected" /></td>
                <td class="px-2 py-1 font-mono">{{ it.doc_no }}</td>
                <td class="px-2 py-1 text-right font-mono">{{ formatMoney(it.remaining) }}</td>
                <td class="px-2 py-1 text-right">
                  <input type="number" step="0.01" min="0" :max="it.remaining" v-model.number="sel[key(it)].amount"
                    :disabled="!sel[key(it)].selected"
                    class="w-28 h-8 px-2 border border-neutral-300 rounded-md text-sm text-right disabled:bg-neutral-100" />
                </td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="font-semibold border-t border-neutral-300">
                <td colspan="3" class="px-2 py-1 text-right">{{ t('accounting.offsets.sum_payables') }}</td>
                <td class="px-2 py-1 text-right font-mono">{{ formatMoney(payableSum) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <div v-if="open && (open.receivables.length || open.payables.length)" class="flex flex-wrap items-center justify-between gap-2 pt-2 border-t border-neutral-100">
        <div class="text-sm" :class="balanced ? 'text-success-600' : 'text-danger-500'">
          <span v-if="balanced">✓ {{ t('accounting.offsets.balanced', { amount: formatMoney(receivableSum) }) }}</span>
          <span v-else>✗ {{ t('accounting.offsets.unbalanced') }}</span>
        </div>
        <button :disabled="!balanced || busy" @click="submit" :class="btnFilled('primary')">
          {{ t('accounting.offsets.create_draft') }}
        </button>
      </div>
    </div>

    <!-- Historie -->
    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="agreements.length === 0" boxed icon="swap"
      :title="t('accounting.offsets.empty')"
      :cta="t('accounting.offsets.new')" @action="startBuild" />
    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th class="px-3 py-2 text-left font-medium">{{ t('accounting.offsets.col_number') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('accounting.offsets.col_date') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('accounting.offsets.col_partner') }}</th>
            <th class="px-3 py-2 text-right font-medium">{{ t('accounting.offsets.col_total') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('accounting.offsets.col_status') }}</th>
            <th class="px-3 py-2 text-right font-medium">{{ t('accounting.offsets.col_actions') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="a in agreements" :key="a.id" class="hover:bg-neutral-50">
            <td class="px-3 py-2 font-mono">{{ a.document_no }}</td>
            <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(a.agreement_date) }}</td>
            <td class="px-3 py-2">{{ a.partner_name }}</td>
            <td class="px-3 py-2 text-right font-mono">{{ formatMoney(a.total_amount) }}</td>
            <td class="px-3 py-2"><span :class="statusClass(a.status)">{{ t(`accounting.offsets.status_${a.status}`) }}</span></td>
            <td class="px-3 py-2">
              <div class="flex flex-wrap items-center justify-end gap-1">
                <button v-if="a.status === 'draft'" :disabled="busy" @click="confirmAgreement(a)" :class="btnOutline('success')">
                  {{ t('accounting.offsets.confirm') }}
                </button>
                <button @click="downloadPdf(a)" :class="btnOutline('primary')">PDF</button>
                <button v-if="a.status !== 'cancelled'" :disabled="busy" @click="cancelAgreement(a)" :class="btnOutline('danger')">
                  {{ t('common.cancel') }}
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
