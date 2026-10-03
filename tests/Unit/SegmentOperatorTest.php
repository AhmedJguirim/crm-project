<?php

use App\Enums\SegmentOperator;

it('flags exactly the negative field operators as including blank values', function () {
    $including = collect(SegmentOperator::cases())
        ->filter(fn (SegmentOperator $operator): bool => $operator->includesBlankValues())
        ->map(fn (SegmentOperator $operator): string => $operator->value)
        ->values()
        ->all();

    expect($including)->toEqualCanonicalizing([
        SegmentOperator::IsNot->value,
        SegmentOperator::DoesNotContain->value,
        SegmentOperator::IsNotFromDomain->value,
        SegmentOperator::NotEqualTo->value,
        SegmentOperator::IsNoneOf->value,
        SegmentOperator::ContainsNoneOf->value,
        SegmentOperator::DoesNotContainAllOf->value,
    ]);
});
