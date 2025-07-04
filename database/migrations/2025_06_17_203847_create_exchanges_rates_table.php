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
        Schema::create('exchanges_rates', function (Blueprint $table) {
            $table->id();
            $table->string('currency', 3); // XOF pour FCFA
            $table->decimal('rate', 15, 2); // Taux BTC/FCFA
            $table->timestamp('fetched_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchanges_rates');
    }
};
