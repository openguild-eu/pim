<?php

namespace App\Providers\Filament;

use AlizHarb\ActivityLog\ActivityLogPlugin;
use App\Filament\Pages\Tenancy\RegisterCatalog;
use App\Http\Middleware\IdentifySubdomain;
use App\Models\Catalog;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('/')
            ->brandName('PIM')
            ->profile()
            ->multiFactorAuthentication([
                AppAuthentication::make(),
            ])
            ->spa()
            ->tenant(Catalog::class, ownershipRelationship: 'catalog', slugAttribute: 'name')
            ->tenantRegistration(RegisterCatalog::class)
            ->login()
            ->authGuard('web')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->plugins([
                ActivityLogPlugin::make()
                    ->navigationGroup('Settings'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverResources(in: base_path('app-modules/categories/src/Filament/Resources'), for: 'Modules\Categories\Filament\Resources')
            ->discoverResources(in: base_path('app-modules/attributes/src/Filament/Resources'), for: 'Modules\Attributes\Filament\Resources')
            ->discoverResources(in: base_path('app-modules/products/src/Filament/Resources'), for: 'Modules\Products\Filament\Resources')
            ->discoverResources(in: base_path('app-modules/channels/src/Filament/Resources'), for: 'Modules\Channels\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->navigationGroups([
                NavigationGroup::make()
                    ->label('Catalog'),
                NavigationGroup::make()
                    ->label('Administration'),
                NavigationGroup::make()
                    ->label('Settings'),
            ])
            ->middleware([
                IdentifySubdomain::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
