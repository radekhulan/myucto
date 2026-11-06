<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { publicPurchaseApprovalApi, type PublicPurchaseApproval } from '@/api/purchaseApprovals'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import PdfFrame from '@/components/documents/PdfFrame.vue'

/**
 * Veřejná stránka schválení přijatého dokladu z e-mailu (bez přihlášení, token v odkazu).
 * Vzor: ApprovalPublic.vue (schvalování výkazu práce).
 */
const { t, locale } = useI18n()
const route = useRoute()
const token = computed(() => String(route.params.token || ''))

const data = ref<PublicPurchaseApproval | null>(null)
const loading = ref(true)
const loadError = ref('')

type Mode = 'review' | 'reject_form'
const mode = ref<Mode>('review')
const comment = ref('')
const reason = ref('')
const submitting = ref(false)
const submitError = ref('')
const showPdf = ref(false)

const isPending = computed(() => !!data.value && data.value.status === 'pending' && !data.value.expired)
const isExpired = computed(() => !!data.value && data.value.status === 'pending' && data.value.expired)

function intlLocale(): string {
  return locale.value === 'en' ? 'en-US' : 'cs-CZ'
}
function fmtMoney(n: number | null | undefined, currency: string): string {
  const decimals = currency === 'JPY' ? 0 : 2
  return Number(n ?? 0).toLocaleString(intlLocale(), { minimumFractionDigits: decimals, maximumFractionDigits: decimals }) + ' ' + currency
}
function fmtNumber(n: number | null | undefined): string {
  return Number(n ?? 0).toLocaleString(intlLocale(), { maximumFractionDigits: 3 })
}
function fmtDate(d: string | null | undefined): string {
  if (!d) return ''
  const p = d.slice(0, 10).split('-')
  if (p.length !== 3) return d
  return locale.value === 'en' ? `${p[2]}.${p[1]}.${p[0]}` : `${Number(p[2])}. ${Number(p[1])}. ${p[0]}`
}

onMounted(async () => {
  document.title = `${t('purchase_approval.public.page_title')} - MyÚčto.cz`
  try {
    data.value = await publicPurchaseApprovalApi.get(token.value)
  } catch (e: any) {
    loadError.value = e?.response?.data?.error?.message || t('purchase_approval.public.invalid_text')
  } finally {
    loading.value = false
  }
})

async function submit(decision: 'approve' | 'reject') {
  if (!data.value || submitting.value) return
  submitError.value = ''
  if (decision === 'reject' && !reason.value.trim()) {
    submitError.value = t('purchase_approval.public.reject_reason_required')
    return
  }
  submitting.value = true
  try {
    data.value = await publicPurchaseApprovalApi.decide(token.value, {
      decision,
      comment: (decision === 'reject' ? reason.value : comment.value).trim() || null,
    })
    mode.value = 'review'
  } catch (e: any) {
    submitError.value = e?.response?.data?.error?.message || t('purchase_approval.public.action_failed')
    // Rozhodnuto jinde nebo odkaz vypršel: načti aktuální stav, ať stránka ukáže výsledek.
    if (e?.response?.status === 409 || e?.response?.status === 410) {
      try { data.value = await publicPurchaseApprovalApi.get(token.value); mode.value = 'review' } catch { /* ponech chybu */ }
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="min-h-screen bg-neutral-50 flex flex-col">
    <header class="bg-surface border-b border-neutral-200 px-4 py-3">
      <div class="max-w-3xl mx-auto flex items-center gap-3">
        <img v-if="data?.company.logo_url" :src="data.company.logo_url" alt="" class="h-8 max-w-[10rem] object-contain object-left" />
        <div v-else class="w-8 h-8 rounded-md flex items-center justify-center text-white font-bold shrink-0 bg-primary-600">
          {{ data?.company.name?.trim().charAt(0).toUpperCase() || 'M' }}
        </div>
        <div class="text-sm min-w-0">
          <div v-if="data?.company.name" class="font-semibold truncate" data-test="public-company">{{ data.company.name }}</div>
          <div v-else class="font-semibold">My<span class="text-primary-700">Účto</span><span class="text-neutral-500">.cz</span></div>
          <div class="text-xs text-neutral-500">{{ t('purchase_approval.public.page_title') }}</div>
        </div>
      </div>
    </header>

    <main class="flex-1 px-4 py-8">
      <div class="max-w-3xl mx-auto">
        <div v-if="loading" class="text-center text-neutral-500 py-16">{{ t('purchase_approval.public.loading') }}</div>

        <!-- Neplatný odkaz -->
        <div v-else-if="loadError" class="bg-surface border border-danger-500/40 rounded-xl p-8 text-center shadow-sm" data-test="public-invalid">
          <h1 class="text-xl font-semibold mb-2">{{ t('purchase_approval.public.invalid_title') }}</h1>
          <p class="text-neutral-600 text-sm">{{ loadError }}</p>
        </div>

        <template v-else-if="data">
          <!-- Platnost odkazu vypršela -->
          <div v-if="isExpired" class="bg-surface border border-warning-500/40 rounded-xl p-8 text-center shadow-sm" data-test="public-expired">
            <h1 class="text-xl font-semibold mb-2 text-warning-600">{{ t('purchase_approval.public.expired_title') }}</h1>
            <p class="text-neutral-600 text-sm">{{ t('purchase_approval.public.expired_text') }}</p>
          </div>

          <!-- Už rozhodnuto / zrušeno -->
          <div v-else-if="!isPending" class="bg-surface border rounded-xl p-8 text-center shadow-sm"
               :class="data.status === 'approved' ? 'border-success-500/40' : data.status === 'rejected' ? 'border-warning-500/40' : 'border-neutral-200'"
               :data-test="`public-${data.status}`">
            <div class="text-5xl mb-3" aria-hidden="true">{{ data.status === 'approved' ? '✓' : data.status === 'rejected' ? '✕' : '–' }}</div>
            <h1 class="text-2xl font-semibold mb-3"
                :class="data.status === 'approved' ? 'text-success-600' : data.status === 'rejected' ? 'text-warning-600' : 'text-neutral-700'">
              {{ t(`purchase_approval.public.${data.status}_title`) }}
            </h1>
            <p class="text-neutral-700">{{ t(`purchase_approval.public.${data.status}_text`) }}</p>
            <p v-if="data.comment && data.status === 'rejected'" class="text-sm text-neutral-600 mt-4 bg-neutral-50 rounded-lg p-3 text-left whitespace-pre-wrap">
              <span class="text-neutral-500">{{ t('purchase_approval.public.stated_reason') }}:</span> {{ data.comment }}
            </p>
            <p class="text-xs text-neutral-500 mt-4">
              <span v-if="data.invoice.document_number">{{ data.invoice.document_number }}</span>
              <span v-if="data.decided_at"> · {{ t('purchase_approval.public.decided_at', { date: fmtDate(data.decided_at) }) }}</span>
              <span v-if="data.invoice.supplier_name"> · {{ data.invoice.supplier_name }}</span>
            </p>
          </div>

          <!-- Ke schválení -->
          <div v-else class="space-y-4">
            <div class="bg-surface border border-neutral-200 rounded-xl p-6 shadow-sm">
              <h1 class="text-xl font-semibold mb-2">{{ t('purchase_approval.public.heading') }}</h1>
              <p v-if="data.approver_name && data.dimension_value" class="text-sm text-neutral-600 mb-3">
                {{ t('purchase_approval.public.intro', { approver: data.approver_name, center: data.dimension_value.name }) }}
              </p>
              <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1.5 text-sm">
                <div><dt class="inline text-neutral-500">{{ t('purchase_approval.public.supplier') }}: </dt><dd class="inline font-medium">{{ data.invoice.supplier_name }}</dd></div>
                <div v-if="data.invoice.supplier_ico"><dt class="inline text-neutral-500">{{ t('purchase_approval.public.ico') }}: </dt><dd class="inline font-mono">{{ data.invoice.supplier_ico }}</dd></div>
                <div v-if="data.invoice.document_number"><dt class="inline text-neutral-500">{{ t('purchase_approval.col.document') }}: </dt><dd class="inline font-mono">{{ data.invoice.document_number }}</dd></div>
                <div v-if="data.invoice.issue_date"><dt class="inline text-neutral-500">{{ t('purchase_approval.public.issue_date') }}: </dt><dd class="inline">{{ fmtDate(data.invoice.issue_date) }}</dd></div>
                <div v-if="data.invoice.tax_date"><dt class="inline text-neutral-500">{{ t('purchase_approval.public.tax_date') }}: </dt><dd class="inline">{{ fmtDate(data.invoice.tax_date) }}</dd></div>
                <div v-if="data.invoice.due_date"><dt class="inline text-neutral-500">{{ t('purchase_approval.public.due_date') }}: </dt><dd class="inline">{{ fmtDate(data.invoice.due_date) }}</dd></div>
                <div v-if="data.dimension_value"><dt class="inline text-neutral-500">{{ t('purchase_approval.public.center') }}: </dt><dd class="inline">{{ data.dimension_value.name }}</dd></div>
              </dl>
            </div>

            <div class="bg-surface border border-neutral-200 rounded-xl shadow-sm overflow-hidden">
              <header class="px-6 py-3 border-b border-neutral-200">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('purchase_approval.public.items') }}</h2>
              </header>
              <div class="overflow-x-auto">
                <table class="w-full text-sm">
                  <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                    <tr>
                      <th class="px-4 py-2 text-left font-medium">{{ t('purchase_approval.public.description') }}</th>
                      <th class="px-3 py-2 text-right font-medium whitespace-nowrap">{{ t('purchase_approval.public.quantity') }}</th>
                      <th class="px-3 py-2 text-left font-medium">{{ t('purchase_approval.public.unit') }}</th>
                      <th class="px-3 py-2 text-right font-medium whitespace-nowrap">{{ t('purchase_approval.public.unit_price') }}</th>
                      <th class="px-3 py-2 text-right font-medium whitespace-nowrap">{{ t('purchase_approval.public.total_without_vat') }}</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-neutral-100">
                    <tr v-for="(it, i) in data.invoice.items" :key="i">
                      <td class="px-4 py-2 whitespace-pre-wrap text-neutral-800">{{ it.description }}</td>
                      <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ fmtNumber(it.quantity) }}</td>
                      <td class="px-3 py-2 text-neutral-600">{{ it.unit }}</td>
                      <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(it.unit_price, data.invoice.currency) }}</td>
                      <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(it.total_without_vat, data.invoice.currency) }}</td>
                    </tr>
                    <tr class="bg-neutral-50 text-neutral-600">
                      <td class="px-4 py-2 text-right" colspan="4">{{ t('purchase_approval.public.total_vat') }}</td>
                      <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(data.invoice.total_vat, data.invoice.currency) }}</td>
                    </tr>
                    <tr class="bg-neutral-50 font-semibold">
                      <td class="px-4 py-2 text-right" colspan="4">{{ t('purchase_approval.public.total_with_vat') }}</td>
                      <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(data.invoice.total_with_vat, data.invoice.currency) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <div class="bg-surface border border-neutral-200 rounded-xl p-4 shadow-sm space-y-3">
              <div class="flex flex-wrap items-center gap-2">
                <template v-if="data.invoice.has_pdf">
                  <button type="button" :class="btnOutline('neutral')" data-test="public-pdf-toggle" @click="showPdf = !showPdf">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
                    {{ t('purchase_approval.public.pdf') }}
                  </button>
                  <a :href="publicPurchaseApprovalApi.pdfUrl(token)" target="_blank" rel="noopener" :class="btnOutline('neutral')">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
                    PDF
                  </a>
                </template>
                <span v-else class="text-sm text-neutral-500">{{ t('purchase_approval.public.no_pdf') }}</span>
              </div>
              <PdfFrame v-if="showPdf && data.invoice.has_pdf" :src="publicPurchaseApprovalApi.pdfUrl(token)"
                        :title="t('purchase_approval.public.pdf')" class="block w-full h-[70vh] border border-neutral-200 rounded-md" />
            </div>

            <div class="bg-surface border border-neutral-200 rounded-xl p-6 shadow-sm space-y-4">
              <div v-if="mode === 'review'">
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('purchase_approval.public.comment') }}</label>
                <textarea v-model="comment" rows="2" maxlength="500" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm" data-test="public-comment"></textarea>
              </div>
              <div v-else>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('purchase_approval.public.reject_reason') }} *</label>
                <textarea v-model="reason" rows="4" maxlength="500" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm" data-test="public-reason"></textarea>
              </div>

              <div v-if="submitError" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500" data-test="public-error">{{ submitError }}</div>

              <div v-if="mode === 'review'" class="flex flex-wrap gap-3">
                <button type="button" :disabled="submitting" :class="btnFilled('success')" class="flex-1 justify-center !h-11 text-base" data-test="public-approve" @click="submit('approve')">
                  <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                  {{ submitting ? t('purchase_approval.public.submitting') : t('purchase_approval.public.approve') }}
                </button>
                <button type="button" :disabled="submitting" :class="btnOutline('danger')" class="!h-11" data-test="public-reject" @click="mode = 'reject_form'">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                  {{ t('purchase_approval.public.reject') }}
                </button>
              </div>
              <div v-else class="flex flex-wrap justify-end gap-3">
                <button type="button" :disabled="submitting" :class="btnOutline('neutral')" @click="mode = 'review'">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
                  {{ t('purchase_approval.public.back') }}
                </button>
                <button type="button" :disabled="submitting" :class="btnFilled('danger')" data-test="public-reject-confirm" @click="submit('reject')">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                  {{ submitting ? t('purchase_approval.public.submitting') : t('purchase_approval.public.reject_confirm') }}
                </button>
              </div>
            </div>
          </div>
        </template>
      </div>
    </main>

    <footer class="border-t border-neutral-200 bg-surface px-4 py-3 text-center text-xs text-neutral-500">
      <template v-if="data?.company.name">{{ data.company.name }} · </template>
      {{ t('purchase_approval.public.footer') }} MyÚčto.cz
    </footer>
  </div>
</template>
