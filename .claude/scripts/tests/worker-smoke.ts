import assert from 'node:assert/strict';
import { spawn, type ChildProcess } from 'node:child_process';
import { once } from 'node:events';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';
import { fileURLToPath } from 'node:url';
import { buildSummary, renderSummary } from '../core.ts';
import { KnowledgeDatabase } from '../database.ts';
import type { EmbeddingEngine } from '../model.ts';

interface Health { ready: boolean; failure: string | null; pending: number; documents: number; chunks: number }
const base = 'http://127.0.0.1:7391';
async function waitFor(child: ChildProcess, condition: (health: Health) => boolean): Promise<Health> {
  const deadline = Date.now() + 45000;
  while (Date.now() < deadline) {
    if (child.exitCode !== null || child.signalCode !== null) { throw new Error('Worker encerrou antes da verificação.'); }
    try {
      const health = await (await fetch(`${base}/health`, { signal: AbortSignal.timeout(2000) })).json() as Health;
      if (condition(health)) { return health; }
    } catch { /* O servidor pode estar iniciando. */ }
    await delay(100);
  }
  throw new Error('Worker não atingiu o estado esperado.');
}
async function stop(child: ChildProcess): Promise<void> {
  if (child.exitCode !== null || child.signalCode !== null) { return; }
  const closed = once(child, 'exit');
  const timer = setTimeout(() => child.kill('SIGKILL'), 5000);
  child.kill('SIGTERM');
  try { await closed; } finally { clearTimeout(timer); }
}
async function request(path: string, input: object): Promise<Response> {
  return fetch(`${base}/${path}`, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(input), signal: AbortSignal.timeout(10000) });
}

export async function workerSmoke(engine: EmbeddingEngine): Promise<void> {
  const directory = await mkdtemp(join(tmpdir(), 'walle-rag-worker-'));
  const source = join(directory, 'nota.md');
  const question = 'SQLite local para armazenar memórias';
  const db = new KnowledgeDatabase(join(directory, 'rag.db'));
  db.replace('nota.md', 'walle-arquiteto', 'modelo-anterior', [{ content: 'MEMORIA_ANTIGA', category: 'arquitetura', embedding: await engine.embed(question) }]);
  db.close();
  await writeFile(source, 'Nota inválida sem metadados.');
  const start = (): ChildProcess => spawn(process.execPath, [fileURLToPath(new URL('../worker.ts', import.meta.url))], { env: { ...process.env, WALLE_RAG_DIR: directory }, stdio: 'ignore' });
  let child = start();
  try {
    await waitFor(child, health => health.failure === 'indexacao_pendente');
    const blocked = await (await request('search', { question, agent: 'walle-arquiteto' })).json() as { context: string };
    assert.equal(blocked.context, '', 'Não deve recuperar vetor antigo quando a reindexação falha.');
    await stop(child);
    await writeFile(source, renderSummary(buildSummary({ agent: 'walle-arquiteto', messages: ['## Decisões de arquitetura e o porquê\nDecidimos usar SQLite local para armazenar memórias porque o projeto não deve depender de APIs externas.'] })));
    child = start();
    await waitFor(child, health => health.ready && health.pending === 0);
    const result = await (await request('search', { question, agent: 'walle-arquiteto' })).json() as { context: string; chunks: number };
    assert.ok(result.context.includes('SQLite local'));
    assert.ok(!result.context.includes('MEMORIA_ANTIGA'));
    assert.equal(result.chunks, 1);
    assert.equal((await request('capture', { agent: 'walle-dados', sessionId: 'smoke', event: 'SubagentStop', agentId: 'a1', messages: ['## Padrões adotados pelo time\nO padrão adotado pelo time usa scripts Docker e testes isolados para os aprendizados locais.'] })).status, 202);
    await waitFor(child, health => health.ready && health.documents === 2 && health.pending === 0);
    assert.equal((await request('capture', { messages: [{}] })).status, 400);
    const final = await waitFor(child, health => health.ready);
    assert.equal(final.documents, 2);
    console.log('OK: worker real, captura assíncrona, recuperação, reinício e bloqueio de vetores antigos após falha de reindexação.');
  } finally { await stop(child); await rm(directory, { recursive: true, force: true }); }
}
