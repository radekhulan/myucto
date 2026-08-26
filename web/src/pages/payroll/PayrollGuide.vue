<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { ICONS } from '@/components/ui/buttonStyles'

/**
 * Krátký in-page návod „Jak to funguje".
 *
 * Why: pořadí kroků mzdového měsíce je dané stavy běhu
 * (`lock_inputs → calculate → review → approve → post → prepare_payments →
 * mark_paid → close`), ale v UI nebylo nikde
 * napsané — menu mzdové sekce má 20 položek v jiném pořadí. Nový uživatel tak
 * nemá jak zjistit, že nepřítomnosti musí být v systému DŘÍV než výpočet.
 *
 * Odmlčí se křížkem (stejný vzor jako `FooterTip.vue`) a jde vrátit odkazem
 * v hlavičce přehledu — návod, který nejde vypnout, se stane šumem.
 */

const STORAGE_KEY = 'myinvoice.payroll.guide.off'

const { t } = useI18n()

const dismissed = ref(false)
const forcedOpen = ref(false)

/**
 * Kroky drží pořadí měsíce i cíl odkazu. Klíče jsou psané doslova, ať je
 * `npm run check:i18n` umí staticky ověřit.
 */
const steps = computed(() => [
  { route: 'payroll-absences', title: t('payroll.guide.steps.absences.title'), hint: t('payroll.guide.steps.absences.hint') },
  { route: 'payroll-time', title: t('payroll.guide.steps.time.title'), hint: t('payroll.guide.steps.time.hint') },
  { route: 'payroll-travel', title: t('payroll.guide.steps.travel.title'), hint: t('payroll.guide.steps.travel.hint') },
  { route: 'payroll-quick-inputs', title: t('payroll.guide.steps.quick_inputs.title'), hint: t('payroll.guide.steps.quick_inputs.hint') },
  { route: 'payroll-runs', title: t('payroll.guide.steps.runs.title'), hint: t('payroll.guide.steps.runs.hint') },
  { route: 'payroll-posting-reconciliation', title: t('payroll.guide.steps.posting_reconciliation.title'), hint: t('payroll.guide.steps.posting_reconciliation.hint') },
  { route: 'payroll-payments', title: t('payroll.guide.steps.payments.title'), hint: t('payroll.guide.steps.payments.hint') },
  { route: 'payroll-documents', title: t('payroll.guide.steps.documents.title'), hint: t('payroll.guide.steps.documents.hint') },
  { route: 'payroll-submissions', title: t('payroll.guide.steps.submissions.title'), hint: t('payroll.guide.steps.submissions.hint') },
].map((step, index) => ({ ...step, index: index + 1 })))

const visible = computed(() => forcedOpen.value || !dismissed.value)

function dismiss() {
  dismissed.value = true
  forcedOpen.value = false
  try {
    localStorage.setItem(STORAGE_KEY, '1')
  } catch {
    /* soukromý režim — návod se příště ukáže znovu, nic horšího se nestane */
  }
}

function reopen() {
  forcedOpen.value = true
}

defineExpose({ reopen })

onMounted(() => {
  try {
    dismissed.value = localStorage.getItem(STORAGE_KEY) === '1'
  } catch {
    dismissed.value = false
  }
})
</script>

<template>
  <section
    v-if="visible"
    class="rounded-xl border border-payroll-500/30 bg-payroll-50 p-4 sm:p-6"
    data-test="payroll-guide"
  >
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="max-w-3xl">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.guide.title') }}</h2>
        <p class="mt-1 text-sm text-neutral-600">{{ t('payroll.guide.intro') }}</p>
      </div>
      <button
        type="button"
        class="shrink-0 cursor-pointer rounded-md p-1.5 text-neutral-500 transition-colors hover:bg-payroll-100 hover:text-neutral-800"
        data-test="payroll-guide-dismiss"
        :title="t('payroll.guide.dismiss')"
        :aria-label="t('payroll.guide.dismiss')"
        @click="dismiss"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
      </button>
    </div>

    <ol class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-3">
      <li v-for="step in steps" :key="step.route">
        <RouterLink
          :to="{ name: step.route }"
          class="flex h-full min-w-0 gap-3 rounded-lg border border-payroll-500/20 bg-surface p-3 transition hover:border-payroll-500/60 hover:shadow-sm"
        >
          <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-payroll-500 text-xs font-semibold text-white">
            {{ step.index }}
          </span>
          <span class="min-w-0">
            <span class="block text-sm font-medium text-neutral-900">{{ step.title }}</span>
            <span class="mt-0.5 block text-xs text-neutral-600">{{ step.hint }}</span>
          </span>
        </RouterLink>
      </li>
    </ol>

    <p class="mt-4 text-xs text-neutral-600">
      {{ t('payroll.guide.setup_note') }}
      <RouterLink :to="{ name: 'payroll-settings' }" class="font-medium text-payroll-700 underline">
        {{ t('payroll.guide.setup_link') }}
      </RouterLink>
    </p>
    <p class="mt-1 text-xs text-neutral-600">
      {{ t('payroll.guide.manual_note') }}
      <a href="/manual?ch=58_Uplne_mzdy" class="font-medium text-payroll-700 underline">
        {{ t('payroll.guide.manual_link') }}
      </a>
    </p>
  </section>
</template>
