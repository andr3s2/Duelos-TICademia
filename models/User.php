<?php

use Illuminate\Database\Eloquent\Model as Eloquent;

class User extends Eloquent {

    public function fullName()
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function avatarPath()
    {
        return '/users/avatars/' . $this->avatar;
    }

    public function linkFullName()
    {
        return "<a class='link info-user' data-user-id='{$this->id}'>{$this->first_name} {$this->last_name}</a>";
    }
	
	   public function courses()
    {
        return $this->belongsToMany('Course')->withTimestamps()->withPivot(['group_id','level_id', 'role','score','percentage', 'contact_information']);
    }
}

