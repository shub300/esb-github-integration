<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeysToPlatformInvoiceArchiveTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('platform_invoice_archive', function (Blueprint $table) {
            $table->foreign(['platform_order_id'], 'cascade_platform_invoice_archive')->references(['id'])->on('platform_order_archive')->onDelete('CASCADE');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('platform_invoice_archive', function (Blueprint $table) {
            $table->dropForeign('cascade_platform_invoice_archive');
        });
    }
}
