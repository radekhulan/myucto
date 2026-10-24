export const PERMISSION_KEYS = [
  'dashboard', 'dashboard.portfolio',
  'clients', 'clients.create', 'clients.archive', 'clients.public_links',
  'projects', 'projects.create', 'projects.archive',
  'invoices', 'invoices.create', 'invoices.issue', 'invoices.send',
  'invoices.reminder', 'invoices.mark_paid', 'invoices.cancel', 'invoices.clone',
  'invoices.delete', 'invoices.approval', 'invoices.edit_issued',
  'purchase_invoices', 'purchase_invoices.create', 'purchase_invoices.transition',
  'purchase_invoices.scan', 'purchase_invoices.extract', 'purchase_invoices.payment_orders', 'purchase_invoices.delete',
  'purchase_invoices.approve', 'purchase_invoices.vat_treatment',
  'price_list',
  'recurring', 'recurring.create', 'recurring.run', 'recurring.pause', 'recurring.delete',
  'bank', 'bank.import', 'bank.match', 'bank.ignore', 'bank.post', 'bank.unpost', 'bank.rules',
  'documents', 'documents.upload', 'documents.move', 'documents.delete',
  'documents.restore', 'documents.requests', 'documents.inbox', 'documents.inbox.delete',
  'documents.submit',
  'accounting', 'other_items', 'accounting.journal.write', 'accounting.journal.post',
  'accounting.periods.manage', 'accounting.periods.close', 'accounting.periods.close_override', 'accounting.offsets',
  'accounting.templates',
  'tax_evidence', 'tax_evidence.classification.write', 'tax_evidence.export',
  'reports', 'reports.submissions', 'reports.submissions.manage', 'reports.finalize', 'reports.submit', 'reports.reopen', 'reports.export',
  'payroll', 'payroll.settings', 'payroll.person.read_sensitive', 'payroll.person.write', 'payroll.personnel',
  'payroll.employment.write', 'payroll.time.write', 'payroll.inputs.write',
  'payroll.calculate', 'payroll.review', 'payroll.approve', 'payroll.reopen',
  'payroll.post', 'payroll.payments', 'payroll.submissions', 'payroll.enforcement', 'payroll.enforcement.cooperation',
  'payroll.insolvency', 'payroll.reports', 'payroll.rulesets', 'payroll.documents',
  'payroll.retention', 'payroll.erasure', 'payroll.health_evidence',
  'cash', 'cash.document.write', 'cash.close',
  'eet', 'eet.accept_payment', 'eet.correct', 'eet.settings', 'eet.certificates', 'eet.export',
  'assets', 'assets.write', 'assets.depreciation', 'assets.dispose',
  'stock', 'stock.items.write', 'stock.documents.write', 'stock.orders.write', 'stock.vendors.write',
  'stock.take', 'stock.close', 'stock.fulfillment.write', 'stock.fulfillment.override',
  'eshop', 'eshop.write', 'eshop.integrations',
  'logbook', 'logbook.write', 'logbook.import', 'logbook.delete',
  'property', 'property.registry.manage', 'property.registry.confirm', 'property.export',
  'property.charges.manage', 'property.charges.confirm',
  'property.charges.issue', 'property.charges.post', 'property.charges.reverse',
  'property.receivables.manage', 'property.receivables.confirm', 'property.receivables.certificates',
  'property.settlements.manage', 'property.settlements.post', 'property.settlements.reverse', 'property.settlements.unwind',
  'property.funds.post', 'property.funds.reverse',
  'property.opening.manage', 'property.opening.post', 'property.opening.revise',
  'property.payments.manage', 'property.payments.post', 'property.payments.reverse',
  'settings.company', 'settings.company.write', 'settings.domains', 'settings.bank_accounts',
  'settings.email_profiles', 'settings.email_templates', 'settings.payment_qr', 'settings.signing_profiles',
  'settings.branding', 'settings.ai_provider', 'settings.signing',
  'utilities', 'utilities.export', 'utilities.import', 'utilities.archives',
  'profile', 'profile.tokens',
] as const

export type PermissionKey = typeof PERMISSION_KEYS[number]
export type AccessLevel = 'read' | 'write'
export type PermissionValue = 0 | 1 | 2

/** PermissionCatalog::role_types obsahující `client`; parity hlídá PHP architecture test. */
export const CLIENT_PERMISSION_KEYS = [
  'clients', 'clients.create', 'clients.archive',
  'invoices', 'invoices.create', 'invoices.issue', 'invoices.send',
  'invoices.reminder', 'invoices.mark_paid', 'invoices.cancel', 'invoices.clone',
  'invoices.delete', 'invoices.approval',
  'purchase_invoices', 'purchase_invoices.create', 'purchase_invoices.transition',
  'purchase_invoices.delete', 'purchase_invoices.extract',
  'price_list',
  'recurring', 'recurring.create', 'recurring.run', 'recurring.pause', 'recurring.delete',
  'bank', 'bank.import', 'bank.match', 'bank.ignore',
  'reports.submissions', 'reports.submit',
  'documents.submit', 'settings.company', 'settings.branding',
  'settings.email_profiles', 'settings.email_templates', 'settings.payment_qr', 'settings.signing_profiles',
  'projects', 'projects.create', 'projects.archive',
  'logbook', 'logbook.write', 'logbook.import', 'logbook.delete',
  'stock', 'stock.items.write', 'stock.fulfillment.write', 'profile',
] as const satisfies readonly PermissionKey[]

const CLIENT_PERMISSION_SET = new Set<PermissionKey>(CLIENT_PERMISSION_KEYS)

export function isClientPermission(key: PermissionKey): boolean {
  return CLIENT_PERMISSION_SET.has(key)
}

export const accessLevelValue: Record<AccessLevel, PermissionValue> = {
  read: 1,
  write: 2,
}

export const CLIENT_STOCK_ACTION_KEYS = ['stock.items.write', 'stock.fulfillment.write'] as const

export const CLIENT_BANK_ACTION_KEYS = ['bank.import', 'bank.match', 'bank.ignore'] as const

/** Client bank actions always include bank read access. Removing read removes the actions. */
export function setRolePermission(
  permissions: Record<string, PermissionValue>, roleType: 'staff' | 'client', key: string, level: PermissionValue,
): void {
  permissions[key] = roleType === 'client' && (key === 'bank' || key === 'stock' || key === 'reports.submissions') && level > 0 ? 1 : level
  if (roleType !== 'client') return
  if (key === 'reports.submissions' && level === 0) {
    permissions['reports.submit'] = 0
  } else if (key === 'reports.submit' && level > 0) {
    permissions['reports.submit'] = 2
    permissions['reports.submissions'] = 1
  }
  if (key === 'stock' && level === 0) {
    for (const action of CLIENT_STOCK_ACTION_KEYS) permissions[action] = 0
  } else if ((CLIENT_STOCK_ACTION_KEYS as readonly string[]).includes(key) && level > 0) {
    permissions.stock = Math.max(1, permissions.stock ?? 0) as PermissionValue
  }
  if (key === 'bank' && level === 0) {
    for (const action of CLIENT_BANK_ACTION_KEYS) permissions[action] = 0
  } else if ((CLIENT_BANK_ACTION_KEYS as readonly string[]).includes(key) && level > 0) {
    permissions.bank = Math.max(1, permissions.bank ?? 0) as PermissionValue
  }
}

export const OPERATIONAL_SETTINGS_KEYS = ['settings.email_profiles', 'settings.email_templates', 'settings.branding', 'settings.payment_qr', 'settings.signing_profiles'] as const satisfies readonly PermissionKey[]

/** Úrovně nabízené editorem respektují omezení klientských agend a samostatných akcí. */
export function rolePermissionLevels(roleType: 'staff' | 'client', key: string): readonly PermissionValue[] {
  if (key === 'reports.submissions') return [0, 1]
  if (key === 'reports.submissions.manage' || (roleType === 'client' && key === 'reports.submit')) return [0, 2]
  if ((OPERATIONAL_SETTINGS_KEYS as readonly string[]).includes(key)) return [0, 2]
  if (roleType === 'client' && (key === 'bank' || key === 'stock')) return [0, 1]
  if (roleType === 'client' && (CLIENT_STOCK_ACTION_KEYS as readonly string[]).includes(key)) return [0, 2]
  if (roleType === 'client' && (CLIENT_BANK_ACTION_KEYS as readonly string[]).includes(key)) return [0, 2]
  return [0, 1, 2]
}
