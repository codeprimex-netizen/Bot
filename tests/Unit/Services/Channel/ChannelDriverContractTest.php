<?php

declare(strict_types=1);

use App\Enums\ChannelCapability;
use App\Enums\ChannelCapabilitySupport;
use App\Enums\ChannelMode;
use App\Enums\MediaKind;
use App\Enums\PresenceState;
use App\Enums\SessionLoginMethod;
use App\Enums\SessionStatus;
use App\Models\Session;
use App\Services\Bridge\BridgeClient;
use App\Services\Bridge\MediaPayload;
use App\Services\Bridge\SentMessageDto;
use App\Services\Bridge\SessionInitDto;
use App\Services\Bridge\SessionStateDto;
use App\Services\Channel\ChannelCredentials;
use App\Services\Channel\ChannelDriver;
use App\Services\Channel\ChannelHealth;
use App\Services\Channel\Concerns\DerivesChannelPolicy;
use App\Services\Channel\InboundEvent;
use App\Services\Channel\ModeGuardedChannelDriver;
use App\Services\Channel\OutboundContent;
use App\Services\Channel\RegistrationResult;
use App\Services\Channel\SendReceipt;
use App\Services\Channel\TemplateRef;
use App\Services\Channel\TextContent;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| ChannelDriver — the contract itself (Req 8.1 / A8; Req 33.1 / NFR4)
|--------------------------------------------------------------------------
| An interface's value is in the contract being right, so what is pinned here is the
| contract rather than any implementation of it: that `ChannelDriver` really is a
| `BridgeClient` and adds only the eight members design.md names, and that the two *derived*
| answers — `requiresAntiBan()` (Req 8.8, Property 24) and `supports()` (Req 8.3,
| Property 21) — cannot drift from the enums they come from even when the driver underneath
| actively lies about them.
|
| Concrete drivers are tasks 7.1–7.5, so every implementation below is an anonymous class
| local to this file. `channelDriverDouble()` is honest and uses the trait real drivers will;
| the same factory with `honest: false` is the adversary the decorator has to hold.
|
| The mode/capability assertions loop the whole 4 × 13 matrix rather than sampling it: that is
| 52 cases, cheap, and exhaustive — a stronger statement than any random sample, and the one
| Properties 21 and 24 will later make against live drivers (tasks 7.7, 8.7).
*/

/**
 * A `ChannelDriver` that exists only here.
 *
 * `honest: true` uses `DerivesChannelPolicy`, exactly as tasks 7.1–7.5 are expected to, and
 * `$refinement` stands in for task 7.4's per-provider resolution. `honest: false` answers
 * `requiresAntiBan(): false` and `supports(): true` unconditionally — the two lies with the
 * worst consequences (a banned number, and a group op dispatched to Cloud API).
 *
 * @param  ArrayObject<int, string>|null  $log  records transport calls, to prove delegation
 */
function channelDriverDouble(
    ChannelMode $mode,
    bool $honest = true,
    ?ChannelCapabilitySupport $refinement = null,
    ?ArrayObject $log = null,
): ChannelDriver {
    /** @var ArrayObject<int, string> $calls */
    $calls = $log ?? new ArrayObject;

    return new class($mode, $honest, $refinement, $calls) implements ChannelDriver
    {
        use DerivesChannelPolicy {
            requiresAntiBan as private derivedRequiresAntiBan;
            supports as private derivedSupports;
        }

        /**
         * @param  ArrayObject<int, string>  $calls
         */
        public function __construct(
            private readonly ChannelMode $mode,
            private readonly bool $honest,
            private readonly ?ChannelCapabilitySupport $refinement,
            private readonly ArrayObject $calls,
        ) {}

        public function mode(): ChannelMode
        {
            return $this->mode;
        }

        public function requiresAntiBan(): bool
        {
            return $this->honest ? $this->derivedRequiresAntiBan() : false;
        }

        public function supports(ChannelCapability $capability): bool
        {
            return $this->honest ? $this->derivedSupports($capability) : true;
        }

        protected function refineSupport(
            ChannelCapability $capability,
            ChannelCapabilitySupport $ceiling,
        ): ChannelCapabilitySupport {
            return $this->refinement ?? $ceiling;
        }

        public function send(Session $session, OutboundContent $content): SendReceipt
        {
            $this->calls[] = 'send:'.$content->idempotencyKey();

            return new SendReceipt(
                mode: $this->mode,
                providerMessageId: 'wamid.'.$content->idempotencyKey(),
                recipient: $content->recipient(),
                idempotencyKey: $content->idempotencyKey(),
                capability: $content->capability(),
            );
        }

        public function sendTemplate(Session $session, TemplateRef $template, array $vars): SendReceipt
        {
            $this->calls[] = 'sendTemplate:'.$template->key();

            return new SendReceipt(
                mode: $this->mode,
                providerMessageId: 'wamid.tpl',
                recipient: '15550001111@s.whatsapp.net',
                idempotencyKey: 'tpl-key',
                capability: ChannelCapability::Template,
                templateName: $template->name,
            );
        }

        public function parseWebhook(Request $request, ChannelCredentials $credentials): InboundEvent
        {
            $this->calls[] = 'parseWebhook';

            return InboundEvent::unsupported($this->mode, $credentials->tenantId);
        }

        public function register(Session $session, ChannelCredentials $credentials): RegistrationResult
        {
            $this->calls[] = 'register';

            return RegistrationResult::live($credentials, 'phone-number-id');
        }

        public function healthCheck(ChannelCredentials $credentials): ChannelHealth
        {
            $this->calls[] = 'healthCheck';

            return ChannelHealth::healthy($credentials);
        }

        public function provisionSession(
            string $sessionId,
            SessionLoginMethod $method,
            ?string $phone = null,
            ?string $authStateDir = null,
        ): SessionInitDto {
            $this->calls[] = 'provisionSession:'.$sessionId.':'.$method->value.':'.($phone ?? '-').':'.($authStateDir ?? '-');

            return new SessionInitDto(sessionId: $sessionId, status: SessionStatus::QrPending);
        }

        public function startSession(string $sessionId): void
        {
            $this->calls[] = 'startSession:'.$sessionId;
        }

        public function stopSession(string $sessionId, bool $logout = false): void
        {
            $this->calls[] = 'stopSession:'.$sessionId.':'.($logout ? 'logout' : 'keep');
        }

        public function sessionState(string $sessionId): SessionStateDto
        {
            $this->calls[] = 'sessionState:'.$sessionId;

            return new SessionStateDto(sessionId: $sessionId, status: SessionStatus::Connected);
        }

        public function qr(string $sessionId): string
        {
            $this->calls[] = 'qr:'.$sessionId;

            return 'base64-qr';
        }

        public function pairingCode(string $sessionId, string $phone): string
        {
            $this->calls[] = 'pairingCode:'.$sessionId.':'.$phone;

            return 'PAIR1234';
        }

        public function sendText(string $sessionId, string $jid, string $text, array $opts = []): SentMessageDto
        {
            $this->calls[] = 'sendText:'.$sessionId.':'.$jid.':'.count($opts);

            return new SentMessageDto(waMessageId: 'wamid.text', jid: $jid);
        }

        public function sendMedia(string $sessionId, string $jid, MediaPayload $media, array $opts = []): SentMessageDto
        {
            $this->calls[] = 'sendMedia:'.$sessionId.':'.$media->kind->value;

            return new SentMessageDto(waMessageId: 'wamid.media', jid: $jid);
        }

        public function sendPresence(string $sessionId, string $jid, PresenceState $presence): void
        {
            $this->calls[] = 'sendPresence:'.$sessionId.':'.$presence->value;
        }

        public function checkNumbers(string $sessionId, array $numbers): array
        {
            $this->calls[] = 'checkNumbers:'.count($numbers);

            return [];
        }

        public function isReachable(): bool
        {
            $this->calls[] = 'isReachable';

            return true;
        }
    };
}

/**
 * A session on `$mode`, unsaved — the routing fact the decorator checks, and nothing else.
 */
function channelSessionOn(ChannelMode $mode): Session
{
    return new Session(['channel_mode' => $mode]);
}

function cloudApiCredentials(string $tenantId = 'tenant-1'): ChannelCredentials
{
    return new ChannelCredentials(
        tenantId: $tenantId,
        mode: ChannelMode::CloudApi,
        config: ['phone_number_id' => '109876543210'],
        secrets: ['access_token' => 'EAAGm0PX4ZoBACCESSTOKEN'],
    );
}

/*
|--------------------------------------------------------------------------
| The shape of the contract
|--------------------------------------------------------------------------
*/

it('is a BridgeClient, so the existing transport satisfies it unchanged', function (): void {
    // design § Channel Mode 2.1: BridgeClient becomes the transport contract every driver
    // implements, and task 7.1 wraps HttpBridgeClient with "unchanged behaviour".
    expect(interface_exists(ChannelDriver::class))->toBeTrue()
        ->and(class_implements(ChannelDriver::class))->toHaveKey(BridgeClient::class);
});

it('adds exactly the eight members design.md names, and re-declares no transport method', function (): void {
    $declared = array_values(array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        array_filter(
            (new ReflectionClass(ChannelDriver::class))->getMethods(),
            static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === ChannelDriver::class,
        ),
    ));

    sort($declared);

    expect($declared)->toBe([
        'healthCheck',
        'mode',
        'parseWebhook',
        'register',
        'requiresAntiBan',
        'send',
        'sendTemplate',
        'supports',
    ]);

    // "Genuinely additive": not one BridgeClient signature is re-shaped, which is what makes
    // BaileysChannelDriver (task 7.1) a wrapper rather than a rewrite.
    $transport = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(BridgeClient::class))->getMethods(),
    );

    expect(array_intersect($declared, $transport))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| requiresAntiBan() — Req 8.8, Property 24
|--------------------------------------------------------------------------
*/

it('derives requiresAntiBan from the mode for every mode, via the trait', function (): void {
    foreach (ChannelMode::cases() as $mode) {
        expect(channelDriverDouble($mode)->requiresAntiBan())
            ->toBe($mode->isWebProtocol(), $mode->value);
    }

    // The concrete claim, spelled out: the two web-protocol modes and no others.
    expect(channelDriverDouble(ChannelMode::Baileys)->requiresAntiBan())->toBeTrue()
        ->and(channelDriverDouble(ChannelMode::OnPremise)->requiresAntiBan())->toBeTrue()
        ->and(channelDriverDouble(ChannelMode::CloudApi)->requiresAntiBan())->toBeFalse()
        ->and(channelDriverDouble(ChannelMode::BspGateway)->requiresAntiBan())->toBeFalse();
});

it('cannot be talked out of the anti-ban gate by the driver underneath', function (): void {
    // Req 8.8: the gate must not be disableable. A driver that answers `false` for a
    // web-protocol mode is the exact bypass that requirement forbids, so the decorator does
    // not ask it.
    foreach (ChannelMode::webProtocol() as $mode) {
        $liar = channelDriverDouble($mode, honest: false);

        expect($liar->requiresAntiBan())->toBeFalse()
            ->and((new ModeGuardedChannelDriver($liar))->requiresAntiBan())->toBeTrue($mode->value);
    }

    // And it does not invent the gate where the mode says it does not apply, either.
    foreach ([ChannelMode::CloudApi, ChannelMode::BspGateway] as $mode) {
        expect((new ModeGuardedChannelDriver(channelDriverDouble($mode)))->requiresAntiBan())->toBeFalse();
    }
});

it('agrees with Session::requiresAntiBan(), which reads the same enum', function (): void {
    // Two readers of one fact. If they could disagree, the send gate would branch one way and
    // the session screen would report the other.
    foreach (ChannelMode::cases() as $mode) {
        expect(channelDriverDouble($mode)->requiresAntiBan())
            ->toBe(channelSessionOn($mode)->requiresAntiBan(), $mode->value);
    }
});

/*
|--------------------------------------------------------------------------
| supports() — Req 8.3, Property 21
|--------------------------------------------------------------------------
*/

it('answers supports() from the capability matrix for all 4 x 13 cells', function (): void {
    foreach (ChannelMode::cases() as $mode) {
        $driver = channelDriverDouble($mode);

        foreach (ChannelCapability::cases() as $capability) {
            expect($driver->supports($capability))
                ->toBe($capability->supportedOn($mode), $mode->value.'/'.$capability->value)
                // Same answer as the session and the enum: one matrix, three readers.
                ->toBe(channelSessionOn($mode)->supports($capability));
        }
    }
});

it('reads a Conditional cell as supported, keeping the three states for tasks 8.1 and 8.4', function (): void {
    $baileys = channelDriverDouble(ChannelMode::Baileys);

    // Baileys renders a template as text only — `⚠️`, not `❌` — so it may be attempted and a
    // later gate enforces the condition. Collapsing it to unsupported here would refuse a
    // legal send; collapsing it to native would lose the rule.
    expect(ChannelCapability::Template->supportOn(ChannelMode::Baileys))
        ->toBe(ChannelCapabilitySupport::Conditional)
        ->and($baileys->supports(ChannelCapability::Template))->toBeTrue();
});

it('lets a refinement narrow a supported cell — task 7.4 resolving a per-provider matrix', function (): void {
    // A partner that genuinely lacks receipts: `⚠️ per provider` resolved downward to a
    // refusal. This direction is the whole point of the runtime sub-matrix.
    $narrowing = channelDriverDouble(
        ChannelMode::BspGateway,
        refinement: ChannelCapabilitySupport::Unsupported,
    );

    expect(ChannelCapability::DeliveryReceipts->supportOn(ChannelMode::BspGateway))
        ->toBe(ChannelCapabilitySupport::Conditional)
        ->and($narrowing->supports(ChannelCapability::DeliveryReceipts))->toBeFalse()
        // Narrowing survives the decorator: `true && false` is still false.
        ->and((new ModeGuardedChannelDriver($narrowing))->supports(ChannelCapability::DeliveryReceipts))
        ->toBeFalse();
});

it('refuses to let a refinement widen a refusal, whatever it answers', function (): void {
    // The floor Property 21 rests on. `refineSupport()` is routed through
    // ChannelCapabilitySupport::refinedBy(), whose Unsupported arm is absorbing — so a
    // refinement that answers Native for a `❌` cell changes nothing.
    $widening = channelDriverDouble(
        ChannelMode::CloudApi,
        refinement: ChannelCapabilitySupport::Native,
    );

    foreach (ChannelCapability::unsupportedBy(ChannelMode::CloudApi) as $refused) {
        expect($widening->supports($refused))->toBeFalse($refused->value);
    }

    // ...and the five Baileys-only capabilities are what those are, on all three official
    // modes: groups, welcome, extraction, tagging, channels.
    foreach ([ChannelMode::CloudApi, ChannelMode::OnPremise, ChannelMode::BspGateway] as $mode) {
        $driver = channelDriverDouble($mode, refinement: ChannelCapabilitySupport::Native);

        foreach ([
            ChannelCapability::Groups,
            ChannelCapability::Welcome,
            ChannelCapability::Extraction,
            ChannelCapability::Tagging,
            ChannelCapability::Channels,
        ] as $baileysOnly) {
            expect($driver->supports($baileysOnly))->toBeFalse($mode->value.'/'.$baileysOnly->value);
        }
    }
});

it('holds a driver that ignores the matrix entirely to it, once wrapped', function (): void {
    // The adversary: `supports()` returns true for everything. Without the decorator that is a
    // group-management call dispatched to Meta's Cloud API, which fails mid-send instead of
    // being refused before dispatch (Req 8.3).
    $liar = channelDriverDouble(ChannelMode::CloudApi, honest: false);
    $guarded = new ModeGuardedChannelDriver($liar);

    expect($liar->supports(ChannelCapability::Groups))->toBeTrue();

    foreach (ChannelCapability::cases() as $capability) {
        expect($guarded->supports($capability))
            ->toBe($capability->supportedOn(ChannelMode::CloudApi), $capability->value);
    }
});

it('lists exactly the capabilities the mode allows, which is what task 8.2 persists', function (): void {
    // The trait on its own — all it requires is `mode()`, so the derivation rules can be
    // asserted without an implementation of the whole eight-member contract in the way.
    foreach (ChannelMode::cases() as $mode) {
        $policy = new class($mode)
        {
            use DerivesChannelPolicy;

            public function __construct(private readonly ChannelMode $mode) {}

            public function mode(): ChannelMode
            {
                return $this->mode;
            }
        };

        expect($policy->capabilities())->toBe(ChannelCapability::supportedBy($mode), $mode->value)
            ->and($policy->requiresAntiBan())->toBe($mode->isWebProtocol());

        // supportFor() keeps the `⚠️` that supports() collapses — the three states tasks 8.1
        // and 8.4 need in order to know a 24-hour-window or provider-tier rule is still owed.
        foreach (ChannelCapability::cases() as $capability) {
            expect($policy->supportFor($capability))
                ->toBe($capability->supportOn($mode), $mode->value.'/'.$capability->value);
        }
    }
});

/*
|--------------------------------------------------------------------------
| The routing and credential refusals — Properties 22, 23
|--------------------------------------------------------------------------
*/

it('refuses to send a session through another mode\'s driver', function (): void {
    $guarded = new ModeGuardedChannelDriver(channelDriverDouble(ChannelMode::CloudApi));
    $content = TextContent::to('15550001111@s.whatsapp.net', 'hi', 'idem-1');

    expect(fn (): SendReceipt => $guarded->send(channelSessionOn(ChannelMode::Baileys), $content))
        ->toThrow(InvalidArgumentException::class, 'BAILEYS');

    // The matching session goes straight through.
    expect($guarded->send(channelSessionOn(ChannelMode::CloudApi), $content)->idempotencyKey)
        ->toBe('idem-1');
});

it('refuses credentials belonging to another mode', function (): void {
    $guarded = new ModeGuardedChannelDriver(channelDriverDouble(ChannelMode::BspGateway));
    $cloudApi = cloudApiCredentials();

    expect(fn (): ChannelHealth => $guarded->healthCheck($cloudApi))
        ->toThrow(InvalidArgumentException::class, 'CLOUD_API')
        ->and(fn (): InboundEvent => $guarded->parseWebhook(Request::create('/webhooks/bsp/k'), $cloudApi))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): RegistrationResult => $guarded->register(channelSessionOn(ChannelMode::BspGateway), $cloudApi))
        ->toThrow(InvalidArgumentException::class);
});

/*
|--------------------------------------------------------------------------
| The transport half is untouched
|--------------------------------------------------------------------------
*/

it('delegates every transport method verbatim, so wrapping changes no existing behaviour', function (): void {
    /** @var ArrayObject<int, string> $log */
    $log = new ArrayObject;
    $guarded = new ModeGuardedChannelDriver(channelDriverDouble(ChannelMode::Baileys, log: $log));

    $guarded->provisionSession('sess-1', SessionLoginMethod::Qr);
    $guarded->startSession('sess-1');
    $guarded->stopSession('sess-1', true);
    $guarded->sessionState('sess-1');
    $guarded->qr('sess-1');
    $guarded->pairingCode('sess-1', '15550001111');
    $guarded->sendText('sess-1', '15550002222@s.whatsapp.net', 'hi', ['linkPreview' => false]);
    $guarded->sendMedia('sess-1', '15550002222@s.whatsapp.net', MediaPayload::fromUrl(
        url: 'https://example.test/a.png',
        mimeType: 'image/png',
        kind: MediaKind::Image,
    ));
    $guarded->sendPresence('sess-1', '15550002222@s.whatsapp.net', PresenceState::Composing);
    $guarded->checkNumbers('sess-1', ['15550003333']);
    $guarded->isReachable();

    expect($log->getArrayCopy())->toBe([
        'provisionSession:sess-1:QR:-:-',
        'startSession:sess-1',
        'stopSession:sess-1:logout',
        'sessionState:sess-1',
        'qr:sess-1',
        'pairingCode:sess-1:15550001111',
        'sendText:sess-1:15550002222@s.whatsapp.net:1',
        'sendMedia:sess-1:image',
        'sendPresence:sess-1:composing',
        'checkNumbers:1',
        'isReachable',
    ]);
});

it('returns the wrapped driver\'s own answers, unaltered', function (): void {
    $inner = channelDriverDouble(ChannelMode::Baileys);
    $guarded = new ModeGuardedChannelDriver($inner);

    expect($guarded->inner())->toBe($inner)
        ->and($guarded->mode())->toBe(ChannelMode::Baileys)
        ->and($guarded->qr('sess-1'))->toBe('base64-qr')
        ->and($guarded->pairingCode('sess-1', '15550001111'))->toBe('PAIR1234')
        ->and($guarded->isReachable())->toBeTrue()
        ->and($guarded->sendText('sess-1', 'jid', 'hi')->waMessageId)->toBe('wamid.text');
});
