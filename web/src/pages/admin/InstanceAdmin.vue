<script setup lang="ts">
import { computed, onActivated, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { instanceAdminApi, type InstanceSmtp, type InstanceStatus, type SmtpTarget, type TechnicalAuditEntry, type TechnicalSupplier, type TechnicalSupplierStatus } from '@/api/instanceAdmin'
import { useAuthStore } from '@/stores/auth'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'

const { t, locale } = useI18n()
const auth = useAuthStore()
const status = ref<InstanceStatus | null>(null)
const smtp = ref<InstanceSmtp | null>(null)
const suppliers = ref<TechnicalSupplier[]>([])
const supplierCursor = ref<number | null>(null)
const audit = ref<TechnicalAuditEntry[]>([])
const auditCursor = ref<number | null>(null)
const selectedSupplier = ref<TechnicalSupplierStatus | null>(null)
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const notice = ref('')
const targetId = ref<number | null>(null)
const password = ref('')
const targets = ref<SmtpTarget[]>([])
const newTarget = reactive<Omit<SmtpTarget, 'id'>>({ name: '', host: '', port: 587, encryption: 'tls', username: '', from_email: '', test_recipient: '' })
const selectedTarget = computed(() => smtp.value?.targets.find(target => target.id === targetId.value))
const hasUnsavedSmtp = computed(() => targetId.value !== smtp.value?.target_id || password.value !== '')
watch(targetId, () => { password.value = '' })
const needsPassword = computed(() => targetId.value !== smtp.value?.target_id || !smtp.value?.password_set)
const canSave = computed(() => !busy.value && !smtp.value?.read_only && targetId.value !== null && (!needsPassword.value || password.value.length > 0))
let firstActivation = true
let generation = 0

function fail(e: unknown) {
  const code = (e as { response?: { data?: { error?: { code?: string } } } })?.response?.data?.error?.code
  const safeCodes = ['smtp_test_rate_limited', 'smtp_not_configured', 'smtp_test_failed', 'smtp_target_requires_password', 'smtp_supplier_profile_unsupported', 'smtp_target_in_use', 'smtp_managed', 'smtp_environment_locked']
  error.value = safeCodes.includes(code ?? '') ? t(`instance_admin.errors.${code}`) : t('common.error')
}

async function load() {
  const current = ++generation
  loading.value = true
  error.value = ''
  status.value = null
  smtp.value = null
  suppliers.value = []
  audit.value = []
  selectedSupplier.value = null
  password.value = ''
  try {
    const [state, mail, companies, events] = await Promise.all([
      instanceAdminApi.status(), instanceAdminApi.smtp(), instanceAdminApi.suppliers(), instanceAdminApi.audit(),
    ])
    if (current !== generation) return
    status.value = state
    smtp.value = mail
    targetId.value = mail.target_id
    password.value = ''
    targets.value = mail.targets
    suppliers.value = companies.data
    supplierCursor.value = companies.next_cursor
    audit.value = events.data
    auditCursor.value = events.next_cursor
    selectedSupplier.value = null
  } catch (e) { if (current === generation) fail(e) }
  finally { if (current === generation) loading.value = false }
}

async function saveSmtp() {
  if (!canSave.value || targetId.value === null) return
  busy.value = true
  error.value = ''
  notice.value = ''
  try {
    await instanceAdminApi.saveSmtp({ target_id: targetId.value, ...(password.value ? { password: password.value } : {}) })
    password.value = ''
    await load()
    notice.value = t('instance_admin.saved')
  } catch (e) { fail(e) }
  finally { password.value = ''; busy.value = false }
}

async function testSmtp(supplierId?: number) {
  busy.value = true
  error.value = ''
  notice.value = ''
  try {
    const result = supplierId === undefined ? await instanceAdminApi.testSmtp() : await instanceAdminApi.testSupplierSmtp(supplierId)
    notice.value = t('instance_admin.test_sent', { recipient: result.test_recipient })
    const events = await instanceAdminApi.audit()
    audit.value = events.data
    auditCursor.value = events.next_cursor
  } catch (e) { fail(e) }
  finally { busy.value = false }
}

async function selectSupplier(id: number) {
  busy.value = true
  error.value = ''
  selectedSupplier.value = null
  try { selectedSupplier.value = await instanceAdminApi.supplierStatus(id) }
  catch (e) { fail(e) }
  finally { busy.value = false }
}

async function moreSuppliers() {
  if (supplierCursor.value === null || busy.value) return
  busy.value = true
  try {
    const page = await instanceAdminApi.suppliers(supplierCursor.value)
    suppliers.value.push(...page.data)
    supplierCursor.value = page.next_cursor
  } catch (e) { fail(e) }
  finally { busy.value = false }
}

async function moreAudit() {
  if (auditCursor.value === null || busy.value) return
  busy.value = true
  try {
    const page = await instanceAdminApi.audit(auditCursor.value)
    audit.value.push(...page.data)
    auditCursor.value = page.next_cursor
  } catch (e) { fail(e) }
  finally { busy.value = false }
}

async function createTarget() {
  if (!auth.isSuperadmin || busy.value) return
  busy.value = true
  error.value = ''
  try {
    await instanceAdminApi.createTarget({ ...newTarget })
    Object.assign(newTarget, { name: '', host: '', port: 587, encryption: 'tls', username: '', from_email: '', test_recipient: '' })
    await load()
    notice.value = t('instance_admin.target_created')
  } catch (e) { fail(e) }
  finally { busy.value = false }
}

async function deleteTarget(target: SmtpTarget) {
  if (!auth.isSuperadmin || busy.value || !confirm(t('instance_admin.delete_target_confirm', { name: target.name }))) return
  busy.value = true
  error.value = ''
  try { await instanceAdminApi.deleteTarget(target.id); await load() }
  catch (e) { fail(e) }
  finally { busy.value = false }
}

async function resetSmtpOverride() {
  if (!auth.isSuperadmin || busy.value || smtp.value?.read_only || smtp.value?.source !== 'approved_target') return
  if (!confirm(t('instance_admin.reset_override_confirm'))) return
  busy.value = true
  error.value = ''
  notice.value = ''
  try {
    await instanceAdminApi.resetSmtpOverride()
    await load()
    notice.value = t('instance_admin.reset_override_done')
  } catch (e) { fail(e) }
  finally { busy.value = false }
}

function auditAction(action: string): string {
  const names: Record<string, string> = { 'instance_admin.smtp_updated': 'smtp_updated', 'instance_admin.smtp_test': 'smtp_test', 'instance_admin.smtp_target_approved': 'target_approved', 'instance_admin.smtp_target_deleted': 'target_deleted', 'instance_admin.smtp_override_reset': 'override_reset' }
  return t(names[action] ? `instance_admin.audit_actions.${names[action]}` : 'instance_admin.technical_change')
}
function auditResult(result: string): string {
  return t(['ok', 'started', 'sent', 'failed'].includes(result) ? `instance_admin.audit_results.${result}` : 'instance_admin.technical_change')
}
const available = (value: boolean) => t(value ? 'instance_admin.available' : 'instance_admin.unavailable')
const freeSpace = computed(() => status.value?.storage.free_bytes == null ? '—' : `${new Intl.NumberFormat(locale.value, { maximumFractionDigits: 1 }).format(status.value.storage.free_bytes / 1024 ** 3)} GB`)
onMounted(load)
onActivated(() => { if (firstActivation) firstActivation = false; else void load() })
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-center justify-between gap-3">
      <div><h1 class="text-2xl font-semibold">{{ t('instance_admin.title') }}</h1><p class="mt-1 text-sm text-neutral-500">{{ t('instance_admin.subtitle') }}</p></div>
      <button :class="btnOutline('neutral')" :disabled="loading || busy" @click="load"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>{{ t('common.refresh') }}</button>
    </header>
    <p v-if="error" role="alert" class="rounded-lg border border-danger-200 bg-danger-50 p-3 text-danger-700">{{ error }}</p>
    <p v-if="notice" role="status" class="rounded-lg border border-success-200 bg-success-50 p-3 text-success-700">{{ notice }}</p>
    <p v-if="loading" role="status" class="text-neutral-500">{{ t('common.loading') }}</p>

    <section v-if="status" class="rounded-lg border border-neutral-200 bg-surface p-4">
      <h2 class="mb-4 text-lg font-semibold">{{ t('instance_admin.runtime') }}</h2>
      <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div><dt class="text-sm text-neutral-500">{{ t('instance_admin.app_version') }}</dt><dd class="font-medium">{{ status.version }}</dd></div>
        <div><dt class="text-sm text-neutral-500">{{ t('instance_admin.php_version') }}</dt><dd class="font-medium">{{ status.php_version }}</dd></div>
        <div><dt class="text-sm text-neutral-500">{{ t('instance_admin.database') }}</dt><dd class="font-medium">{{ available(status.database.available) }} <span v-if="status.database.available">{{ status.database.version }}</span></dd></div>
        <div><dt class="text-sm text-neutral-500">{{ t('instance_admin.free_space') }}</dt><dd class="font-medium">{{ freeSpace }}</dd></div>
      </dl>
      <div class="mt-4 flex flex-wrap gap-2"><span v-for="extension in status.extensions" :key="extension.name" class="rounded-md px-2 py-1 text-xs" :class="extension.available ? 'bg-success-50 text-success-700' : 'bg-danger-50 text-danger-700'">{{ extension.name }}: {{ available(extension.available) }}</span></div>
    </section>

    <section v-if="smtp" class="rounded-lg border border-neutral-200 bg-surface p-4">
      <h2 class="text-lg font-semibold">{{ t('instance_admin.smtp') }}</h2>
      <p class="mt-1 text-sm text-neutral-500">{{ t('instance_admin.smtp_hint') }}</p>
      <p v-if="smtp.read_only" class="mt-3 rounded-md bg-warning-50 p-3 text-sm text-warning-800">{{ t(smtp.read_only_reason === 'environment' ? 'instance_admin.environment_locked' : 'instance_admin.managed_locked') }}</p>
      <form class="mt-4 space-y-4" @submit.prevent="saveSmtp">
        <label class="block text-sm font-medium">{{ t('instance_admin.approved_target') }}
          <select v-model.number="targetId" data-smtp-target :disabled="smtp.read_only || busy" class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3"><option :value="null">{{ t('instance_admin.configuration_source') }}</option><option v-for="target in smtp.targets" :key="target.id" :value="target.id">{{ target.name }} — {{ target.host }}:{{ target.port }}</option></select>
        </label>
        <p v-if="!smtp.read_only && smtp.targets.length === 0" class="rounded-md bg-neutral-50 p-3 text-sm text-neutral-600">{{ t('instance_admin.no_approved_targets') }}</p>
        <p v-if="selectedTarget" class="break-words text-sm text-neutral-500">{{ t('instance_admin.target_identity', { username: selectedTarget.username, sender: selectedTarget.from_email }) }}</p>
        <label class="block text-sm font-medium">{{ t('instance_admin.new_password') }}
          <input v-model="password" data-smtp-password type="password" autocomplete="new-password" :disabled="smtp.read_only || busy || targetId === null" :required="needsPassword && targetId !== null" class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3" />
          <span class="mt-1 block text-xs font-normal text-neutral-500">{{ t(needsPassword ? 'instance_admin.password_required' : 'instance_admin.password_keep') }}</span>
        </label>
        <p class="text-sm text-neutral-500">{{ t('instance_admin.fixed_recipient', { recipient: selectedTarget?.test_recipient ?? smtp.test_recipient ?? '—' }) }}</p>
        <div class="flex flex-wrap gap-2">
          <button type="submit" data-smtp-save :class="btnFilled('primary')" :disabled="!canSave"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ t('common.save') }}</button>
          <button type="button" data-smtp-test :class="btnOutline('success')" :disabled="busy || hasUnsavedSmtp || !smtp.configured || !smtp.test_recipient" @click="testSmtp()"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.send" /></svg>{{ t('instance_admin.send_test') }}</button>
          <button v-if="auth.isSuperadmin && smtp.source === 'approved_target' && !smtp.read_only" type="button" data-smtp-reset :class="btnOutline('warning')" :disabled="busy" @click="resetSmtpOverride"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.uturn" /></svg>{{ t('instance_admin.reset_override') }}</button>
        </div>
      </form>
    </section>

    <section class="rounded-lg border border-neutral-200 bg-surface p-4">
      <h2 class="text-lg font-semibold">{{ t('instance_admin.suppliers') }}</h2>
      <p class="mt-1 text-sm text-neutral-500">{{ t('instance_admin.suppliers_hint') }}</p>
      <p v-if="!loading && !suppliers.length" class="mt-4 text-sm text-neutral-500">{{ t('instance_admin.no_suppliers') }}</p>
      <ul class="mt-4 divide-y divide-neutral-100"><li v-for="supplier in suppliers" :key="supplier.supplier_id" class="flex flex-wrap items-center justify-between gap-2 py-3"><span>{{ supplier.name }} <span class="text-xs text-neutral-500">#{{ supplier.supplier_id }}</span></span><button :class="btnOutline('neutral')" :disabled="busy" @click="selectSupplier(supplier.supplier_id)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.eye" /></svg>{{ t('instance_admin.technical_status') }}</button></li></ul>
      <button v-if="supplierCursor !== null" :class="btnOutline('neutral')" :disabled="busy" @click="moreSuppliers"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('common.load_more') }}</button>
      <div v-if="selectedSupplier" class="mt-4 rounded-md border border-neutral-200 p-3">
        <h3 class="font-medium">{{ selectedSupplier.name }}</h3>
        <p class="mt-1 text-sm text-neutral-500">{{ t(selectedSupplier.mail_transport === 'supplier_profile' ? 'instance_admin.supplier_profile_unsupported' : 'instance_admin.supplier_global_transport') }}</p>
        <button v-if="selectedSupplier.smtp_test_supported" :class="btnOutline('success')" class="mt-3" :disabled="busy" @click="testSmtp(selectedSupplier.supplier_id)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.send" /></svg>{{ t('instance_admin.send_test') }}</button>
      </div>
    </section>

    <section v-if="auth.isSuperadmin" class="rounded-lg border border-neutral-200 bg-surface p-4">
      <h2 class="text-lg font-semibold">{{ t('instance_admin.target_management') }}</h2>
      <p class="mt-1 text-sm text-neutral-500">{{ t('instance_admin.target_management_hint') }}</p>
      <ul class="mt-3 divide-y divide-neutral-100"><li v-for="target in targets" :key="target.id" class="flex flex-wrap items-center justify-between gap-2 py-3"><span class="break-all">{{ target.name }} — {{ target.host }}:{{ target.port }}</span><button :class="btnOutline('danger')" :disabled="busy || smtp?.read_only || smtp?.target_id === target.id" @click="deleteTarget(target)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>{{ t('common.delete') }}</button></li></ul>
      <form class="mt-4 grid gap-3 sm:grid-cols-2" @submit.prevent="createTarget">
        <label class="text-sm">{{ t('instance_admin.target_name') }}<input v-model="newTarget.name" required maxlength="100" class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3" /></label>
        <label class="text-sm">{{ t('instance_admin.host') }}<input v-model="newTarget.host" required maxlength="253" class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3" /></label>
        <label class="text-sm">{{ t('instance_admin.port') }}<input v-model.number="newTarget.port" type="number" min="1" max="65535" required class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3" /></label>
        <label class="text-sm">{{ t('instance_admin.encryption') }}<select v-model="newTarget.encryption" class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3"><option value="tls">{{ t('instance_admin.starttls') }}</option><option value="ssl">{{ t('instance_admin.implicit_tls') }}</option></select></label>
        <label class="text-sm">{{ t('instance_admin.username') }}<input v-model="newTarget.username" required maxlength="190" autocomplete="off" class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3" /></label>
        <label class="text-sm">{{ t('instance_admin.sender') }}<input v-model="newTarget.from_email" type="email" required maxlength="254" class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3" /></label>
        <label class="text-sm">{{ t('instance_admin.test_recipient') }}<input v-model="newTarget.test_recipient" type="email" required maxlength="254" class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3" /></label>
        <div class="flex flex-wrap items-end gap-2"><button type="submit" :class="btnFilled('warning')" :disabled="busy || smtp?.read_only"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.checkCircle" /></svg>{{ t('instance_admin.approve_target') }}</button></div>
      </form>
    </section>

    <section class="rounded-lg border border-neutral-200 bg-surface p-4">
      <h2 class="text-lg font-semibold">{{ t('instance_admin.audit') }}</h2>
      <p class="mt-1 text-sm text-neutral-500">{{ t('instance_admin.audit_hint') }}</p>
      <p v-if="!loading && !audit.length" class="mt-3 text-sm text-neutral-500">{{ t('instance_admin.no_audit') }}</p>
      <ul class="mt-3 divide-y divide-neutral-100"><li v-for="event in audit" :key="event.id" class="flex flex-wrap items-center justify-between gap-2 py-3 text-sm"><span>{{ event.created_at }} · {{ auditAction(event.action) }}<span v-if="event.supplier_id"> · #{{ event.supplier_id }}</span></span><span class="rounded-md bg-neutral-100 px-2 py-1 text-xs">{{ auditResult(event.result) }}</span></li></ul>
      <button v-if="auditCursor !== null" :class="btnOutline('neutral')" :disabled="busy" @click="moreAudit"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('common.load_more') }}</button>
    </section>
  </div>
</template>
