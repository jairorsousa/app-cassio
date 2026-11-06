<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Storage;

/** Logo/foto do topo, por usuário. Arquivo privado (disco local), servido só ao dono. */
class BrandLogo
{
    public const SIZE = 512;

    public static function path(User $user): string
    {
        return 'branding/users/'.$user->id.'.png';
    }

    public static function exists(?User $user): bool
    {
        return $user !== null && Storage::disk('local')->exists(self::path($user));
    }

    public static function url(?User $user): ?string
    {
        return self::exists($user) ? route('settings.logo', ['v' => Storage::disk('local')->lastModified(self::path($user))]) : null;
    }
}
