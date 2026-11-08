<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ParsesDays;
use App\Models\Resource;
use App\Models\Space;
use App\Services\Occupancy\ResourceOccupancy;
use Illuminate\Console\Command;

class OccupancyReport extends Command
{
    use ParsesDays;

    protected $signature = 'occupancy:report
        {from : Primer día del periodo, AAAA-MM-DD}
        {to : Último día del periodo, incluido, AAAA-MM-DD}
        {--space= : Id del espacio por el que filtrar}';

    protected $description = 'Muestra las horas reservadas, las disponibles y la ocupación de cada recurso';

    public function handle(): int
    {
        $fromText = $this->asText($this->argument('from'));
        $toText = $this->asText($this->argument('to'));

        $from = $this->parseDay($fromText);
        $to = $this->parseDay($toText);

        if ($from === null) {
            return $this->invalidDay($fromText);
        }

        if ($to === null) {
            return $this->invalidDay($toText);
        }

        if ($to->lessThan($from)) {
            $this->error(__('La fecha final no puede ser anterior a la inicial.'));

            return self::FAILURE;
        }

        // El comando incluye el día final; el scope trabaja con el periodo semiabierto [from, to).
        $query = Resource::query()->occupancyByPeriod($from, $to->copy()->addDay());

        if ($this->option('space') !== null) {
            $space = $this->asText($this->option('space'));
            $spaceId = $this->existingSpaceId($space);

            if ($spaceId === null) {
                $this->error(__('El espacio ":space" no existe.', ['space' => $space]));

                return self::FAILURE;
            }

            $query->forSpace($spaceId);
        }

        $resources = $query->orderBy('space_id')->orderBy('id')->get();

        if ($resources->isEmpty()) {
            $this->info(__('No hay recursos que mostrar.'));

            return self::SUCCESS;
        }

        $this->table(
            [__('Espacio'), __('Recurso'), __('Horas reservadas'), __('Horas disponibles'), __('Ocupación')],
            $resources->map(fn (Resource $resource) => $this->row($resource))->all(),
        );

        return self::SUCCESS;
    }

    /**
     * Se comprueba que sea un entero porque MySQL convertiría "1abc" en 1 al comparar.
     */
    private function existingSpaceId(string $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);

        return $id !== false && Space::query()->whereKey($id)->exists() ? $id : null;
    }

    /**
     * @return list<string>
     */
    private function row(Resource $resource): array
    {
        $occupancy = $resource->occupancy ?? new ResourceOccupancy(0.0, 0.0);

        return [
            $resource->space->name,
            $resource->name,
            number_format($occupancy->bookedHours, 2),
            number_format($occupancy->availableHours, 2),
            number_format($occupancy->percentage(), 2).' %',
        ];
    }
}
