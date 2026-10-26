# Gate do walle: roda os validadores de uma vez e grava a evidencia em .walle/gate/ultimo.json (e no historico).
# A evidencia amarra o resultado a arvore de arquivos (git write-tree num indice temporario, sem tocar no indice
# real): o gate so vale para um commit cuja arvore seja a mesma. As etapas sao as mesmas dos jobs do CI
# (.github/workflows/ci.yml); mudou uma, mude a outra.
# Saida 0 = VERDE (todas as etapas com codigo 0 e arvore igual do inicio ao fim); 1 = VERMELHO.
# Sem acentos: o PowerShell 5.1 le este arquivo como ANSI.
param(
    [ValidatePattern('^walle_testes(_t[0-9]+)?$')][string]$Banco = 'walle_testes',
    # Separadas por virgula (com -File o PowerShell nao monta lista): pint,phpstan,pest,politicas,gate,plugin,rag
    [string]$Etapas = 'pint,phpstan,pest,politicas,gate,plugin,rag',
    [string]$Saida
)

$ErrorActionPreference = 'Stop'
$conhecidas = @('pint', 'phpstan', 'pest', 'politicas', 'gate', 'plugin', 'rag')
# Virgula ou espaco: chamado de outro PowerShell, "pint,pest" chega como lista e vira "pint pest" no [string].
$listaEtapas = @($Etapas.Split(@(",", " "), [StringSplitOptions]::RemoveEmptyEntries))
$desconhecidas = @($listaEtapas | Where-Object { $conhecidas -notcontains $_ })
if (-not $listaEtapas.Count -or $desconhecidas.Count) {
    [Console]::Error.WriteLine("[gate] Etapa desconhecida: $($desconhecidas -join ', '). Use: $($conhecidas -join ', ').")
    exit 2
}
$raiz = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$raizDocker = $raiz.Replace('\', '/').TrimEnd('/')
if (-not $Saida) { $Saida = Join-Path $raiz '.walle/gate/ultimo.json' }

$comandos = [ordered]@{
    pint      = @('docker', 'exec', 'walle-app', './vendor/bin/pint', '--test')
    phpstan   = @('docker', 'exec', 'walle-app', './vendor/bin/phpstan', 'analyse', '--no-progress', '--memory-limit=1G')
    pest      = @('docker', 'exec', '-e', "DB_DATABASE=$Banco", 'walle-app', './vendor/bin/pest', '--compact')
    politicas = @('powershell', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $raiz '.claude/hooks/tests/agent-policy.Tests.ps1'))
    gate      = @('powershell', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $raiz '.claude/hooks/tests/gate.Tests.ps1'))
    plugin    = @('docker', 'run', '--rm', '-v', "${raizDocker}:/app", '-w', '/app', 'node:24-alpine', 'node', '--test', '.opencode/plugin/agent-hooks.test.mjs')
    # Fontes atuais do RAG sobre as dependencias da imagem walle-rag:local (a imagem fica igual; dependencia nova pede rebuild)
    rag       = @('docker', 'run', '--rm', '-v', "${raizDocker}/.claude/scripts:/src:ro", 'walle-rag:local', 'sh', '-c', 'mkdir /tmp/rag && cp -r /src/. /tmp/rag/ && ln -s /app/node_modules /tmp/rag/node_modules && cd /tmp/rag && node --test tests/rag.test.ts')
}

function Arvore {
    $indice = Join-Path ([IO.Path]::GetTempPath()) ('walle-gate-' + [guid]::NewGuid().ToString('N') + '.index')
    $anterior = $env:GIT_INDEX_FILE
    $preferencia = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $env:GIT_INDEX_FILE = $indice
        # Parte do HEAD: com core.fileMode=false (Windows) o git so preserva o bit de executavel do que ja
        # esta no indice; num indice vazio tudo viraria 644 e a arvore nunca bateria com a do commit.
        & git -C $raiz read-tree HEAD 2>&1 | Out-Null
        & git -C $raiz add -A 2>&1 | Out-Null
        return "$(& git -C $raiz write-tree 2>$null)".Trim()
    } finally {
        $ErrorActionPreference = $preferencia
        if ($anterior) { $env:GIT_INDEX_FILE = $anterior } else { Remove-Item Env:GIT_INDEX_FILE -ErrorAction SilentlyContinue }
        Remove-Item -LiteralPath $indice -Force -ErrorAction SilentlyContinue
    }
}

function ConsultarGit([string[]]$Argumentos) {
    $preferencia = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    # "$(...)" e nao [string](...): sem saida (ex.: branch em HEAD destacado no CI) o cast daria nulo.
    try { return "$(& git -C $raiz @Argumentos 2>$null)".Trim() } finally { $ErrorActionPreference = $preferencia }
}

# Chamada com & (e nao Process.Start) para achar duplos .cmd no PATH, como nos testes das politicas.
function Rodar([string[]]$Comando) {
    if (-not (Get-Command $Comando[0] -ErrorAction SilentlyContinue)) { return @{ codigo = 127; saida = @("comando nao encontrado: $($Comando[0])") } }
    $preferencia = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $global:LASTEXITCODE = 0
        $saida = @(& $Comando[0] @($Comando | Select-Object -Skip 1) 2>&1 | ForEach-Object { [string]$_ })
        return @{ codigo = [int]$LASTEXITCODE; saida = $saida }
    } finally { $ErrorActionPreference = $preferencia }
}

function Resumo([string[]]$Linhas) {
    $limpas = @($Linhas | ForEach-Object { ($_ -replace "\x1b\[[0-9;]*m", '').Trim() } | Where-Object { $_ -and $_ -notmatch '^[.\s]+$' })
    # Prefere as linhas de contagem (Pest, Pint, PHPStan, testes PowerShell e node --test); senao, as ultimas.
    $contagens = @($limpas | Where-Object { $_ -match '^(Tests:|PASS\b|FAIL\b)|\[OK\]|\[ERROR\]|casos .* passaram|^\S{0,2}\s*(tests|pass|fail) \d+$' })
    $escolhidas = if ($contagens.Count) { $contagens } else { $limpas }
    $ultimas = @($escolhidas | Select-Object -Last 3) -join ' | '
    if ($ultimas.Length -gt 400) { $ultimas = $ultimas.Substring(0, 400) }
    return $ultimas
}

$inicio = Get-Date
$arvoreInicial = Arvore
$commit = ConsultarGit @('rev-parse', 'HEAD')
$arvoreDoCommit = ConsultarGit @('rev-parse', 'HEAD^{tree}')
$resultados = @()

foreach ($etapa in $listaEtapas) {
    $comando = $comandos[$etapa]
    Write-Output "[gate] $etapa ..."
    $relogio = [Diagnostics.Stopwatch]::StartNew()
    $execucao = Rodar $comando
    $relogio.Stop()
    $resultados += [ordered]@{
        nome      = $etapa
        comando   = ($comando -join ' ')
        codigo    = $execucao.codigo
        duracao_s = [Math]::Round($relogio.Elapsed.TotalSeconds, 1)
        resumo    = Resumo $execucao.saida
    }
    Write-Output ("[gate] {0}: {1} ({2} s) {3}" -f $etapa, $(if ($execucao.codigo -eq 0) { 'PASSOU' } else { "FALHOU, codigo $($execucao.codigo)" }), $resultados[-1].duracao_s, $resultados[-1].resumo)
}

$arvoreFinal = Arvore
$falhas = @($resultados | Where-Object { $_.codigo -ne 0 } | ForEach-Object { $_.nome })
$motivos = @()
if ($falhas.Count) { $motivos += 'etapas com falha: ' + ($falhas -join ', ') }
if (-not $arvoreInicial -or $arvoreInicial -ne $arvoreFinal) { $motivos += 'os arquivos mudaram durante o gate' }
$verde = $motivos.Count -eq 0

$evidencia = [ordered]@{
    versao                 = 1
    verde                  = $verde
    motivos                = $motivos
    commit                 = $commit
    branch                 = ConsultarGit @('branch', '--show-current')
    arvore                 = $arvoreFinal
    arvore_igual_ao_commit = ($arvoreFinal -and $arvoreFinal -eq $arvoreDoCommit)
    banco                  = $Banco
    inicio                 = $inicio.ToString('yyyy-MM-dd HH:mm:ss')
    fim                    = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
    etapas                 = $resultados
}

$utf8 = New-Object Text.UTF8Encoding($false)
[IO.Directory]::CreateDirectory((Split-Path -Parent $Saida)) | Out-Null
[IO.File]::WriteAllText($Saida, ($evidencia | ConvertTo-Json -Depth 5), $utf8)
[IO.File]::AppendAllText((Join-Path (Split-Path -Parent $Saida) 'historico.jsonl'), ($evidencia | ConvertTo-Json -Depth 5 -Compress) + "`n", $utf8)

$veredito = if ($verde) { 'VERDE' } else { 'VERMELHO (' + ($motivos -join '; ') + ')' }
Write-Output "[gate] $veredito | arvore $arvoreFinal | igual ao commit: $($evidencia.arvore_igual_ao_commit) | evidencia: $Saida"
if ($verde) { exit 0 }
exit 1
