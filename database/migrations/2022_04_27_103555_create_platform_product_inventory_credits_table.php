<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformProductInventoryCreditsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_product_inventory_credits', function (Blueprint $table) {
            $table->integer('id', true);
            $table->bigInteger('platform_inventory_id')->nullable()->index('platform_product_id');
            $table->integer('user_workflow_rule_id')->default(0);
            $table->bigInteger('platform_refund_order_id')->nullable()->index('platform_refund_order_id');
            $table->integer('quantity')->default(0);
            $table->enum('sync_status', ['Pending', 'Ready', 'Synced', 'Failed'])->default('Pending');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('platform_product_inventory_credits');
    }
}
