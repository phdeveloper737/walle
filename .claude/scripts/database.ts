import { DatabaseSync } from 'node:sqlite';
import { readFileSync } from 'node:fs';
import * as sqliteVec from 'sqlite-vec';
import { config, localTimestamp, type Category } from './core.ts';

export interface Chunk { content: string; category: Category; embedding: Float32Array }
export interface Match { id: number; path: string; content: string; category: Category; agent: string; distance: number; similarity: number }
export function vectorBytes(vector: Float32Array): Uint8Array {
  if (vector.length !== config.dimensions || [...vector].some(value => !Number.isFinite(value))) { throw new Error('Embedding inválido.'); }
  return new Uint8Array(vector.buffer, vector.byteOffset, vector.byteLength);
}

export class KnowledgeDatabase {
  readonly db: DatabaseSync;
  constructor(path: string) {
    this.db = new DatabaseSync(path, { allowExtension: true, timeout: 5000 });
    sqliteVec.load(this.db);
    this.db.enableLoadExtension(false);
    this.db.exec(readFileSync(new URL('./schema.sql', import.meta.url), 'utf8'));
  }
  indexedHash(path: string): string | undefined {
    return this.db.prepare('SELECT content_hash FROM documents WHERE path = ?').get(path)?.content_hash as string | undefined;
  }
  replace(path: string, agent: string, contentHash: string, chunks: Chunk[]): void {
    const timestamp = localTimestamp();
    this.db.exec('BEGIN IMMEDIATE');
    try {
      this.db.prepare('DELETE FROM knowledge_vec WHERE rowid IN (SELECT id FROM knowledge WHERE path = ?)').run(path);
      this.db.prepare('DELETE FROM knowledge WHERE path = ?').run(path);
      const insert = this.db.prepare('INSERT INTO knowledge(path,content,embedding,category,agent,created_at) VALUES (?,?,?,?,?,?)');
      const insertVector = this.db.prepare('INSERT INTO knowledge_vec(rowid,embedding) VALUES (?,?)');
      for (const chunk of chunks) {
        const bytes = vectorBytes(chunk.embedding);
        const result = insert.run(path, chunk.content, bytes, chunk.category, agent, timestamp);
        insertVector.run(BigInt(result.lastInsertRowid), bytes);
      }
      this.db.prepare('INSERT INTO documents(path,content_hash,indexed_at) VALUES (?,?,?) ON CONFLICT(path) DO UPDATE SET content_hash=excluded.content_hash,indexed_at=excluded.indexed_at').run(path, contentHash, timestamp);
      this.db.exec('COMMIT');
    } catch (error) {
      this.db.exec('ROLLBACK');
      throw error;
    }
  }
  nearest(vector: Float32Array, preferred?: Category): Match[] {
    const count = Number(this.db.prepare('SELECT count(*) AS n FROM knowledge').get()!.n);
    if (!count) { return []; }
    const rows = this.db.prepare(`SELECT k.*, v.distance FROM knowledge_vec v JOIN knowledge k ON k.id=v.rowid
      WHERE v.embedding MATCH ? AND v.k = ? ORDER BY v.distance`).all(vectorBytes(vector), BigInt(Math.min(count, 24)));
    const matches = rows.map(row => ({
      id: Number(row.id), path: String(row.path), content: String(row.content), category: row.category as Category,
      agent: String(row.agent), distance: Number(row.distance), similarity: 1 - Number(row.distance) ** 2 / 2,
    })).filter(row => row.similarity >= config.minimumSimilarity);
    matches.sort((a, b) => (b.similarity + (b.category === preferred ? config.categoryBonus : 0)) - (a.similarity + (a.category === preferred ? config.categoryBonus : 0)) || b.id - a.id);
    const unique = new Map<string, Match>();
    for (const match of matches) { if (!unique.has(match.content)) { unique.set(match.content, match); } }
    return [...unique.values()].slice(0, Math.min(config.topK, 3));
  }
  close(): void { this.db.close(); }
}
