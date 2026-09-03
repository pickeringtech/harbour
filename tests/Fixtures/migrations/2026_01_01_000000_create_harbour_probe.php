<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('harbour_migration_probe', function (Blueprint $table): void {
            $table->id();
            $table->string('workspace');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('harbour_migration_probe');
    }
};
