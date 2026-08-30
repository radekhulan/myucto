<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import type { RouteLocationRaw } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { preferencesApi } from '@/api/preferences'

/**
 * Průvodce PRVNÍM NASTAVENÍM mezd na přehledu mzdové sekce.
 *
 * Why: sesterský {@see ../../components/dashboard/OnboardingGuide.vue} řeší
 * totéž pro firmu — čerstvě zapnutý modul nemá kde začít, protože všechno
 * podstatné (mzdové účtárny, registrace ČSSZ, účty pojišťoven, předkontace)
 * je schované v jedné stránce Nastavení mezd s šesti záložkami. Doteď na to
 * odkazoval jediný odstavec v patičce návodu „Jak to funguje".
 *
 * POZOR na záměnu: {@see ./PayrollGuide.vue} je něco jiného a zůstává vedle —
 * ten popisuje opakovaný MĚSÍČNÍ tok (nepřítomnosti → … → podání) a ukazuje se
 * pořád. Tenhle průvodce vede k jednorázové připravenosti a mizí, jakmile firma
 * má první schválený mzdový běh (`capabilities.onboarding.has_settled_payroll`).
 *
 * Stav (odškrtnuté kroky + skrytí) žije v user preference `payroll.guide` —
 * per uživatel, ne per firma, a ne v cookie: je to stav přečtení návodu, který
 * má přežít jiný prohlížeč i jiné zařízení. Selhání uložení průvodce neshodí.
 */
const { t } = useI18n()
const auth = useAuthStore()

const emit = defineEmits<{ 'update:visible': [boolean] }>()

const PREF_KEY = 'payroll.guide'

interface GuidePrefs {
  hidden?: boolean
  done?: string[]
}

/** Ikony kroků — stejné 24px outline paths jako ve zbytku mzdové sekce. */
const ICONS: Record<string, string> = {
  employer:      'M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5m-4 0h4',
  registrations: 'M9 12l2 2 4-4m1-5H8a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2',
  institutions:  'M3 9l9-7 9 7m-2 0v9a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V9m4 11V13h4v7',
  posting:       'M3 10h18M3 14h18M5 21V3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v18M9 7h6M9 11h6M9 15h6',
  policies:      'M12 15v2m-6 4h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2zM8 11V7a4 4 0 1 1 8 0v4',
  databox:       'M19 11H5m14 0a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2m14 0V9a2 2 0 0 0-2-2M5 11V9a2 2 0 0 1 2-2m0 0V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v2M7 7h10',
  people:        'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0zM12 14a7 7 0 0 0-7 7h14a7 7 0 0 0-7-7z',
  employment:    'M17 20h5v-2a4 4 0 0 0-3-3.87M9 20H4v-2a3 3 0 0 1 5.356-1.857M15 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0z',
  evidence:      'M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z',
  components:    'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  first_run:     'M4 4v5h5M4 9a8 8 0 0 1 14.13-4.06M20 20v-5h-5M20 15a8 8 0 0 1-14.13 4.06',
  check:         'M5 13l4 4L19 7',
}

type Accent = 'payroll' | 'accent' | 'success'

interface Step {
  id: string
  to: RouteLocationRaw
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

/*
 * Pořadí je pořadí, ve kterém to dává smysl vyplňovat: bez zaměstnavatele a
 * účtárny nejde založit vztah, bez účtů institucí nejde zaplatit, bez
 * předkontací nejde zaúčtovat. Poslední skupina je jediný krok — první mzdový
 * měsíc už vede návod „Jak to funguje" (PayrollGuide.vue), ne tenhle průvodce.
 */
const GROUPS: Group[] = [
  {
    key: 'employer',
    accent: 'payroll',
    steps: [
      { id: 'employer',      to: { name: 'payroll-settings', query: { tab: 'employer' }, hash: '#payroll-employer-offices' },      visible: () => auth.canRead('payroll.settings') },
      { id: 'registrations', to: { name: 'payroll-settings', query: { tab: 'employer' }, hash: '#payroll-employer-registration' }, visible: () => auth.canRead('payroll.settings') },
      { id: 'institutions',  to: { name: 'payroll-settings', query: { tab: 'institutions' } }, visible: () => auth.canRead('payroll.settings') },
      { id: 'posting',       to: { name: 'payroll-settings', query: { tab: 'accounting' } },   visible: () => auth.canRead('payroll.settings') },
      { id: 'policies',      to: { name: 'payroll-settings', query: { tab: 'policies' } },     visible: () => auth.canRead('payroll.settings') },
      // Datová schránka FIRMY, ne globální odesílací brána: mzdová podání
      // (HOZ/PPZ pojišťovnám, JMHZ pro ČSSZ) odcházejí ze schránky firmy.
      { id: 'databox',       to: { name: 'admin-databox' },                                   visible: () => auth.canWrite('settings.signing') },
    ],
  },
  {
    key: 'people',
    accent: 'accent',
    steps: [
      { id: 'people',     to: { name: 'payroll-people' },     cta: 'people', visible: () => auth.canWrite('payroll.person.write') },
      { id: 'employment', to: { name: 'payroll-people' },                    visible: () => auth.canWrite('payroll.employment.write') },
      { id: 'evidence',   to: { name: 'payroll-people' },                    visible: () => auth.canWrite('payroll.person.write') },
      { id: 'components', to: { name: 'payroll-components' },                visible: () => auth.canWrite('payroll.inputs.write') },
    ],
  },
  {
    key: 'start',
    accent: 'success',
    steps: [
      { id: 'first_run', to: { name: 'payroll-runs' }, cta: 'first_run', visible: () => auth.canRead('payroll') },
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
    // Průvodce je pomůcka — když se preference neuloží, nesmí to shodit přehled.
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

defineExpose({ show })

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
  payroll: { tile: 'bg-payroll-500/10', icon: 'text-payroll-600', ring: 'group-hover:border-payroll-500/40' },
  accent:  { tile: 'bg-accent-500/10',  icon: 'text-accent-600',  ring: 'group-hover:border-accent-500/40' },
  success: { tile: 'bg-success-500/10', icon: 'text-success-600', ring: 'group-hover:border-success-500/40' },
}
</script>

<template>
  <!-- Skrytý průvodce nemizí úplně — jednořádková lišta ho vrátí zpátky. -->
  <div v-if="loaded && hidden" data-test="payroll-setup-guide-hidden"
    class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-neutral-200 bg-surface px-4 py-2.5 text-sm shadow-sm">
    <span class="text-neutral-500">{{ t('payroll.setup_guide.hidden_note') }}</span>
    <button type="button" data-test="payroll-setup-guide-show" @click="show"
      class="cursor-pointer inline-flex items-center gap-1.5 h-8 px-3 rounded-md border border-payroll-500/40 text-payroll-700 hover:bg-payroll-50 text-sm font-medium whitespace-nowrap">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0zM2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
      </svg>
      {{ t('payroll.setup_guide.show') }}
    </button>
  </div>

  <div v-else-if="visible" class="space-y-6" data-test="payroll-setup-guide">
    <!-- ═══ Hero: uvítání + postup ═══ -->
    <section class="relative overflow-hidden rounded-2xl border border-payroll-500/25 bg-gradient-to-br from-payroll-500/10 via-accent-500/5 to-transparent p-6 sm:p-8">
      <!-- Dekorativní kruhy: čistě grafika, čtečkám k ničemu (aria-hidden na kontejneru). -->
      <div class="pointer-events-none absolute -top-24 -right-16 w-72 h-72 rounded-full bg-payroll-500/10 blur-3xl" aria-hidden="true" />
      <div class="pointer-events-none absolute -bottom-28 -left-10 w-64 h-64 rounded-full bg-accent-500/10 blur-3xl" aria-hidden="true" />

      <div class="relative flex flex-wrap items-start justify-between gap-6">
        <div class="min-w-[16rem] flex-1">
          <p class="text-xs font-semibold uppercase tracking-wider text-payroll-600 mb-2">{{ t('payroll.setup_guide.eyebrow') }}</p>
          <h2 class="text-2xl sm:text-3xl font-semibold text-neutral-900">{{ t('payroll.setup_guide.title') }}</h2>
          <p class="mt-2 max-w-2xl text-sm sm:text-base text-neutral-600">{{ t('payroll.setup_guide.intro') }}</p>

          <div class="mt-5 flex flex-wrap items-center gap-2">
            <a href="/manual?ch=58_Uplne_mzdy" target="_blank" rel="noopener"
              class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3.5 rounded-md border border-neutral-300 text-neutral-700 hover:bg-neutral-50 text-sm font-medium whitespace-nowrap">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
              </svg>
              {{ t('payroll.setup_guide.manual') }}
            </a>
            <button type="button" data-test="payroll-setup-guide-hide" @click="hide"
              class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3.5 rounded-md border border-neutral-300 text-neutral-600 hover:bg-neutral-50 text-sm font-medium whitespace-nowrap">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0 1 12 19c-4.477 0-8.268-2.943-9.542-7a9.97 9.97 0 0 1 1.563-3.029M6.228 6.228A9.955 9.955 0 0 1 12 5c4.478 0 8.268 2.943 9.542 7a9.99 9.99 0 0 1-4.043 5.197M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.244-4.243" />
              </svg>
              {{ t('payroll.setup_guide.hide') }}
            </button>
          </div>
        </div>

        <!-- Kruhový ukazatel postupu -->
        <div class="flex items-center gap-4">
          <div class="relative w-[68px] h-[68px] shrink-0">
            <svg class="w-full h-full -rotate-90" viewBox="0 0 60 60" aria-hidden="true">
              <circle cx="30" cy="30" r="26" fill="none" stroke="currentColor" stroke-width="6" class="text-neutral-200" />
              <circle cx="30" cy="30" r="26" fill="none" stroke="currentColor" stroke-width="6" stroke-linecap="round"
                class="text-payroll-600 transition-[stroke-dashoffset] duration-500"
                :stroke-dasharray="RING" :stroke-dashoffset="ringOffset" />
            </svg>
            <span class="absolute inset-0 flex items-center justify-center text-sm font-semibold tabular-nums text-neutral-900">{{ percent }} %</span>
          </div>
          <div class="text-sm">
            <p class="font-medium text-neutral-900">{{ t('payroll.setup_guide.progress', { done: doneCount, total: totalCount }) }}</p>
            <p class="text-neutral-500">{{ allDone ? t('payroll.setup_guide.progress_all_done') : t('payroll.setup_guide.progress_hint') }}</p>
          </div>
        </div>
      </div>
    </section>

    <!-- ═══ Kroky ═══ -->
    <section v-for="g in groups" :key="g.key" class="space-y-3">
      <h3 class="rule-label">
        <span>{{ t(`payroll.setup_guide.groups.${g.key}.title`) }}</span>
        <span class="normal-case tracking-normal font-normal text-[11px] text-neutral-400">{{ t(`payroll.setup_guide.groups.${g.key}.hint`) }}</span>
      </h3>

      <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        <article v-for="s in g.steps" :key="s.id"
          class="group relative flex min-w-0 flex-col gap-3 rounded-xl border p-4 shadow-sm transition-shadow hover:shadow-md"
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
                {{ t('payroll.setup_guide.step_n', { n: stepNumber(s.id) }) }}
              </p>
              <h4 class="font-semibold text-neutral-900 leading-snug">{{ t(`payroll.setup_guide.steps.${s.id}.title`) }}</h4>
            </div>
          </div>

          <p class="text-sm text-neutral-500 leading-relaxed">{{ t(`payroll.setup_guide.steps.${s.id}.text`) }}</p>

          <div class="mt-auto flex flex-wrap items-center justify-between gap-2 pt-1">
            <RouterLink :to="s.to"
              class="cursor-pointer inline-flex items-center gap-1.5 h-8 px-3 rounded-md bg-payroll-600 hover:bg-payroll-700 text-white text-sm font-medium whitespace-nowrap shadow-sm hover:shadow-md">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" :d="s.cta ? 'M12 6v6m0 0v6m0-6h6m-6 0H6' : 'M9 5l7 7-7 7'" />
              </svg>
              {{ s.cta ? t(`payroll.setup_guide.steps.${s.id}.cta`) : t('payroll.setup_guide.open') }}
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
              {{ done.has(s.id) ? t('payroll.setup_guide.marked_done') : t('payroll.setup_guide.mark_done') }}
            </button>
          </div>
        </article>
      </div>
    </section>

    <p class="text-xs text-neutral-400">{{ t('payroll.setup_guide.footnote') }}</p>
  </div>
</template>
