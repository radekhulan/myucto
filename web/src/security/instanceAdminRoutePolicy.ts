/** Technický účet používá vlastní plochu; běžný firemní shell se nemontuje. */
export function isInstanceAdminRouteName(name: unknown): boolean {
  return typeof name === 'string' && [
    'instance-admin', 'instance-admin-profile', 'profile-password', 'setup-mfa', 'setup-totp',
  ].includes(name)
}
