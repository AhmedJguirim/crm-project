<?php

namespace App\Services\Owners;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Organization;
use Illuminate\Support\Str;

/**
 * Reads the `owner` column of a contacts or companies import: the email of a current member of the organization.
 *
 * - The cell is trimmed, loses the `'` an export puts before a formula-looking cell (`'+sales@example.com`), and is
 *   matched in lowercase against the current members of the organization (any role); a user of another organization
 *   never matches. A cell over 255 characters, or an email nobody has, fails the row.
 * - A created record without the column gets the person who ran the import while they are still a member; a blank cell
 *   or `-` gives no owner.
 * - An existing record keeps its owner on a missing column or a blank cell, loses it on `-`, and takes the member the
 *   cell names. Its current owner is accepted even when no longer a member, as the form does.
 *
 * The members are read once, at the first use: a membership change during an import is not seen.
 */
final class ImportOwnerResolver
{
    public const COLUMN = 'owner';

    private const MAX_LENGTH = 255;

    /** @var array<string, int>|null */
    private ?array $memberIdsByEmail = null;

    public function __construct(
        private readonly int $organizationId,
        private readonly ?int $importerId = null,
    ) {}

    /**
     * @param  array<string, string>  $row  Keyed by canonical column.
     * @return array{owner_id: ?int, error: ?string}
     */
    public function forNewRecord(array $row): array
    {
        if (! array_key_exists(self::COLUMN, $row)) {
            $importerIsMember = $this->importerId !== null && in_array($this->importerId, $this->memberIdsByEmail(), true);

            return ['owner_id' => $importerIsMember ? $this->importerId : null, 'error' => null];
        }

        $cell = trim((string) $row[self::COLUMN]);

        if ($cell === '' || $cell === '-') {
            return ['owner_id' => null, 'error' => null];
        }

        $resolved = $this->resolve($cell);

        if ($resolved['error'] !== null) {
            return ['owner_id' => null, 'error' => $resolved['error']];
        }

        $ownerId = $this->memberIdsByEmail()[$resolved['email']] ?? null;

        if ($ownerId === null) {
            return ['owner_id' => null, 'error' => $this->unknownEmailError($resolved['email'])];
        }

        return ['owner_id' => $ownerId, 'error' => null];
    }

    /**
     * @param  array<string, string>  $row  Keyed by canonical column.
     * @return array{attributes: array{owner_id?: ?int}, error: ?string}
     */
    public function forExistingRecord(Contact|Company $record, array $row): array
    {
        $cell = trim((string) ($row[self::COLUMN] ?? ''));

        if ($cell === '') {
            return ['attributes' => [], 'error' => null];
        }

        if ($cell === '-') {
            return ['attributes' => ['owner_id' => null], 'error' => null];
        }

        $resolved = $this->resolve($cell);

        if ($resolved['error'] !== null) {
            return ['attributes' => [], 'error' => $resolved['error']];
        }

        $ownerId = $this->memberIdsByEmail()[$resolved['email']] ?? null;

        if ($ownerId !== null) {
            return ['attributes' => ['owner_id' => $ownerId], 'error' => null];
        }

        if ($record->owner_id !== null && mb_strtolower((string) $record->owner?->email) === $resolved['email']) {
            return ['attributes' => [], 'error' => null];
        }

        return ['attributes' => [], 'error' => $this->unknownEmailError($resolved['email'])];
    }

    /**
     * @return array{email: string, error: ?string}
     */
    private function resolve(string $cell): array
    {
        if (mb_strlen($cell) > self::MAX_LENGTH) {
            return ['email' => '', 'error' => "Invalid value for field '".self::COLUMN."': ".Str::limit($cell, 50)];
        }

        if (preg_match('/^\'[=@+\-\t\r]/', $cell) === 1) {
            $cell = substr($cell, 1);
        }

        return ['email' => mb_strtolower(trim($cell)), 'error' => null];
    }

    private function unknownEmailError(string $email): string
    {
        return "No member of this organization has the email '{$email}'.";
    }

    /**
     * @return array<string, int>
     */
    private function memberIdsByEmail(): array
    {
        if ($this->memberIdsByEmail === null) {
            $this->memberIdsByEmail = Organization::query()->find($this->organizationId)?->members()
                ->pluck('users.id', 'users.email')
                ->mapWithKeys(fn (int $id, string $email): array => [mb_strtolower($email) => $id])
                ->all() ?? [];
        }

        return $this->memberIdsByEmail;
    }
}
