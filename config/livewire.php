<?php

// Só o que muda em relação ao padrão do Livewire 4 (o resto vem do pacote)
return [

    // walle: componentes em classe (app/Livewire) com a view em resources/views/livewire, sem emoji no nome
    'make_command' => [
        'type' => 'class',
        'emoji' => false,
        'with' => [
            'js' => false,
            'css' => false,
            'test' => false,
        ],
    ],

];
