$ErrorActionPreference = 'Stop'
& "$PSScriptRoot/../agent-policy.ps1" -Agent 'walle-seguranca' -Event 'PreToolUse'
exit $LASTEXITCODE
