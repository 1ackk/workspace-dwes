<x-form.select name="space_id" :label="__('Espacio')" :options="$spaces" :value="$resource?->space_id" required />
<x-form.input name="name" :label="__('Nombre')" :value="$resource?->name" required />
<x-form.select name="type" :label="__('Tipo')" :options="$types" :value="$resource?->type->value" required />
<x-form.input name="capacity" type="number" min="0" :label="__('Capacidad')" :value="$resource?->capacity" required />
<x-form.input name="hourly_rate" type="number" min="0" step="0.01" :label="__('Tarifa por hora')" :value="$resource?->hourly_rate" required />
