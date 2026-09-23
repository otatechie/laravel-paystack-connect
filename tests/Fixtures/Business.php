<?php

namespace Otatechie\PaystackConnect\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Otatechie\PaystackConnect\Concerns\HasPaystackSubaccount;

class Business extends Model
{
    use HasPaystackSubaccount;

    protected $guarded = [];
}
