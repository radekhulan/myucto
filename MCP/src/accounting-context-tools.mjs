export const ACCOUNTING_CONTEXT_TOOLS = [
  {
    name: 'get_accounting_context',
    api: false,
    apiReason: 'Dosud nepřevedeno do klientského katalogu; zachovává stávající rozsah (FR #285).',
    title: 'Účetní kontext k datu',
    description: 'Účetní rámec, metoda, role účtů a datovaná metadata podle současné znalosti. Zachovejte complete, findings, temporal_source a null. Systémové dimenze poskytují pouze současné identity; jejich historické názvy a aktivita nejsou doloženy. Nástroj nic neinicializuje a nenahrazuje právní nebo daňové posouzení.',
    inputSchema: {
      type: 'object',
      properties: { date: { type: 'string', format: 'date', description: 'Povinné účetní datum YYYY-MM-DD, nejdříve 1900-01-01.' } },
      required: ['date'],
      additionalProperties: false,
    },
    write: false,
    run: (client, args, tool) => client.get('/accounting/catalog/context', { date: args.date }, tool),
  },
];
