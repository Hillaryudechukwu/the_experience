<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Neighbourhoods were the one browsable entity with no picture of its own.
 *
 * Every other card in the product leads with a photograph, so a text-only
 * neighbourhood rail read as a rendering failure rather than a deliberate
 * choice. The attribution column is not optional alongside it: an image we
 * cannot credit is one we should not be publishing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('neighbourhoods', function (Blueprint $table) {
            $table->text('image_url')->nullable()->after('lng');
            $table->jsonb('image_attribution')->nullable()->after('image_url');
        });
    }

    public function down(): void
    {
        Schema::table('neighbourhoods', function (Blueprint $table) {
            $table->dropColumn(['image_url', 'image_attribution']);
        });
    }
};
