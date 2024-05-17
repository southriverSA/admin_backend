<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ChangeUserIdInPaymentProcessesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
	{
        if (Schema::hasColumn('payment_process', 'user_id')) {
			Schema::table('payment_process', function (Blueprint $table) {
				$table->dropForeign('payment_process_user_id_foreign');
				$table->dropColumn('user_id');
			});
		}

        Schema::table('payment_process', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
	{
        Schema::table('payment_process', function (Blueprint $table) {
            //
        });
    }
}
