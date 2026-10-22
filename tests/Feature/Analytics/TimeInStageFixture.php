<?php

namespace Tests\Feature\Analytics;

use App\Models\DealStageChange;

/**
 * The time in stage scenario shared by the service and widget tests: the empty `StageConversionFixture` organizations
 * (Acme, Europe/Paris, with Alice and Bob; Globex with Carol) plus the deals V1 to V10 and one Globex deal, moved at
 * known times (see `build()`), with "now" = 2026-10-21 10:00 UTC. V9 has no history and V10 left Lead at 2026-09-30
 * 22:30 UTC, which is 1 October in Paris.
 */
final class TimeInStageFixture
{
    public static function build(): StageConversionFixture
    {
        $team = StageConversionFixture::build(withDeals: false);

        $team->deal('V1', '2026-10-01 08:00:00', 'Lead', $team->alice, [['2026-10-03 08:00:00', 'Discovery'], ['2026-10-07 08:00:00', 'Proposal Sent'], ['2026-10-13 08:00:00', 'Won']]);
        $team->deal('V2', '2026-10-02 08:00:00', 'Lead', $team->bob, [['2026-10-06 20:00:00', 'Discovery'], ['2026-10-08 20:00:00', 'Lost']]);
        $team->deal('V3', '2026-09-20 08:00:00', 'Lead', $team->alice, [['2026-09-29 08:00:00', 'Discovery']]);
        $team->deal('V4', '2026-10-05 08:00:00', 'Lead', $team->bob);
        $team->deal('V5', '2026-10-18 12:24:00', 'Lead', $team->alice);
        $team->deal('V6', '2026-10-10 08:00:00', 'Proposal Sent', $team->alice);
        $team->deal('V7', '2026-10-12 08:00:00', 'Negotiating', null);
        $team->deal('V8', '2026-10-01 08:00:00', 'Lead', $team->alice, [['2026-10-02 08:00:00', 'Lost'], ['2026-10-04 08:00:00', 'Lead'], ['2026-10-09 08:00:00', 'Discovery']]);
        $team->deal('V9', '2026-10-14 08:00:00', 'Lead', $team->alice);
        $team->deal('V10', '2026-09-28 22:30:00', 'Lead', $team->bob, [['2026-09-30 22:30:00', 'Lost']]);
        $team->deal('Globex deal', '2026-10-01 08:00:00', 'Lead', $team->carol, [['2026-10-11 08:00:00', 'Discovery']], $team->globex);

        DealStageChange::query()->where('deal_id', $team->deals['V9']->id)->delete();

        test()->travelTo(StageConversionFixture::now());

        return $team;
    }
}
