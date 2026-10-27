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
$variaveis = @('WALLE_TEST_GH_LOG', 'WALLE_TEST_GH_DIR', 'WALLE_TEST_GH_CHECKS', 'WALLE_TEST_GH_SEM_MERGE', 'WALLE_TAREFA_ESPERA_CI_S')

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
    $inicio = G @('rev-parse', 'HEAD')
    G @('remote', 'add', 'origin', $origem) | Out-Null
    G @('push', '-q', '-u', 'origin', 'main') | Out-Null
    [IO.File]::WriteAllText((Join-Path $duplos 'gh.cmd'), (@(
        '@echo off'
        'echo %*>> "%WALLE_TEST_GH_LOG%"'
        'if "%1 %2"=="pr create" (echo x> "%WALLE_TEST_GH_DIR%\criado" & echo https://github.com/x/walle/pull/42 & exit /b 0)'
        'if "%1 %2"=="pr checks" exit /b %WALLE_TEST_GH_CHECKS%'
        'if "%1 %2"=="pr merge" if "%WALLE_TEST_GH_SEM_MERGE%"=="1" exit /b 0'
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
    Garantir ((& git -C $origem rev-parse 'tarefa/T-0001-robo-sentinela') -eq (G @('rev-parse', 'HEAD')) -and -not (G @('status', '--porcelain'))) 'abertura commitada e enviada ao origin'

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
    # Revisao obrigatoria do Codex: do mesmo HEAD (sha inteiro) e com P0/P1 descartados pelo arquiteto.
    Espera (Tarefa 'integrar' 'T-0001') 2 'sem parecer do Codex'
    Local 'T-0001' 'revisao-codex.md' "Commit revisado: $('0' * 40)`nBloqueantes do Codex: 0"
    Espera (Tarefa 'integrar' 'T-0001') 2 'parecer do Codex de outro commit'
    Local 'T-0001' 'revisao-codex.md' "Commit revisado: $curto`nBloqueantes do Codex: 0"
    Espera (Tarefa 'integrar' 'T-0001') 2 'parecer do Codex com sha curto'
    Local 'T-0001' 'revisao-codex.md' "Commit revisado: $head`nVeredito do Codex: APROVADO"
    Espera (Tarefa 'integrar' 'T-0001') 2 'parecer do Codex sem a contagem de bloqueantes'
    Local 'T-0001' 'revisao-codex.md' "Commit revisado: $head`nBloqueantes do Codex: 2`n- **P1** ``a.php:3`` - x"
    Espera (Tarefa 'integrar' 'T-0001') 2 'contagem de bloqueantes diferente da lista do Codex'
    Local 'T-0001' 'revisao-codex.md' "Commit revisado: $head`nBloqueantes do Codex: 2`n- **P1** ``a.php:3`` - x`n- **P0** ``b.php:1`` - y`n- **P2** ``c.php:5`` - z"
    Espera (Tarefa 'integrar' 'T-0001') 2 'achados P0/P1 do Codex sem descarte'
    Local 'T-0001' 'revisao.md' "Commit revisado: $curto`nVeredito: APROVADO`nCodex descartado: P1 a.php:3 - falso positivo"
    Espera (Tarefa 'integrar' 'T-0001') 2 'so um dos dois achados P0/P1 descartado'
    Local 'T-0001' 'revisao.md' "Commit revisado: $curto`nVeredito: APROVADO`nCodex descartado: P1 a.php:3 - falso positivo`nCodex descartado: P1 a.php:3 - de novo"
    Espera (Tarefa 'integrar' 'T-0001') 2 'descarte repetido do mesmo achado nao cobre o outro'
    Local 'T-0001' 'revisao.md' "Commit revisado: $curto`nVeredito: APROVADO`nCodex descartado: P1 a.php:3 - falso positivo`nCodex descartado: P0 b.php:1 -"
    Espera (Tarefa 'integrar' 'T-0001') 2 'descarte sem motivo nao conta'
    Local 'T-0001' 'revisao.md' "Commit revisado: $curto`nVeredito: APROVADO`nCodex descartado: P1 a.php:3 - falso positivo`nCodex descartado: P0 b.php:1 -`n## Gate"
    Espera (Tarefa 'integrar' 'T-0001') 2 'motivo do descarte na linha seguinte nao conta'
    $parecerCodex = Join-Path $repo '.walle/local/T-0001/revisao-codex.md'
    $codexValido = [IO.File]::ReadAllText($parecerCodex, $utf8)
    Local 'T-0001' 'revisao-codex.md' "Commit revisado:`n$head`nBloqueantes do Codex: 0"
    Espera (Tarefa 'integrar' 'T-0001') 2 'commit do Codex na linha seguinte nao conta'
    Local 'T-0001' 'revisao-codex.md' "Commit revisado: $head`nBloqueantes do Codex:`n0"
    Espera (Tarefa 'integrar' 'T-0001') 2 'contagem do Codex na linha seguinte nao conta'
    Local 'T-0001' 'revisao-codex.md' $codexValido
    Local 'T-0001' 'revisao.md' "Commit revisado: $curto`nVeredito: APROVADO`nCodex descartado: P1 a.php:3 - falso positivo`nCodex descartado: P0 ``b.php:1`` - fora do diff"
    $env:WALLE_TEST_GH_CHECKS = '8'
    Espera (Tarefa 'integrar' 'T-0001') 4 'CI pendente devolve 4 (tente de novo)'
    $env:WALLE_TEST_GH_CHECKS = '1'
    Espera (Tarefa 'integrar' 'T-0001') 2 'CI vermelho recusa'
    Garantir ((ChamadasGh '^pr merge') -eq 0) 'nenhum merge sem CI verde'
    Garantir ((ChamadasGh '^pr comment') -eq 10) 'cada parecer do HEAD (8 do arquiteto e 2 do Codex) publicado uma vez so, inclusive os que reprovam'
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
    # Finalizacao que caiu depois do merge: main local atrasado, sem o ledger; o integrar so alcanca o origin/main.
    G @('reset', '-q', '--hard', $inicio) | Out-Null
    Espera (Tarefa 'integrar' 'T-0001') 0 'integrar sem ledger local, com a tarefa no origin/main'
    Garantir ((G @('rev-parse', 'HEAD')) -eq (& git -C $origem rev-parse 'main')) 'main local alcancou o origin'
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
    Garantir ((G @('branch', '--show-current')) -eq 'main') 'retomar recusado nao troca de branch'
    Local 'T-0002' 'notas.md' 'Usuario decidiu: seguir com a abordagem B.'
    Espera (Tarefa 'retomar' 'T-0002') 0 'retomar com a decisao'
    $e = Estado 'T-0002'
    Garantir ($e.estado -eq 'em_revisao' -and $e.ciclos -eq 0 -and (G @('branch', '--show-current')) -eq 'tarefa/T-0002-outra') 'desbloqueada no branch, ciclos zerados'
    Garantir ((& git -C $origem rev-parse 'tarefa/T-0002-outra') -eq (G @('rev-parse', 'HEAD'))) 'desbloqueio enviado'
    # O texto do bloqueio nao serve como decisao.
    Local 'T-0002' 'notas.md' 'Falta a senha do servidor de testes.'
    Espera (Tarefa 'bloquear' 'T-0002') 0 'bloquear com motivo'
    Espera (Tarefa 'retomar' 'T-0002') 2 'retomar com o mesmo texto do bloqueio'
    Local 'T-0002' 'notas.md' 'Usuario enviou a senha por outro canal; seguir.'
    Espera (Tarefa 'retomar' 'T-0002') 0 'retomar com a decisao nova'
    Garantir ((Estado 'T-0002').estado -eq 'em_revisao') 'volta para em_revisao (tem PR)'
    # Terceiro ciclo com um notas.md que ja existia: aquele texto tambem nao vale como decisao.
    foreach ($n in 1..3) {
        [IO.File]::WriteAllText((Join-Path $repo 'outra.txt'), "rodada 2, $n`n", $utf8)
        Local 'T-0002' 'commit.txt' "Outra: nova correcao $n"
        Espera (Tarefa 'commitar' 'T-0002') $(if ($n -lt 3) { 0 } else { 3 }) "nova correcao $n"
    }
    Espera (Tarefa 'retomar' 'T-0002') 2 'retomar com o notas.md de antes do terceiro ciclo'
    Local 'T-0002' 'notas.md' 'Usuario aceitou a versao atual.'
    Espera (Tarefa 'retomar' 'T-0002') 0 'retomar com a decisao depois do terceiro ciclo'

    # Abrir exige arvore limpa e tipo valido.
    G @('switch', '-q', 'main') | Out-Null
    [IO.File]::WriteAllText((Join-Path $repo 'sujo.txt'), "x`n", $utf8)
    Local 'T-0003' 'pedido.json' '{"titulo": "Terceira", "origem": "pedido", "tipo": "feature"}'
    Espera (Tarefa 'abrir' 'T-0003') 2 'abrir com arvore suja'
    Remove-Item -LiteralPath (Join-Path $repo 'sujo.txt')
    Local 'T-0003' 'pedido.json' '{"titulo": "Terceira", "origem": "pedido", "tipo": "deploy"}'
    Espera (Tarefa 'abrir' 'T-0003') 2 'tipo invalido'

    # Aberta (sem commit de trabalho) ja aparece de outra maquina e pode ser retomada.
    Local 'T-0003' 'pedido.json' '{"titulo": "Terceira", "origem": "pedido", "tipo": "feature"}'
    Espera (Tarefa 'abrir' 'T-0003') 0 'abrir a terceira'
    G @('switch', '-q', 'main') | Out-Null
    G @('branch', '-q', '-D', 'tarefa/T-0003-terceira') | Out-Null
    Garantir ((Tarefa 'status' 'T-0003').saida -match 'T-0003 \| em_execucao') 'aberta vista pelo branch remoto'
    Espera (Tarefa 'retomar' 'T-0003') 0 'retomar aberta sem trabalho'
    Garantir ((G @('branch', '--show-current')) -eq 'tarefa/T-0003-terceira') 'no branch da terceira'

    # Bloqueada antes do PR volta para em_execucao.
    Local 'T-0003' 'notas.md' 'Qual layout usar?'
    Espera (Tarefa 'bloquear' 'T-0003') 0 'bloquear antes do PR'
    Local 'T-0003' 'notas.md' 'Usuario escolheu o layout A.'
    Espera (Tarefa 'retomar' 'T-0003') 0 'retomar antes do PR'
    Garantir ((Estado 'T-0003').estado -eq 'em_execucao') 'sem PR volta para em_execucao'

    # No proprio branch, o trabalho pendente nao impede retomar nem entra no commit do ledger.
    [IO.File]::WriteAllText((Join-Path $repo 'trabalho.txt'), "pela metade`n", $utf8)
    Espera (Tarefa 'retomar' 'T-0003') 0 'retomar no proprio branch com alteracoes pendentes'
    Garantir ((G @('status', '--porcelain')) -match 'trabalho\.txt') 'trabalho pendente continua fora de commit'

    # Commit que falha (hook do git) nao conta ciclo nem perde a mensagem.
    Local 'T-0003' 'commit.txt' 'Terceira: primeira versao'
    Espera (Tarefa 'commitar' 'T-0003') 0 'commit da terceira'
    Espera (Tarefa 'publicar' 'T-0003') 0 'publicar a terceira'
    $hook = Join-Path $repo '.git/hooks/pre-commit'
    [IO.File]::WriteAllText($hook, "#!/bin/sh`nexit 1`n", $utf8)
    [IO.File]::WriteAllText((Join-Path $repo 'trabalho.txt'), "completo`n", $utf8)
    Local 'T-0003' 'commit.txt' 'Terceira: correcao'
    Espera (Tarefa 'commitar' 'T-0003') 1 'commit recusado pelo hook do git'
    Garantir ((Estado 'T-0003').ciclos -eq 0 -and (Test-Path -LiteralPath (Join-Path $repo '.walle/local/T-0003/commit.txt'))) 'ciclo nao contado e mensagem preservada'
    Local 'T-0003' 'notas.md' 'Bloqueio que nao chega a ser commitado.'
    Espera (Tarefa 'bloquear' 'T-0003') 1 'bloqueio recusado pelo hook do git'
    Garantir ((Estado 'T-0003').estado -eq 'em_revisao' -and -not (G @('diff', '--cached', '--name-only'))) 'estado restaurado e nada em stage'
    Remove-Item -LiteralPath $hook -Force
    Espera (Tarefa 'commitar' 'T-0003') 0 'commit depois de tirar o hook'
    Garantir ((Estado 'T-0003').ciclos -eq 1) 'agora o ciclo conta'

    # Integrar: HEAD sem push, merge que nao aconteceu e Mercadologica.
    $head = G @('rev-parse', 'HEAD')
    Gate $head
    Local 'T-0003' 'revisao.md' "Commit revisado: $head`nVeredito: APROVADO"
    Local 'T-0003' 'revisao-codex.md' "Commit revisado: $head`nBloqueantes do Codex: 0"
    Espera (Tarefa 'integrar' 'T-0003') 2 'HEAD local sem push'
    Espera (Tarefa 'publicar' 'T-0003') 0 'publicar a correcao'
    $env:WALLE_TEST_GH_SEM_MERGE = '1'
    $r = Tarefa 'integrar' 'T-0003'
    Garantir ($r.codigo -eq 1 -and $r.saida -match 'nao ficou MERGED') "merge sem efeito e erro: $($r.saida)"
    Remove-Item Env:WALLE_TEST_GH_SEM_MERGE
    $arquivo = Join-Path $repo '.walle/tarefas/T-0003/estado.json'
    [IO.File]::WriteAllText($arquivo, ([IO.File]::ReadAllText($arquivo, $utf8) -replace '"Terceira"', '"Ajuste da Mercadologica"'), $utf8)
    $r = Tarefa 'integrar' 'T-0003'
    Garantir ($r.codigo -eq 2 -and $r.saida -match 'Mercadologica') "integrar recusa a Mercadologica: $($r.saida)"
    G @('checkout', '--', '.walle/tarefas/T-0003/estado.json') | Out-Null
    Espera (Tarefa 'integrar' 'T-0009') 2 'integrar sem ledger local e fora do main'

    # O desbloqueio commita so o ledger: trabalho pendente e em stage ficam como estavam.
    Local 'T-0003' 'notas.md' 'Esperando o usuario.'
    Espera (Tarefa 'bloquear' 'T-0003') 0 'bloquear a terceira'
    [IO.File]::WriteAllText((Join-Path $repo 'trabalho.txt'), "alterado depois do bloqueio`n", $utf8)
    [IO.File]::WriteAllText((Join-Path $repo 'preparado.txt'), "em stage`n", $utf8)
    G @('add', 'preparado.txt') | Out-Null
    Espera (Tarefa 'retomar' 'T-0002') 2 'retomar outra tarefa com a arvore suja'
    Garantir ((G @('branch', '--show-current')) -eq 'tarefa/T-0003-terceira') 'continua no branch da terceira'
    Local 'T-0003' 'notas.md' 'Usuario respondeu: seguir.'
    Espera (Tarefa 'retomar' 'T-0003') 0 'desbloquear com trabalho pendente'
    $noCommit = @((G @('show', '--name-only', '--format=', 'HEAD')) -split "`n" | Where-Object { $_.Trim() })
    Garantir ($noCommit.Count -gt 0 -and -not @($noCommit | Where-Object { $_ -notlike '.walle/tarefas/T-0003/*' }).Count) "commit do desbloqueio so com o ledger: $($noCommit -join ', ')"
    Garantir ((G @('diff', '--cached', '--name-only')) -eq 'preparado.txt' -and (G @('diff', '--name-only')) -match 'trabalho\.txt') 'stage e trabalho pendente preservados'

    # Retomada entre sessoes: avancos e bloqueios publicados nao podem ser ignorados.
    $repo = Join-Path $temporario 'sincronizacao'
    $peer = Join-Path $temporario 'outra-sessao'
    $mainSincronizacao = Join-Path $temporario 'main-sincronizacao'
    & git -c core.autocrlf=false clone -q $origem $repo
    G @('config', 'user.email', 'teste@walle') | Out-Null
    G @('config', 'user.name', 'Teste') | Out-Null
    G @('config', 'core.autocrlf', 'false') | Out-Null
    [IO.File]::WriteAllText((Join-Path $repo 'sincronizacao.txt'), "base`n", $utf8)
    G @('add', 'sincronizacao.txt') | Out-Null
    G @('commit', '-q', '-m', 'base da sincronizacao') | Out-Null
    G @('push', '-q', 'origin', 'main') | Out-Null
    Local 'T-0041' 'pedido.json' '{"titulo": "Sincronizacao", "origem": "pedido", "tipo": "harness"}'
    Espera (Tarefa 'abrir' 'T-0041') 0 'abrir tarefa entre sessoes'
    $branchSync = G @('branch', '--show-current')
    $headLocalSync = G @('rev-parse', 'HEAD')
    & git -c core.autocrlf=false clone -q --branch $branchSync $origem $peer
    & git -C $peer config user.email teste@walle
    & git -C $peer config user.name Teste
    & git -C $peer config core.autocrlf false
    $estadoPeer = Join-Path $peer '.walle/tarefas/T-0041/estado.json'
    $e = [IO.File]::ReadAllText($estadoPeer, $utf8) | ConvertFrom-Json
    $e.estado = 'bloqueada'
    $e.ciclos = 3
    $e.eventos = @($e.eventos) + @(@{ quando = '2026-10-27 12:00:00'; tipo = 'bloqueada'; detalhe = 'Outra sessao bloqueou.' })
    [IO.File]::WriteAllText($estadoPeer, ($e | ConvertTo-Json -Depth 6), $utf8)
    & git -C $peer add .walle/tarefas/T-0041/estado.json
    & git -C $peer commit -q -m bloqueio-publicado
    & git -C $peer push -q origin $branchSync
    $headBloqueado = "$(& git -C $peer rev-parse HEAD)".Trim()
    $r = Tarefa 'atualizar' 'T-0041'
    Garantir ($r.codigo -eq 2 -and $r.saida -match 'bloqueada') 'remoto bloqueado deve recusar antes de selecionar estado local antigo'
    Garantir ((G @('branch', '--show-current')) -eq $branchSync -and (G @('rev-parse', 'HEAD')) -eq $headLocalSync -and (Estado 'T-0041').ciclos -eq 0) 'bloqueio remoto nao altera branch ou ledger locais'
    G @('switch', '-q', 'main') | Out-Null
    $headMainSync = G @('rev-parse', 'HEAD')
    Espera (Tarefa 'atualizar' 'T-0041') 2 'remoto bloqueado recusa desde main'
    Garantir ((G @('branch', '--show-current')) -eq 'main' -and (G @('rev-parse', $branchSync)) -eq $headLocalSync -and (G @('rev-parse', "origin/$branchSync")) -eq $headBloqueado) 'bloqueio remoto preserva os dois historicos'

    $e.estado = 'em_revisao'
    $e.ciclos = 4
    $e.eventos = @($e.eventos) + @(@{ quando = '2026-10-27 12:01:00'; tipo = 'decisao'; detalhe = 'Fixture ativa publicada.' })
    [IO.File]::WriteAllText($estadoPeer, ($e | ConvertTo-Json -Depth 6), $utf8)
    & git -C $peer add .walle/tarefas/T-0041/estado.json
    & git -C $peer commit -q -m estado-ativo-publicado
    & git -C $peer push -q origin $branchSync
    $headPeer = "$(& git -C $peer rev-parse HEAD)".Trim()
    Espera (Tarefa 'atualizar' 'T-0041') 0 'main incorpora remoto ativo por avanco rapido'
    Garantir ((G @('branch', '--show-current')) -eq $branchSync -and (G @('rev-parse', 'HEAD')) -eq $headPeer -and (Estado 'T-0041').ciclos -eq 4 -and (Estado 'T-0041').eventos[-1].tipo -eq 'decisao') 'avanco remoto preserva ciclos e eventos sem novo commit'
    [IO.File]::WriteAllText((Join-Path $peer 'remoto.txt'), "outra sessao`n", $utf8)
    & git -C $peer add remoto.txt
    & git -C $peer commit -q -m trabalho-publicado
    & git -C $peer push -q origin $branchSync
    $headPeer = "$(& git -C $peer rev-parse HEAD)".Trim()
    Espera (Tarefa 'atualizar' 'T-0041') 0 'proprio branch incorpora remoto ativo'
    Garantir ((G @('rev-parse', 'HEAD')) -eq $headPeer -and (Test-Path -LiteralPath (Join-Path $repo 'remoto.txt'))) 'arquivo publicado alcancou branch local'

    [IO.File]::WriteAllText((Join-Path $repo 'local.txt'), "trabalho local legitimo`n", $utf8)
    G @('add', 'local.txt') | Out-Null
    G @('commit', '-q', '-m', 'trabalho local') | Out-Null
    $headLocalSync = G @('rev-parse', 'HEAD')
    Espera (Tarefa 'atualizar' 'T-0041') 0 'local a frente permanece intacto'
    G @('switch', '-q', 'main') | Out-Null
    Espera (Tarefa 'atualizar' 'T-0041') 0 'main preserva trabalho local a frente'
    Garantir ((G @('rev-parse', 'HEAD')) -eq $headLocalSync -and (G @('rev-parse', "origin/$branchSync")) -eq $headPeer -and (Estado 'T-0041').ciclos -eq 4) 'local a frente nao e descartado nem reduz ciclos'

    # Depois de FF, conflito com main preserva o avanco ja publicado e restaura o branch inicial.
    G @('push', '-q', 'origin', $branchSync) | Out-Null
    & git -C $peer pull -q --ff-only origin $branchSync
    [IO.File]::WriteAllText((Join-Path $peer 'sincronizacao.txt'), "remoto publicado`n", $utf8)
    & git -C $peer add sincronizacao.txt
    & git -C $peer commit -q -m conflito-no-remoto
    & git -C $peer push -q origin $branchSync
    $headPeer = "$(& git -C $peer rev-parse HEAD)".Trim()
    & git -c core.autocrlf=false clone -q $origem $mainSincronizacao
    & git -C $mainSincronizacao config user.email teste@walle
    & git -C $mainSincronizacao config user.name Teste
    & git -C $mainSincronizacao config core.autocrlf false
    [IO.File]::WriteAllText((Join-Path $mainSincronizacao 'sincronizacao.txt'), "main publicado`n", $utf8)
    & git -C $mainSincronizacao add sincronizacao.txt
    & git -C $mainSincronizacao commit -q -m conflito-no-main
    & git -C $mainSincronizacao push -q origin main
    G @('switch', '-q', 'main') | Out-Null
    $headMainSync = G @('rev-parse', 'HEAD')
    $r = Tarefa 'atualizar' 'T-0041'
    Garantir ($r.codigo -eq 2 -and $r.saida -match 'conflitos') 'conflito com main depois de FF e recusado'
    Garantir ((G @('branch', '--show-current')) -eq 'main' -and (G @('rev-parse', 'HEAD')) -eq $headMainSync -and -not (G @('status', '--porcelain'))) 'conflito apos FF restaura main original'
    Garantir ((G @('rev-parse', $branchSync)) -eq $headPeer -and (G @('show', "${branchSync}:sincronizacao.txt")) -eq 'remoto publicado') 'conflito nao desfaz o avanco ja publicado'
    G @('switch', '-q', $branchSync) | Out-Null
    Espera (Tarefa 'atualizar' 'T-0041') 2 'conflito no proprio branch preserva avanco remoto'
    Garantir ((G @('rev-parse', 'HEAD')) -eq $headPeer -and -not (G @('status', '--porcelain'))) 'conflito no proprio branch nao descarta commit remoto'

    [IO.File]::WriteAllText((Join-Path $repo 'local-divergiu.txt'), "local`n", $utf8)
    G @('add', 'local-divergiu.txt') | Out-Null
    G @('commit', '-q', '-m', 'divergencia local') | Out-Null
    $headLocalSync = G @('rev-parse', 'HEAD')
    [IO.File]::WriteAllText((Join-Path $peer 'remoto-divergiu.txt'), "remoto`n", $utf8)
    & git -C $peer add remoto-divergiu.txt
    & git -C $peer commit -q -m divergencia-remota
    & git -C $peer push -q origin $branchSync
    $headPeer = "$(& git -C $peer rev-parse HEAD)".Trim()
    $r = Tarefa 'atualizar' 'T-0041'
    Garantir ($r.codigo -eq 2 -and $r.saida -match 'diverg') 'divergencia recusa antes de merge com main'
    Garantir ((G @('rev-parse', 'HEAD')) -eq $headLocalSync -and (G @('rev-parse', "origin/$branchSync")) -eq $headPeer -and -not (G @('status', '--porcelain'))) 'divergencia preserva ambos os historicos e a arvore'
    G @('switch', '-q', 'main') | Out-Null
    Espera (Tarefa 'atualizar' 'T-0041') 2 'divergencia desde main nao seleciona tarefa'
    Garantir ((G @('branch', '--show-current')) -eq 'main' -and (G @('rev-parse', $branchSync)) -eq $headLocalSync) 'divergencia desde main preserva branch inicial'
    & git -C $peer push -q origin HEAD:refs/heads/tarefa/T-0041-ambigua
    $r = Tarefa 'atualizar' 'T-0041'
    Garantir ($r.codigo -eq 2 -and $r.saida -match 'mais de um branch') 'referencia remota ambigua recusa mesmo com branch local'

    # Atualizacao usa outro clone isolado: nada do trabalho pendente acima e tocado.
    $repo = Join-Path $temporario 'atualizacao'
    $mainAtualizacao = Join-Path $temporario 'main-atualizacao'
    & git -c core.autocrlf=false clone -q $origem $repo
    G @('config', 'user.email', 'teste@walle') | Out-Null
    G @('config', 'user.name', 'Teste') | Out-Null
    G @('config', 'core.autocrlf', 'false') | Out-Null
    [IO.File]::WriteAllText((Join-Path $repo 'comum.txt'), "base`n", $utf8)
    $scriptDoClone = Join-Path $repo '.claude/hooks/tarefa.ps1'
    [IO.Directory]::CreateDirectory((Split-Path -Parent $scriptDoClone)) | Out-Null
    [IO.File]::WriteAllText($scriptDoClone, "param([ValidateSet('retomar')][string]`$Acao)`nexit 99`n", $utf8)
    G @('add', 'comum.txt', '.claude/hooks/tarefa.ps1') | Out-Null
    G @('commit', '-q', '-m', 'base para atualizar') | Out-Null
    G @('push', '-q', 'origin', 'main') | Out-Null
    & git -c core.autocrlf=false clone -q $origem $mainAtualizacao
    & git -C $mainAtualizacao config user.email teste@walle
    & git -C $mainAtualizacao config user.name Teste
    & git -C $mainAtualizacao config core.autocrlf false
    Local 'T-0040' 'pedido.json' '{"titulo": "Atualizacao", "origem": "pedido", "tipo": "harness"}'
    Espera (Tarefa 'abrir' 'T-0040') 0 'abrir tarefa de atualizacao'
    $branchAtualizacao = G @('branch', '--show-current')
    $estadoAtualizacao = Join-Path $repo '.walle/tarefas/T-0040/estado.json'
    $e = Estado 'T-0040'
    $e.ciclos = 2
    [IO.File]::WriteAllText($estadoAtualizacao, ($e | ConvertTo-Json -Depth 6), $utf8)
    G @('add', '.walle/tarefas/T-0040/estado.json') | Out-Null
    G @('commit', '-q', '-m', 'fixture com dois ciclos') | Out-Null
    $headAtualizacao = G @('rev-parse', 'HEAD')
    Espera (Tarefa 'atualizar' 'T-0040') 0 'main ja incorporado'
    Garantir ((G @('rev-parse', 'HEAD')) -eq $headAtualizacao -and (Estado 'T-0040').ciclos -eq 2) 'atualizar sem novidade e idempotente'
    [IO.File]::WriteAllText((Join-Path $repo 'pendente.txt'), "nao perder`n", $utf8)
    Espera (Tarefa 'atualizar' 'T-0040') 2 'atualizar recusa arvore suja'
    Garantir ([IO.File]::ReadAllText((Join-Path $repo 'pendente.txt')) -eq "nao perder`n" -and (G @('rev-parse', 'HEAD')) -eq $headAtualizacao) 'recusa preserva trabalho e HEAD'
    Remove-Item -LiteralPath (Join-Path $repo 'pendente.txt')
    G @('switch', '-q', 'main') | Out-Null
    Espera (Tarefa 'atualizar' 'T-0040') 0 'atualizar desde main seleciona tarefa sem novidades'
    Garantir ((G @('branch', '--show-current')) -eq $branchAtualizacao) 'main seleciona o branch pedido'
    G @('switch', '-q', 'tarefa/T-0001-robo-sentinela') | Out-Null
    Espera (Tarefa 'atualizar' 'T-0040') 2 'atualizar recusa branch de outra tarefa'
    Garantir ((G @('branch', '--show-current')) -eq 'tarefa/T-0001-robo-sentinela') 'recusa nao troca outra tarefa'
    G @('switch', '-q', $branchAtualizacao) | Out-Null

    [IO.File]::WriteAllText((Join-Path $mainAtualizacao 'componente.txt'), "componente do main`n", $utf8)
    & git -C $mainAtualizacao add componente.txt
    $scriptNovoNoMain = Join-Path $mainAtualizacao '.claude/hooks/tarefa.ps1'
    [IO.File]::WriteAllText($scriptNovoNoMain, [IO.File]::ReadAllText($script, $utf8), $utf8)
    & git -C $mainAtualizacao add .claude/hooks/tarefa.ps1
    & git -C $mainAtualizacao commit -q -m componente
    & git -C $mainAtualizacao push -q origin main
    # Falha no commit nao deixa arquivos do merge nem altera o ledger.
    $textoEstadoAntes = [IO.File]::ReadAllText($estadoAtualizacao, $utf8)
    $hookAtualizacao = Join-Path $repo '.git/hooks/pre-commit'
    [IO.File]::WriteAllText($hookAtualizacao, "#!/bin/sh`nexit 1`n", $utf8)
    Espera (Tarefa 'atualizar' 'T-0040') 1 'atualizar com commit recusado'
    Garantir ((G @('rev-parse', 'HEAD')) -eq $headAtualizacao -and -not (G @('status', '--porcelain'))) 'commit recusado restaura HEAD e arvore limpa'
    Garantir ([IO.File]::ReadAllText($estadoAtualizacao, $utf8) -ceq $textoEstadoAntes) 'commit recusado restaura bytes do ledger'
    Remove-Item -LiteralPath $hookAtualizacao
    G @('switch', '-q', 'main') | Out-Null
    G @('pull', '-q', '--ff-only', 'origin', 'main') | Out-Null
    $headMainAtualizacao = G @('rev-parse', 'HEAD')
    Espera (Tarefa 'atualizar' 'T-0999') 2 'tarefa inexistente nao troca main'
    Espera (Tarefa 'atualizar' 'T-0001') 2 'tarefa integrada nao troca main'
    Garantir ((G @('branch', '--show-current')) -eq 'main' -and (G @('rev-parse', 'HEAD')) -eq $headMainAtualizacao) 'recusas preservam main'
    [IO.File]::WriteAllText((Join-Path $repo 'pendente.txt'), "pendencia no main`n", $utf8)
    Espera (Tarefa 'atualizar' 'T-0040') 2 'main sujo nao seleciona tarefa'
    Garantir ((G @('branch', '--show-current')) -eq 'main' -and [IO.File]::ReadAllText((Join-Path $repo 'pendente.txt')) -eq "pendencia no main`n") 'main sujo permanece intacto'
    Remove-Item -LiteralPath (Join-Path $repo 'pendente.txt')
    [IO.File]::WriteAllText($hookAtualizacao, "#!/bin/sh`nexit 1`n", $utf8)
    Espera (Tarefa 'atualizar' 'T-0040') 1 'falha commit desde main restaura branch de origem'
    Garantir ((G @('branch', '--show-current')) -eq 'main' -and (G @('rev-parse', 'HEAD')) -eq $headMainAtualizacao -and -not (G @('status', '--porcelain'))) 'falha desde main retorna ao main intacto'
    Garantir ((G @('rev-parse', $branchAtualizacao)) -eq $headAtualizacao) 'falha desde main preserva HEAD da tarefa'
    Remove-Item -LiteralPath $hookAtualizacao
    # O processo carrega a versao nova do main antes de trocar para o arquivo antigo do branch.
    $scriptOriginal = $script
    $script = $scriptDoClone
    try { Espera (Tarefa 'atualizar' 'T-0040') 0 'bootstrap main atualiza branch com script antigo sem atualizar' } finally { $script = $scriptOriginal }
    Garantir ((Test-Path -LiteralPath (Join-Path $repo 'componente.txt')) -and (Estado 'T-0040').ciclos -eq 2) 'componente incorporado sem zerar ciclos'
    Garantir ((Estado 'T-0040').eventos[-1].tipo -eq 'atualizada' -and -not (G @('status', '--porcelain'))) 'evento da atualizacao commitado'
    Garantir ((& git -C $origem rev-parse $branchAtualizacao) -eq (G @('rev-parse', 'HEAD'))) 'atualizacao publicada no origin'
    $headAtualizacao = G @('rev-parse', 'HEAD')
    Espera (Tarefa 'atualizar' 'T-0040') 0 'segunda atualizacao nao faz outro commit'
    Garantir ((G @('rev-parse', 'HEAD')) -eq $headAtualizacao) 'atualizacao repetida e idempotente'
    G @('switch', '-q', 'main') | Out-Null
    G @('branch', '-q', '-D', $branchAtualizacao) | Out-Null
    Espera (Tarefa 'atualizar' 'T-0040') 0 'main seleciona tarefa que existe so no origin'
    Garantir ((G @('branch', '--show-current')) -eq $branchAtualizacao -and (G @('rev-parse', 'HEAD')) -eq $headAtualizacao -and (Estado 'T-0040').ciclos -eq 2) 'branch remoto recuperado sem alterar commits ou ciclos'

    # Conflito cancela o merge inteiro, preservando o trabalho original e o ledger.
    [IO.File]::WriteAllText((Join-Path $repo 'comum.txt'), "da tarefa`n", $utf8)
    G @('add', 'comum.txt') | Out-Null
    G @('commit', '-q', '-m', 'alteracao da tarefa') | Out-Null
    [IO.File]::WriteAllText((Join-Path $mainAtualizacao 'comum.txt'), "do main`n", $utf8)
    & git -C $mainAtualizacao add comum.txt
    & git -C $mainAtualizacao commit -q -m conflito
    & git -C $mainAtualizacao push -q origin main
    $headAtualizacao = G @('rev-parse', 'HEAD')
    $textoEstadoAntes = [IO.File]::ReadAllText($estadoAtualizacao, $utf8)
    $r = Tarefa 'atualizar' 'T-0040'
    Garantir ($r.codigo -eq 2 -and $r.saida -match 'conflitos') 'atualizacao conflitante explica a recusa'
    Garantir ((G @('rev-parse', 'HEAD')) -eq $headAtualizacao -and -not (G @('status', '--porcelain'))) 'conflito preserva HEAD e arvore limpa'
    Garantir ([IO.File]::ReadAllText((Join-Path $repo 'comum.txt')) -eq "da tarefa`n" -and [IO.File]::ReadAllText($estadoAtualizacao, $utf8) -ceq $textoEstadoAntes) 'conflito preserva conteudo e ledger'
    Garantir (-not (Test-Path -LiteralPath (Join-Path $repo '.git/MERGE_HEAD'))) 'conflito nao deixa merge incompleto'
    G @('switch', '-q', 'main') | Out-Null
    G @('pull', '-q', '--ff-only', 'origin', 'main') | Out-Null
    $headMainAtualizacao = G @('rev-parse', 'HEAD')
    $r = Tarefa 'atualizar' 'T-0040'
    Garantir ($r.codigo -eq 2 -and $r.saida -match 'conflitos') 'conflito desde main explica recusa'
    Garantir ((G @('branch', '--show-current')) -eq 'main' -and (G @('rev-parse', 'HEAD')) -eq $headMainAtualizacao -and -not (G @('status', '--porcelain'))) 'conflito desde main restaura main'
    Garantir ((G @('rev-parse', $branchAtualizacao)) -eq $headAtualizacao) 'conflito desde main preserva tarefa'
    G @('switch', '-q', $branchAtualizacao) | Out-Null

    Local 'T-0040' 'notas.md' 'Precisa decisao antes de atualizar.'
    Espera (Tarefa 'bloquear' 'T-0040') 0 'bloquear tarefa de atualizacao'
    Espera (Tarefa 'atualizar' 'T-0040') 2 'atualizar nao desbloqueia nem zera ciclos'
    Garantir ((Estado 'T-0040').ciclos -eq 2) 'tarefa bloqueada preserva ciclos'
    G @('switch', '-q', 'main') | Out-Null
    Espera (Tarefa 'atualizar' 'T-0040') 2 'bloqueada nao troca main'
    Garantir ((G @('branch', '--show-current')) -eq 'main' -and (G @('rev-parse', 'HEAD')) -eq $headMainAtualizacao) 'recusa bloqueada preserva main'
} finally {
    $env:PATH = $pathAnterior
    foreach ($nome in $variaveis) { Remove-Item "Env:$nome" -ErrorAction SilentlyContinue }
    Remove-Item -LiteralPath $temporario -Recurse -Force -ErrorAction SilentlyContinue
}
Write-Output "$script:casos casos de tarefa passaram."
