<?php

use App\Contingencias\ItemContingencia;

/** Item sem problema, com o que cada teste quiser mudar. */
function itemFiscal(array $dados = []): ItemContingencia
{
    return ItemContingencia::deArray(array_merge([
        'cst' => '000',
        'cfop' => '5102',
        'aliquota' => '18.000',
        'reducao' => '0.000',
        'aliquota_reduzida' => 'T18',
        'cbenef' => '',
    ], $dados));
}

test('item correto não tem inconsistência', function () {
    expect(itemFiscal()->inconsistencias())->toBe([]);
});

test('CST 20 ou 40 sem cBenef é inconsistência', function () {
    expect(itemFiscal(['cst' => '40'])->inconsistencias())->toBe(['CST 40 sem código de benefício (cBenef).'])
        ->and(itemFiscal(['cst' => '40', 'cbenef' => 'GO000001'])->inconsistencias())->toBe([]);
});

test('CST 20 precisa de redução e alíquota reduzida diferente da cheia', function () {
    $semReducao = 'CST 20 sem redução: base de redução zerada ou alíquota reduzida igual à cheia.';

    expect(itemFiscal(['cst' => '20', 'cbenef' => 'GO000001', 'reducao' => '0.000', 'aliquota_reduzida' => 'T12'])->inconsistencias())->toBe([$semReducao])
        ->and(itemFiscal(['cst' => '20', 'cbenef' => 'GO000001', 'reducao' => '33.330', 'aliquota_reduzida' => 'T18'])->inconsistencias())->toBe([$semReducao])
        ->and(itemFiscal(['cst' => '20', 'cbenef' => 'GO000001', 'reducao' => '33.330', 'aliquota_reduzida' => 'T12'])->inconsistencias())->toBe([]);
});

test('CST 00 com cBenef é inconsistência', function () {
    expect(itemFiscal(['cbenef' => 'GO811010'])->inconsistencias())->toBe(['CST 00 com código de benefício (cBenef), que não deveria ter.'])
        ->and(itemFiscal(['cbenef' => 'NULL'])->inconsistencias())->toBe([]);
});

test('CST 60 e alíquota F só com CFOP 5405', function () {
    expect(itemFiscal(['cst' => '60', 'cfop' => '5102'])->inconsistencias())->toBe(['CST 60 com CFOP 5102: o esperado é 5405.'])
        ->and(itemFiscal(['cst' => '60', 'cfop' => '5405'])->inconsistencias())->toBe([])
        ->and(itemFiscal(['cst' => '18', 'aliquota_reduzida' => 'F', 'cfop' => '5102'])->inconsistencias())->toBe(['Alíquota F (substituição tributária) com CFOP diferente de 5405.'])
        ->and(itemFiscal(['cst' => '18', 'aliquota_reduzida' => 'F', 'cfop' => '5405'])->inconsistencias())->toBe([]);
});

test('as regras também valem para o Versatus (CST com 3 dígitos e alíquota decimal)', function () {
    $versatus = itemFiscal(['cst' => '020', 'cbenef' => 'GO000001', 'aliquota' => '18.00', 'reducao' => '0.00', 'aliquota_reduzida' => '18.00']);

    expect($versatus->inconsistencias())->toBe(['CST 20 sem redução: base de redução zerada ou alíquota reduzida igual à cheia.'])
        ->and(itemFiscal(['cst' => '060', 'cfop' => '5102'])->inconsistencias())->toHaveCount(1);
});
