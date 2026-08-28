<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Team extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $fillable = ['tenant_id', 'code', 'name', 'leader_id', 'is_active', 'is_external'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_external' => 'boolean'];
    }

    public function leader()
    {
        return $this->belongsTo(User::class, 'leader_id');
    }

    public function members()
    {
        return $this->belongsToMany(User::class, 'team_members')
            ->withPivot(['member_role', 'joined_on', 'left_on']);
    }
}
