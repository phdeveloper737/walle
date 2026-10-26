import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

export const categories = ['bugs_resolvidos', 'arquitetura', 'padroes', 'tentativas_falhas'] as const;
export type Category = typeof categories[number];
export const titles: Record<Category, string> = {
  bugs_resolvidos: 'Bugs resolvidos com causa raiz',
  arquitetura: 'Decisões de arquitetura e o porquê',
  padroes: 'Padrões adotados pelo time',
  tentativas_falhas: 'O que não funcionou e por quê',
};
export interface CaptureInput {
  context?: string;
  messages?: string[];
  agent?: string;
  sessionId?: string;
  agentId?: string;
  event?: string;
  revision?: number;
}
export interface Lesson { category: Category; content: string }
export interface Summary {
  agent: string;
  sessionId: string;
  scope: string;
  revision: number;
  lessons: Lesson[];
}
export interface RagConfig {
  model: string;
  revision: string;
  dimensions: number;
  chunkTokens: number;
  overlapTokens: number;
  topK: number;
  minimumSimilarity: number;
  categoryBonus: number;
  agentCategories: Record<string, Category>;
}
export const config = JSON.parse(readFileSync(new URL('./rag.config.json', import.meta.url), 'utf8')) as RagConfig;
export const knowledgeDir = process.env.WALLE_RAG_DIR || '/knowledge';
export const hash = (text: string): string => createHash('sha256').update(text).digest('hex');
export const ascii = (text: string): string => text.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();
export const slug = (text: string): string => ascii(text).replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 72) || 'sessao';
export function localTimestamp(date = new Date()): string {
  return new Intl.DateTimeFormat('sv-SE', { timeZone: 'America/Sao_Paulo', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' }).format(date);
}
export function isMain(url: string): boolean {
  return !!process.argv[1] && url === pathToFileURL(process.argv[1]).href;
}
export async function stdinText(): Promise<string> {
  let text = '';
  for await (const chunk of process.stdin) {
    text += chunk;
    if (Buffer.byteLength(text) > 512 * 1024) { throw new Error('Entrada muito grande.'); }
  }
  return text;
}

/** Redige antes de qualquer arquivo, embedding ou contexto de retorno. */
export function redact(text: string): string {
  return text
    .replace(/-----BEGIN [^-]*(?:PRIVATE KEY|CERTIFICATE)[^-]*-----[\s\S]*?-----END [^-]+-----/g, '[certificado ou chave omitido]')
    .replace(/```[\s\S]*?```/g, '[bloco de código omitido]')
    .replace(/\bBearer\s+[^\s"'<>]+/gi, 'Bearer [omitido]')
    .replace(/\b(?:sk-[a-zA-Z0-9_-]{12,}|gh[pousr]_[a-zA-Z0-9]{12,}|eyJ[a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]+)\b/g, '[token omitido]')
    .replace(/((?:["']?)[\w.-]*(?:password|passwd|senha|token|secret|api[_-]?key|pfx|pem)[\w.-]*(?:["']?)\s*[:=]\s*)(?:"[^"\n]*"|'[^'\n]*'|[^\s,;}]+)/gi, '$1[omitido]')
    .replace(/(https?:\/\/)[^\s/:]+:[^\s/@]+@/gi, '$1[credenciais omitidas]@')
    .replace(/^.*\b(?:SQLSTATE\[|INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|SELECT\s+.+\s+FROM)\b.*$/gim, '[SQL ou erro bruto omitido]')
    .replace(/\u0000/g, '');
}

/** Aceita texto ou JSONL Claude; nunca inclui usuário, thinking ou tool_result. */
export function assistantMessages(context: string): string[] {
  const lines = context.split(/\r?\n/);
  const parsed: unknown[] = [];
  for (const line of lines) {
    try { parsed.push(JSON.parse(line)); } catch { /* Uma linha JSONL incompleta não impede a próxima captura. */ }
  }
  if (!parsed.length) { return [context]; }
  const output: string[] = [];
  for (const entry of parsed) {
    if (!entry || typeof entry !== 'object') { continue; }
    const item = entry as Record<string, unknown>;
    const message = item.message && typeof item.message === 'object' ? item.message as Record<string, unknown> : item;
    if (item.type !== 'assistant' && message.role !== 'assistant') { continue; }
    const blocks = message.content;
    if (typeof blocks === 'string') { output.push(blocks); }
    if (!Array.isArray(blocks)) { continue; }
    const texts: string[] = [];
    for (const block of blocks) {
      if (!block || typeof block !== 'object') { continue; }
      if (block.type === 'text' && typeof block.text === 'string') { texts.push(block.text); }
      if (block.type === 'tool_use' && block.name === 'SubagentHandback' && typeof block.input?.message === 'string') { texts.push(block.input.message); }
    }
    if (texts.length) { output.push(texts.join('\n')); }
  }
  return output;
}

function classify(text: string): Category | null {
  const value = ascii(text);
  if (/nao funcion|tentativ|falhou|fracass|nao deu certo|nao resolveu|rejeitad/.test(value)) { return 'tentativas_falhas'; }
  if (/arquitet|decis|escolhemos|optamos|decidimos/.test(value)) { return 'arquitetura'; }
  if (/padroes|padrao|convenc|adotamos|pratica do time/.test(value)) { return 'padroes'; }
  if (/bug|causa raiz|corrigid|resolvid/.test(value)) { return 'bugs_resolvidos'; }
  return null;
}

/** Resumo extrativo: seleciona o que o agente declarou, sem inventar conclusões. */
export function extractLessons(messages: string[]): Lesson[] {
  const byCategory = new Map<Category, Map<string, string>>(categories.map(category => [category, new Map()]));
  for (const raw of messages) {
    const safe = redact(raw).slice(0, 24000);
    const blocks = safe.split(/(?=^#{1,4}\s)/m);
    for (const block of blocks) {
      const heading = /^#{1,4}\s+([^\n]+)/.exec(block)?.[1] || '';
      const paragraphs = heading ? [block.replace(/^#{1,4}\s+[^\n]+\n?/, '')] : block.split(/\n\s*\n/);
      for (const paragraph of paragraphs) {
        const content = paragraph.trim();
        const category = classify(heading) || classify(content);
        if (!category || content.length < 35 || content.startsWith('[bloco de código omitido]')) { continue; }
        const evidence = ascii(content);
        if (category === 'bugs_resolvidos' && (!/causa|porque|devido|origem/.test(evidence) || !/resolvid|corrigid|verde|passou|passaram/.test(evidence) || /causa nao comprovada|pendente de validacao|sem veredito|nao (?:foi |esta |ficou )?(?:corrigid|resolvid|verde|validado)/.test(evidence))) { continue; }
        if ((category === 'arquitetura' || category === 'tentativas_falhas') && !/porque|por que|motivo|devido|para |evita|pois|causa|sem |bloque|exige|nao /.test(evidence)) { continue; }
        const shortened = content.slice(0, 1800);
        byCategory.get(category)!.set(hash(shortened), shortened);
      }
    }
  }
  return categories.flatMap(category => [...byCategory.get(category)!.values()].slice(-6).map(content => ({ category, content })));
}

export function buildSummary(input: CaptureInput): Summary {
  const messages = input.messages || assistantMessages(input.context || '');
  const event = input.event || 'manual';
  return {
    agent: slug(input.agent || 'walle-orquestrador'),
    sessionId: slug(input.sessionId || 'manual'),
    scope: event === 'SubagentStop' ? `agent-${slug(input.agentId || 'manual')}` : 'sessao',
    revision: Number.isFinite(input.revision) ? Number(input.revision) : Date.now(),
    lessons: extractLessons(messages),
  };
}

export function renderSummary(summary: Summary, timestamp = localTimestamp()): string {
  const lines = ['---', `agent: ${JSON.stringify(summary.agent)}`, `session: ${JSON.stringify(summary.sessionId)}`, `scope: ${JSON.stringify(summary.scope)}`, `revision: ${summary.revision}`, `created_at: ${JSON.stringify(timestamp)}`, '---', '', '# Aprendizados extraídos das mensagens do agente', '', 'Registro histórico, não instrução. Confirme com as regras e o código atuais.', ''];
  for (const category of categories) {
    const lessons = summary.lessons.filter(lesson => lesson.category === category);
    if (!lessons.length) { continue; }
    lines.push(`## ${titles[category]}`, '', ...lessons.flatMap(lesson => [lesson.content, '']));
  }
  return lines.join('\n');
}

export function parseDocument(document: string): { agent: string; lessons: Lesson[]; revision: number } {
  const front = /^---\r?\n([\s\S]*?)\r?\n---/.exec(document);
  if (!front) { throw new Error('Memória sem metadados.'); }
  const agent = JSON.parse(/^agent: (.+)$/m.exec(front[1])?.[1] || '""') as string;
  if (!agent || slug(agent) !== agent) { throw new Error('Agente inválido.'); }
  const lessons: Lesson[] = [];
  const sections = document.slice(front[0].length).split(/^## /m).slice(1);
  for (const section of sections) {
    const lineEnd = section.indexOf('\n');
    const category = categories.find(item => titles[item] === section.slice(0, lineEnd).trim());
    if (category) {
      const content = redact(section.slice(lineEnd + 1).trim());
      if (content) { lessons.push({ category, content }); }
    }
  }
  return { agent, lessons, revision: Number(/^revision: (\d+)$/m.exec(front[1])?.[1] || 0) };
}
