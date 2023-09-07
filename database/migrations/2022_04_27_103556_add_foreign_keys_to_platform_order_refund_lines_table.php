<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToPlatformOrderRefundLinesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('platform_order_refund_lines', function (Blueprint $table) {
            $table->foreign(['platform_order_refund_id'], 'cascade_order_refund_line')->references(['id'])->on('platform_order_refunds')->onDelete('CASCADE');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('platform_order_refund_lines', function (Blueprint $table) {
            $table->dropForeign('cascade_order_refund_line');
        });
    }
}
