<?php

declare(strict_types=1);

use App\Enums\ChannelTemplateStatus;
use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The approved-template registry for the official modes (Req 8.12 / A8; design § Channel
 * Mode data model `cloud_api_templates`, § 2.3 capability row *"Template messages
 * (approved)"*).
 *
 * ## What this table is for
 *
 * On `CLOUD_API`, `ON_PREMISE`, and `BSP_GATEWAY`, free-form content is only allowed inside
 * the 24-hour customer-service window; outside it the provider accepts **pre-approved
 * templates only**. Task 8.4 must therefore be able to answer, locally and before any
 * provider call, *"is there an approved template for this?"* — and block with
 * `TemplateRequiredException` when there is not. That answer cannot be a live provider
 * lookup: it is needed on every outbound send, including inside a campaign loop, and a
 * remote call there would make the block slower than the send it prevents.
 *
 * So this table is a **mirror**, not a source of truth. The provider owns `status`; task
 * 8.4 syncs it and stamps `synced_at`. Nothing on the platform may approve a template.
 *
 * ## Why the rows hang off `credential_id` and not off the mode
 *
 * A template is approved against the account it was submitted under — a WABA on Cloud API,
 * a partner account on a BSP. A tenant with two credential sets (a live Twilio account and
 * a sandbox) has two disjoint template registries, and sending a template approved on one
 * through the other fails at the provider. The foreign key onto `channel_credentials` is
 * what makes that structural: `ON DELETE CASCADE`, because a template registry without its
 * account is a set of names that cannot be sent.
 *
 * `tenant_id` is carried as well, rather than being reached through the credential row.
 * Both halves earn their place: the tenancy column is what `BelongsToTenant` scopes on (so
 * a registry read cannot cross tenants even if a caller supplies a foreign
 * `credential_id`), and the credential id is what pins a template to the account it is
 * valid for.
 *
 * ## `uniq(tenant_id, credential_id, name, language)`
 *
 * Providers key a template by `(name, language)` within an account, and so does this table.
 * The language belongs in the key rather than in a JSON bag because it is what a send
 * *selects* on: one template name usually has several localisations, and picking the wrong
 * one is a message in the wrong language rather than an error.
 *
 * `name` is capped at 150 characters — comfortably above every provider's own limit — which
 * keeps the four-column key well inside InnoDB's index-length budget under `utf8mb4`.
 *
 * ## `idx(tenant_id, status)`
 *
 * design.md's index, and the two reads that matter: the panel's approval-status screen
 * ("what is still pending?") and task 8.4's sync sweep, which only re-reads the statuses a
 * provider can still change (`ChannelTemplateStatus::isSyncable()`). Both are per tenant
 * and filter on status, which is exactly this index.
 *
 * ## Columns that hold provider text
 *
 * `body` is the template's own body text — the tenant's copy, with `{{1}}`-style
 * placeholders, not a customer's message — so it is stored as written; nothing here is
 * message content in the sense Req 7.3 forbids logging. `components` holds the structured
 * header/button/footer definition as JSON, because its shape is the provider's and differs
 * per provider; parsing it into columns would be inventing a schema Meta may change.
 * `provider_template_id` is the id the provider assigned, nullable because a locally
 * created template has none until it has been submitted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloud_api_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // idx(tenant_id, status): the approval screen, and task 8.4's sync sweep.
            TenantSchema::tenantId($table, 'status');

            // The provider account this template is approved against.
            $table->foreignUlid('credential_id')
                ->constrained('channel_credentials')
                ->cascadeOnDelete();

            // The provider's template name, and its localisation. Together with the
            // account, this is the key a send selects on.
            $table->string('name', 150);
            $table->string('language', 16);

            // `App\Enums\ChannelTemplateCategory` value: what the provider prices and
            // paces this template as.
            $table->string('category', 16);

            // The template's own body copy, with placeholders. The tenant's text.
            $table->text('body');

            // Header/button/footer definition, in the provider's shape.
            $table->json('components')->nullable();

            // `App\Enums\ChannelTemplateStatus` value. Owned by the provider; mirrored
            // here by task 8.4's sync.
            $table->string('status', 16)->default(ChannelTemplateStatus::default()->value);

            // The provider's own id for this template; NULL until submitted.
            $table->string('provider_template_id', 190)->nullable();

            // When the mirror was last refreshed. NULL means never synced, which is
            // distinguishable from "synced and still pending".
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['tenant_id', 'credential_id', 'name', 'language'],
                'cloud_api_templates_tenant_credential_name_language_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_api_templates');
    }
};
