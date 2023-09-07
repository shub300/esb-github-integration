<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformProductDetailAttributesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_product_detail_attributes', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('platform_product_id')->nullable()->default(0)->index('linked_with_product_table')->comment('platform product primary id');
            $table->text('shortdescription')->nullable();
            $table->text('fulldescription')->nullable();
            $table->float('lenght', 10, 0)->nullable()->comment('api lenght');
            $table->string('height', 10)->nullable()->comment('api hieght');
            $table->float('width', 10, 0)->nullable()->comment('api width');
            $table->float('volume', 10, 0)->nullable()->comment('api volume');
            $table->boolean('taxable')->default(false)->comment('1=Yes 0=No');
            $table->string('taxcode_ids')->nullable()->comment('api tax codes for product in comma separated');
            $table->string('product_type_ids')->nullable()->comment('api product_type id for product in comma separated');
            $table->integer('primary_supplier_id')->nullable();
            $table->string('language_code', 10)->nullable()->comment('api language code ISO code');
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
        Schema::dropIfExists('platform_product_detail_attributes');
    }
}
