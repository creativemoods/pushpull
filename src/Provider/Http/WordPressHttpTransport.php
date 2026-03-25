<?php

declare(strict_types=1);

namespace PushPull\Provider\Http;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception construction is not HTML output.

use PushPull\Provider\Exception\ProviderException;

final class WordPressHttpTransport implements HttpTransportInterface
{
    public function send(HttpRequest $request): HttpResponse
    {
        if (! function_exists('wp_remote_request')) {
            throw new ProviderException(
                ProviderException::TRANSPORT,
                'WordPress HTTP transport is not available.'
            );
        }

        $response = wp_remote_request($request->url, [
            'method' => $request->method,
            'headers' => $request->headers,
            'body' => $request->json !== null ? wp_json_encode($request->json) : null,
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            throw new ProviderException(
                ProviderException::TRANSPORT,
                $response->get_error_message()
            );
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $headers = [];

        foreach ((array) wp_remote_retrieve_headers($response) as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $headers[$name] = $value;
            }
        }

        return new HttpResponse($statusCode, $body, $headers);
    }
}
