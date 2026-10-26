$ErrorActionPreference = 'Stop'
& "$PSScriptRoot/../agent-policy.ps1" -Agent 'walle-dados' -Event 'PreToolUse'
exit $LASTEXITCODE
