<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_id')->constrained('catalogs');
            $table->foreignId('attribute_set_id')->nullable()->constrained('attribute_sets')->nullOnDelete();
            $table->string('sku');
            $table->string('name');
            $table->string('slug');
            $table->string('status')->default('draft');
            $table->text('description')->nullable();
            $table->integer('price')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['catalog_id', 'sku']);
            $table->unique(['catalog_id', 'slug']);
            $table->index('attribute_set_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
