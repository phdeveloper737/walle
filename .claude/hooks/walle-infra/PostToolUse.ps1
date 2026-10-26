$ErrorActionPreference = 'Stop'
& "$PSScriptRoot/../agent-policy.ps1" -Agent 'walle-infra' -Event 'PostToolUse'
exit $LASTEXITCODE
