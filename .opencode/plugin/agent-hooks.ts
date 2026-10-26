import { spawn } from 'node:child_process'
import { readFile } from 'node:fs/promises'
import { join } from 'node:path'

// Politica unica para Claude Code e OpenCode, sem executar texto do modelo no shell.
export const AgentHooks = async ({ client, directory }) => {
  const policies = JSON.parse(await readFile(join(directory, '.claude/hooks/agent-policies.json'), 'utf8'))
  const calls = new Map<string, { agent: string, args: unknown }>()
  const stopRecusado = new Set<string>()

  const agentFor = async (sessionID: string) => {
    const response = await client.session.messages({ path: { id: sessionID } })
    if (response.error || !Array.isArray(response.data)) throw new Error('Nao foi possivel identificar o agent da sessao; ferramenta bloqueada.')
    const assistant = [...response.data].reverse().find(message => message.info.role === 'assistant')
    const agent = assistant?.info.agent
    if (!agent) throw new Error('Sessao sem agent identificavel; ferramenta bloqueada.')
    if (agent.startsWith('walle-') && !policies[agent]) throw new Error(`Agent sem politica: ${agent}`)
    return agent
  }

  const run = (agent: string, event: string, payload: Record<string, unknown>) => new Promise<void>((resolve, reject) => {
    const child = spawn('powershell', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
      join(directory, '.claude/hooks/agent-policy.ps1'), '-Agent', agent, '-Event', event],
      { cwd: directory, windowsHide: true, shell: false, stdio: ['pipe', 'pipe', 'pipe'] })
    let output = ''
    child.stdout.on('data', chunk => { output = (output + chunk).slice(-12000) })
    child.stderr.on('data', chunk => { output = (output + chunk).slice(-12000) })
    child.on('error', reject)
    const timeout = setTimeout(() => { child.kill(); reject(new Error('Timeout na validacao do hook; validacao pendente.')) }, 1_800_000)
    child.on('close', code => {
      clearTimeout(timeout)
      if (code === 0) resolve()
      else reject(new Error(output || `Hook de ${agent} bloqueou ${event} (exit ${code}).`))
    })
    child.stdin.on('error', reject)
    child.stdin.end(JSON.stringify(payload))
  })

  return {
    'tool.execute.before': async (input, output) => {
      const agent = await agentFor(input.sessionID)
      if (!policies[agent]) return
      await run(agent, 'PreToolUse', { tool_name: input.tool, tool_input: output.args, session_id: input.sessionID })
      calls.set(`${input.sessionID}:${input.callID}`, { agent, args: output.args })
    },
    'tool.execute.after': async (input) => {
      const key = `${input.sessionID}:${input.callID}`
      const call = calls.get(key)
      calls.delete(key)
      if (!call) return
      if (['implementacao', 'harness'].includes(policies[call.agent].tipo)) {
        await run(call.agent, 'PostToolUse', { tool_name: input.tool, tool_input: call.args, session_id: input.sessionID })
      }
    },
    // OpenCode nao tem Stop com exit 2. Validamos antes de entregar o texto final;
    // depois de uma recusa, a proxima conclusao vai com stop_hook_active para nao travar em loop.
    'experimental.text.complete': async (input) => {
      const agent = await agentFor(input.sessionID)
      if (policies[agent]?.tipo !== 'implementacao') return
      try {
        await run(agent, 'Stop', { tool_name: '', tool_input: {}, session_id: input.sessionID, stop_hook_active: stopRecusado.has(input.sessionID) })
        stopRecusado.delete(input.sessionID)
      } catch (erro) {
        stopRecusado.add(input.sessionID)
        throw erro
      }
    },
  }
}
