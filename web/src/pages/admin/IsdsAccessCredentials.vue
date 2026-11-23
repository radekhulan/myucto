<script setup lang="ts">
import { computed, onActivated, onDeactivated, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { authApi } from '@/api/auth'
import { apiErrorMessage } from '@/api/errors'
import { isdsAccessApi, type IsdsAdminAccess } from '@/api/isdsAccess'
import SsoStepUpOption from '@/components/security/SsoStepUpOption.vue'
import DialogActions, { type DialogAction } from '@/components/ui/DialogActions.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Modal from '@/components/ui/Modal.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import { formatDate } from '@/composables/useFormat'
import { useSsoEnforcement } from '@/composables/useSsoEnforcement'
import { useToast } from '@/composables/useToast'
import { getCredential, isWebAuthnAvailable } from '@/security/webauthn'
import { useAuthStore } from '@/stores/auth'

type Overview = Awaited<ReturnType<typeof isdsAccessApi.adminList>>
type Grant = IsdsAdminAccess['grants'][number]
type GrantOperation = 'send' | 'receive' | 'receipts'
type Dialog = { kind: 'edit'; item: IsdsAdminAccess | null } | { kind: 'delete'; item: IsdsAdminAccess }

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const sso = useSsoEnforcement()
const { passwordAllowed, localFactorsAllowed } = sso
const proofOperation = 'epo.certificate'
const methods = ['password', 'certificate', 'user_certificate'] as const
const grantOperations = ['send', 'receipts', 'receive'] as const
const hasTotp = computed(() => auth.user?.totp_enabled === true)
const hasPasskey = computed(() => auth.user?.mfa_methods?.includes('passkey') === true || (auth.user?.passkey_count ?? 0) > 0)
const passkeySupported = isWebAuthnAvailable()

const overview = ref<Overview | null>(null)
const loading = ref(false)
const error = ref('')
const dialog = ref<Dialog | null>(null)
const formError = ref('')
const busy = ref(false)
const passkeyBusy = ref(false)
const label = ref('')
const environment = ref<IsdsAdminAccess['environment']>('production')
const method = ref<IsdsAdminAccess['method']>('password')
const username = ref('')
const password = ref('')
const certificateId = ref<number | null>(null)
const replaceCredentials = ref(false)
const grants = ref<Record<number, Grant>>({})
const reauthPassword = ref('')
const totpCode = ref('')
const proofToken = ref('')
const fromSso = ref(false)

const companiesById = computed(() => new Map(overview.value?.companies.map(company => [company.id, company.name])))
const certificate = computed(() => overview.value?.certificates.find(item => item.id === certificateId.value))
const editing = computed(() => dialog.value?.kind === 'edit' ? dialog.value.item : null)
const enteringCredentials = computed(() => dialog.value?.kind === 'edit' && (!editing.value || replaceCredentials.value))
const requiresPassword = computed(() => method.value !== 'certificate')
const requiresCertificate = computed(() => method.value !== 'password')
const receiverId = computed(() => Object.values(grants.value).find(grant => grant.receive)?.supplier_id)
const missingReauth = computed(() => {
  const missing = sso.enforcedStepUpMissing(proofToken.value, fromSso.value, totpCode.value, hasTotp.value)
  if (missing !== null) return missing
  if (proofToken.value || (reauthPassword.value && (!hasTotp.value || /^\d{6}$/.test(totpCode.value.trim())))) return ''
  return 'isdsAccess.admin.verifyIdentityHint'
})
const formReady = computed(() => {
  if (dialog.value?.kind !== 'edit') return dialog.value?.kind === 'delete'
  if (!label.value.trim()) return false
  if (!enteringCredentials.value) return true
  return (!requiresPassword.value || (username.value.trim() !== '' && password.value !== ''))
    && (!requiresCertificate.value || !!certificate.value)
})
const actions = computed<DialogAction[]>(() => {
  const deleting = dialog.value?.kind === 'delete'
  return [
    { key: 'cancel', label: t('common.cancel'), icon: 'x', disabled: busy.value, run: closeDialog },
    {
      key: deleting ? 'remove' : 'save',
      label: t(deleting ? 'isdsAccess.admin.remove' : 'common.save'),
      icon: deleting ? 'trash' : 'check',
      variant: deleting ? 'danger' : 'primary',
      tier: 'primary',
      loading: busy.value,
      disabled: busy.value || passkeyBusy.value || !formReady.value || !!missingReauth.value,
      disabledReason: missingReauth.value ? t(missingReauth.value) : undefined,
      run: submit,
    },
  ]
})

async function load() {
  if (loading.value || !auth.isSuperadmin || auth.isDemo) return
  loading.value = true
  error.value = ''
  try {
    overview.value = await isdsAccessApi.adminList()
  } catch (exception: unknown) {
    error.value = apiErrorMessage(exception, t('common.error'))
  } finally {
    loading.value = false
  }
}

onMounted(load)
onActivated(load)
onDeactivated(resetDialog)

function clearProof() {
  reauthPassword.value = ''
  totpCode.value = ''
  proofToken.value = ''
  fromSso.value = false
}

function clearCredentials() {
  username.value = ''
  password.value = ''
  certificateId.value = null
}

function resetDialog() {
  dialog.value = null
  formError.value = ''
  clearCredentials()
  clearProof()
}

function closeDialog() {
  if (!busy.value && !passkeyBusy.value) resetDialog()
}

function openEdit(item: IsdsAdminAccess | null = null) {
  resetDialog()
  label.value = item?.label ?? ''
  environment.value = item?.environment ?? 'production'
  method.value = item?.method ?? 'password'
  replaceCredentials.value = false
  grants.value = Object.fromEntries((item?.grants ?? []).map(grant => [grant.supplier_id, { ...grant }]))
  dialog.value = { kind: 'edit', item }
}

function openDelete(item: IsdsAdminAccess) {
  resetDialog()
  dialog.value = { kind: 'delete', item }
}

watch(method, clearCredentials)
watch(replaceCredentials, enabled => {
  if (!enabled && editing.value) {
    method.value = editing.value.method
    clearCredentials()
  }
})

function setGrant(supplierId: number, operation: GrantOperation, enabled: boolean) {
  const current = grants.value[supplierId] ?? { supplier_id: supplierId, send: false, receive: false, receipts: false }
  grants.value[supplierId] = { ...current, [operation]: enabled }
}

async function verifyPasskey() {
  if (passkeyBusy.value || !passkeySupported) return
  const target = dialog.value
  formError.value = ''
  passkeyBusy.value = true
  try {
    const flow = await authApi.passkeyStepUpOptions(proofOperation)
    const credential = await getCredential(flow.public_key)
    const token = await authApi.passkeyStepUpVerify(flow.flow_token, proofOperation, credential)
    if (dialog.value === target) {
      fromSso.value = false
      proofToken.value = token
    }
  } catch (exception: unknown) {
    if (dialog.value === target) {
      formError.value = apiErrorMessage(exception, t('reports.submissions.step_up_passkey_failed'))
    }
  } finally {
    passkeyBusy.value = false
  }
}

async function submit() {
  const target = dialog.value
  if (!target || busy.value || passkeyBusy.value || !formReady.value || missingReauth.value) return
  busy.value = true
  formError.value = ''
  try {
    const stepUp = await sso.reauthProof(proofOperation, proofToken.value
      ? { step_up_token: proofToken.value }
      : { password: reauthPassword.value || undefined, totp_code: totpCode.value.trim() || undefined })
    if (dialog.value !== target) return
    if (target.kind === 'delete') {
      await isdsAccessApi.adminRemove(target.item.id, stepUp)
      toast.success(t('isdsAccess.admin.removed'))
    } else {
      await isdsAccessApi.adminSave(target.item?.id ?? null, {
        label: label.value.trim(),
        environment: environment.value,
        method: method.value,
        ...(enteringCredentials.value && requiresPassword.value ? { username: username.value.trim(), password: password.value } : {}),
        ...(enteringCredentials.value && requiresCertificate.value && certificateId.value !== null
          ? { certificate_id: certificateId.value } : {}),
        grants: Object.values(grants.value).filter(grant => grant.send || grant.receive || grant.receipts),
        step_up: stepUp,
      })
      toast.success(t('isdsAccess.admin.saved'))
    }
    resetDialog()
    await load()
  } catch (exception: unknown) {
    if (dialog.value === target) {
      formError.value = apiErrorMessage(exception, t('common.error'))
      clearProof()
    }
  } finally {
    busy.value = false
  }
}

function receiveLocked(supplierId: number) {
  return receiverId.value !== undefined && receiverId.value !== supplierId
}

function grantDescription(grant: Grant) {
  return grantOperations.filter(operation => grant[operation]).map(operation => t(`isdsAccess.admin.operation.${operation}`)).join(', ')
}
</script>

<template>
  <div v-if="auth.isSuperadmin && !auth.isDemo" class="space-y-4">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('isdsAccess.admin.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('isdsAccess.admin.intro') }}</p>
      </div>
      <button type="button" :class="[btnFilled('primary'), 'whitespace-nowrap']" :disabled="!overview || loading" @click="openEdit()">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" stroke-linecap="round" stroke-linejoin="round" /></svg>
        {{ t('isdsAccess.admin.add') }}
      </button>
    </header>

    <p v-if="loading && !overview" class="text-sm text-neutral-500" role="status">{{ t('common.loading') }}</p>
    <EmptyState v-else-if="error" variant="failed" :message="error" @action="load" />
    <EmptyState v-else-if="overview && !overview.items.length" :title="t('isdsAccess.admin.empty')" />

    <ul v-if="overview?.items.length" class="divide-y divide-neutral-200 rounded-lg border border-neutral-200 bg-surface shadow-sm">
      <li v-for="item in overview.items" :key="item.id" class="flex flex-wrap items-start justify-between gap-3 p-4">
        <div class="min-w-0">
          <h2 class="break-words font-medium text-neutral-900">{{ item.label }}</h2>
          <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-neutral-500">
            <span class="font-mono">{{ item.box_id }}</span>
            <span>{{ t(`isdsAccess.admin.environment.${item.environment}`) }}</span>
            <span>{{ t(`isdsAccess.admin.method.${item.method}`) }}</span>
          </p>
          <ul v-if="item.grants.length" class="mt-2 space-y-1 text-sm text-neutral-600">
            <li v-for="grant in item.grants" :key="grant.supplier_id">
              <span class="font-medium">{{ companiesById.get(grant.supplier_id) ?? t('isdsAccess.admin.companyFallback', { id: grant.supplier_id }) }}</span>
              <span> · {{ grantDescription(grant) }}</span>
            </li>
          </ul>
          <p v-else class="mt-2 text-sm text-neutral-500">{{ t('isdsAccess.admin.noGrants') }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <button v-if="item.environment !== 'test' || auth.submissionTestEnvironmentAllowed" type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" @click="openEdit(item)">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" stroke-linecap="round" stroke-linejoin="round" /></svg>
            {{ t('common.edit') }}
          </button>
          <button type="button" :class="[btnOutline('danger'), 'whitespace-nowrap']" @click="openDelete(item)">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" stroke-linecap="round" stroke-linejoin="round" /></svg>
            {{ t('isdsAccess.admin.remove') }}
          </button>
        </div>
      </li>
    </ul>

    <Modal v-if="dialog" :title="t(dialog.kind === 'delete' ? 'isdsAccess.admin.removeTitle' : (editing ? 'isdsAccess.admin.editTitle' : 'isdsAccess.admin.add'))" @close="closeDialog">
      <div class="space-y-4">
        <template v-if="dialog.kind === 'edit'">
          <div class="grid gap-3 sm:grid-cols-2">
            <label class="block text-sm">
              <span class="font-medium">{{ t('isdsAccess.admin.label') }}</span>
              <input v-model="label" type="text" maxlength="120" class="form-input mt-1 w-full" :disabled="busy" />
            </label>
            <label class="block text-sm">
              <span class="font-medium">{{ t('isdsAccess.admin.environmentLabel') }}</span>
              <select v-model="environment" class="form-select mt-1 w-full" :disabled="busy || !!editing">
                <option value="production">{{ t('isdsAccess.admin.environment.production') }}</option>
                <option v-if="auth.submissionTestEnvironmentAllowed" value="test">{{ t('isdsAccess.admin.environment.test') }}</option>
              </select>
            </label>
          </div>
          <p v-if="editing" class="text-sm text-neutral-700">
            {{ t('isdsAccess.admin.verifiedBox') }} <span class="font-mono">{{ editing.box_id }}</span>
            · {{ t(`isdsAccess.admin.method.${editing.method}`) }}
          </p>
          <label v-if="editing" class="flex items-center gap-2 text-sm">
            <input v-model="replaceCredentials" type="checkbox" :disabled="busy" />
            <span>{{ t('isdsAccess.admin.replace') }}</span>
          </label>
          <fieldset v-if="enteringCredentials" :disabled="busy" class="space-y-3">
            <label class="block text-sm">
              <span class="font-medium">{{ t('isdsAccess.admin.methodLabel') }}</span>
              <select v-model="method" class="form-select mt-1 w-full">
                <option v-for="value in methods" :key="value" :value="value">{{ t(`isdsAccess.admin.method.${value}`) }}</option>
              </select>
            </label>
            <div v-if="requiresPassword" class="grid gap-3 sm:grid-cols-2">
              <label class="block text-sm">
                <span class="font-medium">{{ t('isdsAccess.admin.username') }}</span>
                <input v-model="username" type="text" autocomplete="off" class="form-input mt-1 w-full" />
              </label>
              <label class="block text-sm">
                <span class="font-medium">{{ t('isdsAccess.admin.password') }}</span>
                <input v-model="password" type="password" autocomplete="new-password" class="form-input mt-1 w-full" />
              </label>
            </div>
            <div v-if="requiresCertificate">
              <label class="block text-sm">
                <span class="font-medium">{{ t('isdsAccess.admin.certificate') }}</span>
                <select v-model="certificateId" class="form-select mt-1 w-full">
                  <option :value="null">{{ t('isdsAccess.admin.selectCertificate') }}</option>
                  <option v-for="item in overview?.certificates" :key="item.id" :value="item.id">{{ item.label }}</option>
                </select>
              </label>
              <p v-if="certificate?.valid_to" class="mt-1 text-sm text-neutral-500">{{ t('isdsAccess.admin.certificateValidity', { date: formatDate(certificate.valid_to) }) }}</p>
              <RouterLink v-if="!overview?.certificates.length" to="/admin/electronic-signatures#certificate-vault" class="mt-1 inline-block text-sm text-primary-700 underline">
                {{ t('isdsAccess.admin.goToVault') }}
              </RouterLink>
            </div>
            <p class="text-sm text-neutral-500">{{ t(editing ? 'isdsAccess.admin.verifyExisting' : 'isdsAccess.admin.verifyNew') }}</p>
          </fieldset>

          <fieldset :disabled="busy" class="space-y-2">
            <legend class="font-semibold">{{ t('isdsAccess.admin.companies') }}</legend>
            <p class="text-sm text-neutral-500">{{ t('isdsAccess.admin.companiesHint') }}</p>
            <p v-if="!overview?.companies.length" class="text-sm text-neutral-500">{{ t('isdsAccess.admin.noCompanies') }}</p>
            <ul v-else class="max-h-64 divide-y divide-neutral-200 overflow-auto rounded-md border border-neutral-200">
              <li v-for="company in overview.companies" :key="company.id" class="space-y-2 p-3">
                <p class="font-medium text-neutral-900">{{ company.name }}</p>
                <div class="flex flex-wrap gap-x-4 gap-y-2">
                  <label v-for="operation in grantOperations" :key="operation" class="flex items-center gap-2 text-sm">
                    <input type="checkbox" :checked="grants[company.id]?.[operation] === true" :disabled="operation === 'receive' && receiveLocked(company.id)"
                      :aria-label="`${company.name} · ${t(`isdsAccess.admin.operation.${operation}`)}`"
                      @change="setGrant(company.id, operation, ($event.target as HTMLInputElement).checked)" />
                    <span>{{ t(`isdsAccess.admin.operation.${operation}`) }}</span>
                  </label>
                </div>
              </li>
            </ul>
          </fieldset>
        </template>
        <p v-else class="text-sm text-neutral-700">{{ t('isdsAccess.admin.removeHint', { label: dialog.item.label }) }}</p>

        <fieldset :disabled="busy" class="space-y-2 rounded-lg border border-neutral-200 p-3">
          <legend class="px-1 text-sm font-semibold">{{ t('isdsAccess.admin.verifyIdentity') }}</legend>
          <SsoStepUpOption v-if="!proofToken || fromSso" :operation="proofOperation" :context="`isds-access:${dialog.kind}:${dialog.item?.id ?? 'new'}`"
            :verified="fromSso && !!proofToken" :disabled="busy || passkeyBusy" @verified="token => { proofToken = token; fromSso = true }" />
          <p v-if="proofToken && !fromSso" class="text-sm text-success-700">{{ t('reports.submissions.step_up_passkey_verified') }}</p>
          <template v-if="!proofToken">
            <button v-if="localFactorsAllowed && hasPasskey && passkeySupported" type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']"
              :disabled="passkeyBusy" @click="verifyPasskey">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.lock" stroke-linecap="round" stroke-linejoin="round" /></svg>
              {{ t(passkeyBusy ? 'auth.passkey_verifying' : 'reports.submissions.step_up_verify_passkey') }}
            </button>
            <div class="grid gap-2 sm:grid-cols-2">
              <label v-if="passwordAllowed" class="block text-sm">
                {{ t('isdsAccess.admin.reauthPassword') }}
                <input v-model="reauthPassword" type="password" autocomplete="current-password" class="form-input mt-1 w-full" />
              </label>
              <label v-if="localFactorsAllowed && hasTotp" class="block text-sm">
                {{ t('isdsAccess.admin.totp') }}
                <input v-model="totpCode" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="form-input mt-1 w-full" />
              </label>
            </div>
          </template>
        </fieldset>
        <p v-if="formError" role="alert" class="text-sm text-danger-700">{{ formError }}</p>
        <DialogActions :actions="actions" />
      </div>
    </Modal>
  </div>
</template>
