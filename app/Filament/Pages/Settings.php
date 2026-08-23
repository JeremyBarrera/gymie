<?php

namespace App\Filament\Pages;

use App\Contracts\SettingsRepository;
use App\Helpers\Helpers;
use App\Models\User;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Database\QueryException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * @property-read Schema $form
 */
class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    /** @var string|null Page title */
    protected static ?string $title = null;

    /** @var string View file for the settings page */
    protected string $view = 'filament.pages.settings';

    /** @var array<string, mixed>|null Stores the settings data */
    public ?array $data = [];

    /** @var string|null Stores the uploaded settings file */
    public ?string $settings_file = null;

    /**
     * Mount the page and load settings from the storage.
     */
    public function mount(): void
    {
        $settings = Helpers::getSettings();
        $this->data = $settings;

        $this->form->fill($settings);
    }

    public function getTitle(): string
    {
        return __('app.settings.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('app.settings.title');
    }

    /**
     * Defines the form schema with multiple tabs.
     *
     * @return array<int, Component>
     */
    protected function getFormSchema(): array
    {
        return [
            Tabs::make(__('app.settings.title'))
                ->tabs([
                    $this->invoiceTab(),
                    $this->memberTab(),
                    $this->chargesTab(),
                    $this->expensesTab(),
                    $this->subscriptionsTab(),
                    $this->permissionsTab(),
                    $this->notificationsTab(),
                ]),
        ];
    }

    /**
     * Invoice Tab Schema.
     */
    private function invoiceTab(): Tab
    {
        return
            Tab::make(__('app.settings.tabs.invoice'))->icon('heroicon-m-document-text')
                ->schema([
                    Grid::make(3)
                        ->schema([
                            TextInput::make('invoice.prefix')
                                ->placeholder(__('app.settings.placeholders.prefix'))
                                ->label(__('app.settings.fields.prefix')),
                            TextInput::make('invoice.last_number')
                                ->numeric()
                                ->label(__('app.settings.fields.last_number'))
                                ->maxLength(10)
                                ->extraAttributes(['class' => 'verify-money-input']),
                            Select::make('invoice.name_type')
                                ->native(false)
                                ->label(__('app.settings.fields.name_type'))
                                ->options([
                                    'gym_name' => __('app.settings.options.name_type.gym_name'),
                                    'gym_logo' => __('app.settings.options.name_type.gym_logo'),
                                ]),
                        ]),
                    Fieldset::make(__('app.settings.sections.email'))
                        ->columns(['default' => 1, 'md' => 5])
                        ->schema([
                            Group::make()
                                ->schema([
                                    TextInput::make('notifications.email.invoice_subject_template')
                                        ->label(__('app.settings.fields.email_invoice_subject'))
                                        ->placeholder(__('app.settings.placeholders.invoice_email_subject'))
                                        ->helperText(__('app.settings.hints.tokens_invoice')),
                                    TextInput::make('notifications.email.receipt_subject_template')
                                        ->label(__('app.settings.fields.email_receipt_subject'))
                                        ->placeholder(__('app.settings.placeholders.receipt_email_subject'))
                                        ->helperText(__('app.settings.hints.tokens_receipt')),
                                ])->columnSpan(['default' => 1, 'md' => 3]),
                            Group::make()
                                ->schema([
                                    Toggle::make('notifications.email.enabled')
                                        ->label(__('app.settings.fields.email_enabled'))
                                        ->default(false)
                                        ->inlineLabel(),
                                    Toggle::make('notifications.email.auto_send_invoice_issued')
                                        ->label(__('app.settings.fields.auto_send_invoice_issued'))
                                        ->default(false)
                                        ->inlineLabel(),
                                    Toggle::make('notifications.email.auto_send_payment_receipt')
                                        ->label(__('app.settings.fields.auto_send_payment_receipt'))
                                        ->default(false)
                                        ->inlineLabel(),
                                ])
                                ->columns(1)
                                ->columnSpan(['default' => 1, 'md' => 2]),
                        ]),
                ]);
    }

    /**
     * Member Tab Schema.
     */
    private function memberTab(): Tab
    {
        return
            Tab::make(__('app.settings.tabs.member'))->icon('heroicon-m-user-group')
                ->schema([
                    Grid::make(2)
                        ->schema([
                            TextInput::make('member.prefix')
                                ->placeholder(__('app.settings.placeholders.prefix'))
                                ->label(__('app.settings.fields.prefix')),
                            TextInput::make('member.last_number')
                                ->numeric()
                                ->label(__('app.settings.fields.last_number'))
                                ->maxLength(10)
                                ->extraAttributes(['class' => 'verify-money-input']),
                        ]),
                ]);
    }

    /**
     * Charges Tab Schema.
     */
    private function chargesTab(): Tab
    {
        return
            Tab::make(__('app.settings.tabs.charges'))->icon('heroicon-m-currency-rupee')
                ->schema([
                    Grid::make(3)
                        ->schema([
                            TextInput::make('charges.admission_fee')
                                ->numeric()
                                ->label(__('app.settings.fields.admission_fee'))
                                ->extraAttributes(['class' => 'verify-money-input']),
                            TextInput::make('charges.taxes')
                                ->numeric()
                                ->label(__('app.settings.fields.taxes'))
                                ->suffix('%')
                                ->extraAttributes(['class' => 'verify-money-input']),
                            TagsInput::make('charges.discounts')
                                ->label(__('app.settings.fields.discount_percent_available'))
                                ->hint(__('app.settings.hints.press_enter_to_add'))
                                ->placeholder(__('app.settings.hints.type_discount'))
                                ->separator(','),
                        ]),
                ]);
    }

    /**
     * Expenses Tab Schema.
     */
    private function expensesTab(): Tab
    {
        return
            Tab::make(__('app.settings.tabs.expenses'))->icon('heroicon-m-banknotes')
                ->schema([
                    TagsInput::make('expenses.categories')
                        ->label(__('app.settings.fields.categories'))
                        ->hint(__('app.settings.hints.press_enter_to_add'))
                        ->placeholder(__('app.settings.hints.type_category'))
                        ->separator(','),
                ]);
    }

    /**
     * Subscriptions Tab Schema.
     */
    private function subscriptionsTab(): Tab
    {
        return
            Tab::make(__('app.settings.tabs.subscriptions'))->icon('heroicon-m-ticket')
                ->schema([
                    TextInput::make('subscriptions.expiring_days')
                        ->label(__('app.settings.fields.expiring_days'))
                        ->numeric()
                        ->minValue(1)
                        ->default(7)
                        ->extraAttributes(['class' => 'verify-money-input'])
                        ->required(),
                ]);
    }

    /**
     * Permissions Tab Schema.
     */
    private function permissionsTab(): Tab
    {
        return
            Tab::make(__('app.settings.tabs.permissions'))->icon('heroicon-m-lock-closed')
                ->schema([
                    Section::make(__('app.settings.sections.permissions'))
                        ->aside()
                        ->schema([
                            Toggle::make('permissions.enabled')
                                ->label(__('app.settings.fields.permissions_enabled'))
                                ->helperText(__('app.settings.hints.permissions_enabled'))
                                ->default(true),
                            CheckboxList::make('permissions.disabled')
                                ->label(__('app.settings.fields.disabled_permissions'))
                                ->helperText(__('app.settings.hints.disabled_permissions'))
                                ->options(function (): array {
                                    try {
                                        return Permission::query()->pluck('name', 'name')->all();
                                    } catch (QueryException) {
                                        return [];
                                    }
                                })
                                ->searchable()
                                ->bulkToggleable(),
                        ]),
                ]);
    }

    /**
     * Notifications Tab Schema.
     */
    private function notificationsTab(): Tab
    {
        return Tab::make(__('app.settings.tabs.notifications'))->icon('heroicon-m-bell-alert')
            ->visible(fn (): bool => (bool) auth()->user()?->can('manage_follow_up_alerts'))
            ->schema([
                Section::make(__('app.follow_up.title'))
                    ->aside()
                    ->schema([
                        CheckboxList::make('notifications.follow_up.roles')
                            ->label(__('app.follow_up.recipients_label'))
                            ->helperText(__('app.follow_up.help'))
                            ->options(fn (): array => Role::query()->pluck('name', 'name')->all())
                            ->searchable()
                            ->bulkToggleable()
                            ->default(['owner']),
                        Select::make('notifications.follow_up.users')
                            ->label(__('app.follow_up.users_label'))
                            ->helperText(__('app.follow_up.users_help'))
                            ->options(fn (): array => User::query()->pluck('name', 'id')->all())
                            ->multiple()
                            ->searchable()
                            ->preload(),
                        Placeholder::make('follow_up_default')
                            ->content(__('app.follow_up.default_hint'))
                            ->label(__('app.follow_up.default_label')),
                    ]),
                Section::make(__('app.settings.sections.subscription_status_notifications'))
                    ->aside()
                    ->schema([
                        CheckboxList::make('notifications.subscription_status.roles')
                            ->label(__('app.settings.fields.status_notify_roles'))
                            ->helperText(__('app.settings.hints.status_notify_roles'))
                            ->options(fn (): array => Role::query()->pluck('name', 'name')->all())
                            ->searchable()
                            ->bulkToggleable()
                            ->default(['owner']),
                        Select::make('notifications.subscription_status.users')
                            ->label(__('app.settings.fields.status_notify_users'))
                            ->helperText(__('app.settings.hints.status_notify_users'))
                            ->options(fn (): array => User::query()->pluck('name', 'id')->all())
                            ->multiple()
                            ->searchable()
                            ->preload(),
                    ]),
            ]);
    }

    /**
     * Configures a form instance by setting its schema and state path.
     *
     * @param  Schema  $schema  The form instance to configure.
     * @return Schema The configured form instance.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components($this->getFormSchema())
            ->statePath('data');
    }

    /**
     * Persist the current settings.
     */
    public function save(): void
    {
        $settings = $this->data ?? [];

        try {
            app(SettingsRepository::class)->put($settings);
            $this->data = $settings;
        } catch (\Throwable $exception) {
            report($exception);

            Notification::make()
                ->title(__('app.notifications.failed'))
                ->body(__('app.notifications.failed_settings_save'))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('app.notifications.success'))
            ->body(__('app.notifications.success_settings_save'))
            ->success()
            ->send();
    }
}
