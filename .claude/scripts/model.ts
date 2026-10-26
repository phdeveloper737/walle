import { env, pipeline } from '@huggingface/transformers';
import { config } from './core.ts';

export interface EmbeddingEngine {
  tokens(text: string): number[];
  embed(text: string): Promise<Float32Array>;
}
interface Extractor {
  (text: string, options: { pooling: string; normalize: boolean }): Promise<{ data: ArrayLike<number> }>;
  tokenizer: { encode(text: string, options: { add_special_tokens: boolean }): number[] };
}

export async function loadEngine(): Promise<EmbeddingEngine> {
  env.cacheDir = process.env.WALLE_RAG_CACHE || '/models';
  const extractor = await pipeline('feature-extraction', config.model, {
    revision: config.revision,
    dtype: 'q8', device: 'cpu', session_options: { intraOpNumThreads: 2, interOpNumThreads: 1 },
  }) as unknown as Extractor;
  let inference: Promise<unknown> = Promise.resolve();
  return {
    tokens: text => extractor.tokenizer.encode(text, { add_special_tokens: false }),
    embed: async text => {
      const request = inference.then(() => extractor(text, { pooling: 'mean', normalize: true }));
      inference = request.catch(() => {});
      const output = await request;
      const vector = Float32Array.from(output.data);
      if (vector.length !== config.dimensions) { throw new Error('Dimensão inesperada do modelo.'); }
      return vector;
    },
  };
}

/** Preserva o texto original; os limites usam o tokenizer do próprio MiniLM. */
export function chunkText(text: string, tokens: (text: string) => number[]): string[] {
  const words = text.match(/\S+\s*/g) || [];
  const weights = words.map(word => tokens(word).length);
  const chunks: string[] = [];
  let start = 0;
  while (start < words.length) {
    let end = start;
    let count = 0;
    while (end < words.length && (count + weights[end] <= config.chunkTokens || end === start)) { count += weights[end++]; }
    while (end > start + 1 && tokens(words.slice(start, end).join('')).length > config.chunkTokens) { end--; }
    let effectiveStart = start;
    if (end === words.length) {
      while (effectiveStart > 0 && tokens(words.slice(effectiveStart - 1, end).join('')).length <= config.chunkTokens) { effectiveStart--; }
    }
    const content = words.slice(effectiveStart, end).join('').trim();
    if (tokens(content).length > config.chunkTokens) { throw new Error('Trecho individual excede o limite do modelo.'); }
    if (content && chunks.at(-1) !== content) { chunks.push(content); }
    if (end === words.length) { break; }
    start = end;
    let overlap = 0;
    while (start > effectiveStart + 1 && overlap + weights[start - 1] <= config.overlapTokens) { overlap += weights[--start]; }
  }
  return chunks;
}
