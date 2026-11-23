<?php

use App\Filament\Pages\Auth\EditProfile;
use App\Models\User;
use App\Support\Security\LoggedAppAuthentication;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PragmaRX\Google2FAQRCode\Google2FA;
use PragmaRX\Google2FAQRCode\QRCode\QRCodeServiceContract;

beforeEach(function () {
    $this->ann = User::factory()->onboardingCompleted()->create(['email' => 'ann@example.test']);
    $this->actingAs($this->ann);
});

test('the panel provider returns a single SVG data URI', function () {
    $provider = Filament::getPanel('admin')->getMultiFactorAuthenticationProviders()['app'];

    $uri = $provider->generateQrCodeDataUri('JBSWY3DPEHPK3PXP');

    expect($uri)->toStartWith('data:image/svg+xml;base64,');

    $payload = base64_decode(substr($uri, strpos($uri, ',') + 1), true);

    expect($payload)->not->toBeFalse()
        ->and($payload)->toStartWith('<?xml')
        ->and($payload)->toContain('<svg')
        ->and($payload)->not->toStartWith('data:');
});

test('a service that returns a raw SVG string is wrapped once', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"></svg>';

    $service = new class($svg) implements QRCodeServiceContract
    {
        public function __construct(private string $svg) {}

        public function getQRCodeInline($string, $size = 200, $encoding = 'utf-8')
        {
            return $this->svg;
        }
    };

    $provider = new LoggedAppAuthentication(new Google2FA($service));

    expect($provider->generateQrCodeDataUri('JBSWY3DPEHPK3PXP'))
        ->toBe('data:image/svg+xml;base64,'.base64_encode($svg));
});

test('the set-up modal renders the QR image', function () {
    $component = Livewire::test(EditProfile::class)
        ->mountAction(TestAction::make('setUpAppAuthentication')->schemaComponent('app', schema: 'content'))
        ->instance();

    $html = (fn () => $this->getMountedActionSchema()->toHtml())->call($component);

    expect($html)
        ->toContain('src="data:image/svg+xml;base64,PD94')
        ->not->toContain('src="data:image/svg+xml;base64,ZGF0YTpp');
});
