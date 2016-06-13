<?php

use Illuminate\Database\Migrations\Migration;

class CreateRevisionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('revisions', function ($table) {
            $table->increments('id');
            $table->string('revisionable_type');
            $table->integer('revisionable_id');
            $table->string('transaction_id');
            $table->string('ip_address');
            $table->integer('user_id')->nullable();
            $table->string('attribute');
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamps();

            $table->index(array('revisionable_id', 'revisionable_type'));
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::drop('revisions');
    }
}
