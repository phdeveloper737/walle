# Relatório de uso — coleta de contingências

Entrega de 24/10/2026. Este documento acompanha o [relatório técnico](relatorio-tecnico.md) da task 3.1.

## O que foi preparado

Foi preparado o funcionamento interno do robô que reúne as notas com problemas de envio ou autorização. Ele pode guardar os resultados das verificações e os totais encontrados para cada cliente.

Uma falha de comunicação deixa registrado que a verificação não terminou. Os últimos dados válidos são preservados, e o trabalho pode seguir para o próximo cliente. Quando uma verificação posterior funciona, o registro da falha anterior continua disponível para acompanhamento.

Encontrar zero notas é diferente de não conseguir verificar. O robô só registra zero quando a coleta terminou e confirmou esse resultado.

## Isso muda meu uso do sistema agora?

**Ainda não há uma nova ação para o usuário nesta entrega. O robô permanece desligado.**

Você continua utilizando as telas que já existem. Não foi acrescentado botão para iniciar este robô nem uma nova tela para acompanhar suas verificações. Você não precisa executar comandos ou alterar configurações.

A programação das verificações, a ativação e a apresentação dos resultados no painel dependem das próximas entregas. A validação técnica deste trabalho não significa que a coleta automática já esteja funcionando para os clientes.

## O que foi verificado

Os testes confirmaram situações como coleta de mais de mil registros, falha de comunicação, recuperação após falha, cliente sem cadastro necessário e tentativa de duas verificações ao mesmo tempo. Também confirmaram que dados anteriores são preservados quando uma gravação não termina.

A suíte completa passou em 243 testes, sem falhas. Essa verificação ocorreu em ambiente de testes, sem consultar servidores reais dos clientes. A revisão técnica foi aprovada; o aceite final desta entrega pelo usuário ainda não foi registrado.

## Limitações que continuam

Algumas regras do sistema antigo foram mantidas. No Sactus, quando a quantidade de notas continua igual, os detalhes guardados anteriormente não são substituídos apenas porque algum texto mudou. Se o Sactus ficar sem notas, mas ainda existirem notas do Versatus, o histórico anterior do Sactus também é mantido. Essa regra poderá ser reavaliada em uma entrega específica.

O cadastro do Help Desk permanece somente para consulta: esta entrega não altera seus dados.

## Próximas entregas

A equipe ainda precisa preparar a programação e a ativação do robô, além da apresentação dos resultados no painel. Até essas etapas estarem disponíveis, não há um procedimento novo de uso para você executar.
