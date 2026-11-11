<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_set_product', function (Blueprint $table): void {
            $table->foreignId('attribute_set_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->unique(['attribute_set_id', 'product_id']);
        });

        // Backfill: every product that already has a legacy attribute_set_id
        // gets one corresponding row in the new pivot, so it doesn't
        // silently disappear from the new multi-select / sub-navigation
        // immediately after this migration runs. attribute_set_id itself
        // is read-only here and is never modified or dropped.
        DB::table('products')
            ->whereNotNull('attribute_set_id')
            ->orderBy('id')
            ->select('id', 'attribute_set_id')
            ->chunkById(500, function ($products): void {
                DB::table('attribute_set_product')->insert(
                    $products->map(fn ($product): array => [
                        'attribute_set_id' => $product->attribute_set_id,
                        'product_id' => $product->id,
                    ])->all()
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_set_product');
    }
};
