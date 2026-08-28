<?php

namespace App\Providers;

use App\Contracts\SequenceRepository;
use App\Contracts\SettingsRepository;
use App\Contracts\TenantContext;
use App\Helpers\Helpers;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Location;
use App\Models\User;
use App\Observers\InvoiceObserver;
use App\Observers\InvoiceTransactionObserver;
use App\Observers\LocationObserver;
use App\Services\Api\Docs\AddIndexQueryParametersTransformer;
use App\Services\JsonSequenceRepository;
use App\Services\JsonSettingsRepository;
use App\Services\LocationTenantContext;
use App\Support\Data;
use App\Support\Dates\DeviceDateFormat;
use App\Support\Permissions\PermissionFeatureFlags;
use Carbon\Carbon;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component as SchemaComponent;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Component as LivewireComponent;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    

    public function register(): void
    {
        $this->app->singleton(SettingsRepository::class, JsonSettingsRepository::class);
        $this->app->singleton(SequenceRepository::class, JsonSequenceRepository::class);
        $this->app->singletonIf(TenantContext::class, LocationTenantContext::class);
    }

    

    public function boot(Request $request): void
    {
        $this->configureTenantAwareAuthProvider();

        
        
        
        config(['filesystems.disks.public.url' => url('storage')]);

        
        
        
        Validator::extend('date', function (string $attribute, mixed $value): bool {
            if (! is_string($value) && ! $value instanceof \DateTimeInterface) {
                return false;
            }

            try {
                $date = Carbon::parse($value);
            } catch (\Throwable) {
                return false;
            }

            return $date->year >= 1900 && $date->year <= 3000;
        }, 'The :attribute must be a valid date between 1900 and 3000.');

        
        
        
        
        $funnelHost = rtrim((string) config('gymie.funnel_host'), '.');

        if ($request->isSecure() || ($funnelHost !== '' && strcasecmp($request->getHost(), $funnelHost) === 0)) {
            URL::forceScheme('https');
        }
        $this->configureApiRateLimiting();
        $this->configureScrambleApiDocs();

        FilamentAsset::register([
            Css::make('gymie-styles', __DIR__.'/../../resources/css/custom.css'),
        ]);

        

        $resetError = function (LivewireComponent $livewire, SchemaComponent $component): void {
            $livewire->resetValidation($component->getStatePath());
        };

        TextInput::configureUsing(fn (TextInput $field) => $field->live(onBlur: true)->afterStateUpdated($resetError));
        Textarea::configureUsing(fn (Textarea $field) => $field->live(onBlur: true)->afterStateUpdated($resetError));
        Select::configureUsing(fn (Select $field) => $field->live()->afterStateUpdated($resetError));
        DatePicker::configureUsing(fn (DatePicker $field) => $field->live()->afterStateUpdated($resetError));
        DateTimePicker::configureUsing(fn (DateTimePicker $field) => $field->live()->afterStateUpdated($resetError));
        Radio::configureUsing(fn (Radio $field) => $field->live()->afterStateUpdated($resetError));
        Toggle::configureUsing(fn (Toggle $field) => $field->live()->afterStateUpdated($resetError));
        TagsInput::configureUsing(fn (TagsInput $field) => $field->live()->afterStateUpdated($resetError));

        

        CreateAction::configureUsing(function (CreateAction $action) {
            $action->icon('heroicon-s-plus');
        });

        

        EditAction::configureUsing(function (EditAction $action) {
            $action->icon('heroicon-s-pencil-square');
        });

        

        DeleteAction::configureUsing(function (DeleteAction $action) {
            $action->icon('heroicon-s-trash');
        });

        

        ViewAction::configureUsing(function (ViewAction $action) {
            $action->icon('heroicon-s-eye');
        });

        

        Table::configureUsing(function (Table $table) {
            $table->defaultSort('id', 'desc');
        });

        

        Select::configureUsing(function (Select $select) {
            $select
                ->searchable()
                ->native(false)
                ->preload();
        });

        

        DatePicker::configureUsing(function (DatePicker $datePicker) {
            $datePicker
                ->native(false)
                ->placeholder(__('app.placeholders.date_example'))
                ->displayFormat(DeviceDateFormat::date())
                ->prefixIcon('heroicon-o-calendar-days')
                ->minDate(now()->subYears(120))
                ->maxDate(now()->addYears(1000));
        });

        

        DateTimePicker::configureUsing(function (DateTimePicker $datePicker) {
            $datePicker
                ->native(false)
                ->placeholder(__('app.placeholders.date_time_example'))
                ->displayFormat(DeviceDateFormat::dateTime())
                ->prefixIcon('heroicon-o-calendar-days');
        });

        

        TextColumn::configureUsing(function (TextColumn $column) {
            $column->toggleable(isToggledHiddenByDefault: false);
        });

        

        TextInput::configureUsing(function (TextInput $component) {
            $component->placeholder(function (TextInput $component): ?string {
                if ($component->isTel()) {
                    return Helpers::getPhonePlaceholder();
                }

                return null;
            });
        });

        $this->configureDeletionPrevention();
        $this->registerModelObservers();
        $this->registerPermissionFeatureFlags();
    }

    

    private function configureTenantAwareAuthProvider(): void
    {
        Auth::provider('eloquent-tenant-aware', function (mixed $app, array $config): EloquentUserProvider {
            return (new EloquentUserProvider($app['hash'], $config['model']))
                ->withQuery(static fn ($query) => $query->withoutGlobalScope('location'));
        });
    }

    

    private function configureScrambleApiDocs(): void
    {
        if (! class_exists(Scramble::class)) {
            return;
        }

        $config = Scramble::configure();

        $config->routes(static function (Route $route): bool {
            return str_starts_with($route->uri, 'api/v1/');
        });

        $config->withOperationTransformers([
            AddIndexQueryParametersTransformer::class,
        ]);

        if (class_exists(SecurityScheme::class)) {
            $config->withDocumentTransformers(static function (mixed $openApi): void {
                if (! is_object($openApi) || ! method_exists($openApi, 'secure')) {
                    return;
                }

                $openApi->secure(SecurityScheme::http('bearer'));
            });
        }
    }

    

    private function configureApiRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            $key = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(60)->by(Data::string($key));
        });

        RateLimiter::for('api-login', function (Request $request): Limit {
            return Limit::perMinute(10)->by((string) $request->ip());
        });

        RateLimiter::for('api-checkin', function (Request $request): Limit {
            return Limit::perMinute(30)->by((string) $request->ip());
        });

        RateLimiter::for('api-signup', function (Request $request): Limit {
            return Limit::perMinute(10)->by((string) $request->ip());
        });

        RateLimiter::for('scan-page', function (Request $request): Limit {
            return Limit::perMinute(60)->by((string) $request->ip());
        });

        RateLimiter::for('scan-submit', function (Request $request): Limit {
            return Limit::perMinute(30)->by((string) $request->ip());
        });
    }

    

    private function registerModelObservers(): void
    {
        Invoice::observe(InvoiceObserver::class);
        InvoiceTransaction::observe(InvoiceTransactionObserver::class);
        Location::observe(LocationObserver::class);
    }

    

    private function registerPermissionFeatureFlags(): void
    {
        Gate::before(function (mixed $user, string $ability, array $arguments): ?bool {
            if (! $user instanceof User) {
                return null;
            }

            if (PermissionFeatureFlags::isProtectedRoleDeletion($ability, $arguments)) {
                return false;
            }

            if ($user->hasRole(PermissionFeatureFlags::OWNER_ROLE)) {
                return true;
            }

            if (! PermissionFeatureFlags::isPermissionAbility($ability)) {
                return null;
            }

            if (! PermissionFeatureFlags::isMasterEnabled()) {
                return false;
            }

            if (PermissionFeatureFlags::isDisabled($ability)) {
                return false;
            }

            return $user->checkPermissionTo($ability) ?: null;
        });

        Role::deleting(function (Role $role): bool {
            return ! PermissionFeatureFlags::isProtectedRoleName((string) $role->getAttribute('name'));
        });
    }

    

    protected function configureDeletionPrevention(): void
    {
        $map = [];

        foreach ((array) config('prevent-deletion', []) as $class => $relations) {
            if (! is_string($class) || ! is_array($relations)) {
                continue;
            }

            $map[$class] = array_values(array_filter(array_map(
                static fn (mixed $relation): string => Data::string($relation),
                $relations,
            )));
        }

        DeleteAction::configureUsing(function (DeleteAction $action) use ($map): DeleteAction {
            return $action
                ->requiresConfirmation(function (Action $action, $record) use ($map) {
                    if (! is_object($record)) {
                        return $action;
                    }

                    $class = get_class($record);
                    $action->modalIcon('heroicon-o-trash');
                    if (isset($map[$class])) {
                        foreach ($map[$class] as $relation) {
                            if ($record->$relation()->exists()) {
                                $count = $record->$relation()->count();
                                $moduleName = class_basename($record);
                                $label = Str::kebab(Data::string($relation));
                                $action
                                    ->modalIcon('heroicon-o-x-mark')
                                    ->modalHeading(__('app.deletion_prevention.cannot_delete_title', ['module' => $moduleName]))
                                    ->modalDescription(__('app.deletion_prevention.cannot_delete_description', ['count' => $count, 'relation' => $label]))
                                    ->modalCancelAction(false)
                                    ->modalSubmitAction(false);
                                break;
                            }
                        }
                    }

                    return $action;
                });
        }, isImportant: true);

        DeleteBulkAction::configureUsing(function (DeleteBulkAction $action) use ($map): DeleteBulkAction {
            return $action
                ->requiresConfirmation(function (DeleteBulkAction $action, Collection $records) use ($map) {
                    foreach ($records as $record) {
                        if (! is_object($record)) {
                            continue;
                        }

                        $class = get_class($record);
                        $action->modalIcon('heroicon-o-trash');
                        if (isset($map[$class])) {
                            foreach ($map[$class] as $relation) {
                                if ($record->$relation()->exists()) {
                                    $count = $record->$relation()->count();
                                    $moduleName = Str::pluralStudly(class_basename($record));
                                    $label = Str::kebab(Data::string($relation));
                                    $action
                                        ->modalIcon('heroicon-o-x-mark')
                                        ->modalHeading(__('app.deletion_prevention.cannot_delete_title', ['module' => $moduleName]))
                                        ->modalDescription(__('app.deletion_prevention.cannot_delete_bulk_description', ['module' => $moduleName, 'count' => $count, 'relation' => $label]))
                                        ->modalCancelAction(false)
                                        ->modalSubmitAction(false);
                                    break 2;
                                }
                            }
                        }
                    }

                    return $action;
                });
        }, isImportant: true);
    }
}
