<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeadFilterRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Receives a learned allow/deny rule from the peer site (gsc <-> jpeterson).
 *
 * Spammers email every vendor, so a sender an operator blocks on one site
 * should be blocked on the other too. This endpoint is the narrow transport
 * for that: it applies the SAME LeadFilterRule policy a local mark-as-spam/
 * mark-as-real would (LeadFilterRule::applyAllow/applyDeny), but never
 * dispatches SyncLeadFilterToPeer itself — only markAsReal()/markAsSpam()
 * do that. Applying an incoming rule here must not propagate it straight
 * back to where it came from.
 */
class LeadFilterSyncController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['allow', 'deny'])],
            'email' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'ip' => ['nullable', 'string', 'max:45'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $note = (string) ($data['note'] ?? 'synced from peer site');

        $data['action'] === 'allow'
            ? LeadFilterRule::applyAllow($data['email'] ?? null, $data['phone'] ?? null, $data['ip'] ?? null, $note)
            : LeadFilterRule::applyDeny($data['email'] ?? null, $data['phone'] ?? null, $data['ip'] ?? null, $note);

        return response()->json(['data' => ['applied' => true]]);
    }
}
