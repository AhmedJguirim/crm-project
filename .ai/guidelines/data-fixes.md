## Data Fix Rules

- Never write a migration to fix or rewrite existing data (re-keying, backfills, JSON rewrites). Migrations are for schema changes only.
- For one-off data fixes, create a temporary Artisan command (`php artisan make:command`), run it, then delete it. If the data is only seed/test data, tell the user they can run `php artisan migrate:fresh --seed` instead.
- Always get the user's explicit permission before altering existing data (running a fix command, `migrate:fresh`, reseeding, or any write to the database), and say exactly what will change first.
