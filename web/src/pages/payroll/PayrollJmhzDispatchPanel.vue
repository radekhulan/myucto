<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorCode, apiErrorMessage, externalJmhzSubmissionTarget } from '@/api/errors'
import { dataBoxApi, type GatewayStart } from '@/api/dataBox'
import { PAYROLL_JMHZ_LATE_DISCOUNT_CONFIRMATION } from '@/api/payrollTransportCodes'
import {
  payrollApi,
  type PayrollJmhzFreezeBlocker,
  type PayrollJmhzIsdsEnqueueResult,
  type PayrollJmhzPvpojPreview,
  type PayrollJmhzTransportPoll,
  type PayrollRegzelEnvironment,
  type PayrollSubmissionOverviewItem,
} from '@/api/payroll'
import { btnFilled, btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import IsdsSendMethods from '@/components/submission/IsdsSendMethods.vue'
import PayrollLateDiscountConfirm from '@/components/payroll/PayrollLateDiscountConfirm.vue'
import JmhzFreezeBlockerList from './JmhzFreezeBlockerList.vue'
import { freezeBlockersFromError } from './jmhzFreezeBlockers'
import { useAuthStore } from '@/stores/auth'
import { navigateFullPage } from '@/workspace/fullPageNavigation'

const props = defineProps<{
  environment: PayrollRegzelEnvironment
  previews: PayrollJmhzPvpojPreview[]
  obligations: PayrollSubmissionOverviewItem[]
}>()

const emit = defineEmits<{ refresh: [] }>()
const { t } = useI18n()
const auth = useAuthStore()
const canWrite = computed(() => auth.canWrite('payroll.submissions'))

interface DispatchState {
  busy: 'isds' | 'vrep' | null
  /**
   * Kanál, na který se čeká potvrzení. Odeslání na VREP i do datové schránky
   * je nevratné — zpráva odejde úřadu a zpátky ji nikdo nevezme. Dřív obojí
   * odletělo prvním kliknutím; u datovky to zamaskoval dotaz na jméno a heslo,
   * u VREPu se podepisuje certifikátem, takže se nezeptalo nic.
   */
  confirming: 'isds' | 'vrep' | null
  error: string
  /** Proklik k chybě (měsíc podaný předchozím programem → přehled převzatých podání). */
  errorTarget?: ReturnType<typeof externalJmhzSubmissionTarget>
  /** Nálezy blokace zmrazení hlášení se jmény a odkazy na opravu. */
  blockers?: PayrollJmhzFreezeBlocker[]
  isds: PayrollJmhzIsdsEnqueueResult | null
  vrep: PayrollJmhzTransportPoll | null
  gateway: GatewayStart | null
  mobileKeySent: boolean
  /**
   * Varování kontroly 290 čekající na potvrzení účetní (hlášení po splatnosti
   * se slevou). Nese větu ze serveru a kanál, kterým se po potvrzení pokračuje.
   */
  lateDiscount: { message: string; channel: 'isds' | 'vrep' } | null
}

const states = ref<Record<string, DispatchState>>({})

// Testovací prostředí ČSSZ má vlastní přidělený VS účtárny. Hlavička zmrazeného
// XML ho v testu nese (JmhzScenario1DocumentResolver), obálka se s ní musí shodovat.
const testVariableSymbols = ref<Map<number, string>>(new Map())

onMounted(async () => {
  try {
    const settings = await payrollApi.employerSettings()
    const symbols = new Map<number, string>()
    for (const office of settings.offices ?? []) {
      const symbol = (office.test_social_security_variable_symbol ?? '').trim()
      if (/^\d{10}$/.test(symbol)) symbols.set(office.id, symbol)
    }
    testVariableSymbols.value = symbols
  } catch {
    testVariableSymbols.value = new Map()
  }
})

function envelopeVariableSymbol(preview: PayrollJmhzPvpojPreview): string {
  if (props.environment === 'test') {
    return testVariableSymbols.value.get(preview.office.office_id) ?? preview.office.variable_symbol
  }
  return preview.office.variable_symbol
}

function key(preview: PayrollJmhzPvpojPreview): string {
  return `${preview.revision_id}:${preview.office.office_id}`
}

function state(preview: PayrollJmhzPvpojPreview): DispatchState {
  return states.value[key(preview)] ?? {
    busy: null,
    confirming: null,
    error: '',
    isds: null,
    vrep: null,
    gateway: null,
    mobileKeySent: false,
    lateDiscount: null,
  }
}

function askConfirm(preview: PayrollJmhzPvpojPreview, channel: 'isds' | 'vrep') {
  if (!canWrite.value || unavailableReason(preview)) return
  setState(preview, { ...state(preview), confirming: channel, error: '' })
}

function cancelConfirm(preview: PayrollJmhzPvpojPreview) {
  setState(preview, { ...state(preview), confirming: null })
}

/*
 * Zahození rozdělaného odeslání.
 *
 * ČSSZ zprávu převezme, ale zpracovat ji odmítne — třeba proto, že certifikát,
 * kterým je e-podání podepsané, není u OSSZ zapsaný v registru podávajících.
 * Odeslané pak nic není, ale podání uvízne ve stavu, ze kterého nevede cesta zpět:
 * odeslat znovu nejde a nové podání za totéž období databáze nepustí.
 *
 * Aplikace o tom NEROZHODUJE sama — důvodů, proč úřad podání nepřijme, je víc, než
 * kolik jich jde z protokolu spolehlivě rozpoznat. Ukáže tedy odpověď úřadu
 * a rozhodne účetní.
 */
const ABANDONABLE_STATUSES = ['submitted', 'processing', 'waiting_for_identity', 'rejected']
const abandoning = ref<string | null>(null)
/** Po zahození zamítnutého řádného podání: znovu zmrazeno s novým GUID. */
const refreezeNotices = ref<Record<string, string>>({})

function canAbandon(preview: PayrollJmhzPvpojPreview): boolean {
  const item = obligation(preview)
  const latest = item?.latest_submission
  // Zprávu, kterou datová schránka prokazatelně doručila, zahodit nelze:
  // druhé podání téhož by u ČSSZ založilo duplicitu. Server to odmítne tak
  // jako tak (PayrollSubmissionDeliveryProof), ale nabízet tlačítko, které
  // vždycky skončí chybou, je horší než ho neukázat.
  if (item?.settlement?.delivery_proof) return false

  return latest !== null && latest !== undefined
    && ABANDONABLE_STATUSES.includes(latest.status)
}

async function abandon(preview: PayrollJmhzPvpojPreview) {
  const item = obligation(preview)
  const latest = item?.latest_submission
  if (!canWrite.value || !latest || abandoning.value !== null) return

  const reason = window.prompt(
    t('payroll.submissions.overview.jmhz_abandon_prompt'),
    state(preview).error || '',
  )
  if (reason === null || reason.trim() === '') return

  abandoning.value = key(preview)
  setState(preview, { ...state(preview), error: '' })
  try {
    const result = await payrollApi.abandonSubmissionInQueue(
      props.environment,
      latest.id,
      latest.row_version,
      reason.trim(),
    )
    refreezeNotices.value = {
      ...refreezeNotices.value,
      [key(preview)]: result.refreeze?.refrozen
        ? t('payroll.transport_delivery.refrozen', {
            guid: result.refreeze.submission_guid,
            previous: result.refreeze.previous_submission_guid,
          })
        : '',
    }
    emit('refresh')
  } catch (e) {
    setState(preview, {
      ...state(preview),
      error: apiErrorMessage(e, t('payroll.submissions.overview.jmhz_abandon_failed')),
    })
  } finally {
    abandoning.value = null
  }
}

function mobileKeySent(preview: PayrollJmhzPvpojPreview) {
  setState(preview, { ...state(preview), mobileKeySent: true })
  emit('refresh')
}

function setState(preview: PayrollJmhzPvpojPreview, next: DispatchState) {
  states.value = { ...states.value, [key(preview)]: next }
}

function obligation(preview: PayrollJmhzPvpojPreview): PayrollSubmissionOverviewItem | null {
  const officeReference = `payroll_run:${preview.run_id}:office:${preview.office.office_id}`
  const runReference = `payroll_run:${preview.run_id}`

  return props.obligations.find(item =>
    item.agenda_code.toUpperCase().startsWith('JMHZ')
      && item.subject_reference === officeReference,
  ) ?? props.obligations.find(item =>
    item.agenda_code.toUpperCase().startsWith('JMHZ')
      && item.subject_reference === runReference,
  ) ?? null
}

function unavailableReason(preview: PayrollJmhzPvpojPreview): string | null {
  const item = obligation(preview)
  if (!item) return null
  if (props.environment === 'production' && item.deadline.phase === 'not_open') {
    return t(auth.submissionTestEnvironmentAllowed
      ? 'payroll.submissions.overview.jmhz_dispatch_not_open'
      : 'payroll.submissions.overview.jmhz_dispatch_not_open_production_only', {
      date: item.earliest_submission_on,
    })
  }
  if (item.latest_submission && item.latest_submission.status !== 'ready') {
    return t('payroll.submissions.overview.jmhz_dispatch_already_started', {
      status: item.latest_submission.status,
    })
  }

  return null
}

async function submissionId(
  preview: PayrollJmhzPvpojPreview,
  confirmLateDiscount: boolean,
): Promise<number> {
  const item = obligation(preview)
  if (item?.latest_submission?.status === 'ready') return item.latest_submission.id

  const preparation = await payrollApi.freezeJmhzPreparation(
    preview.revision_id,
    crypto.randomUUID(),
    props.environment,
  )
  const frozen = await payrollApi.freezeJmhzSubmission(
    preparation.id,
    item?.id ?? null,
    props.environment,
    preview.office.office_id,
    confirmLateDiscount,
  )

  return frozen.submission_id
}

function cancelLateDiscount(preview: PayrollJmhzPvpojPreview) {
  setState(preview, { ...state(preview), lateDiscount: null })
}

async function dispatch(
  preview: PayrollJmhzPvpojPreview,
  channel: 'isds' | 'vrep',
  confirmLateDiscount = false,
) {
  if (!canWrite.value || unavailableReason(preview)) return
  const current = state(preview)
  setState(preview, {
    ...current,
    busy: channel,
    confirming: null,
    error: '',
    errorTarget: null,
    blockers: [],
    gateway: null,
    lateDiscount: null,
  })

  try {
    const id = await submissionId(preview, confirmLateDiscount)
    if (channel === 'vrep') {
      const result = await payrollApi.sendJmhzTransport(
        id,
        envelopeVariableSymbol(preview),
        props.environment,
        crypto.randomUUID(),
      )
      setState(preview, { ...state(preview), busy: null, vrep: result })
      emit('refresh')
      return
    }

    const queued = await payrollApi.enqueueJmhzIsds(id, props.environment)
    setState(preview, { ...state(preview), busy: null, isds: queued })
    if (queued.transport.automatic) {
      try {
        const gateway = await dataBoxApi.gatewayStartPayroll(queued.outbox_id)
        setState(preview, { ...state(preview), gateway })
      } catch (exception) {
        setState(preview, {
          ...state(preview),
          error: apiErrorMessage(
            exception,
            t('payroll.submissions.overview.jmhz_gateway_start_failed'),
          ),
        })
      }
    }
    emit('refresh')
  } catch (exception) {
    // Kontrola 290 nezakazuje, ale chce vědomé potvrzení účetní. Nabídne se
    // na místě i s tím, kde sleva ověřit a kde ji případně opravit.
    if (apiErrorCode(exception) === PAYROLL_JMHZ_LATE_DISCOUNT_CONFIRMATION) {
      setState(preview, {
        ...state(preview),
        busy: null,
        lateDiscount: {
          message: apiErrorMessage(exception, t('payroll.transport_delivery.late_discount_title')),
          channel,
        },
      })
      return
    }
    setState(preview, {
      ...state(preview),
      busy: null,
      error: apiErrorMessage(
        exception,
        t('payroll.submissions.overview.jmhz_dispatch_failed'),
      ),
      errorTarget: externalJmhzSubmissionTarget(exception),
      blockers: freezeBlockersFromError(exception),
    })
  }
}

function continueGateway(preview: PayrollJmhzPvpojPreview) {
  const gateway = state(preview).gateway
  if (gateway) navigateFullPage(gateway.redirect_url)
}
</script>

<template>
  <section
    class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm"
    data-test="jmhz-dispatch"
  >
    <div class="border-b border-neutral-200 p-4 sm:p-6">
      <h2 class="text-lg font-semibold text-neutral-900">
        {{ t('payroll.submissions.overview.jmhz_dispatch_title') }}
      </h2>
      <p class="mt-1 text-sm text-neutral-500">
        {{ t('payroll.submissions.overview.jmhz_dispatch_description') }}
      </p>
    </div>

    <p v-if="previews.length === 0" class="p-6 text-sm text-neutral-500">
      {{ t('payroll.submissions.overview.jmhz_dispatch_empty') }}
    </p>

    <div v-else class="space-y-4 p-4">
      <article
        v-for="preview in previews"
        :key="key(preview)"
        class="rounded-lg border border-neutral-200 p-4"
        :data-test="`jmhz-dispatch-${key(preview)}`"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h3 class="font-semibold text-neutral-900">
              {{ preview.office.code }} · {{ preview.office.name }}
            </h3>
            <p class="mt-1 text-xs text-neutral-500">
              {{ t('payroll.submissions.overview.jmhz_dispatch_reference', {
                period: preview.period,
                symbol: envelopeVariableSymbol(preview),
              }) }}
            </p>
          </div>
          <div class="flex flex-wrap gap-2">
            <button
              type="button"
              :class="btnFilled('primary')"
              :disabled="!canWrite || state(preview).busy !== null
                || state(preview).isds !== null || state(preview).vrep !== null
                || unavailableReason(preview) !== null"
              :data-test="`jmhz-dispatch-isds-${key(preview)}`"
              @click="askConfirm(preview, 'isds')"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.send" />
              </svg>
              {{ state(preview).busy === 'isds'
                ? t('common.loading')
                : t('payroll.submissions.overview.jmhz_dispatch_isds') }}
            </button>
            <button
              type="button"
              :class="btnOutline('neutral')"
              :disabled="!canWrite || state(preview).busy !== null
                || state(preview).isds !== null || state(preview).vrep !== null
                || unavailableReason(preview) !== null"
              :data-test="`jmhz-dispatch-vrep-${key(preview)}`"
              @click="askConfirm(preview, 'vrep')"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.send" />
              </svg>
              {{ state(preview).busy === 'vrep'
                ? t('common.loading')
                : t('payroll.submissions.overview.jmhz_dispatch_vrep') }}
            </button>
          </div>
        </div>

        <div
          v-if="state(preview).confirming !== null"
          class="mt-3 rounded-lg border border-warning-300 bg-warning-50 p-4"
          :data-test="`jmhz-dispatch-confirm-${key(preview)}`"
        >
          <p class="text-sm font-medium text-warning-900">
            {{ t('payroll.submissions.overview.jmhz_dispatch_confirm_title') }}
          </p>
          <p class="mt-1 text-sm text-warning-800">
            {{ t(state(preview).confirming === 'vrep'
              ? 'payroll.submissions.overview.jmhz_dispatch_confirm_vrep'
              : 'payroll.submissions.overview.jmhz_dispatch_confirm_isds', {
              period: preview.period,
              symbol: envelopeVariableSymbol(preview),
              environment: t(environment === 'production'
                ? 'payroll.submissions.overview.jmhz_dispatch_confirm_production'
                : 'payroll.submissions.overview.jmhz_dispatch_confirm_test'),
            }) }}
          </p>
          <p class="mt-1 text-xs text-warning-700">
            {{ t('payroll.submissions.overview.jmhz_dispatch_confirm_irreversible') }}
          </p>
          <div class="mt-3 flex flex-wrap gap-2">
            <button
              type="button"
              :class="btnFilled('warning')"
              :data-test="`jmhz-dispatch-confirm-yes-${key(preview)}`"
              @click="dispatch(preview, state(preview).confirming ?? 'vrep')"
            >
              {{ t('payroll.submissions.overview.jmhz_dispatch_confirm_send') }}
            </button>
            <button
              type="button"
              :class="btnOutline('neutral')"
              :data-test="`jmhz-dispatch-confirm-no-${key(preview)}`"
              @click="cancelConfirm(preview)"
            >
              {{ t('common.cancel') }}
            </button>
          </div>
        </div>

        <div
          v-if="unavailableReason(preview)"
          class="mt-3 rounded-lg border border-warning-500/30 bg-warning-50 p-3 text-sm text-warning-700"
        >
          <p>{{ unavailableReason(preview) }}</p>
          <!--
            Cesta ven z podání, které úřad nepřijal. Bez ní zůstane povinnost
            trvale nepodatelná: odeslat znovu nejde a nové podání za totéž období
            databáze nepustí.
          -->
          <div v-if="canWrite && canAbandon(preview)" class="mt-2 flex flex-wrap items-center gap-2">
            <button
              type="button"
              :class="btnOutline('danger')"
              :disabled="abandoning !== null"
              :data-test="`jmhz-abandon-${key(preview)}`"
              @click="abandon(preview)"
            >
              <span class="whitespace-nowrap">
                {{ abandoning === key(preview)
                  ? t('payroll.submissions.overview.jmhz_abandoning')
                  : t('payroll.submissions.overview.jmhz_abandon') }}
              </span>
            </button>
            <span class="text-xs text-warning-700/80">
              {{ t('payroll.submissions.overview.jmhz_abandon_hint') }}
            </span>
          </div>
        </div>
        <p
          v-if="refreezeNotices[key(preview)]"
          class="mt-3 rounded-lg border border-success-500/30 bg-success-50 p-3 text-sm text-success-800"
          role="status"
          :data-test="`jmhz-refreeze-${key(preview)}`"
        >
          {{ refreezeNotices[key(preview)] }}
        </p>
        <PayrollLateDiscountConfirm
          v-if="state(preview).lateDiscount"
          class="mt-3"
          :message="state(preview).lateDiscount!.message"
          :busy="state(preview).busy !== null"
          :test-id="`jmhz-late-discount-${key(preview)}`"
          @confirm="dispatch(preview, state(preview).lateDiscount!.channel, true)"
          @cancel="cancelLateDiscount(preview)"
        />
        <p
          v-if="state(preview).error"
          class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
          role="alert"
        >
          {{ state(preview).error }}
          <RouterLink
            v-if="state(preview).errorTarget"
            :to="state(preview).errorTarget!"
            class="mt-1 block font-medium text-payroll-600 underline hover:text-payroll-700"
            data-test="jmhz-dispatch-external-link"
          >
            {{ t('payroll.external_jmhz.open_history') }}
          </RouterLink>
        </p>
        <JmhzFreezeBlockerList
          v-if="state(preview).blockers?.length"
          :blockers="state(preview).blockers!"
          :period="preview.period"
        />

        <div
          v-if="state(preview).vrep"
          class="mt-3 rounded-lg border border-success-500/30 bg-success-50 p-3 text-sm text-success-700"
          data-test="jmhz-dispatch-vrep-result"
        >
          {{ t('payroll.submissions.overview.jmhz_dispatch_vrep_started', {
            id: state(preview).vrep!.attempt.id,
            status: state(preview).vrep!.attempt.status,
          }) }}
        </div>

        <div
          v-if="state(preview).isds"
          class="mt-3 rounded-lg border border-success-500/30 bg-success-50 p-3 text-sm text-success-800"
          data-test="jmhz-dispatch-isds-result"
        >
          <p class="font-medium">
            {{ t('payroll.submissions.overview.jmhz_dispatch_isds_queued', {
              box: state(preview).isds!.recipient.box_id,
              name: state(preview).isds!.recipient.name,
            }) }}
          </p>
          <p class="mt-1 text-xs">
            {{ state(preview).isds!.attachment.filename }} · {{ state(preview).isds!.subject }}
          </p>
          <template v-if="!state(preview).isds!.transport.automatic">
            <p
              v-if="state(preview).mobileKeySent"
              class="mt-2 text-xs font-medium text-success-800"
            >
              {{ t('databox.outbox.mobileKey.sent') }}
            </p>
            <template v-else>
              <IsdsSendMethods
                class="mt-3 text-neutral-900"
                :outbox-id="state(preview).isds!.outbox_id"
                :environment="environment"
                :transport="state(preview).isds!.transport"
                :gateway-start="dataBoxApi.gatewayStartPayroll"
                @sent="(dispatched: boolean) => { if (dispatched) mobileKeySent(preview) }"
              />
              <a
                :href="`/admin/databox?tab=outbox&outbox=${state(preview).isds!.outbox_id}`"
                :class="[btnOutlineSm('neutral'), 'mt-3 inline-flex']"
                data-test="jmhz-dispatch-open-outbox"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.inbox" /></svg>
                {{ t('payroll.submissions.overview.jmhz_dispatch_open_outbox') }}
              </a>
            </template>
          </template>
        </div>

        <div
          v-if="state(preview).gateway"
          class="mt-3 rounded-lg border border-primary-200 bg-primary-50 p-3 text-sm text-primary-900"
          data-test="jmhz-dispatch-gateway"
        >
          <p class="font-medium">
            {{ t('payroll.submissions.overview.jmhz_gateway_title') }}
          </p>
          <p class="mt-1">{{ state(preview).gateway!.login_guidance }}</p>
          <p class="mt-2 text-xs">
            {{ t('payroll.submissions.overview.jmhz_gateway_credentials') }}
          </p>
          <p v-if="!state(preview).gateway!.login_policy_documented" class="mt-2 text-xs text-warning-800">
            {{ t('payroll.submissions.overview.jmhz_gateway_methods') }}
          </p>
          <button
            type="button"
            :class="[btnFilled('primary'), 'mt-3']"
            @click="continueGateway(preview)"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.send" />
            </svg>
            {{ t('payroll.submissions.overview.jmhz_gateway_continue') }}
          </button>
        </div>
      </article>
    </div>
  </section>
</template>
