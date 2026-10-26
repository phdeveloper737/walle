[Console]::InputEncoding = New-Object Text.UTF8Encoding $false
# Testes do gate (.claude/hooks/gate.ps1) com um Docker falso: so as etapas pint e pest, para nao chamar
# os proprios testes de novo. Sem acentos: o PowerShell 5.1 le este arquivo como ANSI.
$ErrorActionPreference = 'Stop'
$raiz = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../..'))
$gate = Join-Path $raiz '.claude/hooks/gate.ps1'
$script:casos = 0

$temporario = Join-Path ([IO.Path]::GetTempPath()) ('walle-gate-tests-' + [guid]::NewGuid().ToString('N'))
[IO.Directory]::CreateDirectory($temporario) | Out-Null
$saida = Join-Path $temporario 'ultimo.json'
$pathAnterior = $env:PATH

function RodarGate([string[]]$Argumentos) {
    $preferencia = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $texto = & powershell -NoProfile -ExecutionPolicy Bypass -File $gate @Argumentos 2>&1 | ForEach-Object { [string]$_ }
        return @{ codigo = $LASTEXITCODE; saida = ($texto -join "`n") }
    } finally { $ErrorActionPreference = $preferencia }
}
function Garantir([bool]$Condicao, [string]$Mensagem) {
    if (-not $Condicao) { throw "gate: $Mensagem" }
    $script:casos++
}

try {
    [IO.File]::WriteAllText((Join-Path $temporario 'docker.cmd'), "@echo off`r`necho Tests: falso %*`r`nexit /b %WALLE_TEST_DOCKER_EXIT%`r`n")
    $env:PATH = $temporario + ';' + $env:PATH

    # Tudo passando: VERDE, saida 0, uma etapa por pedido, arvore com 40 hex.
    $env:WALLE_TEST_DOCKER_EXIT = '0'
    $r = RodarGate @('-Etapas', 'pint,pest', '-Saida', $saida)
    Garantir ($r.codigo -eq 0) "verde deveria sair com 0: $($r.saida)"
    $e = Get-Content -LiteralPath $saida -Raw | ConvertFrom-Json
    Garantir ($e.verde -eq $true) 'verde deveria ser true'
    Garantir (@($e.etapas).Count -eq 2 -and $e.etapas[0].nome -eq 'pint' -and $e.etapas[1].nome -eq 'pest') 'etapas pedidas, na ordem'
    Garantir (@($e.etapas | Where-Object { $_.codigo -ne 0 }).Count -eq 0) 'codigos 0'
    Garantir ($e.arvore -match '^[0-9a-f]{40}$') "arvore invalida: $($e.arvore)"
    Garantir ($e.commit -match '^[0-9a-f]{40}$') "commit invalido: $($e.commit)"
    Garantir ($e.banco -eq 'walle_testes') 'banco padrao'
    Garantir ($e.etapas[1].comando -match 'DB_DATABASE=walle_testes ') 'pest no banco de testes'
    Garantir ($e.etapas[1].resumo -match 'Tests: falso') 'resumo vem da saida do comando'
    Garantir (Test-Path -LiteralPath (Join-Path $temporario 'historico.jsonl')) 'historico gravado'

    # A arvore so e igual a do commit quando nao ha nada pendente (rastreado ou novo e nao ignorado).
    $pendente = "$(& git -C $raiz status --porcelain)"
    $arvoreDoCommit = "$(& git -C $raiz rev-parse 'HEAD^{tree}')".Trim()
    Garantir ($e.arvore_igual_ao_commit -eq (-not $pendente.Trim())) 'arvore_igual_ao_commit segue o git status'
    if (-not $pendente.Trim()) { Garantir ($e.arvore -eq $arvoreDoCommit) 'sem pendencias, arvore = arvore do commit' }

    # Uma etapa falhando: VERMELHO, saida 1, motivo com o nome da etapa.
    $env:WALLE_TEST_DOCKER_EXIT = '1'
    $r = RodarGate @('-Etapas', 'pint,pest', '-Saida', $saida, '-Banco', 'walle_testes_t7')
    Garantir ($r.codigo -eq 1) "vermelho deveria sair com 1: $($r.saida)"
    $e = Get-Content -LiteralPath $saida -Raw | ConvertFrom-Json
    Garantir ($e.verde -eq $false) 'verde deveria ser false'
    Garantir (($e.motivos -join ' ') -match 'pint, pest') "motivo com as etapas: $($e.motivos)"
    Garantir ($e.banco -eq 'walle_testes_t7' -and $e.etapas[1].comando -match 'DB_DATABASE=walle_testes_t7 ') 'banco da sessao paralela'
    Garantir (@(Get-Content -LiteralPath (Join-Path $temporario 'historico.jsonl')).Count -eq 2) 'historico acumula'

    # Banco fora de walle_testes(_tNN) e etapa desconhecida sao recusados antes de rodar qualquer coisa.
    Remove-Item -LiteralPath $saida
    foreach ($argumentos in @(@('-Banco', 'walle', '-Etapas', 'pint', '-Saida', $saida), @('-Banco', 'walle_testes_x', '-Etapas', 'pint', '-Saida', $saida), @('-Etapas', 'deploy', '-Saida', $saida))) {
        $r = RodarGate $argumentos
        Garantir ($r.codigo -ne 0) "deveria recusar $($argumentos -join ' ')"
        Garantir (-not (Test-Path -LiteralPath $saida)) "nao deveria gravar evidencia para $($argumentos -join ' ')"
    }
} finally {
    $env:PATH = $pathAnterior
    Remove-Item Env:WALLE_TEST_DOCKER_EXIT -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $temporario -Recurse -Force -ErrorAction SilentlyContinue
}
Write-Output "$script:casos casos do gate passaram."
