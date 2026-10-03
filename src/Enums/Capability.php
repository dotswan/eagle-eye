<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Enums;

enum Capability: string
{
    case FullTextSearch = 'full_text_search';
    case ExactCount = 'exact_count';
    case UnboundedTimeRange = 'unbounded_time_range';
    case Aggregation = 'aggregation';
    case RankedAggregation = 'ranked_aggregation';
    case SeriesAggregation = 'series_aggregation';
    case LiveTail = 'live_tail';
    case JsonFieldQuery = 'json_field_query';
    case SynchronousDeletion = 'synchronous_deletion';
}
