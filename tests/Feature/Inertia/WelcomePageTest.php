<?php

use Inertia\Testing\AssertableInertia;

test('the inertia welcome page renders the Welcome component', function () {
    $this->get(route('app.welcome'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Welcome')
            ->where('appName', config('app.name'))
        );
});
