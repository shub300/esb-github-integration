<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEsOrganizationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('es_organizations', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('name')->nullable();
            $table->string('access_url')->nullable()->comment('The Urls under which admins will be logging in');
            $table->string('dns_id')->nullable();
            $table->string('about_org', 1000)->nullable();
            $table->string('help_doc_url', 500)->nullable();
            $table->string('contact_us_url', 500)->nullable();
            $table->string('logo_url', 1000)->nullable();
            $table->string('favicon_url', 1000)->nullable();
            $table->tinyInteger('status')->default(1);
            $table->integer('organization_id')->nullable();
            $table->integer('user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at');

            $table->index(['access_url', 'organization_id', 'user_id'], 'organization_details');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('es_organizations');
    }
}
