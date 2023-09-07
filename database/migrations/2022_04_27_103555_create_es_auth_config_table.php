<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEsAuthConfigTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('es_auth_config', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('organization_id');
            $table->integer('updated_by')->nullable()->comment('users id ');
            $table->enum('auth_type', ['Basic Auth', 'SAML 2.0', 'Oauth 2.0'])->default('Basic Auth');
            $table->string('login_url', 400)->nullable();
            $table->string('logout_url', 400)->nullable();
            $table->mediumText('x509_certificate')->nullable();
            $table->boolean('x509_file_inuse')->default(false)->comment('when x509 file uploaded it will be 1 else 0');
            $table->string('x509_file_path', 300)->nullable();
            $table->string('algorithm', 6)->nullable();
            $table->string('client_id', 300)->nullable()->comment('oauth client id');
            $table->string('client_secret', 300)->nullable()->comment('oauth client secret');
            $table->string('identity_url', 350)->nullable()->comment('Vendor identity url');
            $table->string('access_token_url', 350)->nullable()->comment('Access token url');
            $table->string('user_info_url', 350)->nullable();
            $table->string('oauth_scope', 300)->nullable()->comment('Oauth scopes in comma seprated');
            $table->enum('user_info_url_method', ['get', 'post'])->nullable();
            $table->string('btn_name', 100)->nullable();
            $table->string('btn_color', 10)->nullable();
            $table->mediumText('access_token')->nullable();
            $table->mediumText('refresh_token')->nullable();
            $table->string('state', 300)->nullable()->comment('oauth additional information');
            $table->boolean('status')->default(true)->comment('1 = active, 0 = Inactive');
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
        Schema::dropIfExists('es_auth_config');
    }
}
