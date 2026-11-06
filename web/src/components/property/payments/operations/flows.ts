import { onBeforeUnmount, ref, shallowRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useBusyScope } from './busy'
import { createRetryToken } from './idempotency'
import { failureMessage, formErrorKey } from './format'

interface FlowBase { supplier: () => number | null; validate: () => boolean }

function useFlowCore(o: FlowBase) {
  const { t } = useI18n()
  const scope = useBusyScope()
  const busy = ref(false)
  const error = ref('')
  let epoch = 0
  function isBusy() { return busy.value || scope.busy.value }
  function begin() { busy.value = true; error.value = ''; scope.begin() }
  function finish(ticket: number) { if (ticket === epoch && busy.value) { busy.value = false; scope.end() } }
  function fail(e: unknown) { error.value = formErrorKey(e) ? t(formErrorKey(e)!) : failureMessage(e, t('propertyPayments.failed')) }
  function buildFailure(e: unknown) { error.value = formErrorKey(e) ? t(formErrorKey(e)!) : t('propertyPayments.required') }
  function bump() { epoch++; if (busy.value) { busy.value = false; scope.end() } error.value = '' }
  onBeforeUnmount(bump)
  watch(o.supplier, bump)
  return { busy, error, isBusy, begin, finish, fail, buildFailure, bump, ticket: () => epoch }
}

export function usePreviewFlow<Req, Prev extends { preview_hash: string }, Res>(o: FlowBase & {
  canPreview: () => boolean
  canConfirm: () => boolean
  build: () => Req
  preview: (supplier: number, request: Req) => Promise<Prev>
  confirm: (supplier: number, request: Req, hash: string, token: string) => Promise<Res>
  onDone?: (result: Res) => void
}) {
  const core = useFlowCore(o)
  const preview = shallowRef<Prev | null>(null)
  const done = ref(false)
  let submitted: Req | null = null
  let previewGeneration = 0
  let token = crypto.randomUUID()
  function invalidate() { previewGeneration++; preview.value = null; submitted = null; token = crypto.randomUUID(); done.value = false }
  function reset() { core.bump(); invalidate() }
  watch(o.supplier, invalidate)
  async function showPreview() {
    const id = o.supplier()
    if (core.isBusy() || !id || !o.canPreview() || !o.validate()) return
    let payload: Req
    try { payload = o.build() } catch (e) { core.buildFailure(e); return }
    const ticket = core.ticket(); const generation = previewGeneration; core.begin(); preview.value = null; submitted = null; done.value = false
    try {
      const result = await o.preview(id, payload)
      if (generation === previewGeneration && ticket === core.ticket() && o.supplier() === id) { preview.value = result; submitted = payload }
    } catch (e) { if (ticket === core.ticket()) core.fail(e) } finally { core.finish(ticket) }
  }
  async function confirm() {
    const id = o.supplier()
    if (core.isBusy() || !id || !preview.value || !submitted || !o.canConfirm() || !o.validate()) return
    const ticket = core.ticket(); const payload = submitted; const hash = preview.value.preview_hash; core.begin()
    try {
      const result = await o.confirm(id, payload, hash, token)
      if (ticket !== core.ticket() || o.supplier() !== id) return
      preview.value = null; submitted = null; token = crypto.randomUUID(); done.value = true
      o.onDone?.(result)
    } catch (e) { if (ticket === core.ticket()) core.fail(e) } finally { core.finish(ticket) }
  }
  return { busy: core.busy, error: core.error, preview, done, invalidate, reset, showPreview, confirm }
}

export function useWriteFlow<Req, Res>(o: FlowBase & {
  canWrite: () => boolean
  build: () => Req
  write: (supplier: number, request: Req, token: string) => Promise<Res>
  onDone?: (result: Res) => void
}) {
  const core = useFlowCore(o)
  const retry = createRetryToken()
  const done = ref(false)
  function invalidate() { done.value = false }
  function reset() { core.bump(); retry.reset(); done.value = false }
  watch(o.supplier, () => retry.reset())
  async function submit() {
    const id = o.supplier()
    if (core.isBusy() || !id || !o.canWrite() || !o.validate()) return
    let payload: Req
    try { payload = o.build() } catch (e) { core.buildFailure(e); return }
    const token = retry.for(payload)
    const ticket = core.ticket(); core.begin(); done.value = false
    try {
      const result = await o.write(id, payload, token)
      if (ticket !== core.ticket() || o.supplier() !== id) return
      retry.reset(); done.value = true
      o.onDone?.(result)
    } catch (e) { if (ticket === core.ticket()) core.fail(e) } finally { core.finish(ticket) }
  }
  return { busy: core.busy, error: core.error, done, invalidate, reset, submit }
}
