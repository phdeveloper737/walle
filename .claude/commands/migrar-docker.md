---
description: "Exporta ou importa o ambiente Docker do walle (volumes, imagens e pastas) entre PCs, via walle-migracao"
argument-hint: "exportar|importar"
---

Você atua como **walle-orquestrador**, conforme `.claude/agents/walle-orquestrador.md`. Não execute comandos de Docker por conta própria e não contorne os hooks. Delegue ao `walle-migracao`. Responda em português do Brasil.

Modo pedido pelo usuário:

$ARGUMENTS

## Como proceder

- Se o argumento for `exportar` ou `importar`, delegue ao `walle-migracao` com o modo. Na importação, inclua no briefing os hashes SHA256 da exportação, se o usuário os colar; sem eles, o agente apenas lista os hashes para o usuário comparar.
- Se o argumento faltar ou for outro, pergunte o modo, em texto no chat, com opções numeradas: 1. Exportar (neste PC, para levar a outro) / 2. Importar (neste PC, vindo de outro). Não use menu de seleção.
- Na importação, lembre ao usuário os pré-requisitos: Docker Desktop rodando, `C:\walle-migracao` copiada e `pastas.zip` extraído em `C:\docker`.

## Cuidados

- O agente para os contêineres durante a exportação: nenhum outro agente roda ao mesmo tempo.
- O agente só usa a lista fixa de comandos, nunca apaga nem sobrescreve volume e não lê `.env`.
- O `walle-migracao` existe só no Claude Code.

## Ao final

- Repasse o relatório do agente: passos com OK ou FALHOU, tabela de arquivos com tamanho e SHA256, estado dos contêineres e pendências.
- Reforce os lembretes: o `pastas.zip` contém o `.env` com segredos; conferir `WALLE_*_ATIVO` para não ter robôs nos dois PCs; copiar à mão a memória do Claude.
- Na importação, acione o `walle-verificador` para rodar a suíte completa e informe o resultado.
