import { readFile, realpath } from 'node:fs/promises';
import { isAbsolute, relative, resolve, sep } from 'node:path';
import { config, hash, isMain, knowledgeDir, parseDocument } from './core.ts';
import { KnowledgeDatabase, type Chunk } from './database.ts';
import { chunkText, loadEngine, type EmbeddingEngine } from './model.ts';

export async function indexFile(file: string, db: KnowledgeDatabase, engine: EmbeddingEngine, directory = knowledgeDir): Promise<number> {
  const root = await realpath(directory);
  const target = await realpath(file);
  const path = relative(root, target);
  if (isAbsolute(path) || path === '..' || path.startsWith(`..${sep}`) || !path.endsWith('.md')) { throw new Error('Memória fora da pasta autorizada.'); }
  const document = await readFile(target, 'utf8');
  const parsed = parseDocument(document);
  const contentHash = hash(JSON.stringify({ model: config.model, revision: config.revision, dtype: 'q8', chunkTokens: config.chunkTokens, overlapTokens: config.overlapTokens, agent: parsed.agent, lessons: parsed.lessons }));
  if (db.indexedHash(path) === contentHash) { return 0; }
  const chunks: Chunk[] = [];
  for (const lesson of parsed.lessons) {
    for (const content of chunkText(lesson.content, engine.tokens)) {
      chunks.push({ content, category: lesson.category, embedding: await engine.embed(content) });
    }
  }
  db.replace(path, parsed.agent, contentHash, chunks);
  return chunks.length;
}

if (isMain(import.meta.url)) {
  const file = process.argv[2];
  if (!file) { throw new Error('Informe o arquivo .md em /knowledge.'); }
  const db = new KnowledgeDatabase(resolve(knowledgeDir, 'rag.db'));
  try { process.stdout.write(JSON.stringify({ chunks: await indexFile(file, db, await loadEngine()) })); }
  finally { db.close(); }
}
