<?php

namespace Otatechie\PaystackConnect\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Otatechie\PaystackConnect\Concerns\HasPaystackPayments;
use Otatechie\PaystackConnect\Concerns\HasPaystackSubaccount;

class Business extends Model
{
    use HasPaystackPayments;
    use HasPaystackSubaccount;

    protected $guarded = [];
}
