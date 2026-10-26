import { createServer } from 'node:http';
import { mkdir, readdir } from 'node:fs/promises';
import { join } from 'node:path';
import { config, knowledgeDir, type CaptureInput } from './core.ts';
import { KnowledgeDatabase } from './database.ts';
import { loadEngine, type EmbeddingEngine } from './model.ts';
import { summarize } from './summarize.ts';
import { indexFile } from './embed.ts';
import { contextFor, search } from './search.ts';

await mkdir(knowledgeDir, { recursive: true, mode: 0o700 });
const db = new KnowledgeDatabase(join(knowledgeDir, 'rag.db'));
let engine: EmbeddingEngine | undefined;
let bootstrapped = false;
let lastFailure: string | null = null;
let capturing = Promise.resolve();
const pending = new Set<string>();
const failedIndex = new Set<string>();
let indexing = false;
const ready = (): boolean => bootstrapped && !!engine && failedIndex.size === 0;

async function drain(): Promise<void> {
  if (!engine || indexing) { return; }
  indexing = true;
  try {
    while (pending.size) {
      const file = pending.values().next().value!;
      pending.delete(file);
      try { await indexFile(file, db, engine); failedIndex.delete(file); lastFailure = null; }
      catch { failedIndex.add(file); }
    }
  } finally { indexing = false; }
}

function captureInput(value: Record<string, unknown>): CaptureInput {
  if (typeof value.agent !== 'string' || !value.agent || typeof value.sessionId !== 'string') { throw new Error('Metadados inválidos.'); }
  if (!Array.isArray(value.messages) || value.messages.some(item => typeof item !== 'string')) { throw new Error('Mensagens inválidas.'); }
  return { agent: value.agent, sessionId: value.sessionId, agentId: String(value.agentId || ''), event: String(value.event || 'manual'), revision: Number(value.revision) || Date.now(), messages: value.messages as string[] };
}

const server = createServer(async (request, response) => {
  const reply = (status: number, body: object): void => {
    response.writeHead(status, { 'content-type': 'application/json; charset=utf-8' });
    response.end(JSON.stringify(body));
  };
  if (request.method === 'GET' && request.url === '/health') {
    reply(200, { model: config.model, ready: ready(), pending: pending.size + Number(indexing), failure: failedIndex.size ? 'indexacao_pendente' : lastFailure,
      chunks: Number(db.db.prepare('SELECT count(*) AS n FROM knowledge').get()!.n),
      documents: Number(db.db.prepare('SELECT count(*) AS n FROM documents').get()!.n) });
    return;
  }
  if (request.method !== 'POST' || !['/capture', '/search'].includes(request.url || '')) { reply(404, {}); return; }
  try {
    let body = '';
    for await (const chunk of request) {
      body += chunk;
      if (Buffer.byteLength(body) > 384 * 1024) { reply(413, {}); return; }
    }
    const input = JSON.parse(body) as Record<string, unknown>;
    if (request.url === '/search') {
      if (typeof input.question !== 'string' || typeof input.agent !== 'string') { reply(400, {}); return; }
      const matches = ready() && engine ? await search(input.question, input.agent, db, engine) : [];
      reply(200, { context: contextFor(matches), chunks: matches.length });
      return;
    }
    const capture = captureInput(input);
    let file: string | null = null;
    const operation = capturing.then(async () => { file = await summarize(capture); });
    capturing = operation.catch(() => {});
    await operation;
    if (file) { pending.add(file); void drain(); }
    reply(202, { captured: !!file });
  } catch {
    lastFailure = 'entrada_ou_captura_invalida';
    reply(400, { error: 'Não foi possível processar a entrada do RAG.' });
  }
});
server.requestTimeout = 10000;
server.listen(7391, '127.0.0.1');

// Apenas o download inicial do modelo usa a rede. Textos não são enviados a APIs.
void loadEngine().then(async loaded => {
  const files = await readdir(knowledgeDir);
  engine = loaded;
  for (const file of files) { if (file.endsWith('.md')) { pending.add(join(knowledgeDir, file)); } }
  await drain();
  bootstrapped = true;
}).catch(() => { lastFailure = 'modelo_indisponivel'; });

const stop = (): void => { server.close(() => { db.close(); process.exit(0); }); };
process.on('SIGTERM', stop);
process.on('SIGINT', stop);
