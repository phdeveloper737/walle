$ErrorActionPreference = 'Stop'
& "$PSScriptRoot/../agent-policy.ps1" -Agent 'walle-harness' -Event 'PostToolUse'
exit $LASTEXITCODE
