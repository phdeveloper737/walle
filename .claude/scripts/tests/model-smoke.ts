import assert from 'node:assert/strict';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { loadEngine, chunkText } from '../model.ts';
import { KnowledgeDatabase } from '../database.ts';
import { summarize } from '../summarize.ts';
import { indexFile } from '../embed.ts';
import { search, contextFor } from '../search.ts';
import { workerSmoke } from './worker-smoke.ts';

const directory = await mkdtemp(join(tmpdir(), 'walle-rag-smoke-'));
const db = new KnowledgeDatabase(':memory:');
try {
  const engine = await loadEngine();
  const embedding = await engine.embed('SQLite local vector search');
  assert.equal(embedding.length, 384);
  assert.ok(Math.abs([...embedding].reduce((sum, value) => sum + value * value, 0) - 1) < 0.001);
  const text = 'Usamos um índice SQLite para buscar aprendizados locais e manter o projeto independente de APIs externas. '.repeat(35);
  const chunks = chunkText(text, engine.tokens);
  assert.ok(chunks.every(chunk => engine.tokens(chunk).length >= 200 && engine.tokens(chunk).length <= 250));
  const file = (await summarize({ agent: 'walle-arquiteto', sessionId: 'smoke-isolado', messages: ['## Decisões de arquitetura e o porquê\nDecidimos usar SQLite local para indexar aprendizados porque isso mantém a busca independente de APIs externas.'] }, directory))!;
  assert.equal(await indexFile(file, db, engine, directory), 1);
  const matches = await search('SQLite local para indexar aprendizados', 'walle-arquiteto', db, engine);
  assert.equal(matches.length, 1);
  assert.ok(contextFor(matches).includes('SQLite local'));
  console.log('OK: MiniLM real, 384 dimensões, normalização, tokenizer real, SQLite/sqlite-vec e busca ponta a ponta em memória isolada.');
  await workerSmoke(engine);
} finally { db.close(); await rm(directory, { recursive: true, force: true }); }
