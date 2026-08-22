<?php

use App\Filament\Forms\Components\CameraUploadField;
use App\Models\Member;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

uses(RefreshDatabase::class);

function cameraUploadTestSchema(?Member $record = null): Schema
{
    $livewire = new class extends Component implements HasSchemas
    {
        use InteractsWithSchemas;

        public function render(): string
        {
            return '<div></div>';
        }
    };

    return Schema::make($livewire)
        ->model($record ?? Member::class)
        ->components([
            CameraUploadField::make('photo'),
        ]);
}

function cameraUploadDataUrl(): string
{
    return 'data:image/jpeg;base64,'.base64_encode('fake-jpeg-bytes');
}

it('stores a captured data URL on the public disk when the form saves', function (): void {
    Storage::fake('public');

    $schema = cameraUploadTestSchema();
    $schema->fill(['photo' => cameraUploadDataUrl()]);

    $state = $schema->getState();

    expect($state['photo'])->not->toBeNull()
        ->and(str_starts_with($state['photo'], 'images/member-'))->toBeTrue();

    Storage::disk('public')->assertExists($state['photo']);
});

it('deletes the previous stored file when the photo is replaced', function (): void {
    Storage::fake('public');

    $oldPath = 'images/member-old.jpg';
    Storage::disk('public')->put($oldPath, 'old');

    $member = Member::factory()->create(['photo' => $oldPath]);

    $schema = cameraUploadTestSchema($member);
    $schema->fill(['photo' => $oldPath]);

    $schema->fill(['photo' => cameraUploadDataUrl()]);
    $state = $schema->getState();

    expect($state['photo'])->not->toBe($oldPath);

    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists($state['photo']);
});

it('deletes the stored file when the photo is removed', function (): void {
    Storage::fake('public');

    $oldPath = 'images/member-old.jpg';
    Storage::disk('public')->put($oldPath, 'old');

    $member = Member::factory()->create(['photo' => $oldPath]);

    $schema = cameraUploadTestSchema($member);
    $schema->fill(['photo' => $oldPath]);

    $schema->fill(['photo' => null]);
    $state = $schema->getState();

    expect($state['photo'])->toBeNull();

    Storage::disk('public')->assertMissing($oldPath);
});

it('keeps an existing stored photo untouched when nothing changed', function (): void {
    Storage::fake('public');

    $path = 'images/member-existing.jpg';
    Storage::disk('public')->put($path, 'existing');

    $member = Member::factory()->create(['photo' => $path]);

    $schema = cameraUploadTestSchema($member);
    $schema->fill(['photo' => $path]);

    $state = $schema->getState();

    expect($state['photo'])->toBe($path);

    Storage::disk('public')->assertExists($path);
});
