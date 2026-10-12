## Segment membership rules

- Segment membership is kept up to date by model events. Any write to contacts, deals, activities, tags, companies, company types, or the contact_tag / company_contact links must fire model events, or explicitly queue a resync (`ResyncContactSegments::dispatchForContacts(...)` for a few contacts, `SyncSegmentMembership` per affected segment for bulk changes).
- Don't use query-builder `update()`/`delete()`/`insert()`, `*Quietly()` or `withoutEvents()` on those models unless the same code queues the resync. `tests/Arch/EventBypassingWritesTest.php` enforces this; add a reasoned allow-list entry when it's justified.
