<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  settingsApi,
  type CertificateVaultItem,
  type PdfSignatureOutputSetting,
  type PdfSignatureTestResult,
  type PdfSignatureUserDefault,
  type SigningCredentialPassphrasePolicy,
  type SigningProfile,
  type SigningProfileCredentialMeta,
  type SigningProfilePayload,
  type SigningProfileUsage,
  type SigningSettings,
} from '@/api/settings'
import type { EpoSigningCredential } from '@/api/epoSubmissions'
import { apiErrorMessage } from '@/api/errors'
import { authApi } from '@/api/auth'
import { getCredential, isWebAuthnAvailable } from '@/security/webauthn'
import { useToast } from '@/composables/useToast'
import { useAutoSlug } from '@/composables/useAutoSlug'
import { useAuthStore } from '@/stores/auth'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()

const loading = ref(true)
const signingSettings = ref<SigningSettings | null>(null)
const signingProfiles = ref<SigningProfile[]>([])
const signingProfilesLoading = ref(false)
const signingSettingsSaving = ref(false)
const signingProfileSaving = ref(false)
const pdfOutputSettings = ref<PdfSignatureOutputSetting[]>([])
const outputTypes = ref<string[]>([])
const userDefaults = ref<PdfSignatureUserDefault[]>([])
const userDefaultSaving = ref<string | null>(null)
const pdfOutputSettingsSaving = ref(false)
const pdfOutputSettingBaseline = ref<Record<string, string>>({})
const pdfOutputSettingsTesting = ref<string | null>(null)
const signingProfileCredential = ref<SigningProfileCredentialMeta | null>(null)
const signingProfileCredentialLoading = ref(false)
const signingProfileCertFileInput = ref<HTMLInputElement | null>(null)
const signingProfileCertFile = ref<File | null>(null)
const signingProfileCertPassword = ref('')
const signingProfileCertPolicy = ref<SigningCredentialPassphrasePolicy>('encrypted_store')
const signingProfileCertPassphraseProfileId = ref('')
const signingProfileCertUploading = ref(false)
const personalCertificates = ref<EpoSigningCredential[]>([])
const signingProfileCredentialSource = ref<'personal_vault' | 'uploaded_file'>('uploaded_file')
const signingProfileVaultCredentialId = ref<number | null>(null)
const signingProfileVaultPassword = ref('')
const signingProfileVaultTotp = ref('')
const showSigningProfileForm = ref(false)
const editingSigningProfile = ref<number | null>(null)
const signingProfileTsaEnabled = ref(false)
const signingProfileOwnerMode = ref<'supplier' | 'current_user' | 'other_user'>('supplier')
type EmailSmimeIdentityPolicy = 'strict_match' | 'warning_only' | 'same_domain_override' | 'allowlist_override'
const emailSmimeIdentityPolicies: EmailSmimeIdentityPolicy[] = [
  'strict_match',
  'warning_only',
  'same_domain_override',
  'allowlist_override',
]

const signingProfileDraft = reactive<SigningProfilePayload & { owner_user_id: number | null; is_active: boolean }>({
  owner_user_id: null,
  name: '',
  code: '',
  allowed_usages: ['pdf'],
  default_backend: 'native',
  pdf_tsa_url: null,
  pdf_tsa_username: null,
  pdf_tsa_password: '',
  pdf_reason: null,
  is_active: true,
})
const signingProfileSlug = useAutoSlug(
  slug => { signingProfileDraft.code = slug },
  { maxLen: 80 },
)
const isAdmin = computed(() => auth.isCompanyAdminRole)
const isAccountant = computed(() => auth.canRead('settings.signing') && !auth.isCompanyAdminRole)
const accountantProfilesEnabled = computed(() => signingSettings.value?.accountant_profiles_enabled === true)
const canManageSigningProfiles = computed(() => isAdmin.value || (isAccountant.value && accountantProfilesEnabled.value))
const canUseUserDefaults = computed(() => isAdmin.value || (isAccountant.value && accountantProfilesEnabled.value))
const contentVisible = computed(() => isAdmin.value || accountantProfilesEnabled.value)
const signingProfileCredentialUsesUnsupportedPrompt = computed(() =>
  signingProfileCredential.value?.passphrase_policy === 'prompt_on_use',
)
const currentUserPersonalProfile = computed(() =>
  signingProfileOwnerMode.value === 'current_user'
  || signingProfileDraft.owner_user_id === (auth.user?.id ?? null),
)
const usablePersonalCertificates = computed(() =>
  personalCertificates.value.filter(item => item.enabled_for_supplier && item.valid_now),
)
/**
 * Trezor umí backend napojit jen na vlastní osobní profil. U firemního profilu
 * proto platí starší cesta se souborem, ať uživatel nevyplní formulář, který se
 * pak tiše neuloží.
 */
const effectiveCredentialSource = computed<'personal_vault' | 'uploaded_file'>(() =>
  currentUserPersonalProfile.value ? signingProfileCredentialSource.value : 'uploaded_file',
)

/**
 * Trezor certifikátů — jediné místo v aplikaci, kam se PFX/P12 nahrává.
 * Podpisové profily (PDF, S/MIME), EPO i mzdová podání si odsud certifikát jen
 * půjčují; nahrávat ho na třech místech byla historická daň za to, že se každý
 * konzument narodil zvlášť.
 */
const certificates = ref<CertificateVaultItem[]>([])
const certificatesLoading = ref(false)
const certificatesError = ref('')
const certificateBusy = ref(false)
const certificateFileInput = ref<HTMLInputElement | null>(null)
const certificateFile = ref<File | null>(null)
const certificateLabel = ref('')
const certificatePassword = ref('')
const certificateStepPassword = ref('')
const certificateStepTotp = ref('')
const certificateStepPasskeyToken = ref('')
const certificatePasskeyBusy = ref(false)
const passkeySupported = isWebAuthnAvailable()

const hasPasskey = computed(() =>
  auth.user?.mfa_methods?.includes('passkey') === true
  || (auth.user?.passkey_count ?? 0) > 0,
)
const hasTotp = computed(() => auth.user?.totp_enabled === true)

/** Prázdný řetězec = ověření je kompletní; jinak text, co ještě chybí. */
const certificateStepUpMissing = computed(() => {
  if (certificateStepPasskeyToken.value) return ''
  if (!certificateStepPassword.value) return t('settings.certificate_vault_step_up_password_missing')
  if (hasTotp.value && !/^\d{6}$/.test(certificateStepTotp.value.trim())) {
    return t('settings.certificate_vault_step_up_totp_missing')
  }
  return ''
})

const canUploadCertificate = computed(() =>
  !certificateBusy.value
  && certificateFile.value !== null
  && certificatePassword.value !== ''
  && certificateStepUpMissing.value === '',
)

async function load() {
  loading.value = true
  try {
    await Promise.all([loadSigningProfiles(true), loadCertificates()])
  } finally {
    loading.value = false
  }
}

onMounted(load)

function signingProfileUsageLabel(usage: SigningProfileUsage): string {
  return t(`settings.signing_profile_usage_${usage}`)
}

function signingProfileOwnerLabel(profile: SigningProfile): string {
  if (profile.owner_user_id === null) {
    return t('settings.signing_profile_owner_supplier')
  }
  if (profile.owner_user_id === (auth.user?.id ?? null)) {
    return t('settings.signing_profile_owner_current_user')
  }

  return t('settings.signing_profile_owner_user', { id: profile.owner_user_id })
}

async function loadSigningProfiles(silent = true) {
  signingProfilesLoading.value = true
  try {
    const settings = await settingsApi.getSigningSettings()
    signingSettings.value = settings

    if (!isAdmin.value && !settings.accountant_profiles_enabled) {
      signingProfiles.value = []
      pdfOutputSettings.value = []
      pdfOutputSettingBaseline.value = {}
      outputTypes.value = []
      userDefaults.value = []
      return
    }

    if (isAdmin.value) {
      const [profiles, pdfSettings, defaults] = await Promise.all([
        settingsApi.listSigningProfiles(),
        settingsApi.getPdfSigningSettings(),
        settingsApi.getPdfSigningUserDefaults(),
      ])
      signingProfiles.value = profiles
      replacePdfOutputSettings(pdfSettings.output_settings)
      outputTypes.value = defaults.output_types
      userDefaults.value = defaults.user_defaults
      return
    }

    const [profiles, defaults] = await Promise.all([
      settingsApi.listSigningProfiles(),
      settingsApi.getPdfSigningUserDefaults(),
    ])
    signingProfiles.value = profiles
    replacePdfOutputSettings(defaults.output_settings)
    outputTypes.value = defaults.output_types
    userDefaults.value = defaults.user_defaults
  } catch (e: any) {
    if (!silent) toast.error(e?.response?.data?.error?.message || t('settings.signing_profiles_load_failed'))
  } finally {
    signingProfilesLoading.value = false
  }
}

async function saveSigningProfileSettings() {
  if (!signingSettings.value) return
  signingSettingsSaving.value = true
  try {
    signingSettings.value = await settingsApi.updateSigningSettings({
      accountant_profiles_enabled: signingSettings.value.accountant_profiles_enabled,
    })
    toast.success(t('settings.signing_profiles_settings_saved'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
    await loadSigningProfiles(true)
  } finally {
    signingSettingsSaving.value = false
  }
}

function outputSettingFor(outputType: string): PdfSignatureOutputSetting | null {
  return pdfOutputSettings.value.find((setting) => setting.output_type === outputType) ?? null
}

function userDefaultProfileId(outputType: string): number | null {
  const usage = signingOutputUsage(outputType)
  return userDefaults.value.find((item) => item.output_type === outputType && item.usage === usage)?.profile_id ?? null
}

function signingOutputUsage(outputType: string): SigningProfileUsage {
  return outputType.includes('email') ? 'email_smime' : 'pdf'
}

function defaultBackendForOutput(outputType: string): string {
  return signingOutputUsage(outputType) === 'email_smime' ? 'smime' : 'native'
}

function adminSigningProfilesForOutput(outputType: string): SigningProfile[] {
  const usage = signingOutputUsage(outputType)
  return signingProfiles.value.filter(
    profile => profile.owner_user_id === null && profile.is_active && profile.allowed_usages.includes(usage),
  )
}

function activeUserSigningProfilesForOutput(outputType: string): SigningProfile[] {
  const usage = signingOutputUsage(outputType)
  return signingProfiles.value.filter(
    profile => profile.owner_user_id === (auth.user?.id ?? null) && profile.is_active && profile.allowed_usages.includes(usage),
  )
}

function userDefaultProfile(outputType: string): SigningProfile | null {
  const profileId = userDefaultProfileId(outputType)
  return profileId !== null ? (signingProfiles.value.find(profile => profile.id === profileId) ?? null) : null
}

function userProfileMappingWarning(outputType: string): string {
  const setting = outputSettingFor(outputType)
  if (!setting || !setting.enabled) {
    return t('profile_signing.output_mapping_disabled')
  }
  if (setting.selection_source !== 'logged_in_user') {
    return t('profile_signing.output_mapping_not_user')
  }
  const profile = userDefaultProfile(outputType)
  const usage = signingOutputUsage(outputType)
  if (profile !== null && !profile.allowed_usages.includes(usage)) {
    return t('profile_signing.output_usage_mismatch', {
      outputUsage: signingProfileUsageLabel(usage),
      profileUsages: profile.allowed_usages.map(signingProfileUsageLabel).join(', '),
    })
  }

  return ''
}

function userProfileMappingStatus(outputType: string): string {
  const warning = userProfileMappingWarning(outputType)
  if (warning !== '') return warning
  return userDefaultProfileId(outputType) === null
    ? t('profile_signing.default_profile_none')
    : t('profile_signing.default_profile_active')
}

async function saveUserDefault(outputType: string, rawProfileId: string) {
  const profileId = rawProfileId !== '' ? Number(rawProfileId) : null
  userDefaultSaving.value = outputType
  try {
    const saved = await settingsApi.updatePdfSigningUserDefault(outputType, profileId)
    const usage = signingOutputUsage(outputType)
    userDefaults.value = userDefaults.value.filter((item) => !(item.output_type === outputType && item.usage === usage))
    if (saved !== null) {
      userDefaults.value.push(saved)
    }
    toast.success(t('profile_signing.default_profile_saved'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    userDefaultSaving.value = null
  }
}

function resetSigningProfileDraft() {
  editingSigningProfile.value = null
  signingProfileTsaEnabled.value = false
  signingProfileOwnerMode.value = isAdmin.value ? 'supplier' : 'current_user'
  signingProfileCredential.value = null
  signingProfileCertFile.value = null
  signingProfileCertPassword.value = ''
  signingProfileCertPolicy.value = 'encrypted_store'
  signingProfileCertPassphraseProfileId.value = ''
  signingProfileCredentialSource.value = 'personal_vault'
  signingProfileVaultCredentialId.value = null
  signingProfileVaultPassword.value = ''
  signingProfileVaultTotp.value = ''
  if (signingProfileCertFileInput.value) signingProfileCertFileInput.value.value = ''
  Object.assign(signingProfileDraft, {
    owner_user_id: null,
    name: '',
    code: '',
    allowed_usages: ['pdf'],
    default_backend: 'native',
    pdf_tsa_url: null,
    pdf_tsa_username: null,
    pdf_tsa_password: '',
    pdf_reason: null,
    is_active: true,
  })
  signingProfileSlug.init('', false)
}

async function loadSigningProfileCredential(profileId: number, silent = true) {
  signingProfileCredentialLoading.value = true
  try {
    const meta = await settingsApi.getSigningProfileCredential(profileId)
    await loadPersonalCertificates()
    signingProfileCredential.value = meta
    signingProfileCredentialSource.value = meta.certificate_source
      || (meta.has_certificate ? 'uploaded_file' : 'personal_vault')
    signingProfileVaultCredentialId.value = meta.vault_credential_id || null
    signingProfileCertPolicy.value = meta.passphrase_policy === 'prompt_on_use'
      ? 'encrypted_store'
      : (meta.passphrase_policy || 'encrypted_store')
    signingProfileCertPassphraseProfileId.value = meta.passphrase_profile_id || ''
  } catch (e: any) {
    signingProfileCredential.value = { has_certificate: false }
    if (!silent) toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    signingProfileCredentialLoading.value = false
  }
}

async function loadPersonalCertificates() {
  try {
    personalCertificates.value = await settingsApi.listPersonalSigningCertificates()
  } catch {
    personalCertificates.value = []
  }
}

/**
 * Selhání načtení se NESMÍ tvářit jako prázdný trezor.
 *
 * Přesně tohle se stalo: endpoint vracel 500, tenhle blok chybu spolkl a
 * uživatel četl „zatím tu žádný certifikát není" — tedy tvrzení o datech,
 * které jsme nikdy neviděli. Chyba se proto drží ve stavu a šablona ji
 * ukazuje místo prázdného stavu.
 */
async function loadCertificates(silent = true) {
  certificatesLoading.value = true
  certificatesError.value = ''
  try {
    certificates.value = await settingsApi.listCertificates()
  } catch (e: any) {
    certificates.value = []
    certificatesError.value = apiErrorMessage(e, t('settings.certificate_vault_load_failed'))
    if (!silent) toast.error(certificatesError.value)
  } finally {
    certificatesLoading.value = false
  }
}

/**
 * Passkey proof nahradí heslo i TOTP naráz; bez něj platí původní cesta
 * heslo (+ TOTP, má-li ho účet zapnutý). Proof je jednorázový, po odeslání
 * se zahazuje.
 */
async function verifyCertificatePasskey() {
  if (!passkeySupported) return
  certificatePasskeyBusy.value = true
  try {
    const flow = await authApi.passkeyStepUpOptions('epo.certificate')
    const credential = await getCredential(flow.public_key)
    certificateStepPasskeyToken.value = await authApi.passkeyStepUpVerify(
      flow.flow_token,
      'epo.certificate',
      credential,
    )
  } catch (e: any) {
    toast.error(apiErrorMessage(e, t('settings.certificate_vault_passkey_failed')))
  } finally {
    certificatePasskeyBusy.value = false
  }
}

function resetCertificateStepUp() {
  certificateStepPassword.value = ''
  certificateStepTotp.value = ''
  certificateStepPasskeyToken.value = ''
}

function pickCertificateFile() {
  certificateFileInput.value?.click()
}

function onCertificateFileSelected(event: Event) {
  certificateFile.value = (event.target as HTMLInputElement).files?.[0] ?? null
  if (certificateFile.value && certificateLabel.value === '') {
    certificateLabel.value = certificateFile.value.name.replace(/\.(p12|pfx)$/i, '')
  }
}

async function uploadCertificate() {
  if (certificateFile.value === null || certificateBusy.value) return
  if (certificateStepUpMissing.value !== '') {
    toast.error(certificateStepUpMissing.value)
    return
  }
  certificateBusy.value = true
  try {
    await settingsApi.uploadCertificate({
      file: certificateFile.value,
      label: certificateLabel.value.trim(),
      password: certificatePassword.value,
      proof: {
        password: certificateStepPassword.value || undefined,
        totp_code: certificateStepTotp.value.trim() || undefined,
        step_up_token: certificateStepPasskeyToken.value || undefined,
      },
    })
    certificateFile.value = null
    certificateLabel.value = ''
    certificatePassword.value = ''
    if (certificateFileInput.value) certificateFileInput.value.value = ''
    resetCertificateStepUp()
    await Promise.all([loadCertificates(), loadPersonalCertificates()])
    toast.success(t('settings.certificate_vault_uploaded'))
  } catch (e: any) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    certificateBusy.value = false
  }
}

function certificateDate(value: string | null | undefined): string {
  return (value || '').slice(0, 10) || '—'
}

function startCreateSigningProfile() {
  resetSigningProfileDraft()
  showSigningProfileForm.value = true
  loadPersonalCertificates()
}

function startEditSigningProfile(profile: SigningProfile) {
  editingSigningProfile.value = profile.id
  signingProfileTsaEnabled.value = Boolean((profile.pdf_tsa_url || '').trim())
  if (profile.owner_user_id === null) {
    signingProfileOwnerMode.value = 'supplier'
  } else if (profile.owner_user_id === (auth.user?.id ?? null)) {
    signingProfileOwnerMode.value = 'current_user'
  } else {
    signingProfileOwnerMode.value = 'other_user'
  }
  Object.assign(signingProfileDraft, {
    owner_user_id: profile.owner_user_id,
    name: profile.name,
    code: profile.code,
    allowed_usages: [...profile.allowed_usages],
    default_backend: profile.default_backend,
    pdf_tsa_url: profile.pdf_tsa_url,
    pdf_tsa_username: profile.pdf_tsa_username,
    pdf_tsa_password: '',
    pdf_reason: profile.pdf_reason,
    is_active: profile.is_active,
  })
  signingProfileSlug.init(profile.code, true)
  showSigningProfileForm.value = true
  loadSigningProfileCredential(profile.id, true)
}

function onSigningProfileNameInput(event: Event) {
  const name = (event.target as HTMLInputElement).value
  signingProfileDraft.name = name
  signingProfileSlug.fromName(name)
}

function onSigningProfileCodeInput(event: Event) {
  const code = (event.target as HTMLInputElement).value
  signingProfileDraft.code = code
  signingProfileSlug.markManual(code)
}

function cancelSigningProfileEdit() {
  showSigningProfileForm.value = false
  resetSigningProfileDraft()
}

async function saveSigningProfile() {
  const pdfUsageEnabled = signingProfileDraft.allowed_usages.includes('pdf')
  if (signingProfileDraft.allowed_usages.length === 0) {
    toast.error(t('settings.signing_profile_usage_required'))
    return
  }
  if (pdfUsageEnabled && signingProfileTsaEnabled.value && !String(signingProfileDraft.pdf_tsa_url || '').trim()) {
    toast.error(t('settings.signing_tsa_url_required'))
    return
  }
  const saveCredentialAfterSave = hasPendingSigningProfileCredentialSave()
  if (saveCredentialAfterSave && !validateSigningProfileCredentialSave()) {
    return
  }

  const payload: SigningProfilePayload = {
    name: signingProfileDraft.name.trim(),
    code: signingProfileDraft.code.trim(),
    allowed_usages: [...signingProfileDraft.allowed_usages],
    default_backend: signingProfileDraft.default_backend || 'native',
    pdf_tsa_enabled: pdfUsageEnabled && signingProfileTsaEnabled.value,
    pdf_tsa_url: pdfUsageEnabled && signingProfileTsaEnabled.value ? String(signingProfileDraft.pdf_tsa_url || '').trim() : null,
    pdf_tsa_username: pdfUsageEnabled && signingProfileTsaEnabled.value ? (String(signingProfileDraft.pdf_tsa_username || '').trim() || null) : null,
    pdf_reason: signingProfileDraft.pdf_reason || null,
    is_active: signingProfileDraft.is_active,
  }
  if (!pdfUsageEnabled || !signingProfileTsaEnabled.value) {
    payload.pdf_tsa_password = ''
  } else if ((signingProfileDraft.pdf_tsa_password || '').trim() !== '') {
    payload.pdf_tsa_password = signingProfileDraft.pdf_tsa_password?.trim() || null
  }
  if (editingSigningProfile.value === null) {
    if (isAdmin.value) {
      if (signingProfileOwnerMode.value === 'current_user') {
        payload.owner_user_id = auth.user?.id ?? null
      } else if (signingProfileOwnerMode.value === 'other_user') {
        payload.owner_user_id = signingProfileDraft.owner_user_id || null
      } else {
        payload.owner_user_id = null
      }
    }
  }

  signingProfileSaving.value = true
  let credentialSaved = false
  try {
    let savedProfile: SigningProfile
    if (editingSigningProfile.value === null) {
      savedProfile = await settingsApi.createSigningProfile(payload)
      editingSigningProfile.value = savedProfile.id
    } else {
      savedProfile = await settingsApi.updateSigningProfile(editingSigningProfile.value, payload)
    }
    if (saveCredentialAfterSave) {
      signingProfileCertUploading.value = true
      await saveSigningProfileCertFor(savedProfile.id)
      credentialSaved = true
    }
    await loadSigningProfiles(true)
    cancelSigningProfileEdit()
    toast.success(t(credentialSaved ? 'settings.signing_profile_saved_with_cert' : 'settings.signing_profile_saved'))
  } catch (e: any) {
    if (editingSigningProfile.value !== null) {
      await loadSigningProfiles(true)
    }
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    signingProfileSaving.value = false
    signingProfileCertUploading.value = false
  }
}

async function deleteSigningProfile(profile: SigningProfile) {
  if (!window.confirm(t('settings.signing_profile_delete_confirm', { name: profile.name }))) return
  try {
    await settingsApi.deleteSigningProfile(profile.id)
    await loadSigningProfiles(true)
    if (editingSigningProfile.value === profile.id) cancelSigningProfileEdit()
    toast.success(t('settings.signing_profile_deleted'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

function pickSigningProfileCert() {
  signingProfileCertFileInput.value?.click()
}

function onSigningProfileCertSelected(ev: Event) {
  signingProfileCertFile.value = (ev.target as HTMLInputElement).files?.[0] ?? null
}

function hasPendingSigningProfileCredentialSave(): boolean {
  if (effectiveCredentialSource.value === 'personal_vault') {
    return signingProfileVaultCredentialId.value !== null
      && signingProfileVaultCredentialId.value !== (signingProfileCredential.value?.vault_credential_id || null)
  }
  return signingProfileCertFile.value !== null
    || signingProfileCertPassword.value !== ''
    || hasSigningProfileCredentialSettingsChange()
}

function hasSigningProfileCredentialSettingsChange(): boolean {
  const credential = signingProfileCredential.value
  if (!credential?.has_certificate) {
    return false
  }

  const currentPolicy = credential.passphrase_policy || 'encrypted_store'
  const currentPassphraseProfileId = currentPolicy === 'passphrase_file'
    ? (credential.passphrase_profile_id || '')
    : ''
  const nextPassphraseProfileId = signingProfileCertPolicy.value === 'passphrase_file'
    ? signingProfileCertPassphraseProfileId.value.trim()
    : ''

  return currentPolicy !== signingProfileCertPolicy.value
    || currentPassphraseProfileId !== nextPassphraseProfileId
}

function validateSigningProfileCredentialSave(): boolean {
  if (effectiveCredentialSource.value === 'personal_vault') {
    if (!currentUserPersonalProfile.value) {
      toast.error(t('settings.signing_vault_personal_profile_required'))
      return false
    }
    if (!signingProfileVaultCredentialId.value) {
      toast.error(t('settings.signing_vault_certificate_required'))
      return false
    }
    if (!signingProfileVaultPassword.value) {
      toast.error(t('settings.signing_vault_password_required'))
      return false
    }
    if (auth.user?.totp_enabled === true && !/^\d{6}$/.test(signingProfileVaultTotp.value.trim())) {
      toast.error(t('settings.signing_vault_totp_required'))
      return false
    }
    return true
  }
  const hasNewFile = signingProfileCertFile.value !== null
  const password = signingProfileCertPassword.value
  if (hasNewFile && password === '') {
    toast.error(t('settings.signing_profile_cert_file_password_required'))
    return false
  }
  if (!hasNewFile && !signingProfileCredential.value?.has_certificate) {
    toast.error(t('settings.signing_profile_cert_no_certificate_settings'))
    return false
  }
  if (signingProfileCertPolicy.value === 'passphrase_file' && signingProfileCertPassphraseProfileId.value.trim() === '') {
    toast.error(t('settings.signing_profile_cert_passphrase_profile_required'))
    return false
  }
  if (!hasNewFile
    && signingProfileCertPolicy.value === 'encrypted_store'
    && password === ''
    && signingProfileCredential.value?.passphrase_policy !== 'encrypted_store'
  ) {
    toast.error(t('settings.signing_profile_cert_password_required_for_encrypted_store'))
    return false
  }

  return true
}

async function saveSigningProfileCertFor(profileId: number) {
  if (effectiveCredentialSource.value === 'personal_vault') {
    signingProfileCredential.value = await settingsApi.linkPersonalSigningCertificate(
      profileId,
      signingProfileVaultCredentialId.value as number,
      signingProfileVaultPassword.value,
      signingProfileVaultTotp.value,
    )
    signingProfileVaultPassword.value = ''
    signingProfileVaultTotp.value = ''
    return
  }
  const passphraseProfileId = signingProfileCertPolicy.value === 'passphrase_file'
    ? signingProfileCertPassphraseProfileId.value.trim()
    : null
  if (signingProfileCertFile.value !== null) {
    signingProfileCredential.value = await settingsApi.uploadSigningProfileCredential(
      profileId,
      signingProfileCertFile.value,
      signingProfileCertPassword.value,
      signingProfileCertPolicy.value,
      passphraseProfileId,
    )
  } else {
    signingProfileCredential.value = await settingsApi.updateSigningProfileCredentialPassphrase(
      profileId,
      {
        passphrase_policy: signingProfileCertPolicy.value,
        passphrase_profile_id: passphraseProfileId,
        password: signingProfileCertPassword.value !== '' ? signingProfileCertPassword.value : null,
      },
    )
  }
  signingProfileCertFile.value = null
  signingProfileCertPassword.value = ''
  if (signingProfileCertFileInput.value) signingProfileCertFileInput.value.value = ''
}

async function deleteSigningProfileCert() {
  if (editingSigningProfile.value === null) return
  if (!window.confirm(t('settings.signing_profile_cert_delete_confirm'))) return
  try {
    signingProfileCredential.value = await settingsApi.deleteSigningProfileCredential(editingSigningProfile.value)
    signingProfileCertFile.value = null
    signingProfileCertPassword.value = ''
    if (signingProfileCertFileInput.value) signingProfileCertFileInput.value.value = ''
    toast.success(t('settings.signing_profile_cert_deleted'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

function pdfOutputTypeLabel(outputType: string): string {
  return t(`settings.signing_output_type_${outputType}`)
}

function signingOutputBadges(outputType: string): string[] {
  return [signingOutputUsage(outputType) === 'email_smime' ? 'S/MIME' : 'PDF']
}

function smimeIdentityPolicy(setting: PdfSignatureOutputSetting): EmailSmimeIdentityPolicy {
  // Default warning_only = shodné s backendem: podepíše i při neshodě From↔cert,
  // jen zaloguje varování. strict_match / *_override jsou vědomý opt-in.
  const policy = setting.signature_config?.smime_identity_policy
  return isEmailSmimeIdentityPolicy(policy) ? policy : 'warning_only'
}

function setSmimeIdentityPolicy(setting: PdfSignatureOutputSetting, policy: EmailSmimeIdentityPolicy) {
  setting.signature_config = {
    ...(setting.signature_config || {}),
    smime_identity_policy: policy,
  }
}

function onSmimeIdentityPolicyChange(setting: PdfSignatureOutputSetting, event: Event) {
  const value = (event.target as HTMLSelectElement | null)?.value
  setSmimeIdentityPolicy(setting, isEmailSmimeIdentityPolicy(value) ? value : 'warning_only')
}

function isEmailSmimeIdentityPolicy(value: unknown): value is EmailSmimeIdentityPolicy {
  return typeof value === 'string' && emailSmimeIdentityPolicies.includes(value as EmailSmimeIdentityPolicy)
}

function smimeIdentityAllowlistText(setting: PdfSignatureOutputSetting): string {
  const allowlist = setting.signature_config?.smime_identity_allowlist
  if (Array.isArray(allowlist)) {
    return allowlist.filter(item => typeof item === 'string' && item.trim() !== '').join('\n')
  }
  return typeof allowlist === 'string' ? allowlist : ''
}

function onSmimeIdentityAllowlistChange(setting: PdfSignatureOutputSetting, event: Event) {
  const value = (event.target as HTMLTextAreaElement | null)?.value || ''
  const allowlist = value
    .split(/[\n,;]+/)
    .map(item => item.trim().toLowerCase())
    .filter(Boolean)
  setting.signature_config = {
    ...(setting.signature_config || {}),
    smime_identity_allowlist: Array.from(new Set(allowlist)),
  }
}

function signingProfileName(profileId: number | null): string {
  if (profileId === null) return t('settings.signing_output_profile_none')
  return signingProfiles.value.find(profile => profile.id === profileId)?.name || `#${profileId}`
}

function outputUsesAdminProfile(setting: PdfSignatureOutputSetting): boolean {
  return setting.selection_source === 'admin_profile_settings'
    || (setting.selection_source === 'logged_in_user' && setting.user_profile_fallback === 'admin_profile_settings')
}

function isAdminSigningProfileForOutput(outputType: string, profileId: number | null): boolean {
  return profileId !== null && adminSigningProfilesForOutput(outputType).some(profile => profile.id === profileId)
}

function normalizePdfOutputAdminProfile(setting: PdfSignatureOutputSetting) {
  if (!outputUsesAdminProfile(setting) || !isAdminSigningProfileForOutput(setting.output_type, setting.default_profile_id)) {
    setting.default_profile_id = null
  }
}

function onSigningProfileTsaToggle() {
  if (signingProfileTsaEnabled.value) return
  signingProfileDraft.pdf_tsa_url = null
  signingProfileDraft.pdf_tsa_username = null
  signingProfileDraft.pdf_tsa_password = ''
}

function pdfOutputSettingPayload(setting: PdfSignatureOutputSetting) {
  const signatureConfig = signingOutputUsage(setting.output_type) === 'email_smime'
    ? {
        ...(setting.signature_config || {}),
        smime_identity_policy: smimeIdentityPolicy(setting),
      }
    : (setting.signature_config || {})

  return {
    enabled: setting.enabled,
    backend: setting.backend || defaultBackendForOutput(setting.output_type),
    selection_source: setting.selection_source,
    user_profile_fallback: setting.user_profile_fallback,
    default_profile_id: outputUsesAdminProfile(setting) ? setting.default_profile_id : null,
    failure_policy: setting.failure_policy,
    signature_config: signatureConfig,
  }
}

function replacePdfOutputSettings(settings: PdfSignatureOutputSetting[]) {
  pdfOutputSettings.value = settings
  pdfOutputSettingBaseline.value = Object.fromEntries(
    settings.map(setting => [setting.output_type, JSON.stringify(pdfOutputSettingPayload(setting))]),
  )
}

async function saveAllPdfOutputSettings() {
  if (pdfOutputSettingsSaving.value) return
  pdfOutputSettingsSaving.value = true
  try {
    const changed = pdfOutputSettings.value.flatMap(setting => {
      const payload = pdfOutputSettingPayload(setting)
      return pdfOutputSettingBaseline.value[setting.output_type] !== JSON.stringify(payload)
        ? [{ output_type: setting.output_type, ...payload }]
        : []
    })
    if (changed.length > 0) {
      const result = await settingsApi.updatePdfSignatureOutputSettings(changed)
      const updatedByType = new Map(result.output_settings.map(setting => [setting.output_type, setting]))
      replacePdfOutputSettings(
        pdfOutputSettings.value.map(setting => updatedByType.get(setting.output_type) || setting),
      )
    }
    toast.success(t('settings.signing_outputs_saved'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
    await loadSigningProfiles(true)
  } finally {
    pdfOutputSettingsSaving.value = false
  }
}

function pdfSigningTestMessage(result: PdfSignatureTestResult): string {
  const output = pdfOutputTypeLabel(result.output_type)
  const profile = result.profile_code || t('settings.signing_output_profile_none')
  const certificateCn = result.certificate_cn || t('settings.signing_test_cert_cn_unknown')
  if (result.status === 'signed') {
    return t('settings.signing_test_signed', { output, level: result.level || 'PAdES', profile, certificateCn })
  }
  if (result.status === 'fallback_unsigned') {
    return t('settings.signing_test_fallback', { output, error: result.error || result.reason || '—', profile, certificateCn })
  }
  if (result.status === 'failed') {
    return t('settings.signing_test_failed', { output, error: result.error || '—', profile, certificateCn })
  }

  return t('settings.signing_test_skipped', {
    output,
    reason: t(`settings.signing_test_reason_${result.reason || 'unknown'}`),
    profile,
    certificateCn,
  })
}

async function testPdfOutputSetting(setting: PdfSignatureOutputSetting) {
  pdfOutputSettingsTesting.value = setting.output_type
  try {
    const result = await settingsApi.testPdfSigning(setting.output_type)
    const message = pdfSigningTestMessage(result)
    if (result.status === 'signed') toast.success(message)
    else if (result.status === 'failed') toast.error(message)
    else toast.warning(message)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('settings.signing_test_failed_generic'))
  } finally {
    pdfOutputSettingsTesting.value = null
  }
}

</script>

<template>
  <div class="w-full">
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('electronic_signatures.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('electronic_signatures.subtitle') }}</p>
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg p-8 text-sm text-neutral-500">
      {{ t('common.loading') }}
    </div>

    <div v-else class="space-y-5">
      <section id="certificate-vault" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <div class="mb-3">
          <h3 class="text-sm font-medium text-neutral-800">{{ t('settings.certificate_vault_title') }}</h3>
          <p class="text-xs text-neutral-500 mt-1">{{ t('settings.certificate_vault_hint') }}</p>
        </div>

        <div class="rounded-md border border-warning-500/30 bg-warning-50 p-3 text-xs text-warning-800">
          {{ t('settings.certificate_vault_security') }}
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-2 rounded-lg border border-neutral-200 p-4">
          <div v-if="hasPasskey" class="sm:col-span-2 flex flex-wrap items-center gap-3">
            <div v-if="certificateStepPasskeyToken" class="text-sm font-medium text-success-600">
              ✓ {{ t('settings.certificate_vault_passkey_verified') }}
            </div>
            <template v-else-if="passkeySupported">
              <button type="button" :class="btnOutline('primary')"
                :disabled="certificatePasskeyBusy || certificateBusy" @click="verifyCertificatePasskey">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" /></svg>
                {{ certificatePasskeyBusy ? t('common.loading') : t('settings.certificate_vault_verify_passkey') }}
              </button>
              <span class="text-xs text-neutral-500">{{ t('settings.certificate_vault_step_up_or_password') }}</span>
            </template>
            <p v-else class="text-xs text-warning-700">{{ t('settings.certificate_vault_passkey_unsupported') }}</p>
          </div>
          <template v-if="!certificateStepPasskeyToken">
            <label class="text-xs text-neutral-600">
              {{ t('settings.certificate_vault_step_up_password') }}
              <input v-model="certificateStepPassword" type="password" autocomplete="current-password"
                class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" />
            </label>
            <label class="text-xs text-neutral-600">
              {{ t('settings.certificate_vault_step_up_totp') }}
              <input v-model="certificateStepTotp" type="text" inputmode="numeric" maxlength="6"
                autocomplete="one-time-code"
                class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" />
            </label>
          </template>
          <p class="sm:col-span-2 text-xs text-neutral-500">{{ t('settings.certificate_vault_step_up_hint') }}</p>
        </div>

        <h4 class="mt-5 mb-2 text-xs font-medium uppercase tracking-wide text-neutral-500">
          {{ t('settings.certificate_vault_list_title') }}
        </h4>
        <div v-if="certificatesLoading" class="text-xs text-neutral-500 py-2">{{ t('common.loading') }}</div>
        <div v-else-if="certificatesError" class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
          {{ certificatesError }}
        </div>
        <EmptyState v-else-if="certificates.length === 0" dense icon="lock" :title="t('settings.certificate_vault_empty')" />
        <div v-else class="space-y-3">
          <div v-for="certificate in certificates" :key="certificate.id" class="rounded-lg border border-neutral-200 p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div class="min-w-0">
                <div class="text-sm font-medium text-neutral-800">{{ certificate.label }}</div>
                <div class="mt-1 text-xs text-neutral-600 break-words">
                  <span class="text-neutral-500">{{ t('settings.certificate_vault_subject') }}:</span> {{ certificate.subject_dn }}
                </div>
                <div class="mt-1 text-xs text-neutral-600 break-words">
                  <span class="text-neutral-500">{{ t('settings.certificate_vault_issuer') }}:</span> {{ certificate.issuer_dn }}
                </div>
                <div class="mt-1 text-xs text-neutral-600">
                  <span class="text-neutral-500">{{ t('settings.certificate_vault_serial') }}:</span>
                  <span class="font-mono">{{ certificate.serial_hex || '—' }}</span>
                </div>
                <div class="mt-1 text-xs">
                  <span class="text-neutral-500">{{ t('settings.certificate_vault_validity') }}:</span>
                  <span :class="certificate.valid_now ? 'text-success-600' : 'text-danger-600 font-semibold'">
                    {{ certificateDate(certificate.valid_from) }} – {{ certificateDate(certificate.valid_to) }}
                  </span>
                </div>
                <code class="mt-1 block text-[10px] text-neutral-400 break-all">SHA-256: {{ certificate.fingerprint_sha256 }}</code>
              </div>
              <div class="flex flex-col items-end gap-2">
                <span class="rounded-full border px-2 py-0.5 text-[11px]"
                  :class="certificate.enabled_for_supplier ? 'border-success-500/30 bg-success-50 text-success-700' : 'border-neutral-200 bg-neutral-100 text-neutral-600'">
                  {{ certificate.enabled_for_supplier ? t('settings.certificate_vault_enabled_for_company') : t('settings.certificate_vault_disabled_for_company') }}
                </span>
                <span v-if="!certificate.valid_now" class="rounded-full border border-danger-500/30 bg-danger-50 px-2 py-0.5 text-[11px] text-danger-700">
                  {{ t('settings.signing_cert_expired') }}
                </span>
                <span v-if="certificate.linked_profiles_count > 0" class="rounded-full border border-primary-500/30 bg-primary-50 px-2 py-0.5 text-[11px] text-primary-700">
                  {{ t('settings.certificate_vault_linked_profiles', { count: certificate.linked_profiles_count }) }}
                </span>
              </div>
            </div>
          </div>
          <p class="text-xs text-neutral-500">
            {{ t('settings.certificate_vault_grant_hint') }}
            <RouterLink to="/reports/submissions" class="font-medium text-primary-700 hover:underline">
              {{ t('settings.certificate_vault_grant_link') }}
            </RouterLink>
          </p>
        </div>

        <div class="mt-5 border-t border-neutral-100 pt-4">
          <h4 class="text-xs font-medium uppercase tracking-wide text-neutral-500">{{ t('settings.certificate_vault_upload_title') }}</h4>
          <p class="mt-1 text-xs text-neutral-500">{{ t('settings.certificate_vault_upload_hint') }}</p>
          <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <div class="sm:col-span-2 flex flex-wrap items-center gap-3">
              <button type="button" :class="btnOutline('primary')" :disabled="certificateBusy" @click="pickCertificateFile">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.folderOpen" /></svg>
                {{ t('settings.certificate_vault_choose_file') }}
              </button>
              <span class="min-w-0 break-all text-sm"
                :class="certificateFile ? 'text-neutral-700 font-medium' : 'text-neutral-400'">
                {{ certificateFile ? certificateFile.name : t('settings.certificate_vault_no_file') }}
              </span>
              <input ref="certificateFileInput" type="file" accept=".p12,.pfx,application/x-pkcs12"
                class="hidden" @change="onCertificateFileSelected" />
            </div>
            <label class="text-xs text-neutral-600">
              {{ t('settings.certificate_vault_label') }}
              <input v-model="certificateLabel" type="text" maxlength="120"
                class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" />
            </label>
            <label class="text-xs text-neutral-600">
              {{ t('settings.certificate_vault_password') }}
              <input v-model="certificatePassword" type="password" autocomplete="new-password"
                class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" />
            </label>
          </div>
          <p v-if="certificateStepUpMissing" class="mt-2 text-xs text-warning-700">{{ certificateStepUpMissing }}</p>
        </div>

        <div class="mt-4 flex flex-wrap justify-end">
          <button type="button" :class="btnFilled('primary')" :disabled="!canUploadCertificate" @click="uploadCertificate">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
            {{ certificateBusy ? t('common.saving') : t('settings.certificate_vault_store') }}
          </button>
        </div>
      </section>

      <template v-if="contentVisible">
      <section v-if="canManageSigningProfiles" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
          <div>
            <h3 class="text-sm font-medium text-neutral-800">{{ t('settings.signing_profiles_title') }}</h3>
            <p class="text-xs text-neutral-500 mt-1">{{ t('settings.signing_profiles_hint') }}</p>
          </div>
          <button @click="startCreateSigningProfile" type="button" :class="btnFilled('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
            {{ t('settings.signing_profiles_new') }}
          </button>
        </div>

        <label v-if="isAdmin && signingSettings" class="inline-flex items-start gap-2 text-sm mb-3">
          <input v-model="signingSettings.accountant_profiles_enabled" @change="saveSigningProfileSettings"
            type="checkbox" :disabled="signingSettingsSaving"
            class="mt-0.5 h-4 w-4 accent-primary-600 disabled:opacity-50" />
          <span>
            <span class="block text-neutral-700">{{ t('settings.signing_profiles_accountant_enabled') }}</span>
            <span class="block text-xs text-neutral-500">{{ t('settings.signing_profiles_accountant_hint') }}</span>
          </span>
        </label>

        <div v-if="signingProfilesLoading" class="text-xs text-neutral-500 py-2">{{ t('common.loading') }}</div>
        <EmptyState v-else-if="signingProfiles.length === 0" dense icon="lock" :title="t('settings.signing_profiles_empty')" />
        <div v-else class="overflow-x-auto border-y border-neutral-100">
          <table class="w-full text-xs">
            <thead class="bg-neutral-50 text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('settings.signing_profile_name') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('settings.signing_profile_code') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('settings.signing_profile_owner') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('settings.signing_profile_usages') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('settings.signing_profile_backend') }}</th>
                <th class="px-3 py-2 text-center font-medium">{{ t('common.active') }}</th>
                <th class="px-3 py-2 w-28"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="profile in signingProfiles" :key="profile.id">
                <td class="px-3 py-2 font-medium text-neutral-800">{{ profile.name }}</td>
                <td class="px-3 py-2 font-mono text-neutral-700">{{ profile.code }}</td>
                <td class="px-3 py-2 text-neutral-600">{{ signingProfileOwnerLabel(profile) }}</td>
                <td class="px-3 py-2 text-neutral-600">{{ profile.allowed_usages.map(signingProfileUsageLabel).join(', ') }}</td>
                <td class="px-3 py-2 font-mono text-neutral-600">{{ profile.default_backend }}</td>
                <td class="px-3 py-2 text-center">
                  <span :class="profile.is_active ? 'text-success-600' : 'text-neutral-400'">
                    {{ profile.is_active ? '✓' : '—' }}
                  </span>
                </td>
                <td class="px-3 py-2 text-right whitespace-nowrap">
                  <button @click="startEditSigningProfile(profile)" type="button"
                    class="cursor-pointer text-primary-600 hover:text-primary-700">{{ t('common.edit') }}</button>
                  <button @click="deleteSigningProfile(profile)" type="button"
                    class="cursor-pointer ml-2 text-danger-600 hover:text-danger-700">{{ t('common.delete') }}</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <form v-if="showSigningProfileForm" @submit.prevent="saveSigningProfile" class="mt-4 border-t border-neutral-100 pt-4">
          <div class="grid gap-3 sm:grid-cols-2">
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.signing_profile_name') }}</label>
              <input :value="signingProfileDraft.name" @input="onSigningProfileNameInput"
                type="text" maxlength="120" required
                class="h-9 w-full px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.signing_profile_code') }}</label>
              <input :value="signingProfileDraft.code" @input="onSigningProfileCodeInput"
                type="text" maxlength="80" required pattern="[A-Za-z0-9_.-]+"
                class="h-9 w-full px-3 border border-neutral-300 rounded-md bg-surface text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.signing_profile_backend') }}</label>
              <select v-model="signingProfileDraft.default_backend" class="h-9 w-full px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option value="native">native</option>
              </select>
            </div>
            <div v-if="isAdmin">
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.signing_profile_owner_mode') }}</label>
              <select v-model="signingProfileOwnerMode" :disabled="editingSigningProfile !== null"
                class="h-9 w-full px-3 border border-neutral-300 rounded-md bg-surface text-sm disabled:bg-neutral-50 disabled:text-neutral-500">
                <option value="supplier">{{ t('settings.signing_profile_owner_mode_supplier') }}</option>
                <option value="current_user">{{ t('settings.signing_profile_owner_mode_current_user') }}</option>
                <option value="other_user">{{ t('settings.signing_profile_owner_mode_other_user') }}</option>
              </select>
            </div>
            <div v-if="isAdmin && signingProfileOwnerMode === 'other_user'">
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.signing_profile_owner_user_id') }}</label>
              <input v-model.number="signingProfileDraft.owner_user_id" type="number" min="1" required
                :disabled="editingSigningProfile !== null"
                :placeholder="t('settings.signing_profile_owner_user_id')"
                class="h-9 w-full px-3 border border-neutral-300 rounded-md bg-surface text-sm disabled:bg-neutral-50 disabled:text-neutral-500" />
            </div>
            <div class="sm:col-span-2">
              <label class="block text-xs font-medium text-neutral-700 mb-2">{{ t('settings.signing_profile_usages') }}</label>
              <div class="flex flex-wrap gap-4">
                <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
                  <input v-model="signingProfileDraft.allowed_usages" type="checkbox" value="pdf" class="h-4 w-4 accent-primary-600" />
                  {{ t('settings.signing_profile_usage_pdf') }}
                </label>
                <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
                  <input v-model="signingProfileDraft.allowed_usages" type="checkbox" value="email_smime" class="h-4 w-4 accent-primary-600" />
                  {{ t('settings.signing_profile_usage_email_smime') }}
                </label>
                <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
                  <input v-model="signingProfileDraft.is_active" type="checkbox" class="h-4 w-4 accent-primary-600" />
                  {{ t('settings.signing_profile_active') }}
                </label>
              </div>
            </div>
            <div v-if="signingProfileDraft.allowed_usages.includes('pdf')" class="sm:col-span-2 border-t border-neutral-100 pt-3 space-y-4">
              <div>
                <h4 class="text-xs font-medium text-neutral-700">{{ t('settings.signing_profile_pdf_settings') }}</h4>
                <p class="text-xs text-neutral-500 mt-1">{{ t('settings.signing_profile_pdf_settings_hint') }}</p>
              </div>
              <div>
                <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
                  <input v-model="signingProfileTsaEnabled" @change="onSigningProfileTsaToggle" type="checkbox" class="h-4 w-4 accent-primary-600" />
                  <span class="font-medium">{{ t('settings.signing_tsa_enabled') }}</span>
                </label>
                <div v-if="signingProfileTsaEnabled" class="mt-2 space-y-2">
                  <p class="text-xs text-neutral-500">{{ t('settings.signing_tsa_hint') }}</p>
                  <input v-model="signingProfileDraft.pdf_tsa_url" type="text" required placeholder="http://tsa.cesnet.cz:3161/tsa"
                    class="h-10 w-full max-w-3xl px-3 border border-neutral-300 rounded-md bg-surface text-sm font-mono" />
                  <p class="text-xs text-neutral-500">{{ t('settings.signing_tsa_auth_hint') }}</p>
                  <div class="flex flex-wrap items-center gap-2">
                    <input v-model="signingProfileDraft.pdf_tsa_username" type="text" :placeholder="t('settings.signing_tsa_user')" autocomplete="off"
                      class="h-9 w-44 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
                    <input v-model="signingProfileDraft.pdf_tsa_password" type="password"
                      :placeholder="editingSigningProfile !== null && signingProfiles.find(profile => profile.id === editingSigningProfile)?.has_pdf_tsa_password ? t('settings.signing_tsa_pass_set') : t('settings.signing_tsa_pass')"
                      autocomplete="new-password"
                      class="h-9 w-44 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
                  </div>
                </div>
              </div>
              <div>
                <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.signing_reason') }}</label>
                <input v-model="signingProfileDraft.pdf_reason" type="text" :placeholder="t('settings.signing_reason_ph')"
                  class="h-10 w-full max-w-3xl px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
              </div>
            </div>
            <div class="sm:col-span-2 border-t border-neutral-100 pt-3">
              <div class="flex items-center justify-between gap-3 mb-2">
                <div>
                  <label class="block text-xs font-medium text-neutral-700">
                    {{ t('settings.signing_profile_cert_title') }}
                  </label>
                  <p class="mt-1 text-xs text-neutral-500">{{ t('profile_signing.credential_hint') }}</p>
                </div>
                <button v-if="signingProfileCredential?.has_certificate" @click="deleteSigningProfileCert" type="button"
                  class="cursor-pointer text-xs text-danger-600 hover:text-danger-700">{{ t('common.remove') }}</button>
              </div>
              <div v-if="editingSigningProfile === null" class="mb-3 rounded-md border border-neutral-200 bg-neutral-50 px-3 py-2 text-xs text-neutral-500">
                {{ t('settings.signing_profile_cert_upload_after_create') }}
              </div>
              <div v-else-if="signingProfileCredentialLoading" class="text-xs text-neutral-500 py-2">{{ t('common.loading') }}</div>
              <div v-else-if="signingProfileCredential?.has_certificate" class="mb-3 rounded-md border border-neutral-200 bg-neutral-50 p-3 text-xs">
                <div class="mb-1">
                  <span class="inline-flex rounded-full border border-primary-500/30 bg-primary-50 px-2 py-0.5 text-[11px] text-primary-700">
                    {{ signingProfileCredential.certificate_source === 'personal_vault'
                      ? t('settings.signing_vault_source')
                      : t('settings.signing_uploaded_source') }}
                  </span>
                </div>
                <div><span class="text-neutral-500">{{ t('settings.signing_profile_cert_subject') }}:</span> <span class="font-medium">{{ signingProfileCredential.certificate_subject || '—' }}</span></div>
                <div v-if="signingProfileCredential.certificate_email"><span class="text-neutral-500">{{ t('settings.signing_profile_cert_email') }}:</span> {{ signingProfileCredential.certificate_email }}</div>
                <div>
                  <span class="text-neutral-500">{{ t('settings.signing_cert_validity') }}:</span>
                  <span :class="signingProfileCredential.expired ? 'text-danger-600 font-semibold' : 'text-success-600'">
                    {{ (signingProfileCredential.certificate_valid_from || '').slice(0,10) }} – {{ (signingProfileCredential.certificate_valid_to || '').slice(0,10) }}
                    <template v-if="signingProfileCredential.expired"> ({{ t('settings.signing_cert_expired') }})</template>
                  </span>
                </div>
                <div v-if="signingProfileCredential.certificate_source !== 'personal_vault'"><span class="text-neutral-500">{{ t('settings.signing_profile_cert_passphrase_policy') }}:</span> <span class="font-mono">{{ signingProfileCredential.passphrase_policy }}</span></div>
                <div class="font-mono text-[10px] text-neutral-400 mt-1 break-all">SHA-256: {{ signingProfileCredential.certificate_fingerprint }}</div>
              </div>
              <div v-if="currentUserPersonalProfile" class="mb-3 flex flex-wrap items-center gap-2">
                <button type="button"
                  :class="signingProfileCredentialSource === 'personal_vault' ? btnFilled('primary') : btnOutline('primary')"
                  @click="signingProfileCredentialSource = 'personal_vault'">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" /></svg>
                  {{ t('settings.signing_use_personal_vault') }}
                </button>
                <button type="button"
                  :class="signingProfileCredentialSource === 'uploaded_file' ? btnFilled('neutral') : btnOutline('neutral')"
                  @click="signingProfileCredentialSource = 'uploaded_file'">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
                  {{ t('settings.signing_upload_separate') }}
                </button>
                <span class="rounded-full border border-neutral-300 bg-neutral-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-neutral-500">
                  {{ t('settings.signing_upload_legacy_badge') }}
                </span>
              </div>
              <div v-if="effectiveCredentialSource === 'personal_vault'"
                class="rounded-lg border border-primary-500/25 bg-primary-50/40 p-3">
                <div class="grid gap-3 md:grid-cols-2">
                  <label class="text-xs text-neutral-700">
                    {{ t('settings.signing_vault_certificate') }}
                    <select v-model="signingProfileVaultCredentialId"
                      class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
                      <option :value="null">{{ t('settings.signing_vault_choose') }}</option>
                      <option v-for="credential in usablePersonalCertificates" :key="credential.id" :value="credential.id">
                        {{ credential.label }} · {{ credential.subject_dn }}
                      </option>
                    </select>
                  </label>
                  <div class="grid grid-cols-2 gap-2">
                    <label class="text-xs text-neutral-700">
                      {{ t('reports.submissions.current_password') }}
                      <input v-model="signingProfileVaultPassword" type="password" autocomplete="current-password"
                        class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
                    </label>
                    <label class="text-xs text-neutral-700">
                      {{ t('reports.submissions.totp_optional') }}
                      <input v-model="signingProfileVaultTotp" type="text" inputmode="numeric" maxlength="6"
                        autocomplete="one-time-code"
                        class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
                    </label>
                  </div>
                </div>
                <p v-if="usablePersonalCertificates.length === 0" class="mt-2 text-xs text-warning-700">
                  {{ t('settings.signing_vault_empty_hint') }}
                </p>
                <p class="mt-2 text-xs text-neutral-600">{{ t('settings.signing_vault_hint') }}</p>
                <a href="#certificate-vault" class="mt-2 inline-flex text-xs font-medium text-primary-700 hover:underline">
                  {{ t('settings.signing_vault_manage') }}
                </a>
              </div>
              <template v-else>
              <p class="mb-2 rounded-md border border-warning-500/30 bg-warning-50 px-3 py-2 text-xs text-warning-800">
                {{ t('settings.signing_upload_legacy_hint') }}
              </p>
              <div class="grid gap-2 lg:grid-cols-[auto_minmax(10rem,1fr)_minmax(10rem,12rem)_minmax(10rem,13rem)] lg:items-center">
                <button @click="pickSigningProfileCert" type="button"
                  :class="btnOutline('primary')">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
                  {{ t('settings.signing_cert_choose') }}
                </button>
                <span class="text-xs truncate" :class="signingProfileCertFile ? 'text-neutral-700 font-medium' : 'text-neutral-400'">
                  {{ signingProfileCertFile ? signingProfileCertFile.name : t('settings.signing_cert_none_selected') }}
                </span>
                <input v-model="signingProfileCertPassword" type="password" :placeholder="t('settings.signing_password')"
                  autocomplete="new-password"
                  class="h-9 w-full px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
                <select v-model="signingProfileCertPolicy" class="h-9 w-full px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                  <option value="encrypted_store">{{ t('settings.signing_profile_cert_policy_encrypted_store') }}</option>
                  <option value="passphrase_file">{{ t('settings.signing_profile_cert_policy_passphrase_file') }}</option>
                </select>
              </div>
              <p v-if="signingProfileCredentialUsesUnsupportedPrompt" class="mt-2 text-xs text-warning-700">
                {{ t('settings.signing_profile_cert_prompt_on_use_hint') }}
              </p>
              <input v-if="signingProfileCertPolicy === 'passphrase_file'" v-model="signingProfileCertPassphraseProfileId"
                type="text" :placeholder="t('settings.signing_profile_cert_passphrase_profile_id')"
                class="mt-2 h-9 w-full max-w-sm px-3 border border-neutral-300 rounded-md bg-surface text-sm font-mono" />
              </template>
              <input ref="signingProfileCertFileInput" @change="onSigningProfileCertSelected" type="file" accept=".p12,.pfx,application/x-pkcs12" class="hidden" />
            </div>
          </div>
          <div class="flex justify-end gap-2 pt-4">
            <button @click="cancelSigningProfileEdit" type="button"
              :class="btnOutline('neutral')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
              {{ t('common.cancel') }}
            </button>
            <button type="submit" :disabled="signingProfileSaving"
              :class="btnFilled('primary')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
              {{ signingProfileSaving ? t('common.loading') : (editingSigningProfile === null ? t('settings.signing_profile_create') : t('settings.signing_profile_update')) }}
            </button>
          </div>
        </form>

      </section>

      <section v-if="canUseUserDefaults" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-sm font-medium text-neutral-800">{{ t('profile_signing.default_profiles_title') }}</h3>
          <p class="text-xs text-neutral-500 mt-1 mb-3">{{ t('profile_signing.default_profiles_hint') }}</p>
          <div v-if="signingProfilesLoading" class="text-xs text-neutral-500 py-2">{{ t('common.loading') }}</div>
          <div v-else class="overflow-x-auto border-y border-neutral-100">
            <table class="w-full text-xs">
              <thead class="bg-neutral-50 text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th class="px-3 py-2 text-left font-medium">{{ t('settings.signing_output_type') }}</th>
                  <th class="px-3 py-2 text-left font-medium">{{ t('settings.signing_output_profile') }}</th>
                  <th class="px-3 py-2 text-left font-medium">{{ t('profile_signing.default_profiles_status') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <EmptyState v-if="outputTypes.length === 0" dense icon="doc" :colspan="3" :title="t('common.no_data')" />
                <tr v-for="outputType in outputTypes" :key="outputType" class="align-top">
                  <td class="px-3 py-2">
                    <div class="flex flex-wrap items-center gap-2">
                      <span class="font-medium text-neutral-800">{{ pdfOutputTypeLabel(outputType) }}</span>
                      <span v-for="badge in signingOutputBadges(outputType)" :key="badge"
                        class="inline-flex items-center rounded border border-neutral-300 bg-neutral-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-neutral-600">
                        {{ badge }}
                      </span>
                    </div>
                  </td>
                  <td class="px-3 py-2">
                    <select
                      class="h-8 w-56 px-2 border border-neutral-300 rounded-md text-xs bg-surface disabled:bg-neutral-50 disabled:text-neutral-500"
                      :value="userDefaultProfileId(outputType) ?? ''"
                      :disabled="userDefaultSaving === outputType || activeUserSigningProfilesForOutput(outputType).length === 0"
                      @change="saveUserDefault(outputType, ($event.target as HTMLSelectElement).value)"
                    >
                      <option value="">{{ t('profile_signing.default_profile_none') }}</option>
                      <option v-for="profile in activeUserSigningProfilesForOutput(outputType)" :key="profile.id" :value="profile.id">{{ profile.name }} ({{ profile.code }})</option>
                    </select>
                  </td>
                  <td class="px-3 py-2 text-[11px] leading-snug" :class="userProfileMappingWarning(outputType) ? 'text-warning-700' : 'text-neutral-500'">
                    {{ userDefaultSaving === outputType ? t('common.saving') : userProfileMappingStatus(outputType) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
      </section>

      <section v-if="isAdmin" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-sm font-medium text-neutral-800">{{ t('settings.signing_outputs_title') }}</h3>
          <p class="text-xs text-neutral-500 mt-1 mb-3">{{ t('settings.signing_outputs_hint') }}</p>
          <div v-if="signingProfilesLoading" class="text-xs text-neutral-500 py-2">{{ t('common.loading') }}</div>
          <div v-else class="overflow-x-auto border-y border-neutral-100">
            <table class="w-full text-xs">
              <thead class="bg-neutral-50 text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th class="px-3 py-2 text-left font-medium">{{ t('settings.signing_output_type') }}</th>
                  <th class="px-3 py-2 text-center font-medium">{{ t('settings.signing_output_enabled') }}</th>
                  <th class="px-3 py-2 text-left font-medium">{{ t('settings.signing_output_selection_source') }}</th>
                  <th class="px-3 py-2 text-left font-medium">{{ t('settings.signing_output_profile') }}</th>
                  <th class="px-3 py-2 text-left font-medium">{{ t('settings.signing_output_user_fallback') }}</th>
                  <th class="px-3 py-2 text-left font-medium">{{ t('settings.signing_output_failure_policy') }}</th>
                  <th class="px-3 py-2 text-left font-medium">{{ t('settings.signing_output_identity_policy') }}</th>
                  <th class="px-3 py-2 w-24"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="setting in pdfOutputSettings" :key="setting.output_type">
                  <td class="px-3 py-2">
                    <div class="flex flex-wrap items-center gap-2">
                      <span class="font-medium text-neutral-800">{{ pdfOutputTypeLabel(setting.output_type) }}</span>
                      <span v-for="badge in signingOutputBadges(setting.output_type)" :key="badge"
                        class="inline-flex items-center rounded border border-neutral-300 bg-neutral-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-neutral-600">
                        {{ badge }}
                      </span>
                    </div>
                  </td>
                  <td class="px-3 py-2 text-center">
                    <input v-model="setting.enabled" type="checkbox" class="h-4 w-4 accent-primary-600" />
                  </td>
                  <td class="px-3 py-2">
                    <div class="flex items-center gap-2">
                      <select v-model="setting.selection_source" @change="normalizePdfOutputAdminProfile(setting)" class="h-8 w-44 px-2 border border-neutral-300 rounded-md bg-surface text-xs">
                        <option value="admin_profile_settings">{{ t('settings.signing_output_source_admin_profile_settings') }}</option>
                        <option value="logged_in_user">{{ t('settings.signing_output_source_logged_in_user') }}</option>
                      </select>
                      <span v-if="setting.selection_source !== 'logged_in_user'"
                        :title="t('settings.signing_output_user_profiles_inactive')"
                        :aria-label="t('settings.signing_output_user_profiles_inactive')"
                        class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-warning-300 bg-warning-50 text-xs font-bold leading-none text-warning-700">
                        !
                      </span>
                    </div>
                  </td>
                  <td class="px-3 py-2">
                    <select v-model="setting.default_profile_id" :disabled="!outputUsesAdminProfile(setting)"
                      class="h-8 w-44 px-2 border border-neutral-300 rounded-md bg-surface text-xs disabled:bg-neutral-50 disabled:text-neutral-500">
                      <option :value="null">{{ outputUsesAdminProfile(setting) ? t('settings.signing_output_profile_none') : signingProfileName(setting.default_profile_id) }}</option>
                      <option v-for="profile in adminSigningProfilesForOutput(setting.output_type)" :key="profile.id" :value="profile.id">
                        {{ profile.name }} ({{ profile.code }})
                      </option>
                    </select>
                  </td>
                  <td class="px-3 py-2">
                    <select v-model="setting.user_profile_fallback" @change="normalizePdfOutputAdminProfile(setting)" class="h-8 w-44 px-2 border border-neutral-300 rounded-md bg-surface text-xs">
                      <option value="admin_profile_settings">{{ t('settings.signing_output_fallback_admin_profile_settings') }}</option>
                      <option value="fallback_unsigned">{{ t('settings.signing_output_fallback_unsigned') }}</option>
                      <option value="fail_closed">{{ t('settings.signing_output_fallback_fail_closed') }}</option>
                    </select>
                  </td>
                  <td class="px-3 py-2">
                    <select v-model="setting.failure_policy" class="h-8 w-40 px-2 border border-neutral-300 rounded-md bg-surface text-xs">
                      <option value="fallback_unsigned">{{ t('settings.signing_output_policy_fallback_unsigned') }}</option>
                      <option value="fail_closed">{{ t('settings.signing_output_policy_fail_closed') }}</option>
                      <option value="skip_when_unconfigured">{{ t('settings.signing_output_policy_skip_when_unconfigured') }}</option>
                    </select>
                  </td>
                  <td class="px-3 py-2">
                    <div v-if="signingOutputUsage(setting.output_type) === 'email_smime'" class="space-y-2">
                      <select
                        :value="smimeIdentityPolicy(setting)"
                        @change="onSmimeIdentityPolicyChange(setting, $event)"
                        class="h-8 w-56 px-2 border border-neutral-300 rounded-md bg-surface text-xs">
                        <option value="strict_match">{{ t('settings.signing_output_identity_strict_match') }}</option>
                        <option value="warning_only">{{ t('settings.signing_output_identity_warning_only') }}</option>
                        <option value="same_domain_override">{{ t('settings.signing_output_identity_same_domain_override') }}</option>
                        <option value="allowlist_override">{{ t('settings.signing_output_identity_allowlist_override') }}</option>
                      </select>
                      <p v-if="smimeIdentityPolicy(setting) === 'same_domain_override'" class="max-w-56 text-[11px] leading-snug text-neutral-500">
                        {{ t('settings.signing_output_identity_same_domain_hint') }}
                      </p>
                      <div v-if="smimeIdentityPolicy(setting) === 'allowlist_override'" class="space-y-1">
                        <textarea
                          :value="smimeIdentityAllowlistText(setting)"
                          @input="onSmimeIdentityAllowlistChange(setting, $event)"
                          :placeholder="t('settings.signing_output_identity_allowlist_placeholder')"
                          class="min-h-20 w-56 px-2 py-1.5 border border-neutral-300 rounded-md bg-surface text-xs leading-snug"></textarea>
                        <p class="max-w-56 text-[11px] leading-snug text-neutral-500">
                          {{ t('settings.signing_output_identity_allowlist_hint') }}
                        </p>
                      </div>
                    </div>
                    <span v-else class="text-neutral-400">{{ t('settings.signing_output_identity_policy_not_applicable') }}</span>
                  </td>
                  <td class="px-3 py-2 text-right whitespace-nowrap">
                    <button v-if="signingOutputUsage(setting.output_type) === 'pdf'" @click="testPdfOutputSetting(setting)" type="button"
                      :disabled="pdfOutputSettingsTesting === setting.output_type"
                      class="cursor-pointer text-neutral-600 hover:text-neutral-800 disabled:opacity-50">
                      {{ pdfOutputSettingsTesting === setting.output_type ? t('common.loading') : t('settings.signing_output_test') }}
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="!signingProfilesLoading" class="mt-4 flex flex-wrap justify-end">
            <button type="button" :class="btnFilled('primary')" :disabled="pdfOutputSettingsSaving"
              @click="saveAllPdfOutputSettings">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" />
              </svg>
              {{ pdfOutputSettingsSaving ? t('common.saving') : t('settings.signing_outputs_save_all') }}
            </button>
          </div>
      </section>
      </template>

      <div v-else class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('profile_signing.disabled_title') }}</h2>
        <p class="text-sm text-neutral-600 mt-2">{{ t('profile_signing.disabled_text') }}</p>
      </div>
    </div>
  </div>
</template>
