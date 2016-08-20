<?php

use Illuminate\Database\Eloquent\Model as Eloquent;

class Quiz extends Eloquent {

    
    public function path($courseID)
    {
        return   "/quizzes/course_{$courseID}/module_{$this->module_id}/quiz_{$this->id}/launch.html?" . date('Y-m-d H:i:s');
    }

	  public function module()
    {
        return $this->belongsTo('Module');
    }

}
