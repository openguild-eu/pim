<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_sets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_id')->constrained('catalogs');
            $table->string('name');
            $table->string('slug');
            $table->timestamps();

            $table->unique(['catalog_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_sets');
    }
};
