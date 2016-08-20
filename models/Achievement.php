<?php 

use Illuminate\Database\Eloquent\Model as Eloquent;

class Achievement extends Eloquent {


	public function imagePath()
	{
		return '/assets/images/course/achievements/' . $this->id.'.png';
	}

}
