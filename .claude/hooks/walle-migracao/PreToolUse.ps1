$ErrorActionPreference = 'Stop'
& "$PSScriptRoot/../agent-policy.ps1" -Agent 'walle-migracao' -Event 'PreToolUse'
exit $LASTEXITCODE
