import { stdinText } from './core.ts';

/** Cliente curto para docker exec: não recarrega o modelo a cada hook. */
try {
  const mode = process.argv[2];
  if (mode !== 'capture' && mode !== 'search') { throw new Error('Modo inválido.'); }
  const response = await fetch(`http://127.0.0.1:7391/${mode}`, {
    method: 'POST', headers: { 'content-type': 'application/json' },
    body: await stdinText(), signal: AbortSignal.timeout(mode === 'search' ? 5500 : 10000),
  });
  if (!response.ok) { throw new Error('RAG indisponível.'); }
  const output = await response.json() as { context?: string };
  if (mode === 'search' && output.context) {
    process.stdout.write(JSON.stringify({ hookSpecificOutput: { hookEventName: 'UserPromptSubmit', additionalContext: output.context } }));
  }
} catch {
  // Memória é auxiliar: indisponibilidade nunca bloqueia o prompt ou a entrega.
  process.stderr.write('[rag] Consulta ou captura indisponível; sessão continua.\n');
}
