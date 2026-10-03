<?php

declare(strict_types=1);

use Dotswan\EagleEye\Facades\EagleEye;
use Dotswan\EagleEye\Attributes\ActivitySubject;
use Dotswan\EagleEye\Repository\ActivityTrail;
use Illuminate\Database\Eloquent\Model;

#[ActivitySubject('reviewed_model')]
final class ReviewedActivityTrailModel extends Model
{
}

it('adds subject and actor activity trails to persisted Eloquent models', function (): void {
    EagleEye::fake();

    $model = new ReviewedActivityTrailModel();
    $model->exists = true;
    $model->setAttribute('id', 8821);

    $subjectTrail = $model->__call('activityTrail', []);
    $actorTrail = $model->__call('activityTrail', ['actor']);

    expect($subjectTrail)->toBeInstanceOf(ActivityTrail::class)
        ->and($actorTrail)->toBeInstanceOf(ActivityTrail::class);

    if (! $subjectTrail instanceof ActivityTrail || ! $actorTrail instanceof ActivityTrail) {
        throw new RuntimeException('Expected activity trails.');
    }

    expect($subjectTrail->criteria->subjectId)->toBe('8821')
        ->and($subjectTrail->criteria->subjectType)->toBe('reviewed_model')
        ->and($subjectTrail->criteria->subjectClass)->toBe(ReviewedActivityTrailModel::class)
        ->and($actorTrail->criteria->actorId)->toBe('8821');
});

it('rejects activity trails for unpersisted models', function (): void {
    $model = new class () extends Model {};

    $model->__call('activityTrail', []);
})->throws(InvalidArgumentException::class);
