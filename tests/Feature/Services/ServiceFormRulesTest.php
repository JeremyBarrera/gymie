<?php

use App\Filament\Resources\Services\Schemas\ServiceForm;
use App\Models\Service;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Component;

uses(RefreshDatabase::class);

it('keeps the service name required', function (): void {
    $field = serviceFormField('name');

    expect($field)->toBeInstanceOf(TextInput::class)
        ->and($field->isRequired())->toBeTrue();
});

it('makes the service description optional', function (): void {
    $field = serviceFormField('description');

    expect($field)->toBeInstanceOf(Textarea::class)
        ->and($field->isRequired())->toBeFalse();
});

function serviceFormField(string $statePath): Field
{
    $livewire = new class extends Component implements HasSchemas
    {
        use InteractsWithSchemas;

        public function render(): string
        {
            return '<div></div>';
        }
    };

    return ServiceForm::configure(Schema::make($livewire)->model(Service::class))
        ->getComponentByStatePath($statePath);
}
