<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Enums;

enum RequestPart: string
{
    case Headers = 'headers';
    case Query = 'query';
    case Body = 'body';
    case Files = 'files';
    case Cookies = 'cookies';
    case Session = 'session';
    case RouteParams = 'route_params';
    case ResponseHeaders = 'response_headers';
    case ResponseBody = 'response_body';
    case Ip = 'ip';
    case UserAgent = 'user_agent';
}
