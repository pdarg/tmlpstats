<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAccountabilityPersonTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('accountability_person', function (Blueprint $table) {
            $table->integer('person_id')->unsigned()->index();
            $table->integer('accountability_id')->unsigned()->index();
            $table->timestamps();
        });

        Schema::table('accountability_person', function (Blueprint $table) {
            $table->foreign('person_id')->references('id')->on('people')->onDelete('cascade');
            $table->foreign('accountability_id')->references('id')->on('accountabilities')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::drop('accountability_person');
    }

}
