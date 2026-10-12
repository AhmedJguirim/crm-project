<?php

test('the overridden Flowforge card view is the one Laravel resolves', function () {
    expect(view()->getFinder()->find('flowforge::livewire.card'))
        ->toBe(resource_path('views/vendor/flowforge/livewire/card.blade.php'));
});

test('the vendor card view has not changed since the override was copied', function () {
    expect(hash_file('sha256', base_path('vendor/relaticle/flowforge/resources/views/livewire/card.blade.php')))
        ->toBe(
            '9570d0ae942f27152a04eeefb3b65a641936505459e8560ad375d40f17fe1e0b',
            "Flowforge's card view changed. Copy the new vendor/relaticle/flowforge/resources/views/livewire/card.blade.php over resources/views/vendor/flowforge/livewire/card.blade.php, re-apply the BUG-27 edits, then update this hash.",
        );
});
