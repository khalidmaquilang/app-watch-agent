<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Exception groups are a normal (non-partitioned) table: one row per
     * fingerprint per environment, while each occurrence goes to the partitioned `exception_occurrences`.
     * `environment_id` has no foreign key: telemetry lives in the client's database, where App Watch's
     * central `environments` table does not exist.
     */
    public function up(): void
    {
        Schema::connection($this->getConnection())->create('exception_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('environment_id');
            $table->char('fingerprint', 64);
            $table->string('class');
            $table->text('message');
            $table->string('file');
            $table->integer('line');
            $table->timestampTz('first_seen_at', 6);
            $table->timestampTz('last_seen_at', 6);
            $table->bigInteger('occurrences')->default(0);
            $table->timestamps();

            $table->unique(['environment_id', 'fingerprint']);
            $table->index(['environment_id', 'last_seen_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('exception_groups');
    }
};
