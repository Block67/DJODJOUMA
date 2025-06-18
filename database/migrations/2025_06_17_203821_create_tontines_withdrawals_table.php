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
        Schema::create('tontines_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tontine_id');
            $table->unsignedBigInteger('user_id');
            $table->decimal('amount_fcfa', 15, 2);
            $table->bigInteger('amount_sats');
            $table->text('bolt11_invoice');
            $table->string('payment_hash')->nullable();
            $table->integer('round');
            $table->timestamps();

            $table->foreign('tontine_id')->references('id')->on('tontines')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tontines_withdrawals');
    }
};
