<?php

declare(strict_types=1);

use App\Models\Catalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds the `slug` column, backfills every existing row via
     * Catalog::resolveUniqueSlug() (the same collision-resolution algorithm
     * the model's creating() hook uses for new rows), then enforces
     * NOT NULL and uniqueness. All three steps are safe to run inside a
     * single migration on both drivers this application uses: MySQL
     * (production, see `.env`/`config/database.php`) applies the later
     * `MODIFY COLUMN ... NOT NULL` directly against the already-backfilled
     * rows, and SQLite (the test driver, see `phpunit.xml`) rebuilds the
     * table from its *current* data when compiling a column change, so the
     * backfilled values survive that rebuild. Neither grammar requires
     * doctrine/dbal for `->change()` (not installed as a dependency of
     * this application), so there is no driver-specific reason to split
     * this into two files.
     */
    public function up(): void
    {
        Schema::table('catalogs', function (Blueprint $table): void {
            $table->string('slug')->nullable()->after('name');
        });

        Catalog::query()->withoutGlobalScopes()->each(function (Catalog $catalog): void {
            $catalog->forceFill([
                'slug' => Catalog::resolveUniqueSlug($catalog->name, $catalog->subdomain_id, $catalog->id),
            ])->saveQuietly();
        });

        Schema::table('catalogs', function (Blueprint $table): void {
            $table->string('slug')->nullable(false)->change();
            $table->unique(['subdomain_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('catalogs', function (Blueprint $table): void {
            $table->dropUnique(['subdomain_id', 'slug']);
            $table->dropColumn('slug');
        });
    }
};
