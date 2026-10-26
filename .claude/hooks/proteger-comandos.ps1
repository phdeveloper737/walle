# Hook PreToolUse (Bash e PowerShell) do walle: barra comandos que exporiam segredos ou apagariam dados fora do banco de testes.
# Saida 2 bloqueia o comando e devolve a mensagem ao agente. Sem acentos: o PowerShell 5.1 le este arquivo como ANSI.
$ErrorActionPreference = 'Stop'

try {
    $entrada = [Console]::In.ReadToEnd() | ConvertFrom-Json
} catch {
    exit 0
}

$comando = [string]$entrada.tool_input.command
if ([string]::IsNullOrWhiteSpace($comando)) {
    exit 0
}

$segredos = @(
    @{ padrao = '(?<![\w.-])\.env(?!\.example)(\.[\w-]+)*(?![\w.-])'; motivo = 'le ou altera um arquivo .env (use o .env.example)' },
    @{ padrao = '\bprintenv\b|\bdocker\s+(inspect|compose\s+config)\b|\bconfig:show\b'; motivo = 'mostra variaveis de ambiente ou configuracao com credenciais' },
    @{ padrao = '\bdocker\s+exec\b.*\s(env|printenv)(\s|$)'; motivo = 'lista as variaveis de ambiente do conteiner' },
    @{ padrao = '\b(echo|printf|print|Write-Output|Write-Host)\b[^|;&]*\$\{?\w*(PASSWORD|SENHA|TOKEN|SECRET|KEY)'; motivo = 'imprime o valor de um segredo' },
    @{ padrao = '\benv\(\s*[''"]\w*(PASSWORD|SENHA|TOKEN|SECRET|KEY)|config\(\s*[''"]database\.connections\.[\w.]*(password|senha)'; motivo = 'le um segredo pela configuracao do Laravel' }
)

foreach ($regra in $segredos) {
    if ($comando -match $regra.padrao) {
        [Console]::Error.WriteLine("Bloqueado pelo hook do walle: o comando $($regra.motivo). Se for mesmo necessario, peca ao usuario para rodar.")
        exit 2
    }
}

# No Help Desk real, o acesso direto fica restrito a uma consulta explicitamente
# somente leitura pelo cliente MySQL/MariaDB. Em caso de duvida, bloqueia.
if ($comando -match '192\.168\.10\.254') {
    $clienteSql = $comando -match '(?i)^\s*(?:docker\s+exec\s+walle-app\s+)?(?:mysql|mariadb)(?:\.exe)?(?:\s|$)'
    $hostSql = $comando -match '(?i)(?:--host(?:=|\s+)[''\"]?192\.168\.10\.254\b|(?:^|\s)-h\s*[''\"]?192\.168\.10\.254\b)'
    $consultaLeitura = $comando -match '(?is)(?:--execute(?:=|\s+)|(?:^|\s)-e\s+)[''\"]?\s*(?:select|show|describe|desc|explain)\b'
    $semRiscoDeEscrita = $comando -notmatch '(?is)[;|&<>`$]|\b(?:update|insert|delete|replace|truncate|alter|create|drop|rename|grant|revoke|call|load\s+(?:data|file)|tx_senha|password|token|secret|private_key|certificado|pfx|p12)\b|\bfor\s+(?:update|share)\b|\block\s+in\s+share\s+mode\b|\binto\b|\bget_lock\s*\(|\brelease_lock\s*\(|:='

    if (-not ($clienteSql -and $hostSql -and $consultaLeitura -and $semRiscoDeEscrita)) {
        [Console]::Error.WriteLine('Bloqueado pelo hook do walle: no Help Desk real so e permitida uma consulta SELECT/SHOW/DESCRIBE/EXPLAIN explicita via cliente MySQL/MariaDB. Escritas e comandos ambiguos sao proibidos.')
        exit 2
    }
}

$destrutivo = '\b(migrate:(fresh|reset|rollback|refresh)|db:wipe|walle:importar-suporte)\b'
if ($comando -match $destrutivo -and $comando -notmatch 'DB_DATABASE=walle_testes') {
    [Console]::Error.WriteLine('Bloqueado pelo hook do walle: este comando apaga dados e so pode rodar contra um banco de testes (docker exec -e DB_DATABASE=walle_testes_tNN ...). Nunca contra o banco walle de desenvolvimento.')
    exit 2
}

# Merge automatico so com o CI verde: o plano do GitHub nao protege branch, entao a barreira e este hook.
# Aspas sao ignoradas na analise ("--admin" entre aspas tambem conta); merge pela API fica proibido.
$semAspas = $comando -replace '["'']', ''
if ($semAspas -match '(?i)\bgh(\.exe)?\s+api\b.*\bpulls/\d+/merge\b') {
    [Console]::Error.WriteLine('Bloqueado pelo hook do walle: merge pela API do GitHub nao e permitido. Use gh pr merge <numero> depois do CI verde.')
    exit 2
}
if ($semAspas -match '(?i)\bgh(\.exe)?\s+pr\s+merge\b') {
    if ($semAspas -match '(^|\s)--admin\b') {
        [Console]::Error.WriteLine('Bloqueado pelo hook do walle: merge com --admin passa por cima das protecoes. Faca o merge sem --admin, depois do CI verde e da revisao.')
        exit 2
    }
    if ($comando -match '[;&|<>`$\r\n]' -or $semAspas -notmatch '(?i)\bpr\s+merge\s+(\d+)\b') {
        [Console]::Error.WriteLine('Bloqueado pelo hook do walle: o merge precisa ser um comando simples com o numero do PR (gh pr merge <numero> ...), para o hook conferir o CI.')
        exit 2
    }
    $null = $semAspas -match '(?i)\bpr\s+merge\s+(\d+)\b'
    $numero = $Matches[1]
    $ErrorActionPreference = 'Continue'
    $global:LASTEXITCODE = -1
    & gh pr checks $numero 2>&1 | Out-Null
    $codigoChecks = $LASTEXITCODE
    if ($codigoChecks -ne 0) {
        [Console]::Error.WriteLine("Bloqueado pelo hook do walle: o CI do PR $numero nao esta todo verde (gh pr checks saiu com ${codigoChecks}: falha, pendente ou gh indisponivel). Espere o CI passar.")
        exit 2
    }
}

exit 0
