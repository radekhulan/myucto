import { api } from './client'

export interface ApiToken {
  id: number
  supplier_id: number | null
  supplier_name: string | null
  supplier_company: string | null
  name: string
  prefix: string
  scope: 'read' | 'read_write'
  /** Smí v Dokumentech vidět evidenci mzdových podání (doručenky, protokoly). */
  allow_payroll_submission_docs: boolean
  last_used_at: string | null
  last_used_ip: string | null
  expires_at: string | null
  revoked_at: string | null
  created_at: string
  is_revoked: boolean
  is_expired: boolean
  /** Počet pravidel IP allowlistu. 0 = token funguje z libovolné adresy. */
  ip_rule_count: number
}

export interface TokenIpRule {
  id: number
  /** IPv4/IPv6 adresa nebo CIDR rozsah, např. `192.168.1.0/24`, `2001:db8::/32`. */
  cidr: string
  note: string
  created_at: string
}

export interface ApiLogEntry {
  id: number
  token_id: number | null
  token_name: string | null
  supplier_id: number | null
  ts: string
  ip: string | null
  method: string
  route: string
  query: string
  status: number
  duration_ms: number
  scope_used: string
  /** `mcp` u volání z MCP serveru, prázdné u přímé integrace. */
  client: string
  client_version: string
  /** Název MCP nástroje, který volání vyvolal. */
  tool: string
  error_code: string
}

export interface ApiLogFilter {
  token_id?: number
  method?: string
  route?: string
  client?: string
  only_errors?: boolean
  limit?: number
  offset?: number
}

export interface CreateTokenPayload {
  name: string
  /** Re-auth současným heslem — backend ho vyžaduje vždy (step-up, CreateTokenAction). */
  password: string
  supplier_id?: number | null
  scope: 'read' | 'read_write'
  expires_at?: string | null
  /** Bez expirace — jen vědomá volba, pro read_write navíc jen superadmin. */
  never_expires?: boolean
  /**
   * Odemkne tokenu evidenci mzdových PODÁNÍ v Dokumentech (doručenky,
   * protokoly ČSSZ, odpovědi pojišťoven). Ostatní mzdová evidence — zdraví,
   * exekuce, insolvence, výplatní pásky — zůstává jen pro přihlášenou relaci.
   */
  allow_payroll_submission_docs?: boolean
  totp_code?: string
  step_up_token?: string
}

export interface CreateTokenResult {
  token: string
  id: number
  prefix: string
  warning: string
}

export const tokensApi = {
  list: () => api.get<{ tokens: ApiToken[] }>('/auth/tokens').then((r) => r.data.tokens),

  create: (payload: CreateTokenPayload) =>
    api.post<CreateTokenResult>('/auth/tokens', payload).then((r) => r.data),

  revoke: (id: number) => api.delete(`/auth/tokens/${id}`),

  /**
   * Trvalé smazání. Zrušení nechá v přehledu náhrobek a v api-logu celou
   * historii volání; tohle uklidí i náhrobek a volání ztratí přiřazení
   * k tokenu. Nevratné.
   */
  purge: (id: number) => api.delete(`/auth/tokens/${id}/purge`),

  // ── IP allowlist tokenu — prázdný seznam znamená bez omezení ──────────────
  listIps: (tokenId: number) =>
    api.get<{ rules: TokenIpRule[] }>(`/auth/tokens/${tokenId}/ips`).then((r) => r.data.rules),

  addIp: (tokenId: number, cidr: string, note: string) =>
    api
      .post<{ rules: TokenIpRule[] }>(`/auth/tokens/${tokenId}/ips`, { cidr, note })
      .then((r) => r.data.rules),

  deleteIp: (tokenId: number, ruleId: number) =>
    api
      .delete<{ rules: TokenIpRule[] }>(`/auth/tokens/${tokenId}/ips/${ruleId}`)
      .then((r) => r.data.rules),

  // ── Log volání veřejného API vlastními tokeny ─────────────────────────────
  log: (filter: ApiLogFilter = {}) =>
    api
      .get<{ entries: ApiLogEntry[]; total: number; limit: number; offset: number }>(
        '/auth/api-log',
        { params: filter },
      )
      .then((r) => r.data),
}
