<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('type', ['income', 'expense']);
            $table->unsignedBigInteger('amount_minor');
            $table->date('occurred_on');
            $table->string('description', 2000)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'occurred_on']);
            $table->index(['user_id', 'category_id', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
