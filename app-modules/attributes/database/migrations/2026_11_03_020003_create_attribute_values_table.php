<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Forward reference: `products` does not exist in this codebase yet
     * (the Products module hasn't been built). This file locks in the
     * agreed schema now, but `php artisan migrate` will fail on it until
     * the Products module's own migration creates `products` — run it only
     * once that table exists. See blueprints/attributes.md's "Forward
     * reference" note.
     */
    public function up(): void
    {
        Schema::create('attribute_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_id')->constrained('catalogs');
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('attribute_id')->constrained('attributes')->restrictOnDelete();
            $table->string('value_text')->nullable();
            $table->integer('value_integer')->nullable();
            $table->decimal('value_decimal', 10, 2)->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->date('value_date')->nullable();
            $table->unique(['product_id', 'attribute_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_values');
    }
};
