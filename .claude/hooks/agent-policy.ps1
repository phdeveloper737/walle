param(
    [Parameter(Mandatory = $true)][string]$Agent,
    [Parameter(Mandatory = $true)][ValidateSet('PreToolUse', 'PostToolUse', 'Stop')][string]$Event,
    # Evento em JSON quando chamado de outro script (politica-global.ps1); sem ele, le da entrada padrao.
    [string]$Payload
)

$ErrorActionPreference = 'Stop'
function Bloquear([string]$Motivo) {
    [Console]::Error.WriteLine("Bloqueado pelo hook de ${Agent}: $Motivo")
    exit 2
}

# Arquivo que um make: gera, para conferir contra o dominio do agent.
function CaminhoDoMake([string]$Tipo, [string]$Nome, [string]$Opcoes) {
    switch ($Tipo) {
        'class' { return "app/$Nome.php" }
        'model' { return "app/Models/$Nome.php" }
        'job' { return "app/Jobs/$Nome.php" }
        'command' { return "app/Console/Commands/$Nome.php" }
        'listener' { return "app/Listeners/$Nome.php" }
        'factory' { return "database/factories/$Nome.php" }
        'seeder' { return "database/seeders/$Nome.php" }
        'migration' { return "database/migrations/$Nome.php" }
        'test' { if ($Opcoes -match '--unit') { return "tests/Unit/$Nome.php" } else { return "tests/Feature/$Nome.php" } }
    }
    return ''
}

# Caminho relativo a raiz; recusa arquivo fora do projeto e links.
function CaminhoRelativo([string]$Arquivo, [string]$Raiz) {
    if (-not $Arquivo) { Bloquear 'Edicao sem caminho de arquivo verificavel.' }
    if (-not [IO.Path]::IsPathRooted($Arquivo)) { $Arquivo = Join-Path $Raiz $Arquivo }
    $Arquivo = [IO.Path]::GetFullPath($Arquivo)
    if (-not $Arquivo.StartsWith($Raiz + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) { Bloquear 'Arquivo fora do projeto.' }
    # Junctions/symlinks tambem nao podem escapar da raiz autorizada.
    $ancestral = $Arquivo
    while ($ancestral -and $ancestral.Length -gt $Raiz.Length) {
        if (Test-Path -LiteralPath $ancestral) {
            if ((Get-Item -LiteralPath $ancestral -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) { Bloquear 'Edicao por link simbolico ou junction nao e permitida.' }
        }
        $ancestral = Split-Path -Parent $ancestral
    }
    return $Arquivo.Substring($Raiz.Length + 1).Replace('\', '/')
}

# Depois de uma edicao do harness: nenhuma definicao pode perder as travas.
function ConferirHarness([string]$Relativo, [string]$Raiz, $Politicas) {
    $caminho = Join-Path $Raiz $Relativo
    if (-not (Test-Path -LiteralPath $caminho -PathType Leaf)) { return }
    $texto = [IO.File]::ReadAllText($caminho)
    $frontmatter = ''
    if ($texto -match '(?s)\A\uFEFF?---\r?\n(?<fm>.*?)\r?\n---') { $frontmatter = $Matches.fm }
    $aviso = ' O arquivo ja foi gravado: desfaca a alteracao e relate ao usuario.'
    if ($Relativo -match '^\.(claude|opencode)/agents/(?<nome>walle-[a-z0-9-]+)\.md$') {
        $nome = $Matches.nome
        $alvo = $Politicas.PSObject.Properties[$nome].Value
        if (-not $alvo) { Bloquear "Agent $nome sem politica em agent-policies.json; so o usuario cadastra agents novos.$aviso" }
        if ($frontmatter -match '(?im)^\s*permissionMode\s*:') { Bloquear "permissionMode nao e permitido em $Relativo.$aviso" }
    }
    if ($Relativo -match '^\.claude/agents/(?<nome>walle-[a-z0-9-]+)\.md$') {
        $nome = $Matches.nome
        $alvo = $Politicas.PSObject.Properties[$nome].Value
        if ($frontmatter -notmatch "(?m)^name:\s*$nome\s*$") { Bloquear "O frontmatter de $Relativo precisa manter name: $nome.$aviso" }
        if ($frontmatter -notmatch 'PreToolUse:\s*-\s*matcher:\s*"\*"') { Bloquear "O frontmatter de $Relativo precisa manter o PreToolUse com matcher *.$aviso" }
        $eventos = @('PreToolUse')
        if ($alvo.tipo -eq 'implementacao') { $eventos += @('PostToolUse', 'Stop') }
        foreach ($evento in $eventos) {
            if (-not $frontmatter.Contains(".claude/hooks/$nome/$evento.ps1")) { Bloquear "O frontmatter de $Relativo perdeu o hook $evento.$aviso" }
        }
    }
    if ($Relativo -match '^\.(claude|opencode)/commands/|^\.(claude|agents)/skills/') {
        if ($texto -match '(?im)^\s*allowed-tools\s*:' -or $texto.Contains('!`')) { Bloquear "Commands e skills nao podem declarar allowed-tools nem executar comandos com !`...` ($Relativo).$aviso" }
    }
}

try {
    if (-not $Payload) { $Payload = [Console]::In.ReadToEnd() }
    $entrada = $Payload | ConvertFrom-Json
    $politicas = Get-Content -LiteralPath "$PSScriptRoot/agent-policies.json" -Raw | ConvertFrom-Json
    $politica = $politicas.PSObject.Properties[$Agent].Value
    if (-not $politica) { Bloquear 'Agent sem politica cadastrada.' }
    $raiz = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
    $ferramenta = [string]$entrada.tool_name
    $edicao = $ferramenta -match '^(Write|Edit|MultiEdit|write|edit|multiedit|apply_patch)$'
    $shell = $ferramenta -match '^(Bash|PowerShell|bash|shell)$'
    $comando = if ($shell) { [string]$entrada.tool_input.command } else { '' }
    $arquivoEditado = [string]$entrada.tool_input.file_path
    if (-not $arquivoEditado) { $arquivoEditado = [string]$entrada.tool_input.filePath }
    $prefixo = 'docker exec(?: -e DB_DATABASE=walle_testes(?:_t[0-9]+)?)? walle-app '
    $make = '^docker exec walle-app php artisan make:(?<tipo>class|model|migration|test|job|factory|seeder|command|listener) (?<nome>[A-Za-z0-9_/]+)(?<opcoes>(?: --(?:pest|unit|no-interaction|create=[a-z0-9_]+|table=[a-z0-9_]+))*)$'
    $ehMake = $shell -and $comando -match $make
    $testesDoHarness = @(
        'powershell -NoProfile -ExecutionPolicy Bypass -File .claude/hooks/tests/agent-policy.Tests.ps1',
        'docker run --rm -v C:/docker/walle:/app -w /app node:24-alpine node --test .opencode/plugin/agent-hooks.test.mjs'
    )
    # Marca de edicao por agent e sessao: o Stop so roda a suite se houve edicao.
    $chave = ($Agent + '_' + [string]$entrada.session_id + '_' + [string]$entrada.agent_id) -replace '[^A-Za-z0-9_-]', '_'
    $marcador = Join-Path ([IO.Path]::GetTempPath()) "walle-hooks/$chave.editou"

    # Evento de outro agent: quando o orquestrador e a sessao principal (--agent), os hooks dele tambem veem as
    # ferramentas dos subagents. A politica de cada subagent ja vale pelos hooks dele e pelo hook global.
    $outroAgent = [string]$entrada.agent_type
    if ($outroAgent -and $outroAgent -cne $Agent) { exit 0 }

    if ($Event -eq 'PreToolUse') {
        # Leitura: nunca terminal; edicao so nos arquivos da politica (o arquiteto grava apenas o parecer da revisao).
        if ($politica.tipo -eq 'leitura' -and ($shell -or ($edicao -and -not $politica.arquivos))) { Bloquear 'Este agent apenas le e analisa; nao pode editar arquivos nem executar comandos.' }
        # Orquestrador: delega o trabalho; no terminal, so o ledger e o Git das tarefas (tarefa.ps1, acoes fixas).
        if ($politica.tipo -eq 'orquestrador' -and $shell) {
            $tarefa = '^powershell -NoProfile -ExecutionPolicy Bypass -File \.claude/hooks/tarefa\.ps1 -Acao '
            if ($comando -cmatch ($tarefa + '(proxima|status)\z') -or $comando -cmatch ($tarefa + '(abrir|retomar|bloquear|commitar|publicar|integrar|status) -Tarefa T-[0-9]{4}\z')) { exit 0 }
            # Revisao obrigatoria do Codex (somente leitura), decisao do usuario de 27/10/2026.
            if ($comando -cmatch '^powershell -NoProfile -ExecutionPolicy Bypass -File \.claude/hooks/revisao-codex\.ps1 -Tarefa T-[0-9]{4}\z') { exit 0 }
            Bloquear 'O orquestrador so roda .claude/hooks/tarefa.ps1 (acao e id da tarefa) e .claude/hooks/revisao-codex.ps1 (id da tarefa); o resto e delegado aos especialistas.'
        }
        if ($politica.tipo -eq 'operacao' -and $edicao) { Bloquear 'O walle-migracao nao edita arquivos; so roda os comandos fixos de migracao do Docker.' }
        if ($politica.tipo -eq 'verificacao' -and $edicao) { Bloquear 'O verificador le e executa validadores; nao edita arquivos.' }
        if ($ferramenta -match '(?i)database|query|schema' -and $politica.tipo -in @('verificacao', 'orquestrador', 'harness', 'operacao')) { Bloquear 'Este agent nao acessa bancos por ferramentas; use os testes isolados.' }
        if ($edicao) {
            if ($ferramenta -match '^(MultiEdit|multiedit|apply_patch)$') { Bloquear 'Use Write/Edit por arquivo para validar o caminho antes da edicao.' }
            $relativo = CaminhoRelativo $arquivoEditado $raiz
            if ($relativo -notmatch $politica.arquivos) { Bloquear "Arquivo fora do dominio autorizado: $relativo." }
        }
        if ($shell) {
            # Lista positiva ancorada: sem shell composto, redirecionamento ou expansao.
            if ($comando -match '[;&|<>`$\r\n]' -or $comando -match '\.\.' -or $comando -match '["'']') { Bloquear 'Comandos compostos, expansoes e caminhos relativos ascendentes nao sao permitidos.' }
            # walle-migracao: lista fixa de exportacao e importacao do ambiente Docker. Nunca apaga; so restaura em volume vazio.
            if ($politica.tipo -eq 'operacao') {
                $destino = 'C:/walle-migracao'
                $volumes = 'walle_mysql|walle_valkey|testes220_mysql220|walle-rag_models'
                $imagens = 'walle-php testes220 walle-rag:local mysql:9.7 valkey/valkey:9 nginx:stable-alpine node:24-alpine ubuntu'
                $exatos = @(
                    'git status --short', 'docker ps -a', 'docker volume ls', 'docker images', 'Get-PSDrive C',
                    "New-Item -ItemType Directory -Force $destino", "Get-ChildItem $destino",
                    'docker compose --profile dev stop',
                    "docker save -o $destino/imagens.tar $imagens",
                    "docker save --platform linux/amd64 -o $destino/imagens.tar $imagens",
                    "tar -a -c -f $destino/pastas.zip --exclude walle/vendor --exclude walle/node_modules -C C:/docker walle testes220",
                    "docker load -i $destino/imagens.tar",
                    'docker volume create --label com.docker.compose.project=walle --label com.docker.compose.volume=mysql walle_mysql',
                    'docker volume create --label com.docker.compose.project=walle --label com.docker.compose.volume=valkey walle_valkey',
                    'docker volume create --label com.docker.compose.project=testes220 --label com.docker.compose.volume=mysql220 testes220_mysql220',
                    'docker volume create --label com.docker.compose.project=walle-rag --label com.docker.compose.volume=models walle-rag_models',
                    'docker exec walle-app composer install --no-interaction',
                    'docker exec walle-app php artisan optimize:clear',
                    'docker exec walle-app php artisan migrate:status',
                    'docker run --rm -v C:/docker/walle:/app -w /app node:24-alpine npm ci',
                    'docker run --rm -v C:/docker/walle:/app -w /app node:24-alpine npm run build'
                )
                if ($exatos -contains $comando) { exit 0 }
                if ($comando -match '^docker compose(?: -f (?:\.claude/scripts/compose\.yml|C:/docker/testes220/docker-compose\.yml))? (?:ps|stop|start|up -d)$') { exit 0 }
                if ($comando -match "^Get-FileHash -Algorithm SHA256 $destino/(?:imagens\.tar|pastas\.zip|volume-(?:$volumes)\.tar\.gz)$") { exit 0 }
                if ($comando -match "^docker run --rm -v (?<v>$volumes):/origem:ro -v ${destino}:/backup ubuntu tar --numeric-owner -czpf /backup/volume-\k<v>\.tar\.gz -C /origem \.$") { exit 0 }
                if ($comando -match "^docker run --rm -v (?<v>$volumes):/destino -v ${destino}:/backup:ro ubuntu tar --numeric-owner -xzpf /backup/volume-\k<v>\.tar\.gz -C /destino$") {
                    $volume = $Matches.v
                    # Confere que o volume esta vazio; stderr descartado sem derrubar o hook no PowerShell 5.1.
                    $ErrorActionPreference = 'Continue'
                    $conteudo = & docker run --rm -v "${volume}:/d" ubuntu ls -A /d 2>$null
                    $codigo = $LASTEXITCODE
                    $ErrorActionPreference = 'Stop'
                    if ($codigo -ne 0 -or $conteudo) { Bloquear "O volume $volume nao esta vazio ou nao pode ser conferido; a importacao nunca sobrescreve dados." }
                    exit 0
                }
                Bloquear 'O walle-migracao so roda os comandos fixos de exportacao e importacao do ambiente Docker.'
            }
            if ($politica.tipo -eq 'harness') {
                if ($testesDoHarness -contains $comando) { exit 0 }
                if ($comando -match '^git (status(?: --short)?|diff(?: --stat| --name-only)?)$') { exit 0 }
                Bloquear 'O walle-harness so roda os dois testes do harness e git status/diff.'
            }
            # Sem argumentos executa a suite inteira (Unit, Feature e Migrations pelo phpunit.xml).
            $pest = '^' + $prefixo + '\./vendor/bin/pest(?: (?:tests(?:/[A-Za-z0-9_./-]+)?|--(?:filter|group|exclude-group|testsuite)=[A-Za-z0-9_.-]+|--(?:compact|no-coverage|stop-on-failure)))* *$'
            if ($comando -match $pest) { exit 0 }
            if ($comando -match '^git (status(?: --short)?|diff(?: --stat| --name-only)?)$') { exit 0 }
            if ($comando -match ('^' + $prefixo + '\./vendor/bin/phpstan analyse(?: --no-progress)?$')) { exit 0 }
            if ($comando -match ('^' + $prefixo + '\./vendor/bin/pint(?<argumentos>(?: --test| --dirty| --format agent| (?:app|tests|database|routes|config)/[A-Za-z0-9_./-]+)+)$')) {
                $argumentosPint = $Matches.argumentos
                $teste = $argumentosPint -match ' --test\b'
                $sujo = $argumentosPint -match ' --dirty\b'
                $alvos = @($argumentosPint.Trim().Split(' ') | Where-Object { $_ -match '^(app|tests|database|routes|config)/' })
                if ($politica.tipo -eq 'verificacao') {
                    if ($teste) { exit 0 }
                    Bloquear 'O verificador so roda Pint com --test.'
                }
                if ($sujo) {
                    if ($alvos.Count -gt 0) { Bloquear 'Use --dirty ou a lista de arquivos, nao os dois.' }
                    if ($env:WALLE_HOOK_TEST_DATABASE) { Bloquear 'Sessao paralela (WALLE_HOOK_TEST_DATABASE definido): --dirty formataria arquivos de outras tasks; passe seus arquivos.' }
                    exit 0
                }
                foreach ($alvo in $alvos) {
                    if ($alvo -notmatch $politica.arquivos) { Bloquear 'Pint so pode receber arquivos do dominio deste agent.' }
                }
                if ($alvos.Count -gt 0 -or $teste) { exit 0 }
            }
            # Gate completo com evidencia em .walle/gate/ultimo.json: so o verificador, sem escolher etapas.
            if ($politica.tipo -eq 'verificacao' -and $comando -cmatch '^powershell -NoProfile -ExecutionPolicy Bypass -File \.claude/hooks/gate\.ps1(?: -Banco walle_testes(?:_t[0-9]+)?)?\z') { exit 0 }
            if ($politica.tipo -eq 'verificacao') { Bloquear 'O verificador so roda o gate, Pest, pint --test, phpstan analyse e git status/diff.' }
            if ($comando -match $make) {
                $tipo = $Matches.tipo
                $nome = $Matches.nome
                $opcoes = $Matches.opcoes
                if ($opcoes -match '--(pest|unit)' -and $tipo -ne 'test') { Bloquear '--pest e --unit so valem para make:test.' }
                if ($opcoes -match '--(create|table)=' -and $tipo -ne 'migration') { Bloquear '--create e --table so valem para make:migration.' }
                if ($tipo -eq 'migration' -and $nome -match '/') { Bloquear 'Nome de migration nao pode ter barra.' }
                $gerado = CaminhoDoMake $tipo $nome $opcoes
                if ($gerado -notmatch $politica.arquivos) { Bloquear "make:$tipo geraria $gerado, fora do dominio deste agent." }
                exit 0
            }
            if ($Agent -eq 'walle-telas' -and $comando -match '^docker run --rm -v C:/docker/walle:/app -w /app node:24-alpine npm run build$') { exit 0 }
            if ($Agent -eq 'walle-infra' -and $comando -match '^docker exec walle-app php artisan (?:optimize:clear|schedule:list|route:list(?: --except-vendor)?)$') { exit 0 }
            Bloquear 'Comando fora do escopo. Push, alteracoes Git, SQL direto, acesso externo e interpretadores arbitrarios nao sao permitidos. Use ferramentas de leitura/edicao e os validadores Docker autorizados.'
        }
        exit 0
    }

    if ($politica.tipo -eq 'harness') {
        if ($Event -eq 'PostToolUse' -and $edicao) { ConferirHarness (CaminhoRelativo $arquivoEditado $raiz) $raiz $politicas }
        exit 0
    }
    if ($politica.tipo -ne 'implementacao') { exit 0 }
    if ($Event -eq 'PostToolUse') {
        # Edicao ou make: so marca a edicao; a suite inteira roda no Stop.
        if ($edicao -or $ehMake) {
            [IO.Directory]::CreateDirectory((Split-Path -Parent $marcador)) | Out-Null
            [IO.File]::WriteAllText($marcador, [DateTime]::Now.ToString('o'))
        }
        exit 0
    }
    if ($Event -eq 'Stop') {
        if (-not (Test-Path -LiteralPath $marcador)) { exit 0 }
        # Segunda parada seguida: libera para evitar loop; o agent relata a validacao como PENDENTE.
        if ($entrada.stop_hook_active -eq $true) {
            Remove-Item -LiteralPath $marcador -Force
            exit 0
        }
    }
    Push-Location $raiz
    try {
        # O banco explicito nao depende de .env e nunca aponta ao desenvolvimento.
        $banco = 'walle_testes'
        if ($env:WALLE_HOOK_TEST_DATABASE) {
            if ($env:WALLE_HOOK_TEST_DATABASE -notmatch '^walle_testes_t[0-9]+$') { Bloquear 'WALLE_HOOK_TEST_DATABASE deve ser walle_testes_tNN.' }
            $banco = $env:WALLE_HOOK_TEST_DATABASE
        }
        $argumentos = @('exec', '-e', "DB_DATABASE=$banco", 'walle-app', './vendor/bin/pest')
        & docker @argumentos
        if ($LASTEXITCODE -ne 0) {
            Bloquear 'Os testes falharam ou nao puderam executar. Corrija o que e do seu dominio ou relate a pendencia; validacao nao concluida. Se a falha nao for sua, relate; na proxima parada o hook libera e a validacao fica PENDENTE.'
        }
    } finally { Pop-Location }
    Remove-Item -LiteralPath $marcador -Force -ErrorAction SilentlyContinue
    exit 0
} catch {
    Bloquear 'Falha ao validar o evento ou executar os testes. Verifique o payload e a disponibilidade do Docker.'
}
