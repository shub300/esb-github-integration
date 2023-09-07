<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEsTimezoneTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('es_timezone', function (Blueprint $table) {
            $table->string('country', 34)->nullable();
            $table->string('ISO_country_code', 3)->default('')->primary();
            $table->tinyInteger('status')->default(1);
            $table->decimal('timezone', 5)->nullable();
            $table->string(' GMT_offset_min', 3)->nullable();
            $table->string('GMT_offset_max', 10)->nullable();
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
        Schema::dropIfExists('es_timezone');
    }
}
