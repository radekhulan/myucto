import {
  ApiError, HOSTED_MAX_FILE_BYTES, MyUctoClient, ReadOnlyError,
} from './client.mjs';
import { validateToolArguments } from './tool-args.mjs';
import { toolResult } from './tool-result.mjs';
import { TOOLS, TOOLS_BY_NAME } from './tools.mjs';
import { VERSION } from './version.mjs';

export function hostedError(error) {
  const message = error instanceof ApiError
    ? `HTTP ${error.status} (${error.code}): ${error.message}`
    : error.message;
  return {
    content: [{ type: 'text', text: message }],
    isError: true,
  };
}

/**
 * Společné jádro serverového MCP. Liší se jen cesta, kterou se volá API:
 * `hosted-bridge.mjs` spouští PHP CLI sám, `hosted-relay.mjs` předává požadavek
 * zpět PHP procesu, který ho spustil.
 */
export async function runHosted(input, fetcher) {
  try {
    const readOnly = input.scope !== 'read_write';
    const boundSupplierId = Number.isSafeInteger(input.boundSupplierId) && input.boundSupplierId > 0
      ? input.boundSupplierId : null;
    const lockedSupplierId = Number.isSafeInteger(input.lockedSupplierId) && input.lockedSupplierId > 0
      ? input.lockedSupplierId : null;
    const withoutCompany = new Set(['whoami', 'list_suppliers']);
    const allowed = Array.isArray(input.allowedTools) ? new Set(input.allowedTools) : null;

    if (input.operation === 'list') {
      const exposed = TOOLS.filter((tool) => (!readOnly || !tool.write) && (allowed === null || allowed.has(tool.name)));
      const cursor = input.cursor === undefined ? 0 : Number(input.cursor);
      if (cursor !== 0) {
        throw new Error('Neplatný kurzor seznamu nástrojů.');
      }
      return { tools: exposed.map((tool) => ({
        name: tool.name,
        title: tool.title,
        description: tool.write
          ? `${tool.description}\n\n⚠️ Tento nástroj MĚNÍ DATA v ostré instanci.`
            + (tool.destructive ? ' Jde o NEVRATNOU operaci; vyžaduje `confirm: true`.' : '')
          : tool.description,
        inputSchema: boundSupplierId !== null || withoutCompany.has(tool.name)
          ? tool.inputSchema
          : {
              ...tool.inputSchema,
              properties: {
                ...tool.inputSchema.properties,
                supplier_id: {
                  type: 'integer', minimum: 1,
                  description: 'ID firmy ze seznamu list_suppliers. Práva se ověřují při každém volání.',
                },
              },
              required: [...(tool.inputSchema.required ?? []), 'supplier_id'],
            },
        annotations: {
          title: tool.title,
          readOnlyHint: !tool.write,
          destructiveHint: Boolean(tool.destructive),
          idempotentHint: !tool.write,
          openWorldHint: true,
        },
      })) };
    }
    if (input.operation === 'call') {
      const tool = TOOLS_BY_NAME.get(input.name);
      if (allowed !== null && !allowed.has(input.name)) throw new Error('Pro tento nástroj nemáte oprávnění.');
      if (!tool) throw new Error(`Neznámý nástroj "${input.name}".`);
      if (tool.write && readOnly) throw new ReadOnlyError(input.name);

      let args = input.arguments ?? {};
      if (Array.isArray(args) && args.length === 0) args = {};
      if (args === null || typeof args !== 'object' || Array.isArray(args)) {
        throw new Error('Neplatné argumenty nástroje.');
      }
      const supplierId = boundSupplierId ?? args.supplier_id;
      if (boundSupplierId !== null && args.supplier_id !== undefined
        && args.supplier_id !== boundSupplierId) {
        throw new Error('Toto připojení je omezené na původně schválenou firmu. Pro přístup k dalším firmám je znovu připojte.');
      }
      if (!withoutCompany.has(tool.name) && !Number.isSafeInteger(supplierId)) {
        throw new Error('Zadejte supplier_id ze seznamu list_suppliers.');
      }
      if (!withoutCompany.has(tool.name) && supplierId < 1) {
        throw new Error('Zadejte platné supplier_id ze seznamu list_suppliers.');
      }
      if (lockedSupplierId !== null && !withoutCompany.has(tool.name) && supplierId !== lockedSupplierId) {
        throw new Error('Tato doména je omezená na jinou firmu.');
      }
      const { supplier_id: _supplierId, ...rawArguments } = args;
      const toolArguments = validateToolArguments(tool, rawArguments);

      const client = new MyUctoClient({
        baseUrl: input.apiUrl,
        token: input.token,
        supplierId: withoutCompany.has(tool.name) ? (lockedSupplierId ?? boundSupplierId) : supplierId,
        readOnly,
        maxConcurrent: 1,
        maxFileBytes: HOSTED_MAX_FILE_BYTES,
        version: VERSION,
        fetcher,
      });
      return toolResult(await tool.run(client, toolArguments, input.name));
    }
    throw new Error('Neznámá operace MCP mostu.');
  } catch (error) {
    return hostedError(error);
  }
}
