<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_relations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_id')->constrained('catalogs');
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('related_product_id')->constrained('products');
            $table->string('type');
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'related_product_id', 'type']);
            $table->index('related_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_relations');
    }
};
