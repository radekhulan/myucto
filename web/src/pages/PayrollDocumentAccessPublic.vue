<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  payrollDocumentAccessApi,
  type PayrollDocumentAccessState,
} from '@/api/payrollDocumentAccess'

const route = useRoute()
// Token se čte JEN odsud a použije se jen jako součást URL API volání a
// stahovacího odkazu — nikam se neloguje ani nekopíruje.
const token = computed(() => String(route.params.token || ''))
const { t } = useI18n()

const loading = ref(true)
const loadError = ref(false)
const state = ref<PayrollDocumentAccessState | null>(null)

type Step = 'intro' | 'code'
const step = ref<Step>('intro')
const code = ref('')
const busy = ref(false)
const authError = ref('')
const cooldown = ref(0)
let cooldownTimer: number | null = null

const KIND_LABELS: Record<string, string> = {
  payslip: t('payrollDocumentAccess.public.kind.payslip'),
  payroll_sheet: t('payrollDocumentAccess.public.kind.payroll_sheet'),
  taxable_income_advance_certificate: t('payrollDocumentAccess.public.kind.taxable_income_advance_certificate'),
  taxable_income_withholding_certificate: t('payrollDocumentAccess.public.kind.taxable_income_withholding_certificate'),
  employment_certificate: t('payrollDocumentAccess.public.kind.employment_certificate'),
  average_earnings_certificate: t('payrollDocumentAccess.public.kind.average_earnings_certificate'),
  average_earnings_statement: t('payrollDocumentAccess.public.kind.average_earnings_statement'),
  annual_settlement_result: t('payrollDocumentAccess.public.kind.annual_settlement_result'),
  monthly_bundle: t('payrollDocumentAccess.public.kind.monthly_bundle'),
}
function kindLabel(kind: string): string {
  return KIND_LABELS[kind] || kind
}

function formatDate(value: string | null): string {
  if (!value) return ''
  const normalized = value.includes('T') ? value : value.replace(' ', 'T')
  const date = new Date(normalized)
  return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat('cs-CZ', {
    dateStyle: 'medium',
  }).format(date)
}
function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${new Intl.NumberFormat('cs-CZ', { maximumFractionDigits: 1 }).format(bytes / 1024)} kB`
  return `${new Intl.NumberFormat('cs-CZ', { maximumFractionDigits: 1 }).format(bytes / (1024 * 1024))} MB`
}
function codeTtlMinutes(): number {
  const seconds = state.value?.code_ttl_seconds ?? 0
  return Math.max(1, Math.round(seconds / 60))
}

function startCooldown(seconds: number): void {
  cooldown.value = Math.max(0, seconds)
  if (cooldownTimer !== null) window.clearInterval(cooldownTimer)
  cooldownTimer = window.setInterval(() => {
    cooldown.value = Math.max(0, cooldown.value - 1)
    if (cooldown.value === 0 && cooldownTimer !== null) {
      window.clearInterval(cooldownTimer)
      cooldownTimer = null
    }
  }, 1000)
}

onMounted(async () => {
  try {
    state.value = await payrollDocumentAccessApi.get(token.value)
    document.title = t('payrollDocumentAccess.public.title')
  } catch {
    // Jediná odpověď na neplatný/prošlý/zneplatněný odkaz — klidná koncová
    // obrazovka bez tlačítka, které by endpoint jen zbytečně bombardovalo.
    loadError.value = true
  } finally {
    loading.value = false
  }
})
onBeforeUnmount(() => {
  if (cooldownTimer !== null) window.clearInterval(cooldownTimer)
})

async function requestCode(resend = false): Promise<void> {
  if (busy.value) return
  authError.value = ''
  busy.value = true
  try {
    const result = await payrollDocumentAccessApi.requestCode(token.value)
    step.value = 'code'
    startCooldown(result.cooldown_remaining || state.value?.resend_cooldown_seconds || 60)
    if (!resend) code.value = ''
  } catch {
    // Odkaz mezitím zneplatnil/prošel — zpět na skoupý stav, ne na hlášku
    // o kódu, který se netýká.
    loadError.value = true
  } finally {
    busy.value = false
  }
}

async function verify(): Promise<void> {
  if (busy.value) return
  const trimmed = code.value.trim()
  if (!/^[0-9]{6}$/.test(trimmed)) {
    authError.value = t('payrollDocumentAccess.public.invalid_code')
    return
  }
  authError.value = ''
  busy.value = true
  try {
    state.value = await payrollDocumentAccessApi.verify(token.value, trimmed)
  } catch (e: any) {
    authError.value = e?.response?.data?.error?.message
      || t('payrollDocumentAccess.public.invalid_code')
  } finally {
    busy.value = false
  }
}

const downloadHref = computed(() => payrollDocumentAccessApi.downloadUrl(token.value))
</script>

<template>
  <div class="min-h-screen bg-neutral-50 flex flex-col">
    <header class="bg-surface border-b border-neutral-200 px-4 py-3">
      <div class="max-w-md mx-auto flex items-center gap-3">
        <div class="w-8 h-8 bg-primary-600 rounded-md flex items-center justify-center text-white font-bold">M</div>
        <div class="text-sm">
          <div class="font-semibold">My<span class="text-primary-700">Účto</span><span class="text-neutral-500">.cz</span></div>
          <div class="text-xs text-neutral-500">{{ t('payrollDocumentAccess.public.title') }}</div>
        </div>
      </div>
    </header>

    <main class="flex-1 px-4 py-8">
      <div class="max-w-md mx-auto">

        <div v-if="loading" class="text-center text-neutral-500 py-16">
          {{ t('payrollDocumentAccess.public.loading') }}
        </div>

        <!-- Neplatný/prošlý/zneplatněný odkaz — jedna a tatáž klidná obrazovka -->
        <div v-else-if="loadError" class="bg-surface border border-neutral-200 rounded-xl p-8 text-center shadow-sm">
          <div class="text-4xl mb-3">⚠</div>
          <h1 class="text-xl font-semibold mb-2 text-neutral-900">{{ t('payrollDocumentAccess.public.link_invalid') }}</h1>
          <p class="text-neutral-600 text-sm">{{ t('payrollDocumentAccess.public.link_invalid_hint') }}</p>
        </div>

        <!-- Ověřený stav: karta dokumentu + stažení -->
        <div v-else-if="state?.verified" class="space-y-4">
          <div class="bg-surface border border-neutral-200 rounded-xl p-6 shadow-sm text-center">
            <div class="w-12 h-12 mx-auto mb-3 rounded-full bg-success-50 flex items-center justify-center text-success-600">
              <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />
              </svg>
            </div>
            <h1 class="text-lg font-semibold text-neutral-900">
              {{ state.document ? kindLabel(state.document.kind) : t('payrollDocumentAccess.public.title') }}
            </h1>
            <p v-if="state.document?.period_start" class="mt-1 text-sm text-neutral-500">
              {{ formatDate(state.document.period_start) }}
            </p>
            <dl v-if="state.document" class="mt-4 grid grid-cols-2 gap-3 text-xs text-left">
              <div>
                <dt class="text-neutral-500">{{ t('payrollDocumentAccess.public.created_at') }}</dt>
                <dd class="mt-0.5 text-neutral-800">{{ formatDate(state.document.created_at) }}</dd>
              </div>
              <div>
                <dt class="text-neutral-500">{{ t('payrollDocumentAccess.public.size') }}</dt>
                <dd class="mt-0.5 text-neutral-800">{{ formatSize(state.document.size_bytes) }}</dd>
              </div>
            </dl>
            <a :href="downloadHref" data-test="download-payroll-document"
              class="mt-6 inline-flex w-full items-center justify-center gap-2 h-12 bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">
              <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
              </svg>
              {{ t('payrollDocumentAccess.public.download') }}
            </a>
          </div>
          <p class="text-xs text-neutral-500 text-center px-4">{{ t('payrollDocumentAccess.public.footer_note') }}</p>
        </div>

        <!-- Neověřeno: krok 1 poslat kód, krok 2 zadat kód -->
        <div v-else class="bg-surface border border-neutral-200 rounded-xl p-6 shadow-sm">
          <h1 class="text-lg font-semibold text-neutral-900 mb-1">{{ t('payrollDocumentAccess.public.auth_title') }}</h1>
          <p class="text-sm text-neutral-600 mb-4">
            {{ t('payrollDocumentAccess.public.recipient_intro') }}
            <strong class="text-neutral-900">{{ state?.recipient_masked }}</strong>
          </p>

          <!-- Krok: úvod, jen tlačítko na odeslání kódu -->
          <div v-if="step === 'intro'" class="space-y-3">
            <button type="button" data-test="request-code" :disabled="busy" @click="requestCode()"
              class="cursor-pointer w-full h-12 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
              {{ busy ? t('payrollDocumentAccess.public.sending_code') : t('payrollDocumentAccess.public.send_code') }}
            </button>
            <details class="text-sm text-neutral-500">
              <summary class="cursor-pointer select-none font-medium text-neutral-600">
                {{ t('payrollDocumentAccess.public.why_code_title') }}
              </summary>
              <p class="mt-2 leading-relaxed">{{ t('payrollDocumentAccess.public.why_code_body') }}</p>
            </details>
          </div>

          <!-- Krok: zadání kódu -->
          <div v-else class="space-y-3">
            <p class="text-sm text-success-600">{{ t('payrollDocumentAccess.public.code_sent') }}</p>
            <div>
              <label class="block text-xs font-medium text-neutral-600 mb-1">
                {{ t('payrollDocumentAccess.public.code_label') }}
              </label>
              <input v-model="code" data-test="access-code" type="text" inputmode="numeric" autocomplete="one-time-code"
                maxlength="6" :placeholder="t('payrollDocumentAccess.public.code_placeholder')"
                class="w-full h-12 px-3 border border-neutral-300 rounded-md text-center text-lg tracking-widest font-mono"
                @keyup.enter="verify" />
              <p class="mt-1 text-xs text-neutral-500">
                {{ t('payrollDocumentAccess.public.code_ttl_hint', { minutes: codeTtlMinutes() }) }}
              </p>
            </div>
            <p v-if="authError" data-test="access-code-error" class="text-sm text-danger-600">{{ authError }}</p>
            <button type="button" data-test="verify-code" :disabled="busy" @click="verify"
              class="cursor-pointer w-full h-12 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
              {{ busy ? t('payrollDocumentAccess.public.verifying') : t('payrollDocumentAccess.public.verify') }}
            </button>
            <button type="button" data-test="resend-code" :disabled="busy || cooldown > 0" @click="requestCode(true)"
              class="cursor-pointer w-full h-10 text-sm text-neutral-600 hover:text-neutral-900 disabled:opacity-50">
              {{ cooldown > 0 ? t('payrollDocumentAccess.public.resend_in', { n: cooldown }) : t('payrollDocumentAccess.public.resend') }}
            </button>
            <p class="text-xs text-neutral-500 leading-relaxed">{{ t('payrollDocumentAccess.public.why_code_body') }}</p>
          </div>
        </div>
      </div>
    </main>

    <footer class="border-t border-neutral-200 bg-surface px-4 py-3 text-center text-xs text-neutral-500">
      MyÚčto.cz
    </footer>
  </div>
</template>
