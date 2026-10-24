<script setup lang="ts">
/**
 * Odeslání daňového podání datovou schránkou firmy.
 *
 * Při otevření podání zařadí do fronty datové schránky (idempotentně: pro týž
 * snapshot a příjemce vrátí už založenou zprávu) a nabídne sdílený výběr cest,
 * jak zprávu odeslat. Odeslání samo je teprve úkon zvolené cesty; po úspěchu
 * backend označí podání jako podané a rodič přenačte seznam.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import { dataBoxApi } from '@/api/dataBox'
import { epoSubmissionsApi, type TaxIsdsEnqueueResult } from '@/api/epoSubmissions'
import { ICONS, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import IsdsSendMethods from '@/components/submission/IsdsSendMethods.vue'

const props = defineProps<{
  submissionId: number
  title: string
  clientMode?: boolean
}>()
const emit = defineEmits<{ close: []; sent: []; changed: [] }>()
const { t, locale } = useI18n()

const loading = ref(true)
const error = ref('')
const queued = ref<TaxIsdsEnqueueResult | null>(null)
const busy = ref(false)
const cancelError = ref('')
const sent = ref(false)

/** Přihlášení, certifikát a Mobilní klíč se nastavují na stránce Datová schránka. */
const ACCESS_LINK = '/admin/databox?tab=access'

const outboxId = computed(() => queued.value?.outbox_id ?? 0)
const outboxLink = computed(() => `/admin/databox?tab=outbox&outbox=${outboxId.value}`)
const isReady = computed(() => (queued.value?.row?.dispatch_state ?? 'ready') === 'ready')

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KiB`
  return `${(bytes / 1024 / 1024).toLocaleString(locale.value, { maximumFractionDigits: 1 })} MiB`
}

async function enqueue() {
  loading.value = true
  error.value = ''
  try {
    queued.value = await epoSubmissionsApi.enqueueIsds(props.submissionId)
    emit('changed')
  } catch (exception) {
    error.value = apiErrorMessage(exception, t('reports.submissions.isds.enqueue_failed'))
  } finally {
    loading.value = false
  }
}

function onSent(dispatched: boolean) {
  if (dispatched) {
    sent.value = true
    emit('sent')
  } else {
    emit('changed')
  }
}

async function cancelQueued() {
  if (!queued.value || busy.value) return
  if (!window.confirm(t('reports.submissions.isds.cancel_confirm'))) return
  busy.value = true
  cancelError.value = ''
  try {
    await epoSubmissionsApi.cancelIsds(props.submissionId)
    emit('changed')
    emit('close')
  } catch (exception) {
    cancelError.value = apiErrorMessage(exception, t('reports.submissions.isds.cancel_failed'))
  } finally {
    busy.value = false
  }
}

onMounted(() => { void enqueue() })
</script>

<template>
  <div
    class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4"
    data-test="tax-isds-dialog"
    @click.self="emit('close')"
  >
    <div class="w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-xl bg-surface shadow-xl border border-neutral-200">
      <div class="sticky top-0 z-10 bg-surface px-5 py-4 border-b border-neutral-200 flex items-start justify-between gap-3">
        <div>
          <h2 class="font-semibold">{{ t('reports.submissions.isds.dialog_title') }}</h2>
          <p class="text-xs text-neutral-500 mt-1">{{ title }}</p>
        </div>
        <button type="button" class="p-2 text-neutral-500 hover:text-neutral-900" :aria-label="t('common.close')" @click="emit('close')">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" />
          </svg>
        </button>
      </div>

      <div class="p-5 space-y-4">
        <div v-if="loading" class="py-8 text-center text-sm text-neutral-500" data-test="tax-isds-loading">
          {{ t('reports.submissions.isds.enqueueing') }}
        </div>

        <div
          v-else-if="error"
          class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
          role="alert"
          data-test="tax-isds-enqueue-error"
        >
          {{ error }}
        </div>

        <template v-else-if="queued">
          <div class="rounded-lg border border-primary-500/25 bg-primary-50 p-3 text-sm text-primary-900">
            <p>{{ t('reports.submissions.isds.receipt_note') }}</p>
            <p class="mt-1 text-primary-800">{{ t('reports.submissions.isds.defects_note') }}</p>
          </div>

          <div class="rounded-lg border border-neutral-200 p-3" data-test="tax-isds-recipient">
            <h3 class="font-medium text-sm">{{ t('reports.submissions.isds.recipient') }}</h3>
            <dl class="mt-2 space-y-1.5 text-sm">
              <div class="flex flex-wrap justify-between gap-2">
                <dt class="text-neutral-500">{{ t('reports.submissions.isds.office') }}</dt>
                <dd class="text-right font-medium">{{ queued.recipient.name }}</dd>
              </div>
              <div class="flex flex-wrap justify-between gap-2">
                <dt class="text-neutral-500">{{ t('reports.submissions.isds.box_id') }}</dt>
                <dd class="text-right font-mono">{{ queued.recipient.box_id }}</dd>
              </div>
              <div class="flex flex-wrap justify-between gap-2">
                <dt class="text-neutral-500">{{ t('reports.submissions.isds.recipient_source') }}</dt>
                <dd class="text-right">
                  <span
                    class="inline-flex rounded-full border px-2 py-0.5 text-xs font-medium"
                    :class="queued.recipient.source === 'override'
                      ? 'border-warning-500/30 bg-warning-50 text-warning-700'
                      : 'border-neutral-200 bg-neutral-100 text-neutral-600'"
                  >
                    {{ queued.recipient.source === 'override'
                      ? t('reports.submissions.isds.source_override')
                      : t('reports.submissions.isds.source_default') }}
                  </span>
                </dd>
              </div>
            </dl>
            <div
              v-if="queued.office_mismatch"
              class="mt-3 rounded border border-warning-500/30 bg-warning-50 px-3 py-2 text-xs text-warning-800"
              role="alert"
              data-test="tax-isds-mismatch"
            >
              {{ t('reports.submissions.isds.office_mismatch', { code: queued.xml_office_code ?? '?' }) }}
            </div>
          </div>

          <div class="rounded-lg border border-neutral-200 p-3 text-sm">
            <dl class="space-y-1.5">
              <div>
                <dt class="text-xs text-neutral-500">{{ t('reports.submissions.isds.subject') }}</dt>
                <dd class="mt-0.5 break-words">{{ queued.subject }}</dd>
              </div>
              <div>
                <dt class="text-xs text-neutral-500">{{ t('reports.submissions.isds.attachment') }}</dt>
                <dd class="mt-0.5 break-all" data-test="tax-isds-attachment">
                  {{ queued.attachment.filename }} · {{ formatSize(queued.attachment.bytes) }}
                  · <span class="font-mono text-xs" :title="queued.attachment.sha256">SHA-256 {{ queued.attachment.sha256.slice(0, 12) }}…</span>
                </dd>
              </div>
            </dl>
          </div>

          <div
            v-if="sent"
            class="rounded-lg border border-success-500/30 bg-success-50 p-3 text-sm text-success-800"
            role="status"
            data-test="tax-isds-sent"
          >
            {{ t('reports.submissions.isds.sent') }}
          </div>

          <div
            v-else-if="!isReady"
            class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-sm text-neutral-700"
            data-test="tax-isds-not-ready"
          >
            {{ t('reports.submissions.isds.not_ready', { state: t(`reports.submissions.isds.state_${queued.row.dispatch_state}`) }) }}
          </div>

          <template v-else>
            <div>
              <h3 class="font-medium text-sm">{{ t('reports.submissions.isds.method_title') }}</h3>
              <IsdsSendMethods
                class="mt-2"
                :outbox-id="outboxId"
                environment="production"
                :transport="queued.transport"
                :gateway-only="clientMode"
                :gateway-start="dataBoxApi.gatewayStartTax"
                @sent="onSent"
              />
              <p v-if="queued.transport.channel === 'manual_upload'" class="mt-2 text-xs text-neutral-500" data-test="tax-isds-transport-manual">{{ t('reports.submissions.isds.transport_manual') }}</p>
            </div>

            <div v-if="!clientMode" class="rounded-lg border border-dashed border-neutral-300 p-3 text-xs text-neutral-600">
              <p>{{ t('reports.submissions.isds.manual_hint') }}</p>
              <a :href="outboxLink" :class="[btnOutlineSm('neutral'), 'mt-2']" data-test="tax-isds-open-outbox">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.inbox" />
                </svg>
                {{ t('reports.submissions.isds.open_outbox') }}
              </a>
              <p class="mt-3">{{ t('reports.submissions.isds.access_hint') }}</p>
              <a :href="ACCESS_LINK" :class="[btnOutlineSm('neutral'), 'mt-2']" data-test="tax-isds-open-access">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" />
                </svg>
                {{ t('reports.submissions.isds.open_access') }}
              </a>
            </div>
          </template>

          <p
            v-if="cancelError"
            class="rounded border border-danger-500/30 bg-danger-50 px-3 py-2 text-sm text-danger-700"
            role="alert"
            data-test="tax-isds-cancel-error"
          >
            {{ cancelError }}
          </p>
        </template>
      </div>

      <div class="px-5 py-4 border-t border-neutral-200 flex flex-wrap justify-end gap-2">
        <button
          v-if="queued && isReady && !sent"
          type="button"
          :class="btnOutline('danger')"
          :disabled="busy"
          data-test="tax-isds-cancel"
          @click="cancelQueued"
        >
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" />
          </svg>
          {{ t('reports.submissions.isds.cancel_queued') }}
        </button>
        <button type="button" :class="btnOutline('neutral')" @click="emit('close')">
          {{ t('common.close') }}
        </button>
      </div>
    </div>
  </div>
</template>
