<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlatformLookupTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('platform_lookup', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('platform_id', 50)->unique('platform_id');
            $table->string('platform_name', 50);
            $table->string('platform_image')->nullable();
            $table->string('auth_endpoint', 50)->nullable()->comment('Internal Authentication Endpoint Url');
            $table->boolean('status')->default(true)->index('status');
            $table->enum('auth_type', ['oAuth', 'Basic Auth'])->nullable();
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
        Schema::dropIfExists('platform_lookup');
    }
}
