<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  settingsApi,
  type EmailProfile,
  type EmailProfileImapFolder,
  type EmailProfilePayload,
  type SigningProfile,
} from '@/api/settings'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const auth = useAuthStore()
const props = withDefaults(defineProps<{ clientScoped?: boolean }>(), {
  clientScoped: false,
})

/**
 * H-02 — spravovaná instalace: odesílá hosting, obálku určuje jeho MTA a na ní
 * stojí SPF. Vlastní SMTP/sendmail transport se proto nenabízí, ale pole se
 * NESKRÝVÁ: uživatel musí vidět, proč tam volba není. Backend odmítá i bez UI.
 */
const transportLocked = computed(() => auth.isManagedInstallation)
const transportLockedHint = computed(() =>
  transportLocked.value ? t('settings.email_profile_transport_managed') : undefined,
)
const toast = useToast()
const pageId = useId()

const loading = ref(true)
const saving = ref(false)
const deletingId = ref<number | null>(null)
const testingId = ref<number | null>(null)
const testingDraft = ref(false)
const browsingImapFolders = ref(false)
const testingImapSettings = ref(false)
const editingHasSmtpPassword = ref(false)
const editingHasImapPassword = ref(false)
const profiles = ref<EmailProfile[]>([])
const signingProfiles = ref<SigningProfile[]>([])
const imapFolderOptions = ref<EmailProfileImapFolder[]>([])
const showForm = ref(false)
const editingId = ref<number | null>(null)
const configureReplyTo = ref(false)
const configureDkim = ref(false)
const configureImapSent = ref(false)
const validationErrors = ref<string[]>([])
const validationScope = ref<'profile' | 'imap' | null>(null)
const draftTestFeedback = ref<{
  status: 'success' | 'error'
  title: string
  detail: string
  smtpResponse?: string
  imapStatus?: string
} | null>(null)

const draft = reactive<EmailProfilePayload>({
  name: '',
  code: '',
  from_email: '',
  from_name: '',
  reply_to_email: '',
  reply_to_name: '',
  reply_to_enabled: false,
  signing_profile_id: null,
  dkim_domain: '',
  dkim_selector: '',
  dkim_enabled: false,
  transport_type: 'global',
  smtp_host: '',
  smtp_port: 587,
  smtp_encryption: 'tls',
  smtp_auth_enabled: false,
  smtp_auth_type: 'PLAIN',
  smtp_username: '',
  smtp_password: '',
  smtp_verify_peer: true,
  smtp_verify_peer_name: true,
  smtp_allow_self_signed: false,
  smtp_timeout: 30,
  smtp_keepalive: false,
  sendmail_command: '',
  imap_sent_enabled: false,
  imap_host: '',
  imap_port: 993,
  imap_encryption: 'ssl',
  imap_validate_cert: true,
  imap_username: '',
  imap_password: '',
  imap_folder: 'Sent',
  imap_create_folder: false,
  imap_mark_seen: true,
  imap_timeout: 30,
  imap_on_failure: 'log_only',
  is_default: false,
  is_active: true,
})

const emailSigningProfiles = computed(() =>
  signingProfiles.value.filter(profile =>
    profile.owner_user_id === null
    && profile.is_active
    && profile.allowed_usages.includes('email_smime'),
  ),
)

const selectedSigningProfile = computed(() =>
  emailSigningProfiles.value.find(profile => profile.id === draft.signing_profile_id) || null,
)

const smimeIdentityState = computed(() => {
  const profile = selectedSigningProfile.value
  if (profile === null) return null

  if (!profile.has_certificate) {
    return { level: 'neutral', text: t('settings.email_profile_smime_identity_no_cert') }
  }
  if (!profile.certificate_is_active) {
    return { level: 'warning', text: t('settings.email_profile_smime_identity_inactive_cert') }
  }
  if (isExpired(profile.certificate_valid_to)) {
    return { level: 'warning', text: t('settings.email_profile_smime_identity_expired_cert') }
  }
  if (!profile.certificate_email) {
    return { level: 'warning', text: t('settings.email_profile_smime_identity_no_email') }
  }

  const fromEmail = normalizeEmail(draft.from_email)
  const certificateEmail = normalizeEmail(profile.certificate_email)
  if (fromEmail === '') {
    return {
      level: 'neutral',
      text: t('settings.email_profile_smime_identity_waiting', { email: profile.certificate_email }),
    }
  }
  if (fromEmail === certificateEmail) {
    return {
      level: 'success',
      text: t('settings.email_profile_smime_identity_match', { email: profile.certificate_email }),
    }
  }

  return {
    level: 'danger',
    text: t('settings.email_profile_smime_identity_mismatch', { email: profile.certificate_email }),
  }
})

const smimeIdentityClass = computed(() => {
  switch (smimeIdentityState.value?.level) {
    case 'success':
      return 'border-success-200 bg-success-50 text-success-700'
    case 'danger':
      return 'border-danger-200 bg-danger-50 text-danger-700'
    case 'warning':
      return 'border-warning-200 bg-warning-50 text-warning-700'
    default:
      return 'border-neutral-200 bg-neutral-50 text-neutral-600'
  }
})

const smtpPasswordRequired = computed(() =>
  draft.transport_type === 'smtp'
  && draft.smtp_auth_enabled
  && (editingId.value === null || !editingHasSmtpPassword.value),
)
const imapPasswordRequired = computed(() =>
  configureImapSent.value
  && (editingId.value === null || !editingHasImapPassword.value),
)

onMounted(load)

watch(
  () => [
    draft.name,
    draft.code,
    draft.from_email,
    configureReplyTo.value,
    draft.reply_to_email,
    configureDkim.value,
    draft.dkim_domain,
    draft.dkim_selector,
    draft.transport_type,
    draft.smtp_host,
    draft.smtp_auth_enabled,
    draft.smtp_username,
    draft.smtp_password,
    smtpPasswordRequired.value,
    configureImapSent.value,
    draft.imap_host,
    draft.imap_username,
    draft.imap_password,
    draft.imap_folder,
    imapPasswordRequired.value,
  ],
  () => {
    if (validationErrors.value.length > 0) {
      if (validationScope.value === 'imap') {
        validateImapSettings()
      } else {
        validateDraft()
      }
    }
  },
)

watch(
  () => [
    configureImapSent.value,
    draft.imap_host,
    draft.imap_port,
    draft.imap_encryption,
    draft.imap_validate_cert,
    draft.imap_username,
    draft.imap_password,
    draft.imap_timeout,
  ],
  () => {
    imapFolderOptions.value = []
  },
)

async function load() {
  loading.value = true
  try {
    const [profileRows, signingRows] = await Promise.all([
      settingsApi.listEmailProfiles(props.clientScoped),
      props.clientScoped ? Promise.resolve([] as SigningProfile[]) : settingsApi.listSigningProfiles(),
    ])
    profiles.value = profileRows
    signingProfiles.value = signingRows
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('settings.email_profiles_load_failed'))
  } finally {
    loading.value = false
  }
}

function resetDraft() {
  editingId.value = null
  editingHasSmtpPassword.value = false
  editingHasImapPassword.value = false
  draftTestFeedback.value = null
  validationErrors.value = []
  validationScope.value = null
  imapFolderOptions.value = []
  draft.name = ''
  draft.code = ''
  draft.from_email = ''
  draft.from_name = ''
  draft.reply_to_email = ''
  draft.reply_to_name = ''
  draft.reply_to_enabled = false
  draft.signing_profile_id = null
  draft.dkim_domain = ''
  draft.dkim_selector = ''
  draft.dkim_enabled = false
  draft.transport_type = 'global'
  draft.smtp_host = ''
  draft.smtp_port = 587
  draft.smtp_encryption = 'tls'
  draft.smtp_auth_enabled = false
  draft.smtp_auth_type = 'PLAIN'
  draft.smtp_username = ''
  draft.smtp_password = ''
  draft.smtp_verify_peer = true
  draft.smtp_verify_peer_name = true
  draft.smtp_allow_self_signed = false
  draft.smtp_timeout = 30
  draft.smtp_keepalive = false
  draft.sendmail_command = ''
  draft.imap_sent_enabled = false
  draft.imap_host = ''
  draft.imap_port = 993
  draft.imap_encryption = 'ssl'
  draft.imap_validate_cert = true
  draft.imap_username = ''
  draft.imap_password = ''
  draft.imap_folder = 'Sent'
  draft.imap_create_folder = false
  draft.imap_mark_seen = true
  draft.imap_timeout = 30
  draft.imap_on_failure = 'log_only'
  draft.is_default = profiles.value.length === 0
  draft.is_active = true
  configureReplyTo.value = false
  configureDkim.value = false
  configureImapSent.value = false
}

function newProfile() {
  resetDraft()
  showForm.value = true
}

function editProfile(profile: EmailProfile) {
  draftTestFeedback.value = null
  validationErrors.value = []
  validationScope.value = null
  imapFolderOptions.value = []
  editingId.value = profile.id
  editingHasSmtpPassword.value = profile.has_smtp_password
  editingHasImapPassword.value = profile.has_imap_password
  draft.name = profile.name
  draft.code = profile.code
  draft.from_email = profile.from_email
  draft.from_name = profile.from_name || ''
  draft.reply_to_email = profile.reply_to_email || ''
  draft.reply_to_name = profile.reply_to_name || ''
  draft.reply_to_enabled = profile.reply_to_enabled
  draft.signing_profile_id = profile.signing_profile_id ?? null
  draft.dkim_domain = profile.dkim_domain || ''
  draft.dkim_selector = profile.dkim_selector || ''
  draft.dkim_enabled = profile.dkim_enabled
  draft.transport_type = profile.transport_type
  draft.smtp_host = profile.smtp_host || ''
  draft.smtp_port = profile.smtp_port || 587
  draft.smtp_encryption = profile.smtp_encryption
  draft.smtp_auth_enabled = profile.smtp_auth_enabled
  draft.smtp_auth_type = profile.smtp_auth_type
  draft.smtp_username = profile.smtp_username || ''
  draft.smtp_password = ''
  draft.smtp_verify_peer = profile.smtp_verify_peer
  draft.smtp_verify_peer_name = profile.smtp_verify_peer_name
  draft.smtp_allow_self_signed = profile.smtp_allow_self_signed
  draft.smtp_timeout = profile.smtp_timeout || 30
  draft.smtp_keepalive = profile.smtp_keepalive
  draft.sendmail_command = profile.sendmail_command || ''
  draft.imap_sent_enabled = profile.imap_sent_enabled
  draft.imap_host = profile.imap_host || ''
  draft.imap_port = profile.imap_port || 993
  draft.imap_encryption = profile.imap_encryption || 'ssl'
  draft.imap_validate_cert = profile.imap_validate_cert
  draft.imap_username = profile.imap_username || ''
  draft.imap_password = ''
  draft.imap_folder = profile.imap_folder || 'Sent'
  draft.imap_create_folder = profile.imap_create_folder
  draft.imap_mark_seen = profile.imap_mark_seen
  draft.imap_timeout = profile.imap_timeout || 30
  draft.imap_on_failure = profile.imap_on_failure || 'log_only'
  draft.is_default = profile.is_default
  draft.is_active = profile.is_active
  configureReplyTo.value = profile.reply_to_enabled
  configureDkim.value = profile.dkim_enabled
  configureImapSent.value = profile.imap_sent_enabled
  showForm.value = true
}

function payload(): EmailProfilePayload {
  const data: EmailProfilePayload = {
    name: draft.name,
    code: draft.code,
    from_email: draft.from_email,
    from_name: draft.from_name || null,
    reply_to_email: configureReplyTo.value ? (draft.reply_to_email || null) : null,
    reply_to_name: configureReplyTo.value ? (draft.reply_to_name || null) : null,
    reply_to_enabled: configureReplyTo.value,
    signing_profile_id: draft.signing_profile_id || null,
    dkim_domain: configureDkim.value ? (draft.dkim_domain || null) : null,
    dkim_selector: configureDkim.value ? (draft.dkim_selector || null) : null,
    dkim_enabled: configureDkim.value,
    transport_type: draft.transport_type || 'global',
    smtp_host: draft.transport_type === 'smtp' ? (draft.smtp_host || null) : null,
    smtp_port: draft.transport_type === 'smtp' ? (Number(draft.smtp_port) || 587) : null,
    smtp_encryption: draft.transport_type === 'smtp' ? (draft.smtp_encryption || 'tls') : 'tls',
    smtp_auth_enabled: draft.transport_type === 'smtp' ? Boolean(draft.smtp_auth_enabled) : false,
    smtp_auth_type: draft.transport_type === 'smtp' && draft.smtp_auth_enabled ? (draft.smtp_auth_type || 'PLAIN') : 'PLAIN',
    smtp_username: draft.transport_type === 'smtp' && draft.smtp_auth_enabled ? (draft.smtp_username || null) : null,
    smtp_password: draft.transport_type === 'smtp' && draft.smtp_auth_enabled && draft.smtp_password ? draft.smtp_password : null,
    smtp_verify_peer: draft.transport_type === 'smtp' ? Boolean(draft.smtp_verify_peer) : true,
    smtp_verify_peer_name: draft.transport_type === 'smtp' ? Boolean(draft.smtp_verify_peer_name) : true,
    smtp_allow_self_signed: draft.transport_type === 'smtp' ? Boolean(draft.smtp_allow_self_signed) : false,
    smtp_timeout: draft.transport_type === 'smtp' ? (Number(draft.smtp_timeout) || 30) : null,
    smtp_keepalive: draft.transport_type === 'smtp' ? Boolean(draft.smtp_keepalive) : false,
    sendmail_command: draft.transport_type === 'sendmail' ? (draft.sendmail_command || null) : null,
    imap_sent_enabled: configureImapSent.value,
    imap_host: configureImapSent.value ? (draft.imap_host || null) : null,
    imap_port: configureImapSent.value ? (Number(draft.imap_port) || 993) : null,
    imap_encryption: configureImapSent.value ? (draft.imap_encryption || 'ssl') : 'ssl',
    imap_validate_cert: configureImapSent.value ? Boolean(draft.imap_validate_cert) : true,
    imap_username: configureImapSent.value ? (draft.imap_username || null) : null,
    imap_password: configureImapSent.value && draft.imap_password ? draft.imap_password : null,
    imap_folder: configureImapSent.value ? (draft.imap_folder || 'Sent') : null,
    imap_create_folder: configureImapSent.value ? Boolean(draft.imap_create_folder) : false,
    imap_mark_seen: configureImapSent.value ? Boolean(draft.imap_mark_seen) : true,
    imap_timeout: configureImapSent.value ? (Number(draft.imap_timeout) || 30) : 30,
    imap_on_failure: configureImapSent.value ? (draft.imap_on_failure || 'log_only') : 'log_only',
    is_default: draft.is_default,
    is_active: draft.is_active,
  }
  if (props.clientScoped) {
    delete data.signing_profile_id
    delete data.sendmail_command
  }
  return data
}

function imapBrowsePayload(): Partial<EmailProfilePayload> {
  return {
    imap_sent_enabled: true,
    imap_host: draft.imap_host || null,
    imap_port: Number(draft.imap_port) || 993,
    imap_encryption: draft.imap_encryption || 'ssl',
    imap_validate_cert: Boolean(draft.imap_validate_cert),
    imap_username: draft.imap_username || null,
    imap_password: draft.imap_password || null,
    imap_folder: draft.imap_folder || 'Sent',
    imap_create_folder: Boolean(draft.imap_create_folder),
    imap_mark_seen: Boolean(draft.imap_mark_seen),
    imap_timeout: Number(draft.imap_timeout) || 30,
    imap_on_failure: draft.imap_on_failure || 'log_only',
  }
}

function prefillFromSmime() {
  const profile = selectedSigningProfile.value
  if (!profile?.certificate_email) return
  draft.from_email = normalizeEmail(profile.certificate_email)

  const cn = certificateCommonName(profile.certificate_subject)
  if (cn !== null && String(draft.from_name || '').trim() === '') {
    draft.from_name = cn
  }
}

async function saveProfile() {
  if (!validateDraft()) {
    toast.error(t('settings.email_profile_validation_failed'))
    return
  }

  saving.value = true
  try {
    const data = payload()
    if (editingId.value === null) {
      await settingsApi.createEmailProfile(data, props.clientScoped)
    } else {
      await settingsApi.updateEmailProfile(editingId.value, data, props.clientScoped)
    }
    showForm.value = false
    resetDraft()
    toast.success(t('settings.email_profile_saved'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
    return
  } finally {
    saving.value = false
  }

  await load()
}

async function deleteProfile(profile: EmailProfile) {
  if (!window.confirm(t('settings.email_profile_delete_confirm', { name: profile.name }))) return
  deletingId.value = profile.id
  try {
    await settingsApi.deleteEmailProfile(profile.id, props.clientScoped)
    profiles.value = profiles.value.filter(row => row.id !== profile.id)
    toast.success(t('settings.email_profile_deleted'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    deletingId.value = null
  }
}

async function testProfile(profile: EmailProfile) {
  testingId.value = profile.id
  try {
    const result = await settingsApi.testEmailProfile(profile.id, props.clientScoped)
    toast.success(t('settings.email_profile_test_sent', { email: result.sent_to.join(', ') }))
    if (result.imap_append?.status === 'failed') {
      toast.error(imapAppendText(result.imap_append))
    } else if (result.imap_append?.status === 'saved') {
      toast.success(imapAppendText(result.imap_append))
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('settings.email_profile_test_failed'))
  } finally {
    testingId.value = null
  }
}

async function testDraftProfile() {
  if (!validateDraft()) {
    toast.error(t('settings.email_profile_validation_failed'))
    return
  }

  testingDraft.value = true
  draftTestFeedback.value = null
  try {
    const result = await settingsApi.testEmailProfileDraft(payload(), editingId.value, props.clientScoped)
    const recipients = result.sent_to.join(', ')
    draftTestFeedback.value = {
      status: 'success',
      title: t('settings.email_profile_test_accepted_title'),
      detail: t('settings.email_profile_test_accepted_detail', { email: recipients }),
      smtpResponse: smtpResponseText(result.smtp_response),
      imapStatus: imapAppendText(result.imap_append),
    }
    toast.success(t('settings.email_profile_test_sent', { email: recipients }))
  } catch (e: any) {
    const message = e?.response?.data?.error?.message || t('settings.email_profile_test_failed')
    draftTestFeedback.value = {
      status: 'error',
      title: t('settings.email_profile_test_failed_title'),
      detail: message,
    }
    toast.error(message)
  } finally {
    testingDraft.value = false
  }
}

async function browseImapFolders() {
  if (!validateImapSettings()) {
    toast.error(t('settings.email_profile_validation_failed'))
    return
  }

  browsingImapFolders.value = true
  imapFolderOptions.value = []
  try {
    const result = await settingsApi.browseEmailProfileImapFolders(imapBrowsePayload(), editingId.value, props.clientScoped)
    imapFolderOptions.value = result.folders ?? []
    if (imapFolderOptions.value.length > 0) {
      toast.success(t('settings.email_profile_imap_folders_loaded', { count: imapFolderOptions.value.length }))
    } else {
      toast.info(t('settings.email_profile_imap_folders_none'))
    }
  } catch (e: any) {
    toast.error(
      e?.response?.data?.message
      || e?.response?.data?.error?.message
      || t('settings.email_profile_imap_folders_failed'),
    )
  } finally {
    browsingImapFolders.value = false
  }
}

async function testImapSettings() {
  if (!validateImapSettings()) {
    toast.error(t('settings.email_profile_validation_failed'))
    return
  }

  testingImapSettings.value = true
  try {
    await settingsApi.testEmailProfileImapSettings(imapBrowsePayload(), editingId.value, props.clientScoped)
    toast.success(t('settings.email_profile_imap_test_ok', { folder: draft.imap_folder || 'Sent' }))
  } catch (e: any) {
    toast.error(
      e?.response?.data?.message
      || e?.response?.data?.error?.message
      || t('settings.email_profile_imap_test_failed'),
    )
  } finally {
    testingImapSettings.value = false
  }
}

function selectImapFolder(folder: string) {
  draft.imap_folder = folder
  imapFolderOptions.value = []
}

function imapFolderLabel(folder: EmailProfileImapFolder): string {
  const labels = []
  if (folder.sent) labels.push(t('settings.email_profile_imap_folder_sent_badge'))
  if (!folder.writable) labels.push(t('settings.email_profile_imap_folder_readonly_badge'))
  const suffix = labels.length > 0 ? ` (${labels.join(', ')})` : ''
  return `${folder.full_name || folder.path}${suffix}`
}

function smtpResponseText(value: string | null | undefined): string {
  const response = String(value || '').trim()
  return response !== '' ? response : t('settings.email_profile_test_no_smtp_response')
}

function imapAppendText(value: { status: 'skipped' | 'saved' | 'failed'; folder: string | null; error: string | null } | null | undefined): string {
  if (!value || value.status === 'skipped') {
    return t('settings.email_profile_test_imap_skipped')
  }
  if (value.status === 'saved') {
    return t('settings.email_profile_test_imap_saved', { folder: value.folder || 'Sent' })
  }
  return t('settings.email_profile_test_imap_failed', { error: value.error || t('common.error') })
}

function validateDraft(): boolean {
  validationScope.value = 'profile'
  const errors: string[] = []
  if (isBlank(draft.name)) errors.push(t('settings.email_profile_validation_name'))
  if (isBlank(draft.code)) errors.push(t('settings.email_profile_validation_code'))
  if (isBlank(draft.from_email)) errors.push(t('settings.email_profile_validation_from_email'))
  if (configureReplyTo.value && isBlank(draft.reply_to_email)) {
    errors.push(t('settings.email_profile_validation_reply_to_email'))
  }
  if (configureDkim.value && isBlank(draft.dkim_domain)) {
    errors.push(t('settings.email_profile_validation_dkim_domain'))
  }
  if (configureDkim.value && isBlank(draft.dkim_selector)) {
    errors.push(t('settings.email_profile_validation_dkim_selector'))
  }
  if (draft.transport_type === 'smtp' && isBlank(draft.smtp_host)) {
    errors.push(t('settings.email_profile_validation_smtp_host'))
  }
  if (draft.transport_type === 'smtp' && draft.smtp_auth_enabled && isBlank(draft.smtp_username)) {
    errors.push(t('settings.email_profile_validation_smtp_username'))
  }
  if (smtpPasswordRequired.value && isBlank(draft.smtp_password)) {
    errors.push(t(draft.smtp_auth_type === 'XOAUTH2'
      ? 'settings.email_profile_validation_smtp_access_token'
      : 'settings.email_profile_validation_smtp_password'))
  }
  if (configureImapSent.value && isBlank(draft.imap_host)) {
    errors.push(t('settings.email_profile_validation_imap_host'))
  }
  if (configureImapSent.value && isBlank(draft.imap_username)) {
    errors.push(t('settings.email_profile_validation_imap_username'))
  }
  if (imapPasswordRequired.value && isBlank(draft.imap_password)) {
    errors.push(t('settings.email_profile_validation_imap_password'))
  }
  if (configureImapSent.value && isBlank(draft.imap_folder)) {
    errors.push(t('settings.email_profile_validation_imap_folder'))
  }

  validationErrors.value = errors
  return errors.length === 0
}

function validateImapSettings(): boolean {
  validationScope.value = 'imap'
  const errors: string[] = []
  if (isBlank(draft.imap_host)) {
    errors.push(t('settings.email_profile_validation_imap_host'))
  }
  if (isBlank(draft.imap_username)) {
    errors.push(t('settings.email_profile_validation_imap_username'))
  }
  if (imapPasswordRequired.value && isBlank(draft.imap_password)) {
    errors.push(t('settings.email_profile_validation_imap_password'))
  }
  if (isBlank(draft.imap_folder)) {
    errors.push(t('settings.email_profile_validation_imap_folder'))
  }

  validationErrors.value = errors
  return errors.length === 0
}

function isBlank(value: unknown): boolean {
  return String(value ?? '').trim() === ''
}

function signingProfileLabel(profile: EmailProfile): string {
  if (profile.signing_profile_id === null) return t('settings.email_profile_signing_none')
  return profile.signing_profile_name
    ? `${profile.signing_profile_name} (${profile.signing_profile_code || profile.signing_profile_id})`
    : `#${profile.signing_profile_id}`
}

function transportLabel(profile: EmailProfile): string {
  if (profile.transport_type === 'smtp') {
    return profile.smtp_host
      ? `${profile.smtp_host}:${profile.smtp_port || 587}`
      : t('settings.email_profile_transport_smtp')
  }
  if (profile.transport_type === 'sendmail') {
    return profile.sendmail_command || t('settings.email_profile_transport_sendmail')
  }
  return t('settings.email_profile_transport_global')
}

function normalizeEmail(value: string | null | undefined): string {
  return String(value || '').trim().toLowerCase()
}

function isExpired(value: string | null | undefined): boolean {
  if (!value) return false
  const timestamp = Date.parse(value)
  return Number.isFinite(timestamp) && timestamp < Date.now()
}

function certificateCommonName(subject: string | null | undefined): string | null {
  const match = String(subject || '').match(/(?:^|,)CN=([^,]+)/i)
  const cn = match?.[1]?.trim() || ''
  return cn !== '' ? cn : null
}
</script>

<template>
  <div class="space-y-6">
    <section class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 class="text-2xl font-semibold">{{ t('settings.email_profiles_title') }}</h1>
          <p class="text-sm text-neutral-500 mt-1">{{ t('settings.email_profiles_hint') }}</p>
        </div>
        <button type="button" @click="newProfile" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('settings.email_profiles_new') }}
        </button>
      </div>
      <p class="mt-4 rounded-md border border-neutral-200 bg-neutral-50 px-3 py-2 text-xs text-neutral-600">
        {{ t('settings.email_profiles_global_fallback') }}
      </p>
    </section>

    <section v-if="showForm" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
      <h2 class="text-sm font-medium text-neutral-800">
        {{ editingId === null ? t('settings.email_profile_create') : t('settings.email_profile_update') }}
      </h2>
      <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
        <label class="block text-xs font-medium text-neutral-600">
          {{ t('settings.email_profile_name') }}
          <span class="ml-0.5 text-danger-600" :title="t('common.required')" aria-hidden="true">*</span>
          <span class="sr-only">{{ t('common.required') }}</span>
          <input v-model="draft.name" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
        </label>
        <label class="block text-xs font-medium text-neutral-600">
          {{ t('settings.email_profile_code') }}
          <span class="ml-0.5 text-danger-600" :title="t('common.required')" aria-hidden="true">*</span>
          <span class="sr-only">{{ t('common.required') }}</span>
          <input v-model="draft.code" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
        </label>
        <label class="block text-xs font-medium text-neutral-600">
          {{ t('settings.email_profile_from_email') }}
          <span class="ml-0.5 text-danger-600" :title="t('common.required')" aria-hidden="true">*</span>
          <span class="sr-only">{{ t('common.required') }}</span>
          <input v-model="draft.from_email" type="email" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
        </label>
        <label class="block text-xs font-medium text-neutral-600">
          {{ t('settings.email_profile_from_name') }}
          <input v-model="draft.from_name" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
        </label>
        <label v-if="!props.clientScoped" class="block text-xs font-medium text-neutral-600">
          {{ t('settings.email_profile_signing_profile') }}
          <select v-model="draft.signing_profile_id" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
            <option :value="null">{{ t('settings.email_profile_signing_none') }}</option>
            <option v-for="profile in emailSigningProfiles" :key="profile.id" :value="profile.id">
              {{ profile.name }} ({{ profile.code }})
            </option>
          </select>
        </label>
        <div v-if="selectedSigningProfile" class="md:col-span-2 rounded-md border px-3 py-2 text-xs" :class="smimeIdentityClass">
          <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <div class="font-medium">{{ smimeIdentityState?.text }}</div>
              <div v-if="selectedSigningProfile.certificate_subject" class="mt-0.5 text-[11px] opacity-80">
                {{ selectedSigningProfile.certificate_subject }}
              </div>
            </div>
            <button v-if="selectedSigningProfile.certificate_email" type="button" @click="prefillFromSmime"
              class="cursor-pointer self-start rounded-md border border-current px-2 py-1 text-xs font-medium hover:bg-surface sm:self-center">
              {{ t('settings.email_profile_smime_prefill_from') }}
            </button>
          </div>
        </div>
        <div class="md:col-span-2">
          <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
            <input v-model="configureReplyTo" type="checkbox" class="h-4 w-4 accent-primary-600" />
            {{ t('settings.email_profile_configure_reply_to') }}
          </label>
          <div v-if="configureReplyTo" class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <label class="block text-xs font-medium text-neutral-600">
              {{ t('settings.email_profile_reply_to_email') }}
              <span class="ml-0.5 text-danger-600" :title="t('common.required')" aria-hidden="true">*</span>
              <span class="sr-only">{{ t('common.required') }}</span>
              <input v-model="draft.reply_to_email" type="email" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
            </label>
            <label class="block text-xs font-medium text-neutral-600">
              {{ t('settings.email_profile_reply_to_name') }}
              <input v-model="draft.reply_to_name" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
            </label>
          </div>
        </div>
        <div class="md:col-span-2">
          <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
            <input v-model="configureDkim" type="checkbox" class="h-4 w-4 accent-primary-600" />
            {{ t('settings.email_profile_configure_dkim') }}
          </label>
          <div v-if="configureDkim" class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <label class="block text-xs font-medium text-neutral-600">
              {{ t('settings.email_profile_dkim_domain') }}
              <span class="ml-0.5 text-danger-600" :title="t('common.required')" aria-hidden="true">*</span>
              <span class="sr-only">{{ t('common.required') }}</span>
              <input v-model="draft.dkim_domain" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
            </label>
            <label class="block text-xs font-medium text-neutral-600">
              {{ t('settings.email_profile_dkim_selector') }}
              <span class="ml-0.5 text-danger-600" :title="t('common.required')" aria-hidden="true">*</span>
              <span class="sr-only">{{ t('common.required') }}</span>
              <input v-model="draft.dkim_selector" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
            </label>
          </div>
        </div>
        <div class="md:col-span-2">
          <label class="block text-xs font-medium text-neutral-600">
            {{ t('settings.email_profile_transport') }}
            <select
              v-model="draft.transport_type"
              :disabled="transportLocked"
              :title="transportLockedHint"
              class="mt-1 block w-full max-w-md rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm disabled:bg-neutral-50 disabled:text-neutral-400"
            >
              <!--
                Volby zůstávají i v zamčeném stavu: profil zděděný ze samostatné
                instalace může mít vlastní transport pořád nastavený a select bez
                odpovídající položky by ukázal prázdno místo skutečné hodnoty.
              -->
              <option value="global">{{ t('settings.email_profile_transport_global') }}</option>
              <option value="smtp">{{ t('settings.email_profile_transport_smtp') }}</option>
              <option v-if="!props.clientScoped" value="sendmail">{{ t('settings.email_profile_transport_sendmail') }}</option>
            </select>
          </label>
          <p v-if="transportLocked" class="mt-1.5 flex gap-1.5 text-xs text-neutral-500">
            <svg class="mt-0.5 h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" /></svg>
            <span>{{ t('settings.email_profile_transport_managed') }}</span>
          </p>
          <div v-if="draft.transport_type === 'smtp'" class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2">
            <label class="block text-xs font-medium text-neutral-600">
              {{ t('settings.email_profile_smtp_host') }}
              <span class="ml-0.5 text-danger-600" :title="t('common.required')" aria-hidden="true">*</span>
              <span class="sr-only">{{ t('common.required') }}</span>
              <input v-model="draft.smtp_host" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
            </label>
            <label class="block text-xs font-medium text-neutral-600">
              {{ t('settings.email_profile_smtp_port') }}
              <input v-model.number="draft.smtp_port" type="number" min="1" max="65535" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
            </label>
            <label class="block text-xs font-medium text-neutral-600">
              {{ t('settings.email_profile_smtp_encryption') }}
              <select v-model="draft.smtp_encryption" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
                <option value="tls">{{ t('settings.email_profile_smtp_encryption_tls') }}</option>
                <option value="ssl">{{ t('settings.email_profile_smtp_encryption_ssl') }}</option>
                <option value="none">{{ t('settings.email_profile_smtp_encryption_none') }}</option>
              </select>
            </label>
            <label class="block text-xs font-medium text-neutral-600">
              {{ t('settings.email_profile_smtp_timeout') }}
              <input v-model.number="draft.smtp_timeout" type="number" min="1" max="300" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
            </label>
            <div class="grid grid-cols-1 gap-3 md:col-span-2 md:grid-cols-2">
              <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
                <input v-model="draft.smtp_verify_peer" type="checkbox" class="h-4 w-4 accent-primary-600" />
                {{ t('settings.email_profile_smtp_verify_peer') }}
              </label>
              <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
                <input v-model="draft.smtp_verify_peer_name" type="checkbox" class="h-4 w-4 accent-primary-600" />
                {{ t('settings.email_profile_smtp_verify_peer_name') }}
              </label>
              <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
                <input v-model="draft.smtp_allow_self_signed" type="checkbox" class="h-4 w-4 accent-primary-600" />
                {{ t('settings.email_profile_smtp_allow_self_signed') }}
              </label>
              <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
                <input v-model="draft.smtp_keepalive" type="checkbox" class="h-4 w-4 accent-primary-600" />
                {{ t('settings.email_profile_smtp_keepalive') }}
              </label>
            </div>
            <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
              <input v-model="draft.smtp_auth_enabled" type="checkbox" class="h-4 w-4 accent-primary-600" />
              {{ t('settings.email_profile_smtp_auth_enabled') }}
            </label>
            <div v-if="draft.smtp_auth_enabled" class="grid grid-cols-1 gap-4 md:col-span-2 md:grid-cols-2">
              <label class="block text-xs font-medium text-neutral-600 md:col-span-2">
                {{ t('settings.email_profile_smtp_auth_type') }}
                <select v-model="draft.smtp_auth_type" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
                  <option value="PLAIN">{{ t('settings.email_profile_smtp_auth_plain') }}</option>
                  <option value="LOGIN">{{ t('settings.email_profile_smtp_auth_login') }}</option>
                  <option value="CRAM-MD5">{{ t('settings.email_profile_smtp_auth_cram_md5') }}</option>
                  <option value="XOAUTH2">{{ t('settings.email_profile_smtp_auth_xoauth2') }}</option>
                </select>
              </label>
              <label class="block text-xs font-medium text-neutral-600">
                {{ t('settings.email_profile_smtp_username') }}
                <span class="ml-0.5 text-danger-600" :title="t('common.required')" aria-hidden="true">*</span>
                <span class="sr-only">{{ t('common.required') }}</span>
                <input v-model="draft.smtp_username" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
              </label>
              <label class="block text-xs font-medium text-neutral-600">
                {{ draft.smtp_auth_type === 'XOAUTH2' ? t('settings.email_profile_smtp_access_token') : t('settings.email_profile_smtp_password') }}
                <span v-if="smtpPasswordRequired" class="ml-0.5 text-danger-600" :title="t('common.required')" aria-hidden="true">*</span>
                <span v-if="smtpPasswordRequired" class="sr-only">{{ t('common.required') }}</span>
                <input v-model="draft.smtp_password" type="password" autocomplete="new-password" :required="smtpPasswordRequired"
                  :placeholder="editingId === null ? '' : t('settings.email_profile_smtp_password_keep')"
                  class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
              </label>
            </div>
          </div>
          <div v-else-if="draft.transport_type === 'sendmail'" class="mt-3">
            <label class="block text-xs font-medium text-neutral-600">
              {{ t('settings.email_profile_sendmail_command') }}
              <input v-model="draft.sendmail_command" :placeholder="t('settings.email_profile_sendmail_command_ph')"
                class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm font-mono" />
            </label>
          </div>
        </div>
        <div class="md:col-span-2">
          <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
            <input v-model="configureImapSent" type="checkbox" class="h-4 w-4 accent-primary-600" />
            {{ t('settings.email_profile_configure_imap_sent') }}
          </label>
          <div v-if="configureImapSent" class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2">
            <label class="block text-xs font-medium text-neutral-600">
              {{ t('settings.email_profile_imap_host') }}
              <span class="ml-0.5 text-danger-600" :title="t('common.required')" aria-hidden="true">*</span>
              <span class="sr-only">{{ t('common.required') }}</span>
              <input v-model="draft.imap_host" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
            </label>
            <label class="block text-xs font-medium text-neutral-600">
              {{ t('settings.email_profile_imap_port') }}
              <input v-model.number="draft.imap_port" type="number" min="1" max="65535" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
            </label>
            <label class="block text-xs font-medium text-neutral-600">
              {{ t('settings.email_profile_imap_encryption') }}
              <select v-model="draft.imap_encryption" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
                <option value="ssl">{{ t('settings.email_profile_smtp_encryption_ssl') }}</option>
                <option value="tls">{{ t('settings.email_profile_smtp_encryption_tls') }}</option>
                <option value="none">{{ t('settings.email_profile_smtp_encryption_none') }}</option>
              </select>
            </label>
            <div class="block text-xs font-medium text-neutral-600">
              <label :for="`${pageId}-email-profile-imap-folder`">
                {{ t('settings.email_profile_imap_folder') }}
                <span class="ml-0.5 text-danger-600" :title="t('common.required')" aria-hidden="true">*</span>
                <span class="sr-only">{{ t('common.required') }}</span>
              </label>
              <div class="mt-1 flex flex-col gap-2 sm:flex-row">
                <input :id="`${pageId}-email-profile-imap-folder`" v-model="draft.imap_folder" required class="min-w-0 flex-1 rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
                <button type="button" @click="browseImapFolders" :disabled="browsingImapFolders || testingImapSettings"
                  :class="[btnOutline('neutral'), 'shrink-0']">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.inbox" /></svg>
                  {{ browsingImapFolders ? t('settings.email_profile_imap_browsing') : t('settings.email_profile_imap_browse') }}
                </button>
                <button type="button" @click="testImapSettings" :disabled="testingImapSettings || browsingImapFolders"
                  :class="[btnOutline('neutral'), 'shrink-0']">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.play" /></svg>
                  {{ testingImapSettings ? t('settings.email_profile_imap_testing') : t('settings.email_profile_imap_test') }}
                </button>
              </div>
              <select v-if="imapFolderOptions.length > 0" :value="draft.imap_folder" @change="selectImapFolder(($event.target as HTMLSelectElement).value)"
                class="mt-2 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
                <option v-for="folder in imapFolderOptions" :key="folder.path" :value="folder.path" :disabled="!folder.writable">
                  {{ imapFolderLabel(folder) }}
                </option>
              </select>
            </div>
            <div class="grid grid-cols-1 gap-4 md:col-span-2 md:grid-cols-2">
              <label class="block text-xs font-medium text-neutral-600">
                {{ t('settings.email_profile_imap_username') }}
                <span class="ml-0.5 text-danger-600" :title="t('common.required')" aria-hidden="true">*</span>
                <span class="sr-only">{{ t('common.required') }}</span>
                <input v-model="draft.imap_username" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
              </label>
              <label class="block text-xs font-medium text-neutral-600">
                {{ t('settings.email_profile_imap_password') }}
                <span v-if="imapPasswordRequired" class="ml-0.5 text-danger-600" :title="t('common.required')" aria-hidden="true">*</span>
                <span v-if="imapPasswordRequired" class="sr-only">{{ t('common.required') }}</span>
                <input v-model="draft.imap_password" type="password" autocomplete="new-password" :required="imapPasswordRequired"
                  :placeholder="editingId === null ? '' : t('settings.email_profile_smtp_password_keep')"
                  class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
              </label>
            </div>
            <label class="block text-xs font-medium text-neutral-600">
              {{ t('settings.email_profile_imap_timeout') }}
              <input v-model.number="draft.imap_timeout" type="number" min="1" max="300" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
            </label>
            <label class="block text-xs font-medium text-neutral-600">
              {{ t('settings.email_profile_imap_on_failure') }}
              <select v-model="draft.imap_on_failure" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
                <option value="log_only">{{ t('settings.email_profile_imap_on_failure_log_only') }}</option>
                <option value="fail_send">{{ t('settings.email_profile_imap_on_failure_fail_send') }}</option>
              </select>
            </label>
            <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
              <input v-model="draft.imap_validate_cert" type="checkbox" class="h-4 w-4 accent-primary-600" />
              {{ t('settings.email_profile_imap_validate_cert') }}
            </label>
            <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
              <input v-model="draft.imap_create_folder" type="checkbox" class="h-4 w-4 accent-primary-600" />
              {{ t('settings.email_profile_imap_create_folder') }}
            </label>
            <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
              <input v-model="draft.imap_mark_seen" type="checkbox" class="h-4 w-4 accent-primary-600" />
              {{ t('settings.email_profile_imap_mark_seen') }}
            </label>
          </div>
        </div>
        <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="draft.is_default" type="checkbox" class="h-4 w-4 accent-primary-600" />
          {{ t('settings.email_profile_default') }}
        </label>
        <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="draft.is_active" type="checkbox" class="h-4 w-4 accent-primary-600" />
          {{ t('common.active') }}
        </label>
      </div>
      <div class="mt-5 flex flex-wrap items-center gap-3">
        <button type="button" @click="testDraftProfile" :disabled="testingDraft || saving"
          :class="btnOutline('neutral')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.send" /></svg>
          {{ testingDraft ? t('common.loading') : t('settings.email_profile_test_draft') }}
        </button>
        <button type="button" @click="saveProfile" :disabled="saving"
          :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ saving ? t('common.loading') : t('common.save') }}
        </button>
        <button type="button" @click="showForm = false; resetDraft()"
          :class="btnOutline('neutral')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
      </div>
      <div v-if="validationErrors.length > 0" class="mt-4 rounded-md border border-danger-200 bg-danger-50 px-3 py-2 text-sm text-danger-800">
        <div class="font-medium">{{ t('settings.email_profile_validation_title') }}</div>
        <ul class="mt-1 list-disc space-y-0.5 pl-5">
          <li v-for="error in validationErrors" :key="error">{{ error }}</li>
        </ul>
      </div>
      <div v-if="draftTestFeedback" class="mt-4 rounded-md border px-3 py-2 text-sm"
        :class="draftTestFeedback.status === 'success'
          ? 'border-success-200 bg-success-50 text-success-800'
          : 'border-danger-200 bg-danger-50 text-danger-800'">
        <div class="font-medium">{{ draftTestFeedback.title }}</div>
        <div class="mt-1">{{ draftTestFeedback.detail }}</div>
        <div v-if="draftTestFeedback.smtpResponse" class="mt-2">
          <div class="text-xs font-medium uppercase text-current/70">{{ t('settings.email_profile_test_smtp_response') }}</div>
          <div class="mt-1 whitespace-pre-wrap break-words rounded border border-current/20 bg-surface/70 px-2 py-1 font-mono text-xs">
            {{ draftTestFeedback.smtpResponse }}
          </div>
        </div>
        <div v-if="draftTestFeedback.imapStatus" class="mt-2">
          <div class="text-xs font-medium uppercase text-current/70">{{ t('settings.email_profile_test_imap_status') }}</div>
          <div class="mt-1 whitespace-pre-wrap break-words rounded border border-current/20 bg-surface/70 px-2 py-1 text-xs">
            {{ draftTestFeedback.imapStatus }}
          </div>
        </div>
      </div>
    </section>

    <section class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
      <div v-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>
      <EmptyState v-else-if="profiles.length === 0" dense icon="send" :title="t('settings.email_profiles_empty')" />
      <div v-else class="overflow-x-auto border-y border-neutral-100">
        <table class="w-full text-xs">
          <thead class="bg-neutral-50 text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('settings.email_profile_name') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('settings.email_profile_from') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('settings.email_profile_reply_to') }}</th>
              <th v-if="!props.clientScoped" class="px-3 py-2 text-left font-medium">{{ t('settings.email_profile_signing_profile') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('settings.email_profile_dkim') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('settings.email_profile_transport') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('settings.email_profile_imap_sent') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('settings.email_profile_status') }}</th>
              <th class="px-3 py-2 text-right font-medium"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="profile in profiles" :key="profile.id">
              <td class="px-3 py-2">
                <div class="font-medium text-neutral-800">{{ profile.name }}</div>
                <div class="text-neutral-500">{{ profile.code }}</div>
              </td>
              <td class="px-3 py-2">
                <div>{{ profile.from_email }}</div>
                <div v-if="profile.from_name" class="text-neutral-500">{{ profile.from_name }}</div>
              </td>
              <td class="px-3 py-2">
                <template v-if="profile.reply_to_enabled && profile.reply_to_email">
                  <div>{{ profile.reply_to_email }}</div>
                  <div v-if="profile.reply_to_name" class="text-neutral-500">{{ profile.reply_to_name }}</div>
                </template>
                <span v-else class="text-neutral-400">{{ t('settings.email_profile_reply_to_disabled') }}</span>
              </td>
              <td v-if="!props.clientScoped" class="px-3 py-2">{{ signingProfileLabel(profile) }}</td>
              <td class="px-3 py-2">
                <template v-if="profile.dkim_enabled">
                  <div>{{ profile.dkim_domain }}</div>
                  <div class="text-neutral-500">{{ profile.dkim_selector }}</div>
                </template>
                <span v-else class="text-neutral-400">{{ t('settings.email_profile_dkim_disabled') }}</span>
              </td>
              <td class="px-3 py-2">
                <div>{{ transportLabel(profile) }}</div>
                <div v-if="profile.transport_type === 'smtp' && profile.smtp_auth_enabled" class="text-neutral-500">
                  {{ t('settings.email_profile_smtp_auth_enabled') }} · {{ profile.smtp_auth_type }}
                </div>
              </td>
              <td class="px-3 py-2">
                <template v-if="profile.imap_sent_enabled">
                  <div>{{ profile.imap_folder || 'Sent' }}</div>
                  <div class="text-neutral-500">{{ profile.imap_host }}:{{ profile.imap_port || 993 }}</div>
                </template>
                <span v-else class="text-neutral-400">{{ t('settings.email_profile_imap_sent_disabled') }}</span>
              </td>
              <td class="px-3 py-2">
                <div class="flex flex-wrap gap-1">
                  <span v-if="profile.is_default" class="rounded border border-primary-200 bg-primary-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-primary-700">
                    {{ t('settings.email_profile_default_badge') }}
                  </span>
                  <span class="rounded border px-1.5 py-0.5 text-[10px] font-semibold uppercase"
                    :class="profile.is_active ? 'border-success-200 bg-success-50 text-success-700' : 'border-neutral-200 bg-neutral-50 text-neutral-500'">
                    {{ profile.is_active ? t('common.active') : t('common.inactive') }}
                  </span>
                </div>
              </td>
              <td class="px-3 py-2 text-right whitespace-nowrap">
                <template v-if="profile.client_manageable !== false">
                  <button type="button" @click="testProfile(profile)" :disabled="testingId === profile.id"
                    class="cursor-pointer text-neutral-600 hover:text-neutral-800 disabled:opacity-50">
                    {{ testingId === profile.id ? t('common.loading') : t('settings.email_profile_test') }}
                  </button>
                  <button type="button" @click="editProfile(profile)" class="cursor-pointer ml-3 text-primary-600 hover:text-primary-700">
                    {{ t('common.edit') }}
                  </button>
                  <button type="button" @click="deleteProfile(profile)" :disabled="deletingId === profile.id"
                    class="cursor-pointer ml-3 text-danger-600 hover:text-danger-700 disabled:opacity-50">
                    {{ deletingId === profile.id ? t('common.loading') : t('common.delete') }}
                  </button>
                </template>
                <span v-else class="text-neutral-500" :title="t('settings.email_profile_managed_by_staff_hint')">
                  {{ t('settings.email_profile_managed_by_staff') }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
