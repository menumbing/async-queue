<?php

use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Migrations\Migration;

class CreateFailedMessagesTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('failed_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('pool', 50)->index();
            $table->text('payload');
            $table->text('exception');
            $table->dateTime('failed_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('failed_messages');
    }
}
