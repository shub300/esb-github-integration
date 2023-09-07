<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformUrlsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_urls', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->integer('user_id')->default(0);
            $table->integer('platform_id')->nullable()->index('platform_id');
            $table->integer('user_integration_id')->default(0);
            $table->mediumText('url')->nullable();
            $table->string('url_name', 50)->nullable();
            $table->boolean('status')->default(false)->comment('0 = Waiting to be processed
1 = Processed
2 = Processing');
            $table->boolean('option_status')->default(false)->index('option_status');
            $table->text('response')->nullable()->comment('if NULL then OKY and if not then error');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at');

            $table->index(['status', 'user_id', 'platform_id', 'user_integration_id'], 'account_details');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('platform_urls');
    }
}
