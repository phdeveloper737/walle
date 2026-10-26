[Console]::InputEncoding = New-Object Text.UTF8Encoding $false
# Testes do ledger e do Git das tarefas (.claude/hooks/tarefa.ps1) num repositorio temporario, com um origin
# local (bare) e um gh falso no PATH que guarda estado (PR criado, PR mergeado): nada toca o repositorio real
# nem o GitHub. Sem acentos: o PowerShell 5.1 le este arquivo como ANSI.
$ErrorActionPreference = 'Stop'
$script = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../tarefa.ps1'))
$powershellReal = (Get-Command powershell -CommandType Application | Select-Object -First 1).Source
$script:casos = 0
$utf8 = New-Object Text.UTF8Encoding($false)

$temporario = Join-Path ([IO.Path]::GetTempPath()) ('walle-tarefa-tests-' + [guid]::NewGuid().ToString('N'))
$repo = Join-Path $temporario 'repo'
$origem = Join-Path $temporario 'origem.git'
$duplos = Join-Path $temporario 'duplos'
$estadoGh = Join-Path $temporario 'gh'
$logGh = Join-Path $temporario 'gh.log'
$pathAnterior = $env:PATH
$variaveis = @('WALLE_TEST_GH_LOG', 'WALLE_TEST_GH_DIR', 'WALLE_TEST_GH_CHECKS', 'WALLE_TAREFA_ESPERA_CI_S')

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
function Gate([string]$Commit, [bool]$Valida = $true, [bool]$Preliminar = $false) {
    $arquivo = Join-Path $repo '.walle/gate/ultimo.json'
    [IO.Directory]::CreateDirectory((Split-Path -Parent $arquivo)) | Out-Null
    [IO.File]::WriteAllText($arquivo, (@{ commit = $Commit; valida_para_commit = $Valida; completo = $true; preliminar = $Preliminar } | ConvertTo-Json), $utf8)
}
function ChamadasGh([string]$Padrao) { return @(Get-Content -LiteralPath $logGh -ErrorAction SilentlyContinue | Where-Object { $_ -match $Padrao }).Count }
function Garantir([bool]$Condicao, [string]$Mensagem) {
    if (-not $Condicao) { throw "tarefa: $Mensagem" }
    $script:casos++
}
function Espera([hashtable]$R, [int]$Codigo, [string]$Mensagem) { Garantir ($R.codigo -eq $Codigo) "$Mensagem (esperado $Codigo, recebido $($R.codigo)): $($R.saida)" }

try {
    # Repositorio com main publicado num origin local; gh falso com estado em arquivos.
    foreach ($pasta in @($repo, $duplos, $estadoGh)) { [IO.Directory]::CreateDirectory($pasta) | Out-Null }
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
        'if "%1 %2"=="pr create" (echo x> "%WALLE_TEST_GH_DIR%\criado" & echo https://github.com/x/walle/pull/42 & exit /b 0)'
        'if "%1 %2"=="pr checks" exit /b %WALLE_TEST_GH_CHECKS%'
        'if "%1 %2"=="pr merge" (echo MERGED> "%WALLE_TEST_GH_DIR%\estado" & exit /b 0)'
        'if "%1 %2"=="pr view" goto ver'
        'exit /b 0'
        ':ver'
        'echo %* | findstr /c:"number" >nul && goto numero'
        'if exist "%WALLE_TEST_GH_DIR%\estado" (type "%WALLE_TEST_GH_DIR%\estado") else (echo OPEN)'
        'exit /b 0'
        ':numero'
        'if exist "%WALLE_TEST_GH_DIR%\criado" (echo 42& exit /b 0)'
        'exit /b 1'
    ) -join "`r`n") + "`r`n")
    $env:PATH = $duplos + ';' + $pathAnterior
    $env:WALLE_TEST_GH_LOG = $logGh
    $env:WALLE_TEST_GH_DIR = $estadoGh
    $env:WALLE_TEST_GH_CHECKS = '0'
    $env:WALLE_TAREFA_ESPERA_CI_S = '0'

    # Abertura: id, pedido, titulo e a Classificacao Mercadologica (sempre recusada).
    Garantir ((Tarefa 'proxima').saida.Trim() -eq 'T-0001') 'primeira tarefa e T-0001'
    Espera (Tarefa 'abrir' 'T-1') 2 'id fora do formato'
    Espera (Tarefa 'abrir' 'T-0001') 2 'abrir sem pedido.json'
    Local 'T-0001' 'pedido.json' '{"titulo": "Robo \"Sentinela\"", "origem": "PLAN 3.2", "tipo": "plano"}'
    Espera (Tarefa 'abrir' 'T-0001') 2 'titulo com aspas'
    Local 'T-0001' 'pedido.json' '{"titulo": "Robo da classificacao", "origem": "PLAN 3.3", "tipo": "plano"}'
    Espera (Tarefa 'abrir' 'T-0001') 2 'Mercadologica pela task do plano'
    Local 'T-0001' 'pedido.json' '{"titulo": "Painel Mercadologica", "origem": "pedido do usuario", "tipo": "feature"}'
    Espera (Tarefa 'abrir' 'T-0001') 2 'Mercadologica pelo titulo'
    Local 'T-0001' 'pedido.json' '{"titulo": "Robo Sentinela", "origem": "PLAN 3.2", "tipo": "plano", "dono": "walle-robos"}'
    Espera (Tarefa 'abrir' 'T-0001') 0 'abrir com pedido'
    Garantir ((G @('branch', '--show-current')) -eq 'tarefa/T-0001-robo-sentinela') 'branch da tarefa com slug do titulo'
    $e = Estado 'T-0001'
    Garantir ($e.estado -eq 'em_execucao' -and $e.ciclos -eq 0 -and $e.dono -eq 'walle-robos' -and @($e.eventos).Count -eq 1) 'estado inicial com dono'
    Espera (Tarefa 'abrir' 'T-0001') 2 'abrir duas vezes'

    # Commit antes do PR nao conta ciclo.
    [IO.File]::WriteAllText((Join-Path $repo 'robo.txt'), "codigo do robo`n", $utf8)
    Espera (Tarefa 'commitar' 'T-0001') 2 'commitar sem commit.txt'
    Local 'T-0001' 'commit.txt' "Robo Sentinela: coleta`n`nCorpo da mensagem."
    Espera (Tarefa 'commitar' 'T-0001') 0 'commitar'
    Garantir ((G @('log', '-1', '--format=%s')) -eq 'Robo Sentinela: coleta' -and (Estado 'T-0001').ciclos -eq 0) 'mensagem do commit, sem ciclo'
    Garantir (-not (G @('status', '--porcelain'))) 'arvore limpa depois do commit'

    # Publicar: push e PR uma vez so.
    Espera (Tarefa 'publicar' 'T-0001') 2 'publicar sem pr.md'
    Local 'T-0001' 'pr.md' 'Descricao do PR'
    Espera (Tarefa 'publicar' 'T-0001') 0 'publicar'
    $e = Estado 'T-0001'
    Garantir ($e.pr -eq 42 -and $e.estado -eq 'em_revisao') 'PR #42 no ledger'
    $head = G @('rev-parse', 'HEAD')
    Garantir ((& git -C $origem rev-parse 'tarefa/T-0001-robo-sentinela') -eq $head) 'origin com o HEAD da tarefa'
    Espera (Tarefa 'publicar' 'T-0001') 0 'publicar de novo so empurra'
    Garantir ((ChamadasGh '^pr create') -eq 1) 'um PR so'

    # Integrar: recusas em cada pre-condicao, nessa ordem.
    Espera (Tarefa 'integrar' 'T-0001') 2 'integrar sem gate'
    Gate $head $false
    Espera (Tarefa 'integrar' 'T-0001') 2 'gate nao valido'
    Gate ('0' * 40)
    Espera (Tarefa 'integrar' 'T-0001') 2 'gate de outro commit'
    Gate $head $true $true
    Espera (Tarefa 'integrar' 'T-0001') 2 'gate preliminar (sessao paralela)'
    Gate $head
    Espera (Tarefa 'integrar' 'T-0001') 2 'sem revisao'
    $curto = $head.Substring(0, 7)
    Local 'T-0001' 'revisao.md' "Commit revisado: 1234567`nVeredito: APROVADO"
    Espera (Tarefa 'integrar' 'T-0001') 2 'revisao de outro commit'
    Local 'T-0001' 'revisao.md' "Commit revisado: $curto`nVeredito: MUDANCAS NECESSARIAS"
    Espera (Tarefa 'integrar' 'T-0001') 2 'revisao nao aprovada'
    Local 'T-0001' 'revisao.md' "Commit revisado: $curto`nVeredito: MUDANCAS NECESSARIAS`nVeredito: APROVADO"
    Espera (Tarefa 'integrar' 'T-0001') 2 'dois vereditos'
    Local 'T-0001' 'revisao.md' "Commit revisado: $curto`nVeredito: APROVADO"
    $env:WALLE_TEST_GH_CHECKS = '8'
    Espera (Tarefa 'integrar' 'T-0001') 4 'CI pendente devolve 4 (tente de novo)'
    $env:WALLE_TEST_GH_CHECKS = '1'
    Espera (Tarefa 'integrar' 'T-0001') 2 'CI vermelho recusa'
    Garantir ((ChamadasGh '^pr merge') -eq 0) 'nenhum merge sem CI verde'
    Garantir ((ChamadasGh '^pr comment') -eq 1) 'parecer publicado uma vez so para o mesmo HEAD'
    $env:WALLE_TEST_GH_CHECKS = '0'
    Espera (Tarefa 'integrar' 'T-0001') 0 'integrar com tudo verde'
    Garantir ((ChamadasGh "^pr merge 42 --squash --delete-branch --match-head-commit $head") -eq 1) 'merge amarrado ao HEAD conferido'
    Garantir ((G @('branch', '--show-current')) -eq 'main') 'volta para o main'

    # O merge do GitHub (simulado) leva o ledger ao main: "integrada" vem do origin/main, em qualquer maquina.
    G @('push', '-q', 'origin', 'tarefa/T-0001-robo-sentinela:main') | Out-Null
    G @('pull', '-q', '--ff-only', 'origin', 'main') | Out-Null
    Remove-Item -LiteralPath (Join-Path $repo '.walle/local/T-0001') -Recurse -Force
    Garantir ((Tarefa 'status' 'T-0001').saida -match 'T-0001 \| integrada') 'integrada pelo origin/main, sem arquivo local'
    Espera (Tarefa 'integrar' 'T-0001') 0 'integrar de novo depois do merge so finaliza'
    Garantir ((ChamadasGh '^pr merge') -eq 1) 'sem segundo merge'
    Espera (Tarefa 'retomar' 'T-0001') 2 'retomar tarefa integrada'
    Garantir ((Tarefa 'proxima').saida.Trim() -eq 'T-0002') 'proxima conta o que esta no main'

    # Ciclos: depois do PR, cada commit e uma correcao contada pelo proprio script; o terceiro bloqueia.
    Remove-Item -LiteralPath (Join-Path $estadoGh 'estado') -Force
    Local 'T-0002' 'pedido.json' '{"titulo": "Outra", "origem": "PLAN 3.4", "tipo": "plano"}'
    Espera (Tarefa 'abrir' 'T-0002') 0 'abrir a segunda a partir do main'
    Espera (Tarefa 'bloquear' 'T-0002') 2 'bloquear sem motivo'
    [IO.File]::WriteAllText((Join-Path $repo 'outra.txt'), "1`n", $utf8)
    Local 'T-0002' 'commit.txt' 'Outra: primeira versao'
    Espera (Tarefa 'commitar' 'T-0002') 0 'commit inicial'
    Espera (Tarefa 'publicar' 'T-0002') 0 'publicar reaproveita o PR que ja existe para o branch'
    Garantir ((ChamadasGh '^pr create') -eq 1 -and (Estado 'T-0002').pr -eq 42) 'sem segundo gh pr create'
    foreach ($n in 1..2) {
        [IO.File]::WriteAllText((Join-Path $repo 'outra.txt'), "$($n + 1)`n", $utf8)
        Local 'T-0002' 'commit.txt' "Outra: correcao $n"
        Espera (Tarefa 'commitar' 'T-0002') 0 "correcao $n"
        Garantir ((Estado 'T-0002').ciclos -eq $n) "ciclo $n contado pelo commitar"
    }
    [IO.File]::WriteAllText((Join-Path $repo 'outra.txt'), "4`n", $utf8)
    Local 'T-0002' 'commit.txt' 'Outra: correcao 3'
    Espera (Tarefa 'commitar' 'T-0002') 3 'terceira correcao bloqueia'
    Garantir ((Estado 'T-0002').estado -eq 'bloqueada') 'estado bloqueada'
    Garantir ((& git -C $origem rev-parse 'tarefa/T-0002-outra') -eq (G @('rev-parse', 'HEAD'))) 'bloqueio enviado ao origin'
    Local 'T-0002' 'commit.txt' 'nao deveria'
    Espera (Tarefa 'commitar' 'T-0002') 2 'commitar tarefa bloqueada'
    Espera (Tarefa 'publicar' 'T-0002') 2 'publicar tarefa bloqueada'
    Espera (Tarefa 'integrar' 'T-0002') 2 'integrar tarefa bloqueada'

    # Do main, a bloqueada continua visivel (pelo origin) e o id nao e reaproveitado; retomar exige a decisao.
    Remove-Item -LiteralPath (Join-Path $repo '.walle/local/T-0002/commit.txt') -Force
    G @('switch', '-q', 'main') | Out-Null
    G @('branch', '-q', '-D', 'tarefa/T-0002-outra') | Out-Null
    Garantir ((Tarefa 'status' 'T-0002').saida -match 'T-0002 \| bloqueada') 'bloqueada vista pelo branch remoto'
    Garantir ((Tarefa 'proxima').saida.Trim() -eq 'T-0003') 'proxima nao reaproveita o id da bloqueada'
    Espera (Tarefa 'retomar' 'T-0002') 2 'retomar bloqueada sem a decisao'
    G @('switch', '-q', 'main') | Out-Null
    Local 'T-0002' 'notas.md' 'Usuario decidiu: seguir com a abordagem B.'
    Espera (Tarefa 'retomar' 'T-0002') 0 'retomar com a decisao'
    $e = Estado 'T-0002'
    Garantir ($e.estado -eq 'em_revisao' -and $e.ciclos -eq 0 -and (G @('branch', '--show-current')) -eq 'tarefa/T-0002-outra') 'desbloqueada no branch, ciclos zerados'
    Garantir ((& git -C $origem rev-parse 'tarefa/T-0002-outra') -eq (G @('rev-parse', 'HEAD'))) 'desbloqueio enviado'

    # Abrir exige arvore limpa e tipo valido.
    G @('switch', '-q', 'main') | Out-Null
    [IO.File]::WriteAllText((Join-Path $repo 'sujo.txt'), "x`n", $utf8)
    Local 'T-0003' 'pedido.json' '{"titulo": "Terceira", "origem": "pedido", "tipo": "feature"}'
    Espera (Tarefa 'abrir' 'T-0003') 2 'abrir com arvore suja'
    Remove-Item -LiteralPath (Join-Path $repo 'sujo.txt')
    Local 'T-0003' 'pedido.json' '{"titulo": "Terceira", "origem": "pedido", "tipo": "deploy"}'
    Espera (Tarefa 'abrir' 'T-0003') 2 'tipo invalido'
} finally {
    $env:PATH = $pathAnterior
    foreach ($nome in $variaveis) { Remove-Item "Env:$nome" -ErrorAction SilentlyContinue }
    Remove-Item -LiteralPath $temporario -Recurse -Force -ErrorAction SilentlyContinue
}
Write-Output "$script:casos casos de tarefa passaram."
