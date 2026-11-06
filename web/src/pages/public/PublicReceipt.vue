<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { btnFilled } from '@/components/ui/buttonStyles'
import { ensureLocaleLoaded } from '@/i18n'
import { publicReceiptApi, type PublicReceiptData, type PublicReceiptItem } from '@/api/publicReceipt'

const { t } = useI18n()
const route = useRoute()
const token = computed(() => String(route.params.token || ''))

const data = ref<PublicReceiptData | null>(null)
const loading = ref(true)
const loadError = ref(false)

// Účtenka mluví jazykem dokladu, ne prohlížeče — stejně jako PDF.
const lang = computed(() => data.value?.receipt.language || 'cs')
const rcpt = computed(() => data.value?.receipt ?? null)
const isVatPayer = computed(() => !!data.value?.supplier.is_vat_payer)
const locale = computed(() => (lang.value === 'en' ? 'en-US' : 'cs-CZ'))

function tr(key: string, params: Record<string, unknown> = {}): string {
  return t(key, params, { locale: lang.value })
}

const docLabel = computed(() => {
  if (!isVatPayer.value) return tr('publicReceipt.doc_receipt')
  return rcpt.value?.is_simplified ? tr('publicReceipt.doc_simplified') : tr('publicReceipt.doc_tax')
})

const paidLabel = computed(() => {
  switch (rcpt.value?.payment_method) {
    case 'cash': return tr('publicReceipt.paid_cash')
    case 'card': return tr('publicReceipt.paid_card')
    case 'offset': return tr('publicReceipt.paid_voucher')
    default: return tr('publicReceipt.paid')
  }
})

const amountLines = computed(() => (rcpt.value?.items ?? []).filter(it => it.item_kind !== 'text'))
const textLines = computed(() => (rcpt.value?.items ?? []).filter(it => it.item_kind === 'text'))

function fmtMoney(n: number | string, dec?: number): string {
  const d = dec ?? rcpt.value?.currency_decimals ?? 2
  return Number(n).toLocaleString(locale.value, { minimumFractionDigits: d, maximumFractionDigits: d }) + ' ' + (rcpt.value?.currency || '')
}
function fmtQty(n: number): string {
  return Number(n).toLocaleString(locale.value, { maximumFractionDigits: 3 })
}
function fmtRate(r: number): string {
  return Number(r).toLocaleString(locale.value, { maximumFractionDigits: 2 }) + ' %'
}
function fmtDate(d: string | null | undefined): string {
  if (!d) return ''
  const parts = d.slice(0, 10).split('-')
  if (parts.length !== 3) return d
  return lang.value === 'en' ? `${parts[2]}.${parts[1]}.${parts[0]}` : `${Number(parts[2])}. ${Number(parts[1])}. ${parts[0]}`
}
function fmtDateTime(iso: string): string {
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return iso
  return date.toLocaleString(locale.value, { timeZone: 'Europe/Prague', dateStyle: 'medium', timeStyle: 'medium' })
}

/** V režimu cen s DPH nese unit_price_without_vat brutto — cena za jednotku s DPH. */
function unitPrice(it: PublicReceiptItem): number {
  if (!isVatPayer.value || rcpt.value?.prices_include_vat) {
    return it.quantity !== 0 ? it.total_with_vat / it.quantity : it.unit_price_without_vat
  }
  return it.unit_price_without_vat
}

const partyName = computed(() => {
  const c = data.value?.client
  if (!c) return ''
  return String(c.company_name || [c.first_name, c.last_name].filter(Boolean).join(' '))
})
const supplierAddress = computed(() => {
  const s = data.value?.supplier
  if (!s) return []
  return [s.street || '', [s.zip, s.city].filter(Boolean).join(' ')].filter(Boolean)
})

const pdfUrl = computed(() => publicReceiptApi.pdfUrl(token.value, true))

onMounted(async () => {
  try {
    data.value = await publicReceiptApi.get(token.value)
    await ensureLocaleLoaded(data.value.receipt.language)
    document.title = `${docLabel.value} ${rcpt.value?.number || ''} — MyÚčto.cz`
  } catch {
    loadError.value = true
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="min-h-screen bg-neutral-50 flex flex-col">
    <header class="bg-surface border-b border-neutral-200 px-4 py-3">
      <div class="max-w-xl mx-auto flex items-center gap-3">
        <div class="w-8 h-8 bg-primary-600 rounded-md flex items-center justify-center text-white font-bold">M</div>
        <div class="text-sm">
          <div class="font-semibold">My<span class="text-primary-700">Účto</span><span class="text-neutral-500">.cz</span></div>
          <div class="text-xs text-neutral-500">{{ tr('publicReceipt.header') }}</div>
        </div>
      </div>
    </header>

    <main class="flex-1 px-4 py-6">
      <div class="max-w-xl mx-auto">
        <div v-if="loading" class="text-center text-neutral-500 py-16">{{ tr('publicReceipt.loading') }}</div>

        <div v-else-if="loadError" class="bg-surface border border-danger-500/40 rounded-xl p-8 text-center shadow-sm">
          <h1 class="text-xl font-semibold mb-2">{{ t('publicReceipt.invalid_title') }}</h1>
          <p class="text-neutral-600 text-sm">{{ t('publicReceipt.invalid_message') }}</p>
        </div>

        <div v-else-if="data && rcpt" class="space-y-4">
          <!-- Hlavička dokladu -->
          <div class="bg-surface border border-neutral-200 rounded-xl p-5 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <div class="text-sm text-neutral-500">{{ docLabel }}</div>
                <h1 class="text-2xl font-semibold font-mono">{{ rcpt.number || '—' }}</h1>
                <span class="inline-block mt-2 text-xs px-2 py-0.5 rounded font-medium bg-success-50 text-success-600">
                  {{ paidLabel }}
                </span>
              </div>
              <div class="flex flex-wrap items-center gap-2">
                <a :href="pdfUrl" :class="btnFilled('primary')">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                  </svg>
                  {{ tr('publicReceipt.download_pdf') }}
                </a>
              </div>
            </div>
            <div class="mt-4 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
              <div>
                <span class="text-neutral-500 text-xs block">{{ tr('publicReceipt.issue_date') }}</span>
                {{ fmtDate(rcpt.issue_date) }}
              </div>
              <div v-if="isVatPayer && rcpt.tax_date">
                <span class="text-neutral-500 text-xs block">{{ tr('publicReceipt.tax_date') }}</span>
                {{ fmtDate(rcpt.tax_date) }}
              </div>
            </div>
          </div>

          <!-- Prodávající / kupující -->
          <div class="bg-surface border border-neutral-200 rounded-xl p-5 shadow-sm text-sm">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-2">{{ tr('publicReceipt.supplier') }}</h2>
            <div class="font-semibold text-neutral-900">{{ data.supplier.company_name }}</div>
            <div class="text-neutral-600">
              <div v-for="line in supplierAddress" :key="line">{{ line }}</div>
            </div>
            <div class="text-neutral-600 mt-1 space-y-0.5">
              <div v-if="data.supplier.ic"><span class="text-neutral-500">{{ tr('publicReceipt.ic') }}:</span> {{ data.supplier.ic }}</div>
              <div v-if="data.supplier.dic"><span class="text-neutral-500">{{ tr('publicReceipt.dic') }}:</span> {{ data.supplier.dic }}</div>
              <div v-if="!isVatPayer" class="text-xs text-neutral-500">{{ tr('publicReceipt.not_vat_payer') }}</div>
            </div>
            <template v-if="data.client">
              <h2 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mt-4 mb-1">{{ tr('publicReceipt.customer') }}</h2>
              <div class="font-semibold text-neutral-900">{{ partyName }}</div>
              <div v-if="data.client.ic" class="text-neutral-600"><span class="text-neutral-500">{{ tr('publicReceipt.ic') }}:</span> {{ data.client.ic }}</div>
              <div v-if="data.client.dic" class="text-neutral-600"><span class="text-neutral-500">{{ tr('publicReceipt.dic') }}:</span> {{ data.client.dic }}</div>
            </template>
          </div>

          <!-- Položky a součty -->
          <div class="bg-surface border border-neutral-200 rounded-xl shadow-sm overflow-hidden">
            <ul class="divide-y divide-neutral-100">
              <li v-for="(it, i) in amountLines" :key="i" class="px-5 py-3 text-sm flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <div class="text-neutral-900 whitespace-pre-wrap break-words">{{ it.description }}</div>
                  <div v-if="it.item_kind !== 'discount'" class="text-xs text-neutral-500 mt-0.5">
                    {{ fmtQty(it.quantity) }}<template v-if="it.unit"> {{ it.unit }}</template> × {{ fmtMoney(unitPrice(it), 2) }}
                    <template v-if="isVatPayer"> · {{ tr('publicReceipt.vat') }} {{ fmtRate(it.vat_rate_snapshot) }}</template>
                  </div>
                </div>
                <div class="font-mono whitespace-nowrap">{{ fmtMoney(isVatPayer ? it.total_with_vat : it.total_without_vat, 2) }}</div>
              </li>
              <li v-for="(it, i) in textLines" :key="'t' + i" class="px-5 py-2 text-sm text-neutral-700 whitespace-pre-wrap">{{ it.description }}</li>
            </ul>
            <div class="border-t border-neutral-200 px-5 py-4 space-y-1 text-sm">
              <template v-if="isVatPayer">
                <div v-for="b in rcpt.vat_breakdown" :key="b.rate" class="flex justify-between text-neutral-600">
                  <span>{{ tr('publicReceipt.vat_line', { rate: fmtRate(b.rate) }) }}</span>
                  <span class="font-mono whitespace-nowrap">{{ fmtMoney(b.base, 2) }} + {{ fmtMoney(b.vat, 2) }}</span>
                </div>
              </template>
              <div v-if="rcpt.totals && rcpt.totals.rounding" class="flex justify-between text-neutral-600">
                <span>{{ tr('publicReceipt.rounding') }}</span>
                <span class="font-mono">{{ fmtMoney(rcpt.totals.rounding, 2) }}</span>
              </div>
              <div class="flex justify-between items-baseline pt-2 border-t border-neutral-200 font-semibold text-neutral-900">
                <span>{{ tr('publicReceipt.total') }}</span>
                <span class="font-mono text-lg">{{ fmtMoney(rcpt.totals?.with_vat ?? 0) }}</span>
              </div>
            </div>
          </div>

          <!-- Evidence tržby (EET) -->
          <div v-for="(r, i) in data.eet" :key="'eet' + i" class="bg-surface border border-neutral-200 rounded-xl p-5 shadow-sm text-sm">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
              <h2 class="text-xs font-semibold uppercase tracking-wide text-neutral-500">
                {{ r.kind === 'correction' ? tr('publicReceipt.eet_correction') : tr('publicReceipt.eet_title') }}
              </h2>
              <span v-if="r.playground" class="text-xs px-2 py-0.5 rounded font-medium bg-warning-50 text-warning-600">
                {{ tr('publicReceipt.eet_playground') }}
              </span>
            </div>
            <dl class="grid grid-cols-2 gap-x-4 gap-y-1">
              <dt class="text-neutral-500">{{ tr('publicReceipt.eet_eic') }}</dt><dd class="font-mono">{{ r.eic }}</dd>
              <dt class="text-neutral-500">{{ tr('publicReceipt.eet_premises') }}</dt><dd class="font-mono">{{ r.premises ?? '—' }}</dd>
              <dt class="text-neutral-500">{{ tr('publicReceipt.eet_register') }}</dt><dd class="font-mono break-all">{{ r.cash_register ?? '—' }}</dd>
              <dt class="text-neutral-500">{{ tr('publicReceipt.eet_sequence') }}</dt><dd class="font-mono break-all">{{ r.sequence_number ?? '—' }}</dd>
              <dt class="text-neutral-500">{{ tr('publicReceipt.eet_time') }}</dt><dd>{{ fmtDateTime(r.sale_at) }}</dd>
              <dt class="text-neutral-500">{{ tr('publicReceipt.eet_amount') }}</dt><dd class="font-mono">{{ fmtMoney(r.total_amount, 2) }}</dd>
              <template v-if="r.pok">
                <dt class="text-neutral-500">{{ tr('publicReceipt.eet_pok') }}</dt><dd class="font-mono break-all text-xs">{{ r.pok }}</dd>
              </template>
            </dl>
            <p v-if="!r.confirmed" class="text-xs text-neutral-500 mt-2">{{ tr('publicReceipt.eet_pending') }}</p>
          </div>

          <p class="text-center text-xs text-neutral-500">
            {{ tr('publicReceipt.valid_until', { date: fmtDate(rcpt.expires_at) }) }}
          </p>
        </div>
      </div>
    </main>

    <footer class="border-t border-neutral-200 bg-surface px-4 py-3 text-center text-xs text-neutral-500">
      MyÚčto.cz · {{ tr('publicReceipt.header') }}
    </footer>
  </div>
</template>
