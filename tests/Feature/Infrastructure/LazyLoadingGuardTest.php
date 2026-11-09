<?php

use App\Models\Contact;
use App\Models\Tag;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Support\Facades\Process;

function lazyLoadingGuardProbe(string $environment): string
{
    $result = Process::path(base_path())
        ->env([
            'APP_ENV' => $environment,
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ])
        ->run(['php', 'artisan', 'tinker', '--execute', 'echo Illuminate\Database\Eloquent\Model::preventsLazyLoading() ? "guard-on" : "guard-off";']);

    return $result->output().$result->errorOutput();
}

test('the lazy loading guard is on in the testing environment', function () {
    expect(Model::preventsLazyLoading())->toBeTrue();
});

test('the lazy loading guard is off in production, on a real boot in a subprocess', function () {
    $output = lazyLoadingGuardProbe('production');

    expect($output)->toContain('guard-off')
        ->and($output)->not->toContain('guard-on');
});

test('the lazy loading guard is on in local, on a real boot in a subprocess', function () {
    $output = lazyLoadingGuardProbe('local');

    expect($output)->toContain('guard-on')
        ->and($output)->not->toContain('guard-off');
});

test('a lazy load on a model fetched with others throws', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $org = $user->personalOrganization();
    $this->actingAs($user);
    Filament::setTenant($org);

    $tag = Tag::factory()->create(['organization_id' => $org->id]);
    foreach (['Ada Lovelace', 'Alan Turing'] as $name) {
        Contact::factory()->create(['organization_id' => $org->id, 'name' => $name])->tags()->sync([$tag->id]);
    }

    $contacts = Contact::query()->orderBy('name')->get();

    expect($contacts)->toHaveCount(2)
        ->and(fn () => $contacts->first()->tags)->toThrow(LazyLoadingViolationException::class);
});

test('a lazy load on a single fetched model does not throw', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $org = $user->personalOrganization();
    $this->actingAs($user);
    Filament::setTenant($org);

    $tag = Tag::factory()->create(['organization_id' => $org->id]);
    Contact::factory()->create(['organization_id' => $org->id])->tags()->sync([$tag->id]);

    $contact = Contact::query()->firstOrFail();

    expect($contact->tags)->toHaveCount(1);
});
