<?php

namespace App\Providers\Filament;

use App\Filament\Pages\CheckInActivity;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Notifications;
use App\Filament\Pages\PlanCheckIn;
use App\Filament\Pages\PrintQrCodes;
use App\Filament\Pages\Reception;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Enquiries\EnquiryResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\FollowUps\FollowUpResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Locations\LocationResource;
use App\Filament\Resources\Members\MemberResource;
use App\Filament\Resources\Plans\PlanResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Shield\RoleResource;
use App\Http\Middleware\SetAppLocale;
use App\Http\Middleware\SetCurrentLocation;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Enums\ThemeMode;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Filament panel provider for the main admin panel.
 */
class AdminPanelProvider extends PanelProvider
{
    /**
     * Configure the panel.
     */
    public function panel(Panel $panel): Panel
    {
        return $this->basePanel($panel)
            ->navigation(fn (NavigationBuilder $builder) => $this->buildNavigation($builder));
    }

    /**
     * Configure the base panel options.
     */
    public function basePanel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('/')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login()
            ->passwordReset()
            ->brandName('Gymie')
            ->brandLogo('/images/logo.svg')
            ->darkModeBrandLogo('/images/logo-dark-mode.svg')
            ->brandLogoHeight('2.5rem')
            ->favicon('/images/favicon.svg')
            ->unsavedChangesAlerts()
            ->colors($this->colors())
            ->defaultThemeMode(ThemeMode::Light)
            ->sidebarWidth('15rem')
            ->sidebarFullyCollapsibleOnDesktop()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->resources([RoleResource::class])
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
                Settings::class,
                PlanCheckIn::class,
                Reception::class,
                CheckInActivity::class,
                Notifications::class,
                PrintQrCodes::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([])
            ->plugins([FilamentShieldPlugin::make()
                ->navigationIcon(fn (): null => null)
                ->activeNavigationIcon(fn (): null => null)])
            ->middleware([
                SetAppLocale::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                SetCurrentLocation::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->databaseNotifications()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_AFTER,
                fn (): HtmlString => new HtmlString(
                    Blade::render('@livewire(\\App\\Filament\\Livewire\\LocaleSwitcher::class, [], key(\'locale-switcher\'))')
                ),
            )
            // Registered after the locale switcher on the same hook so the
            // topbar reads: search · language · sound · notifications · profile.
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_AFTER,
                fn (): HtmlString => new HtmlString(
                    Blade::render('@livewire(\\App\\Filament\\Livewire\\SoundAlertToggle::class, [], key(\'sound-alerts-toggle\'))')
                ),
            )
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                function (): HtmlString {
                    if (Filament::auth()->guest()) {
                        return new HtmlString('');
                    }

                    return new HtmlString(
                        Blade::render(<<<'BLADE'
                            {{-- Server-side mirror of the acting user's sound preference, read by
                                 resources/js/sound-alerts.js on boot (before the deferred bundle). --}}
                            <script>
                                window.GYMIE_SOUND_ALERTS = @js((bool) auth()->user()?->sound_alerts);
                                window.GYMIE_USER_ID = @js(auth()->user()?->id);
                            </script>
                        BLADE)
                    );
                },
            )
            ->renderHook(
                PanelsRenderHook::HEAD_START,
                fn (): HtmlString => new HtmlString(
                    Blade::render('@vite([\'resources/js/app.js\'])')
                ),
            )
            // The global waiting-line icon (fixed-position FAB rendered by
            // LiveSignupPopup) is reachable from every admin page and owns
            // the queue badge logic there.
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                function (): HtmlString {
                    if (Filament::auth()->guest()) {
                        return new HtmlString('');
                    }

                    return new HtmlString(
                        Blade::render('@livewire(\\App\\Filament\\Livewire\\LiveSignupPopup::class, [], key(\'live-signup-popup\'))')
                    );
                },
            );
    }

    /**
     * Build grouped navigation for the admin panel.
     */
    protected function buildNavigation(NavigationBuilder $builder): NavigationBuilder
    {
        $administration = [
            ...Settings::getNavigationItems(),
            ...UserResource::getNavigationItems(),
            ...LocationResource::getNavigationItems(),
            ...RoleResource::getNavigationItems(),
        ];

        $sales = [
            ...EnquiryResource::getNavigationItems(),
            ...FollowUpResource::getNavigationItems(),
        ];

        $billing = [
            ...InvoiceResource::getNavigationItems(),
            ...ExpenseResource::getNavigationItems(),
        ];

        $memberships = [
            NavigationItem::make(__('app.onboarding.step1_title'))
                ->icon('heroicon-o-user-plus')
                ->url(fn () => MemberResource::getUrl('create'))
                ->isActiveWhen(fn () => request()->routeIs('filament.admin.resources.members.create'))
                ->sort(1),
            ...SubscriptionResource::getNavigationItems(),
            NavigationItem::make(MemberResource::getNavigationLabel())
                ->icon(MemberResource::getNavigationIcon())
                ->url(fn () => MemberResource::getUrl('index'))
                ->isActiveWhen(fn () => request()->routeIs('filament.admin.resources.members.index'))
                ->sort(MemberResource::getNavigationSort()),
            ...PlanResource::getNavigationItems(),
            ...ServiceResource::getNavigationItems(),
        ];

        $topLevel = [
            NavigationItem::make(__('app.navigation.dashboard'))
                ->icon('heroicon-o-chart-bar')
                ->url(fn () => Dashboard::getUrl())
                ->isActiveWhen(fn () => request()->routeIs('filament.admin.pages.dashboard'))
                ->sort(-2),
            NavigationItem::make(__('app.navigation.reception'))
                ->icon('heroicon-o-clipboard-document-check')
                ->url(fn () => Reception::getUrl())
                ->isActiveWhen(fn () => request()->routeIs('filament.admin.pages.reception'))
                ->sort(-1),
            NavigationItem::make(__('app.navigation.activity'))
                ->icon('heroicon-o-clock')
                ->url(fn () => CheckInActivity::getUrl())
                ->isActiveWhen(fn () => request()->routeIs('filament.admin.pages.activity'))
                ->sort(0),
            NavigationItem::make(__('app.reception.qr_codes'))
                ->icon('heroicon-o-qr-code')
                ->url(fn () => PrintQrCodes::getUrl())
                ->isActiveWhen(fn () => request()->routeIs('filament.admin.pages.qr-codes'))
                ->sort(1),
            NavigationItem::make(__('app.follow_up.title'))
                ->icon('heroicon-o-bell')
                ->url(fn () => Notifications::getUrl())
                ->isActiveWhen(fn () => request()->routeIs('filament.admin.pages.notifications'))
                ->sort(2),
        ];

        return $builder
            ->groups([
                NavigationGroup::make(__('app.navigation.groups.memberships'))
                    ->items($memberships)
                    ->collapsed(false),

                NavigationGroup::make(__('app.navigation.groups.billing'))
                    ->items($billing)
                    ->collapsed(false),

                NavigationGroup::make(__('app.navigation.groups.administration'))
                    ->items($administration)
                    ->collapsed(false),

                NavigationGroup::make(__('app.navigation.groups.sales'))
                    ->items($sales)
                    ->collapsed(false),
            ])
            ->items($topLevel);
    }

    /**
     * Panel color palette.
     *
     * @return array<string, mixed>
     */
    protected function colors(): array
    {
        return [
            'primary' => [
                50 => '#b3fefc',
                100 => '#37f2ee',
                200 => '#2dcdc9',
                300 => '#24adaa',
                400 => '#1c908d',
                500 => '#157573',
                600 => '#0e5c5a',
                700 => '#084543',
                800 => '#042f2e',
                900 => '#021f1e',
                950 => '#011413',
            ],
            'danger' => Color::Rose,
            'gray' => Color::Gray,
            'info' => Color::Blue,
            'success' => Color::Emerald,
            'warning' => Color::Orange,
        ];
    }
}
