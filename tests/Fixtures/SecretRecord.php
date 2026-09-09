<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Casts\Encrypted;
use App\Casts\EncryptedArray;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Database\TenantSchema;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tenant-owned model with encrypted attributes, standing in for the real
 * consumers of the casts (`channel_credentials` in task 6.4, lead PII, WA auth
 * state).
 *
 * It lives here rather than in `app/` because the casts must be provable *now*,
 * before any of those tables exist — and a throwaway production table would be
 * worse than a fixture. Its table is created per test from `migrate()` below.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $label
 * @property string|null $access_token
 * @property array<string, mixed>|null $secret_config
 */
class SecretRecord extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'test_secret_records';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'label',
        'access_token',
        'secret_config',
    ];

    /**
     * Create the fixture's table, shaped like a real tenant-owned secrets table.
     */
    public static function migrate(): void
    {
        if (Schema::hasTable('test_secret_records')) {
            return;
        }

        Schema::create('test_secret_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            TenantSchema::tenantId($table, 'label');
            $table->string('label')->nullable();
            $table->text('access_token')->nullable();
            $table->text('secret_config')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => Encrypted::class,
            'secret_config' => EncryptedArray::class,
        ];
    }
}
