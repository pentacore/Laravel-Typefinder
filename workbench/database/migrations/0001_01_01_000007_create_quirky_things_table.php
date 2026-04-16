<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create table quirky_things (id integer primary key autoincrement, shape geometry, name varchar(255))');
    }

    public function down(): void
    {
        Schema::dropIfExists('quirky_things');
    }
};
