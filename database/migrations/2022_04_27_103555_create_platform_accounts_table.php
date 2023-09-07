<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformAccountsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_accounts', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_id')->nullable()->index('account_details');
            $table->integer('platform_id')->nullable()->index('platform_id');
            $table->string('account_name', 100)->nullable();
            $table->string('app_id', 1000)->nullable()->comment('Use for woocommerce,netsuite consumer key, amazon client id,3PL client ID');
            $table->string('app_secret', 1000)->nullable()->comment('Use for woocommerce,netsuite consumer secret, amazon client secret,3PL client secret');
            $table->boolean('status')->default(true);
            $table->mediumText('refresh_token')->nullable()->comment('skuvault tenant_token,netsuite token,3PL TPL');
            $table->mediumText('access_token')->nullable()->comment('skuvault user_token,netsuite token secret');
            $table->enum('env_type', ['sandbox', 'production'])->default('production');
            $table->string('access_key')->nullable()->comment('amazon access key,3PL user login id');
            $table->string('secret_key')->nullable()->comment('amazon secret key,3PL facility id');
            $table->string('role_arn', 500)->nullable()->comment('amazon role arn');
            $table->string('region', 30)->nullable()->comment('amazon region, also use SFTP port number');
            $table->string('marketplace_id', 50)->nullable()->comment('amazon marketplace,3PL default customer id ');
            $table->integer('installation_instance_id')->nullable()->comment('BP & other api intallation id');
            $table->string('api_domain', 150)->nullable()->comment('BP & other api domain');
            $table->string('custom_domain', 200)->nullable()->comment('custom api domain for platform');
            $table->string('token_type', 30)->nullable()->comment('Token type from bp and other api');
            $table->string('connection_type', 10)->default('default');
            $table->string('expires_in', 20)->nullable()->comment('expire time from api');
            $table->string('token_refresh_time', 20)->nullable()->comment('Last refresh timestamp');
            $table->boolean('allow_refresh')->default(true);
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
        Schema::dropIfExists('platform_accounts');
    }
}
