<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformCustomerTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_customer', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->integer('user_id')->default(0);
            $table->integer('platform_id')->nullable()->index('platform_id');
            $table->integer('user_integration_id')->default(0);
            $table->string('api_customer_id', 30)->nullable()->comment('column for create/update/delete record for platform');
            $table->string('api_customer_code', 50)->nullable()->comment('some platform having customer code like intacct is having id that need to assign at time of show selected data');
            $table->string('api_customer_group_id', 30)->nullable()->comment('Customer\'s Group or Price List API ID ');
            $table->string('customer_name')->nullable()->comment('api customer full name');
            $table->string('first_name', 100)->nullable()->comment('api customer name');
            $table->string('last_name', 100)->nullable()->comment('api customer last name');
            $table->string('company_name', 100)->nullable()->comment('api company name');
            $table->string('phone', 50)->nullable()->comment('api customer phone or mobile number');
            $table->string('fax', 100)->nullable()->comment('api customer fax number');
            $table->string('email')->nullable()->comment('api customer primary email if multiple available');
            $table->string('address1', 150)->nullable();
            $table->string('address2', 150)->nullable()->comment('city information');
            $table->string('address3', 150)->nullable()->comment('state information');
            $table->text('postal_addresses')->nullable()->comment('api post code or address full detail as text');
            $table->string('country', 150)->nullable()->comment('ISO code of the country eg: US, IN, SA)');
            $table->string('company_id', 20)->nullable()->comment('api company id');
            $table->enum('sync_status', ['Pending', 'Ready', 'Synced', 'Failed', 'Inactive', 'Processing', 'Ignore'])->default('Pending')->index('sync_status')->comment('Separates customer data with other user data.
eg: if the entry is an employee data then type = Employee. Customer by defaut.');
            $table->string('account_status', 50)->nullable()->comment('customer account status');
            $table->enum('type', ['Customer', 'Vendor', 'Employee'])->default('Customer');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at');
            $table->string('api_created_at', 50)->nullable();
            $table->string('api_updated_at', 40)->nullable();
            $table->integer('linked_id')->nullable()->default(0)->index('linked_id');
            $table->boolean('is_deleted')->nullable()->default(false)->comment('when customer deleted');

            $table->index(['country', 'api_updated_at'], 'IX_platform_customer');
            $table->index(['user_id', 'platform_id', 'api_customer_id', 'email', 'user_integration_id'], 'customer_details');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('platform_customer');
    }
}
