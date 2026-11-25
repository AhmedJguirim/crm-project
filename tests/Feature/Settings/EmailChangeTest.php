<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->ann = User::factory()->onboardingCompleted()->withPersonalOrganization()->create([
        'name' => 'Ann',
        'email' => 'ann@example.com',
        'password' => Hash::make('password'),
    ]);
    $this->actingAs($this->ann);
});

test('the page shows the session messages escaped', function () {
    $this->withSession(['email-change-error' => '<script>alert(1)</script>'])
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});
