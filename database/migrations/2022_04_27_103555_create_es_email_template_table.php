<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEsEmailTemplateTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('es_email_template', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('organization_id')->nullable()->index('organization_id');
            $table->string('mail_type', 45)->nullable();
            $table->string('mail_subject', 200)->nullable();
            $table->longText('mail_body')->nullable();
            $table->tinyInteger('active')->nullable()->default(1);
            $table->integer('user_id')->nullable()->index('user_id');
            $table->timestamps();

            $table->index(['mail_type', 'active'], 'IX_mail_type');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('es_email_template');
    }
}
