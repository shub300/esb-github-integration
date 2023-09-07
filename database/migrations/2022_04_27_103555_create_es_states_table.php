<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEsStatesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('es_states', function (Blueprint $table) {
            $table->mediumIncrements('id');
            $table->string('name');
            $table->unsignedMediumInteger('country_id')->index('country_region');
            $table->char('country_code', 2);
            $table->string('fips_code')->nullable();
            $table->string('iso2')->nullable();
            $table->string('known_for', 150)->nullable();
            $table->text('description')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at');
            $table->boolean('flag')->default(true);
            $table->string('wikiDataId')->nullable()->comment('Rapid API GeoDB Cities');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('es_states');
    }
}
