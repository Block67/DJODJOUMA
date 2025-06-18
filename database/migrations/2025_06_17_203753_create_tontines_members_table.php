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
        Schema::create('tontines_members', function (Blueprint $table) {
           $table->id();
            $table->unsignedBigInteger('tontine_id');
            $table->unsignedBigInteger('user_id');
            $table->integer('position');
            $table->boolean('has_received')->default(false);
            $table->timestamp('joined_at');
            $table->timestamps();

            $table->foreign('tontine_id')->references('id')->on('tontines')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['tontine_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tontines_members');
    }
};
