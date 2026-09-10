<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DomainChallengeMethod;
use App\Models\TenantDomain;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Serves an ACME-style HTTP challenge token for a domain that already points at the
 * platform (Req 9.7 / A9).
 *
 * ```
 * GET http://chat.acme.example/.well-known/wa-domain-challenge/{token}
 * ```
 *
 * ## What this proves, and why the platform serves it
 *
 * `DomainChallengeMethod::HttpFile` asks: does `{host}` serve this token? There are two
 * ways for the answer to be yes, and both are proofs of control:
 *
 *  - the tenant's own web server serves the file (classic ACME http-01), which proves the
 *    tenant controls what that host answers with; **or**
 *  - the host's DNS already points at *us*, so this route answers — which proves the A /
 *    CNAME record for that host resolves to the platform, i.e. the tenant pointed it here.
 *
 * Without this route the second case could never pass: a domain already aimed at the
 * platform would get our 404, so the only satisfiable method would be DNS. Since a
 * custom domain must eventually point here for routing to work at all, that is the steady
 * state, and the HTTP method would be dead.
 *
 * ## Why echoing the token here is safe
 *
 * The token is looked up **with the request host as part of the key**: a row matches only
 * if its `host` equals the host the request arrived on. So this endpoint answers only for
 * the exact name the token was issued for, and only to a request that already reached us
 * *as* that name. It cannot be used to learn a token (you must present it), to test one
 * against a different host (the host is in the WHERE clause), or to enumerate (43 random
 * characters, and a miss is an indistinguishable 404).
 *
 * The host is used here in the same way `CustomDomainTenantResolver` uses it — as a
 * lookup key, never as an assertion — and nothing on this path binds a tenant, reads a
 * session, or emits a URL.
 *
 * ## Response shape
 *
 * `text/plain` with the token and no trailing newline, which is what
 * `DomainVerifier::checkHttpChallenge()` compares against (it trims, so a newline added
 * by a proxy is tolerated). A miss is a bare 404 with no body: it must be
 * indistinguishable from "this platform does not know that path".
 */
final class DomainChallengeController extends Controller
{
    public function __invoke(Request $request, string $token): Response
    {
        // By host only — `tenant_domains.host` is globally unique, so this is the one row
        // that could match. The token is then compared with `hash_equals` rather than in
        // the WHERE clause: a secret compared by the database is compared by a routine
        // that makes no timing promises, and there is no reason to rely on one here.
        $domain = TenantDomain::query()
            ->where('host', '=', strtolower(rtrim(trim($request->getHost()), '.')))
            ->first();

        if (! $domain instanceof TenantDomain
            || $domain->challenge_method !== DomainChallengeMethod::HttpFile
            || $domain->challenge_token === null
            || ! $domain->hasLiveChallenge()
            || ! hash_equals($domain->challenge_token, $token)
        ) {
            // One indistinguishable 404 for every miss: unknown host, wrong method, wrong
            // token, expired challenge. Anything else would answer questions about hosts
            // the caller has no claim to.
            return response('', 404);
        }

        return response($token, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
