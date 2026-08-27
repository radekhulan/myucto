export const PERMISSION_KEYS = [
  'dashboard', 'dashboard.portfolio',
  'clients', 'clients.create', 'clients.archive', 'clients.public_links',
  'projects', 'projects.create', 'projects.archive',
  'invoices', 'invoices.create', 'invoices.issue', 'invoices.send',
  'invoices.reminder', 'invoices.mark_paid', 'invoices.cancel', 'invoices.clone',
  'invoices.delete', 'invoices.approval',
  'purchase_invoices', 'purchase_invoices.create', 'purchase_invoices.transition',
  'purchase_invoices.scan', 'purchase_invoices.payment_orders', 'purchase_invoices.delete',
  'recurring', 'recurring.create', 'recurring.run', 'recurring.pause', 'recurring.delete',
  'bank', 'bank.import', 'bank.match', 'bank.post', 'bank.unpost', 'bank.rules',
  'documents', 'documents.upload', 'documents.move', 'documents.delete',
  'documents.restore', 'documents.requests', 'documents.inbox', 'documents.inbox.delete',
  'documents.submit',
  'accounting', 'accounting.journal.write', 'accounting.journal.post',
  'accounting.periods.manage', 'accounting.periods.close', 'accounting.periods.close_override', 'accounting.offsets',
  'accounting.templates',
  'tax_evidence', 'tax_evidence.classification.write', 'tax_evidence.export',
  'reports', 'reports.finalize', 'reports.submit', 'reports.reopen', 'reports.export',
  'payroll', 'payroll.settings', 'payroll.person.read_sensitive', 'payroll.person.write',
  'payroll.employment.write', 'payroll.time.write', 'payroll.inputs.write',
  'payroll.calculate', 'payroll.review', 'payroll.approve', 'payroll.reopen',
  'payroll.post', 'payroll.payments', 'payroll.submissions', 'payroll.enforcement', 'payroll.enforcement.cooperation',
  'payroll.insolvency', 'payroll.reports', 'payroll.rulesets', 'payroll.documents',
  'payroll.retention', 'payroll.erasure', 'payroll.health_evidence',
  'cash', 'cash.document.write', 'cash.close',
  'assets', 'assets.write', 'assets.depreciation', 'assets.dispose',
  'stock', 'stock.items.write', 'stock.documents.write', 'stock.orders.write', 'stock.vendors.write',
  'stock.take', 'stock.close',
  'eshop', 'eshop.write',
  'logbook', 'logbook.write', 'logbook.import', 'logbook.delete',
  'settings.company', 'settings.company.write', 'settings.domains', 'settings.bank_accounts',
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
  'purchase_invoices.delete',
  'recurring', 'recurring.create', 'recurring.run', 'recurring.pause', 'recurring.delete',
  'documents.submit', 'settings.company', 'profile',
] as const satisfies readonly PermissionKey[]

const CLIENT_PERMISSION_SET = new Set<PermissionKey>(CLIENT_PERMISSION_KEYS)

export function isClientPermission(key: PermissionKey): boolean {
  return CLIENT_PERMISSION_SET.has(key)
}

export const accessLevelValue: Record<AccessLevel, PermissionValue> = {
  read: 1,
  write: 2,
}
