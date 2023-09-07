<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEsCountryCodesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('es_country_codes', function (Blueprint $table) {
            $table->integer('id', true);
            $table->char('iso', 2);
            $table->string('name_caps', 80);
            $table->string('name', 80);
            $table->char('iso3', 3)->nullable();
            $table->smallInteger('numcode')->nullable();
            $table->integer('phonecode');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('es_country_codes');
    }
}
