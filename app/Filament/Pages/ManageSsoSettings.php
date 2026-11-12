<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Subdomain;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ManageSsoSettings extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'SSO';

    protected static ?string $title = 'SSO';

    protected string $view = 'filament.pages.manage-sso-settings';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * The current tenant's Subdomain row. Not a route-bound {record} — this
     * page is single-record settings for "the current tenant", always
     * app('current_subdomain'), never user-selectable.
     */
    public Subdomain $record;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage sso settings') ?? false;
    }

    public function mount(): void
    {
        $this->record = app('current_subdomain');

        $this->form->fill($this->record->only([
            'sso_enabled',
            'sso_issuer',
            'sso_client_id',
        ]));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Toggle::make('sso_enabled')
                        ->label('Enable SSO'),

                    TextInput::make('sso_issuer')
                        ->label('Issuer URL')
                        ->url()
                        ->helperText('e.g. https://your-idp.example.com')
                        ->nullable()
                        ->requiredIf('sso_enabled', true),

                    TextInput::make('sso_client_id')
                        ->label('Client ID')
                        ->maxLength(255)
                        ->nullable()
                        ->requiredIf('sso_enabled', true),

                    TextInput::make('sso_client_secret')
                        ->label('Client secret')
                        ->password()
                        ->revealable()
                        ->nullable()
                        // Deliberately not a plain required_if('sso_enabled',
                        // true): this field always renders blank (see the
                        // dehydrated() note below), even when a secret is
                        // already stored. A literal required_if would force
                        // re-entering the secret on every save while SSO is
                        // enabled, including the "disable then re-enable"
                        // round trip, which must not require resupplying
                        // credentials already on file. So the secret is only
                        // required when SSO is being enabled and no secret
                        // is stored yet.
                        ->required(fn (Get $get): bool => (bool) $get('sso_enabled') && blank($this->record->sso_client_secret))
                        // Leaving this blank on an edit must not overwrite the
                        // stored secret: dehydrated(false) excludes the key
                        // from the form state entirely, so save()'s
                        // $this->record->fill($data) below never touches
                        // sso_client_secret when the field was left blank.
                        ->dehydrated(fn (?string $state): bool => filled($state)),
                ])
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->submit('save')
                                ->keyBindings(['mod+s']),
                        ]),
                    ]),
            ])
            ->record($this->record)
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $this->record->fill($data);
        $this->record->save();

        Notification::make()
            ->success()
            ->title('SSO settings saved')
            ->send();
    }
}
