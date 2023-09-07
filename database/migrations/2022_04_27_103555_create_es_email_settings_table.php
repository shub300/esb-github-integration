<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEsEmailSettingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('es_email_settings', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('is_default')->nullable()->default(1);
            $table->string('from_email', 70)->nullable();
            $table->string('from_name', 70)->nullable();
            $table->integer('is_custom_smtp')->nullable();
            $table->string('smtp_host', 70)->nullable();
            $table->string('smtp_encryption', 5)->nullable();
            $table->integer('smtp_port')->nullable();
            $table->string('smtp_username', 70)->nullable();
            $table->string('smtp_password', 45)->nullable();
            $table->integer('organization_id')->nullable();
            $table->tinyInteger('active')->nullable()->default(1);
            $table->integer('user_id')->nullable();
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
        Schema::dropIfExists('es_email_settings');
    }
}
