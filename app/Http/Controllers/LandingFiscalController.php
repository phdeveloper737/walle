<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Prévia interna da landing page "Fiscal em dia, do caixa ao SPED" (posicionamento de marca da JM).
 * O arquivo é gerado pelo squad de landing page do ExpxAgents e servido como está: HTML único, fora do walle-ui.
 */
class LandingFiscalController extends Controller
{
    public function __invoke(): BinaryFileResponse
    {
        return response()->file(resource_path('views/marketing/landing-fiscal.html'), [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }
}
