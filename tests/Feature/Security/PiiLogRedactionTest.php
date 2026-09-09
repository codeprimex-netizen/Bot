<?php

declare(strict_types=1);

use App\Exceptions\Security\PiiRedactionException;
use App\Logging\PiiRedactionProcessor;
use App\Logging\RedactingLogManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\Level;
use Monolog\LogRecord;

/*
|--------------------------------------------------------------------------
| Log redaction is plumbing, not a convention (Req 7.3 / A7; Req 32.2 / NFR3)
|--------------------------------------------------------------------------
| Req 7.3 says phone numbers are redacted in logs and message bodies are never
| logged — only content hashes. The only version of that guarantee worth having is
| one that holds for a log call written by somebody who has never read this file,
| on a channel configured after it, so every test below writes through the ordinary
| `Log` facade to a channel that has no tap, no processor list, and no special
| treatment, and then reads the bytes that landed on disk.
*/

/**
 * A real single-file channel, registered under a fresh name so nothing is cached
 * and nothing about it is special.
 *
 * The level is pinned rather than inherited: `LOG_LEVEL` comes from the
 * environment and a value Monolog rejects would fail the channel rather than the
 * assertion.
 */
function probeChannel(): string
{
    $name = 'pii_probe_'.Str::lower(Str::random(8));

    config()->set('logging.channels.'.$name, [
        'driver' => 'single',
        'path' => probeLogPath($name),
        'level' => 'debug',
        'replace_placeholders' => true,
    ]);

    return $name;
}

function probeLogPath(string $channel): string
{
    return sys_get_temp_dir().'/'.$channel.'.log';
}

function probeContents(string $channel): string
{
    $path = probeLogPath($channel);

    return is_file($path) ? (string) file_get_contents($path) : '';
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/pii_probe_*.log') ?: [] as $path) {
        @unlink($path);
    }
});

/*
|--------------------------------------------------------------------------
| The headline case
|--------------------------------------------------------------------------
*/

it('never writes a raw phone number that was handed to Log::info', function (): void {
    $channel = probeChannel();

    Log::channel($channel)->info('outbound send to +14155552671 accepted');

    $written = probeContents($channel);

    expect($written)->not->toContain('+14155552671')
        // Masked, not deleted: an operator can still tie the line to a ticket.
        ->and($written)->toContain('+14*******71')
        ->and($written)->toContain('outbound send to');
});

it('hashes a message body instead of writing it', function (): void {
    $channel = probeChannel();
    $body = 'Hi, my order 998 has not arrived yet';

    Log::channel($channel)->info('reply generated', ['body' => $body, 'conversation_id' => 'conv_1']);

    $written = probeContents($channel);

    expect($written)->not->toContain($body)
        ->and($written)->toContain('sha256:'.hash('sha256', $body))
        // Everything that is not content survives, or the line would be useless.
        ->and($written)->toContain('conv_1');
});

it('masks PII wherever it is in the context, at any depth', function (): void {
    $channel = probeChannel();

    Log::channel($channel)->warning('send failed', [
        'phone' => '919876543210',
        'meta' => ['customer' => ['email' => 'jane.doe+shop@example.com'], 'note' => 'called 415-555-2671 twice'],
        'payment' => ['card' => '4111111111111111'],
        'api_key' => 'sk-live-should-never-appear',
    ]);

    $written = probeContents($channel);

    foreach (['919876543210', 'jane.doe+shop@example.com', '415-555-2671', '4111111111111111', 'sk-live-should-never-appear'] as $value) {
        expect($written)->not->toContain($value);
    }

    expect($written)->toContain('[redacted]');
});

it('masks PII interpolated into the message through a placeholder', function (): void {
    $channel = probeChannel();

    Log::channel($channel)->info('dialling {phone}', ['phone' => '+14155552671']);

    expect(probeContents($channel))->not->toContain('+14155552671');
});

/*
|--------------------------------------------------------------------------
| Exception context — the path nobody remembers
|--------------------------------------------------------------------------
*/

it('masks PII inside an exception logged as context', function (): void {
    $channel = probeChannel();

    Log::channel($channel)->error('handler failed', [
        'exception' => new RuntimeException(
            'delivery to +14155552671 rejected',
            0,
            new LogicException('lookup of jane@example.com failed'),
        ),
    ]);

    $written = probeContents($channel);

    expect($written)->not->toContain('+14155552671');
    expect($written)->not->toContain('jane@example.com');

    // The shape survives: an operator still sees what failed and where.
    expect($written)->toContain('RuntimeException')
        ->and($written)->toContain('LogicException')
        ->and($written)->toContain('rejected');
});

/*
|--------------------------------------------------------------------------
| Coverage: every channel, however it was created
|--------------------------------------------------------------------------
*/

it('redacts on the default channel and on a stack', function (): void {
    $channel = probeChannel();

    config()->set('logging.channels.pii_probe_stacked', [
        'driver' => 'stack',
        'channels' => [$channel],
    ]);
    config()->set('logging.default', 'pii_probe_stacked');

    // No channel named: this is the call an ordinary piece of application code makes.
    Log::info('stacked send to +14155552671');

    expect(probeContents($channel))
        ->not->toContain('+14155552671')
        ->toContain('+14*******71');
});

it('redacts on a channel built at runtime, which no config file could have tapped', function (): void {
    $path = sys_get_temp_dir().'/pii_probe_'.Str::lower(Str::random(8)).'.log';

    Log::build(['driver' => 'single', 'path' => $path, 'level' => 'debug'])
        ->info('ad-hoc send to +14155552671');

    expect((string) file_get_contents($path))->not->toContain('+14155552671');
});

it('wires the redacting manager as the platform log manager', function (): void {
    expect(app('log'))->toBeInstanceOf(RedactingLogManager::class);
});

/*
|--------------------------------------------------------------------------
| Properties of the processor itself
|--------------------------------------------------------------------------
*/

it('marks a record as redacted and does not process it twice', function (): void {
    $channel = probeChannel();
    $body = 'exactly once please';

    Log::channel($channel)->info('body hashing is stable', ['body' => $body]);

    $written = probeContents($channel);

    // A stack copies its children's processors, so a record can meet the processor
    // more than once; a second pass would hash the digest and break correlation.
    expect($written)->toContain(PiiRedactionProcessor::MARKER)
        ->and($written)->toContain('sha256:'.hash('sha256', $body));
});

it('withholds a record it cannot redact rather than writing it raw', function (): void {
    $record = new LogRecord(
        new DateTimeImmutable,
        'probe',
        Level::Info,
        'leaking +14155552671',
        ['phone' => '+14155552671'],
    );

    // A backtrack budget of one step makes the detectors unrunnable — the same failure
    // a pathological input would cause, forced deterministically. The processor is
    // exercised directly so the assertion is about *its* behaviour rather than a
    // formatter's reaction to the same limit.
    $previous = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '1');

    try {
        $processed = app(PiiRedactionProcessor::class)($record);
    } finally {
        ini_set('pcre.backtrack_limit', is_string($previous) ? $previous : '1000000');
    }

    // The record survives so an operator knows something was logged at this level and
    // time; its content does not. Failing closed on content is the only safe direction.
    expect($processed->message)->not->toContain('+14155552671')
        ->and($processed->message)->toContain('PII redaction failed')
        ->and($processed->context)->toBe(['redaction_error' => PiiRedactionException::class])
        ->and($processed->extra[PiiRedactionProcessor::MARKER])->toBeTrue();
});
