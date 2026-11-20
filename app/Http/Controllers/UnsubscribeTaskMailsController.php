<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Response;

/**
 * Turns task reminder mails off from the link in the mail (SEC-73). A GET only shows a confirmation page, so link
 * scanners change nothing; the POST comes from that page's button or from the mail client's one-click unsubscribe
 * (RFC 8058). The link is signed, never expires and is tied to the address it was mailed to.
 */
class UnsubscribeTaskMailsController extends Controller
{
    public function show(string $id, string $hash): Response
    {
        $user = $this->userFor($id, $hash);

        if (! $user instanceof User) {
            return response()->view('task-mails.unsubscribe', ['state' => 'invalid'], 404);
        }

        return response()->view('task-mails.unsubscribe', ['state' => $user->task_reminder_mails ? 'confirm' : 'already-off']);
    }

    public function store(string $id, string $hash): Response
    {
        $user = $this->userFor($id, $hash);

        if (! $user instanceof User) {
            return response()->view('task-mails.unsubscribe', ['state' => 'invalid'], 404);
        }

        $user->forceFill(['task_reminder_mails' => false])->save();

        return response()->view('task-mails.unsubscribe', ['state' => 'done']);
    }

    private function userFor(string $id, string $hash): ?User
    {
        $user = User::query()->find((int) $id);

        if (! $user instanceof User || ! hash_equals(sha1($user->email), $hash)) {
            return null;
        }

        return $user;
    }
}
