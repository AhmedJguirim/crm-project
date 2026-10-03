<?php

/**
 * Tenant models are filtered by the organization the code works for (see TenantContext), and return no rows when
 * none is set. Code that runs outside the panel (jobs, queued notifications, commands, controllers) must therefore say
 * which organization it works for. This test scans those folders for files that reference a tenant model and neither
 * set a context nor opt out of the scope explicitly.
 *
 * When a file is justified without any of them, add it to the allow-list with the reason.
 */

/**
 * A reviewed exception: 'relative/path.php' => 'reason'.
 *
 * @return array<string, string>
 */
function tenantContextAllowList(): array
{
    return [];
}

/** The folders of `app/` whose code does not run inside the panel. */
const TENANT_CONTEXT_FOLDERS = ['Jobs', 'Notifications', 'Console/Commands', 'Http/Controllers'];

/**
 * The model classes that use `BelongsToOrganization`, found by scanning `app/Models`.
 *
 * @return array<int, string>
 */
function tenantModelClasses(string $appPath): array
{
    $classes = [];

    foreach (glob("{$appPath}/Models/*.php") ?: [] as $file) {
        if (str_contains(file_get_contents($file), 'use BelongsToOrganization;')) {
            $classes[] = basename($file, '.php');
        }
    }

    return $classes;
}

/**
 * Whether the source references a tenant model but neither sets a tenant context nor opts out of the scope.
 *
 * @param  array<int, string>  $tenantModels
 */
function isUnguardedTenantCode(string $source, array $tenantModels): bool
{
    $referencesTenantModel = preg_match('/\b(?:'.implode('|', $tenantModels).')\b/', $source) === 1;

    if (! $referencesTenantModel) {
        return false;
    }

    $setsContext = str_contains($source, 'WithTenantContext')
        || preg_match('/TenantContext::class\)\s*->\s*(?:run|set)\s*\(/', $source) === 1;
    $optsOut = preg_match('/->\s*withoutGlobalScopes?\s*\(|::\s*withoutGlobalScopes?\s*\(|\bforOrganization\s*\(/', $source) === 1;

    return ! $setsContext && ! $optsOut;
}

/**
 * The files of the scanned folders that are unguarded, relative to `app/`.
 *
 * @return array<int, string>
 */
function unguardedTenantFiles(string $appPath): array
{
    $tenantModels = tenantModelClasses($appPath);
    $unguarded = [];

    foreach (TENANT_CONTEXT_FOLDERS as $folder) {
        if (! is_dir("{$appPath}/{$folder}")) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$appPath}/{$folder}", FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && isUnguardedTenantCode(file_get_contents($file->getPathname()), $tenantModels)) {
                $unguarded[] = ltrim(str_replace($appPath, '', $file->getPathname()), DIRECTORY_SEPARATOR);
            }
        }
    }

    sort($unguarded);

    return $unguarded;
}

it('has no job, notification, command or controller that uses tenant models without a tenant context', function () {
    $allowList = tenantContextAllowList();
    $unexpected = array_values(array_filter(
        unguardedTenantFiles(dirname(__DIR__, 2).'/app'),
        fn (string $file): bool => ! isset($allowList[$file]),
    ));

    expect($unexpected)->toBe([], "These files use tenant models, which return no rows outside a tenant context. Run the code in its organization's context (the WithTenantContext job middleware, or app(TenantContext::class)->run(\$organizationId, fn () => …)), or opt out explicitly with withoutGlobalScope('organization') and an organization_id filter (or forOrganization()). If none applies, add the file to the allow-list of this test with the reason:\n".implode("\n", $unexpected));
});

it('has no allow-list entry that matches nothing any more', function () {
    $unguarded = unguardedTenantFiles(dirname(__DIR__, 2).'/app');
    $stale = array_values(array_filter(array_keys(tenantContextAllowList()), fn (string $file): bool => ! in_array($file, $unguarded, true)));

    expect($stale)->toBe([], "These allow-list entries no longer match anything, remove them:\n".implode("\n", $stale));
});

it('finds the tenant models by scanning the models', function () {
    expect(tenantModelClasses(dirname(__DIR__, 2).'/app'))->toContain('Contact', 'Segment', 'Tag', 'Task', 'Invoice')
        ->not->toContain('Organization', 'User');
});

it('reports a job that queries a tenant model without a context', function () {
    $source = "<?php\n\nclass NewJob\n{\n    public function handle(): void\n    {\n        Segment::where('is_published', true)->get();\n    }\n}\n";

    expect(isUnguardedTenantCode($source, ['Segment', 'Contact']))->toBeTrue();
});

it('accepts code that sets a context or opts out explicitly', function (string $code) {
    expect(isUnguardedTenantCode("<?php\n\n{$code}\n", ['Segment', 'Contact']))->toBeFalse();
})->with([
    'job middleware' => ['new WithTenantContext($this->organizationId); Segment::query()->get();'],
    'run' => ['app(TenantContext::class)->run(1, fn () => Segment::count());'],
    'set' => ['app(TenantContext::class)->set(1); Contact::count();'],
    'opt-out' => ["Segment::query()->withoutGlobalScope('organization')->get();"],
    'static opt-out' => ['Segment::withoutGlobalScopes()->get();'],
    'forOrganization' => ['Contact::forOrganization(1)->count();'],
    'no tenant model' => ['Organization::query()->get();'],
]);
