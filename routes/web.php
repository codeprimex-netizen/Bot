<?php

declare(strict_types=1);

use App\Http\Controllers\DomainChallengeController;
use App\Services\Domains\DomainVerifier;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
| ACME-style HTTP ownership challenge for a tenant custom domain (Req 9.7 / A9).
|
| Answers on *any* host, deliberately: its whole purpose is to be reachable at the host
| under verification, and it only ever responds when the request host matches a row that
| carries the presented token (see `DomainChallengeController`). The path is configurable
| (`wa.tenancy.domains.http_challenge_path`) but is registered from the configured value
| here, so the URL the verifier fetches and the URL the platform serves are the same
| string by construction.
|
| No `auth` and no `resolve.tenant` semantics are needed: nothing on this path binds a
| tenant, reads a session, or emits a URL. It is a public, side-effect-free echo of a
| token the caller already presented.
*/
Route::get(
    trim((string) config('wa.tenancy.domains.http_challenge_path', DomainVerifier::DEFAULT_HTTP_PATH), '/ ').'/{token}',
    DomainChallengeController::class,
)->where('token', '[A-Za-z0-9_-]{8,128}')->name('domains.challenge');
