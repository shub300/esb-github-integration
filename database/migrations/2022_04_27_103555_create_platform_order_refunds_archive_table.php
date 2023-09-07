<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformOrderRefundsArchiveTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_order_refunds_archive', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->bigInteger('platform_order_id')->nullable()->default(0)->index('platform_order_id')->comment('platform_order primary id');
            $table->integer('api_id')->nullable()->index('api_id');
            $table->string('date_created', 30)->nullable()->comment('api date created date');
            $table->float('amount', 10, 0)->nullable()->comment('api total amount');
            $table->integer('linked_id')->default(0);
            $table->enum('sync_status', ['Pending', 'Synced', 'Failed', 'Partial', 'Ready'])->nullable()->default('Pending');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('platform_order_refunds_archive');
    }
}
