<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tontines', function (Blueprint $table) {
           $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->unsignedBigInteger('creator_id');
            $table->decimal('amount_fcfa', 15, 2);
            $table->bigInteger('amount_sats');
            $table->enum('frequency', ['daily', 'weekly', 'monthly']);
            $table->integer('max_members');
            $table->integer('current_members')->default(0);
            $table->integer('current_round')->default(1);
            $table->enum('status', ['active', 'completed'])->default('active');
            $table->timestamp('start_date');
            $table->timestamp('next_distribution')->nullable();
            $table->timestamps();

            $table->foreign('creator_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tontines');
    }
};
