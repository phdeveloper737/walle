import { mkdir, readFile, rename, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { randomUUID } from 'node:crypto';
import { buildSummary, hash, isMain, knowledgeDir, localTimestamp, parseDocument, renderSummary, slug, stdinText, type CaptureInput } from './core.ts';

export async function summarize(input: CaptureInput, directory = knowledgeDir): Promise<string | null> {
  const summary = buildSummary(input);
  if (!summary.lessons.length) { return null; }
  const timestamp = localTimestamp();
  const name = `${timestamp.slice(0, 10)}-${summary.agent}-${slug(summary.sessionId).slice(0, 20)}-${summary.scope}.md`;
  const file = join(directory, name);
  await mkdir(directory, { recursive: true, mode: 0o700 });
  const document = renderSummary(summary, timestamp);
  try {
    const previous = await readFile(file, 'utf8');
    if (parseDocument(previous).revision > summary.revision) { return file; }
    if (hash(previous.replace(/^created_at: .*$/m, '')) === hash(document.replace(/^created_at: .*$/m, ''))) { return file; }
  } catch (error) {
    if ((error as NodeJS.ErrnoException).code !== 'ENOENT') { throw error; }
  }
  const temporary = `${file}.${randomUUID()}.tmp`;
  await writeFile(temporary, document, { mode: 0o600 });
  await rename(temporary, file);
  return file;
}

if (isMain(import.meta.url)) {
  const text = await stdinText();
  let input: CaptureInput;
  try {
    const parsed: unknown = JSON.parse(text);
    input = parsed && typeof parsed === 'object' && ('context' in parsed || 'messages' in parsed) ? parsed as CaptureInput : { context: text };
  } catch { input = { context: text }; }
  const file = await summarize(input);
  process.stdout.write(JSON.stringify({ path: file }));
}
