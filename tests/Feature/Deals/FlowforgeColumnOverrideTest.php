<?php

test('the overridden Flowforge column view is the one Laravel resolves', function () {
    expect(view()->getFinder()->find('flowforge::livewire.column'))
        ->toBe(resource_path('views/vendor/flowforge/livewire/column.blade.php'));
});

test('the vendor column view has not changed since the override was copied', function () {
    expect(hash_file('sha256', base_path('vendor/relaticle/flowforge/resources/views/livewire/column.blade.php')))
        ->toBe(
            'd5218b0badf1e3e1f46d6f26774612b0512e2b24538b368f37f2bb3b2ba7ba06',
            "Flowforge's column view changed. Copy the new vendor/relaticle/flowforge/resources/views/livewire/column.blade.php over resources/views/vendor/flowforge/livewire/column.blade.php, re-apply the FEAT-63 summary block, then update this hash.",
        );
});
