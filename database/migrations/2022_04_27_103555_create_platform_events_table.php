<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformEventsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_events', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('platform_id')->nullable()->index('platform_id');
            $table->string('event_description', 100)->nullable();
            $table->string('event_id', 80)->nullable();
            $table->string('event_name', 80)->nullable();
            $table->boolean('status')->default(true);
            $table->integer('run_in_min')->nullable()->default(5)->comment('cron time in minute');
            $table->enum('linked_table', ['platform_product', 'platform_order', 'platform_customer', 'platform_invoice', 'platform_order_transactions', 'platform_order_shipments', 'platform_order_refunds'])->nullable();
            $table->string('linked_status_column', 100)->nullable()->comment('Linked tables status column name to show in log Ex. product,order or customer tables status column inventory sync or order sync ');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('platform_events');
    }
}
