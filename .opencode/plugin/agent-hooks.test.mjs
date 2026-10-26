import { test, after } from 'node:test'
import assert from 'node:assert/strict'
import { mkdtemp, writeFile, readFile, unlink, rmdir } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { AgentHooks } from './agent-hooks.ts'

// Executar em node:24-alpine. O motor PowerShell real tem sua propria bateria.
const temporary = await mkdtemp(join(tmpdir(), 'walle-plugin-'))
const executable = join(temporary, 'powershell')
const log = join(temporary, 'calls')
await writeFile(executable, '#!/bin/sh\nprintf "%s\\n" "$*" >> "$WALLE_PLUGIN_TEST_LOG"\ncat >> "$WALLE_PLUGIN_TEST_LOG"\nprintf "\\n" >> "$WALLE_PLUGIN_TEST_LOG"\nexit "${WALLE_PLUGIN_TEST_EXIT:-0}"\n', { mode: 0o700 })
const originalPath = process.env.PATH
process.env.PATH = `${temporary}:${originalPath}`
process.env.WALLE_PLUGIN_TEST_LOG = log
after(async () => {
  process.env.PATH = originalPath
  delete process.env.WALLE_PLUGIN_TEST_LOG
  delete process.env.WALLE_PLUGIN_TEST_EXIT
  await unlink(executable)
  await unlink(log)
  await rmdir(temporary)
})

const create = agent => AgentHooks({
  directory: process.cwd(),
  client: { session: { messages: async () => ({ data: [{ info: { role: 'assistant', agent } }] }) } },
})

test('identifica cada agent e encaminha PreToolUse sem interpolar o comando no shell', async () => {
  const policies = JSON.parse(await readFile('.claude/hooks/agent-policies.json', 'utf8'))
  for (const agent of Object.keys(policies)) {
    const plugin = await create(agent)
    await plugin['tool.execute.before']({ sessionID: 's', callID: 'c', tool: 'bash' }, { args: { command: 'git push; echo perigo' } })
    const calls = await readFile(log, 'utf8')
    assert.ok(calls.includes(`-Agent ${agent} -Event PreToolUse`))
    assert.ok(calls.includes('"command":"git push; echo perigo"'))
  }
})

test('propaga exit 2 como bloqueio antes de executar ferramenta', async () => {
  const plugin = await create('walle-dados')
  process.env.WALLE_PLUGIN_TEST_EXIT = '2'
  try {
    await assert.rejects(plugin['tool.execute.before']({ sessionID: 's', callID: 'c', tool: 'bash' }, { args: { command: 'git push' } }), /exit 2/)
  } finally { delete process.env.WALLE_PLUGIN_TEST_EXIT }
})

test('isola identidades por chamada e valida edicao e conclusao', async () => {
  const plugin = await create('walle-telas')
  await plugin['tool.execute.before']({ sessionID: 's', callID: 'edit', tool: 'edit' }, { args: { filePath: 'app/Livewire/Sentinela.php' } })
  await plugin['tool.execute.after']({ sessionID: 's', callID: 'edit', tool: 'edit' })
  await plugin['experimental.text.complete']({ sessionID: 's' })
  const calls = await readFile(log, 'utf8')
  assert.ok(calls.includes('-Agent walle-telas -Event PostToolUse'))
  assert.ok(calls.includes('-Agent walle-telas -Event Stop'))
  process.env.WALLE_PLUGIN_TEST_EXIT = '2'
  try { await assert.rejects(plugin['experimental.text.complete']({ sessionID: 's' }), /exit 2/) }
  finally { delete process.env.WALLE_PLUGIN_TEST_EXIT }
})

test('bloqueia identidade ausente e perfil walle desconhecido', async () => {
  for (const agent of [undefined, 'walle-desconhecido']) {
    const plugin = await create(agent)
    await assert.rejects(plugin['tool.execute.before']({ sessionID: 's', callID: 'c', tool: 'bash' }, { args: {} }))
  }
})

test('repassa tool_input ao PostToolUse e stop_hook_active depois de um Stop recusado', async () => {
  const plugin = await create('walle-robos')
  await plugin['tool.execute.before']({ sessionID: 'r', callID: 'w', tool: 'write' }, { args: { filePath: 'app/Sefaz/Exemplo.php' } })
  await plugin['tool.execute.after']({ sessionID: 'r', callID: 'w', tool: 'write' })
  process.env.WALLE_PLUGIN_TEST_EXIT = '2'
  try { await assert.rejects(plugin['experimental.text.complete']({ sessionID: 'r' }), /exit 2/) }
  finally { delete process.env.WALLE_PLUGIN_TEST_EXIT }
  await plugin['experimental.text.complete']({ sessionID: 'r' })
  const calls = await readFile(log, 'utf8')
  assert.match(calls, /-Event PostToolUse\n\{"tool_name":"write","tool_input":\{"filePath":"app\/Sefaz\/Exemplo\.php"\}/)
  assert.ok(calls.includes('"stop_hook_active":true'))
})

test('encaminha PostToolUse do walle-harness e nao roda Stop', async () => {
  const plugin = await create('walle-harness')
  await plugin['tool.execute.before']({ sessionID: 'h', callID: 'e', tool: 'edit' }, { args: { filePath: 'CLAUDE.md' } })
  await plugin['tool.execute.after']({ sessionID: 'h', callID: 'e', tool: 'edit' })
  await plugin['experimental.text.complete']({ sessionID: 'h' })
  const calls = await readFile(log, 'utf8')
  assert.ok(calls.includes('-Agent walle-harness -Event PostToolUse'))
  assert.ok(!calls.includes('-Agent walle-harness -Event Stop'))
})
