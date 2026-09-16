<?php

namespace App\Models;

use Database\Factories\DeploymentHeartbeatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['deployment_id', 'app_version', 'students', 'teachers', 'users', 'modules_in_use'])]
class DeploymentHeartbeat extends Model
{
    /** @use HasFactory<DeploymentHeartbeatFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'modules_in_use' => 'array',
        ];
    }

    /**
     * Deployment that sent this heartbeat.
     */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }
}
