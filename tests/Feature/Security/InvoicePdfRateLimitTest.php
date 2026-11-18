<?php

use App\Enums\InvoiceStatus;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'UTC'));

    $this->ana = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'ana@example.com']);
    $this->anaOrg = $this->ana->personalOrganization();

    $this->invoiceOf = function ($organization): Invoice {
        $contact = Contact::factory()->create(['organization_id' => $organization->id]);

        return Invoice::factory()->create([
            'organization_id' => $organization->id,
            'contact_id' => $contact->id,
            'status' => InvoiceStatus::Sent,
        ]);
    };

    $this->anaInvoice = ($this->invoiceOf)($this->anaOrg);

    $this->waitMessage = fn (int $minutes): string => 'Too many PDF downloads in a short time. Please try again in '.$minutes.' '.($minutes === 1 ? 'minute' : 'minutes').'.';
});

it('allows twenty downloads a minute and refuses the 21st with the wait', function () {
    $this->actingAs($this->ana);

    foreach (range(1, 20) as $ignored) {
        $this->get(route('invoices.pdf', $this->anaInvoice))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    $this->get(route('invoices.pdf', $this->anaInvoice))
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertSee('<title>Please wait a moment</title>', false)
        ->assertSee('<h1>Please wait a moment</h1>', false)
        ->assertSee(($this->waitMessage)(1))
        ->assertDontSee('%PDF', false);
});

it('gives the minute budget back after a minute', function () {
    $this->actingAs($this->ana);

    foreach (range(1, 20) as $ignored) {
        $this->get('/invoices/999999999/pdf')->assertNotFound();
    }

    $this->get(route('invoices.pdf', $this->anaInvoice))->assertStatus(429);

    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:01:01', 'UTC'));

    $this->get(route('invoices.pdf', $this->anaInvoice))->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('holds the hourly budget across minutes', function () {
    $this->actingAs($this->ana);

    foreach (range(0, 9) as $round) {
        $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'UTC')->addMinutes($round * 2));

        foreach (range(1, 20) as $ignored) {
            $this->get('/invoices/999999999/pdf')->assertNotFound();
        }
    }

    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:20:00', 'UTC'));

    $this->get(route('invoices.pdf', $this->anaInvoice))
        ->assertStatus(429)
        ->assertHeader('Retry-After', '2400')
        ->assertSee(($this->waitMessage)(40));
});

it('rounds the wait up to whole minutes', function () {
    $this->actingAs($this->ana);

    foreach (range(0, 9) as $round) {
        $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'UTC')->addMinutes($round * 2));

        foreach (range(1, 20) as $ignored) {
            $this->get('/invoices/999999999/pdf')->assertNotFound();
        }
    }

    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:19:30', 'UTC'));

    $this->get(route('invoices.pdf', $this->anaInvoice))
        ->assertStatus(429)
        ->assertHeader('Retry-After', '2430')
        ->assertSee(($this->waitMessage)(41));
});

it('gives each user their own budget', function () {
    $ben = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'ben@example.com']);
    $benInvoice = ($this->invoiceOf)($ben->personalOrganization());

    $this->actingAs($this->ana);

    foreach (range(1, 20) as $ignored) {
        $this->get('/invoices/999999999/pdf')->assertNotFound();
    }

    $this->get(route('invoices.pdf', $this->anaInvoice))->assertStatus(429);

    $this->actingAs($ben);

    $this->get(route('invoices.pdf', $benInvoice))->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('throttles a user walking invoice ids of an organization they are not in', function () {
    $other = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'globex@example.com']);
    $foreignInvoice = ($this->invoiceOf)($other->personalOrganization());

    $this->actingAs($this->ana);

    foreach (range(1, 20) as $ignored) {
        $this->get(route('invoices.pdf', $foreignInvoice))->assertForbidden();
    }

    $this->get(route('invoices.pdf', $foreignInvoice))->assertStatus(429);
});

it('does not count guests and sends them to the login', function () {
    foreach (range(1, 25) as $ignored) {
        $this->get(route('invoices.pdf', $this->anaInvoice))->assertRedirect();
    }

    $this->actingAs($this->ana);

    $this->get(route('invoices.pdf', $this->anaInvoice))->assertOk();
});

it('does not count paths the route refuses', function () {
    $this->actingAs($this->ana);

    foreach (['abc', '-1', '99999999999999999999', ' 5'] as $junk) {
        foreach (range(1, 6) as $ignored) {
            $this->get('/invoices/'.rawurlencode($junk).'/pdf')->assertNotFound();
        }
    }

    $this->get(route('invoices.pdf', $this->anaInvoice))->assertOk();
});

it('wires the limiter on the route after auth', function () {
    $middleware = Route::getRoutes()->getByName('invoices.pdf')->gatherMiddleware();

    expect(array_values(array_filter($middleware, fn ($name): bool => in_array($name, ['auth', 'throttle:invoice-pdf'], true))))
        ->toBe(['auth', 'throttle:invoice-pdf']);
});
