<x-mail::message>
# You've Been Invited

@if ($inviterName)
**{{ $inviterName }}** has invited you to join **{{ $organizationName }}** as a **{{ $role }}**.
@else
You have been invited to join **{{ $organizationName }}** as a **{{ $role }}**.
@endif

<x-mail::button :url="$acceptUrl">
Accept Invitation
</x-mail::button>

This invitation expires on **{{ $expiresAt }}**.

If you didn't expect this invitation, you can safely ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
