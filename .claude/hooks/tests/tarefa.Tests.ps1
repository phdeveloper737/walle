[Console]::InputEncoding = New-Object Text.UTF8Encoding $false
# Testes do ledger e do Git das tarefas (.claude/hooks/tarefa.ps1) num repositorio temporario, com um origin
# local (bare) e um gh falso no PATH: nada toca o repositorio real nem o GitHub.
# Sem acentos: o PowerShell 5.1 le este arquivo como ANSI.
$ErrorActionPreference = 'Stop'
$script = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../tarefa.ps1'))
$powershellReal = (Get-Command powershell -CommandType Application | Select-Object -First 1).Source
$script:casos = 0
$utf8 = New-Object Text.UTF8Encoding($false)

$temporario = Join-Path ([IO.Path]::GetTempPath()) ('walle-tarefa-tests-' + [guid]::NewGuid().ToString('N'))
$repo = Join-Path $temporario 'repo'
$origem = Join-Path $temporario 'origem.git'
$duplos = Join-Path $temporario 'duplos'
$logGh = Join-Path $temporario 'gh.log'
$pathAnterior = $env:PATH

function G([string[]]$Argumentos) {
    $preferencia = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { return "$(& git -C $repo @Argumentos 2>&1)".Trim() } finally { $ErrorActionPreference = $preferencia }
}
function Tarefa([string]$Acao, [string]$Id) {
    $argumentos = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $script, '-Acao', $Acao, '-Raiz', $repo)
    if ($Id) { $argumentos += @('-Tarefa', $Id) }
    $preferencia = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $texto = & $powershellReal @argumentos 2>&1 | ForEach-Object { [string]$_ }
        return @{ codigo = $LASTEXITCODE; saida = ($texto -join "`n") }
    } finally { $ErrorActionPreference = $preferencia }
}
function Local([string]$Id, [string]$Nome, [string]$Texto) {
    $pasta = Join-Path $repo ".walle/local/$Id"
    [IO.Directory]::CreateDirectory($pasta) | Out-Null
    [IO.File]::WriteAllText((Join-Path $pasta $Nome), $Texto, $utf8)
}
function Estado([string]$Id) { return [IO.File]::ReadAllText((Join-Path $repo ".walle/tarefas/$Id/estado.json"), $utf8) | ConvertFrom-Json }
function Garantir([bool]$Condicao, [string]$Mensagem) {
    if (-not $Condicao) { throw "tarefa: $Mensagem" }
    $script:casos++
}
function Espera([hashtable]$R, [int]$Codigo, [string]$Mensagem) { Garantir ($R.codigo -eq $Codigo) "$Mensagem (esperado $Codigo, recebido $($R.codigo)): $($R.saida)" }

try {
    # Repositorio com main publicado num origin local; gh falso que registra as chamadas.
    [IO.Directory]::CreateDirectory($repo) | Out-Null
    [IO.Directory]::CreateDirectory($duplos) | Out-Null
    & git init -q --bare -b main $origem
    & git init -q -b main $repo
    G @('config', 'user.email', 'teste@walle') | Out-Null
    G @('config', 'user.name', 'Teste') | Out-Null
    G @('config', 'core.autocrlf', 'false') | Out-Null
    [IO.File]::WriteAllText((Join-Path $repo '.gitignore'), "/.walle/local/`n/.walle/gate/`n", $utf8)
    [IO.File]::WriteAllText((Join-Path $repo 'LEIAME.md'), "repositorio de teste`n", $utf8)
    G @('add', '-A') | Out-Null
    G @('commit', '-q', '-m', 'inicio') | Out-Null
    G @('remote', 'add', 'origin', $origem) | Out-Null
    G @('push', '-q', '-u', 'origin', 'main') | Out-Null
    [IO.File]::WriteAllText((Join-Path $duplos 'gh.cmd'), (@(
        '@echo off'
        'echo %*>> "%WALLE_TEST_GH_LOG%"'
        'if "%1 %2"=="pr create" echo https://github.com/x/walle/pull/42& exit /b 0'
        'if "%1 %2"=="pr checks" exit /b %WALLE_TEST_GH_CHECKS%'
        'if "%1 %2"=="pr view" echo MERGED& exit /b 0'
        'exit /b 0'
    ) -join "`r`n") + "`r`n")
    $env:PATH = $duplos + ';' + $pathAnterior
    $env:WALLE_TEST_GH_LOG = $logGh
    $env:WALLE_TEST_GH_CHECKS = '0'

    # Numeracao e abertura.
    $r = Tarefa 'proxima'
    Garantir ($r.codigo -eq 0 -and $r.saida.Trim() -eq 'T-0001') "primeira tarefa e T-0001: $($r.saida)"
    Espera (Tarefa 'abrir' 'T-1') 2 'id fora do formato'
    Espera (Tarefa 'abrir' 'T-0001') 2 'abrir sem pedido.json'
    Local 'T-0001' 'pedido.json' '{"titulo": "Robo Sentinela", "origem": "PLAN 3.2", "tipo": "plano", "resumo": "teste"}'
    Espera (Tarefa 'abrir' 'T-0001') 0 'abrir com pedido'
    Garantir ((G @('branch', '--show-current')) -eq 'tarefa/T-0001-robo-sentinela') 'branch da tarefa com slug do titulo'
    $e = Estado 'T-0001'
    Garantir ($e.estado -eq 'em_execucao' -and $e.ciclos -eq 0 -and $e.origem -eq 'PLAN 3.2' -and @($e.eventos).Count -eq 1) 'estado inicial'
    Garantir (Test-Path -LiteralPath (Join-Path $repo '.walle/tarefas/T-0001/pedido.json')) 'pedido copiado para o ledger versionado'
    Espera (Tarefa 'abrir' 'T-0001') 2 'abrir duas vezes'
    Garantir ((Tarefa 'proxima').saida.Trim() -eq 'T-0002') 'proxima depois da aberta'

    # Commit: so com a mensagem do orquestrador; inclui o ledger; arvore limpa depois.
    [IO.File]::WriteAllText((Join-Path $repo 'robo.txt'), "codigo do robo`n", $utf8)
    Espera (Tarefa 'commitar' 'T-0001') 2 'commitar sem commit.txt'
    Local 'T-0001' 'commit.txt' "Robo Sentinela: coleta`n`nCorpo da mensagem."
    Espera (Tarefa 'commitar' 'T-0001') 0 'commitar'
    Garantir ((G @('log', '-1', '--format=%s')) -eq 'Robo Sentinela: coleta') 'mensagem do commit'
    Garantir (-not (G @('status', '--porcelain'))) 'arvore limpa depois do commit'
    Garantir ((G @('show', '--name-only', '--format=', 'HEAD')) -match 'estado.json' -and (G @('show', '--name-only', '--format=', 'HEAD')) -match 'robo.txt') 'commit leva codigo e ledger'
    Garantir (-not (Test-Path -LiteralPath (Join-Path $repo '.walle/local/T-0001/commit.txt'))) 'commit.txt consumido'

    # Publicar: push e PR uma vez so; numero do PR no ledger.
    Espera (Tarefa 'publicar' 'T-0001') 2 'publicar sem pr.md'
    Local 'T-0001' 'pr.md' 'Descricao do PR'
    Espera (Tarefa 'publicar' 'T-0001') 0 'publicar'
    $e = Estado 'T-0001'
    Garantir ($e.pr -eq 42 -and $e.estado -eq 'em_revisao') 'PR #42 no ledger'
    $head = G @('rev-parse', 'HEAD')
    Garantir ((& git -C $origem rev-parse 'tarefa/T-0001-robo-sentinela') -eq $head) 'origin com o HEAD da tarefa'
    Espera (Tarefa 'publicar' 'T-0001') 0 'publicar de novo so empurra'
    Garantir (@(Get-Content -LiteralPath $logGh | Where-Object { $_ -match '^pr create' }).Count -eq 1) 'um PR so'

    # Integrar: gate valido do HEAD, revisao aprovada do mesmo commit e CI verde; nessa ordem.
    Espera (Tarefa 'integrar' 'T-0001') 2 'integrar sem gate'
    $gate = Join-Path $repo '.walle/gate/ultimo.json'
    [IO.Directory]::CreateDirectory((Split-Path -Parent $gate)) | Out-Null
    [IO.File]::WriteAllText($gate, "{`"commit`": `"$head`", `"valida_para_commit`": false}", $utf8)
    Espera (Tarefa 'integrar' 'T-0001') 2 'integrar com gate nao valido'
    [IO.File]::WriteAllText($gate, "{`"commit`": `"0000000000000000000000000000000000000000`", `"valida_para_commit`": true}", $utf8)
    Espera (Tarefa 'integrar' 'T-0001') 2 'integrar com gate de outro commit'
    [IO.File]::WriteAllText($gate, "{`"commit`": `"$head`", `"valida_para_commit`": true}", $utf8)
    Espera (Tarefa 'integrar' 'T-0001') 2 'integrar sem revisao'
    Local 'T-0001' 'revisao.md' "Commit revisado: 1234567`nVeredito: APROVADO"
    Espera (Tarefa 'integrar' 'T-0001') 2 'revisao de outro commit'
    Local 'T-0001' 'revisao.md' "Commit revisado: $($head.Substring(0, 7))`nVeredito: MUDANCAS NECESSARIAS"
    Espera (Tarefa 'integrar' 'T-0001') 2 'revisao nao aprovada'
    Local 'T-0001' 'revisao.md' "Commit revisado: $($head.Substring(0, 7))`nVeredito: APROVADO"
    $env:WALLE_TEST_GH_CHECKS = '8'
    Espera (Tarefa 'integrar' 'T-0001') 2 'CI pendente'
    Garantir (@(Get-Content -LiteralPath $logGh | Where-Object { $_ -match '^pr merge' }).Count -eq 0) 'sem merge com CI pendente'
    $env:WALLE_TEST_GH_CHECKS = '0'
    Espera (Tarefa 'integrar' 'T-0001') 0 'integrar com tudo verde'
    Garantir (@(Get-Content -LiteralPath $logGh | Where-Object { $_ -match '^pr merge 42 --squash --delete-branch' }).Count -eq 1) 'merge do PR 42'
    Garantir (@(Get-Content -LiteralPath $logGh | Where-Object { $_ -match '^pr comment 42 --body-file' }).Count -ge 1) 'parecer publicado no PR'
    Garantir ((G @('branch', '--show-current')) -eq 'main') 'volta para o main'
    Garantir ((Tarefa 'status' 'T-0001').codigo -eq 0) 'status depois de integrar'

    # Ciclos: dois permitidos; o terceiro bloqueia (saida 3) e nada mais anda.
    Local 'T-0002' 'pedido.json' '{"titulo": "Outra", "origem": "PLAN 3.4", "tipo": "plano"}'
    Espera (Tarefa 'abrir' 'T-0002') 0 'abrir a segunda a partir do main'
    Espera (Tarefa 'bloquear' 'T-0002') 2 'bloquear sem motivo'
    Espera (Tarefa 'ciclo' 'T-0002') 0 'ciclo 1'
    Espera (Tarefa 'ciclo' 'T-0002') 0 'ciclo 2'
    Espera (Tarefa 'ciclo' 'T-0002') 3 'terceiro ciclo bloqueia'
    $e = Estado 'T-0002'
    Garantir ($e.estado -eq 'bloqueada' -and $e.ciclos -eq 3) 'estado bloqueada'
    Garantir ((G @('log', '-1', '--format=%s')) -match 'bloqueada') 'bloqueio commitado no ledger'
    Local 'T-0002' 'commit.txt' 'nao deveria'
    Espera (Tarefa 'commitar' 'T-0002') 2 'commitar tarefa bloqueada'
    Espera (Tarefa 'integrar' 'T-0002') 2 'integrar tarefa bloqueada'

    # Abrir exige arvore limpa.
    G @('switch', '-q', 'main') | Out-Null
    [IO.File]::WriteAllText((Join-Path $repo 'sujo.txt'), "x`n", $utf8)
    Local 'T-0003' 'pedido.json' '{"titulo": "Terceira", "origem": "pedido", "tipo": "feature"}'
    Espera (Tarefa 'abrir' 'T-0003') 2 'abrir com arvore suja'
    Local 'T-0004' 'pedido.json' '{"titulo": "Quarta", "origem": "pedido", "tipo": "deploy"}'
    Remove-Item -LiteralPath (Join-Path $repo 'sujo.txt')
    Espera (Tarefa 'abrir' 'T-0004') 2 'tipo invalido'
} finally {
    $env:PATH = $pathAnterior
    foreach ($nome in @('WALLE_TEST_GH_LOG', 'WALLE_TEST_GH_CHECKS')) { Remove-Item "Env:$nome" -ErrorAction SilentlyContinue }
    Remove-Item -LiteralPath $temporario -Recurse -Force -ErrorAction SilentlyContinue
}
Write-Output "$script:casos casos de tarefa passaram."
