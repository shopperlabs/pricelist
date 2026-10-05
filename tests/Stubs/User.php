<?php

declare(strict_types=1);

namespace Shopper\PriceList\Tests\Stubs;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Shopper\Models\Contracts\ShopperUser;
use Shopper\Traits\InteractsWithShopper;

class User extends Authenticatable implements ShopperUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use InteractsWithShopper;

    protected $guarded = [];

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
