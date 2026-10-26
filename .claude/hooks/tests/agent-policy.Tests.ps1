[Console]::InputEncoding = New-Object Text.UTF8Encoding $false
$ErrorActionPreference = 'Stop'
$raiz = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../..'))
$script:casos = 0
$bancoAnterior = $env:WALLE_HOOK_TEST_DATABASE
Remove-Item Env:WALLE_HOOK_TEST_DATABASE -ErrorAction SilentlyContinue

function Rodar([string]$Script, [string]$Argumentos, [string]$Payload) {
    $inicio = New-Object Diagnostics.ProcessStartInfo
    $inicio.FileName = 'powershell'
    $inicio.Arguments = '-NoProfile -ExecutionPolicy Bypass -File "' + $raiz + '/.claude/hooks/' + $Script + '"' + $Argumentos
    $inicio.UseShellExecute = $false
    $inicio.CreateNoWindow = $true
    $inicio.RedirectStandardInput = $true
    $inicio.RedirectStandardOutput = $true
    $inicio.RedirectStandardError = $true
    $processo = [Diagnostics.Process]::Start($inicio)
    $processo.StandardInput.Write($Payload)
    $processo.StandardInput.Close()
    $saida = $processo.StandardOutput.ReadToEnd() + $processo.StandardError.ReadToEnd()
    $processo.WaitForExit()
    return @{ codigo = $processo.ExitCode; saida = $saida }
}
function Conferir([string]$Agent, [string]$Tool, [hashtable]$Dados, [int]$Esperado, [string]$Evento = 'PreToolUse', [hashtable]$Extra = @{}) {
    $corpo = @{ tool_name = $Tool; tool_input = $Dados }
    foreach ($item in $Extra.GetEnumerator()) { $corpo[$item.Key] = $item.Value }
    $resultado = Rodar "$Agent/$Evento.ps1" '' ($corpo | ConvertTo-Json -Compress)
    if ($resultado.codigo -ne $Esperado) { throw "${Agent}/${Evento}/${Tool} $($Dados | ConvertTo-Json -Compress): esperado $Esperado, recebido $($resultado.codigo): $($resultado.saida)" }
    if ($Esperado -eq 2 -and $resultado.saida -notmatch 'Bloqueado pelo hook') { throw 'Bloqueio sem mensagem clara.' }
    $script:casos++
}
# Hook global: o agent vem do agent_type do evento, nao do caminho do wrapper. Payload cru para testar evento invalido.
function ConferirGlobal([string]$Payload, [int]$Esperado, [string]$Evento = 'PreToolUse') {
    $resultado = Rodar 'politica-global.ps1' " -Event $Evento" $Payload
    if ($resultado.codigo -ne $Esperado) { throw "global/${Evento} ${Payload}: esperado $Esperado, recebido $($resultado.codigo): $($resultado.saida)" }
    if ($Esperado -eq 2 -and $resultado.saida -notmatch 'Bloqueado pelo hook') { throw 'Bloqueio sem mensagem clara.' }
    $script:casos++
}
function EventoGlobal([string]$AgentType, [string]$Tool, [hashtable]$Dados) {
    $corpo = @{ tool_name = $Tool; tool_input = $Dados }
    if ($AgentType) { $corpo.agent_type = $AgentType }
    return ($corpo | ConvertTo-Json -Compress)
}
function Marcador([string]$Agent) { Join-Path ([IO.Path]::GetTempPath()) "walle-hooks/${Agent}__.editou" }

# Leitura e orquestrador: so leem.
foreach ($agent in @('walle-arquiteto','walle-explorador-220','walle-orquestrador')) {
    foreach ($tool in @('Write','Edit','Bash')) { Conferir $agent $tool @{command='git status';file_path='app/Models/Cliente.php'} 2 }
    Conferir $agent 'Read' @{file_path='PLAN.md'} 0
}

# Verificador: le e roda validadores, nunca edita.
foreach ($comando in @('docker exec walle-app ./vendor/bin/pest','docker exec -e DB_DATABASE=walle_testes_t32 walle-app ./vendor/bin/pest tests/Feature/Contingencias --stop-on-failure','docker exec walle-app ./vendor/bin/pint --test','docker exec walle-app ./vendor/bin/pint --test app/Sefaz/ChaveDeAcesso.php','docker exec walle-app ./vendor/bin/phpstan analyse --no-progress','git status','powershell -NoProfile -ExecutionPolicy Bypass -File .claude/hooks/gate.ps1','powershell -NoProfile -ExecutionPolicy Bypass -File .claude/hooks/gate.ps1 -Banco walle_testes_t32')) {
    Conferir 'walle-verificador' 'Bash' @{command=$comando} 0
}
# Gate: so completo (sem escolher etapas), so em banco de testes e so pelo verificador.
foreach ($comando in @('powershell -NoProfile -ExecutionPolicy Bypass -File .claude/hooks/gate.ps1 -Banco walle','powershell -NoProfile -ExecutionPolicy Bypass -File .claude/hooks/gate.ps1 -Etapas pint','powershell -NoProfile -ExecutionPolicy Bypass -File .claude/hooks/gate.ps1 -Saida x.json','powershell -NoProfile -ExecutionPolicy Bypass -File .claude/hooks/gate.ps1; git push')) {
    Conferir 'walle-verificador' 'Bash' @{command=$comando} 2
}
foreach ($agent in @('walle-robos','walle-dados','walle-harness','walle-orquestrador')) {
    Conferir $agent 'Bash' @{command='powershell -NoProfile -ExecutionPolicy Bypass -File .claude/hooks/gate.ps1'} 2
}
foreach ($comando in @('git push','docker exec walle-app ./vendor/bin/pest; git push','docker exec walle-app ./vendor/bin/pest --bootstrap=malicioso.php','docker exec walle-app php artisan migrate:fresh','docker exec -e DB_DATABASE=walle walle-app ./vendor/bin/pest','docker exec walle-app ./vendor/bin/pint app/Sefaz/ChaveDeAcesso.php','docker exec walle-app ./vendor/bin/pint --dirty','docker exec walle-app php artisan make:model Exemplo','docker run --rm -v C:/docker/walle:/app -w /app node:24-alpine npm run build')) {
    Conferir 'walle-verificador' 'Bash' @{command=$comando} 2
}
Conferir 'walle-verificador' 'Write' @{file_path='tests/Feature/Paridade/ExemploTest.php'} 2
foreach ($tool in @('Read','Grep','Glob')) { Conferir 'walle-verificador' $tool @{file_path='PLAN.md'} 0 }
Conferir 'walle-verificador' 'mcp__laravel-boost__database-query' @{} 2

# Implementadores.
$permitidos = @{
    'walle-dados'='app/Models/Exemplo.php'; 'walle-robos'='app/Sefaz/Exemplo.php'
    'walle-infra'='tests/Pest.php'; 'walle-seguranca'='app/Livewire/Concerns/ConfirmaSenha.php'
    'walle-telas'='resources/css/app.css'; 'walle-paridade'='tests/Fixtures/legado/sentinela/amostra.json'
}
foreach ($agent in $permitidos.Keys) {
    Conferir $agent 'Write' @{file_path=$permitidos[$agent]} 0
    foreach ($arquivo in @('.claude/hooks/agent-policy.ps1','../testes220/newsuporte/index.php','app/Models/../../.env','composer.json','PLAN.md','SPEC.md','docs/features/x/relatorio-tecnico.md')) { Conferir $agent 'Edit' @{file_path=$arquivo} 2 }
    foreach ($comando in @('git push','git commit -am teste','docker exec walle-app php artisan tinker','docker exec walle-mysql mysql','docker exec walle-app ./vendor/bin/pest && git push','docker exec walle-app php artisan make:provider ExemploProvider')) { Conferir $agent 'Bash' @{command=$comando} 2 }
    Conferir $agent 'Bash' @{command='docker exec walle-app ./vendor/bin/pest tests/Feature/Contingencias'} 0
    Conferir $agent 'Bash' @{command='docker exec walle-app ./vendor/bin/pint --dirty --format agent'} 0
}

# Donos novos e exclusoes.
$dominio = @(
    @('walle-robos','app/Autorizadas/RoboAutorizadas.php',0), @('walle-robos','tests/Unit/ChaveDeAcessoTest.php',0),
    @('walle-seguranca','app/Models/Concerns/SomenteLeitura.php',0), @('walle-seguranca','config/database.php',0), @('walle-seguranca','app/Livewire/Conversor.php',0),
    @('walle-telas','app/Livewire/Concerns/ConfirmaSenha.php',2), @('walle-telas','app/Livewire/Conversor.php',2),
    @('walle-telas','resources/views/livewire/conversor.blade.php',2), @('walle-telas','resources/views/livewire/saude.blade.php',2),
    @('walle-telas','app/Livewire/Sentinela.php',0), @('walle-telas','tests/Feature/EntrarTest.php',0), @('walle-telas','app/Concorrentes/AnaliseConcorrentes.php',0),
    @('walle-dados','database/factories/ExemploFactory.php',0), @('walle-dados','app/Models/Concerns/SomenteLeitura.php',2),
    @('walle-infra','tests/Support/ServidorSshFalso.php',0), @('walle-infra','app/Support/Texto.php',0), @('walle-infra','app/Support/HelpDesk/SenhaHelpDesk.php',2),
    @('walle-paridade','tests/Feature/Paridade/SentinelaParidadeTest.php',0), @('walle-paridade','app/Sentinela/RoboSentinela.php',2)
)
foreach ($caso in $dominio) { Conferir $caso[0] 'Write' @{file_path=$caso[1]} $caso[2] }

# make: so dentro do dominio.
$makes = @(
    @('walle-dados','make:model Exemplo',0), @('walle-dados','make:migration create_exemplo_table --create=exemplo',0),
    @('walle-dados','make:factory ExemploFactory',0), @('walle-dados','make:model Exemplo --migration',2), @('walle-dados','make:class Sefaz/Exemplo',2),
    @('walle-robos','make:class Sefaz/Exemplo',0), @('walle-robos','make:job RodarCicloExemplo',0), @('walle-robos','make:test Sefaz/ExemploTest --pest',0),
    @('walle-telas','make:test Autorizadas/ExemploTest --pest',0), @('walle-telas','make:test ExemploTest --pest --unit',2), @('walle-telas','make:model Exemplo --pest',2),
    @('walle-infra','make:listener RegistrarExemplo',0),
    @('walle-paridade','make:test Paridade/SentinelaParidadeTest --pest',0), @('walle-paridade','make:class Paridade/Exemplo',2)
)
foreach ($caso in $makes) { Conferir $caso[0] 'Bash' @{command=('docker exec walle-app php artisan ' + $caso[1])} $caso[2] }

# npm so para telas; --dirty bloqueado em sessao paralela.
Conferir 'walle-telas' 'Bash' @{command='docker run --rm -v C:/docker/walle:/app -w /app node:24-alpine npm run build'} 0
Conferir 'walle-robos' 'Bash' @{command='docker run --rm -v C:/docker/walle:/app -w /app node:24-alpine npm run build'} 2
$env:WALLE_HOOK_TEST_DATABASE = 'walle_testes_t01'
try { Conferir 'walle-robos' 'Bash' @{command='docker exec walle-app ./vendor/bin/pint --dirty'} 2 }
finally { Remove-Item Env:WALLE_HOOK_TEST_DATABASE }

# walle-harness: so definicoes de agents, commands, skills e regras; nunca hooks, politicas, settings ou plugin.
foreach ($arquivo in @('.claude/agents/walle-dados.md','.claude/agents/walle-orquestrador.md','.opencode/agents/walle-telas.md','.claude/commands/nova-feature.md','.opencode/commands/corrigir-bug.md','.claude/skills/tela-walle-ui/SKILL.md','.ai/guidelines/walle.md','CLAUDE.md','AGENTS.md')) {
    Conferir 'walle-harness' 'Write' @{file_path=$arquivo} 0
}
foreach ($arquivo in @('.claude/agents/walle-harness.md','.opencode/agents/walle-harness.md','.claude/agents/outro.md','.claude/hooks/agent-policies.json','.claude/hooks/agent-policy.ps1','.claude/hooks/walle-dados/PreToolUse.ps1','.claude/settings.json','.opencode/plugin/agent-hooks.ts','.opencode/plugin/agent-hooks.test.mjs','opencode.json','PLAN.md','SPEC.md','docs/x.md','app/Models/Usuario.php','../testes220/newsuporte/index.php')) {
    Conferir 'walle-harness' 'Edit' @{file_path=$arquivo} 2
}
foreach ($comando in @('powershell -NoProfile -ExecutionPolicy Bypass -File .claude/hooks/tests/agent-policy.Tests.ps1','docker run --rm -v C:/docker/walle:/app -w /app node:24-alpine node --test .opencode/plugin/agent-hooks.test.mjs','git status','git diff --stat')) {
    Conferir 'walle-harness' 'Bash' @{command=$comando} 0
}
foreach ($comando in @('docker exec walle-app ./vendor/bin/pest','docker exec walle-app ./vendor/bin/pint --dirty','powershell -NoProfile -File .claude/hooks/agent-policy.ps1','git commit -am x','docker exec walle-app php artisan make:class Exemplo')) {
    Conferir 'walle-harness' 'Bash' @{command=$comando} 2
}
Conferir 'walle-harness' 'mcp__laravel-boost__database-query' @{} 2
# PostToolUse confere o arquivo gravado.
foreach ($arquivo in @('.claude/agents/walle-dados.md','.claude/agents/walle-paridade.md','.claude/agents/walle-arquiteto.md','.claude/agents/walle-verificador.md','.claude/agents/walle-orquestrador.md','.opencode/agents/walle-dados.md','.claude/commands/nova-feature.md','.opencode/commands/orquestrar.md','CLAUDE.md')) {
    Conferir 'walle-harness' 'Edit' @{file_path=$arquivo} 0 'PostToolUse'
}
$agenteFalso = Join-Path $raiz '.claude/agents/walle-teste-harness.md'
$commandFalso = Join-Path $raiz '.claude/commands/teste-harness.md'
try {
    [IO.File]::WriteAllText($agenteFalso, "---`nname: walle-teste-harness`ntools: Bash`n---`n")
    [IO.File]::WriteAllText($commandFalso, "---`nallowed-tools: Bash`n---`n")
    Conferir 'walle-harness' 'Write' @{file_path='.claude/agents/walle-teste-harness.md'} 2 'PostToolUse'
    Conferir 'walle-harness' 'Write' @{file_path='.claude/commands/teste-harness.md'} 2 'PostToolUse'
} finally {
    Remove-Item -LiteralPath $agenteFalso -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $commandFalso -ErrorAction SilentlyContinue
}

# walle-migracao: so a lista fixa de exportacao e importacao do Docker; nunca edita, apaga volume nem roda comando composto.
foreach ($comando in @('docker volume ls','docker ps -a','docker compose stop','docker compose --profile dev stop','docker compose -f C:/docker/testes220/docker-compose.yml start','docker compose -f .claude/scripts/compose.yml up -d','docker run --rm -v walle_mysql:/origem:ro -v C:/walle-migracao:/backup ubuntu tar --numeric-owner -czpf /backup/volume-walle_mysql.tar.gz -C /origem .','docker save -o C:/walle-migracao/imagens.tar walle-php testes220 walle-rag:local mysql:9.7 valkey/valkey:9 nginx:stable-alpine node:24-alpine ubuntu','tar -a -c -f C:/walle-migracao/pastas.zip --exclude walle/vendor --exclude walle/node_modules -C C:/docker walle testes220','Get-FileHash -Algorithm SHA256 C:/walle-migracao/volume-walle_mysql.tar.gz','docker load -i C:/walle-migracao/imagens.tar','docker volume create --label com.docker.compose.project=walle --label com.docker.compose.volume=mysql walle_mysql','docker exec walle-app composer install --no-interaction')) {
    Conferir 'walle-migracao' 'PowerShell' @{command=$comando} 0
}
foreach ($comando in @('docker volume rm walle_mysql','docker compose down -v','docker compose build','docker system prune -a','git push','docker volume ls; docker volume rm walle_mysql','docker run --rm -v walle_mysql:/origem:ro -v C:/walle-migracao:/backup ubuntu tar --numeric-owner -czpf /backup/volume-walle_valkey.tar.gz -C /origem .','docker run --rm -v walle_mysql:/origem -v C:/:/backup ubuntu sh','docker exec walle-app ./vendor/bin/pest','Get-Content C:/docker/walle/.env','docker volume create walle_mysql')) {
    Conferir 'walle-migracao' 'PowerShell' @{command=$comando} 2
}
foreach ($tool in @('Write','Edit')) { Conferir 'walle-migracao' $tool @{file_path='app/Models/Cliente.php'} 2 }
Conferir 'walle-migracao' 'Read' @{file_path='docker-compose.yml'} 0
Conferir 'walle-migracao' 'mcp__laravel-boost__database-query' @{} 2
ConferirGlobal (EventoGlobal 'walle-migracao' 'PowerShell' @{command='docker volume ls'}) 0
ConferirGlobal (EventoGlobal 'walle-migracao' 'PowerShell' @{command='docker volume rm walle_mysql'}) 2

# Hook global: falha fechada para evento ilegivel e agent sem politica.
foreach ($payload in @('nao e json', '', '{}', '{"tool_input":{"file_path":"PLAN.md"}}')) { ConferirGlobal $payload 2 }
foreach ($agentType in @('walle-inexistente', 'general-purpose', 'Explore')) {
    foreach ($tool in @('Read', 'Write', 'Bash')) { ConferirGlobal (EventoGlobal $agentType $tool @{file_path='PLAN.md';command='git status'}) 2 }
}
# Sem agent_type vale o "agent" do settings.json ou, sem ele, o orquestrador: so le.
#ConferirGlobal (EventoGlobal '' 'Read' @{file_path='PLAN.md'}) 0
#ConferirGlobal (EventoGlobal '' 'Write' @{file_path='PLAN.md'}) 2
#ConferirGlobal (EventoGlobal '' 'Bash' @{command='git status --short'}) 2
# Sem agent_type e a sessao principal do chat: livre.
ConferirGlobal (EventoGlobal '' 'Read' @{file_path='PLAN.md'}) 0
ConferirGlobal (EventoGlobal '' 'Write' @{file_path='PLAN.md'}) 0
ConferirGlobal (EventoGlobal '' 'Bash' @{command='git status --short'}) 0

# Com agent_type, a politica daquele agent.
ConferirGlobal (EventoGlobal 'walle-harness' 'Bash' @{command='git status --short'}) 0
ConferirGlobal (EventoGlobal 'walle-harness' 'Bash' @{command='git log -1 --oneline'}) 2
ConferirGlobal (EventoGlobal 'walle-harness' 'Write' @{file_path='teste-bloqueio-harness.txt'}) 2
ConferirGlobal (EventoGlobal 'walle-harness' 'Write' @{file_path='.claude/hooks/agent-policies.json'}) 2
ConferirGlobal (EventoGlobal 'walle-harness' 'Write' @{file_path='.claude/agents/walle-dados.md'}) 0
ConferirGlobal (EventoGlobal 'walle-orquestrador' 'Bash' @{command='git status --short'}) 2
ConferirGlobal (EventoGlobal 'walle-arquiteto' 'Edit' @{file_path='PLAN.md'}) 2
ConferirGlobal (EventoGlobal 'walle-dados' 'Write' @{file_path='app/Models/Exemplo.php'}) 0
ConferirGlobal (EventoGlobal 'walle-dados' 'Write' @{file_path='app/Sefaz/Exemplo.php'}) 2
ConferirGlobal (EventoGlobal 'walle-verificador' 'Write' @{file_path='tests/Feature/Paridade/ExemploTest.php'}) 2
# PostToolUse global confere o harness; agent sem politica continua bloqueado.
ConferirGlobal (EventoGlobal 'walle-harness' 'Edit' @{file_path='.claude/agents/walle-dados.md'}) 0 'PostToolUse'
ConferirGlobal (EventoGlobal 'general-purpose' 'Edit' @{file_path='PLAN.md'}) 2 'PostToolUse'
$agenteFalso = Join-Path $raiz '.claude/agents/walle-teste-harness.md'
try {
    [IO.File]::WriteAllText($agenteFalso, "---`nname: walle-teste-harness`ntools: Bash`n---`n")
    ConferirGlobal (EventoGlobal 'walle-harness' 'Write' @{file_path='.claude/agents/walle-teste-harness.md'}) 2 'PostToolUse'
} finally {
    Remove-Item -LiteralPath $agenteFalso -ErrorAction SilentlyContinue
}

$temporario = Join-Path ([IO.Path]::GetTempPath()) ('walle-hooks-' + [guid]::NewGuid().ToString('N'))
[IO.Directory]::CreateDirectory($temporario) | Out-Null
$pathAnterior = $env:PATH
try {
    # Duplo do Docker: comprova propagacao de falhas sem executar suite por caso.
    [IO.File]::WriteAllText((Join-Path $temporario 'docker.cmd'), "@echo off`r`necho %*`r`nexit /b %WALLE_TEST_DOCKER_EXIT%`r`n")
    $env:PATH = $temporario + ';' + $env:PATH
    foreach ($agent in $permitidos.Keys) {
        Remove-Item -LiteralPath (Marcador $agent) -ErrorAction SilentlyContinue
        $semTestes = $agent -eq 'walle-paridade' -and -not (Test-Path -LiteralPath (Join-Path $raiz 'tests/Feature/Paridade'))
        $esperadoPost = if ($semTestes) { 2 } else { 0 }
        $env:WALLE_TEST_DOCKER_EXIT = '1'
        Conferir $agent '' @{} 0 'Stop'                                    # sem edicao, nao roda a suite
        Conferir $agent 'Bash' @{command='git status'} 0 'PostToolUse'     # Bash nao dispara testes
        $env:WALLE_TEST_DOCKER_EXIT = '0'
        Conferir $agent 'Write' @{file_path=$permitidos[$agent]} $esperadoPost 'PostToolUse'
        Conferir $agent '' @{} 0 'Stop'
        $env:WALLE_TEST_DOCKER_EXIT = '1'
        Conferir $agent '' @{} 0 'Stop'                                    # Stop verde limpou a marca
        Conferir $agent 'Write' @{file_path=$permitidos[$agent]} 2 'PostToolUse'
        Conferir $agent '' @{} 2 'Stop'
        Conferir $agent '' @{} 0 'Stop' @{stop_hook_active=$true}          # segunda parada libera
        Conferir $agent '' @{} 0 'Stop'                                    # e limpa a marca
    }
    # PostToolUse global nao duplica os testes dos implementadores (o Docker falso falharia).
    $env:WALLE_TEST_DOCKER_EXIT = '1'
    ConferirGlobal (EventoGlobal 'walle-dados' 'Write' @{file_path='app/Models/Exemplo.php'}) 0 'PostToolUse'
    Conferir 'walle-robos' 'Bash' @{command='docker exec walle-app php artisan make:class Sefaz/Exemplo'} 0 'PostToolUse'
    Conferir 'walle-robos' '' @{} 2 'Stop'
    Conferir 'walle-robos' '' @{} 0 'Stop' @{stop_hook_active=$true}
    # walle-migracao: restauracao so em volume vazio (o Docker falso sempre devolve conteudo).
    $env:WALLE_TEST_DOCKER_EXIT = '0'
    Conferir 'walle-migracao' 'PowerShell' @{command='docker run --rm -v walle_mysql:/destino -v C:/walle-migracao:/backup:ro ubuntu tar --numeric-owner -xzpf /backup/volume-walle_mysql.tar.gz -C /destino'} 2
} finally {
    $env:PATH = $pathAnterior
    Remove-Item Env:WALLE_TEST_DOCKER_EXIT -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath (Join-Path $temporario 'docker.cmd')
    Remove-Item -LiteralPath $temporario
    if ($bancoAnterior) { $env:WALLE_HOOK_TEST_DATABASE = $bancoAnterior }
}
Write-Output "$script:casos casos de politica passaram."
