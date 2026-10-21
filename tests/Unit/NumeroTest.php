<?php

use App\Support\Numero;

test('números no jeito brasileiro', function () {
    expect(Numero::inteiro(1284))->toBe('1.284')
        ->and(Numero::decimal(2.25))->toBe('2,3')
        ->and(Numero::percentual(87.5))->toBe('87,5%')
        ->and(Numero::compacto(950))->toBe('950')
        ->and(Numero::compacto(1200))->toBe('1,2 mil')
        ->and(Numero::compacto(2000))->toBe('2 mil')
        ->and(Numero::compacto(10000))->toBe('10 mil')
        ->and(Numero::compacto(3_400_000))->toBe('3,4 mi');
});

test('escala do eixo com números redondos que cobrem o maior valor', function () {
    expect(Numero::escala(0))->toBe(['topo' => 1.0, 'marcas' => [0.0, 1.0]])
        ->and(Numero::escala(3)['marcas'])->toBe([0.0, 1.0, 2.0, 3.0])
        ->and(Numero::escala(87)['topo'])->toBe(100.0)
        ->and(Numero::escala(1284))->toBe(['topo' => 1500.0, 'marcas' => [0.0, 500.0, 1000.0, 1500.0]]);
});
