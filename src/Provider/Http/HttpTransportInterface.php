<?php

declare(strict_types=1);

namespace PushPull\Provider\Http;

interface HttpTransportInterface
{
    public function send(HttpRequest $request): HttpResponse;
}
