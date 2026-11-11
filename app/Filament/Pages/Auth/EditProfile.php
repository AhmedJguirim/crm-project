<?php

namespace App\Filament\Pages\Auth;

use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Arr;

/**
 * The panel profile page. It only manages the second factor (app authentication and its recovery codes):
 * name, email and password stay on `/settings/profile` and `/settings/password`.
 */
class EditProfile extends BaseEditProfile
{
    public function getTitle(): string|Htmlable
    {
        return 'Two-factor authentication';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [$this->getCancelFormAction()];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            ...Arr::wrap($this->getMultiFactorAuthenticationContentComponent()),
            $this->getFormContentComponent(),
        ]);
    }

    /**
     * The page never edits the account, so a forged `save` call changes nothing.
     */
    public function save(): void {}
}
