<?php

use Illuminate\Database\Eloquent\Model as Eloquent;

class Duel extends Eloquent {

    public function quiz()
    {
        return $this->belongsTo('Quiz');
    }
}
