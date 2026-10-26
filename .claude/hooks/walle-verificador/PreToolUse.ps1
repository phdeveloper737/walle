$ErrorActionPreference = 'Stop'
& "$PSScriptRoot/../agent-policy.ps1" -Agent 'walle-verificador' -Event 'PreToolUse'
exit $LASTEXITCODE
