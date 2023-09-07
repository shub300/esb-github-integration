<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEsCitiesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('es_cities', function (Blueprint $table) {
            $table->mediumIncrements('id');
            $table->string('name');
            $table->unsignedMediumInteger('state_id')->index('state_ibfk_1');
            $table->string('state_code');
            $table->unsignedMediumInteger('country_id')->index('country_ibfk_2');
            $table->char('country_code', 2);
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->timestamp('created_at')->default('2014-01-01 06:31:01');
            $table->timestamp('updated_on');
            $table->boolean('flag')->default(true);
            $table->string('wikiDataId')->nullable()->comment('Rapid API GeoDB Cities');
            $table->string('known_for', 150)->nullable();
            $table->text('description')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('es_cities');
    }
}
