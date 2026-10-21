<?php

/*
 * Configurações próprias do walle (robôs, limites, regras do negócio).
 * Os robôs que agem fora do walle ficam DESLIGADOS por padrão: liga um por vez, na virada.
 */
return [

    // Robô das autorizadas: consulta na Sefaz as contingências e marca as que já foram autorizadas
    // (substitui o tools/services/autorizadas.php do sistema antigo)
    'autorizadas' => [
        'ativo' => (bool) env('WALLE_AUTORIZADAS_ATIVO', false),
        // Chaves consultadas por ciclo
        'limite_ciclo' => 250,
        // Pausa entre uma chave e outra (segundos)
        'pausa_entre_chaves' => 1,
        // Uma chave não autorizada só é consultada de novo depois de tantas horas
        'intervalo_horas' => 3,
        // Chaves com referência mais antiga que isso são apagadas
        'dias_retencao' => 73,
        // Grava os resultados a cada N chaves consultadas
        'gravar_a_cada' => 50,
        // Semeadura a partir do history_contingencia: linhas por leitura e leituras por ciclo
        'semear_por_leitura' => 2000,
        'semear_leituras_por_ciclo' => 20,
        // Se a Sefaz acusar consumo indevido (656), o robô fica parado por tantos minutos
        'pausa_consumo_indevido_minutos' => 60,
    ],

];
