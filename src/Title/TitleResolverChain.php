<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Title;

use Dotswan\EagleEye\Contracts\Title\TitleResolverInterface;
use Dotswan\EagleEye\Data\ActivityDraft;

final readonly class TitleResolverChain implements TitleResolverInterface
{
    /** @param list<TitleResolverInterface> $resolvers */
    public function __construct(private array $resolvers)
    {
    }

    public function resolve(ActivityDraft $draft): ?string
    {
        foreach ($this->resolvers as $resolver) {
            $title = $resolver->resolve($draft);

            if (null !== $title && '' !== $title) {
                return $title;
            }
        }

        return null;
    }
}
