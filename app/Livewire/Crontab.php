<?php

namespace App\Livewire;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Crontab: as rotinas do agendador (routes/console.php) com frequência, próxima execução e se estão ligadas. Substitui o Cron Control Center do sistema antigo; só leitura. */
#[Title('Crontab')]
class Crontab extends Component
{
    public function render(Schedule $agenda, ConsoleKernel $console): View
    {
        // routes/console.php só carrega no bootstrap do Kernel de console; em requisição web
        // a agenda estaria vazia. O bootstrap é idempotente (commandsLoaded) e não executa eventos.
        $console->bootstrap();

        $rotinas = collect($agenda->events())
            ->map(fn (Event $evento): array => [
                'nome' => $evento->description ?: $evento->getSummaryForDisplay(),
                'frequencia' => $this->frequencia($evento),
                'proxima' => $evento->nextRunDate(),
                'ligada' => $this->ligada($evento),
            ])
            ->all();

        return view('livewire.crontab', ['rotinas' => $rotinas]);
    }

    /** Avalia só as condições (->when/->skip) da rotina, sem disparar o evento. */
    private function ligada(Event $evento): bool
    {
        return $evento->filtersPass(app());
    }

    private function frequencia(Event $evento): string
    {
        if ($evento->isRepeatable()) {
            return "a cada {$evento->repeatSeconds} segundos";
        }

        $cron = $evento->getExpression();

        if ($cron === '* * * * *') {
            return 'a cada minuto';
        }

        if ($cron === '0 * * * *') {
            return 'de hora em hora';
        }

        if (preg_match('#^\*/(\d+) \* \* \* \*$#', $cron, $m) === 1) {
            return "a cada {$m[1]} minutos";
        }

        return "cron {$cron}";
    }
}
