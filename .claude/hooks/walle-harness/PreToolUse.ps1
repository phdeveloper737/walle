$ErrorActionPreference = 'Stop'
& "$PSScriptRoot/../agent-policy.ps1" -Agent 'walle-harness' -Event 'PreToolUse'
exit $LASTEXITCODE
