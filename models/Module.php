<?php

use Illuminate\Database\Eloquent\Model as Eloquent;

class Module extends Eloquent {

    protected $fillable = [];

    public function quizzes()
    {
        return $this->hasMany('Quiz');
    }

}