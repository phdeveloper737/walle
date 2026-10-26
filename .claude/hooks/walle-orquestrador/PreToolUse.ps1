$ErrorActionPreference = 'Stop'
& "$PSScriptRoot/../agent-policy.ps1" -Agent 'walle-orquestrador' -Event 'PreToolUse'
exit $LASTEXITCODE
