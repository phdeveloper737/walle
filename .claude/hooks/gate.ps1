# Gate do walle: roda os validadores de uma vez e grava a evidencia em .walle/gate/ (e no historico).
# A evidencia amarra o resultado a arvore de arquivos (git write-tree num indice temporario que parte do HEAD,
# sem tocar no indice real). Ela so vale como prova quando "valida_para_commit" e true: gate completo, todas as
# etapas com codigo 0, arvore igual do inicio ao fim e igual a do commit (faca o commit antes de rodar o gate).
# Os arquivos ignorados (vendor, .env, build) ficam fora da arvore: a prova em ambiente limpo e o CI, que roda
# as mesmas etapas (.github/workflows/ci.yml); mudou uma, mude a outra.
# Saida: 0 = etapas pedidas passaram com a arvore estavel (VERDE no completo, PARCIAL no parcial); 1 = VERMELHO;
# 2 = argumento invalido (nada roda nem e gravado).
# Sem acentos: o PowerShell 5.1 le este arquivo como ANSI.
param(
    # Banco de testes; sem ele vale WALLE_HOOK_TEST_DATABASE (sessao paralela) e depois walle_testes.
    [string]$Banco,
    # Separadas por virgula (com -File o PowerShell nao monta lista): pint,phpstan,pest,politicas,gate,tarefa,plugin,rag
    [string]$Etapas = 'pint,phpstan,pest,politicas,gate,tarefa,plugin,rag',
    [string]$Saida
)

$ErrorActionPreference = 'Stop'
$conhecidas = @('pint', 'phpstan', 'pest', 'politicas', 'gate', 'tarefa', 'plugin', 'rag')

if (-not $Banco) { $Banco = if ($env:WALLE_HOOK_TEST_DATABASE) { $env:WALLE_HOOK_TEST_DATABASE } else { 'walle_testes' } }
if ($Banco -cnotmatch '^walle_testes(_t[0-9]+)?\z') {
    [Console]::Error.WriteLine("[gate] Banco invalido: $Banco. Use walle_testes ou walle_testes_tNN.")
    exit 2
}
# Virgula ou espaco: chamado de outro PowerShell, "pint,pest" chega como lista e vira "pint pest" no [string].
$listaEtapas = @($Etapas.Split(@(",", " "), [StringSplitOptions]::RemoveEmptyEntries))
$desconhecidas = @($listaEtapas | Where-Object { $conhecidas -cnotcontains $_ })
if (-not $listaEtapas.Count -or $desconhecidas.Count) {
    [Console]::Error.WriteLine("[gate] Etapa desconhecida: $($desconhecidas -join ', '). Use: $($conhecidas -join ', ').")
    exit 2
}
$completo = @($conhecidas | Where-Object { $listaEtapas -cnotcontains $_ }).Count -eq 0

$raiz = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$raizDocker = $raiz.Replace('\', '/').TrimEnd('/')
if (-not $Saida) {
    # Um arquivo por banco (sessoes paralelas nao sobrescrevem a evidencia uma da outra) e o parcial a parte.
    $nome = $(if ($completo) { 'ultimo' } else { 'parcial' }) + $(if ($Banco -ceq 'walle_testes') { '' } else { "-$Banco" })
    $Saida = Join-Path $raiz ".walle/gate/$nome.json"
}

$comandos = [ordered]@{
    pint      = @('docker', 'exec', 'walle-app', './vendor/bin/pint', '--test')
    phpstan   = @('docker', 'exec', 'walle-app', './vendor/bin/phpstan', 'analyse', '--no-progress', '--memory-limit=1G')
    pest      = @('docker', 'exec', '-e', "DB_DATABASE=$Banco", 'walle-app', './vendor/bin/pest', '--compact')
    politicas = @('powershell', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $raiz '.claude/hooks/tests/agent-policy.Tests.ps1'))
    gate      = @('powershell', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $raiz '.claude/hooks/tests/gate.Tests.ps1'))
    tarefa    = @('powershell', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $raiz '.claude/hooks/tests/tarefa.Tests.ps1'))
    plugin    = @('docker', 'run', '--rm', '-v', "${raizDocker}:/app", '-w', '/app', 'node:24-alpine', 'node', '--test', '.opencode/plugin/agent-hooks.test.mjs')
    # Fontes atuais do RAG sobre as dependencias da imagem walle-rag:local (a imagem fica igual; dependencia nova pede rebuild)
    rag       = @('docker', 'run', '--rm', '-v', "${raizDocker}/.claude/scripts:/src:ro", 'walle-rag:local', 'sh', '-c', 'mkdir /tmp/rag && cp -r /src/. /tmp/rag/ && ln -s /app/node_modules /tmp/rag/node_modules && cd /tmp/rag && node --test tests/rag.test.ts')
}

# Hash da arvore de trabalho como seria commitada; '' se qualquer comando do git falhar (falha fechada:
# um "git add" abortado deixaria o indice igual ao HEAD e a arvore pareceria a do commit).
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
        if ($LASTEXITCODE -ne 0) { return '' }
        & git -C $raiz add -A 2>&1 | Out-Null
        if ($LASTEXITCODE -ne 0) { return '' }
        $arvore = "$(& git -C $raiz write-tree 2>$null)".Trim()
        if ($LASTEXITCODE -ne 0 -or $arvore -cnotmatch '^[0-9a-f]{40}\z') { return '' }
        return $arvore
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
# O codigo comeca em -1: se o processo nem chegar a rodar, a etapa falha (falha fechada).
function Rodar([string[]]$Comando) {
    if (-not (Get-Command $Comando[0] -ErrorAction SilentlyContinue)) { return @{ codigo = 127; saida = @("comando nao encontrado: $($Comando[0])") } }
    $preferencia = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $global:LASTEXITCODE = -1
        $saida = @(& $Comando[0] @($Comando | Select-Object -Skip 1) 2>&1 | ForEach-Object { [string]$_ })
        return @{ codigo = [int]$LASTEXITCODE; saida = $saida }
    } catch {
        return @{ codigo = 1; saida = @("falha ao executar: $($_.Exception.Message)") }
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

$utf8 = New-Object Text.UTF8Encoding($false)
function Gravar($Evidencia, [switch]$Historico) {
    [IO.Directory]::CreateDirectory((Split-Path -Parent $Saida)) | Out-Null
    [IO.File]::WriteAllText($Saida, ($Evidencia | ConvertTo-Json -Depth 5), $utf8)
    if ($Historico) { [IO.File]::AppendAllText((Join-Path (Split-Path -Parent $Saida) 'historico.jsonl'), ($Evidencia | ConvertTo-Json -Depth 5 -Compress) + "`n", $utf8) }
}

$inicio = Get-Date
$commit = ConsultarGit @('rev-parse', 'HEAD')
$arvoreDoCommit = ConsultarGit @('rev-parse', 'HEAD^{tree}')
$evidencia = [ordered]@{
    versao                 = 2
    verde                  = $false
    valida_para_commit     = $false
    completo               = $completo
    # Sessao paralela (walle_testes_tNN): gate preliminar; o definitivo roda sozinho, no walle_testes.
    preliminar             = ($Banco -cne 'walle_testes')
    motivos                = @('em andamento')
    commit                 = $commit
    branch                 = ConsultarGit @('branch', '--show-current')
    arvore                 = ''
    arvore_igual_ao_commit = $false
    banco                  = $Banco
    inicio                 = $inicio.ToString('yyyy-MM-dd HH:mm:ss')
    fim                    = ''
    etapas                 = @()
}
# Grava ja no inicio: se o gate for interrompido, a evidencia anterior nao continua parecendo atual.
Gravar $evidencia

$arvoreInicial = Arvore
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
if (-not $arvoreInicial -or -not $arvoreFinal) { $motivos += 'nao foi possivel calcular a arvore (o git falhou)' }
elseif ($arvoreInicial -cne $arvoreFinal) { $motivos += 'os arquivos mudaram durante o gate' }
$passou = $motivos.Count -eq 0
if (-not $completo) { $motivos += 'gate parcial (' + ($listaEtapas -join ', ') + '): nao vale como evidencia' }

$evidencia.verde = $passou -and $completo
$evidencia.arvore = $arvoreFinal
$evidencia.arvore_igual_ao_commit = [bool]($arvoreFinal -and $arvoreFinal -ceq $arvoreDoCommit)
$evidencia.valida_para_commit = $evidencia.verde -and $evidencia.arvore_igual_ao_commit
$evidencia.motivos = $motivos
$evidencia.fim = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
$evidencia.etapas = $resultados
Gravar $evidencia -Historico

$veredito = if ($evidencia.verde) { 'VERDE' } elseif ($passou) { 'PARCIAL (etapas pedidas passaram)' } else { 'VERMELHO (' + ($motivos -join '; ') + ')' }
Write-Output "[gate] $veredito | arvore $arvoreFinal | igual ao commit: $($evidencia.arvore_igual_ao_commit) | valida para o commit: $($evidencia.valida_para_commit) | evidencia: $Saida"
if ($passou) { exit 0 }
exit 1
