<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import { ensureLocaleLoaded } from '@/i18n'
import { publicInvoiceApi, type PublicInvoiceData, type PublicInvoiceItem, type PublicInvoiceParty } from '@/api/publicInvoice'
import { appIsoDate } from '@/utils/date'
import { formatDuration, isPreciseUnitPrice, isTimeItem, itemQuantity } from '@/utils/timeBilling'

const { t } = useI18n()
const route = useRoute()
const token = computed(() => String(route.params.token || ''))

const data = ref<PublicInvoiceData | null>(null)
const loading = ref(true)
const loadError = ref<string>('')

const lang = computed(() => data.value?.invoice.language || 'cs')
const inv = computed(() => data.value?.invoice ?? null)
const isVatPayer = computed(() => !!data.value?.supplier.is_vat_payer)

function tt(cs: string, en: string): string {
  return lang.value === 'en' ? en : cs
}

// Státy spotřeby OSS doložky v jazyce DOKLADU (ne aplikace) — stejně jako zbytek náhledu.
const ossCountryNames = computed(() =>
  (data.value?.oss_clause?.countries ?? [])
    .map(c => (lang.value === 'en' ? c.name_en : c.name_cs))
    .join(', '))

const typeLabel = computed(() => {
  switch (inv.value?.invoice_type) {
    case 'proforma':     return tt('Zálohová faktura', 'Proforma invoice')
    case 'credit_note':  return tt('Opravný daňový doklad', 'Credit note')
    case 'tax_document': return tt('Daňový doklad k přijaté platbě', 'Tax document for payment received')
    case 'cancellation': return tt('Storno doklad', 'Cancellation document')
    case 'rent_calendar': return tt('Splátkový kalendář', 'Rent instalment calendar')
    case 'advance_calendar': return tt('Platební kalendář', 'Payment calendar')
    default:            return isVatPayer.value ? tt('Faktura — daňový doklad', 'Invoice — tax document') : tt('Faktura', 'Invoice')
  }
})

// is_paid počítá server stejně jako PDF a e-mail (InvoicePaidState) — zaplacená
// faktura s dodatečně přidanými položkami má doplatek. Status je jen fallback.
const isPaid = computed(() => inv.value?.is_paid ?? inv.value?.status === 'paid')
const isCancelled = computed(() => inv.value?.status === 'cancelled')
const remaining = computed(() => {
  if (!inv.value) return 0
  return Math.round((inv.value.amount_to_pay - inv.value.paid_total) * 100) / 100
})
const isOverdue = computed(() => {
  if (!inv.value || isPaid.value || isCancelled.value || remaining.value <= 0) return false
  return inv.value.due_date < appIsoDate()
})

const statusBadge = computed(() => {
  if (isCancelled.value) return { text: tt('Stornováno', 'Cancelled'), cls: 'bg-neutral-100 text-neutral-600' }
  if (isPaid.value)      return { text: tt('Uhrazeno', 'Paid'), cls: 'bg-success-50 text-success-600' }
  if (isOverdue.value)   return { text: tt('Po splatnosti', 'Overdue'), cls: 'bg-danger-50 text-danger-500' }
  if (inv.value?.payment_status === 'partially_paid' || (inv.value?.paid_total ?? 0) > 0)
    return { text: tt('Částečně uhrazeno', 'Partially paid'), cls: 'bg-warning-50 text-warning-600' }
  return { text: tt('Neuhrazeno', 'Unpaid'), cls: 'bg-primary-50 text-primary-700' }
})

// ─── formátování (vzor ApprovalPublic) ───
const decimals = computed(() => inv.value?.currency_decimals ?? (inv.value?.currency === 'JPY' ? 0 : 2))
function fmtMoney(n: number, dec?: number): string {
  const d = dec ?? decimals.value
  const locale = lang.value === 'en' ? 'en-US' : 'cs-CZ'
  return n.toLocaleString(locale, { minimumFractionDigits: d, maximumFractionDigits: d }) + ' ' + (inv.value?.currency || '')
}
function fmtQty(n: number): string {
  const locale = lang.value === 'en' ? 'en-US' : 'cs-CZ'
  return n.toLocaleString(locale, { maximumFractionDigits: 3 })
}
function fmtRate(r: number): string {
  const locale = lang.value === 'en' ? 'en-US' : 'cs-CZ'
  return r.toLocaleString(locale, { maximumFractionDigits: 2 }) + ' %'
}
function fmtDate(d: string | null): string {
  if (!d) return ''
  const parts = d.slice(0, 10).split('-')
  if (parts.length !== 3) return d
  return lang.value === 'en'
    ? `${parts[2]}.${parts[1]}.${parts[0]}`
    : `${Number(parts[2])}. ${Number(parts[1])}. ${parts[0]}`
}

/** V režimu „ceny s DPH" nese unit_price_without_vat brutto — netto dopočítáme z řádkového základu (vzor PDF šablony). */
function unitPrice(it: PublicInvoiceItem): number {
  if (inv.value?.prices_include_vat && isPreciseUnitPrice(it) && itemQuantity(it) !== 0) {
    return Math.round(it.total_without_vat / itemQuantity(it) * 1e6) / 1e6
  }
  return inv.value?.prices_include_vat && it.quantity !== 0
    ? it.total_without_vat / it.quantity
    : it.unit_price_without_vat
}

function fmtUnitPrice(it: PublicInvoiceItem): string {
  const price = unitPrice(it)
  const precision = isPreciseUnitPrice(it)
    ? Math.max(2, (price.toFixed(6).replace(/0+$/, '').split('.')[1] ?? '').length)
    : 2
  return fmtMoney(price, precision)
}

function partyLines(p?: PublicInvoiceParty | null): string[] {
  if (!p) return []
  const cityLine = [p.zip, p.city].filter(Boolean).join(' ')
  return [p.street || '', cityLine].filter(Boolean) as string[]
}
function partyCountry(p?: PublicInvoiceParty | null): string {
  if (!p) return ''
  return String((lang.value === 'en' ? p.country_name_en : p.country_name_cs) || '')
}
function partyName(p?: PublicInvoiceParty | null): string {
  if (!p) return ''
  return String(p.company_name || [p.first_name, p.last_name].filter(Boolean).join(' '))
}

/**
 * Bezpečná web URL dodavatele pro href — připustí jen http(s), jinak null.
 * Brání javascript:/data: schématu (Vue :href nesanitizuje), které by mohl
 * škodlivý dodavatel uložit do supplier.web a zaútočit na klienta otevírajícího
 * veřejnou stránku. Bez schématu doplní https://.
 */
const supplierWebHref = computed<string | null>(() => {
  const raw = String(data.value?.supplier.web || '').trim()
  if (!raw) return null
  const url = /^[a-z][a-z0-9+.-]*:\/\//i.test(raw) ? raw : `https://${raw}`
  try {
    const proto = new URL(url).protocol.toLowerCase()
    return proto === 'http:' || proto === 'https:' ? url : null
  } catch {
    return null
  }
})

const showPayPanel = computed(() =>
  !!inv.value && !isCancelled.value
  && (isPaid.value || inv.value.payment_method !== 'bank_transfer' || !!data.value?.bank))

const pdfUrl = computed(() => publicInvoiceApi.pdfUrl(token.value, true))
const isdocUrl = computed(() => publicInvoiceApi.isdocUrl(token.value))

function attachmentUrl(attId: number): string {
  return publicInvoiceApi.attachmentUrl(token.value, attId)
}
function fmtBytes(n: number): string {
  if (n < 1024) return `${n} B`
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(0)} kB`
  return `${(n / (1024 * 1024)).toFixed(1)} MB`
}

onMounted(async () => {
  try {
    data.value = await publicInvoiceApi.get(token.value)
    await ensureLocaleLoaded(data.value.invoice.language)
    document.title = `${typeLabel.value} ${inv.value?.varsymbol || ''} — MyÚčto.cz`
  } catch (e: any) {
    loadError.value = e?.response?.data?.error?.message
      || 'Tento odkaz není platný nebo byl zneplatněn. / This link is invalid or has been revoked.'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="min-h-screen bg-neutral-50 flex flex-col">
    <!-- Hlavička -->
    <header class="bg-surface border-b border-neutral-200 px-4 py-3">
      <div class="max-w-3xl mx-auto flex items-center gap-3">
        <div class="w-8 h-8 bg-primary-600 rounded-md flex items-center justify-center text-white font-bold">M</div>
        <div class="text-sm">
          <div class="font-semibold">My<span class="text-primary-700">Účto</span><span class="brand-tld">.cz</span></div>
          <div class="text-xs text-neutral-500">{{ tt('Online náhled faktury', 'Online invoice view') }}</div>
        </div>
      </div>
    </header>

    <main class="flex-1 px-4 py-8">
      <div class="max-w-3xl mx-auto">

        <!-- Loading -->
        <div v-if="loading" class="text-center text-neutral-500 py-16">
          {{ tt('Načítám…', 'Loading…') }}
        </div>

        <!-- Token error -->
        <div v-else-if="loadError" class="bg-surface border border-danger-500/40 rounded-xl p-8 text-center shadow-sm">
          <div class="text-4xl mb-3">⚠</div>
          <h1 class="text-xl font-semibold mb-2">{{ tt('Odkaz není platný', 'Link not valid') }}</h1>
          <p class="text-neutral-600 text-sm">{{ loadError }}</p>
          <p class="text-xs text-neutral-500 mt-4">
            {{ tt('Pokud máte dotaz, kontaktujte prosím dodavatele.', 'If you have a question, please contact the supplier.') }}
          </p>
        </div>

        <div v-else-if="data && inv" class="space-y-4">
          <!-- Hlavička dokladu + PDF -->
          <div class="bg-surface border border-neutral-200 rounded-xl p-6 shadow-sm">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
              <div>
                <div class="text-sm text-neutral-500">{{ typeLabel }}</div>
                <h1 class="text-2xl font-semibold font-mono">{{ inv.varsymbol || '—' }}</h1>
                <span class="inline-block mt-2 text-xs px-2 py-0.5 rounded font-medium" :class="statusBadge.cls">
                  {{ statusBadge.text }}
                </span>
              </div>
              <div class="flex flex-wrap items-center gap-2">
                <a :href="pdfUrl" :class="btnFilled('primary')">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                  </svg>
                  {{ t('invoice.download_pdf', {}, { locale: lang }) }}
                </a>
                <a v-if="data.isdoc_available" :href="isdocUrl" :class="btnOutline('neutral')">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                  </svg>
                  {{ t('invoice.download_isdoc', {}, { locale: lang }) }}
                </a>
              </div>
            </div>
            <div class="mt-4 grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-1 text-sm">
              <div>
                <span class="text-neutral-500 text-xs block">{{ tt('Datum vystavení', 'Issue date') }}</span>
                {{ fmtDate(inv.issue_date) }}
              </div>
              <div v-if="isVatPayer && inv.tax_date && inv.invoice_type !== 'proforma'">
                <span class="text-neutral-500 text-xs block">{{ tt('Datum zdan. plnění', 'Tax date') }}</span>
                {{ fmtDate(inv.tax_date) }}
              </div>
              <div>
                <span class="text-neutral-500 text-xs block">{{ tt('Datum splatnosti', 'Due date') }}</span>
                <span :class="isOverdue ? 'text-danger-500 font-semibold' : ''">{{ fmtDate(inv.due_date) }}</span>
              </div>
            </div>
          </div>

          <!-- Dodavatel / odběratel -->
          <div class="bg-surface border border-neutral-200 rounded-xl shadow-sm grid sm:grid-cols-2 divide-y sm:divide-y-0 sm:divide-x divide-neutral-200">
            <div class="p-6">
              <h2 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-2">{{ tt('Dodavatel', 'Supplier') }}</h2>
              <div class="font-semibold text-neutral-900">{{ partyName(data.supplier) }}</div>
              <div class="text-sm text-neutral-600">
                <div v-for="line in partyLines(data.supplier)" :key="line">{{ line }}</div>
                <div v-if="partyCountry(data.supplier)">{{ partyCountry(data.supplier) }}</div>
              </div>
              <div class="text-sm text-neutral-600 mt-2 space-y-0.5">
                <div v-if="data.supplier.ic"><span class="text-neutral-500">{{ tt('IČ', 'Company ID') }}:</span> {{ data.supplier.ic }}</div>
                <div v-if="data.supplier.dic"><span class="text-neutral-500">{{ tt('DIČ', 'VAT ID') }}:</span> {{ data.supplier.dic }}</div>
                <div v-if="!data.supplier.is_vat_payer" class="text-xs text-neutral-500">{{ tt('Neplátce DPH', 'Not a VAT payer') }}</div>
                <div v-if="data.supplier.commercial_register" class="text-xs text-neutral-500">{{ data.supplier.commercial_register }}</div>
              </div>
            </div>
            <!-- Zjednodušený doklad bez odběratele (§ 30): koncový zákazník se nezobrazuje. -->
            <div v-if="data.client" class="p-6">
              <h2 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-2">{{ tt('Odběratel', 'Customer') }}</h2>
              <div class="font-semibold text-neutral-900">{{ partyName(data.client) }}</div>
              <div class="text-sm text-neutral-600">
                <div v-for="line in partyLines(data.client)" :key="line">{{ line }}</div>
                <div v-if="partyCountry(data.client)">{{ partyCountry(data.client) }}</div>
              </div>
              <div class="text-sm text-neutral-600 mt-2 space-y-0.5">
                <div v-if="data.client.ic"><span class="text-neutral-500">{{ tt('IČ', 'Company ID') }}:</span> {{ data.client.ic }}</div>
                <div v-if="data.client.dic"><span class="text-neutral-500">{{ tt('DIČ', 'VAT ID') }}:</span> {{ data.client.dic }}</div>
                <div v-if="data.client.tax_number"><span class="text-neutral-500">{{ tt('Daňové číslo', 'Tax number') }}:</span> {{ data.client.tax_number }}</div>
              </div>
            </div>
          </div>

          <!-- Platební panel -->
          <div v-if="showPayPanel" class="bg-surface border rounded-xl p-6 shadow-sm"
            :class="isPaid ? 'border-success-500/40' : 'border-neutral-200'">
            <div class="flex flex-col sm:flex-row gap-6 items-start">
              <div v-if="data.qr_data_uri && !isPaid" class="shrink-0 text-center mx-auto sm:mx-0">
                <img :src="data.qr_data_uri" alt="QR" class="w-36 h-36 border border-neutral-200 rounded-lg bg-white" />
                <div class="text-[11px] text-neutral-500 uppercase tracking-wide mt-1">{{ tt('QR platba', 'QR payment') }}</div>
              </div>
              <div class="flex-1 w-full">
                <template v-if="isPaid">
                  <h2 class="text-lg font-semibold text-success-600 mb-1">✓ {{ tt('Uhrazeno', 'Paid') }}</h2>
                  <p class="text-sm text-neutral-600">
                    {{ inv.payment_method === 'card' ? tt('Platba proběhla kartou online.', 'Paid online by card.')
                      : inv.payment_method === 'cash' ? tt('Platba proběhla v hotovosti.', 'Paid in cash.')
                      : tt('Neplaťte prosím znovu.', 'Please do not pay again.') }}
                    <span v-if="inv.paid_at"> {{ tt('Datum úhrady', 'Paid on') }}: {{ fmtDate(inv.paid_at) }}</span>
                  </p>
                </template>
                <template v-else>
                  <div class="flex items-baseline justify-between flex-wrap gap-2 mb-3">
                    <h2 class="text-xs font-semibold uppercase tracking-wide text-neutral-500">
                      {{ tt('Platební údaje', 'Payment details') }}
                    </h2>
                    <div class="text-xl font-bold font-mono" :class="isOverdue ? 'text-danger-500' : 'text-neutral-900'">
                      {{ fmtMoney(remaining) }}
                    </div>
                  </div>
                  <div v-if="inv.payment_method === 'card'" class="text-sm text-neutral-600">
                    {{ tt('Úhrada platební kartou — bankovní převod se nepoužije.', 'Card payment — bank transfer is not used.') }}
                  </div>
                  <div v-else-if="inv.payment_method === 'cash'" class="text-sm text-neutral-600">
                    {{ tt('Úhrada v hotovosti.', 'Cash payment.') }}
                  </div>
                  <div v-else-if="data.bank" class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1.5 text-sm">
                    <div v-if="data.bank.account_number">
                      <span class="text-neutral-500 text-xs block">{{ tt('Číslo účtu', 'Account number') }}</span>
                      <span class="font-mono">{{ data.bank.account_number }}<template v-if="data.bank.bank_code"> / {{ data.bank.bank_code }}</template></span>
                    </div>
                    <div v-if="inv.payment_varsymbol || inv.varsymbol">
                      <span class="text-neutral-500 text-xs block">{{ tt('Variabilní symbol', 'Variable symbol') }}</span>
                      <span class="font-mono">{{ inv.payment_varsymbol || inv.varsymbol }}</span>
                    </div>
                    <div v-if="data.bank.iban">
                      <span class="text-neutral-500 text-xs block">IBAN</span>
                      <span class="font-mono break-all">{{ data.bank.iban }}</span>
                    </div>
                    <div v-if="data.bank.bic">
                      <span class="text-neutral-500 text-xs block">BIC / SWIFT</span>
                      <span class="font-mono">{{ data.bank.bic }}</span>
                    </div>
                    <div v-if="data.bank.bank_name" class="sm:col-span-2 text-xs text-neutral-500">
                      {{ tt('Banka', 'Bank') }}: {{ data.bank.bank_name }}
                    </div>
                  </div>
                  <div v-if="inv.paid_total > 0" class="mt-2 text-xs text-warning-600">
                    {{ tt('Částečně uhrazeno', 'Partially paid') }}: {{ fmtMoney(inv.paid_total) }} —
                    {{ tt('zbývá uhradit', 'remaining') }} {{ fmtMoney(remaining) }}
                  </div>
                </template>
              </div>
            </div>
          </div>

          <!-- Položky + součty -->
          <div class="bg-surface border border-neutral-200 rounded-xl shadow-sm overflow-hidden">
            <div v-if="inv.note_above_items" class="px-6 pt-4 text-sm text-neutral-700 whitespace-pre-wrap">{{ inv.note_above_items }}</div>
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                  <tr>
                    <th class="px-4 py-2 text-left font-medium">{{ tt('Popis', 'Description') }}</th>
                    <th class="px-3 py-2 text-right font-medium whitespace-nowrap">{{ tt('Množství', 'Qty') }}</th>
                    <th class="px-3 py-2 text-left font-medium">{{ tt('MJ', 'Unit') }}</th>
                    <th class="px-3 py-2 text-right font-medium whitespace-nowrap">{{ tt('Cena/MJ', 'Unit price') }}</th>
                    <th v-if="isVatPayer" class="px-3 py-2 text-center font-medium whitespace-nowrap">{{ tt('DPH', 'VAT') }}</th>
                    <th v-if="isVatPayer" class="px-3 py-2 text-right font-medium whitespace-nowrap">{{ tt('Bez DPH', 'Excl. VAT') }}</th>
                    <th class="px-3 py-2 text-right font-medium whitespace-nowrap">{{ isVatPayer ? tt('S DPH', 'Incl. VAT') : tt('Celkem', 'Total') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="(it, i) in inv.items" :key="i">
                    <!-- Textový řádek: jen text přes celou šířku tabulky (stejně jako v PDF). -->
                    <td v-if="it.item_kind === 'text'" :colspan="isVatPayer ? 7 : 5"
                      class="px-4 py-2 whitespace-pre-wrap font-medium text-neutral-800" data-test="public-text-row">{{ it.description }}</td>
                    <template v-else>
                    <td class="px-4 py-2 whitespace-pre-wrap text-neutral-800">{{ it.description }}</td>
                    <template v-if="it.item_kind === 'discount'">
                      <td></td><td></td><td></td>
                    </template>
                    <template v-else>
                      <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ isTimeItem(it) && it.duration_minutes != null ? formatDuration(it.duration_minutes) : fmtQty(it.quantity) }}</td>
                      <td class="px-3 py-2 text-neutral-600">{{ it.unit }}</td>
                      <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ fmtUnitPrice(it) }}</td>
                    </template>
                    <td v-if="isVatPayer" class="px-3 py-2 text-center whitespace-nowrap text-neutral-600">{{ fmtRate(it.vat_rate_snapshot) }}</td>
                    <td v-if="isVatPayer" class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(it.total_without_vat, 2) }}</td>
                    <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(isVatPayer ? it.total_with_vat : it.total_without_vat, 2) }}</td>
                    </template>
                  </tr>
                </tbody>
              </table>
            </div>

            <div v-if="inv.reverse_charge" class="px-6 py-3 text-xs text-neutral-600 border-t border-neutral-100">
              {{ (data.client?.country_iso2 || 'CZ') === 'CZ'
                  ? tt('Daň odvede zákazník (přenesená daňová povinnost dle § 92a zákona o DPH).',
                       'Reverse charge — VAT to be accounted for by the customer.')
                  : tt('Daň odvede zákazník (přenesení daňové povinnosti dle čl. 196 směrnice 2006/112/ES).',
                       'Reverse charge — VAT to be accounted for by the customer pursuant to Article 196 of Council Directive 2006/112/EC.') }}
            </div>

            <!-- OSS doložka — musí znít stejně jako v PDF (api/templates/invoice/invoice.twig),
                 podklad staví stejný SSOT na backendu (OssInvoiceClause). -->
            <div v-if="data.oss_clause" class="px-6 py-3 text-xs text-neutral-600 border-t border-neutral-100">
              {{ data.oss_clause.all_items
                  ? tt('Daň je přiznána a odvedena ve státě spotřeby v režimu jednoho správního místa (One Stop Shop) podle § 110a a násl. zákona o DPH.',
                       'VAT is declared and paid in the Member State of consumption under the One Stop Shop scheme pursuant to Articles 369a et seq. of Council Directive 2006/112/EC.')
                  : tt('U položek v režimu jednoho správního místa (One Stop Shop) je daň přiznána a odvedena ve státě spotřeby podle § 110a a násl. zákona o DPH.',
                       'For the items covered by the One Stop Shop scheme, VAT is declared and paid in the Member State of consumption pursuant to Articles 369a et seq. of Council Directive 2006/112/EC.') }}
              <template v-if="data.oss_clause.countries.length">
                {{ data.oss_clause.countries.length > 1
                    ? tt('Státy spotřeby', 'Member States of consumption')
                    : tt('Stát spotřeby', 'Member State of consumption') }}:
                {{ ossCountryNames }}.
              </template>
            </div>

            <!-- Součty -->
            <div class="border-t border-neutral-200 px-6 py-4 flex justify-end">
              <div class="w-full sm:w-80 space-y-1 text-sm">
                <template v-if="isVatPayer && inv.invoice_type !== 'proforma'">
                  <div v-for="b in inv.vat_breakdown" :key="b.rate" class="contents">
                    <div class="flex justify-between text-neutral-600">
                      <span>{{ tt('Základ', 'Base') }} {{ fmtRate(b.rate) }}</span>
                      <span class="font-mono">{{ fmtMoney(b.base, 2) }}</span>
                    </div>
                    <div v-if="b.vat > 0" class="flex justify-between text-neutral-600">
                      <span>{{ tt('DPH', 'VAT') }} {{ fmtRate(b.rate) }}</span>
                      <span class="font-mono">{{ fmtMoney(b.vat, 2) }}</span>
                    </div>
                  </div>
                  <div v-if="inv.vat_breakdown.length > 1" class="flex justify-between text-neutral-600 pt-1 border-t border-neutral-100">
                    <span>{{ tt('Celkem bez DPH', 'Total without VAT') }}</span>
                    <span class="font-mono">{{ fmtMoney(inv.totals.without_vat, 2) }}</span>
                  </div>
                </template>
                <div v-if="inv.totals.rounding" class="flex justify-between text-neutral-600">
                  <span>{{ tt('Zaokrouhlení', 'Rounding') }}</span>
                  <span class="font-mono">{{ fmtMoney(inv.totals.rounding, 2) }}</span>
                </div>
                <div v-if="inv.totals.advance_paid_amount" class="flex justify-between text-neutral-600">
                  <span>{{ tt('Uhrazená záloha', 'Advance paid') }}</span>
                  <span class="font-mono">−{{ fmtMoney(inv.totals.advance_paid_amount, 2) }}</span>
                </div>
                <div class="flex justify-between items-baseline pt-2 border-t border-neutral-200 font-semibold text-neutral-900">
                  <span>{{ tt('Celkem k úhradě', 'Total due') }}</span>
                  <span class="font-mono text-lg">{{ fmtMoney(inv.totals.amount_to_pay) }}</span>
                </div>
                <div v-if="inv.czk_recap" class="flex justify-between text-xs text-neutral-500 pt-1">
                  <span>{{ tt('Přepočet', 'Converted') }} ({{ tt('kurz', 'rate') }} {{ inv.czk_recap.rate }} / {{ fmtDate(inv.czk_recap.rate_date) }})</span>
                  <span class="font-mono">{{ inv.czk_recap.total_with_vat_czk.toLocaleString(lang === 'en' ? 'en-US' : 'cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }} CZK</span>
                </div>
              </div>
            </div>

            <div v-if="inv.note_below_items" class="px-6 pb-4 text-sm text-neutral-600 whitespace-pre-wrap">{{ inv.note_below_items }}</div>
          </div>

          <!-- Přílohy -->
          <div v-if="data.attachments.length" class="bg-surface border border-neutral-200 rounded-xl shadow-sm p-6">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">
              {{ tt('Přílohy', 'Attachments') }}
            </h2>
            <ul class="divide-y divide-neutral-100">
              <li v-for="a in data.attachments" :key="a.id">
                <a :href="attachmentUrl(a.id)"
                  class="flex items-center gap-3 py-2 group" download>
                  <svg class="w-4 h-4 text-neutral-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 1 0 2.828 2.828l6.414-6.586a4 4 0 0 0-5.656-5.656l-6.415 6.585a6 6 0 1 0 8.486 8.486L20.5 13" />
                  </svg>
                  <span class="text-sm text-primary-700 group-hover:underline break-all">{{ a.original_name }}</span>
                  <span class="text-xs text-neutral-500 shrink-0 ml-auto">{{ fmtBytes(a.size_bytes) }}</span>
                </a>
              </li>
            </ul>
          </div>

          <!-- Kontakt na dodavatele -->
          <div v-if="data.supplier.email || supplierWebHref" class="text-center text-xs text-neutral-500">
            {{ tt('Dotazy k faktuře', 'Invoice questions') }}:
            <a v-if="data.supplier.email" :href="`mailto:${data.supplier.email}`" class="text-primary-700 hover:underline">{{ data.supplier.email }}</a>
            <template v-if="data.supplier.email && supplierWebHref"> · </template>
            <a v-if="supplierWebHref" :href="supplierWebHref" target="_blank" rel="noopener" class="text-primary-700 hover:underline">{{ data.supplier.web }}</a>
          </div>
        </div>
      </div>
    </main>

    <footer class="border-t border-neutral-200 bg-surface px-4 py-3 text-center text-xs text-neutral-500">
      MyÚčto.cz · {{ tt('Online náhled faktury', 'Online invoice view') }}
    </footer>
  </div>
</template>
