<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class MasterAdmin extends Authenticatable implements JWTSubject
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Indicates if the model exists.
     *
     * @var bool
     */
    public $exists = true;

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [];

    /**
     * The "booting" method of the model.
     */
    protected static function boot()
    {
        parent::boot();

        // Prevent any database operations
        static::saving(function () {
            return false;
        });

        static::updating(function () {
            return false;
        });

        static::deleting(function () {
            return false;
        });
    }

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return 'master';
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array<string, mixed>
     */
    public function getJWTCustomClaims()
    {
        return [
            'is_master' => true,
        ];
    }

    /**
     * Validate the master admin password.
     */
    public function validatePassword(string $password): bool
    {
        $masterPassword = config('auth.master_admin_password');

        if (empty($masterPassword)) {
            return false;
        }

        return hash_equals($masterPassword, $password);
    }

    /**
     * Get a static instance for authentication.
     */
    public static function instance(): self
    {
        return new self();
    }
}
