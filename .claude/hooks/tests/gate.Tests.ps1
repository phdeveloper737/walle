[Console]::InputEncoding = New-Object Text.UTF8Encoding $false
# Testes do gate (.claude/hooks/gate.ps1) com duplos de docker e powershell no PATH: nenhuma etapa real roda,
# nem os proprios testes de novo. O gate e chamado pelo powershell real (caminho completo).
# Sem acentos: o PowerShell 5.1 le este arquivo como ANSI.
$ErrorActionPreference = 'Stop'
$raiz = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../..'))
$gate = Join-Path $raiz '.claude/hooks/gate.ps1'
$powershellReal = (Get-Command powershell -CommandType Application | Select-Object -First 1).Source
$gitReal = (Get-Command git -CommandType Application | Select-Object -First 1).Source
$script:casos = 0

$temporario = Join-Path ([IO.Path]::GetTempPath()) ('walle-gate-tests-' + [guid]::NewGuid().ToString('N'))
$semDocker = Join-Path $temporario 'sem-docker'
[IO.Directory]::CreateDirectory($semDocker) | Out-Null
$saida = Join-Path $temporario 'evidencia.json'
$copia = Join-Path $temporario 'copia-durante.json'
$arquivoNovo = Join-Path $raiz ('.walle-gate-teste-' + [guid]::NewGuid().ToString('N') + '.txt')
$pathAnterior = $env:PATH
$variaveis = @('WALLE_TEST_DOCKER_EXIT', 'WALLE_TEST_POWERSHELL_EXIT', 'WALLE_TEST_DOCKER_TOCA', 'WALLE_TEST_GATE_SAIDA', 'WALLE_TEST_GATE_COPIA', 'WALLE_HOOK_TEST_DATABASE', 'GIT_CONFIG_COUNT', 'GIT_CONFIG_KEY_0', 'GIT_CONFIG_VALUE_0')
$bancoAnterior = $env:WALLE_HOOK_TEST_DATABASE

function RodarGate([string[]]$Argumentos) {
    $preferencia = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $texto = & $powershellReal -NoProfile -ExecutionPolicy Bypass -File $gate @Argumentos 2>&1 | ForEach-Object { [string]$_ }
        return @{ codigo = $LASTEXITCODE; saida = ($texto -join "`n") }
    } finally { $ErrorActionPreference = $preferencia }
}
function Evidencia { return Get-Content -LiteralPath $saida -Raw | ConvertFrom-Json }
function Garantir([bool]$Condicao, [string]$Mensagem) {
    if (-not $Condicao) { throw "gate: $Mensagem" }
    $script:casos++
}
function Limpar {
    foreach ($nome in $variaveis) { Remove-Item "Env:$nome" -ErrorAction SilentlyContinue }
    $env:WALLE_TEST_DOCKER_EXIT = '0'
    $env:WALLE_TEST_POWERSHELL_EXIT = '0'
    foreach ($arquivo in @($saida, $copia, $arquivoNovo)) { Remove-Item -LiteralPath $arquivo -Force -ErrorAction SilentlyContinue }
}

try {
    [IO.File]::WriteAllText((Join-Path $temporario 'docker.cmd'), (@(
        '@echo off'
        'echo Detalhe completo do Pest falso'
        'echo Tests: falso %*'
        'if defined WALLE_TEST_DOCKER_TOCA echo mudou> "%WALLE_TEST_DOCKER_TOCA%"'
        'if defined WALLE_TEST_GATE_COPIA copy /y "%WALLE_TEST_GATE_SAIDA%" "%WALLE_TEST_GATE_COPIA%" >nul'
        'exit /b %WALLE_TEST_DOCKER_EXIT%'
    ) -join "`r`n") + "`r`n")
    [IO.File]::WriteAllText((Join-Path $temporario 'powershell.cmd'), "@echo off`r`necho 1 casos falsos passaram.`r`nexit /b %WALLE_TEST_POWERSHELL_EXIT%`r`n")
    $env:PATH = $temporario + ';' + $pathAnterior
    $pendente = "$(& $gitReal -C $raiz status --porcelain)".Trim()
    $arvoreDoCommit = "$(& $gitReal -C $raiz rev-parse 'HEAD^{tree}')".Trim()

    # 1. Gate completo com tudo passando: VERDE, saida 0, oito etapas na ordem, completo.
    Limpar
    $r = RodarGate @('-Saida', $saida)
    Garantir ($r.codigo -eq 0) "completo verde deveria sair com 0: $($r.saida)"
    $e = Evidencia
    Garantir ($e.verde -eq $true -and $e.completo -eq $true -and $e.preliminar -eq $false) 'completo, verde e definitivo'
    Garantir ((@($e.etapas | ForEach-Object { $_.nome }) -join ',') -eq 'pint,phpstan,pest,politicas,gate,tarefa,plugin,rag') 'oito etapas na ordem'
    Garantir ($e.arvore -match '^[0-9a-f]{40}$' -and $e.commit -match '^[0-9a-f]{40}$') 'arvore e commit com 40 hex'
    Garantir ($e.banco -eq 'walle_testes' -and $e.etapas[2].comando -match 'DB_DATABASE=walle_testes ') 'banco padrao no pest'
    Garantir ($e.etapas[3].resumo -match 'casos falsos passaram' -and $e.etapas[2].resumo -match 'Tests: falso') 'resumo vem da saida'
    # A arvore so e igual a do commit sem pendencias (rastreadas ou novas nao ignoradas).
    Garantir ($e.arvore_igual_ao_commit -eq (-not $pendente)) 'arvore_igual_ao_commit segue o git status'
    Garantir ($e.valida_para_commit -eq ($e.verde -and $e.arvore_igual_ao_commit)) 'valida_para_commit = verde e arvore do commit'
    if (-not $pendente) { Garantir ($e.arvore -eq $arvoreDoCommit) 'sem pendencias, arvore = arvore do commit (inclusive bit de executavel)' }
    Garantir (Test-Path -LiteralPath (Join-Path $temporario 'historico.jsonl')) 'historico gravado'
    Garantir (Test-Path -LiteralPath $e.saida_pest) 'saida completa do Pest referenciada na evidencia'
    $conteudoPest = [IO.File]::ReadAllText($e.saida_pest)
    Garantir ($conteudoPest -match 'Detalhe completo do Pest falso' -and $conteudoPest -match 'Tests: falso') 'saida preserva detalhes alem do resumo'
    Garantir ([IO.File]::ReadAllText((Join-Path $temporario 'pest-ultimo.txt')) -eq $conteudoPest) 'atalho para ultimo Pest definitivo'
    $primeiroLog = $e.saida_pest

    # 2. Gate parcial: as etapas pedidas passam (saida 0), mas nunca vale como evidencia.
    Limpar
    $r = RodarGate @('-Etapas', 'pint,pest', '-Saida', $saida)
    Garantir ($r.codigo -eq 0 -and $r.saida -match 'PARCIAL') "parcial passando sai com 0 e PARCIAL: $($r.saida)"
    $e = Evidencia
    Garantir ($e.completo -eq $false -and $e.verde -eq $false -and $e.valida_para_commit -eq $false) 'parcial nao e verde nem valido'
    Garantir ((($e.motivos -join ' ') -match 'gate parcial') -and @($e.etapas).Count -eq 2) 'motivo de parcial e so as etapas pedidas'

    # 3. Uma etapa falhando: VERMELHO, saida 1, motivo com o nome da etapa.
    Limpar
    $env:WALLE_TEST_POWERSHELL_EXIT = '1'
    $r = RodarGate @('-Saida', $saida)
    Garantir ($r.codigo -eq 1) "vermelho deveria sair com 1: $($r.saida)"
    $e = Evidencia
    Garantir ($e.verde -eq $false -and (($e.motivos -join ' ') -match 'etapas com falha: politicas, gate, tarefa')) "motivo com as etapas: $($e.motivos)"

    # Pest falhando tambem preserva a saida completa, sem sobrescrever o arquivo de outra execucao.
    Limpar
    $env:WALLE_TEST_DOCKER_EXIT = '1'
    $r = RodarGate @('-Etapas', 'pest', '-Saida', $saida)
    $e = Evidencia
    Garantir ($r.codigo -eq 1 -and $e.saida_pest -ne $primeiroLog) 'Pest falhou com arquivo proprio'
    Garantir ([IO.File]::ReadAllText($e.saida_pest) -match 'Detalhe completo do Pest falso') 'detalhe de falha preservado'
    Garantir (Test-Path -LiteralPath $primeiroLog) 'log anterior continua preservado'

    # 4. Sem -Banco, vale WALLE_HOOK_TEST_DATABASE (sessao paralela); o -Banco explicito vence.
    Limpar
    $env:WALLE_HOOK_TEST_DATABASE = 'walle_testes_t7'
    $r = RodarGate @('-Etapas', 'pest', '-Saida', $saida)
    $e = Evidencia
    Garantir ($e.banco -eq 'walle_testes_t7' -and $e.etapas[0].comando -match 'DB_DATABASE=walle_testes_t7 ') 'banco da sessao paralela'
    Garantir ($e.preliminar -eq $true) 'gate de sessao paralela e preliminar'
    $r = RodarGate @('-Etapas', 'pest', '-Saida', $saida, '-Banco', 'walle_testes_t9')
    Garantir ((Evidencia).banco -eq 'walle_testes_t9') '-Banco explicito vence'
    Garantir (Test-Path -LiteralPath (Join-Path $temporario 'pest-ultimo-walle_testes_t9.txt')) 'ultimo Pest separado por banco'

    # 5. Banco fora de walle_testes(_tNN), inclusive por variavel ou com maiusculas, e etapa desconhecida:
    #    recusados com saida 2, antes de rodar ou gravar qualquer coisa.
    $recusas = @(
        @{ argumentos = @('-Banco', 'walle'); variavel = $null },
        @{ argumentos = @('-Banco', 'WALLE_TESTES'); variavel = $null },
        @{ argumentos = @('-Banco', 'walle_testes_x'); variavel = $null },
        @{ argumentos = @(); variavel = 'walle' },
        @{ argumentos = @('-Etapas', 'deploy'); variavel = $null },
        @{ argumentos = @('-Etapas', 'pint,deploy'); variavel = $null }
    )
    foreach ($recusa in $recusas) {
        Limpar
        if ($recusa.variavel) { $env:WALLE_HOOK_TEST_DATABASE = $recusa.variavel }
        $r = RodarGate (@($recusa.argumentos) + @('-Saida', $saida))
        Garantir ($r.codigo -eq 2) "deveria recusar com 2: $($recusa.argumentos -join ' ') / $($recusa.variavel): $($r.saida)"
        Garantir (-not (Test-Path -LiteralPath $saida)) "nao deveria gravar evidencia: $($recusa.argumentos -join ' ')"
    }

    # 6. Arquivos mudando durante o gate: VERMELHO, mesmo com as etapas passando.
    Limpar
    $env:WALLE_TEST_DOCKER_TOCA = $arquivoNovo
    $r = RodarGate @('-Etapas', 'pint', '-Saida', $saida)
    Garantir ($r.codigo -eq 1 -and ((Evidencia).motivos -join ' ') -match 'mudaram durante o gate') "arquivo novo no meio do gate deveria dar VERMELHO: $($r.saida)"

    # 7. Comando inexistente: codigo 127 e VERMELHO.
    Limpar
    $env:PATH = $semDocker + ';' + (Split-Path -Parent $gitReal)
    $r = RodarGate @('-Etapas', 'pint', '-Saida', $saida)
    $env:PATH = $temporario + ';' + $pathAnterior
    Garantir ($r.codigo -eq 1 -and (Evidencia).etapas[0].codigo -eq 127) "docker ausente deveria dar 127: $($r.saida)"

    # 8. O git falhando ao montar a arvore (git add abortado) nao pode virar "arvore do commit": VERMELHO.
    Limpar
    [IO.File]::WriteAllText($arquivoNovo, "linha com CRLF`r`n")
    $env:GIT_CONFIG_COUNT = '1'
    $env:GIT_CONFIG_KEY_0 = 'core.safecrlf'
    $env:GIT_CONFIG_VALUE_0 = 'true'
    $r = RodarGate @('-Etapas', 'pint', '-Saida', $saida)
    $e = Evidencia
    Garantir ($r.codigo -eq 1 -and (($e.motivos -join ' ') -match 'git falhou')) "git add abortado deveria dar VERMELHO: $($r.saida)"
    Garantir ($e.arvore -eq '' -and $e.arvore_igual_ao_commit -eq $false -and $e.valida_para_commit -eq $false) 'sem arvore, nada valido'

    # 9. A evidencia e gravada como "em andamento" logo no inicio (gate interrompido nao deixa verde antigo).
    Limpar
    [IO.File]::WriteAllText($saida, '{"verde": true}')
    $env:WALLE_TEST_GATE_SAIDA = $saida
    $env:WALLE_TEST_GATE_COPIA = $copia
    $r = RodarGate @('-Etapas', 'pint', '-Saida', $saida)
    $durante = Get-Content -LiteralPath $copia -Raw | ConvertFrom-Json
    Garantir ($durante.verde -eq $false -and (($durante.motivos -join ' ') -match 'em andamento')) 'durante o gate a evidencia diz em andamento'

    # Trava de evidencia: uma segunda execucao recusa sem apagar o resultado do dono.
    Limpar
    . "$raiz/.claude/hooks/trava-testes.ps1"
    [IO.File]::WriteAllText($saida, '{"dono": "primeiro"}')
    $trava = AbrirTravaWalle ('gate:' + [IO.Path]::GetFullPath($saida))
    try {
        $r = RodarGate @('-Etapas', 'pint', '-Saida', $saida)
        Garantir ($r.codigo -eq 2 -and (Evidencia).dono -eq 'primeiro') 'gate concorrente nao sobrescreve evidencia'
    } finally { FecharTravaWalle $trava }

    # A mesma trava de banco e usada pelo Stop. Bancos diferentes continuam independentes.
    Limpar
    $trava = AbrirTravaWalle 'docker:walle-app:banco:walle_testes_t98765'
    try {
        $r = RodarGate @('-Etapas', 'pest', '-Banco', 'walle_testes_t98765', '-Saida', $saida)
        Garantir ($r.codigo -eq 1 -and (Evidencia).valida_para_commit -eq $false) 'banco ocupado nao gera validacao'
        Garantir ([IO.File]::ReadAllText((Evidencia).saida_pest) -match 'Banco de testes ocupado') 'banco ocupado nao chama Docker'
        $r = RodarGate @('-Etapas', 'pest', '-Banco', 'walle_testes_t98766', '-Saida', $saida)
        Garantir ($r.codigo -eq 0) 'banco diferente pode validar'
    } finally { FecharTravaWalle $trava }
    $r = RodarGate @('-Etapas', 'pest', '-Banco', 'walle_testes_t98765', '-Saida', $saida)
    Garantir ($r.codigo -eq 0) 'banco liberado depois da validacao'

    # Falha na gravacao dos logs impede uma etapa Pest verde.
    Limpar
    $pastaSemLog = Join-Path $temporario 'sem-log'
    [IO.Directory]::CreateDirectory($pastaSemLog) | Out-Null
    [IO.File]::WriteAllText((Join-Path $pastaSemLog 'pest'), 'arquivo bloqueia a pasta')
    $saidaSemLog = Join-Path $pastaSemLog 'gate.json'
    $r = RodarGate @('-Etapas', 'pest', '-Saida', $saidaSemLog)
    $e = [IO.File]::ReadAllText($saidaSemLog) | ConvertFrom-Json
    Garantir ($r.codigo -eq 1 -and $e.etapas[0].codigo -eq 1 -and $e.valida_para_commit -eq $false) 'sem log preservado, gate falha fechado'
} finally {
    $env:PATH = $pathAnterior
    Limpar
    foreach ($nome in @('WALLE_TEST_DOCKER_EXIT', 'WALLE_TEST_POWERSHELL_EXIT')) { Remove-Item "Env:$nome" -ErrorAction SilentlyContinue }
    if ($bancoAnterior) { $env:WALLE_HOOK_TEST_DATABASE = $bancoAnterior }
    Remove-Item -LiteralPath $temporario -Recurse -Force -ErrorAction SilentlyContinue
}
Write-Output "$script:casos casos do gate passaram."
