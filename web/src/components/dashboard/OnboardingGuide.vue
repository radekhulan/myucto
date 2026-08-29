<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { preferencesApi } from '@/api/preferences'

/**
 * Průvodce prvním nastavením na Přehledu.
 *
 * Why: čerstvě založená instance (typicky SaaS provisioning) přivítá uživatele
 * prázdným dashboardem — všechno podstatné (údaje firmy, daňový režim, bankovní
 * účet, logo na fakturách) je schované v menu Systém, kam se nový uživatel
 * nedostane sám. Průvodce je proto rozcestník, ne validátor: NEHÁDÁ z dat, co je
 * hotové, protože „hotovo" u většiny kroků stejně určuje jen uživatel (kolik
 * bankovních účtů je dost, jestli je logo to finální). Odškrtává se ručně.
 *
 * Stav (odškrtnuté kroky + skrytí) žije v user preference `onboarding.guide` —
 * per uživatel, ne per firma: je to stav přečtení návodu, ne stav dat. Selhání
 * uložení průvodce neshazuje; stav zůstane aspoň v rámci session.
 */
const { t } = useI18n()
const auth = useAuthStore()
const supplierStore = useSupplierStore()

const emit = defineEmits<{ 'update:visible': [boolean] }>()

const PREF_KEY = 'onboarding.guide'

interface GuidePrefs {
  hidden?: boolean
  done?: string[]
}

/** Ikony kroků — stejné 24px outline paths jako v hlavním menu. */
const ICONS: Record<string, string> = {
  company:   'M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5m-4 0h4',
  taxes:     'M3 10h18M3 14h18M5 21V3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v18M9 7h6M9 11h6M9 15h6',
  bank:      'M3 9l9-7 9 7m-2 0v9a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V9m4 11V13h4v7',
  branding:  'M4 16l4.586-4.586a2 2 0 0 1 2.828 0L16 16m-2-2 1.586-1.586a2 2 0 0 1 2.828 0L20 14m-6-6h.01M6 20h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2z',
  numbering: 'M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z',
  series:    'M4 6h16M4 12h16M4 18h16M7 4v4M13 10v4M17 16v4',
  bank_email:'M3 8l7.89 5.26a2 2 0 0 0 2.22 0L21 8M5 19h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2z',
  suppliers: 'M17 20h5v-2a4 4 0 0 0-3-3.87M9 20H4v-2a3 3 0 0 1 5.356-1.857M15 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0zM23 11a4 4 0 1 1-8 0 4 4 0 0 1 8 0z',
  databox:   'M19 11H5m14 0a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2m14 0V9a2 2 0 0 0-2-2M5 11V9a2 2 0 0 1 2-2m0 0V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v2M7 7h10',
  users:     'M17 20h5v-2a4 4 0 0 0-3-3.87M9 20H4v-2a3 3 0 0 1 5.356-1.857M15 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0z',
  client:    'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0zM12 14a7 7 0 0 0-7 7h14a7 7 0 0 0-7-7z',
  invoice:   'M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z',
  // Jiskra - AI extrakce polozek z PDF faktury.
  ai_extraction: 'M5 3v4M3 5h4M6 17v4m-2-2h4m5-16 2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z',
  check:     'M5 13l4 4L19 7',
}

type Accent = 'primary' | 'accent' | 'success'

interface Step {
  id: string
  to: string
  /** Popisek odkazu; bez něj se použije obecné „Otevřít". */
  cta?: string
  /** Viditelnost podle práv — krok, na který uživatel nesmí, se nezobrazí. */
  visible: () => boolean
}

interface Group {
  key: string
  accent: Accent
  steps: Step[]
}

const isSuperadmin = computed(() => auth.isSuperadmin)
/** Číselné řady deníku dávají smysl až v podvojném účetnictví — jinde krok nenabízíme. */
const isDoubleEntry = computed(() => supplierStore.currentSupplier?.accounting_mode === 'double_entry')
/**
 * Datovou schránku firma zřizuje kvůli mzdovým podáním (ČSSZ, zdravotní
 * pojišťovny, JMHZ). S vypnutými mzdami nemá co odesílat, takže krok
 * nenabízíme — stejně jako mizí i z menu. Mzdy jsou opt-in, undefined = vypnuto.
 */
const payrollEnabled = computed(() => supplierStore.currentSupplier?.payroll_enabled === true)

/**
 * Účtování dalších firem nabízíme jen tomu, kdo na ně má licenci a ještě mu
 * zbývá místo. `max_companies === null` = neomezeně; bez licence radši nic —
 * poslat uživatele zakládat firmu, kterou mu licence stejně zamítne, je horší
 * než krok nenabídnout.
 */
const canAddCompanies = computed(() => {
  const lic = auth.license
  if (!lic) return false
  if (lic.max_companies === null) return true
  return lic.max_companies > 1 && lic.companies_active < lic.max_companies
})

const GROUPS: Group[] = [
  {
    key: 'company',
    accent: 'primary',
    steps: [
      { id: 'company',   to: '/admin/settings?tab=company',    visible: () => auth.canRead('settings.company') },
      { id: 'taxes',     to: '/admin/settings?tab=accounting', visible: () => auth.canRead('settings.company') },
      { id: 'bank',      to: '/bank?tab=accounts',             visible: () => auth.canRead('bank') },
      { id: 'branding',  to: '/admin/branding',                visible: () => auth.canRead('settings.branding') },
      { id: 'suppliers', to: '/admin/suppliers',               visible: () => isSuperadmin.value && canAddCompanies.value },
    ],
  },
  {
    key: 'documents',
    accent: 'accent',
    steps: [
      { id: 'numbering', to: '/admin/settings?tab=documents',  visible: () => auth.canRead('settings.company') },
      { id: 'series',    to: '/utilities?section=document-series', visible: () => isDoubleEntry.value && auth.canRead('utilities') },
      { id: 'bank_email', to: '/bank?tab=email',               visible: () => auth.canRead('bank') },
      { id: 'databox',   to: '/admin/databox',                 visible: () => isSuperadmin.value && payrollEnabled.value },
      { id: 'ai_extraction', to: '/admin/integrations?tab=ai', visible: () => auth.canWrite('settings.company') },
      { id: 'users',     to: '/admin/users',                   visible: () => isSuperadmin.value },
    ],
  },
  {
    key: 'start',
    accent: 'success',
    steps: [
      { id: 'client',  to: '/clients/new',  cta: 'client',  visible: () => auth.canWrite('clients') },
      { id: 'invoice', to: '/invoices/new', cta: 'invoice', visible: () => auth.canWrite('invoices') },
    ],
  },
]

const groups = computed(() => GROUPS
  .map(g => ({ ...g, steps: g.steps.filter(s => s.visible()) }))
  .filter(g => g.steps.length > 0))

const allSteps = computed(() => groups.value.flatMap(g => g.steps))

/** Pořadové číslo kroku napříč skupinami — číslují se průběžně, ne od jedničky v každé. */
function stepNumber(id: string): number {
  return allSteps.value.findIndex(s => s.id === id) + 1
}

const done = ref<Set<string>>(new Set())
const hidden = ref(false)
const loaded = ref(false)

const doneCount = computed(() => allSteps.value.filter(s => done.value.has(s.id)).length)
const totalCount = computed(() => allSteps.value.length)
const percent = computed(() => (totalCount.value === 0 ? 0 : Math.round((doneCount.value / totalCount.value) * 100)))
const allDone = computed(() => totalCount.value > 0 && doneCount.value === totalCount.value)

/** Obvod kruhu progressu (r = 26) — pro stroke-dasharray. */
const RING = 2 * Math.PI * 26
const ringOffset = computed(() => RING * (1 - percent.value / 100))

const visible = computed(() => loaded.value && !hidden.value)

function emitVisible() {
  emit('update:visible', visible.value)
}

async function persist() {
  try {
    await preferencesApi.putPreferenceKey<GuidePrefs>(PREF_KEY, {
      hidden: hidden.value,
      done: Array.from(done.value),
    })
  } catch {
    // Průvodce je pomůcka — když se preference neuloží, nesmí to shodit Přehled.
  }
}

function toggle(id: string) {
  if (done.value.has(id)) done.value.delete(id)
  else done.value.add(id)
  done.value = new Set(done.value)
  void persist()
}

function hide() {
  hidden.value = true
  emitVisible()
  void persist()
}

function show() {
  hidden.value = false
  emitVisible()
  void persist()
}

onMounted(async () => {
  try {
    const prefs = await preferencesApi.getPreferenceKey<GuidePrefs>(PREF_KEY)
    hidden.value = prefs?.hidden === true
    done.value = new Set(Array.isArray(prefs?.done) ? prefs!.done!.filter(x => typeof x === 'string') : [])
  } catch {
    // Bez uložených preferencí se průvodce prostě ukáže celý.
  } finally {
    loaded.value = true
    emitVisible()
  }
})

/*
 * Tinty se musí psát jako celé literály — Tailwind skenuje zdroj staticky a
 * skládané třídy (`bg-${accent}-500/10`) by do buildu vůbec nedoputovaly.
 */
const TINT: Record<Accent, { tile: string; icon: string; ring: string }> = {
  primary: { tile: 'bg-primary-500/10',  icon: 'text-primary-600',  ring: 'group-hover:border-primary-500/40' },
  accent:  { tile: 'bg-accent-500/10',   icon: 'text-accent-600',   ring: 'group-hover:border-accent-500/40' },
  success: { tile: 'bg-success-500/10',  icon: 'text-success-600',  ring: 'group-hover:border-success-500/40' },
}
</script>

<template>
  <!-- Skrytý průvodce nemizí úplně — jednořádková lišta ho vrátí zpátky. -->
  <div v-if="loaded && hidden" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-neutral-200 bg-surface px-4 py-2.5 text-sm shadow-sm">
    <span class="text-neutral-500">{{ t('dashboard.onboarding.hidden_note') }}</span>
    <button type="button" @click="show"
      class="cursor-pointer inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-primary-500/40 text-primary-700 hover:bg-primary-50 text-sm font-medium whitespace-nowrap">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0zM2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
      </svg>
      {{ t('dashboard.onboarding.show') }}
    </button>
  </div>

  <div v-else-if="visible" class="space-y-6">
    <!-- ═══ Hero: uvítání + postup ═══ -->
    <section class="relative overflow-hidden rounded-2xl border border-primary-500/25 bg-gradient-to-br from-primary-500/10 via-accent-500/5 to-transparent p-6 sm:p-8">
      <!-- Dekorativní kruhy: čistě grafika, čtečkám k ničemu (aria-hidden na kontejneru). -->
      <div class="pointer-events-none absolute -top-24 -right-16 w-72 h-72 rounded-full bg-primary-500/10 blur-3xl" aria-hidden="true" />
      <div class="pointer-events-none absolute -bottom-28 -left-10 w-64 h-64 rounded-full bg-accent-500/10 blur-3xl" aria-hidden="true" />

      <div class="relative flex flex-wrap items-start justify-between gap-6">
        <div class="min-w-[16rem] flex-1">
          <p class="text-xs font-semibold uppercase tracking-wider text-primary-600 mb-2">{{ t('dashboard.onboarding.eyebrow') }}</p>
          <h1 class="text-2xl sm:text-3xl font-semibold text-neutral-900">{{ t('dashboard.onboarding.title') }}</h1>
          <p class="mt-2 max-w-2xl text-sm sm:text-base text-neutral-600">{{ t('dashboard.onboarding.intro') }}</p>

          <div class="mt-5 flex flex-wrap items-center gap-2">
            <a href="/manual?ch=92_Nastaveni" target="_blank" rel="noopener"
              class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3.5 rounded-md border border-neutral-300 text-neutral-700 hover:bg-neutral-50 text-sm font-medium whitespace-nowrap">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
              </svg>
              {{ t('dashboard.onboarding.manual') }}
            </a>
            <button type="button" @click="hide"
              class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3.5 rounded-md border border-neutral-300 text-neutral-600 hover:bg-neutral-50 text-sm font-medium whitespace-nowrap">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0 1 12 19c-4.477 0-8.268-2.943-9.542-7a9.97 9.97 0 0 1 1.563-3.029M6.228 6.228A9.955 9.955 0 0 1 12 5c4.478 0 8.268 2.943 9.542 7a9.99 9.99 0 0 1-4.043 5.197M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.244-4.243" />
              </svg>
              {{ t('dashboard.onboarding.hide') }}
            </button>
          </div>
        </div>

        <!-- Kruhový ukazatel postupu -->
        <div class="flex items-center gap-4">
          <div class="relative w-[68px] h-[68px] shrink-0">
            <svg class="w-full h-full -rotate-90" viewBox="0 0 60 60" aria-hidden="true">
              <circle cx="30" cy="30" r="26" fill="none" stroke="currentColor" stroke-width="6" class="text-neutral-200" />
              <circle cx="30" cy="30" r="26" fill="none" stroke="currentColor" stroke-width="6" stroke-linecap="round"
                class="text-primary-600 transition-[stroke-dashoffset] duration-500"
                :stroke-dasharray="RING" :stroke-dashoffset="ringOffset" />
            </svg>
            <span class="absolute inset-0 flex items-center justify-center text-sm font-semibold tabular-nums text-neutral-900">{{ percent }} %</span>
          </div>
          <div class="text-sm">
            <p class="font-medium text-neutral-900">{{ t('dashboard.onboarding.progress', { done: doneCount, total: totalCount }) }}</p>
            <p class="text-neutral-500">{{ allDone ? t('dashboard.onboarding.progress_all_done') : t('dashboard.onboarding.progress_hint') }}</p>
          </div>
        </div>
      </div>
    </section>

    <!-- ═══ Kroky ═══ -->
    <section v-for="g in groups" :key="g.key" class="space-y-3">
      <h2 class="rule-label">
        <span>{{ t(`dashboard.onboarding.groups.${g.key}.title`) }}</span>
        <span class="normal-case tracking-normal font-normal text-[11px] text-neutral-400">{{ t(`dashboard.onboarding.groups.${g.key}.hint`) }}</span>
      </h2>

      <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        <article v-for="s in g.steps" :key="s.id"
          class="group relative flex flex-col gap-3 rounded-xl border p-4 shadow-sm transition-shadow hover:shadow-md"
          :class="done.has(s.id)
            ? 'border-success-500/40 bg-success-500/5'
            : ['border-neutral-200 bg-surface', TINT[g.accent].ring]">
          <div class="flex items-start gap-3">
            <div class="w-10 h-10 shrink-0 rounded-lg flex items-center justify-center"
              :class="done.has(s.id) ? 'bg-success-500/15' : TINT[g.accent].tile">
              <svg class="w-5 h-5" :class="done.has(s.id) ? 'text-success-600' : TINT[g.accent].icon"
                fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" :d="done.has(s.id) ? ICONS.check : ICONS[s.id]" />
              </svg>
            </div>
            <div class="min-w-0">
              <p class="text-[11px] font-semibold uppercase tracking-wider text-neutral-400">
                {{ t('dashboard.onboarding.step_n', { n: stepNumber(s.id) }) }}
              </p>
              <h3 class="font-semibold text-neutral-900 leading-snug">{{ t(`dashboard.onboarding.steps.${s.id}.title`) }}</h3>
            </div>
          </div>

          <p class="text-sm text-neutral-500 leading-relaxed">{{ t(`dashboard.onboarding.steps.${s.id}.text`) }}</p>

          <div class="mt-auto flex flex-wrap items-center justify-between gap-2 pt-1">
            <RouterLink :to="s.to"
              class="cursor-pointer inline-flex items-center gap-1.5 h-8 px-3 rounded-md bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium whitespace-nowrap shadow-sm hover:shadow-md">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" :d="s.cta ? 'M12 6v6m0 0v6m0-6h6m-6 0H6' : 'M9 5l7 7-7 7'" />
              </svg>
              {{ s.cta ? t(`dashboard.onboarding.steps.${s.id}.cta`) : t('dashboard.onboarding.open') }}
            </RouterLink>

            <button type="button" @click="toggle(s.id)"
              :aria-pressed="done.has(s.id)"
              class="cursor-pointer inline-flex items-center gap-1.5 h-8 px-2.5 rounded-md text-sm font-medium whitespace-nowrap border"
              :class="done.has(s.id)
                ? 'border-success-500/50 text-success-600 hover:bg-success-50'
                : 'border-neutral-300 text-neutral-500 hover:bg-neutral-50'">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path v-if="done.has(s.id)" stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />
                <circle v-else cx="12" cy="12" r="9" />
              </svg>
              {{ done.has(s.id) ? t('dashboard.onboarding.marked_done') : t('dashboard.onboarding.mark_done') }}
            </button>
          </div>
        </article>
      </div>
    </section>

    <p class="text-xs text-neutral-400">{{ t('dashboard.onboarding.footnote') }}</p>
  </div>
</template>
