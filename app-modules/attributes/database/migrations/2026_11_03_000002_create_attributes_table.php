<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attributes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_id')->constrained('catalogs');
            $table->string('code');
            $table->string('label');
            $table->string('type');
            $table->json('options')->nullable();
            $table->boolean('is_filterable')->default(false);
            $table->timestamps();

            $table->unique(['catalog_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attributes');
    }
};
