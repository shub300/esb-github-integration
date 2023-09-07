<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLogsLinkedColumnsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('logs_linked_columns', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('linked_table', 100)->nullable();
            $table->string('table_display_name', 80)->nullable();
            $table->string('status_column', 100)->nullable();
            $table->string('display_name', 80)->nullable();
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
        Schema::dropIfExists('logs_linked_columns');
    }
}
