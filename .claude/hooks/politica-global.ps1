# Hook global do walle (settings.json): aplica a politica central a qualquer agent, sem depender dos hooks do frontmatter.
# Falha fechada: evento ilegivel ou agent sem politica bloqueia (exit 2). Sem acentos: o PowerShell 5.1 le este arquivo como ANSI.
param(
    [Parameter(Mandatory = $true)][ValidateSet('PreToolUse', 'PostToolUse')][string]$Event
)

$ErrorActionPreference = 'Stop'
function Bloquear([string]$Motivo) {
    [Console]::Error.WriteLine("Bloqueado pelo hook global do walle: $Motivo")
    exit 2
}

try {
    $json = [Console]::In.ReadToEnd()
    $entrada = $json | ConvertFrom-Json
} catch {
    Bloquear 'Evento ilegivel; ferramenta bloqueada.'
}
if (-not $entrada -or -not [string]$entrada.tool_name) { Bloquear 'Evento sem tool_name; ferramenta bloqueada.' }

#$agent = [string]$entrada.agent_type
#if (-not $agent) {
#    try {
#        $agent = [string](Get-Content -LiteralPath "$PSScriptRoot/../settings.json" -Raw | ConvertFrom-Json).agent
#    } catch {
#        Bloquear 'Nao foi possivel ler o settings.json para identificar o agent.'
#    }
#}
#if (-not $agent) { $agent = 'walle-orquestrador' }

# Agent pelo agent_type do evento; sem ele e a sessao principal do chat, que fica livre.
$agent = [string]$entrada.agent_type
# Sem agent_type e a sessao principal do chat: fica livre (o proteger-comandos.ps1 continua valendo).
if (-not $agent) { exit 0 }

try {
    $politicas = Get-Content -LiteralPath "$PSScriptRoot/agent-policies.json" -Raw | ConvertFrom-Json
} catch {
    Bloquear 'Nao foi possivel ler agent-policies.json.'
}
$politica = $politicas.PSObject.Properties[$agent].Value
if (-not $politica) { Bloquear "Agent $agent sem politica em agent-policies.json; so o usuario cadastra agents novos." }

# PostToolUse global so confere as edicoes do harness; os implementadores ja rodam os testes pelo frontmatter.
if ($Event -eq 'PostToolUse' -and $politica.tipo -ne 'harness') { exit 0 }

& "$PSScriptRoot/agent-policy.ps1" -Agent $agent -Event $Event -Payload $json
exit $LASTEXITCODE
