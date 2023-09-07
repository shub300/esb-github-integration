<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserModulesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('user_modules', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('module_name', 100)->nullable();
            $table->string('module_code', 100)->nullable();
            $table->string('ideal_portal', 45)->nullable();
            $table->string('option_view', 45)->nullable();
            $table->string('option_create', 45)->nullable();
            $table->string('option_edit', 45)->nullable();
            $table->integer('user_id')->nullable();
            $table->boolean('status')->default(true);
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
        Schema::dropIfExists('user_modules');
    }
}
