<?php

declare(strict_types=1);

namespace App\Services\Channel;

use App\Enums\ChannelMode;
use LogicException;

/**
 * What *"marked deprecated with a documented `ON_PREMISE → CLOUD_API` migration path"* is, as
 * code — the whole of Req 8.9 / A8 that is not already on `App\Enums\ChannelMode`
 * (design § Channel Mode 2.2 mode 3, 2.8).
 *
 * ```php
 * OnPremiseDeprecation::notice();          // the sentence the mode picker and the health light show
 * OnPremiseDeprecation::target();          // ChannelMode::CloudApi
 * OnPremiseDeprecation::migrationPath();   // list<OnPremiseMigrationStep>, ordered, with ordering hazards
 * OnPremiseDeprecation::logContext($tenantId);   // the operator's half, for the warning on every login
 * ```
 *
 * ## Why a `@deprecated` tag was not enough, and what "marked" turned into
 *
 * Req 8.9 says the mode is marked deprecated **in UI** and the path is **exposed**. A
 * `@deprecated` PHPDoc tag is read by an IDE and by nobody else: it does not reach the tenant
 * choosing a mode, the operator reading logs, or the connection screen showing a red light. So
 * "marked" is implemented at four surfaces, and none of them is optional:
 *
 * | Surface | Mechanism | Who sees it |
 * |---|---|---|
 * | the mode picker (§4.1 row 10) | `ChannelMode::isDeprecated()` + `notice()` + `migrationPath()` | the tenant, **before** choosing the mode |
 * | the connection screen / `channel_credentials` | `OnPremiseChannelDriver::healthCheck()` prefixes **every** `ChannelHealth::$detail` with `notice()` | the tenant, on every probe, healthy or not |
 * | the session's registration record | `OnPremiseChannelDriver::register()` prefixes its `RegistrationResult::$detail` | the tenant and the operator, once per session |
 * | the logs | a `warning` on every On-Premise **login**, i.e. once per request or job that actually used the backend | the operator, at a rate that is neither zero nor per-send |
 *
 * The login is deliberately where the log line lives. It is the one event that happens exactly
 * once per unit of work (`OnPremiseTokenCache` is what makes that true), so a campaign of nine
 * thousand sends produces one warning rather than nine thousand — and a deployment with no
 * On-Premise tenant produces none, which is what keeps the warning meaningful.
 *
 * What is deliberately **not** done: the capability matrix is not narrowed and no send is
 * refused. Design § 2.2 says the mode is *"supported for tenants who still run it"*, and a
 * platform that degraded a working backend to make a point would break the tenants the
 * requirement exists to protect.
 *
 * ## Why this class holds no state and cannot be constructed
 *
 * Every value here is a platform-authored constant. There is nothing per tenant, so an
 * instance would only be a way for one caller to hold a stale copy — and there is nothing to
 * inject, so a container binding would be a fifth place to look for a fixed string.
 *
 * ## The migration path, and the one thing about it that is irreversible
 *
 * `migrationPath()` is eight ordered steps (`OnPremiseMigrationStep`). Two of them cannot be
 * undone, and both are in the middle:
 *
 * - **step 4**, requesting the migration from the Cloud API side, must happen *while* the
 *   number is still registered on the container and needs the two-step verification PIN from
 *   step 1. Deregistering or deleting the container first leaves the number on **neither**
 *   backend, recoverable only by a fresh SMS/voice verification — and not recoverable at all
 *   if the PIN was only ever stored on the container that was destroyed;
 * - **step 8**, decommissioning the container, must follow the mode switch of step 7, because
 *   that switch is what drains the sends still queued on the On-Premise driver (task 8.6).
 *
 * Every step therefore carries `breaksIfEarly` in the tenant's own terms, so the checklist does
 * not rely on a reader inferring the hazard from the ordering. See `OnPremiseMigrationStep`.
 */
final class OnPremiseDeprecation
{
    /**
     * The mode this is about — the only deprecated one (`ChannelMode::isDeprecated()`).
     */
    public const ChannelMode MODE = ChannelMode::OnPremise;

    /**
     * The stable log code an operator alerts on, and the key the panel's copy is looked up by.
     *
     * A constant rather than an inline string so the alert rule, the log line and the test all
     * name the same thing.
     */
    public const string CODE = 'channel.on_premise.deprecated';

    /**
     * The one sentence every tenant-facing surface shows.
     *
     * Deliberately **short**, and that is a functional requirement rather than a style
     * preference: it is prefixed to every `ChannelHealth::$detail` and every
     * `RegistrationResult::$detail`, both of which are bounded at
     * `ChannelCredentials::MAX_DETAIL_LENGTH` by `ChannelCredentials::redact()`. A notice long
     * enough to push the actual health answer past that bound would truncate the thing a tenant
     * needs in order to fix its credentials — so the long form lives in `migrationPath()`, and
     * `OnPremiseChannelDriverTest` asserts that no detail this prefix produces is ever truncated.
     *
     * Specific enough to be an instruction rather than a warning: it names the target mode, which
     * is the only thing the tenant has to act on.
     */
    public static function notice(): string
    {
        return 'DEPRECATED: Meta is sunsetting the On-Premise API — migrate this number to '
            .self::target()->value.', following the ordered migration path (its steps are not '
            .'interchangeable).';
    }

    /**
     * The mode to migrate to.
     *
     * Read from the enum rather than restated, so `ChannelMode::migrationTarget()` and this
     * class cannot name two different destinations. A `null` there would mean the enum has
     * stopped calling `ON_PREMISE` deprecated, which is a contradiction rather than a state to
     * degrade into.
     *
     * @throws LogicException when the enum no longer declares a migration target for this mode
     */
    public static function target(): ChannelMode
    {
        $target = self::MODE->migrationTarget();

        if ($target === null) {
            throw new LogicException(sprintf(
                'ChannelMode::%s no longer declares a migration target, but this class exists to '
                .'document one. One of the two is wrong; they must not disagree in a running system.',
                self::MODE->name,
            ));
        }

        return $target;
    }

    /**
     * `ON_PREMISE → CLOUD_API`, as one token for a log line, an audit payload, or a panel badge.
     */
    public static function path(): string
    {
        return self::MODE->value.' → '.self::target()->value;
    }

    /**
     * The ordered migration path — see the class docblock for the two irreversible steps.
     *
     * @return non-empty-list<OnPremiseMigrationStep>
     */
    public static function migrationPath(): array
    {
        return [
            new OnPremiseMigrationStep(
                order: 1,
                title: 'Record the number\'s two-step verification PIN, and export what you need from the container',
                detail: 'The Cloud API registration in step 4 requires the same two-step verification PIN the '
                    .'On-Premise number was set up with. Write it down somewhere that is not the container. '
                    .'Export any message history, media, and contact data you need from the container\'s own '
                    .'database now: the platform holds its own copy of everything it sent and received, but '
                    .'anything the container stored and the platform never saw is only there.',
                breaksIfEarly: 'Nothing — this step is additive and repeatable. Skipping it is what breaks: '
                    .'without the PIN, step 4 cannot be completed, and after step 4 the container is no longer '
                    .'the number\'s registration so the PIN cannot be read back from it.',
            ),
            new OnPremiseMigrationStep(
                order: 2,
                title: 'Create the WhatsApp Business Account and re-submit every approved template',
                detail: 'Template approval is per WhatsApp Business Account and does not transfer with a '
                    .'number. Submit each template the tenant actually sends — same name, same language tag — '
                    .'into the new WABA and wait for Meta to approve them before any traffic moves.',
                breaksIfEarly: 'Nothing — approvals are additive. Doing it *late* is the hazard: a number that '
                    .'is live on Cloud API with no approved template can still reply inside the 24-hour '
                    .'customer-service window and cannot send anything outside it, so campaigns and '
                    .'notifications fail while ordinary replies keep working — which reads as an intermittent '
                    .'fault rather than a missing template.',
            ),
            new OnPremiseMigrationStep(
                order: 3,
                title: 'Enter the CLOUD_API credentials on the platform and let them verify — but do not switch the mode',
                detail: 'Add the WABA id, phone-number id, system-user access token, webhook verify token and '
                    .'app secret as a CLOUD_API credential set. The platform probes them before activating '
                    .'them, so a typo is caught here rather than during the switch.',
                breaksIfEarly: 'Entering them before the WABA exists records the set as invalid and it has to '
                    .'be retyped. Switching the session\'s mode at this point — rather than at step 7 — points '
                    .'every send at a number Meta has not been given yet, and each one is refused.',
            ),
            new OnPremiseMigrationStep(
                order: 4,
                title: 'Request the migration from the Cloud API side, while the number is still on the container',
                detail: 'Migrate the number into the new WABA from Meta\'s side, using the PIN from step 1. The '
                    .'On-Premise client keeps serving traffic until Meta completes the move, and then reports '
                    .'its gateway as unregistered — which is the signal that the move happened.',
                breaksIfEarly: 'Deregistering the number on the container, or deleting the container, before '
                    .'this step leaves the number registered on neither backend. Recovering it needs a fresh '
                    .'SMS or voice verification, and is not possible at all if the PIN from step 1 was only '
                    .'ever stored on the container that was destroyed.',
                irreversible: true,
            ),
            new OnPremiseMigrationStep(
                order: 5,
                title: 'Wait until Meta reports the number verified',
                detail: 'The platform\'s CLOUD_API registration check reports the number as pending until Meta '
                    .'says verified. That answer, not the elapsed time, is what step 7 waits for.',
                breaksIfEarly: 'Nothing — this step is a wait. It exists because the two steps around it are '
                    .'the ones that must not overlap.',
            ),
            new OnPremiseMigrationStep(
                order: 6,
                title: 'Re-point inbound: set the Meta app\'s callback, then clear the container\'s webhook',
                detail: 'Point the Meta app\'s webhook at the platform\'s cloud-api callback URL with the '
                    .'verify token from step 3, confirm the handshake, and only then clear the On-Premise '
                    .'container\'s configured webhook URL.',
                breaksIfEarly: 'Clearing the container\'s webhook before Meta is delivering drops every '
                    .'customer message that arrives in the gap. The customer\'s phone shows the message as '
                    .'delivered and the platform never sees it, so there is nothing to retry and no error to '
                    .'find afterwards.',
            ),
            new OnPremiseMigrationStep(
                order: 7,
                title: 'Switch the session\'s channel mode to CLOUD_API',
                detail: 'The platform stops admitting new sends to the On-Premise driver, drains or explicitly '
                    .'fails what is already queued on it, re-runs the capability handshake for the new mode, '
                    .'and swaps the anti-ban gate for the provider\'s template and 24-hour-window rules. The '
                    .'switch is audited with both modes and the drained and failed counts.',
                breaksIfEarly: 'Switching before Meta reports the number verified marks the session sendable '
                    .'against a registration that does not exist yet, so every send is refused as an auth or '
                    .'registration failure — and, because those are not retryable, the messages are lost '
                    .'rather than deferred.',
            ),
            new OnPremiseMigrationStep(
                order: 8,
                title: 'Decommission the On-Premise container',
                detail: 'Once inbound traffic has been observed on the Cloud API route for a full business '
                    .'day, and the platform reports no queued sends left on the old driver, retire the '
                    .'container and its database.',
                breaksIfEarly: 'Destroying the container before step 7\'s drain has finished fails the sends '
                    .'that were still queued on the On-Premise driver, and the container\'s own copy of '
                    .'anything the platform never received goes with it.',
                irreversible: true,
            ),
        ];
    }

    /**
     * The migration path as panel-renderable rows.
     *
     * @return non-empty-list<array{order: int, title: string, detail: string, breaksIfEarly: string, irreversible: bool}>
     */
    public static function migrationPathArray(): array
    {
        $rows = array_map(
            static fn (OnPremiseMigrationStep $step): array => $step->toArray(),
            self::migrationPath(),
        );

        /** @var non-empty-list<array{order: int, title: string, detail: string, breaksIfEarly: string, irreversible: bool}> $rows */
        return $rows;
    }

    /**
     * The operator's half — the context of the `warning` logged on every On-Premise login.
     *
     * The tenant id is **fingerprinted** rather than written down, the way
     * `WebhookVerificationException::fingerprint()` does it: an operator needs to tell one
     * tenant's deprecation warnings from another's and to correlate them with a refusal, and
     * neither of those needs the id itself in a log file (Req 7.3 / A7).
     *
     * @return array<string, string|int>
     */
    public static function logContext(string $tenantId): array
    {
        return [
            'code' => self::CODE,
            'mode' => self::MODE->value,
            'migration' => self::path(),
            'steps' => count(self::migrationPath()),
            'tenant' => $tenantId === '' ? '<none>' : '#'.substr(hash('sha256', $tenantId), 0, 8),
        ];
    }

    /**
     * `$detail` with the deprecation notice in front of it.
     *
     * What `healthCheck()` and `register()` run their own sentence through, so the notice cannot
     * be attached at one of those surfaces and forgotten at the other. The result still goes
     * through `ChannelCredentials::redact()` inside `ChannelHealth` / `RegistrationResult`,
     * which is also what bounds its length.
     */
    public static function prefix(string $detail): string
    {
        $trimmed = trim($detail);

        return $trimmed === '' ? self::notice() : self::notice().' '.$trimmed;
    }

    /**
     * Not constructible: every member is a platform constant. See the class docblock.
     *
     * @throws LogicException always
     */
    private function __construct()
    {
        throw new LogicException('OnPremiseDeprecation is a set of platform constants and holds no state.');
    }
}
