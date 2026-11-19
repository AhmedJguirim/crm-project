<?php

namespace App\Services\Contacts;

use App\Enums\MergeSide;
use App\Exceptions\ContactMergeRefused;
use App\Jobs\ResyncContactSegments;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Merges a contact (the loser) into another contact of the same organization (the survivor).
 *
 * The loser's activities, deals, tasks and invoices are repointed to the survivor one model at a time, so the model
 * events keep the segments right; its tags and companies are added to the survivor's. Each field takes the value of the
 * side the caller chose, or else the survivor's, or the loser's when the survivor's is blank. The loser is then
 * soft-deleted with a link to the survivor and a snapshot, and can never be restored (there is no unmerge).
 *
 * Everything runs in one transaction with both rows locked in id order. The loser is written before the survivor on
 * purpose: the unique index on the email counts soft-deleted rows, and a unique custom field counts the loser while it
 * is live, so the survivor can take such a value only after the loser has let go of it.
 */
class ContactMerger
{
    /**
     * Every foreign key that references `contacts`, and how a merge handles it. A test fails when the database has a
     * key that is not listed here.
     *
     * @var array<string, string>
     */
    public const HANDLED_FOREIGN_KEYS = [
        'company_contact.contact_id' => 'union',
        'contact_activities.contact_id' => 'repoint',
        'contact_segment.contact_id' => 'resync',
        'contact_tag.contact_id' => 'union',
        'contacts.merged_into_id' => 'merge link',
        'deals.contact_id' => 'repoint',
        'invoices.contact_id' => 'repoint',
        'tasks.contact_id' => 'repoint',
    ];

    private const FIELDS = ['name', 'email', 'phone', 'status', 'lead_source', 'owner_id'];

    /**
     * @param  array<string, MergeSide>  $choices  Field (or `custom.<key>`) to the side its value comes from.
     *
     * @throws ContactMergeRefused
     */
    public function merge(Contact $survivor, Contact $loser, array $choices, User $mergedBy): Contact
    {
        $this->refuseUnlessAllowed($survivor, $loser, $mergedBy);

        $definitions = $survivor->customFieldDefinitions();
        $this->refuseForgedChoices($choices, $definitions);

        return DB::transaction(function () use ($survivor, $loser, $choices, $mergedBy, $definitions): Contact {
            [$keep, $drop] = $this->lockedPair($survivor, $loser);

            $final = $this->finalValues($keep, $drop, $choices, $definitions);
            $moved = $this->repointRows($keep, $drop);
            $added = $this->unionLinks($keep, $drop);
            $snapshot = $this->snapshot($keep, $drop, $moved, $added);

            $this->retire($keep, $drop, $final, $definitions, $mergedBy, $snapshot);

            $keep->fill($final['attributes']);
            $keep->custom_field_values = $final['custom_field_values'];
            $keep->save();

            ResyncContactSegments::dispatchForContacts($keep->organization_id, [$keep->id, $drop->id]);

            return $keep->fresh();
        });
    }

    /**
     * @throws ContactMergeRefused
     */
    private function refuseUnlessAllowed(Contact $survivor, Contact $loser, User $mergedBy): void
    {
        if ($survivor->is($loser)) {
            throw new ContactMergeRefused('Pick two different contacts to merge.');
        }

        if ($survivor->organization_id !== $loser->organization_id) {
            throw new ContactMergeRefused('Only contacts of the same organization can be merged.');
        }

        $mergedBy->forgetRoles();
        $gate = Gate::forUser($mergedBy);

        if ($gate->denies('merge', $survivor) || $gate->denies('merge', $loser)) {
            throw new ContactMergeRefused('Only admins and the owner can merge contacts.');
        }
    }

    /**
     * @param  array<mixed>  $choices
     * @param  Collection<int, CustomField>  $definitions
     *
     * @throws ContactMergeRefused
     */
    private function refuseForgedChoices(array $choices, Collection $definitions): void
    {
        $allowed = array_merge(self::FIELDS, $definitions->map(fn (CustomField $field): string => 'custom.'.$field->key)->all());

        foreach ($choices as $key => $side) {
            if (! in_array($key, $allowed, true) || ! $side instanceof MergeSide) {
                throw new ContactMergeRefused('The merge form is out of date. Refresh the page and try again.');
            }
        }
    }

    /**
     * Both rows, locked in id order and read again from the database: the objects passed in may be stale.
     *
     * @return array{0: Contact, 1: Contact} The survivor, then the loser.
     *
     * @throws ContactMergeRefused
     */
    private function lockedPair(Contact $survivor, Contact $loser): array
    {
        $rows = Contact::withTrashed()
            ->forOrganization($survivor->organization_id)
            ->whereKey([$survivor->id, $loser->id])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $keep = $rows->get($survivor->id);
        $drop = $rows->get($loser->id);

        if ($keep === null || $drop === null || $keep->trashed() || $drop->trashed() || $keep->isMerged() || $drop->isMerged()) {
            throw new ContactMergeRefused('One of these contacts was deleted or merged in the meantime. Refresh the page and try again.');
        }

        return [$keep, $drop];
    }

    /**
     * @param  array<string, MergeSide>  $choices
     * @param  Collection<int, CustomField>  $definitions
     * @return array{attributes: array<string, mixed>, custom_field_values: array<string, mixed>, taken_from_loser: array<int, string>}
     */
    private function finalValues(Contact $survivor, Contact $loser, array $choices, Collection $definitions): array
    {
        $attributes = [];

        foreach (self::FIELDS as $field) {
            $attributes[$field] = $this->pick($choices[$field] ?? null, $survivor->{$field}, $loser->{$field});
        }

        $live = $definitions->pluck('key')->all();
        $survivorValues = $survivor->custom_field_values ?? [];
        $loserValues = $loser->custom_field_values ?? [];
        $custom = $survivorValues;
        $takenFromLoser = [];

        foreach (array_unique([...array_keys($survivorValues), ...array_keys($loserValues)]) as $key) {
            $side = in_array($key, $live, true) ? ($choices['custom.'.$key] ?? null) : null;
            $value = $this->pick($side, $survivorValues[$key] ?? null, $loserValues[$key] ?? null);

            if ($value === null) {
                unset($custom[$key]);

                continue;
            }

            $custom[$key] = $value;

            if (! blank($value) && $value === ($loserValues[$key] ?? null) && $value !== ($survivorValues[$key] ?? null)) {
                $takenFromLoser[] = (string) $key;
            }
        }

        return [
            'attributes' => $attributes,
            'custom_field_values' => $custom,
            'taken_from_loser' => $takenFromLoser,
        ];
    }

    private function pick(?MergeSide $side, mixed $survivorValue, mixed $loserValue): mixed
    {
        return match ($side) {
            MergeSide::Survivor => $survivorValue,
            MergeSide::Loser => $loserValue,
            null => blank($survivorValue) && ! blank($loserValue) ? $loserValue : $survivorValue,
        };
    }

    /**
     * Moves the loser's activities, deals, tasks (trashed ones too) and invoices to the survivor, one model at a time
     * so the observers run and queue the segment resync.
     *
     * @return array<string, array<int, int>> The ids moved, by kind, in ascending order.
     */
    private function repointRows(Contact $survivor, Contact $loser): array
    {
        $organizationId = $survivor->organization_id;
        $moved = [];

        foreach (['activities', 'deals', 'tasks', 'invoices'] as $kind) {
            $rows = match ($kind) {
                'activities' => Activity::forOrganization($organizationId)->where('contact_id', $loser->id)->get(),
                'deals' => Deal::forOrganization($organizationId)->where('contact_id', $loser->id)->get(),
                'tasks' => Task::withTrashed()->forOrganization($organizationId)->where('contact_id', $loser->id)->get(),
                'invoices' => Invoice::forOrganization($organizationId)->where('contact_id', $loser->id)->get(),
            };

            foreach ($rows as $row) {
                $row->contact_id = $survivor->id;
                $row->save();
            }

            $moved[$kind] = $rows->modelKeys();
            sort($moved[$kind]);
        }

        return $moved;
    }

    /**
     * Adds the loser's tags and companies (trashed ones too) to the survivor. The loser keeps its own links.
     *
     * @return array{tags: array<int, int>, companies: array<int, int>} The ids newly linked to the survivor, ascending.
     */
    private function unionLinks(Contact $survivor, Contact $loser): array
    {
        $tags = $survivor->tags()->syncWithoutDetaching(
            $loser->tags()->withoutGlobalScope('organization')->withTrashed()->pluck('tags.id')->all()
        );
        $companies = $survivor->companies()->syncWithoutDetaching(
            $loser->companies()->withoutGlobalScope('organization')->withTrashed()->pluck('companies.id')->all()
        );

        $attachedTags = array_map('intval', $tags['attached']);
        $attachedCompanies = array_map('intval', $companies['attached']);
        sort($attachedTags);
        sort($attachedCompanies);

        return ['tags' => $attachedTags, 'companies' => $attachedCompanies];
    }

    /**
     * @param  array<string, array<int, int>>  $moved
     * @param  array{tags: array<int, int>, companies: array<int, int>}  $added
     * @return array<string, mixed>
     */
    private function snapshot(Contact $survivor, Contact $loser, array $moved, array $added): array
    {
        return [
            'survivor_id' => $survivor->id,
            'loser' => $this->valuesOf($loser),
            'survivor_before' => $this->valuesOf($survivor),
            'moved' => $moved,
            'added_to_survivor' => $added,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function valuesOf(Contact $contact): array
    {
        return [
            'name' => $contact->name,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'status' => $contact->status?->value,
            'lead_source' => $contact->lead_source?->value,
            'owner_id' => $contact->owner_id,
            'custom_field_values' => $contact->custom_field_values ?? [],
        ];
    }

    /**
     * Lets go of what the survivor takes (its email, its unique custom values), then links the loser to the survivor and
     * soft-deletes it. Must run before the survivor is saved.
     *
     * @param  array{attributes: array<string, mixed>, custom_field_values: array<string, mixed>, taken_from_loser: array<int, string>}  $final
     * @param  Collection<int, CustomField>  $definitions
     * @param  array<string, mixed>  $snapshot
     */
    private function retire(Contact $survivor, Contact $loser, array $final, Collection $definitions, User $mergedBy, array $snapshot): void
    {
        $finalEmail = $final['attributes']['email'];

        if ($finalEmail !== null && $finalEmail === $loser->email && $finalEmail !== $survivor->email) {
            $loser->email = null;
        }

        $uniqueKeys = $definitions->where('unique', true)->pluck('key')->all();
        $loserValues = $loser->custom_field_values ?? [];

        foreach (array_intersect($final['taken_from_loser'], $uniqueKeys) as $key) {
            unset($loserValues[$key]);
        }

        $loser->custom_field_values = $loserValues;
        $loser->merged_into_id = $survivor->id;
        $loser->merged_by_id = $mergedBy->id;
        $loser->merged_at = now();
        $loser->merge_snapshot = $snapshot;
        $loser->save();
        $loser->delete();
    }
}
