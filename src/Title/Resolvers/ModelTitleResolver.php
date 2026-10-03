<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Title\Resolvers;

use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Illuminate\Support\Str;

final class ModelTitleResolver extends ChannelTitleResolver
{
    protected function channel(): ActivityChannel
    {
        return ActivityChannel::Model;
    }

    protected function render(ActivityDraft $draft): string
    {
        $state = $draft->payload['state'] ?? null;

        if (is_array($state) && isset($state['field']) && array_key_exists('new', $state)) {
            return $this->renderKey($draft, 'model.state_changed', [
                'subject_label' => $this->subjectLabel($draft),
                'field'         => Str::headline((string) $state['field']),
                'new_state'     => $this->scalar($state['new']),
            ]);
        }

        $summary = $this->changeSummary($draft);
        $draft->summary ??= '' !== $summary ? $summary : null;

        return $this->renderTemplate($draft, 'model', [
            'subject_label'  => $this->subjectLabel($draft),
            'action'         => $draft->action,
            'change_summary' => $summary,
            'changed_fields' => $this->changedFields($draft),
        ]);
    }
}
