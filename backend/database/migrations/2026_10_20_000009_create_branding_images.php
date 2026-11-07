<?php

use App\Services\SystemWriteLock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        app(SystemWriteLock::class)->run(function () {
            Schema::create('branding_images', function (Blueprint $table) {
                $table->id();
                $table->enum('slot', ['story_light', 'story_dark', 'logo_light',
                    'logo_dark', 'footer_signature_light', 'footer_signature_dark'])->unique();
                $table->uuid('version')->unique();
                $table->string('storage_path')->unique();
                $table->enum('mime_type', ['image/png', 'image/jpeg', 'image/webp']);
                $table->unsignedInteger('bytes');
                $table->unsignedSmallInteger('width');
                $table->unsignedSmallInteger('height');
                $table->unsignedBigInteger('updated_by');
                $table->timestamps();
                $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
            });
            DB::statement('ALTER TABLE branding_images ADD CONSTRAINT branding_image_size '
                .'CHECK (bytes BETWEEN 1 AND 5242880 AND width BETWEEN 1 AND 4096 AND height BETWEEN 1 AND 4096)');
        });
    }

    public function down(): void
    {
        app(SystemWriteLock::class)->run(fn () => Schema::dropIfExists('branding_images'));
    }
};
