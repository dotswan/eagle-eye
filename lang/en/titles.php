<?php

declare(strict_types=1);

return [
    'model.created'       => 'Created :subject_label',
    'model.updated'       => 'Updated :subject_label (:changed_fields)',
    'model.deleted'       => 'Deleted :subject_label',
    'model.restored'      => 'Restored :subject_label',
    'model.force_deleted' => 'Permanently deleted :subject_label',
    'model.default'       => 'Recorded :action on :subject_label',
    'model.state_changed' => ':subject_label :field changed to :new_state',

    'http.request'         => ':method :route → :status',
    'http.default'         => ':method :route → :status',
    'http.page'            => 'Viewed :page',
    'http.resource.view'   => 'Viewed :resource_label :record',
    'http.resource.edit'   => 'Opened :resource_label :record for editing',
    'http.resource.index'  => 'Viewed :resource_label',
    'http.resource.create' => 'Created :resource_label',
    'http.livewire_action' => ':action_label on :page',
    'http.livewire'        => 'Interacted with :page',

    'auth.login'               => 'Signed in (:guard) from :ip',
    'auth.failed'              => 'Failed sign-in from :ip',
    'auth.logout'              => 'Signed out from :ip',
    'auth.registered'          => 'Registered a new account',
    'auth.password_reset'      => 'Reset their password',
    'auth.verified'            => 'Verified their email address',
    'auth.lockout'             => 'Locked out after too many attempts from :ip',
    'auth.other_device_logout' => 'Signed out other devices',
    'auth.default'             => 'Recorded :action',

    'queue.queued'     => 'Queued :job_short on :connection',
    'queue.processing' => 'Started :job_short (attempt :attempts)',
    'queue.processed'  => 'Ran :job_short (:duration)',
    'queue.failed'     => 'Job :job_short failed after :attempts attempt(s): :error',
    'queue.default'    => 'Recorded :action',

    'console.started'   => 'Ran :command',
    'console.finished'  => 'Ran :command (exit :exit_code, :duration)',
    'schedule.started'  => 'Started scheduled task :command',
    'schedule.finished' => 'Finished scheduled task :command (:duration)',
    'schedule.failed'   => 'Scheduled task :command failed: :error',
    'schedule.default'  => 'Recorded :action',
    'console.default'   => 'Ran :action',

    'mail.sending' => 'Sending a mail message',
    'mail.sent'    => 'Sent email “:subject” to :recipient_count recipient(s)',
    'mail.failed'  => 'Mail delivery failed: :error',
    'mail.default' => 'Recorded :action',

    'notification.sent'    => 'Sent a notification via :channel',
    'notification.failed'  => ':notification_short via :channel failed',
    'notification.default' => 'Recorded :action',

    'outbound_http.response' => 'Called :method :host:path → :status',
    'outbound_http.failed'   => 'Failed to reach :host:path: :error',
    'outbound_http.default'  => 'Recorded :action',

    'query.slow'    => 'Slow query on :connection (:duration_ms ms)',
    'query.summary' => 'Ran :query_count queries (:duration)',

    'exception.default' => 'Saw an exception: :error',

    'fallback' => 'Recorded :action on :subject_label',
];
