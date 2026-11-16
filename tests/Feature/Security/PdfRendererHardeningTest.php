<?php

use Dompdf\Dompdf;
use Dompdf\Options;

function pdfRenderer(): Dompdf
{
    return app('dompdf');
}

function pdfOptions(): Options
{
    return pdfRenderer()->getOptions();
}

it('refuses files outside public', function (): void {
    $result = pdfOptions()->validateLocalUri('file://'.base_path('composer.json'));

    expect($result[0])->toBeFalse()
        ->and($result[1])->toContain('Permission denied');
});

it('refuses the project secret files', function (string $file): void {
    expect(pdfOptions()->validateLocalUri('file://'.base_path($file))[0])->toBeFalse();
})->with(['.env.example', 'artisan', 'config/app.php']);

it('refuses a path that climbs out of public', function (): void {
    expect(pdfOptions()->validateLocalUri('file://'.public_path('../composer.json'))[0])->toBeFalse();
});

it('keeps files under public readable', function (): void {
    expect(pdfOptions()->validateLocalUri('file://'.public_path('index.php'))[0])->toBeTrue();
});

it('restricts the chroot to public', function (): void {
    expect(pdfOptions()->getChroot())->toBe([realpath(base_path('public'))]);
});

it('turns javascript, remote loading and php off', function (): void {
    expect(pdfOptions()->getIsJavascriptEnabled())->toBeFalse()
        ->and(pdfOptions()->getIsRemoteEnabled())->toBeFalse()
        ->and(pdfOptions()->getIsPhpEnabled())->toBeFalse();
});

it('allows only the data and file protocols', function (): void {
    expect(array_keys(pdfOptions()->getAllowedProtocols()))->toBe(['data://', 'file://']);
});

it('still renders a pdf when the html points at a secret file', function (): void {
    $dompdf = pdfRenderer();
    $dompdf->loadHtml('<p>Hello</p><img src="file://'.base_path('.env.example').'">');
    $dompdf->render();

    expect(str_starts_with($dompdf->output(), '%PDF'))->toBeTrue();
});
