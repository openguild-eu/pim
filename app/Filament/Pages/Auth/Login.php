<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Support\Components\Attributes\Unexposed;

class Login extends \Filament\Auth\Pages\Login
{
    /**
     * @return array<Action|ActionGroup>
     */
    protected function getFormActions(): array
    {
        return [
            ...parent::getFormActions(),
            Action::make('sso')
                ->label('Log in with SSO')
                ->link()
                ->url(route('sso.redirect'))
                ->visible(fn (): bool => app()->has('current_subdomain')
                    && app('current_subdomain')->hasSsoConfigured()),
        ];
    }

    /**
     * Surfaces the error flashed by SsoController::callback() on a failed
     * SSO attempt. #[Unexposed] only gates Livewire's remote "call" dispatch
     * (see Filament\Support\Livewire\MethodAccess, registered via
     * before('call', ...)); it has no effect on mount(), which Livewire
     * invokes directly during the mount lifecycle, not through that hook —
     * so overriding mount() here is safe.
     */
    #[Unexposed]
    public function mount(): void
    {
        parent::mount();

        if ($error = session('sso_error')) {
            Notification::make()
                ->title($error)
                ->danger()
                ->send();
        }
    }
}
